<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\LandingPages\AbTest;
use App\LandingPages\PageData;
use App\Models\LandingPage;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class LandingPageController extends Controller
{
    public function show(Request $request, string $tenant, string $page, CurrentTenant $current): View
    {
        return $this->render($tenant, $page, $current, preview: false, request: $request);
    }

    public function preview(string $tenant, string $page, CurrentTenant $current): View
    {
        return $this->render($tenant, $page, $current, preview: true);
    }

    private function render(string $tenantSlug, string $slug, CurrentTenant $current, bool $preview, ?Request $request = null): View
    {
        $tenant = Tenant::query()->where('slug', $tenantSlug)->where('status', '!=', 'suspended')->firstOrFail();

        return $current->run($tenant, function () use ($tenant, $slug, $preview, $request) {
            $page = LandingPage::query()->where('slug', $slug)->firstOrFail();
            abort_unless($preview || $page->isPublished(), 404);

            // The A/B test address (?v=auto): page A or page B (which may be a draft), each visit counted.
            if (! $preview && $request !== null && ($picked = AbTest::pick($request, $page)) !== null) {
                [$page, $variant] = $picked;
                $request->attributes->set('ab_variant', $variant);
                $page->newQuery()->whereKey($page->getKey())->incrementEach(['views' => 1, 'ab_views' => 1]);

                return view('public.landing', PageData::for($page, $tenant, false));
            }

            if (! $preview) {
                $page->newQuery()->whereKey($page->getKey())->increment('views');
            }

            return view('public.landing', PageData::for($page, $tenant, $preview));
        });
    }
}
