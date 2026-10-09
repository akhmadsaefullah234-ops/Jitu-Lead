<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class UpdateSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_maintenance_page_is_friendly_and_reloads_itself(): void
    {
        Route::get('/_maintenance-probe', fn () => abort(503));

        $this->get('/_maintenance-probe')->assertStatus(503)
            ->assertSee('Sedang diperbarui')->assertSee('data Anda aman')
            ->assertSee('http-equiv="refresh"', false)->assertSee('noindex', false);
    }

    public function test_staging_shows_a_banner_and_production_does_not(): void
    {
        $tenant = $this->makeTenant();
        $admin = $this->adminOf($tenant);

        $this->actingAs($admin)->get('/app/'.$tenant->slug)->assertOk()->assertDontSee('LINGKUNGAN UJI COBA');

        $this->app['env'] = 'staging';
        $this->actingAs($admin)->get('/app/'.$tenant->slug)->assertOk()->assertSee('LINGKUNGAN UJI COBA');
    }

    public function test_the_health_check_answers_for_the_update_script(): void
    {
        $this->get('/up')->assertOk();
        $this->get('/')->assertOk();
        $this->get('/app/login')->assertOk();
    }

    public function test_deploy_scripts_are_valid_bash(): void
    {
        foreach (['update.sh', 'staging.sh', 'install.sh'] as $script) {
            exec('bash -n '.escapeshellarg(base_path("deploy/$script")).' 2>&1', $out, $code);
            $this->assertSame(0, $code, "$script: ".implode("\n", $out));
        }

        $this->assertStringContainsString('map $request_uri $jitu_xfo', file_get_contents(base_path('deploy/install.sh')));
    }
}
