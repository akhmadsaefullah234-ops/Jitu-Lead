<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** A photo a visitor uploaded in a form, shown only to people who may open that lead. */
class LeadPhotoController extends Controller
{
    public function show(Request $request, int $lead, int $index, CurrentTenant $current): Response
    {
        abort_if($request->user() === null, 404);

        $row = Lead::query()->withoutGlobalScopes()->find($lead);
        $tenant = $row === null ? null : Tenant::query()->find($row->tenant_id);
        $path = $row?->custom_fields['foto_formulir'][$index]['path'] ?? null;

        abort_if($tenant === null || ! is_string($path) || ! str_starts_with($path, "lead-uploads/{$tenant->getKey()}/{$row->getKey()}/"), 404);

        $allowed = $current->run($tenant, fn () => $current->roleOf($request->user()) !== null && Gate::forUser($request->user())->allows('view', $row));

        // The same answer for "no such photo" and "not yours", so ids cannot be probed.
        abort_unless($allowed && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => Storage::disk('local')->mimeType($path) ?: 'image/jpeg',
            'Content-Disposition' => 'inline',
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
