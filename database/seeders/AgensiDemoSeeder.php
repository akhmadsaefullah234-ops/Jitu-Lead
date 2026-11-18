<?php

namespace Database\Seeders;

use App\Actions\MoveLeadToStage;
use App\Ai\Evaluation\AiHealth;
use App\Ai\Evaluation\Scenarios;
use App\Ai\Facts\Facts;
use App\Ai\Facts\PaymentDefaults;
use App\Ai\Facts\PaymentModel;
use App\Enums\AiMode;
use App\Enums\Interest;
use App\Enums\MessageStatus;
use App\Enums\Need;
use App\Enums\PaymentMethod;
use App\Enums\PropertyKind;
use App\Enums\WaChannelStatus;
use App\Enums\WaChannelType;
use App\LandingPages\Templates;
use App\LeadForms\FormTemplates;
use App\Models\AiEvaluation;
use App\Models\AiFact;
use App\Models\AiKnowledgeItem;
use App\Models\ConversionEvent;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\LeadSource;
use App\Models\LostReason;
use App\Models\Property;
use App\Models\Stage;
use App\Models\Tenant;
use App\Models\WaChannel;
use App\Models\WaConversation;
use App\Models\WaLabelEvent;
use App\Tracking\PropertyAdDefaults;
use Database\Seeders\Demo\DemoSeeder;

/**
 * "Agensi Demo" (properti, slug agensi-demo): the property agency the tutorial screenshots come from. Three
 * listings (primary and secondary), twelve leads from Lead baru to Closing and Gugur, chats, a "Minat properti" form, and a "Rumah KPR"
 * landing page with the KPR calculator and unit types. All of it is made up; see DemoSeeder.
 *
 *   php artisan db:seed --class=AgensiDemoSeeder
 */
class AgensiDemoSeeder extends DemoSeeder
{
    protected function slug(): string
    {
        return 'agensi-demo';
    }

    protected function name(): string
    {
        return 'Agensi Demo';
    }

    protected function industry(): string
    {
        return 'properti';
    }

