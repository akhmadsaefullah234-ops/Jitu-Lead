<?php

namespace Tests\Feature;

use App\Filament\Resources\LeadForms\Pages\ListLeadForms;
use App\Filament\Resources\Leads\Pages\ListLeads;
use App\LeadForms\FormFields;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\LeadFormRejection;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A filled robot trap must never lose a buyer: Facebook's in-app browser (FB_IAB/FB4A) autofilled the old
 * "website" trap and real orders were thrown away with a 200 "Terima kasih!".
 */
class FormSpamGuardTest extends TestCase
{
    use RefreshDatabase;

    private const FB_UA = 'Mozilla/5.0 (Linux; Android 14; SM-A155F Build/UP1A.231005.007; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/130.0.6723.102 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/487.0.0.43.109;]';

    private Tenant $tenant;

    private LeadForm $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('quicker-sport');
        $this->form = $this->in(fn () => LeadForm::create([
            'name' => 'Quickers', 'slug' => 'quickers', 'status' => 'active',
            'fields' => FormFields::normalize([
                ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => true, 'maps_to' => 'name'],
                ['key' => 'wa', 'type' => 'phone', 'label' => 'WhatsApp', 'required' => true, 'maps_to' => 'phone'],
            ]),
            'submit' => ['label' => 'Pesan'],
            'after_submit' => ['type' => 'whatsapp', 'wa_target' => 'manual', 'wa_number' => '0813 0000 1111', 'wa_message' => 'Halo, saya {nama}'],
        ]));
    }

    private function in(callable $fn): mixed
    {
        return app(CurrentTenant::class)->run($this->tenant, $fn);
    }

    /** The trap name and signed time a page view really carries. */
    private function page(): array
    {
        $html = $this->get('/f/quicker-sport/quickers')->assertOk()->getContent();
        preg_match('/name="(lf_hp_[a-z0-9]{8})"/', $html, $trap);
        preg_match('/name="lf_ts" value="([^"]+)"/', $html, $stamp);

        return ['trap' => $trap[1], 'stamp' => $stamp[1], 'html' => $html];
    }

    private function send(array $extra, string $wa = '0812 3456 7890')
    {
        return $this->withHeaders(['User-Agent' => self::FB_UA])
            ->postJson('/f/quicker-sport/quickers', ['f' => ['nama' => 'Rina', 'wa' => $wa]] + $extra);
    }

    public function test_the_trap_is_hidden_from_autofill(): void
    {
        $page = $this->page();

        $this->assertStringNotContainsString('name="website"', $page['html']);
        $this->assertMatchesRegularExpression('/<div class="lf-trap" style="display:none" aria-hidden="true"><input type="text" name="lf_hp_[a-z0-9]{8}" value="" tabindex="-1" autocomplete="off" data-lpignore="true"/', $page['html']);
        $this->assertNotSame($page['trap'], $this->page()['trap'], 'A new name on every page view.');
    }

    public function test_a_filled_trap_with_a_real_number_is_saved_marked_and_sent_to_whatsapp(): void
    {
        $page = $this->page();
        $this->travel(5)->seconds();

        $res = $this->send([$page['trap'] => 'https://quicker.id', 'lf_ts' => $page['stamp']])->assertOk()
            ->assertJsonPath('action.type', 'whatsapp');
        $this->assertStringStartsWith('https://wa.me/6281300001111', $res->json('action.url'));

        $lead = $this->in(fn () => Lead::query()->sole());
        $this->assertSame('Rina', $lead->name);
        $this->assertSame(['Kolom jebakan robot terisi'], $lead->spamReasons());
        $this->assertTrue($this->in(fn () => $lead->activities()->where('body', 'like', 'Dicurigai spam%')->exists()));
        $this->assertSame(0, LeadFormRejection::query()->count());

        // The old trap name (pages opened before the change) is handled the same way.
        $this->send(['website' => 'x'], '0813 9999 8888')->assertOk()->assertJsonPath('action.type', 'whatsapp');
        $this->assertSame(2, $this->in(fn () => Lead::query()->count()));

        // Shown in the Lead list.
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(ListLeads::class)->assertSee('Dicurigai spam');
    }

    public function test_a_fast_robot_or_a_bad_number_with_the_trap_is_rejected_logged_and_counted(): void
    {
        Log::spy();
        $page = $this->page();

        $this->send([$page['trap'] => 'spam', 'lf_ts' => $page['stamp']])->assertOk()->assertJsonPath('action.type', 'message');
        // A bad number on a built form is a visible error for a person to fix, never a silent drop.
        $this->travel(10)->seconds();
        $this->send([$page['trap'] => 'spam', 'lf_ts' => $page['stamp']], '021 555 1234')->assertUnprocessable();
        // A form whose WhatsApp field is optional, left empty, with the trap filled: a robot.
        $this->form->forceFill(['fields' => FormFields::normalize([
            ['key' => 'nama', 'type' => 'text', 'label' => 'Nama', 'required' => true, 'maps_to' => 'name'],
            ['key' => 'wa', 'type' => 'phone', 'label' => 'WhatsApp', 'maps_to' => 'phone'],
        ])])->save();
        $this->send([$page['trap'] => 'spam', 'lf_ts' => $page['stamp']], '')->assertOk()->assertJsonPath('action.type', 'message');

        $this->assertSame(0, $this->in(fn () => Lead::query()->count()));
        $this->assertSame(2, LeadFormRejection::query()->where('lead_form_id', $this->form->getKey())->count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($msg, $ctx) => $msg === 'Public form submission rejected as spam'
            && $ctx['tenant'] === $this->tenant->getKey() && $ctx['form'] === $this->form->getKey()
            && str_contains($ctx['user_agent'], 'FB_IAB') && ! str_contains(json_encode($ctx), 'Rina'))->twice();

        // Counted on the Formulir list for 7 days.
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        Livewire::test(ListLeadForms::class)->assertSee('Ditolak spam (7 hari)')
            ->assertTableColumnStateSet('spam_rejected', 2, $this->form);
        $this->travel(8)->days();
        Livewire::test(ListLeadForms::class)->assertTableColumnStateSet('spam_rejected', 0, $this->form);
    }

    public function test_a_forged_or_missing_time_never_counts_as_fast(): void
    {
        $this->send(['lf_hp_zz' => 'x', 'lf_ts' => now()->getTimestamp().'.0000000000000000'])->assertOk();
        $this->send(['lf_hp_zz' => 'x'], '0813 2222 3333')->assertOk();

        $this->assertSame(2, $this->in(fn () => Lead::query()->count()), 'Kept as suspected spam, not rejected.');
    }

    public function test_normal_submissions_are_unchanged(): void
    {
        $page = $this->page();

        // Even sent fast: only the trap marks a robot.
        $this->send(['lf_ts' => $page['stamp'], $page['trap'] => ''])->assertOk()->assertJsonPath('action.type', 'whatsapp');

        $lead = $this->in(fn () => Lead::query()->sole());
        $this->assertSame([], $lead->spamReasons());
        $this->assertFalse($this->in(fn () => $lead->activities()->where('body', 'like', 'Dicurigai spam%')->exists()));
        $this->assertSame(1, $this->form->refresh()->submissions);
    }

    public function test_agency_html_forms_keep_trapped_buyers_too(): void
    {
        $token = $this->tenant->regenerateCaptureToken();

        $this->withHeaders(['User-Agent' => self::FB_UA])
            ->postJson("/capture/{$token}", ['name' => 'Budi', 'phone' => '0812 1111 2222', 'website' => 'autofill'])->assertOk();
        $this->postJson("/capture/{$token}", ['name' => 'Bot', 'phone' => '021555', 'lf_hp_x' => 'spam'])->assertOk();

        $lead = $this->in(fn () => Lead::query()->sole());
        $this->assertSame('Budi', $lead->name);
        $this->assertNotEmpty($lead->spamReasons());
        $this->assertSame(1, LeadFormRejection::query()->whereNull('lead_form_id')->count());
    }
}
