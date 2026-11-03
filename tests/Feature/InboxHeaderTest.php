<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Automation\RetailStages;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\Filament\Pages\Inbox;
use App\Models\Lead;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Support\CurrentTenant;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The chat header in the Inbox is one compact row (back, name, stage, product, ⋯); the shop actions live in the ⋯
 * menu, the lead's details fold out under the name, and the route shows only when it says something: a quiet line
 * for a free official reply, a warning for a closed window, nothing for a gateway reply.
 */
class InboxHeaderTest extends TestCase
{
    use RefreshDatabase;

    private function channel(Tenant $tenant, WaChannelType $type): WaChannel
    {
        return app(CurrentTenant::class)->run($tenant, fn () => WaChannel::create($type === WaChannelType::Gateway
            ? ['type' => $type, 'name' => 'GW', 'status' => WaChannelStatus::Connected, 'position' => 1, 'credentials' => ['base_url' => 'https://gw.example.com', 'api_key' => 'k', 'signing_secret' => 's']]
            : ['type' => $type, 'name' => 'Resmi', 'status' => WaChannelStatus::Connected, 'position' => 2, 'credentials' => ['phone_number_id' => '555', 'access_token' => 't', 'app_secret' => 's', 'verify_token' => 'v']]));
    }

    /** Opens the lead's chat; `$wroteHoursAgo` null means the client never wrote. */
    private function inbox(Tenant $tenant, Lead $lead, ?WaChannel $wroteTo = null, ?int $wroteHoursAgo = null): Testable
    {
        app(CurrentTenant::class)->run($tenant, fn () => WaConversation::create([
            'lead_id' => $lead->getKey(), 'phone' => $lead->phone,
            'last_inbound_channel_id' => $wroteTo?->getKey(), 'last_inbound_at' => $wroteHoursAgo === null ? null : now()->subHours($wroteHoursAgo),
        ]));
        $this->actingInTenant($this->adminOf($tenant), $tenant);

        return Livewire::withQueryParams(['lead' => $lead->getKey()])->test(Inbox::class);
    }

    private function header(Testable $page): string
    {
        preg_match('/<div class="wa-head".*?<div class="wa-msgs/s', $page->html(), $m);

        return $m[0] ?? '';
    }

    public function test_retail_shop_actions_are_in_the_more_menu_and_the_details_fold_under_the_name(): void
    {
        $shop = app(ProvisionTenant::class)('Quicker Sport', 'quicker-sport', User::factory()->create(), 'retail');
        $gw = $this->channel($shop, WaChannelType::Gateway);
        $lead = app(CurrentTenant::class)->run($shop, function () {
            $product = Property::query()->create(['name' => 'CAPRA Pro Raket Badminton 3U', 'price' => 349000]);

            return Lead::query()->create(['name' => 'Rina Kartika', 'phone' => '+6281234560001', 'property_id' => $product->getKey(),
                'stage_id' => (new RetailStages)->stage(RetailStages::PAYMENT)->getKey(), 'payment_proof_at' => now(), 'ad_title' => 'Promo raket']);
        });

        $page = $this->inbox($shop, $lead, $gw, 1);
        $head = $this->header($page);

        $this->assertStringContainsString('class="wa-more"', $head);
        $this->assertMatchesRegularExpression('/class="wa-more">.*Pembayaran diterima.*Nomor resi.*Buka lead/s', $head, 'the shop actions are inside the ⋯ menu');
        $this->assertStringNotContainsString('fi-btn', preg_replace('/class="wa-more">.*/s', '', $head), 'no big buttons left in the header row');
        $this->assertStringContainsString('CAPRA Pro Raket Badminton 3U', $head);
        $this->assertStringContainsString('class="wa-chip"', $head);
        $this->assertMatchesRegularExpression('/id="wa-details" x-show="open" x-cloak>.*\+6281234560001.*Dari iklan:.*Promo raket.*Bukti transfer masuk/s', $head);
        $this->assertStringContainsString('localStorage', $head);

        // The actions still run the same logic: payment confirmed from the menu.
        $page->callAction(TestAction::make('paymentReceived'));
        $this->assertNotNull($lead->fresh()->paid_at);
    }

    public function test_property_lead_has_no_shop_actions_and_a_gateway_reply_says_nothing_about_a_window(): void
    {
        $agency = $this->makeTenant('griya-uji');
        $gw = $this->channel($agency, WaChannelType::Gateway);
        $lead = $this->makeLead($agency, ['name' => 'Budi Santoso']);

        $page = $this->inbox($agency, $lead, $gw, 2);
        $html = preg_replace('/<style.*?<\/style>/s', '', $page->html());

        $this->assertMatchesRegularExpression('/class="wa-more">.*Buka lead/s', $this->header($page));
        $this->assertStringNotContainsString('Pembayaran diterima', $html);
        $this->assertStringNotContainsString('Nomor resi', $html);
        $this->assertStringNotContainsString('wa-route', $html, 'a normal gateway reply shows no route line');
        $this->assertStringNotContainsString('jendela', mb_strtolower(strip_tags($html)));
    }

    public function test_official_reply_inside_the_window_is_one_quiet_line(): void
    {
        $agency = $this->makeTenant('griya-resmi');
        $official = $this->channel($agency, WaChannelType::Official);
        $lead = $this->makeLead($agency);

        $html = $this->inbox($agency, $lead, $official, 1)->html();

        $this->assertMatchesRegularExpression('/<div class="wa-route" title="[^"]*">Nomor resmi · gratis · sisa 2[23] jam<\/div>/u', $html);
        $this->assertStringNotContainsString('wa-route warn', $html);
    }

    public function test_a_closed_window_is_a_warning_line_that_opens_to_the_full_explanation(): void
    {
        $agency = $this->makeTenant('griya-tutup');
        $official = $this->channel($agency, WaChannelType::Official);
        $lead = $this->makeLead($agency);

        $html = $this->inbox($agency, $lead, $official, 30)->html();

        $this->assertMatchesRegularExpression('/<details class="wa-route warn">\s*<summary>Jendela tutup · hanya template berbayar, tanpa lampiran<\/summary>\s*<p><b>Template berbayar di nomor resmi\.<\/b> Jendela tutup/u', $html);
    }

    public function test_a_first_follow_up_from_a_gateway_warns_that_it_starts_with_the_introduction(): void
    {
        $agency = $this->makeTenant('griya-intro');
        $this->channel($agency, WaChannelType::Gateway);
        $lead = $this->makeLead($agency);

        $html = $this->inbox($agency, $lead)->html();

        $this->assertStringContainsString('<summary>Jendela tutup · diawali pesan perkenalan</summary>', $html);
    }
}
