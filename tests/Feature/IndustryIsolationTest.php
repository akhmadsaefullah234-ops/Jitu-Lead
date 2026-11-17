<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Ai\AiSettings;
use App\Ai\PropertyTools;
use App\Ai\ShopTools;
use App\Automation\PropertyStageAutomationSettings;
use App\Models\FollowUpRule;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Models\User;
use App\Shipping\ShippingSettings;
use App\Support\CurrentTenant;
use App\WhatsApp\BusinessLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A shop and a property agency side by side, each new and each existing (made before Jitu's new-agency defaults):
 * AI settings, instructions, follow-ups, stage automation, AI tools (ShopTools vs PropertyTools), ad tracking and
 * labels, and the defaults of config/industries.php never cross from one industry to the other, and a changed
 * default reaches only NEW agencies of that industry. Every name and number here is made up.
 */
class IndustryIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $shop;

    private Tenant $agency;

    private Tenant $oldShop;

    private Tenant $oldAgency;

    protected function setUp(): void
    {
        parent::setUp();
        $provision = fn (string $name, string $slug, string $industry) => app(ProvisionTenant::class)($name, $slug, User::factory()->create(), $industry);

        $this->shop = $provision('Toko Baru', 'toko-baru', 'retail');
        $this->agency = $provision('Agensi Baru', 'agensi-baru', 'properti');
        // Agencies made before the new-agency defaults: no AI settings stored, nothing chosen.
        $this->oldShop = $provision('Toko Lama', 'toko-lama', 'retail');
        $this->oldAgency = $provision('Agensi Lama', 'agensi-lama', 'properti');
        foreach ([$this->oldShop, $this->oldAgency] as $old) {
            $old->forceFill(['ai_settings' => null, 'stage_automation' => null, 'pipeline_settings' => null])->save();
        }
    }

    private function in(Tenant $tenant, callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($tenant, $fn);
    }

    private function lead(Tenant $tenant): Lead
    {
        return $this->in($tenant, fn () => Lead::create(['name' => 'Klien Contoh', 'phone' => '08990001'.random_int(1000, 9999),
            'stage_id' => Stage::query()->orderBy('position')->value('id')]));
    }

    public function test_each_new_agency_starts_with_its_own_industrys_defaults_only(): void
    {
        $this->assertSame(AiSettings::NEW_PROPERTY, $this->agency->ai_settings);
        $this->assertNull($this->shop->ai_settings, 'a new shop keeps the AI settings shops always had');

        $this->assertSame(config('industries.industries.properti.ai_instructions'), $this->agency->ai_instructions);
        $this->assertSame(config('industries.industries.retail.ai_instructions'), $this->shop->ai_instructions);
        $this->assertNotSame($this->shop->ai_instructions, $this->agency->ai_instructions);

        $rules = fn (Tenant $t) => $this->in($t, fn () => FollowUpRule::query()->orderBy('id')->pluck('name')->all());
        $this->assertSame(array_column(config('industries.industries.properti.follow_ups'), 0), array_slice($rules($this->agency), 0, count(config('industries.industries.properti.follow_ups'))));
        $this->assertSame(array_column(config('industries.industries.retail.follow_ups'), 0), array_slice($rules($this->shop), 0, count(config('industries.industries.retail.follow_ups'))));
        $this->assertSame([], array_intersect($rules($this->shop), array_column(config('industries.industries.properti.follow_ups'), 0)));

        $this->assertTrue(PropertyStageAutomationSettings::of($this->agency)->chosen(), 'a new agency starts with Otomatisasi tahap on');
        $this->assertFalse(PropertyStageAutomationSettings::appliesTo($this->shop));
        $this->assertFalse(PropertyStageAutomationSettings::of($this->oldAgency)->chosen(), 'an existing agency stays off until an admin chooses');

        $this->assertNotNull($this->in($this->agency, fn () => TrackingSetting::starting()));
        $this->assertNull($this->in($this->shop, fn () => TrackingSetting::starting()));
        $this->assertNull($this->in($this->oldAgency, fn () => TrackingSetting::starting()), 'an existing agency keeps its tracking as it was');

        $stagesOf = fn (Tenant $t) => $this->in($t, fn () => Stage::query()->pluck('id')->all());
        $labelStages = fn (Tenant $t, string $industry) => $this->in($t, fn () => collect(BusinessLabels::defaults($industry)['rules'])->flatMap(fn ($r) => [...$r['stages'], ...$r['cod_stages']])->all());
        $this->assertSame([], array_diff($labelStages($this->agency, 'properti'), $stagesOf($this->agency)), 'property labels point at the agency\'s own stages');
        $this->assertSame([], array_diff($labelStages($this->shop, 'retail'), $stagesOf($this->shop)), 'shop labels point at the shop\'s own stages');
    }

    public function test_ai_tools_stay_in_their_own_industry(): void
    {
        $shipping = ['enabled' => true, 'couriers' => ['JNE REG'], 'zones' => [['name' => 'Kota Contoh', 'provinces' => ['Jawa Barat'], 'cities' => [], 'prices' => ['JNE REG' => 10000]]]] + ShippingSettings::blank();
        $this->shop->forceFill(['shipping' => $shipping])->save();
        $this->agency->forceFill(['shipping' => $shipping])->save();
        $this->assertTrue(ShippingSettings::of($this->agency)->enabled());

        $this->assertNotNull(PropertyTools::for($this->agency, $this->lead($this->agency)));
        $this->assertNull(PropertyTools::for($this->shop, $this->lead($this->shop)), 'a shop never gets survey or needs tools');
        $this->assertNotNull(ShopTools::for($this->shop->fresh()));
        $this->assertNull(ShopTools::for($this->agency->fresh()), 'an agency never gets the order and shipping tools, even with shipping switched on');
    }

    public function test_what_one_agency_saves_never_reaches_another(): void
    {
        $before = [$this->shop->fresh()->only(['ai_settings', 'ai_instructions', 'stage_automation', 'pipeline_settings']),
            $this->oldAgency->fresh()->only(['ai_settings', 'ai_instructions', 'stage_automation', 'pipeline_settings'])];

        $this->agency->forceFill(['ai_settings' => array_merge($this->agency->ai_settings, ['tone' => 'formal', 'auto_gate' => false]), 'ai_instructions' => 'Instruksi agensi contoh.'])->save();
        PropertyStageAutomationSettings::store($this->agency, array_merge(PropertyStageAutomationSettings::defaults(), ['surveyed' => ['enabled' => false]]));
        $this->in($this->agency, fn () => TrackingSetting::create(['meta_pixel_id' => '1234567890', 'credentials' => ['meta_capi_token' => 'contoh']]));

        $this->assertSame($before, [$this->shop->fresh()->only(['ai_settings', 'ai_instructions', 'stage_automation', 'pipeline_settings']),
            $this->oldAgency->fresh()->only(['ai_settings', 'ai_instructions', 'stage_automation', 'pipeline_settings'])]);
        $this->assertNull($this->in($this->shop, fn () => TrackingSetting::current()));
        $this->assertNull($this->in($this->oldAgency, fn () => TrackingSetting::current()));
    }

    public function test_a_changed_default_reaches_only_new_agencies_of_that_industry(): void
    {
        $was = [$this->shop->ai_instructions, $this->agency->ai_instructions, $this->oldAgency->ai_instructions];
        config(['industries.industries.properti.ai_instructions' => 'Instruksi bawaan properti yang baru.']);

        $newer = app(ProvisionTenant::class)('Agensi Terbaru', 'agensi-terbaru', User::factory()->create(), 'properti');
        $newShop = app(ProvisionTenant::class)('Toko Terbaru', 'toko-terbaru', User::factory()->create(), 'retail');

        $this->assertSame('Instruksi bawaan properti yang baru.', $newer->ai_instructions);
        $this->assertSame($was[0], $newShop->ai_instructions, 'a shop made now is untouched by a property default');
        $this->assertSame($was, [$this->shop->fresh()->ai_instructions, $this->agency->fresh()->ai_instructions, $this->oldAgency->fresh()->ai_instructions],
            'agencies that already exist keep what they had');
    }
}
