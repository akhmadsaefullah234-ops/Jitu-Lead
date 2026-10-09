<?php

namespace Tests\Feature;

use App\Backup\BackupRunner;
use App\Backup\Shell;
use App\Filament\Admin\Resources\BackupRuns\Pages\ListBackupRuns;
use App\Filament\Admin\Widgets\BackupStatus;
use App\Mail\BackupFailed;
use App\Models\BackupRun;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** Stands in for pg_dump, tar and rclone: records every call and creates the files the real tools would. */
class FakeShell extends Shell
{
    /** @var list<array{cmd: list<string>, env: array<string,string>}> */
    public array $calls = [];

    /** @var array<string, int> tool name => exit code */
    public array $exit = [];

    public bool $rclone = true;

    public function run(array $command, array $env = [], ?callable $onOutput = null, int $timeout = 3600): array
    {
        $this->calls[] = ['cmd' => $command, 'env' => $env];
        $tool = $command[0];

        if ($tool === 'pg_dump' && $onOutput) {
            $onOutput("-- fake dump\nCREATE TABLE t();\n");
        }
        if ($tool === 'tar') {
            file_put_contents($command[2], 'fake tar');
        }

        $code = $this->exit[$tool] ?? 0;

        return [$code, $code ? 'boom password=rahasia123 gagal' : ''];
    }

    public function exists(string $binary): bool
    {
        return $this->rclone;
    }

    public function used(string $tool): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['cmd'][0] === $tool));
    }
}

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private FakeShell $shell;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/jitu-backup-test-'.uniqid();
        $this->shell = new FakeShell;
        $this->app->instance(Shell::class, $this->shell);
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

    private function runBackup(): BackupRun
    {
        return app(BackupRunner::class)();
    }

    public function test_a_run_writes_private_database_and_upload_archives_and_warns_that_nothing_is_off_server(): void
    {
        Mail::fake();

        $run = $this->runBackup();

        $this->assertSame(BackupRun::WARNING, $run->status);
        $this->assertStringContainsString('Salinan di luar server', $run->message);
        $this->assertSame('-- fake dump', trim(explode("\n", gzdecode(file_get_contents($run->folder.'/database.sql.gz')))[0]));
        $this->assertFileExists($run->folder.'/storage.tar.gz');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($run->folder.'/database.sql.gz')), -4));
        $this->assertSame('0700', substr(sprintf('%o', fileperms($run->folder)), -4));
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

    public function test_gateway_sessions_are_included_when_the_folder_exists(): void
    {
        File::ensureDirectoryExists($this->dir.'-gw');
        config(['jitu.backup.gateway_path' => $this->dir.'-gw']);

        $run = $this->runBackup();

        $this->assertFileExists($run->folder.'/gateway.tar.gz');
        $this->assertContains('ok', collect($run->steps)->where('label', 'Sesi WhatsApp gateway')->pluck('status')->all());
    }

    public function test_an_off_server_copy_is_made_through_rclone_and_old_remote_copies_are_pruned(): void
    {
        config(['jitu.backup.rclone_remote' => 'offsite:jitu', 'jitu.backup.rclone_env' => ['RCLONE_CONFIG_OFFSITE_TYPE' => 's3']]);

        $run = $this->runBackup();

        $this->assertSame(BackupRun::OK, $run->status);
        $copy = $this->shell->used('rclone')[0];
        $this->assertSame(['rclone', 'copy', '--'], array_slice($copy['cmd'], 0, 3));
        $this->assertSame('s3', $copy['env']['RCLONE_CONFIG_OFFSITE_TYPE']);
        $this->assertStringStartsWith('offsite:jitu/', end($copy['cmd']));
        $this->assertContains('delete', array_column(array_column($this->shell->used('rclone'), 'cmd'), 1));
    }

    public function test_a_failed_step_fails_the_run_emails_support_and_hides_secrets(): void
    {
        Mail::fake();
        config(['database.connections.pgsql.password' => 'rahasia123']);
        $this->shell->exit['pg_dump'] = 1;

        $run = $this->runBackup();

        $this->assertSame(BackupRun::FAILED, $run->status);
        $this->assertStringNotContainsString('rahasia123', json_encode($run->steps));
        Mail::assertSent(BackupFailed::class, fn ($m) => $m->hasTo('bantuan@example.com'));
    }

    public function test_a_failed_off_server_copy_counts_as_failure(): void
    {
        Mail::fake();
        config(['jitu.backup.rclone_remote' => 'offsite:jitu']);
        $this->shell->exit['rclone'] = 1;

        $this->assertSame(BackupRun::FAILED, $this->runBackup()->status);
        Mail::assertSent(BackupFailed::class);
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

    public function test_old_local_folders_are_rotated_but_recent_ones_and_strangers_stay(): void
    {
        File::ensureDirectoryExists($old = $this->dir.'/20200101-020000');
        File::ensureDirectoryExists($recent = $this->dir.'/'.now()->subDays(2)->format('Ymd-His'));
        File::ensureDirectoryExists($other = $this->dir.'/jangan-dihapus');
        file_put_contents($old.'/database.sql.gz', 'x');
        touch($old, now()->subDays(30)->getTimestamp());
        touch($other, now()->subDays(30)->getTimestamp());
        touch($recent, now()->subDays(2)->getTimestamp());

        $this->runBackup();

        $this->assertDirectoryDoesNotExist($old);
        $this->assertDirectoryExists($recent);
        $this->assertDirectoryExists($other);
    }

    public function test_the_command_reports_and_fails_on_error(): void
    {
        $this->artisan('backup:run')->assertSuccessful();
        $this->shell->exit['tar'] = 2;
        $this->artisan('backup:run')->assertFailed();
        $this->assertSame(2, BackupRun::count());
    }

    public function test_the_backup_is_scheduled_daily_at_two_in_jakarta(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'backup:run'));

        $this->assertNotNull($event);
        $this->assertSame('0 2 * * *', $event->expression);
        $this->assertSame('Asia/Jakarta', (string) $event->timezone);
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
        Livewire::test(BackupStatus::class)->assertSee('Backup terakhir')->assertSee('Berhasil dengan peringatan');
        Livewire::test(ListBackupRuns::class)->assertCanSeeTableRecords(BackupRun::all());
    }

    public function test_the_widget_turns_alarming_when_no_backup_ran_for_over_a_day(): void
    {
        $owner = User::factory()->create();
        $owner->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($owner);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(BackupStatus::class)->assertSee('Belum pernah');

        $run = $this->runBackup();
        $run->update(['started_at' => now()->subHours(40)]);
        Livewire::test(BackupStatus::class)->assertSee('lebih dari 36 jam');
    }
}
