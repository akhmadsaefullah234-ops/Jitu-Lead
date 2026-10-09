<?php

namespace Tests\Feature;

use App\Actions\ImportLeadsFromCsv;
use App\Enums\Role;
use App\Filament\Pages\ImportLeads;
use App\Filament\Pages\LeadCapture;
use App\Filament\Resources\LeadSources\Pages\ManageLeadSources;
use App\Filament\Resources\LostReasons\Pages\ManageLostReasons;
use App\Filament\Resources\Stages\Pages\ManageStages;
use App\Filament\Resources\Team\Pages\ManageTeam;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LostReason;
use App\Models\Membership;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsImportCaptureTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant();
    }

    private function inTenant(callable $fn, ?Tenant $tenant = null): mixed
    {
        return app(CurrentTenant::class)->run($tenant ?? $this->tenant, $fn);
    }

    private function csv(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($path, $content);

        return $path;
    }

    public function test_only_admin_reaches_settings_screens(): void
    {
        foreach ([ManageStages::class, ManageLeadSources::class, ManageLostReasons::class, ManageTeam::class, LeadCapture::class] as $page) {
            foreach ([Role::Agent, Role::Manager] as $role) {
                $this->actingInTenant($this->member($this->tenant, $role), $this->tenant);
                Livewire::test($page)->assertForbidden();
            }

            $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
            Livewire::test($page)->assertOk();
        }
    }

    public function test_admin_adds_and_edits_a_stage_at_the_end(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(ManageStages::class)
            ->callAction('create', ['name' => 'Menunggu KPR', 'type' => 'open'])
            ->assertHasNoActionErrors();

        $stage = $this->inTenant(fn () => Stage::where('name', 'Menunggu KPR')->firstOrFail());
        $this->assertSame($this->inTenant(fn () => (int) Stage::max('position')), $stage->position);
        $this->assertSame($this->tenant->getKey(), $stage->tenant_id);
    }

    public function test_stage_with_leads_or_last_of_its_kind_cannot_be_deleted(): void
    {
        $admin = $this->adminOf($this->tenant);
        $this->actingInTenant($admin, $this->tenant);
        $this->makeLead($this->tenant);

        $withLeads = $this->stage($this->tenant, 'Lead baru');
        $last = $this->stage($this->tenant, 'Gugur');
        $free = $this->stage($this->tenant, 'Negosiasi');

        $this->assertFalse($admin->can('delete', $withLeads));
        $this->assertFalse($admin->can('delete', $last)); // the only "lost" stage
        $this->assertTrue($admin->can('delete', $free));
    }

    public function test_settings_of_another_agency_are_not_reachable(): void
    {
        $other = $this->makeTenant('rumah-lain');
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        $foreign = $this->inTenant(fn () => LeadSource::create(['name' => 'Rahasia', 'type' => 'manual']), $other);

        Livewire::test(ManageLeadSources::class)->assertDontSee('Rahasia');
        $this->assertFalse($this->adminOf($this->tenant)->can('update', $foreign));
    }

    public function test_admin_manages_sources_and_lost_reasons(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(ManageLeadSources::class)->callAction('create', ['name' => 'Brosur', 'type' => 'manual'])->assertHasNoActionErrors();
        Livewire::test(ManageLostReasons::class)->callAction('create', ['label' => 'Pindah kota'])->assertHasNoActionErrors();

        $this->assertTrue($this->inTenant(fn () => LeadSource::where('name', 'Brosur')->exists()));
        $this->assertSame($this->tenant->getKey(), $this->inTenant(fn () => LostReason::where('label', 'Pindah kota')->firstOrFail()->tenant_id));
    }

    public function test_admin_adds_team_member_with_hashed_password_and_unique_email(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(ManageTeam::class)
            ->callAction('add', ['name' => 'Budi', 'email' => 'budi@contoh.id', 'password' => 'sementara-123', 'role' => 'agent'])
            ->assertHasNoActionErrors();

        $user = User::where('email', 'budi@contoh.id')->firstOrFail();
        $this->assertTrue(Hash::check('sementara-123', $user->password));
        $this->assertSame(Role::Agent, $user->roleIn($this->tenant));

        Livewire::test(ManageTeam::class)
            ->callAction('add', ['name' => 'Budi 2', 'email' => 'budi@contoh.id', 'password' => 'sementara-123', 'role' => 'agent'])
            ->assertHasActionErrors(['email']);
    }

    public function test_last_admin_cannot_be_demoted_or_removed(): void
    {
        $admin = $this->adminOf($this->tenant);
        $membership = Membership::query()->where('tenant_id', $this->tenant->getKey())->where('user_id', $admin->getKey())->firstOrFail();
        $this->actingInTenant($admin, $this->tenant);

        $this->assertTrue($membership->isLastActiveAdmin());
        $this->assertFalse($admin->can('delete', $membership));

        $this->expectException(\LogicException::class);
        $membership->update(['role' => 'agent']);
    }

    public function test_second_admin_can_be_demoted(): void
    {
        $second = $this->member($this->tenant, Role::Admin);
        $membership = Membership::query()->where('tenant_id', $this->tenant->getKey())->where('user_id', $second->getKey())->firstOrFail();

        $membership->update(['role' => 'agent']);

        $this->assertSame(Role::Agent, $second->roleIn($this->tenant));
    }

    public function test_csv_import_creates_leads_skips_duplicates_and_reports_bad_rows(): void
    {
        $agent = $this->member($this->tenant, Role::Agent);
        $path = $this->csv("Nama;Telepon;Email;Catatan\nAni;0812-1111-2222;ani@contoh.id;Cari rumah 2 KT\nAni Lagi;+62 812 1111 2222;;\n;0813;;\nBudi;;budi@contoh.id;\nCici;12;;\n");

        $result = $this->inTenant(fn () => app(ImportLeadsFromCsv::class)($path, 'Pameran'));

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['duplicates']);
        $this->assertCount(2, $result['invalid']);

        $lead = $this->inTenant(fn () => Lead::where('name', 'Ani')->firstOrFail());
        $this->assertSame('+6281211112222', $lead->phone);
        $this->assertSame($agent->getKey(), $lead->owner_id);
        $this->assertSame('Pameran', $this->inTenant(fn () => $lead->source->name));
        $this->assertSame($this->stage($this->tenant, 'Lead baru')->getKey(), $lead->stage_id);
        $this->assertSame(2, $this->inTenant(fn () => Lead::count()));
    }

    public function test_csv_without_required_columns_or_too_big_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->inTenant(fn () => app(ImportLeadsFromCsv::class)($this->csv("foo,bar\n1,2\n"), 'X'));
    }

    public function test_csv_import_never_touches_another_agency(): void
    {
        $other = $this->makeTenant('rumah-lain');
        $this->member($other, Role::Agent);
        $this->makeLead($other, ['name' => 'Milik Lain', 'phone' => '081233334444']);

        $result = $this->inTenant(fn () => app(ImportLeadsFromCsv::class)($this->csv("nama,telepon\nBaru,081233334444\n"), 'Impor'));

        $this->assertSame(1, $result['created']); // not a duplicate of the other agency's lead
        $this->assertSame(1, $this->inTenant(fn () => Lead::count()));
    }

    public function test_agent_import_assigns_to_themselves(): void
    {
        $agent = $this->member($this->tenant, Role::Agent);
        $this->member($this->tenant, Role::Agent);
        $path = $this->csv("nama,telepon\nA,081200000001\nB,081200000002\n");

        $this->inTenant(fn () => app(ImportLeadsFromCsv::class)($path, 'Impor', $agent->getKey()));

        $this->assertSame(2, $this->inTenant(fn () => Lead::where('owner_id', $agent->getKey())->count()));
    }

    public function test_import_page_is_open_to_agents(): void
    {
        $this->actingInTenant($this->member($this->tenant, Role::Agent), $this->tenant);
        Livewire::test(ImportLeads::class)->assertOk();
    }

    public function test_capture_endpoint_creates_lead_for_the_token_agency_only(): void
    {
        $this->member($this->tenant, Role::Agent);
        $token = $this->tenant->regenerateCaptureToken();
        $other = $this->makeTenant('rumah-lain');
        $other->regenerateCaptureToken();

        $this->postJson("/capture/{$token}", ['name' => 'Dewi', 'phone' => '0812 9999 0000', 'note' => 'Tanya KPR', 'source' => 'Landing Mei'])
            ->assertOk()->assertJson(['ok' => true])->assertHeader('Access-Control-Allow-Origin', '*');

        $lead = $this->inTenant(fn () => Lead::where('name', 'Dewi')->firstOrFail());
        $this->assertSame('+6281299990000', $lead->phone);
        $this->assertSame('Landing Mei', $this->inTenant(fn () => $lead->source->name));
        $this->assertSame(0, $this->inTenant(fn () => Lead::count(), $other));
    }

    public function test_capture_dedupes_and_logs_second_contact(): void
    {
        $token = $this->tenant->regenerateCaptureToken();

        $this->post("/capture/{$token}", ['name' => 'Dewi', 'phone' => '081299990000'])->assertOk()->assertSee('Terima kasih');
        $this->post("/capture/{$token}", ['name' => 'Dewi', 'phone' => '+6281299990000', 'note' => 'Lagi'])->assertOk();

        $this->assertSame(1, $this->inTenant(fn () => Lead::count()));
        $this->assertTrue($this->inTenant(fn () => Lead::firstOrFail()->activities()->where('body', 'like', 'Menghubungi lagi%')->exists()));
    }

    public function test_capture_rejects_unknown_token_bad_input_and_honeypot(): void
    {
        $token = $this->tenant->regenerateCaptureToken();

        $this->postJson('/capture/salah', ['name' => 'X', 'phone' => '0812'])->assertNotFound();
        $this->postJson("/capture/{$token}", ['phone' => '081200000000'])->assertUnprocessable();
        $this->postJson("/capture/{$token}", ['name' => 'Tanpa kontak'])->assertUnprocessable();
        $this->postJson("/capture/{$token}", ['name' => 'Bot', 'phone' => '081200000000', 'website' => 'http://spam'])->assertOk();

        $this->assertSame(0, $this->inTenant(fn () => Lead::count()));
    }

    public function test_suspended_agency_and_old_token_stop_capturing(): void
    {
        $old = $this->tenant->regenerateCaptureToken();
        $this->tenant->regenerateCaptureToken();
        $this->postJson("/capture/{$old}", ['name' => 'X', 'phone' => '081200000000'])->assertNotFound();

        $token = $this->tenant->fresh()->capture_token;
        $this->tenant->forceFill(['status' => 'suspended'])->save();
        $this->postJson("/capture/{$token}", ['name' => 'X', 'phone' => '081200000000'])->assertNotFound();
    }

    public function test_capture_is_rate_limited(): void
    {
        $token = $this->tenant->regenerateCaptureToken();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson("/capture/{$token}", ['name' => "L{$i}", 'phone' => '08120000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)])->assertOk();
        }

        $this->postJson("/capture/{$token}", ['name' => 'Banjir', 'phone' => '081200009999'])->assertStatus(429);
    }

    public function test_admin_generates_capture_token_and_it_is_not_in_serialization(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(LeadCapture::class)->assertSee('Formulir belum aktif')
            ->callAction('generate')->assertSee('/capture/');

        $this->assertNotNull($this->tenant->fresh()->capture_token);
        $this->assertArrayNotHasKey('capture_token', $this->tenant->fresh()->toArray());
    }
}
