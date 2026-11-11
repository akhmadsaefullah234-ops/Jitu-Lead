<?php

namespace Database\Seeders;

use App\Automation\RetailStages;
use App\Enums\AiMode;
use App\Enums\Interest;
use App\Enums\MessageStatus;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\LandingPages\Templates;
use App\LeadForms\FormTemplates;
use App\Models\AiDraft;
use App\Models\AiKnowledgeItem;
use App\Models\CarrierAccount;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\LostReason;
use App\Models\Property;
use App\Models\Shipment;
use App\Models\Tenant;
use App\Models\TrackingSetting;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Shipping\Carriers\ShipmentStatus;
use Database\Seeders\Demo\DemoSeeder;

/**
 * "Toko Demo" (retail, slug toko-demo): the shop the tutorial screenshots come from. Three bags, a short order
 * form, a landing page, twelve leads across the stages, WhatsApp chats (welcome, form message, AI reply, one
 * handoff), AI knowledge, five parcels and one member of each role. All of it is made up; see DemoSeeder.
 *
 *   php artisan db:seed --class=TokoDemoSeeder
 */
class TokoDemoSeeder extends DemoSeeder
{
    protected function slug(): string
    {
        return 'toko-demo';
    }

    protected function name(): string
    {
        return 'Toko Demo';
    }

    protected function industry(): string
    {
        return 'retail';
    }

