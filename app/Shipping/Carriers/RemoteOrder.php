<?php

namespace App\Shipping\Carriers;

use Carbon\CarbonImmutable;
use Throwable;

/** One order as the provider's own order list shows it (Everpro: GET /shipment/v1/orders). */
final class RemoteOrder
{
    public function __construct(
        public readonly string $orderNo,
        public readonly ?string $awb,
        public readonly ?string $receiverPhone,
        public readonly bool $cod,
        public readonly ?int $totalCod,
        public readonly ?CarbonImmutable $orderedAt,
        public readonly string $packageDesc = '',
        public readonly string $courier = '',
        public readonly ?int $shipmentPrice = null,
        public readonly ?int $codFee = null,
        public readonly ?int $codDisbursement = null,
        public readonly ?int $cashback = null,
        public readonly ?string $labelUrl = null,
        public readonly ?string $status = null,
    ) {}

    /** @param  array<string, mixed>  $row  one entry of "orders" */
    public static function fromEverpro(array $row): ?self
    {
        $no = trim((string) ($row['shipment_order_no'] ?? $row['client_order_no'] ?? ''));

        if ($no === '') {
            return null;
        }

        $int = fn ($v) => is_numeric($v) ? (int) round((float) $v) : null;
        $courier = $row['courier_detail'] ?? $row['courier'] ?? '';
        $courier = is_array($courier) ? implode(' ', array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : null, array_intersect_key($courier, array_flip(['logistic_name', 'courier_name', 'name', 'rate_name', 'service_name', 'service']))))) : (string) $courier;

        try {
            $date = filled($row['order_date'] ?? null) ? CarbonImmutable::parse((string) $row['order_date'], 'Asia/Jakarta') : null;
        } catch (Throwable) {
            $date = null;
        }

        $cod = $row['is_cod'] ?? null;

        return new self(
            $no,
            filled($row['awb_number'] ?? null) ? strtoupper(trim((string) $row['awb_number'])) : null,
            filled($row['receiver_phone_number'] ?? null) ? preg_replace('/\D+/', '', (string) $row['receiver_phone_number']) : null,
            $cod === true || $cod === 1 || $cod === '1' || $cod === 'true' || ($cod === null && ($int($row['total_cod'] ?? null) ?? 0) > 0),
            $int($row['total_cod'] ?? null),
            $date,
            trim((string) ($row['package_desc'] ?? '')),
            trim($courier),
            $int($row['shipment_price'] ?? null),
            $int($row['cod_fee'] ?? null),
            $int($row['total_cod_disbursement'] ?? null),
            $int($row['cashback'] ?? null),
            filled($row['label_url'] ?? null) ? (string) $row['label_url'] : null,
            filled($row['status'] ?? null) ? (string) $row['status'] : null,
        );
    }
}
