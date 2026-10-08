<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\RegisterLead;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public endpoint behind the agency's website forms and landing pages. The
 * token in the address says which agency the lead belongs to.
 */
class LeadCaptureController extends Controller
{
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
        ]);

        // Bots fill the hidden field; they get the same thank-you and nothing is stored.
        if (filled($data['website'] ?? null)) {
            return $this->thanks($request);
        }

        $current->run($tenant, fn () => $register($data, ($data['source'] ?? null) ?: 'Formulir web', 'form'));

        return $this->thanks($request);
    }

    public function preflight(): Response
    {
        return response('', 204)->withHeaders($this->cors());
    }

    private function thanks(Request $request): JsonResponse|Response
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true])->withHeaders($this->cors());
        }

        return response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Terima kasih</title>'
            .'<body style="font-family:system-ui,sans-serif;text-align:center;padding:4rem 1rem"><h1>Terima kasih</h1><p>Data Anda sudah kami terima. Tim kami akan segera menghubungi Anda.</p></body>')
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