    protected function fill(Tenant $tenant, array $team): void
    {
        $channel = WaChannel::create([
            'type' => WaChannelType::Gateway, 'name' => 'WA Agensi', 'phone' => '+628990000002', 'status' => WaChannelStatus::Connected, 'position' => 1,
            'ai_mode' => AiMode::Draft, 'credentials' => ['base_url' => 'https://gateway.contoh.test', 'api_key' => 'demo', 'signing_secret' => 'demo'],
        ]);

        $cluster = Property::create(['kind' => PropertyKind::Primary, 'name' => 'Cluster Contoh Asri', 'property_type' => 'Rumah', 'location' => 'Kota Contoh Selatan',
            'developer' => 'PT Pengembang Contoh', 'price_from' => 685_000_000, 'status' => 'available',
            'details' => ['tipe' => '36/72 dan 45/90', 'Tipe 36/72' => 'luas bangunan 36 m2, luas tanah 72 m2', 'Tipe 45/90' => 'luas bangunan 45 m2, luas tanah 90 m2',
                'sertifikat' => 'SHM', 'kpr' => 'DP mulai 5%']]);
        $tower = Property::create(['kind' => PropertyKind::Primary, 'name' => 'Apartemen Contoh Tower A', 'property_type' => 'Apartemen', 'location' => 'Kota Contoh Pusat',
            'developer' => 'PT Hunian Contoh', 'price_from' => 540_000_000, 'status' => 'available', 'details' => ['tipe' => 'Studio dan 2BR', 'lantai' => '5-20']]);
        $second = Property::create(['kind' => PropertyKind::Secondary, 'name' => 'Rumah Jl. Contoh No. 10', 'property_type' => 'Rumah', 'location' => 'Kota Contoh Timur',
            'price_from' => 1_250_000_000, 'status' => 'available', 'details' => ['luas_tanah' => 150, 'luas_bangunan' => 110, 'kamar_tidur' => 3, 'sertifikat' => 'SHM']]);

        // The "Minat properti" form for Cluster Contoh Asri, the agency's main form (used by the landing page below)
        LeadForm::create(['name' => 'Minat Cluster Contoh Asri', 'slug' => 'minat-cluster-contoh', 'status' => 'active', 'is_primary' => true,
            'property_id' => $cluster->getKey()] + FormTemplates::attributes('properti'));

        $blocks = Templates::blocks('kpr');
        $unitTypes = collect(Templates::blocks('cluster'))->firstWhere('type', 'unit_types');
        array_splice($blocks, 3, 0, $unitTypes ? [$unitTypes] : []);
        // The demo listing's own numbers in place of the template's "Rp 000 juta"
        $blocks = array_map(fn (array $b) => match ($b['type']) {
            'price' => array_replace_recursive($b, ['props' => ['price' => 'Rp 685 juta', 'installment' => 'DP mulai 5%, bisa KPR']]),
            'kpr' => array_replace_recursive($b, ['props' => ['price' => 685_000_000, 'dp_percent' => 5]]),
            default => $b,
        }, $blocks);
        LandingPage::create(['title' => 'Cluster Contoh Asri - KPR ringan', 'slug' => 'cluster-contoh-asri', 'status' => 'published', 'published_at' => now()->subDays(3),
            'template' => 'kpr', 'property_id' => $cluster->getKey(), 'color' => '#16a34a', 'font' => 'ramah', 'blocks' => $blocks]);

        AiKnowledgeItem::create(['title' => 'Harga dan tipe Cluster Contoh Asri', 'content' => 'Tipe 36/72 (luas bangunan 36 m2, luas tanah 72 m2) mulai Rp 685 juta, tipe 45/90 (luas bangunan 45 m2, luas tanah 90 m2) mulai Rp 820 juta. DP mulai 5%, bisa KPR.', 'source' => 'manual', 'active' => true]);
        AiKnowledgeItem::create(['title' => 'Jadwal survei', 'content' => 'Survei lokasi setiap hari pukul 09.00 sampai 16.00. Mohon konfirmasi H-1.', 'source' => 'manual', 'active' => true]);

        // "Isi properti": the project and its two types, a brochure (its land area that disagreed was rejected, its rent
        // estimate waits for review), and one "Uji AI saya" run.
        foreach ([
            [null, null, 'project', 'Cluster Contoh Asri'], [null, null, 'developer', 'PT Pengembang Contoh'],
            [null, null, 'location', 'Jl. Contoh Raya, Kota Contoh Selatan'], [null, null, 'legal', 'SHM'],
            [null, null, 'handover', 'Sekitar 12 bulan setelah akad'],
            // Payment model: cash, cash in instalments, or KPR, with a light down payment (profile "KPR + DP ringan").
            [null, null, 'pay_options', 'cash,installment,kpr'], [null, null, 'installment_tenor', '12'], [null, null, 'installment_interest', 'tidak'],
            [null, null, 'dp_model', 'light'], [null, null, 'dp_amount', '5%'], [null, null, 'booking_fee', '5000000'], [null, null, 'booking_fee_reduces', 'ya'],
            [null, null, 'survey_hours', 'Setiap hari 09.00 sampai 16.00'],
            [null, null, 'survey_location', 'Rumah contoh Cluster Contoh Asri'], [null, null, 'contact', 'Kantor pemasaran Cluster Contoh Asri'],
            ['36/72', $cluster->getKey(), 'price', '685000000'], ['36/72', $cluster->getKey(), 'building_area', '36'],
            ['36/72', $cluster->getKey(), 'land_area', '72'], ['36/72', $cluster->getKey(), 'availability', 'Tersedia'],
            ['45/90', null, 'price', '820000000'], ['45/90', null, 'building_area', '45'], ['45/90', null, 'land_area', '90'], ['45/90', null, 'availability', 'Inden'],
        ] as [$subject, $propertyId, $key, $value]) {
            AiFact::create(['subject' => $subject, 'property_id' => $propertyId, 'key' => $key, 'value' => $value, 'source' => AiFact::WIZARD, 'status' => AiFact::APPROVED]);
        }
        // Already decided in "Tinjau data AI" (the wizard's 90 kept), so the agency is ready for Auto as it comes.
        AiFact::create(['subject' => '45/90', 'key' => 'land_area', 'value' => '95', 'source' => AiFact::DOCUMENT, 'source_name' => 'brosur-cluster-contoh.pdf', 'status' => AiFact::REJECTED]);
        AiFact::create(['key' => 'rent_estimate', 'value' => 'Sekitar Rp3 juta per bulan', 'source' => AiFact::DOCUMENT, 'source_name' => 'brosur-cluster-contoh.pdf', 'status' => AiFact::PENDING]);
        Facts::rebuild();
        PaymentDefaults::adapt($tenant, PaymentModel::current());
        AiEvaluation::create(['trigger' => 'quick', 'passed' => 9, 'total' => 10, 'knowledge_version' => AiHealth::version(), 'results' => array_map(
            fn (array $s, int $i) => ['no' => $i + 1, 'title' => $s['title'], 'passed' => $i !== 1, 'outcome' => 'menjawab',
                'reply' => $i === 1 ? 'Luas tanah tipe 45/90 sekitar 95 m2 kak 😊' : 'Halo Kak 😊', 'problems' => $i === 1 ? ['tidak menyebut luas tanah tipe 45/90'] : []],
            $quick = Scenarios::quick($tenant), array_keys($quick))]);

        $source = fn (string $name) => LeadSource::query()->where('name', $name)->value('id');
        $stage = fn (string $name) => Stage::query()->where('name', $name)->firstOrFail();
        $first = $stage('Lead baru');

        // [name, stage, listing, interest, owner, budget (juta), details]
        $rows = [
            ['Andi Contoh', 'Lead baru', $cluster, Interest::Hot, 'agent', [650, 750], []],
            ['Bunga Contoh', 'Lead baru', $tower, Interest::Warm, 'agent2', [500, 600], []],
            ['Candra Contoh', 'Dihubungi', $cluster, Interest::Warm, 'agent', [600, 700], []],
            ['Dina Contoh', 'Dihubungi', $second, Interest::Cold, 'agent2', [1100, 1300], []],
            ['Erik Contoh', 'Terkualifikasi', $cluster, Interest::Hot, 'agent', [700, 800], []],
            ['Fitri Contoh', 'Jadwal survei', $cluster, Interest::Hot, 'agent', [650, 800], ['survey_at' => now()->addDays(2)->setTime(10, 0), 'survey_location' => 'Rumah contoh Cluster Contoh Asri']],
            ['Gani Contoh', 'Sudah survei', $second, Interest::Warm, 'agent2', [1200, 1300], []],
            ['Hesti Contoh', 'Negosiasi', $tower, Interest::Hot, 'agent2', [520, 560], []],
            ['Imam Contoh', 'Booking', $cluster, Interest::Hot, 'agent', [680, 700], ['unit' => 'Blok A-07 (contoh)', 'deal_value' => 685_000_000]],
            ['Juli Contoh', 'Closing', $second, Interest::Hot, 'agent2', [1200, 1250], ['unit' => 'Jl. Contoh No. 10', 'deal_value' => 1_225_000_000]],
            ['Kurnia Contoh', 'Gugur', $tower, Interest::Cold, 'agent', [400, 450], ['lost_reason_id' => LostReason::query()->where('label', 'KPR ditolak')->value('id')]],
            ['Lukman Contoh', 'Terkualifikasi', $tower, Interest::Warm, 'agent2', [550, 600], []],
        ];

        $leads = [];

        foreach ($rows as $i => [$name, $stageName, $listing, $interest, $owner, [$min, $max], $details]) {
            $lead = Lead::create([
                'name' => $name, 'phone' => self::phone($i + 51), 'lead_source_id' => $source($i % 2 ? 'Formulir web' : 'Iklan Meta'),
                'stage_id' => $first->getKey(), 'owner_id' => $team[$owner]->getKey(), 'interest' => $interest, 'need' => Need::Buy,
                'property_type' => $listing->property_type, 'location' => $listing->location, 'budget_min' => $min * 1_000_000, 'budget_max' => $max * 1_000_000,
                'payment_method' => $i % 3 ? PaymentMethod::Kpr : PaymentMethod::Cash, 'property_id' => $listing->getKey(),
                'next_action' => $first->default_action, 'next_action_due_at' => now()->addHours($i - 3),
            ]);

            if ($stageName !== 'Lead baru') {
                app(MoveLeadToStage::class)($lead, $stage($stageName), $team[$owner], $details);
            }

            $leads[$name] = $lead;
        }

        // Leads past "Jadwal survei" had their visit already (the dashboard counts them by date)
        foreach (['Gani Contoh' => 6, 'Hesti Contoh' => 9, 'Imam Contoh' => 12, 'Juli Contoh' => 20] as $name => $days) {
            $leads[$name]->forceFill(['survey_at' => now()->subDays($days)->setTime(10, 0), 'survey_location' => $leads[$name]->property?->name])->save();
        }

        foreach ([
            ['Andi Contoh', [[40, 'in', 'Halo, Cluster Contoh Asri masih ada tipe 36?'], [38, 'out', 'Halo Pak Andi, masih ada. Mau saya kirim brosur dan simulasi KPR-nya?']], 1],
            ['Fitri Contoh', [[600, 'in', 'Sabtu jam 10 bisa survei?'], [590, 'out', 'Bisa Bu Fitri, sudah kami jadwalkan Sabtu pukul 10.00 di rumah contoh.'], [585, 'in', 'Siap, terima kasih.']], 0],
            ['Imam Contoh', [[1500, 'in', 'Berkas KPR apa saja yang perlu disiapkan?'], [1490, 'out', 'KTP, KK, NPWP, slip gaji 3 bulan, dan rekening koran 3 bulan, Pak.'], [1480, 'in', 'Baik, saya siapkan dulu.']], 0],
        ] as [$name, $messages, $unread]) {
            $lead = $leads[$name];
            $c = WaConversation::create(['lead_id' => $lead->getKey(), 'phone' => $lead->phone, 'unread_count' => $unread]);

            foreach ($messages as [$ago, $direction, $body]) {
                $c->messages()->create(['channel_id' => $channel->getKey(), 'user_id' => $direction === 'out' ? $lead->owner_id : null, 'direction' => $direction, 'type' => 'text',
                    'body' => $body, 'status' => $direction === 'in' ? MessageStatus::Delivered : MessageStatus::Read, 'sent_at' => now()->subMinutes($ago)]);
            }

            $lastIn = min(array_column(array_filter($messages, fn ($m) => $m[1] === 'in'), 0));
            $c->forceFill(['last_inbound_at' => now()->subMinutes($lastIn), 'last_inbound_channel_id' => $channel->getKey(),
                'free_window_ends_at' => now()->subMinutes($lastIn)->addDay(), 'last_message_at' => now()->subMinutes(end($messages)[0])])->save();
        }

        // "AI dan Survei": surveys the AI booked (by itself, or an agent sending its suggestion), handovers with their
        // reason, and the Meta events and WhatsApp labels the stages sent. The number carries Jitu's label flow.
        $note = function (Lead $lead, string $type, string $body, int $hoursAgo) {
            $lead->activities()->create(['type' => $type, 'body' => $body])->forceFill(['created_at' => now()->subHours($hoursAgo)])->saveQuietly();

            // A survey the AI booked came some time after the lead did (the dashboard shows the time in between).
            if ($type === 'ai_survey' && $lead->created_at->greaterThan(now()->subHours($hoursAgo))) {
                $lead->forceFill(['created_at' => now()->subHours($hoursAgo + 30)])->saveQuietly();
            }
        };
        $note($leads['Fitri Contoh'], 'ai_survey', 'Survei dijadwalkan dari chat (AI): '.$leads['Fitri Contoh']->survey_at?->format('j M Y H:i').' di Rumah contoh Cluster Contoh Asri', 10);
        $note($leads['Gani Contoh'], 'ai_survey', 'Survei dijadwalkan dari chat ('.$team['agent2']->name.', saran AI): '.$leads['Gani Contoh']->survey_at?->format('j M Y H:i').' di Rumah Jl. Contoh No. 10', 7 * 24);
        $note($leads['Imam Contoh'], 'ai_survey', 'Survei dijadwalkan dari chat (AI): '.$leads['Imam Contoh']->survey_at?->format('j M Y H:i').' di Rumah contoh Cluster Contoh Asri', 13 * 24);
        $note($leads['Hesti Contoh'], 'ai_handoff', 'AI menyerahkan chat ke agen: Klien siap booking unit', 30);
        $note($leads['Imam Contoh'], 'ai_handoff', 'AI menyerahkan chat ke agen: Klien siap booking unit', 12 * 24);
        $note($leads['Dina Contoh'], 'ai_handoff', 'AI menyerahkan chat ke agen: Klien minta bicara dengan agen', 50);

        $channel->forceFill(['label_settings' => PropertyAdDefaults::labels($channel)])->save();

        foreach ([
            ['Erik Contoh', 'QualifiedLead', 'Prospek', 30], ['Lukman Contoh', 'QualifiedLead', 'Prospek', 26], ['Fitri Contoh', 'Schedule', 'Prospek', 10],
            ['Imam Contoh', 'InitiateCheckout', 'Order baru', 5 * 24], ['Juli Contoh', 'Purchase', 'Lunas', 3 * 24],
        ] as [$name, $event, $label, $hoursAgo]) {
            $lead = $leads[$name];
            ConversionEvent::create(['lead_id' => $lead->getKey(), 'event_name' => $event, 'event_id' => 'demo-'.$lead->getKey().'-'.$event, 'trigger' => 'stage',
                'results' => ['meta' => ['ok' => true]]])->forceFill(['created_at' => now()->subHours($hoursAgo)])->saveQuietly();
            WaLabelEvent::query()->firstOrCreate(['lead_id' => $lead->getKey(), 'label' => $label], ['wa_channel_id' => $channel->getKey(), 'status' => 'sent', 'sent_at' => now()->subHours($hoursAgo)]);
        }
    }
}
