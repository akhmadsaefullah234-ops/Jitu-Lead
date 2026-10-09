<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\RegisterLead;
use App\Http\Controllers\Controller;
use App\Jobs\SendConversionEvents;
use App\Models\LandingPage;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Support\CurrentTenant;
use App\Tracking\ConversionPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public endpoint behind the agency's website forms and landing pages. The
 * token in the address says which agency the lead belongs to.
 */
class LeadCaptureController extends Controller
{
    private const CAMPAIGN_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];

    private const BROWSER_IDS = ['fbp', 'fbc', 'ttclid', 'ttp'];

    public function store(Request $request, string $token, CurrentTenant $current, RegisterLead $register): JsonResponse|Response
    {
        $tenant = Tenant::query()->where('capture_token', $token)->where('status', '!=', 'suspended')->first();
        abort_if($tenant === null, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30', 'required_without:email'],
            'email' => ['nullable', 'email:rfc', 'max:160', 'required_without:phone'],
            'note' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'string', 'max:60'],
            'website' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer'],
            'event_id' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'source_url' => ['nullable', 'url', 'max:500'],
            ...array_fill_keys([...self::CAMPAIGN_FIELDS, ...self::BROWSER_IDS], ['nullable', 'string', 'max:200']),
        ]);

        // Bots fill the hidden field; they get the same thank-you and nothing is stored.
        if (filled($data['website'] ?? null)) {
            return $this->thanks($request, $tenant);
        }

        $current->run($tenant, function () use ($request, $tenant, $data, $register) {
            $page = filled($data['page'] ?? null) ? LandingPage::query()->find($data['page']) : null;
            $source = $page ? 'Landing: '.$page->title : (($data['source'] ?? null) ?: 'Formulir web');
            $campaign = array_filter(array_intersect_key($data, array_flip(self::CAMPAIGN_FIELDS)));

            [, $created] = $register($data, $source, 'form', null, $campaign, $page?->property_id);

            if ($created && filled($data['event_id'] ?? null) && TrackingSetting::current()?->hasAny()) {
                SendConversionEvents::dispatch($tenant->getKey(), ConversionPayload::make(
                    $data['event_id'], $data['email'] ?? null, $data['phone'] ?? null, $data['source_url'] ?? null,
                    $request->ip(), $request->userAgent(), array_filter(array_intersect_key($data, array_flip(self::BROWSER_IDS))),
                )->toArray());
            }
        });

        return $this->thanks($request, $tenant);
    }

    public function preflight(): Response
    {
        return response('', 204)->withHeaders($this->cors());
    }

    private function thanks(Request $request, Tenant $tenant): JsonResponse|Response
    {
        $message = $tenant->formSettings()['success'];

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message])->withHeaders($this->cors());
        }

        return response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Terima kasih</title>'
            .'<body style="font-family:system-ui,sans-serif;text-align:center;padding:4rem 1rem"><h1>Terima kasih</h1><p>'.e($message).'</p></body>')
            ->withHeaders($this->cors());
    }

    /** @return array<string, string> */
    private function cors(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, Accept',
        ];
    }
}
