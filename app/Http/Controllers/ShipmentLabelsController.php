<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Shipping\Shipments\LabelSheet;
use App\Support\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/** The merged label PDF of a bulk "Cetak label": a signed link (one hour), opened only by members of that agency. */
class ShipmentLabelsController extends Controller
{
    public function show(Request $request, int $tenant, string $file, CurrentTenant $current): Response
    {
        $row = Tenant::query()->find($tenant);
        $path = LabelSheet::DIR.'/'.$tenant.'/'.$file;
        $member = $row !== null && $request->user() !== null && $current->run($row, fn () => $current->roleOf($request->user()) !== null);

        // The same answer for "no such file" and "not yours".
        abort_unless($member && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="label-'.now()->format('Ymd-Hi').'.pdf"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
