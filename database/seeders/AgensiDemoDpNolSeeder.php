<?php

namespace Database\Seeders;

use Database\Seeders\Demo\PaymentProfileSeeder;

/**
 * "Agensi Demo DP Nol" (properti, slug agensi-demo-dp-nol), a payment profile for ai:evaluate: Cash keras atau KPR dengan DP gratis (promo sampai 31 Desember 2026).
 *
 *   php artisan db:seed --class=AgensiDemoDpNolSeeder
 */
class AgensiDemoDpNolSeeder extends PaymentProfileSeeder
{
    protected function slug(): string
    {
        return 'agensi-demo-dp-nol';
    }

    protected function name(): string
    {
        return 'Agensi Demo DP Nol';
    }

    protected function channelPhone(): string
    {
        return '+628990000004';
    }

    protected function payment(): array
    {
        return ['pay_options' => ['cash', 'kpr'], 'kpr_banks' => 'Bank Contoh', 'dp_model' => 'free', 'dp_promo' => 'sampai 31 Desember 2026', 'booking_fee' => '3000000', 'booking_fee_reduces' => 'ya'];
    }
}
