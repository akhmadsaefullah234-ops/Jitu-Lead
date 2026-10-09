<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\LandingPages\PageData;
use App\Models\LandingPage;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Support\CurrentTenant;
use Illuminate\Contracts\View\View;

class LandingPageController extends Controller
{
    public function show(string $tenant, string $page, CurrentTenant $current): View
    {
        return $this->render($tenant, $page, $current, preview: false);
    }

    public function preview(string $tenant, string $page, CurrentTenant $current): View
    {
        return $this->render($tenant, $page, $current, preview: true);
    }

    /**
     * The hosted lead form: a real page for a link, and the thing an iframe on
     * the agency's own website shows.
     */
    public function form(string $token, CurrentTenant $current): View
    {
        $tenant = Tenant::query()->where('capture_token', $token)->where('status', '!=', 'suspended')->firstOrFail();

        return $current->run($tenant, fn () => view('public.form', [
            'tenant' => $tenant,
            'settings' => $tenant->formSettings(),
            'endpoint' => $tenant->captureUrl(),
            'tracking' => TrackingSetting::active(),
            'pageId' => null,
        ]));
    }

    private function render(string $tenantSlug, string $slug, CurrentTenant $current, bool $preview): View
    {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->where('status', '!=', 'suspended')->firstOrFail();

        return $current->run($tenant, function () use ($tenant, $slug, $preview) {
            $page = LandingPage::query()->where('slug', $slug)->firstOrFail();
            abort_unless($preview || $page->isPublished(), 404);

            if (! $preview) {
                $page->newQuery()->whereKey($page->getKey())->increment('views');
            }

            return view('public.landing', PageData::for($page, $tenant, $preview));
        });
    }
}
