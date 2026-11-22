<?php

namespace Tests\Feature;

use App\Actions\ProvisionTenant;
use App\Backup\BackupRunner;
use App\Backup\DatabaseOwnership;
use App\Backup\Shell;
use App\Console\Commands\BackupMonitorCommand;
use App\Console\Commands\BackupRestoreCheckCommand;
use App\Filament\Admin\Resources\BackupRuns\Pages\ListBackupRuns;
use App\Filament\Admin\Widgets\BackupStatus;
use App\Jobs\SendPushAlert;
use App\Mail\BackupFailed;
use App\Mail\BackupStale;
use App\Models\BackupRun;
use App\Models\Lead;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaConversation;
use App\Models\WaMessage;
use App\Push\PushTransport;
use App\Support\CurrentTenant;
use App\WhatsApp\MessageContent;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** Stands in for pg_dump, gzip, tar and rclone: records every call and creates the files the real tools would. */
class FakeShell extends Shell
{
    /** @var list<array{cmd: list<string>, env: array<string,string>}> */
    public array $calls = [];

    /** @var array<string, int> "tool" or "tool subcommand" (e.g. "rclone copy") => exit code */
    public array $exit = [];

    /** @var array<string, list<string>> what `rclone lsf` lists in a remote folder */
    public array $remote = [];

    /** @var list<float> free disk percentages, one per reading (the last repeats) */
    public array $free = [90.0];

    public bool $rclone = true;

    public function run(array $command, array $env = [], ?callable $onOutput = null, int $timeout = 3600): array
    {
        $this->calls[] = ['cmd' => $command, 'env' => $env];
        $tool = $command[0];
        $sub = $tool.' '.($command[1] ?? '');

        if ($tool === 'pg_dump' && $onOutput) {
            $onOutput("-- fake dump\nCREATE TABLE t();\n");
        }
        if ($tool === 'tar') {
            file_put_contents($command[2], 'fake tar');
        }
        if ($sub === 'rclone lsf' && $onOutput) {
            $onOutput(implode("\n", $this->remote[end($command)] ?? [])."\n");
        }

        $code = $this->exit[$sub] ?? $this->exit[$tool] ?? 0;

        return [$code, $code ? 'boom password=rahasia123 gagal' : ''];
    }

    public function freePercent(string $path): ?float
    {
        return count($this->free) > 1 ? array_shift($this->free) : $this->free[0];
    }

    public function exists(string $binary): bool
    {
        return $this->rclone;
    }

    public function used(string $tool, ?string $sub = null): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['cmd'][0] === $tool && ($sub === null || ($c['cmd'][1] ?? null) === $sub)));
    }

    /** @return list<string> the last argument of every matching call */
    public function targets(string $tool, string $sub): array
    {
        return array_map(fn ($c) => end($c['cmd']), $this->used($tool, $sub));
    }
}

class FakeOwnership extends DatabaseOwnership
{
    public array $rows = [];

    public function foreign(): array
    {
        return $this->rows;
    }

