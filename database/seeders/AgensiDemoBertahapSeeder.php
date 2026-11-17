<?php

namespace Database\Seeders;

use Database\Seeders\Demo\PaymentProfileSeeder;

/**
 * "Agensi Demo Bertahap" (properti, slug agensi-demo-bertahap), a payment profile for ai:evaluate: Hanya cash bertahap: tenor 24 bulan tanpa bunga, DP standar 30%, tanda jadi di luar harga.
 *
 *   php artisan db:seed --class=AgensiDemoBertahapSeeder
 */
class AgensiDemoBertahapSeeder extends PaymentProfileSeeder
{
    protected function slug(): string
    {
        return 'agensi-demo-bertahap';
    }

    protected function name(): string
    {
        return 'Agensi Demo Bertahap';
    }

    protected function channelPhone(): string
    {
        return '+628990000003';
    }

    protected function payment(): array
    {
        return ['pay_options' => ['installment'], 'installment_tenor' => '24', 'installment_interest' => 'tidak', 'dp_model' => 'standard', 'dp_amount' => '30%', 'booking_fee' => '5000000', 'booking_fee_reduces' => 'tidak'];
    }
}
