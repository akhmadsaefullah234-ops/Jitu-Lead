<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The daily backup runs as www-data and must read the WhatsApp sessions that the gateway
 * (jitu-gw) writes. The shell side is covered by gateway/test; these pin the wiring in the installer.
 */
class GatewayInstallTest extends TestCase
{
    private function installer(): string
    {
        return file_get_contents(base_path('gateway/install.sh'));
    }

    public function test_installer_shares_the_session_folder_with_the_app_through_a_group(): void
    {
        $sh = $this->installer();

        $this->assertStringContainsString('usermod -aG jitu-gw www-data', $sh);
        $this->assertStringContainsString('normalize_data_perms "$APP_DIR/data" jitu-gw', $sh);
        $this->assertStringContainsString('UMask=0027', $sh);
    }

    public function test_permissions_helper_sets_group_readable_modes_and_drops_acls(): void
    {
        $helper = file_get_contents(base_path('gateway/permissions.sh'));

        $this->assertStringContainsString('chmod 2750', $helper);
        $this->assertStringContainsString('chmod 0640', $helper);
        $this->assertStringContainsString('setfacl -R -b', $helper);
    }

    public function test_gateway_writes_group_readable_files_not_0600(): void
    {
        foreach (['authState.js', 'sessions.js'] as $file) {
            $js = file_get_contents(base_path("gateway/src/$file"));

            $this->assertStringNotContainsString('0o600', $js, "$file masih menulis 0600");
            $this->assertStringContainsString('0o640', $js);
        }
    }

    public function test_backup_reads_the_gateway_folder_the_installer_prepares(): void
    {
        $this->assertSame('/opt/jitu-gateway/data', config('jitu.backup.gateway_path'));
        $this->assertStringContainsString('APP_DIR=/opt/jitu-gateway', $this->installer());
    }
}