    public function user(): string
    {
        return 'jitu';
    }
}

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private FakeShell $shell;

    private FakeOwnership $ownership;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/jitu-backup-test-'.uniqid();
        $this->shell = new FakeShell;
        $this->ownership = new FakeOwnership;
        $this->app->instance(Shell::class, $this->shell);
        $this->app->instance(DatabaseOwnership::class, $this->ownership);
        config([
            'jitu.backup.path' => $this->dir, 'jitu.backup.gateway_path' => $this->dir.'-gw-none',
            'jitu.backup.rclone_remote' => null, 'jitu.backup.rclone_env' => [], 'jitu.support_email' => 'bantuan@example.com',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory($this->dir.'-gw');
        parent::tearDown();
    }

    private function runBackup(string $kind = BackupRunner::FULL): BackupRun
    {
        return app(BackupRunner::class)($kind);
    }

    private function offsite(): void
    {
        config(['jitu.backup.rclone_remote' => 'offsite:jitu', 'jitu.backup.rclone_env' => ['RCLONE_CONFIG_OFFSITE_TYPE' => 's3']]);
    }

    private function stamp(int $daysAgo): string
    {
        return now()->subDays($daysAgo)->timezone(config('app.timezone'))->format('Ymd-His');
    }

    private function step(BackupRun $run, string $label): ?array
    {
        return collect($run->steps)->first(fn ($s) => str_starts_with($s['label'], $label));
    }

    // ---- What a run makes -----------------------------------------------------------------------------------------

    public function test_a_run_writes_a_checked_private_dump_and_an_uploads_archive_and_warns_that_nothing_is_off_server(): void
    {
        Mail::fake();

        $run = $this->runBackup();

        $this->assertSame(BackupRun::WARNING, $run->status);
        $this->assertSame('full', $run->kind);
        $this->assertStringContainsString('Salinan di luar server', $run->message);
        $this->assertStringStartsWith($this->dir.'/db/database-', $run->database_file);
        $this->assertSame('-- fake dump', trim(explode("\n", gzdecode(file_get_contents($run->database_file)))[0]));
        $this->assertSame(['gzip', '-t', '--', $run->database_file], $this->shell->used('gzip')[0]['cmd']);
        $this->assertCount(1, glob($this->dir.'/storage/storage-*.tar.gz'));
        $this->assertSame('0600', substr(sprintf('%o', fileperms($run->database_file)), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->dir.'/db')), -4));
        $this->assertSame([], $this->shell->used('rclone'));
        Mail::assertNothingSent();
    }

    public function test_the_database_password_goes_by_environment_not_on_the_command_line(): void
    {
        config(['database.connections.pgsql.password' => 'sandi-db-rahasia']);

        $this->runBackup();

        $dump = $this->shell->used('pg_dump')[0];
        $this->assertSame('sandi-db-rahasia', $dump['env']['PGPASSWORD']);
        $this->assertStringNotContainsString('sandi-db-rahasia', implode(' ', $dump['cmd']));
    }

    public function test_gateway_sessions_get_their_own_tarball_when_the_folder_exists(): void
    {
        File::ensureDirectoryExists($this->dir.'-gw');
        config(['jitu.backup.gateway_path' => $this->dir.'-gw']);

        $run = $this->runBackup();

        $this->assertCount(1, glob($this->dir.'/sessions/gateway-*.tar.gz'));
        $this->assertSame('ok', $this->step($run, 'Sesi WhatsApp gateway')['status']);
    }

    public function test_a_database_run_dumps_only_the_database(): void
    {
        File::ensureDirectoryExists($this->dir.'-gw');
        config(['jitu.backup.gateway_path' => $this->dir.'-gw']);
        $this->offsite();

        $run = $this->runBackup(BackupRunner::DATABASE);

        $this->assertSame(BackupRun::OK, $run->status);
        $this->assertSame('database', $run->kind);
        $this->assertNotNull($run->database_file);
        $this->assertSame([], $this->shell->used('tar'));
        $this->assertSame(['offsite:jitu/db'], $this->shell->targets('rclone', 'copy'));
        $this->assertSame([], $this->shell->used('rclone', 'sync'));
    }

    public function test_a_dump_that_fails_gzip_t_is_not_kept_or_counted_valid(): void
    {
        Mail::fake();
        $this->shell->exit['gzip'] = 1;

        $run = $this->runBackup(BackupRunner::DATABASE);

        $this->assertSame(BackupRun::FAILED, $run->status);
        $this->assertNull($run->database_file);
        $this->assertSame([], glob($this->dir.'/db/*'));
        $this->assertStringContainsString('gzip -t', $this->step($run, 'Database')['note']);
        $this->assertNull(BackupRun::latestValidDatabase());
    }

    // ---- Off the server -------------------------------------------------------------------------------------------

    public function test_offsite_the_dump_goes_first_and_on_its_own_then_sessions_then_the_mirror(): void
    {
        File::ensureDirectoryExists($this->dir.'-gw');
        config(['jitu.backup.gateway_path' => $this->dir.'-gw']);
        $this->offsite();

        $run = $this->runBackup();

        $this->assertSame(BackupRun::OK, $run->status, (string) $run->message);
        $uploads = array_values(array_filter($this->shell->used('rclone'), fn ($c) => in_array($c['cmd'][1], ['copy', 'sync'], true)));
        $this->assertSame(['copy', 'copy', 'sync'], array_map(fn ($c) => $c['cmd'][1], $uploads));
        $this->assertSame(['offsite:jitu/db', 'offsite:jitu/sessions', 'offsite:jitu/storage'], array_map(fn ($c) => end($c['cmd']), $uploads));
        $this->assertSame('s3', $uploads[0]['env']['RCLONE_CONFIG_OFFSITE_TYPE']);

        // The mirror sends only changes, keeps what changed aside by date, and leaves the backups folder out.
        $sync = $uploads[2]['cmd'];
        $this->assertSame(storage_path('app'), $sync[count($sync) - 2]);
        $this->assertStringStartsWith('offsite:jitu/storage-deleted/', $sync[array_search('--backup-dir', $sync) + 1]);
        $this->assertSame([], glob($this->dir.'/storage/*') ?: []);
    }

    public function test_the_mirror_leaves_out_the_backup_folder_when_it_lies_inside_storage(): void
    {
        $this->offsite();
        config(['jitu.backup.path' => storage_path('app/backups-test-'.uniqid())]);

        try {
            $this->runBackup();
            $sync = $this->shell->used('rclone', 'sync')[0]['cmd'];
            $this->assertSame('/'.basename(config('jitu.backup.path')).'/**', $sync[array_search('--exclude', $sync) + 1]);
        } finally {
            File::deleteDirectory(config('jitu.backup.path'));
        }
    }

    public function test_old_remote_copies_are_removed_even_when_the_upload_fails_but_never_the_newest(): void
    {
        Mail::fake();
        $this->offsite();
        config(['jitu.backup.database.remote_keep_days' => 60]);
        $this->shell->exit['rclone copy'] = 7; // storage_cap_exceeded
        [$d90, $d70, $d3] = [$this->stamp(90), $this->stamp(70), $this->stamp(3)];
        $this->shell->remote['offsite:jitu/db'] = ["database-$d90.sql.gz", "database-$d70.sql.gz", "database-$d3.sql.gz"];

        $run = $this->runBackup(BackupRunner::DATABASE);

        $this->assertSame(BackupRun::FAILED, $run->status);
        $this->assertEqualsCanonicalizing(["offsite:jitu/db/database-$d90.sql.gz", "offsite:jitu/db/database-$d70.sql.gz"], $this->shell->targets('rclone', 'deletefile'));
        // Removing came before the upload, so a full bucket frees itself.
        $order = array_map(fn ($c) => $c['cmd'][1], $this->shell->used('rclone'));
        $this->assertLessThan(array_search('copy', $order), array_search('deletefile', $order));
        $this->assertStringContainsString('2 salinan lama dihapus', $this->step($run, 'Database ke luar server')['note']);
    }

    public function test_the_newest_remote_dump_stays_however_old_it_is(): void
    {
        $this->offsite();
        $this->shell->exit['rclone copy'] = 7;
        [$d200, $d100] = [$this->stamp(200), $this->stamp(100)];
        $this->shell->remote['offsite:jitu/db'] = ["database-$d200.sql.gz", "database-$d100.sql.gz"];

        $this->runBackup(BackupRunner::DATABASE);

        $this->assertSame(["offsite:jitu/db/database-$d200.sql.gz"], $this->shell->targets('rclone', 'deletefile'));
    }

    public function test_old_dated_folders_on_the_remote_go_once_dumps_exist_in_the_new_layout(): void
    {
        $this->offsite();
        config(['jitu.backup.legacy_remote_keep_days' => 7]);
        [$d40, $d20, $d10, $d5, $d2] = [$this->stamp(40), $this->stamp(20), $this->stamp(10), $this->stamp(5), $this->stamp(2)];
        $this->shell->remote['offsite:jitu'] = ["$d20/", "$d10/", "$d2/", 'db/', 'storage/'];
        $this->shell->remote['offsite:jitu/storage-deleted'] = ["$d40/", "$d5/"];

        $run = $this->runBackup();

        $this->assertEqualsCanonicalizing(["offsite:jitu/storage-deleted/$d40", "offsite:jitu/$d20", "offsite:jitu/$d10"], $this->shell->targets('rclone', 'purge'));
        $this->assertStringContainsString('2 folder bertanggal lama dihapus', $this->step($run, 'Salinan lama')['note']);
    }

    public function test_the_newest_old_dated_folder_stays_while_the_remote_has_no_new_dump(): void
    {
        Mail::fake();
        $this->offsite();
        config(['jitu.backup.legacy_remote_keep_days' => 7]);
        $this->shell->exit['pg_dump'] = 1;
        [$d20, $d10] = [$this->stamp(20), $this->stamp(10)];
        $this->shell->remote['offsite:jitu'] = ["$d20/", "$d10/"];

        $this->runBackup();

        $this->assertSame(["offsite:jitu/$d20"], $this->shell->targets('rclone', 'purge'));
    }

    public function test_a_failed_dump_with_a_working_mirror_still_fails_the_run_and_alerts(): void
    {
        Mail::fake();
        $this->offsite();
        $this->shell->exit['pg_dump'] = 1;

        $run = $this->runBackup();

        $this->assertSame(BackupRun::FAILED, $run->status);
        $this->assertSame('ok', $this->step($run, 'Unggahan (cermin')['status']);
        $this->assertSame('Gagal: Database (pg_dump)', $run->message);
        Mail::assertSent(BackupFailed::class);
    }

    public function test_a_failed_mirror_keeps_the_dump_valid(): void
    {
        Mail::fake();
        $this->offsite();
        $this->shell->exit['rclone sync'] = 1;

        $run = $this->runBackup();

        $this->assertSame(BackupRun::FAILED, $run->status);
        $this->assertSame('ok', $this->step($run, 'Database ke luar server')['status']);
        $this->assertSame($run->getKey(), BackupRun::latestValidDatabase()->getKey());
    }

    public function test_rclone_missing_or_a_bad_remote_is_reported_not_run(): void
    {
        config(['jitu.backup.rclone_remote' => 'offsite:jitu']);
        $this->shell->rclone = false;
        $this->assertSame(BackupRun::FAILED, $this->runBackup()->status);

        config(['jitu.backup.rclone_remote' => '--evil']);
        $this->shell->rclone = true;
        $this->runBackup();
        $this->assertSame([], $this->shell->used('rclone'));
    }

    // ---- Table ownership ------------------------------------------------------------------------------------------

    public function test_tables_the_app_does_not_own_are_named_and_unreadable_ones_left_out_of_the_dump(): void
    {
        $this->ownership->rows = [
            ['name' => 'backup_landing_pages_20261119', 'owner' => 'postgres', 'readable' => false],
            ['name' => 'catatan_manual', 'owner' => 'postgres', 'readable' => true],
        ];

        $run = $this->runBackup(BackupRunner::DATABASE);

        $this->assertSame(BackupRun::WARNING, $run->status);
        $note = $this->step($run, 'Kepemilikan tabel')['note'];
        $this->assertStringContainsString('backup_landing_pages_20261119 (pemilik postgres)', $note);
        $this->assertStringContainsString('TIDAK ikut dicadangkan: backup_landing_pages_20261119', $note);
        $this->assertStringContainsString('OWNER TO jitu', $note);
        $cmd = $this->shell->used('pg_dump')[0]['cmd'];
        $this->assertSame('"backup_landing_pages_20261119"', $cmd[array_search('--exclude-table', $cmd) + 1]);
        $this->assertCount(1, array_keys($cmd, '--exclude-table', true));
        $this->assertNotNull($run->database_file, 'the rest of the database is still saved');
    }

    public function test_the_ownership_query_runs_on_postgres(): void
    {
        $this->assertSame([], (new DatabaseOwnership)->foreign(), 'the test database is owned by its user');
    }

    // ---- Local retention ------------------------------------------------------------------------------------------

    public function test_old_local_copies_are_rotated_by_kind_but_the_newest_and_strangers_stay(): void
    {
        File::ensureDirectoryExists($old = $this->dir.'/20200101-020000');
        File::ensureDirectoryExists($other = $this->dir.'/jangan-dihapus');
        File::ensureDirectoryExists($this->dir.'/db');
        File::ensureDirectoryExists($this->dir.'/sessions');
        file_put_contents($old.'/database.sql.gz', 'x');
        file_put_contents($dump20 = $this->dir.'/db/database-20200101-020000.sql.gz', 'x');
        file_put_contents($dump10 = $this->dir.'/db/database-20200102-020000.sql.gz', 'x');
        file_put_contents($session = $this->dir.'/sessions/gateway-20200101-020000.tar.gz', 'x');
        touch($old, now()->subDays(30)->getTimestamp());
        touch($other, now()->subDays(30)->getTimestamp());
        touch($dump20, now()->subDays(20)->getTimestamp());
        touch($dump10, now()->subDays(10)->getTimestamp());
        touch($session, now()->subDays(10)->getTimestamp());
        config(['jitu.backup.database.keep_days' => 14, 'jitu.backup.keep_days' => 7]);

        $this->runBackup();

        $this->assertDirectoryDoesNotExist($old);
        $this->assertFileDoesNotExist($dump20);
        $this->assertFileExists($dump10, 'dumps are kept 14 days');
        $this->assertFileExists($session, 'the only sessions tarball is the newest: it stays');
        $this->assertDirectoryExists($other);
    }

    public function test_a_full_disk_removes_the_oldest_local_copies_first_but_never_the_newest_valid_dump(): void
    {
        File::ensureDirectoryExists($legacy = $this->dir.'/20200101-020000');
        File::ensureDirectoryExists($this->dir.'/sessions');
        file_put_contents($legacy.'/storage.tar.gz', 'x');
        file_put_contents($session = $this->dir.'/sessions/gateway-20991231-020000.tar.gz', 'x');
        touch($legacy, now()->subDays(3)->getTimestamp());
        touch($session, now()->subDays(2)->getTimestamp());
        config(['jitu.backup.disk_min_free_percent' => 20]);
        // 12% free; 15% after the first removal; 25% after the second.
        $this->shell->free = [12.0, 15.0, 25.0];

        $run = $this->runBackup(BackupRunner::DATABASE);

        $this->assertDirectoryDoesNotExist($legacy);
        $this->assertFileDoesNotExist($session);
        $this->assertFileExists($run->database_file);
        $this->assertSame('warning', $this->step($run, 'Ruang disk')['status']);
        $this->assertStringContainsString('2 cadangan lokal tertua dihapus, sekarang 25', $this->step($run, 'Ruang disk')['note']);
    }

    public function test_the_newest_dump_survives_a_disk_that_stays_full(): void
    {
        $this->shell->free = [5.0];

        $run = $this->runBackup(BackupRunner::DATABASE);

        $this->assertFileExists($run->database_file);
        $this->assertStringContainsString('kosongkan disk server', $this->step($run, 'Ruang disk')['note']);
    }

    // ---- Alerts ---------------------------------------------------------------------------------------------------

    public function test_a_failed_step_fails_the_run_emails_and_pushes_to_the_owner_and_hides_secrets(): void
    {
        Mail::fake();
        Bus::fake([SendPushAlert::class]);
        $this->app->instance(PushTransport::class, new class extends PushTransport
        {
            public function enabled(): bool
            {
                return true;
            }
        });
        $owner = User::factory()->create();
        $owner->forceFill(['is_super_admin' => true])->save();
        User::factory()->create();
        config(['database.connections.pgsql.password' => 'rahasia123']);
        $this->shell->exit['pg_dump'] = 1;

        $run = $this->runBackup(BackupRunner::DATABASE);

        $this->assertSame(BackupRun::FAILED, $run->status);
        $this->assertStringNotContainsString('rahasia123', json_encode($run->steps));
        Mail::assertSent(BackupFailed::class, fn ($m) => $m->hasTo('bantuan@example.com'));
        Bus::assertDispatchedSync(SendPushAlert::class, fn ($job) => $job->userIds === [$owner->getKey()] && $job->payload['title'] === 'Backup database GAGAL');
    }

    public function test_the_failure_email_renders_with_the_error_text(): void
    {
        $run = BackupRun::create(['status' => BackupRun::FAILED, 'started_at' => now(), 'message' => 'pg_dump gagal', 'steps' => [['label' => 'Database', 'status' => 'gagal', 'note' => null]]]);

        $html = (new BackupFailed($run))->render();

        $this->assertStringContainsString('pg_dump gagal', $html);
        $this->assertStringContainsString('Database: gagal', $html);
    }

    public function test_the_monitor_alerts_once_when_no_valid_dump_is_newer_than_26_hours(): void
    {
        Mail::fake();
        config(['jitu.backup.stale_hours' => 26]);

        // A fresh install stays quiet.
        $this->artisan('backup:monitor')->assertSuccessful();
        Mail::assertNothingSent();

        $run = $this->runBackup(BackupRunner::DATABASE);
        $run->forceFill(['finished_at' => now()->subHours(20)])->save();
        $this->artisan('backup:monitor')->assertSuccessful();
        Mail::assertNothingSent();

        $run->forceFill(['finished_at' => now()->subHours(30)])->save();
        $this->artisan('backup:monitor')->assertSuccessful();
        $this->artisan('backup:monitor')->assertSuccessful();
        Mail::assertSent(BackupStale::class, 1);
        $this->assertStringContainsString('26 jam</strong> tidak ada cadangan database', (new BackupStale($run, 26))->render());

        // After 6 hours it reminds again; a new valid dump ends it.
        Cache::forget(BackupMonitorCommand::ALERTED);
        $this->artisan('backup:monitor');
        Mail::assertSent(BackupStale::class, 2);
        $this->runBackup(BackupRunner::DATABASE);
        $this->artisan('backup:monitor');
        Mail::assertSent(BackupStale::class, 2);
    }

    public function test_the_monitor_alerts_when_runs_happen_but_none_ever_produced_a_valid_dump(): void
    {
        Mail::fake();
        BackupRun::create(['status' => BackupRun::FAILED, 'started_at' => now()->subDays(3), 'finished_at' => now()->subDays(3)]);

        $this->artisan('backup:monitor');

        Mail::assertSent(BackupStale::class);
    }

    // ---- Commands, schedule, panel --------------------------------------------------------------------------------

    public function test_the_commands_report_and_fail_on_error(): void
    {
        $this->artisan('backup:run')->assertSuccessful();
        $this->artisan('backup:database')->assertSuccessful();
        $this->shell->exit['tar'] = 2;
        $this->artisan('backup:run')->assertFailed();
        $this->shell->exit['pg_dump'] = 1;
        $this->artisan('backup:database')->assertFailed();
        $this->assertSame(4, BackupRun::count());
    }

    public function test_backups_are_scheduled_in_jakarta_time(): void
    {
        $events = collect(app(Schedule::class)->events());
        $find = fn (string $name) => $events->first(fn ($e) => str_contains($e->command, $name));

        $this->assertSame('0 2 * * *', $find('backup:run')->expression);
        $this->assertSame('Asia/Jakarta', (string) $find('backup:run')->timezone);
        $this->assertSame('0 */6 * * *', $find('backup:database')->expression);
        $this->assertSame('Asia/Jakarta', (string) $find('backup:database')->timezone);
        $this->assertSame('30 * * * *', $find('backup:monitor')->expression);
    }

    public function test_the_status_shows_in_the_admin_panel_for_the_owner_only(): void
    {
        $this->runBackup();
        $owner = User::factory()->create();
        $owner->forceFill(['is_super_admin' => true])->save();
        $this->actingAs(User::factory()->create())->get('/admin/backup-runs')->assertForbidden();

        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get('/admin/backup-runs')->assertOk();
        Livewire::test(BackupStatus::class)->assertSee('Backup terakhir')->assertSee('Berhasil dengan peringatan')
            ->assertSee('Database valid terakhir')->assertSee('Disk server');
        Livewire::test(ListBackupRuns::class)->assertCanSeeTableRecords(BackupRun::all());
    }

    public function test_the_widget_turns_alarming_when_backups_are_old_or_the_disk_fills(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(BackupStatus::class)->assertSee('Belum pernah')->assertSee('Belum ada');

        $run = $this->runBackup();
        $run->update(['started_at' => now()->subHours(40), 'finished_at' => now()->subHours(40)]);
        $this->shell->free = [25.0];
        Livewire::test(BackupStatus::class)->assertSee('lebih dari 36 jam')->assertSee('lebih dari 26 jam')->assertSee('75% terpakai')->assertSee('Lebih dari 70%');

        // The 6-hourly database runs do not hide the nightly run.
        $this->runBackup(BackupRunner::DATABASE);
        Livewire::test(BackupStatus::class)->assertSee('lebih dari 36 jam')->assertDontSee('lebih dari 26 jam');
    }
    // ---- Restore check (the real tools) ---------------------------------------------------------------------------

    private function realTools(): void
    {
        foreach (['pg_dump', 'psql', 'gzip', 'bash'] as $tool) {
            if (! (new Shell)->exists($tool)) {
                $this->markTestSkipped("$tool tidak terpasang");
            }
        }
        $this->app->instance(Shell::class, new Shell);
        $this->app->instance(DatabaseOwnership::class, new DatabaseOwnership);
    }

    public function test_the_newest_real_dump_passes_the_restore_check_in_a_temporary_database(): void
    {
        $this->realTools();
        config(['jitu.backup.database.min_tables' => 30]);

        $run = $this->runBackup(BackupRunner::DATABASE);
        $this->assertNotNull($run->database_file, (string) $run->message);
        $this->assertGreaterThanOrEqual(30, BackupRestoreCheckCommand::tables($run->database_file));

        $this->artisan('backup:restore-check')
            ->expectsOutputToContain('gzip -t: arsip utuh')
            ->expectsOutputToContain('Uji muat: dimuat ke jitu_restore_check_')
            ->assertSuccessful();

        // The temporary database is gone again; the test database is untouched.
        $this->assertSame([], DB::select("select datname from pg_database where datname like 'jitu_restore_check_%'"));
        $this->assertSame(1, BackupRun::query()->count());
    }

    public function test_a_broken_or_too_small_dump_fails_the_restore_check(): void
    {
        $this->realTools();
        File::ensureDirectoryExists($this->dir.'/db');
        file_put_contents($broken = $this->dir.'/db/database-20990101-000000.sql.gz', 'bukan gzip');
        $this->artisan('backup:restore-check')->expectsOutputToContain('GAGAL')->assertFailed();

        file_put_contents($small = $this->dir.'/db/database-20990102-000000.sql.gz', gzencode("CREATE TABLE public.satu (id int);\n"));
        touch($small, time() + 10);
        $this->artisan('backup:restore-check', ['--no-load' => true])->expectsOutputToContain('Jumlah tabel: 1 tabel (minimal 40)')->assertFailed();

        $this->artisan('backup:restore-check', ['--file' => $this->dir.'/tidak-ada.sql.gz'])->assertFailed();
    }

    // ---- Media retention ------------------------------------------------------------------------------------------

    /** @return array{0: Tenant, 1: WaMessage, 2: WaMessage} a tenant with one old and one new picture */
    private function media(): array
    {
        Storage::fake('local');
        $tenant = app(ProvisionTenant::class)('Agensi Media', 'agensi-media', User::factory()->create(), 'properti');

        [$old, $new] = app(CurrentTenant::class)->run($tenant, function () use ($tenant) {
            $lead = Lead::create(['name' => 'Klien Uji', 'phone' => '+6289900000071', 'stage_id' => Stage::query()->value('id')]);
            $conversation = WaConversation::create(['lead_id' => $lead->getKey(), 'phone' => $lead->phone]);
            $make = function (string $name, int $daysAgo) use ($conversation, $tenant) {
                $path = "wa-media/{$tenant->getKey()}/$name.jpg";
                Storage::disk('local')->put($path, 'gambar');
                touch(Storage::disk('local')->path($path), now()->subDays($daysAgo)->getTimestamp());

                return WaMessage::create(['conversation_id' => $conversation->getKey(), 'direction' => 'in', 'type' => 'image', 'sent_at' => now(), 'media' => ['mime_type' => 'image/jpeg', 'path' => $path, 'bytes' => 6]]);
            };

            return [$make('lama', 120), $make('baru', 10)];
        });

        return [$tenant, $old, $new];
    }

    public function test_media_pruning_is_a_dry_run_by_default_and_writes_the_list(): void
    {
        [$tenant, $old] = $this->media();

        $this->artisan('backup:prune-media')
            ->expectsOutputToContain("agensi {$tenant->getKey()}: 1 berkas")
            ->expectsOutputToContain('Uji kering')
            ->assertSuccessful();

        Storage::disk('local')->assertExists($old->media['path']);
        $log = collect(glob(storage_path('logs/media-prune-*-uji-kering.log')))->sortByDesc(fn ($f) => filemtime($f))->first();
        $this->assertStringContainsString($old->media['path'], file_get_contents($log));
        $this->assertStringNotContainsString('baru.jpg', file_get_contents($log));
        @unlink($log);
    }

    public function test_media_pruning_deletes_only_old_files_and_marks_their_messages(): void
    {
        [$tenant, $old, $new] = $this->media();

        $this->artisan('backup:prune-media', ['--apply' => true, '--mode' => 'delete', '--tenant' => ['agensi-media']])->assertSuccessful();

        Storage::disk('local')->assertMissing($old->media['path']);
        Storage::disk('local')->assertExists($new->media['path']);
        $old = WaMessage::withoutGlobalScopes()->find($old->getKey());
        $this->assertArrayNotHasKey('path', $old->media);
        $this->assertSame('expired', $old->media['error']);
        $this->assertSame('[Gambar sudah dihapus dari server karena sudah lama]', MessageContent::unavailable($old));
        $this->assertArrayHasKey('path', WaMessage::withoutGlobalScopes()->find($new->getKey())->media);
        array_map('unlink', glob(storage_path('logs/media-prune-*-delete.log')) ?: []);
    }

    public function test_archiving_media_moves_it_off_the_server_first_and_changes_nothing_when_that_fails(): void
    {
        [, $old] = $this->media();

        // No remote: refused, nothing changed.
        $this->artisan('backup:prune-media', ['--apply' => true])->assertFailed();
        Storage::disk('local')->assertExists($old->media['path']);

        $this->offsite();
        $this->shell->exit['rclone move'] = 1;
        $this->artisan('backup:prune-media', ['--apply' => true])->assertFailed();
        $this->assertSame('image/jpeg', WaMessage::withoutGlobalScopes()->find($old->getKey())->media['mime_type']);
        $this->assertArrayHasKey('path', WaMessage::withoutGlobalScopes()->find($old->getKey())->media);

        $this->shell->exit['rclone move'] = 0;
        $this->artisan('backup:prune-media', ['--apply' => true])->assertSuccessful();
        $move = $this->shell->used('rclone', 'move')[1]['cmd'];
        $this->assertSame('offsite:jitu/media-archive', end($move));
        $this->assertSame('archived', WaMessage::withoutGlobalScopes()->find($old->getKey())->media['error']);
        array_map('unlink', glob(storage_path('logs/media-prune-*.log')) ?: []);
    }

    public function test_media_pruning_asks_again_in_production(): void
    {
        [, $old] = $this->media();
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('backup:prune-media', ['--apply' => true, '--mode' => 'delete'])
            ->expectsConfirmation('Yakin MENGHAPUS 1 berkas media ini?', 'no')
            ->assertFailed();

        Storage::disk('local')->assertExists($old->media['path']);
        array_map('unlink', glob(storage_path('logs/media-prune-*.log')) ?: []);
    }
}
