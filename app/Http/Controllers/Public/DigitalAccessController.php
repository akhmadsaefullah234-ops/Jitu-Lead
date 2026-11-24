<?php

namespace App\Http\Controllers\Public;

use App\Digital\DigitalAccesses;
use App\Models\DigitalAccess;
use App\Support\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The buyer's access link (/akses/{token}, signed and time-limited): a page with the buyer's name, a download button
 * and a "buka dengan Chrome" hint, never a redirect straight to the file. The download (/akses/{token}/unduh) counts
 * against the limit and serves the product's private file, an HTML file with the buyer's licence; a product with only
 * an outside link is sent on to it from there.
 */
class DigitalAccessController
{
    public function show(string $token, Request $request, CurrentTenant $current): Response
    {
        $access = $this->find($token);

        return $current->run($access->tenant, function () use ($access, $request) {
            if (($problem = $this->problem($access)) !== null) {
                return $this->unavailable($access, $problem);
            }

            if ($access->opened_at === null) {
                $access->lead->activities()->create(['type' => 'access_opened', 'body' => 'Pembeli membuka halaman akses produk.', 'meta' => ['digital_access_id' => $access->getKey()]]);
            }

            $access->forceFill(['opened_at' => $access->opened_at ?? now(), 'last_ip' => Str::limit((string) $request->ip(), 45, ''),
                'last_user_agent' => Str::limit((string) $request->userAgent(), 255, '')])->save();

            return response()->view('public.digital-access', [
                'access' => $access, 'lead' => $access->lead, 'product' => $access->property, 'tenant' => $access->tenant,
                'downloadUrl' => DigitalAccesses::downloadUrl($access), 'left' => max(0, $access->max_downloads - $access->downloads),
                'until' => DigitalAccesses::date($access->expires_at), 'file' => (bool) $access->property?->hasAccessFile(),
            ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
        });
    }

    public function download(string $token, Request $request, CurrentTenant $current): Response
    {
        $access = $this->find($token);

        return $current->run($access->tenant, function () use ($access, $request) {
            $product = $access->property;
            $file = $product?->hasAccessFile() && Storage::disk('digital')->exists($product->access_file_path) ? $product->access_file_path : null;
            $link = $file === null && $access->lead ? DigitalAccesses::linkOf($access->lead) : null;

            if (($problem = $this->problem($access)) !== null) {
                return $this->unavailable($access, $problem);
            }

            if ($file === null && $link === null) {
                return $this->unavailable($access, 'berkas produk belum tersedia');
            }

            if (! DigitalAccesses::countDownload($access, $request->ip(), $request->userAgent())) {
                return $this->unavailable($access, $access->problem() ?? 'batas unduhan habis');
            }

            $access->lead->activities()->create(['type' => 'access_downloaded',
                'body' => 'Pembeli mengunduh produk ('.$access->downloads.' dari '.$access->max_downloads.').', 'meta' => ['digital_access_id' => $access->getKey()]]);

            if ($link !== null) {
                return redirect()->away($link);
            }

            $name = $product->access_file_name ?: basename($file);
            $headers = ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer'];

            if (in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['html', 'htm'], true)) {
                $html = DigitalAccesses::personalise((string) Storage::disk('digital')->get($file), DigitalAccesses::licence($access));

                return response()->streamDownload(fn () => print ($html), $name, $headers + ['Content-Type' => 'text/html; charset=UTF-8']);
            }

            return Storage::disk('digital')->download($file, $name, $headers);
        });
    }

    private function problem(DigitalAccess $access): ?string
    {
        return match (true) {
            $access->lead === null || $access->lead->paid_at === null => 'pembayaran belum dikonfirmasi',
            default => $access->problem(),
        };
    }

    private function unavailable(DigitalAccess $access, string $problem): Response
    {
        return response()->view('public.digital-access-unavailable', ['tenant' => $access->tenant, 'problem' => $problem], 410)->header('Cache-Control', 'no-store');
    }

    private function find(string $token): DigitalAccess
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        return DigitalAccess::query()->withoutGlobalScopes()
            ->with(['tenant', 'lead' => fn ($q) => $q->withoutGlobalScopes(), 'property' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('token', $token)->firstOrFail();
    }
}
