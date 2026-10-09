<?php

namespace Tests\Feature;

use Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_front_page_shows_plans_from_config_and_links_to_app(): void
    {
        config(['jitu.registration' => 'open']);

        $html = $this->get('/')->assertOk()->getContent();

        foreach (config('plans.plans') as $plan) {
            $this->assertStringContainsString($plan['name'], $html);
        }
        $this->assertStringContainsString('79.000', $html);
        $this->assertStringContainsString(url('/app/register'), $html);
        $this->assertStringContainsString(url('/app/login'), $html);
    }

    public function test_closed_registration_sends_visitors_to_login(): void
    {
        config(['jitu.registration' => 'closed']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('/app/register', $html);
    }
}
