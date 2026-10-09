<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\WaChannelType;
use App\Filament\Pages\DataPrivacy;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\Property;
use App\Models\SupportThread;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Privacy\TenantExport;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class DataRightsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Tenant $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('griya-prima');
        $this->other = $this->makeTenant('tetangga');
        Storage::fake('public');
        RateLimiter::clear('tenant-export:'.$this->tenant->getKey());
    }

    private function seedData(Tenant $tenant, string $tag): void
    {
        app(CurrentTenant::class)->run($tenant, function () use ($tag, $tenant) {
            $lead = Lead::create(['name' => "Lead $tag", 'phone' => '0812'.random_int(10000000, 99999999), 'stage_id' => $tenant->stages()->first()->getKey()]);
            $lead->activities()->create(['type' => 'note', 'body' => "Catatan $tag"]);
            Property::create(['kind' => 'primary', 'name' => "Properti $tag", 'property_type' => 'Rumah']);
            $channel = WaChannel::create(['type' => WaChannelType::Gateway, 'name' => "WA $tag", 'status' => 'disconnected', 'credentials' => ['api_key' => "RAHASIA-$tag"]]);
            $conv = WaConversation::create(['lead_id' => $lead->getKey(), 'phone' => $lead->phone]);
            WaMessage::create(['conversation_id' => $conv->getKey(), 'channel_id' => $channel->getKey(), 'direction' => 'in', 'body' => "Halo $tag", 'sent_at' => now()]);
            LandingPage::create(['title' => "Landing $tag", 'slug' => "landing-$tag", 'status' => 'draft', 'blocks' => []]);
            SupportThread::create(['tenant_id' => $tenant->getKey(), 'subject' => "Bantuan $tag"]);
            Storage::disk('public')->put("landing/{$tenant->getKey()}/foto.webp", 'x');
        });
    }

    private function csvs(string $zipPath): array
    {
        $zip = new ZipArchive;
        $zip->open($zipPath);
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $files[$zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $files;
    }

    // --- export ---

    public function test_the_export_zip_holds_the_agencys_data_as_csv_and_nothing_of_other_agencies(): void
    {
        $this->seedData($this->tenant, 'satu');
        $this->seedData($this->other, 'dua');

        $path = app(TenantExport::class)($this->tenant);
        $files = $this->csvs($path);
        unlink($path);
        $all = implode("\n", $files);

        foreach (['lead.csv', 'aktivitas-lead.csv', 'properti.csv', 'pesan-whatsapp.csv', 'percakapan-whatsapp.csv', 'landing-page.csv', 'chat-support.csv', 'anggota.csv', 'agensi.csv', 'langganan.csv', 'BACA-SAYA.txt'] as $name) {
            $this->assertArrayHasKey($name, $files);
        }
        $this->assertStringContainsString('Lead satu', $files['lead.csv']);
        $this->assertStringContainsString('Catatan satu', $files['aktivitas-lead.csv']);
        $this->assertStringContainsString('Halo satu', $files['pesan-whatsapp.csv']);
        $this->assertStringContainsString('Properti satu', $files['properti.csv']);
        $this->assertStringContainsString('Bantuan satu', $files['chat-support.csv']);
        $this->assertStringNotContainsString('dua', str_replace('tetangga', '', $all), 'nothing of the other agency');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $files['lead.csv']);
    }

    public function test_secrets_never_leave_in_the_export(): void
    {
        $this->seedData($this->tenant, 'satu');
        $admin = $this->adminOf($this->tenant);
        $token = $this->tenant->fresh()->capture_token;

        $path = app(TenantExport::class)($this->tenant);
        $all = implode("\n", $this->csvs($path));
        unlink($path);

        $this->assertStringContainsString($admin->email, $all);
        $this->assertStringNotContainsString($admin->password, $all);
        $this->assertStringNotContainsString('RAHASIA-satu', $all);
        $this->assertStringNotContainsString(WaChannel::withoutGlobalScopes()->first()->webhook_token, $all);
        $this->assertStringNotContainsString('remember_token', $all);
        if ($token) {
            $this->assertStringNotContainsString($token, $all);
        }
    }

    public function test_spreadsheet_formulas_in_data_are_defused(): void
    {
        app(CurrentTenant::class)->run($this->tenant, fn () => Lead::create(['name' => '=HYPERLINK("http://x")', 'phone' => '+62812000111', 'stage_id' => $this->tenant->stages()->first()->getKey()]));

        $path = app(TenantExport::class)($this->tenant);
        $csv = $this->csvs($path)['lead.csv'];
        unlink($path);

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringContainsString('+62812000111', $csv);
    }

    public function test_the_admin_downloads_the_zip_from_the_page_even_when_the_trial_has_ended(): void
    {
        $this->seedData($this->tenant, 'satu');
        $this->tenant->currentSubscription()->update(['trial_ends_at' => now()->subDays(3)]);
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);

        Livewire::test(DataPrivacy::class)->callAction('export')->assertFileDownloaded();
    }

    public function test_the_export_is_rate_limited(): void
    {
        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        $page = Livewire::test(DataPrivacy::class);

        for ($i = 0; $i < 3; $i++) {
            $page->callAction('export')->assertFileDownloaded();
        }
        $page->callAction('export')->assertNoFileDownloaded();
    }

    public function test_only_the_agency_admin_can_open_the_page(): void
    {
        foreach ([Role::Manager, Role::Agent] as $role) {
            $this->actingInTenant($this->member($this->tenant, $role), $this->tenant);
            $this->assertFalse(DataPrivacy::canAccess());
        }

        $this->actingInTenant($this->adminOf($this->tenant), $this->tenant);
        $this->assertTrue(DataPrivacy::canAccess());
        $this->get('/app/'.$this->tenant->slug.'/data-privacy')->assertOk()->assertSee('Unduh semua data');
    }

    // --- delete ---

    private function deleteWith(array $data)
    {
        return Livewire::test(DataPrivacy::class)->callAction('deleteTenant', $data);
    }

    public function test_the_admin_deletes_the_agency_alone_and_everything_in_it_goes(): void
    {
        $this->seedData($this->tenant, 'satu');
        $this->seedData($this->other, 'dua');
        $agent = $this->member($this->tenant, Role::Agent);
        $admin = $this->adminOf($this->tenant);
        $admin->update(['password' => 'sandi-rahasia-1']);
        $this->actingInTenant($admin, $this->tenant);
        $id = $this->tenant->getKey();

        $this->deleteWith(['confirm' => 'griya-prima', 'password' => 'sandi-rahasia-1'])->assertHasNoActionErrors();

        $this->assertNull(Tenant::find($id));
        foreach (['leads', 'lead_activities', 'properties', 'wa_channels', 'wa_conversations', 'wa_messages', 'landing_pages', 'support_threads', 'stages', 'subscriptions', 'tenant_user', 'follow_up_rules', 'lead_sources'] as $table) {
            $this->assertSame(0, DB::table($table)->where('tenant_id', $id)->count(), $table);
        }
        Storage::disk('public')->assertMissing("landing/$id/foto.webp");
        $this->assertNull(User::find($agent->getKey()), 'a member of no other agency goes too');
        $this->assertNotNull(User::find($admin->getKey()), 'the admin keeps their own account');

        $this->assertNotNull(Tenant::find($this->other->getKey()));
        $this->assertSame(1, DB::table('leads')->where('tenant_id', $this->other->getKey())->count());
        Storage::disk('public')->assertExists("landing/{$this->other->getKey()}/foto.webp");
    }

    public function test_members_of_another_agency_and_super_admins_keep_their_accounts(): void
    {
        $shared = $this->member($this->tenant, Role::Agent);
        $this->other->users()->attach($shared, ['role' => Role::Agent->value, 'status' => 'active']);
        $boss = $this->member($this->tenant, Role::Manager);
        $boss->forceFill(['is_super_admin' => true])->save();
        $admin = $this->adminOf($this->tenant);
        $admin->update(['password' => 'sandi-rahasia-1']);
        $this->actingInTenant($admin, $this->tenant);

        $this->deleteWith(['confirm' => 'griya-prima', 'password' => 'sandi-rahasia-1']);

        $this->assertNotNull(User::find($shared->getKey()));
        $this->assertNotNull(User::find($boss->getKey()));
        $this->assertTrue($shared->fresh()->canAccessTenant($this->other));
    }

    public function test_a_wrong_workspace_name_or_password_deletes_nothing(): void
    {
        $this->seedData($this->tenant, 'satu');
        $admin = $this->adminOf($this->tenant);
        $admin->update(['password' => 'sandi-rahasia-1']);
        $this->actingInTenant($admin, $this->tenant);

        $this->deleteWith(['confirm' => 'salah', 'password' => 'sandi-rahasia-1'])->assertHasActionErrors(['confirm']);
        $this->deleteWith(['confirm' => 'griya-prima', 'password' => 'salah-banget'])->assertHasActionErrors(['password']);

        $this->assertNotNull(Tenant::find($this->tenant->getKey()));
        $this->assertSame(1, DB::table('leads')->where('tenant_id', $this->tenant->getKey())->count());
    }

    public function test_a_non_admin_cannot_delete_the_agency(): void
    {
        $agent = $this->member($this->tenant, Role::Agent);
        $agent->update(['password' => 'sandi-rahasia-1']);
        $this->actingInTenant($agent, $this->tenant);

        try {
            $this->deleteWith(['confirm' => 'griya-prima', 'password' => 'sandi-rahasia-1']);
        } catch (\Throwable) {
            // refused one way or another
        }

        $this->assertNotNull(Tenant::find($this->tenant->getKey()));
    }

    public function test_deleting_works_when_the_plan_has_ended_and_needs_no_super_admin(): void
    {
        $this->tenant->currentSubscription()->update(['trial_ends_at' => now()->subDays(30)]);
        $admin = $this->adminOf($this->tenant);
        $admin->update(['password' => 'sandi-rahasia-1']);
        $this->actingInTenant($admin, $this->tenant);
        $this->assertFalse((bool) $admin->is_super_admin);

        $this->deleteWith(['confirm' => 'griya-prima', 'password' => 'sandi-rahasia-1'])->assertHasNoActionErrors();

        $this->assertNull(Tenant::find($this->tenant->getKey()));
    }
}
