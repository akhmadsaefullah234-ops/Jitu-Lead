<?php

namespace App\Filament\Support;

use App\Automation\StageAutomation;
use App\Models\Shipment;
use App\Shipping\Carriers\ShipmentStatus;
use App\Support\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/** A parcel's journey for the lead page and the "Lacak paket" window: status, tracking link, then each step, newest first. */
class ShipmentTimeline
{
    public static function html(?Shipment $shipment): HtmlString
    {
        if ($shipment === null) {
            return new HtmlString('<span style="color:var(--gray-500)">Belum ada kiriman.</span>');
        }

        $tz = app(CurrentTenant::class)->get()?->timezone ?: 'Asia/Jakarta';
        $color = match ($shipment->status) {
            ShipmentStatus::DELIVERED => '#166534', ShipmentStatus::PICKUP_FAILED, ShipmentStatus::DELIVERY_FAILED, ShipmentStatus::RETURNING, ShipmentStatus::RETURNED => '#9f1239', default => 'var(--gray-700)',
        };
        $head = '<div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:center"><strong style="color:'.$color.'">'.e(ShipmentStatus::label($shipment->status)).'</strong>'
            .($shipment->waitingBalance() ? '<span style="padding:.05rem .5rem;border-radius:999px;background:#fef3c7;color:#92400e;font-size:.75rem;font-weight:600">Menunggu saldo</span>' : '')
            .($shipment->needsAction() ? '<span style="padding:.05rem .5rem;border-radius:999px;background:#ffe4e6;color:#9f1239;font-size:.75rem;font-weight:600">Perlu tindakan</span>' : '')
            .'<span style="color:var(--gray-500)">'.e(trim(($shipment->courier ?? '').' · '.($shipment->awb ?: $shipment->client_order_no), ' ·')).'</span>'
            .(filled($shipment->awb) ? '<a href="'.e(StageAutomation::trackingUrl($shipment->courier, $shipment->awb)).'" target="_blank" rel="noopener" style="text-decoration:underline">Lacak di situs kurir</a>' : '')
            .'</div>';

        if ($shipment->status_reason) {
            $head .= '<div style="color:#9f1239;font-size:.85rem">'.e($shipment->status_reason).'</div>';
        }

        if ($shipment->manual()) {
            $head .= '<div style="color:var(--gray-500);font-size:.8rem">Resi dihubungkan manual (dibuat di luar JITU LEAD).</div>';
        }

        if ($shipment->untrackable()) {
            $head .= '<div style="color:#9f1239;font-size:.85rem;font-weight:600">Tidak bisa dilacak: resi tidak dikenali penyedia setelah '.Shipment::MAX_TRACK_FAILURES.' kali dicoba. Cek status kiriman manual.</div>';
        }

        if (($shipment->state === Shipment::FAILED || $shipment->waitingBalance()) && $shipment->error) {
            $head .= '<div style="color:#9f1239;font-size:.85rem">'.e($shipment->error).'</div>';
        }

        $rows = collect((array) $shipment->events)->reverse()->map(fn (array $e) => '<li style="display:grid;grid-template-columns:7.5rem 1fr;gap:.5rem;padding:.35rem 0;border-bottom:1px solid var(--gray-100)">'
            .'<span style="color:var(--gray-500);font-size:.8rem">'.e(Carbon::parse($e['at'])->timezone($tz)->format('d M H:i')).'</span>'
            .'<span>'.e($e['description'] ?: ShipmentStatus::label($e['status'] ?? null)).'</span></li>')->implode('');

        return new HtmlString('<div style="display:grid;gap:.5rem;font-size:.875rem">'.$head.($rows !== '' ? '<ul style="list-style:none;margin:0;padding:0">'.$rows.'</ul>' : '').'</div>');
    }
}
