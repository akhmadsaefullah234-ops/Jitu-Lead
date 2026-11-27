<?php

namespace App\Filament\Support;

use App\Filament\Pages\AiHealthPage;
use App\Filament\Pages\AiModes;
use App\Filament\Pages\CtwaReadinessPage;
use App\Filament\Pages\FactReview;
use App\Filament\Pages\PaymentGatewayPage;
use App\Filament\Pages\PaymentSettingsPage;
use App\Filament\Pages\PropertySetup;
use App\Filament\Pages\ShippingSettingsPage;
use App\Filament\Pages\StageAutomationPage;
use App\Filament\Pages\TrackingSettings;
use App\Filament\Resources\AiKnowledge\AiKnowledgeResource;
use App\Filament\Resources\AiSuggestions\AiSuggestionResource;
use App\Filament\Resources\FollowUpRules\FollowUpRuleResource;
use App\Filament\Resources\LeadSources\LeadSourceResource;
use App\Filament\Resources\LostReasons\LostReasonResource;
use App\Filament\Resources\MediaAssets\MediaAssetResource;
use App\Filament\Resources\MediaFolders\MediaFolderResource;
use App\Filament\Resources\Stages\StageResource;
use App\Filament\Resources\WaChannels\WaChannelResource;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Illuminate\Support\Str;

/**
 * What the "Pengaturan & Alat" page holds (App\Filament\Pages\SettingsAndTools): the settings pages and resources
 * that used to fill the AI Asisten, Pustaka media and Pengaturan groups of the sidebar, as cards in groups.
 *
 * Only the way they are opened changed: their URLs, routes, access rules and contents are their own. A card shows
 * only when the page's canAccess() lets the person in (for a resource: canViewAny), so the plan and industry rules
 * that used to hide a menu item hide its card. The pages themselves are no longer registered in the sidebar.
 */
final class SettingsCatalog
{
    public const GROUPS = [
        'ai' => 'AI Asisten',
        'sales' => 'Penjualan & otomatisasi',
        'channels' => 'Saluran & iklan',
        'payments' => 'Pembayaran & pengiriman',
        'media' => 'Media',
    ];

    /** Shown above the groups, in this order, for those who may open them. */
    public const QUICK = [WaChannelResource::class, AiModes::class, MediaAssetResource::class];

    /**
     * group, one-sentence description, extra words the search box also finds (the name is always searched).
     *
     * @return array<class-string, array{0: string, 1: string, 2: string}>
     */
    public static function definitions(): array
    {
        return [
            AiModes::class => ['ai', 'Pilih siapa yang membalas chat di tiap nomor: Anda sendiri, AI menyiapkan, atau AI membalas otomatis.', 'pembalas bot balas otomatis siapa yang membalas'],
            AiKnowledgeResource::class => ['ai', 'Jawaban, harga, dan arahan yang dipakai AI saat membalas chat.', 'faq arahan jam malam lampiran sambutan prompt'],
            AiSuggestionResource::class => ['ai', 'Tanya-jawab yang diusulkan dari chat, disetujui dulu sebelum dipakai AI.', 'tanya jawab faq usulan qa'],
            PropertySetup::class => ['ai', 'Data proyek dan tipe unit yang dipakai AI untuk menjawab klien.', 'proyek tipe unit dokumen brosur isi properti'],
            FactReview::class => ['ai', 'Setujui atau tolak data yang dibaca dari dokumen sebelum dipakai AI.', 'fakta bertentangan konflik dokumen'],
            AiHealthPage::class => ['ai', 'Skor kesiapan AI dan hasil uji jawaban terbaru.', 'kesiapan skor uji evaluasi auto'],

            StageResource::class => ['sales', 'Nama, urutan, dan jenis tahap di Pipeline.', 'pipeline kolom status tahap'],
            StageAutomationPage::class => ['sales', 'Lead pindah tahap sendiri saat order, pembayaran, atau resi tercatat.', 'follow up otomatis aturan tahap paket gagal antar pembayaran diterima resi'],
            FollowUpRuleResource::class => ['sales', 'Pesan susulan WhatsApp untuk lead yang belum membalas.', 'follow up susulan pengingat otomatis'],
            LeadSourceResource::class => ['sales', 'Daftar asal lead, misalnya iklan, Instagram, atau rekomendasi.', 'asal sumber kampanye channel'],
            LostReasonResource::class => ['sales', 'Pilihan alasan saat lead ditandai gugur.', 'gugur batal gagal lost'],

            WaChannelResource::class => ['channels', 'Hubungkan nomor WhatsApp lewat scan QR atau WhatsApp Business API.', 'wa whatsapp nomor qr scan gateway label business api'],
            TrackingSettings::class => ['channels', 'Pixel dan konversi Meta, TikTok, dan Google untuk iklan Anda.', 'pixel meta facebook instagram tiktok google ga4 capi konversi event iklan'],
            CtwaReadinessPage::class => ['channels', 'Periksa kesiapan iklan Klik ke WhatsApp sebelum dijalankan.', 'ctwa klik ke whatsapp meta iklan'],

            PaymentSettingsPage::class => ['payments', 'Rekening bank, kode unik, batas waktu bayar, dan instruksi transfer.', 'rekening bank transfer kode unik instruksi bayar'],
            PaymentGatewayPage::class => ['payments', 'Akun iPaymu Anda sendiri untuk pembayaran otomatis pembeli.', 'ipaymu va virtual account gateway bayar otomatis'],
            ShippingSettingsPage::class => ['payments', 'Asal kiriman, kurir, ongkir, Everpro, resi otomatis, dan pesan status kiriman.', 'ongkir ongkos kirim kurir everpro resi asal zona tarif promo status kiriman'],

            MediaAssetResource::class => ['media', 'Foto, brosur, video, dan dokumen untuk dikirim di chat.', 'foto gambar brosur video dokumen pdf price list testimoni'],
            MediaFolderResource::class => ['media', 'Kelompokkan isi Pustaka media ke dalam folder.', 'folder kelompok media'],
        ];
    }

