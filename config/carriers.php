<?php

use App\Shipping\Carriers\EverproCarrier;

/*
 * Automatic shipping through a courier aggregator (App\Shipping\Carriers). Each agency picks its provider and
 * fills its own credentials under Pengaturan > Pengiriman; nothing here is a secret.
 *
 * "providers" maps a provider key to the class implementing App\Shipping\Carriers\CarrierProvider. KiriminAja or
 * Biteship become another entry; the buttons, status messages, timeline and AI do not change.
 */
return [

    'providers' => [
        'everpro' => EverproCarrier::class,
    ],

    'labels' => [
        'everpro' => 'Everpro',
    ],

    // Seconds per HTTP call, and how often a queued call is tried before it is given up.
    'timeout' => 20,
    'tries' => 3,

    // Bulk "Cetak label": seconds per label PDF download, the most labels in one PDF, and how long the
    // merged PDF is kept (hours; its link works for one hour).
    'label_timeout' => 15,
    'label_max' => 100,
    'label_keep_hours' => 24,

    // Each parcel's own label PDF, kept once its tracking number comes (Pengiriman menu): days kept, and how often
    // the download is tried before the parcel shows "Label belum tersedia". Print runs are kept to download again.
    'label_store_days' => 90,
    'label_tries' => 5,
    'label_batch_keep_days' => 30,

    // Automatic resi (Pengaturan > Pengiriman > Resi otomatis): the defaults of each agency's settings.
    'auto' => [
        'delay_minutes' => 30,
        'daily_limit' => 50,
        'cod_max' => 1000000,
    ],

    // Parcels not yet delivered or returned are tracked again this often (hours) when no callback came.
    'track_every_hours' => 2,

    // The AI tracks again before answering when the stored status is older than this (hours).
    'ai_refresh_hours' => 2,

    'everpro' => [
        'sandbox_url' => 'https://client-api-sandbox.everpro.id',
        // Production base URL from the Everpro dashboard / documentation.
        'base_url' => env('EVERPRO_BASE_URL'),
        'docs_url' => 'https://developer.everpro.id/prepaid/',

        /*
         * Everpro statuses (callback tracking_code, or the order's status / status_id) to our own. Anything not
         * listed is only written to the parcel's history. Keys are compared upper case, spaces as underscores.
         */
        'status_map' => [
            // 300-301: waiting for pickup / drop off
            '300' => 'awb_created', '301' => 'awb_created', 'WAITING' => 'awb_created', 'WAITING_PICKUP' => 'awb_created', 'WAITING_FOR_PICK_UP' => 'awb_created',
            'WAITING_DROP_OFF' => 'awb_created', 'AWB_CREATED' => 'awb_created', 'CREATED' => 'awb_created', 'ALLOCATED' => 'awb_created',
            // 302: handed to the courier
            '302' => 'picked_up', 'PICKED_UP' => 'picked_up', 'PICKUP' => 'picked_up', 'TRANSFERRED_TO_3PL' => 'picked_up', 'MANIFESTED' => 'picked_up',
            // 303, 400, 403: on the way
            '303' => 'in_transit', '400' => 'in_transit', '403' => 'in_transit', 'IN_TRANSIT' => 'in_transit', 'ON_PROCESS' => 'in_transit', 'IN_PROCESS' => 'in_transit',
            'ARRIVED_AT_SORTING_HUB' => 'in_transit', 'ARRIVED_AT_DESTINATION_CITY' => 'in_transit', 'ON_TRANSIT' => 'in_transit',
            // 404: with the courier to the buyer
            '404' => 'out_for_delivery', 'ONGOING_TO_RECEIVER' => 'out_for_delivery', 'ON_DELIVERY' => 'out_for_delivery', 'OUT_FOR_DELIVERY' => 'out_for_delivery', 'DELIVERING' => 'out_for_delivery',
            // 602, 704, 706: could not deliver
            '602' => 'delivery_failed', '704' => 'delivery_failed', '706' => 'delivery_failed', 'FAILED' => 'delivery_failed', 'DELIVERY_FAILED' => 'delivery_failed',
            'FAILED_DELIVERY' => 'delivery_failed', 'IN_TROUBLE' => 'delivery_failed', 'REPEAT_DELIVERY' => 'delivery_failed', 'UNDELIVERED' => 'delivery_failed',
            // 500: received
            '500' => 'delivered', 'DELIVERED' => 'delivered', 'COMPLETED' => 'delivered', 'SUCCESS' => 'delivered',
            // 402: going back to the shop
            '402' => 'returning', 'RETURNING' => 'returning', 'IN_PROCESS_RETURN' => 'returning', 'RETURN_IN_PROCESS' => 'returning', 'ON_RETURN' => 'returning',
            // 702: back at the shop
            '702' => 'returned', 'RETURNED' => 'returned', 'RETURN' => 'returned', 'RETURN_COMPLETED' => 'returned',
            // 700-701: cancelled
            '700' => 'cancelled', '701' => 'cancelled', 'CANCELED' => 'cancelled', 'CANCELLED' => 'cancelled', 'EXPIRED' => 'cancelled',
        ],
    ],
];