    protected function fill(Tenant $tenant, array $team): void
    {
        $tenant->forceFill(['shipping' => [
            'enabled' => true, 'provider' => 'zone', 'origin' => 'Kota Bandung, Jawa Barat',
            'couriers' => ['JNE REG', 'SiCepat REG'], 'primary_courier' => 'JNE REG',
        ] + (array) $tenant->shipping])->save();

        // Fake keys: the demo never reaches Everpro or Meta.
        CarrierAccount::create(['provider' => 'everpro', 'sandbox' => true, 'credentials' => ['client_key' => 'demo-key', 'client_secret' => 'demo-secret'], 'settings' => []]);
        TrackingSetting::create(['meta_pixel_id' => '000000000000000', 'credentials' => ['meta_capi_token' => 'demo-token']]);

        $channel = WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'WA Toko', 'phone' => '+628990000001', 'status' => WaChannelStatus::Connected, 'position' => 1,
            'ai_mode' => AiMode::Auto, 'credentials' => ['base_url' => 'https://gateway.contoh.test', 'api_key' => 'demo', 'signing_secret' => 'demo'],
        ]);

        $bags = [
            ['Tas Raket Pro Demo', 249000, 299000, 900, 'Hitam, Merah, Biru'],
            ['Tas Ransel Sport Demo', 189000, null, 700, 'Hitam, Abu-abu'],
            ['Tas Selempang Mini Demo', 99000, 129000, 300, 'Hitam, Krem, Hijau'],
        ];

        $products = array_map(fn (array $b) => Property::create([
            'name' => $b[0], 'property_type' => 'Tas', 'price_from' => $b[1], 'compare_price' => $b[2], 'weight_grams' => $b[3],
            'length_cm' => 40, 'width_cm' => 30, 'height_cm' => 10, 'status' => 'available', 'details' => ['Warna' => $b[4]],
        ]), $bags);
        [$raket, $ransel, $mini] = $products;

        $form = FormTemplates::createOrderForm($raket->name, ['Hitam', 'Merah', 'Biru']);
        $form->forceFill(['name' => 'Form order singkat', 'slug' => 'order-tas-raket', 'is_primary' => true, 'property_id' => $raket->getKey(),
            'fields' => array_map(fn (array $f) => ($f['type'] ?? null) === 'address' ? ['address_options' => ['hide_postal', 'rt_choice']] + $f : $f, $form->fields)])->save();

        $pageA = LandingPage::create([
            'title' => 'Tas Raket Pro Demo', 'slug' => 'tas-raket-pro', 'status' => 'published', 'published_at' => now()->subDays(2),
            'template' => 'produk_varian', 'property_id' => $raket->getKey(), 'color' => '#7c3aed', 'font' => 'modern',
            'blocks' => Templates::blocks('produk_varian'), 'description' => 'Tas raket badminton muat 6 raket.',
        ]);
        // Version B for the "Uji A/B" chapter: a copy that stays a draft, served 50:50 with A at ?v=auto.
        $pageB = LandingPage::create([
            'title' => 'Tas Raket Pro Demo (versi B)', 'slug' => 'tas-raket-pro-b', 'status' => 'draft',
            'template' => 'produk_varian', 'property_id' => $raket->getKey(), 'color' => '#7c3aed', 'font' => 'modern',
            'blocks' => Templates::blocks('produk_varian'), 'description' => 'Tas raket badminton muat 6 raket.',
        ]);
        $pageA->forceFill(['ab_test' => ['enabled' => true, 'partner_id' => $pageB->getKey()], 'ab_views' => 48])->save();
        $pageB->forceFill(['ab_views' => 46])->save();

        foreach ([
            ['Harga dan varian Tas Raket Pro Demo', 'Tas Raket Pro Demo harganya Rp 249.000 (normal Rp 299.000). Warna: hitam, merah, biru. Muat 6 raket dan sepatu.'],
            ['Cara bayar', 'Bisa transfer bank atau COD (bayar di tempat) untuk seluruh Indonesia.'],
            ['Jam operasional', 'Admin membalas setiap hari pukul 08.00 sampai 21.00 WIB. Pesanan sebelum pukul 15.00 dikirim hari itu juga.'],
        ] as [$title, $content]) {
            AiKnowledgeItem::create(['title' => $title, 'content' => $content, 'source' => 'manual', 'active' => true]);
        }

        $stages = new RetailStages;
        $source = fn (string $name) => LeadSource::query()->where('name', $name)->value('id');
        $address = fn (string $street, string $kel, string $kec, string $kota, string $prov, string $pos) => [
            'shipping_address' => "{$street}, RT 001/RW 002, {$kel}, {$kec}, {$kota}, {$prov} {$pos}", 'shipping_province' => $prov,
            'shipping_address_parts' => ['street' => $street, 'rt_rw' => '001/002', 'kelurahan' => $kel, 'kecamatan' => $kec, 'kota' => $kota, 'provinsi' => $prov, 'kode_pos' => $pos],
        ];
        $order = fn (Property $p, string $variant, int $qty) => ['property_id' => $p->getKey(), 'variant' => $variant, 'quantity' => $qty,
            'order_items' => [['product_id' => $p->getKey(), 'name' => $p->name, 'variant' => $variant, 'quantity' => $qty, 'price' => (int) $p->price_from]]];

        // [name, stage, product, variant, qty, source, owner, extra]
        $rows = [
            ['Budi Contoh', RetailStages::INQUIRY, $raket, 'Hitam', 1, 'Iklan Meta', 'agent', []],
            ['Citra Contoh', RetailStages::INQUIRY, $mini, 'Krem', 1, 'Instagram', 'agent2', []],
            ['Dedi Contoh', RetailStages::ORDER, $raket, 'Merah', 1, 'Formulir web', 'agent', $address('Jl. Contoh Raya No. 1', 'Sukajadi', 'Sukajadi', 'Kota Bandung', 'Jawa Barat', '40162')],
            ['Eka Contoh', RetailStages::ORDER, $ransel, 'Abu-abu', 2, 'Iklan Meta', 'agent2', $address('Jl. Percontohan No. 7', 'Wenang Utara', 'Wenang', 'Kota Manado', 'Sulawesi Utara', '95111')],
            ['Fani Contoh', RetailStages::PAYMENT, $mini, 'Hijau', 1, 'TikTok', 'agent', $address('Jl. Demo No. 3', 'Lowokwaru', 'Lowokwaru', 'Kota Malang', 'Jawa Timur', '65141') + ['payment_type' => 'transfer', 'courier' => 'JNE REG', 'shipping_cost' => 18000]],
            ['Gilang Contoh', RetailStages::SHIPPING, $raket, 'Biru', 1, 'Formulir web', 'agent', $address('Jl. Uji Coba No. 9', 'Coblong', 'Coblong', 'Kota Bandung', 'Jawa Barat', '40132') + ['payment_type' => 'cod', 'courier' => 'JNE REG', 'shipping_cost' => 12000]],
            ['Hana Contoh', RetailStages::SHIPPING, $ransel, 'Hitam', 1, 'Iklan Meta', 'agent2', $address('Jl. Simulasi No. 4', 'Tebet Barat', 'Tebet', 'Kota Jakarta Selatan', 'DKI Jakarta', '12810') + ['payment_type' => 'cod', 'courier' => 'JNE REG', 'shipping_cost' => 15000]],
            ['Indra Contoh', RetailStages::SHIPPING, $mini, 'Hitam', 2, 'WhatsApp', 'agent', $address('Jl. Latihan No. 2', 'Gubeng', 'Gubeng', 'Kota Surabaya', 'Jawa Timur', '60281') + ['payment_type' => 'transfer', 'paid_at' => now()->subDay(), 'courier' => 'JNE REG', 'shipping_cost' => 16000]],
            ['Joko Contoh', RetailStages::SHIPPING, $raket, 'Hitam', 1, 'Iklan Meta', 'agent2', $address('Jl. Peraga No. 5', 'Kotabaru', 'Gondokusuman', 'Kota Yogyakarta', 'DI Yogyakarta', '55224') + ['payment_type' => 'cod', 'courier' => 'JNE REG', 'shipping_cost' => 14000]],
            ['Kiki Contoh', RetailStages::SHIPPING, $ransel, 'Abu-abu', 1, 'Formulir web', 'agent', $address('Jl. Teladan No. 8', 'Medan Baru', 'Medan Baru', 'Kota Medan', 'Sumatera Utara', '20153') + ['payment_type' => 'cod', 'courier' => 'JNE REG', 'shipping_cost' => 32000]],
            ['Lina Contoh', RetailStages::DONE, $mini, 'Krem', 1, 'Referral', 'agent2', $address('Jl. Panutan No. 6', 'Banjarsari', 'Banjarsari', 'Kota Surakarta', 'Jawa Tengah', '57131') + ['payment_type' => 'transfer', 'paid_at' => now()->subDays(6), 'courier' => 'JNE REG', 'shipping_cost' => 14000, 'tracking_number' => 'DEMO0000000011', 'shipped_at' => now()->subDays(5)]],
            ['Maya Contoh', RetailStages::CANCELLED, $raket, 'Merah', 1, 'Iklan Meta', 'agent', []],
        ];

        $leads = [];

        foreach ($rows as $i => [$name, $stage, $product, $variant, $qty, $from, $owner, $extra]) {
            $leads[$name] = Lead::create($extra + $order($product, $variant, $qty) + [
                'name' => $name, 'phone' => self::phone($i + 11), 'lead_source_id' => $source($from), 'stage_id' => $stages->stage($stage)->getKey(),
                'stage_entered_at' => now()->subHours(30 - 2 * $i), 'owner_id' => $team[$owner]->getKey(), 'interest' => $i % 3 === 0 ? Interest::Hot : Interest::Warm,
                'next_action' => $stages->stage($stage)->default_action, 'next_action_due_at' => now()->addHours($i - 3),
                'lead_form_id' => $from === 'Formulir web' ? $form->getKey() : null,
            ]);
        }

        $leads['Maya Contoh']->forceFill(['lost_reason_id' => LostReason::query()->where('label', 'Ongkir mahal')->value('id')])->save();

        $this->chats($channel, $team, $leads, $raket);
        $this->parcels($leads);
    }

    /** Chats: a welcome, a form message with the AI's summary, an AI answer, and one handed to an agent. */
    private function chats(WaChannel $channel, array $team, array $leads, Property $raket): void
    {
        $chat = function (Lead $lead, array $messages, int $unread = 0) use ($channel): WaConversation {
            $conversation = WaConversation::create(['lead_id' => $lead->getKey(), 'phone' => $lead->phone, 'unread_count' => $unread]);
            $last = null;

            foreach ($messages as [$minutesAgo, $direction, $body, $userId]) {
                $last = $conversation->messages()->create([
                    'channel_id' => $channel->getKey(), 'user_id' => $userId, 'direction' => $direction, 'type' => 'text', 'body' => $body,
                    'status' => $direction === 'in' ? MessageStatus::Delivered : MessageStatus::Read, 'sent_at' => now()->subMinutes($minutesAgo),
                ]);
                $last->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();

                if ($direction === 'in') {
                    $conversation->forceFill(['last_inbound_at' => now()->subMinutes($minutesAgo), 'last_inbound_channel_id' => $channel->getKey(),
                        'free_window_ends_at' => now()->subMinutes($minutesAgo)->addDay()])->save();
                }
            }

            $conversation->forceFill(['last_message_at' => $last?->sent_at])->save();

            return $conversation;
        };
        $agent = $team['agent']->getKey();

        // Form order: the buyer's form message, the AI's summary (sent automatically).
        $dedi = $leads['Dedi Contoh'];
        $c = $chat($dedi, [
            [50, 'in', "Halo admin, saya Dedi Contoh mau order {$raket->name}:\nVarian: Merah\nJumlah: 1\nPembayaran: COD (bayar di tempat)\nAlamat: {$dedi->shipping_address}\nCatatan: -\nMohon info total dan ongkirnya.", null],
            [49, 'out', "Halo kak Dedi, terima kasih 🙏 Pesanan sudah kami catat sebagai draft:\n- {$raket->name} Merah x 1\nOngkir JNE REG: Rp 12.000\nTotal: Rp 261.000\nMohon balas OK/SESUAI bila sudah benar ya kak.", $agent],
        ]);
        $this->aiDraft($c, AiDraft::SENT);

        // Asking about a product: a welcome, then the AI's answer from the knowledge.
        $c = $chat($leads['Budi Contoh'], [
            [30, 'in', 'Kak, tas raket masih ada warna hitam?', null],
            [29, 'out', 'Halo kak Budi, selamat datang di Toko Demo 👋 Tas Raket Pro Demo warna hitam ready ya kak, harganya Rp 249.000 (normal Rp 299.000). Muat 6 raket dan sepatu.', $agent],
            [12, 'in', 'Bisa kirim ke Bandung?', null],
        ], 1);
        $this->aiDraft($c, AiDraft::SENT);

        // Handed to an agent: a question the AI must not answer alone.
        $c = $chat($leads['Eka Contoh'], [
            [20, 'in', 'Kak kalau beli 10 bisa harga grosir? Untuk klub badminton kantor', null],
        ], 1);
        $this->aiDraft($c, AiDraft::HANDOFF, 'Di luar pengetahuan AI: pembeli menanyakan harga grosir');
        $leads['Eka Contoh']->activities()->create(['type' => 'ai_handoff', 'body' => 'AI menyerahkan chat ke agen: Di luar pengetahuan AI: pembeli menanyakan harga grosir']);
        $c->forceFill(['ai_paused_until' => null])->save();

        $chat($leads['Citra Contoh'], [[90, 'in', 'Selempang mini ada warna krem?', null], [88, 'out', 'Ada kak, krem ready 😊', $team['agent2']->getKey()]]);
        $chat($leads['Gilang Contoh'], [[300, 'in', 'OK sesuai, COD ya kak', null], [298, 'out', 'Siap kak, pesanan segera kami kemas 🙏', $agent]]);
    }

    private function aiDraft(WaConversation $conversation, string $status, ?string $reason = null): void
    {
        $inbound = $conversation->messages()->where('direction', 'in')->latest('id')->first();

        AiDraft::create(['conversation_id' => $conversation->getKey(), 'message_id' => $inbound->getKey(), 'status' => $status, 'reason' => $reason, 'api_call' => true,
            'body' => $status === AiDraft::SENT ? $conversation->messages()->where('direction', 'out')->latest('id')->value('body') : null]);
    }

    /** Five parcels: two not printed yet, one waiting for its resi, one on its way, one that needs action. */
    private function parcels(array $leads): void
    {
        $n = 0;
        $parcel = function (Lead $lead, array $attributes) use (&$n): Shipment {
            $n++;

            return Shipment::create($attributes + [
                'lead_id' => $lead->getKey(), 'provider' => 'everpro', 'client_order_no' => 'JL-DEMO-'.$n, 'courier' => 'JNE REG', 'rate_code' => 'REG23',
                'is_cod' => $lead->payment_type === 'cod', 'cod_amount' => $lead->payment_type === 'cod' ? 261000 : null, 'price' => (int) $lead->shipping_cost, 'charged' => (int) $lead->shipping_cost,
                'weight_grams' => 900, 'automatic' => $n === 1,
            ]);
        };

        $parcel($leads['Gilang Contoh'], ['state' => Shipment::SHIPPED, 'awb' => 'DEMO0000000001', 'status' => ShipmentStatus::AWB_CREATED]);
        $parcel($leads['Hana Contoh'], ['state' => Shipment::SHIPPED, 'awb' => 'DEMO0000000002', 'status' => ShipmentStatus::AWB_CREATED]);
        $parcel($leads['Joko Contoh'], ['state' => Shipment::ORDERED, 'order_id' => 'DEMO-ORDER-3']);
        $parcel($leads['Indra Contoh'], ['state' => Shipment::SHIPPED, 'awb' => 'DEMO0000000004', 'status' => ShipmentStatus::IN_TRANSIT, 'printed_at' => now()->subDay(), 'tracked_at' => now()->subHour()]);
        $parcel($leads['Kiki Contoh'], ['state' => Shipment::SHIPPED, 'awb' => 'DEMO0000000005', 'status' => ShipmentStatus::DELIVERY_FAILED, 'status_reason' => 'Rumah kosong (data demo)', 'printed_at' => now()->subDays(2), 'tracked_at' => now()->subHour()]);

        foreach (['Gilang Contoh', 'Hana Contoh', 'Indra Contoh', 'Kiki Contoh'] as $i => $name) {
            $leads[$name]->forceFill(['tracking_number' => 'DEMO000000000'.[1, 2, 4, 5][$i]])->save();
        }
    }
}