    /** @return list<class-string> every page and resource on the page, whether or not this person may open it */
    public static function members(): array
    {
        return array_keys(self::definitions());
    }

    public static function canOpen(string $class): bool
    {
        if (Filament::getTenant() === null) {
            return false;
        }

        if (is_subclass_of($class, Resource::class) && ! $class::hasPage('index')) {
            return false;
        }

        return $class::canAccess();
    }

    /**
     * The cards this person may open, grouped; empty groups are left out.
     *
     * @return array<string, array{label: string, cards: list<array<string, mixed>>}>
     */
    public static function groups(): array
    {
        $cards = collect(self::cards())->groupBy('group');
        $groups = [];

        foreach (self::GROUPS as $key => $label) {
            if ($cards->has($key)) {
                $groups[$key] = ['label' => $label, 'cards' => $cards[$key]->all()];
            }
        }

        return $groups;
    }

    /**
     * @return list<array{class: class-string, group: string, label: string, description: string, icon: string|BackedEnum|null, url: string, badge: ?string, badgeColor: string|array|null, search: string}>
     */
    public static function cards(): array
    {
        return self::memo('cards', function () {
            $cards = [];

            foreach (self::definitions() as $class => [$group, $description, $keywords]) {
                if (! self::canOpen($class)) {
                    continue;
                }

                $badge = $class::getNavigationBadge();
                $label = $class::getNavigationLabel();

                $cards[] = [
                    'class' => $class,
                    'group' => $group,
                    'label' => $label,
                    'description' => $description,
                    'icon' => $class::getNavigationIcon(),
                    'url' => self::url($class),
                    'badge' => filled($badge) ? (string) $badge : null,
                    'badgeColor' => filled($badge) ? $class::getNavigationBadgeColor() : null,
                    'search' => self::normalize($label.' '.$description.' '.$keywords.' '.self::GROUPS[$group]),
                ];
            }

            return $cards;
        });
    }

    /** @return list<array<string, mixed>> the quick links this person may open */
    public static function quick(): array
    {
        $cards = collect(self::cards())->keyBy('class');

        return collect(self::QUICK)->filter(fn (string $class) => $cards->has($class))->map(fn (string $class) => $cards[$class])->values()->all();
    }

    public static function url(string $class): string
    {
        return is_subclass_of($class, Resource::class) ? $class::getUrl('index') : $class::getUrl();
    }

    /** The sum of the cards' number badges ("3", not "Segera"); null when there is none. */
    public static function badgeTotal(): ?int
    {
        $total = collect(self::cards())->sum(fn (array $card) => ctype_digit((string) $card['badge']) ? (int) $card['badge'] : 0);

        return $total > 0 ? $total : null;
    }

    /** @return list<string> the route names of every member, for keeping "Pengaturan & Alat" lit on their pages */
    public static function routePatterns(): array
    {
        return collect(self::members())->flatMap(fn (string $class) => (array) $class::getNavigationItemActiveRoutePattern())->values()->all();
    }

    /** The search box: every word typed must be in the card's name, description, or keywords. */
    public static function matches(array $card, string $query): bool
    {
        $words = array_filter(explode(' ', self::normalize($query)));

        foreach ($words as $word) {
            if (! str_contains(' '.$card['search'], $word)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> the labels of the cards a search finds */
    public static function search(string $query): array
    {
        return collect(self::cards())->filter(fn (array $card) => self::matches($card, $query))->pluck('label')->values()->all();
    }

    /** Lowercase, and everything that is not a letter or digit becomes a space ("Follow-up" = "follow up"). */
    public static function normalize(string $text): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', Str::lower(Str::ascii($text))));
    }

    /** Badges and access are worked out once per request: the sidebar and the page both ask. */
    private static function memo(string $key, \Closure $make): mixed
    {
        $attributes = request()->attributes;
        $key = 'settings-catalog.'.$key.'.'.(Filament::getTenant()?->getKey() ?? '-').'.'.(auth()->id() ?? '-');

        if (! $attributes->has($key)) {
            $attributes->set($key, $make());
        }

        return $attributes->get($key);
    }
}
