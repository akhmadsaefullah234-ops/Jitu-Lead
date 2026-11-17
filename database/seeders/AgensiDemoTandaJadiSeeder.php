<?php

namespace Database\Seeders;

use Database\Seeders\Demo\PaymentProfileSeeder;

/**
 * "Agensi Demo Tanda Jadi" (properti, slug agensi-demo-tanda-jadi), a payment profile for ai:evaluate: Tanpa DP: tanda jadi Rp2 juta yang mengurangi harga, lalu cash keras atau cicilan developer sampai 60 bulan.
 *
 *   php artisan db:seed --class=AgensiDemoTandaJadiSeeder
 */
class AgensiDemoTandaJadiSeeder extends PaymentProfileSeeder
{
    protected function slug(): string
    {
        return 'agensi-demo-tanda-jadi';
    }

    protected function name(): string
    {
        return 'Agensi Demo Tanda Jadi';
    }

    protected function channelPhone(): string
    {
        return '+628990000005';
    }

    protected function payment(): array
    {
        return ['pay_options' => ['cash', 'developer'], 'developer_tenor' => '60', 'dp_model' => 'none', 'booking_fee' => '2000000', 'booking_fee_reduces' => 'ya'];
    }
}
