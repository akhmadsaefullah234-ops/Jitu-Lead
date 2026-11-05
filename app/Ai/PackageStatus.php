<?php

namespace App\Ai;

use App\Automation\StageAutomation;
use App\Models\Lead;
use App\Models\Shipment;
use App\Shipping\Carriers\CarrierException;
use App\Shipping\Carriers\Carriers;
use App\Shipping\Carriers\ShipmentStatus;
use App\Shipping\Shipments\Shipments;
use App\Support\CurrentTenant;
use Illuminate\Support\Carbon;

/**
 * "Paketnya sampai mana?": the AI answers from the parcel status we hold (tracked again first when it is older
 * than config carriers.ai_refresh_hours), never with an estimate of its own. With no parcel data the chat goes
 * to an agent. Must run inside the tenant.
 */
class PackageStatus
{
    private const QUESTION = '/\b(?:sampai\s+mana|sampe\s+mana|udah\s+sampai|sudah\s+sampai|kapan\s+(?:sampai|sampe|tiba|datang|nyampe)|posisi\s+paket|paket(?:nya|\s+saya|\s+aku)?\s+(?:di\s*mana|dimana|sudah|udah|belum|kok)'
        .'|(?:no(?:mor)?\.?\s*)?resi(?:nya)?|lacak|tracking|sudah\s+dikirim|udah\s+dikirim|kapan\s+dikirim)\b/iu';

    private const ESTIMATE = '/\b(?:\d+\s*(?:-\s*\d+\s*)?hari|besok|lusa|minggu\s+depan|estimasi|perkiraan|diperkirakan|paling\s+lambat|biasanya)\b/iu';

    public static function asks(string $question): bool
    {
        return (bool) preg_match(self::QUESTION, $question);
    }

    /** The parcel facts for the prompt, or null when there is nothing to answer from. */
    public static function context(Lead $lead): ?string
    {
        $shipment = Shipment::latestFor($lead);
        $tenant = app(CurrentTenant::class)->get();

        if ($shipment !== null && filled($shipment->awb) && ! $shipment->isFinal() && Carriers::configured($tenant)
            && ($shipment->tracked_at === null || $shipment->tracked_at->lt(now()->subHours((int) config('carriers.ai_refresh_hours', 2))))) {
            try {
                $shipment = app(Shipments::class)->refresh($shipment);
            } catch (CarrierException $e) {
                report($e);
            }
        }

        $awb = $shipment?->awb ?: $lead->tracking_number;

        if (blank($awb)) {
            return null;
        }

        $courier = $shipment?->courier ?: $lead->courier;
        $tz = $tenant?->timezone ?: 'Asia/Jakarta';
        $last = collect((array) $shipment?->events)->last();

        return implode("\n", array_filter([
            'STATUS PAKET (satu-satunya sumber untuk pertanyaan posisi paket):',
            'Kurir: '.($courier ?: '-').' · Nomor resi: '.$awb,
            'Tautan lacak: '.StageAutomation::trackingUrl($courier, $awb),
            $shipment?->status ? 'Status terakhir: '.ShipmentStatus::label($shipment->status).($shipment->status_reason ? ' ('.$shipment->status_reason.')' : '') : 'Status terakhir: belum ada kabar dari kurir.',
            $last ? 'Kabar terakhir kurir ('.Carbon::parse($last['at'])->timezone($tz)->format('d M H:i').'): '.$last['description'] : null,
            'Bila pembeli menanyakan posisi paket, kapan sampai, atau resinya: jawab hanya dari data di atas (status, nomor resi, tautan lacak). Jangan menyebut perkiraan hari tiba atau estimasi apa pun. Bila pertanyaannya tidak terjawab dari data ini, balas hanya dengan kata HANDOFF.',
        ]));
    }

    /** True when the answer gives an arrival estimate the parcel data does not hold. */
    public static function invents(string $answer, string $context): bool
    {
        preg_match_all(self::ESTIMATE, $answer, $m);

        return collect($m[0])->contains(fn ($phrase) => mb_stripos($context, $phrase) === false);
    }
}
