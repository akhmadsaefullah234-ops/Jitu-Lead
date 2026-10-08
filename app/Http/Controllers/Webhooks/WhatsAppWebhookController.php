<?php

namespace App\Http\Controllers\Webhooks;

use App\Actions\HandleInboundWhatsApp;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Support\CurrentTenant;
use App\WhatsApp\Providers;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppWebhookController extends Controller
{
    /**
     * Meta's one-time handshake when the webhook URL is saved in the app dashboard.
     */
    public function verify(Request $request, string $token): Response
    {
        $channel = $this->channel($token, 'official');
        $expected = (string) $channel->credential('verify_token');

        if ($request->query('hub_mode') !== 'subscribe' || $expected === '' || ! hash_equals($expected, (string) $request->query('hub_verify_token'))) {
            abort(403);
        }

        return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, string $token, Providers $providers, HandleInboundWhatsApp $inbound, CurrentTenant $current): Response
    {
        $type = $request->routeIs('webhooks.whatsapp.official') ? 'official' : 'gateway';
        $channel = $this->channel($token, $type);
        $provider = $providers->for($channel);

        if (! $provider->verifySignature($request)) {
            abort(401);
        }

        $parsed = $provider->parseWebhook($request);
        $tenant = Tenant::query()->findOrFail($channel->tenant_id);

        $current->run($tenant, function () use ($parsed, $inbound, $channel) {
            foreach ($parsed['messages'] as $message) {
                $inbound->message($channel, $message);
            }
            foreach ($parsed['statuses'] as $status) {
                $inbound->status($channel, $status);
            }
            if ($parsed['session'] !== null) {
                $inbound->session($channel, $parsed['session']);
            }
        });

        return response('ok', 200);
    }

    private function channel(string $token, string $type): WaChannel
    {
        // No tenant is known yet; the token is what identifies the channel.
        return WaChannel::query()->withoutGlobalScopes()
            ->where('webhook_token', $token)
            ->where('type', $type)
            ->firstOrFail();
    }
}
