<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\WaMessage;
use App\Support\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** Shows a picture from a WhatsApp chat, only to people who may open that lead. */
class WhatsAppMediaController extends Controller
{
    public function show(Request $request, int $message, CurrentTenant $current): Response
    {
        abort_if($request->user() === null, 404);

        $row = WaMessage::query()->withoutGlobalScopes()->find($message);
        $tenant = $row === null ? null : Tenant::query()->find($row->tenant_id);
        $path = $row?->media['path'] ?? null;
        $mime = $row?->media['mime_type'] ?? null;

        abort_if($tenant === null || ! is_string($path) || ! is_string($mime) || ! str_starts_with($path, "wa-media/{$tenant->getKey()}/"), 404);

        $allowed = $current->run($tenant, function () use ($request, $current, $row) {
            $lead = $row->conversation()->first()?->lead;

            return $lead !== null && $current->roleOf($request->user()) !== null
                && Gate::forUser($request->user())->allows('view', $lead);
        });

        // The same answer for "no such picture" and "not yours", so ids cannot be probed.
        abort_unless($allowed && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
