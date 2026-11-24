<?php

namespace App\Console\Commands;

use App\Agency\AgencyProfile;
use App\LandingPages\BlockFormat;
use App\LandingPages\Navigation;
use App\LandingPages\PageLayout;
use App\LandingPages\Templates;
use App\LeadForms\FormTemplates;
use App\Models\LandingPage;
use App\Models\LeadForm;
use App\Models\Property;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Console\Command;

/**
 * One "Ludo Pasutri" product, its order form and a DRAFT landing page from the "Produk digital" template, in one
 * digital-product agency only. It can be run again: whatever already exists (by product name, form and page slug) is
 * left exactly as it is, so later edits are never overwritten. Nothing is ever published; an admin checks the page and
 * publishes it. Only --refresh changes an existing page: it puts the latest sections and the dark theme on the seeder's
 * own page while it is still a draft (pictures already added to the hero and the phone screenshots are kept), and
 * refuses, changing nothing, once the page is published. The copy is neutral on purpose (docs/digital-landing.md): no explicit words or pictures, no "APK".
 */
class SeedLudoPasutriCommand extends Command
{
    protected $signature = 'landing:ludo-pasutri {--tenant= : Slug agensi Produk digital yang diisi}
        {--refresh : Perbarui isi bagian landing DRAF Ludo Pasutri ke versi terbaru (menolak halaman yang sudah terbit)}';

    protected $description = 'Membuat produk, formulir, dan landing DRAF Ludo Pasutri di satu agensi Produk digital (aman diulang).';

    public const PRODUCT = 'Ludo Pasutri';

    public const PRICE = 99000;

    public const FORM_SLUG = 'beli-ludo-pasutri';

    public const PAGE_SLUG = 'ludo-pasutri';

    public function handle(CurrentTenant $current): int
    {
        $slug = trim((string) $this->option('tenant'));
        $tenant = $slug === '' ? null : Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            $this->error('Agensi tidak ditemukan. Pakai --tenant=<slug agensi>.');

            return self::FAILURE;
        }

        if ($tenant->industry()->key !== 'digital') {
            $this->error("Agensi {$tenant->slug} bukan jenis Produk digital; tidak ada yang diubah.");

            return self::FAILURE;
        }

        $refresh = (bool) $this->option('refresh');
        $published = $refresh ? $current->run($tenant, fn () => LandingPage::query()->where('slug', self::PAGE_SLUG)->where('status', '!=', 'draft')->exists()) : false;

        if ($published) {
            $this->error('Landing '.self::PAGE_SLUG.' sudah terbit; --refresh hanya untuk draf. Tidak ada yang diubah. Ubah halaman lewat menu Landing page.');

            return self::FAILURE;
        }

        $made = $current->run($tenant, fn () => $this->seed($tenant, $refresh));

        foreach ($made as $what => $state) {
            $this->line(match ($state) {
                true => 'dibuat  ', 'refreshed' => 'diperbarui ', default => 'sudah ada '
            }.$what);
        }

        $this->info('Landing tetap DRAF. Periksa dan terbitkan sendiri dari menu Landing page.');

        return self::SUCCESS;
    }

    /** @return array<string, bool|string> what was made (true), refreshed ('refreshed') or already there (false) */
    private function seed(Tenant $tenant, bool $refresh = false): array
    {
        $product = Property::query()->where('name', self::PRODUCT)->first();
        $made = ['produk '.self::PRODUCT => $product === null];
        $product ??= Property::create(['name' => self::PRODUCT, 'price_from' => self::PRICE, 'property_type' => 'Permainan digital', 'status' => 'available']);

        $form = LeadForm::query()->where('slug', self::FORM_SLUG)->first();
        $made['formulir '.self::FORM_SLUG] = $form === null;

        if ($form === null) {
            $form = FormTemplates::createOrderForm(self::PRODUCT, [], FormTemplates::ORDER_DIGITAL);
            $form->forceFill(['slug' => self::FORM_SLUG, 'property_id' => $product->getKey()])->save();
        }

        $page = LandingPage::query()->where('slug', self::PAGE_SLUG)->first();
        $made['landing '.self::PAGE_SLUG.' (draf)'] = $page === null;

        if ($page === null) {
            $template = Templates::get(Templates::DIGITAL);
            LandingPage::create([
                'title' => self::PRODUCT, 'slug' => self::PAGE_SLUG, 'template' => Templates::DIGITAL, 'status' => 'draft',
                'color' => AgencyProfile::for($tenant)->color() ?? $template['color'], 'font' => $template['font'],
                'description' => 'Aplikasi permainan untuk pasangan suami istri. Dibuka di HP lewat Chrome, tanpa print.',
                'blocks' => self::blocks((int) $form->getKey(), $product), 'property_id' => $product->getKey(),
                'navigation' => Navigation::of([]), 'layout' => PageLayout::clean(Templates::DIGITAL_LAYOUT),
            ]);
        } elseif ($refresh && $page->status === 'draft') {
            $page->forceFill([
                'blocks' => self::keepPictures(self::blocks((int) $form->getKey(), $product), (array) $page->blocks),
                // The dark theme; the spacing and an accent the admin chose stay.
                'layout' => PageLayout::clean(['theme' => 'gelap'] + (array) $page->layout + Templates::DIGITAL_LAYOUT),
            ])->save();
            $made['landing '.self::PAGE_SLUG.' (draf)'] = 'refreshed';
        }

        return $made;
    }

    /**
     * The new sections, with the pictures the admin already put on the page: the hero's picture (and its alt text) and
     * the phone screenshots.
     *
     * @param  list<array<string, mixed>>  $fresh
     * @param  array<int, mixed>  $old
     * @return list<array<string, mixed>>
     */
    private static function keepPictures(array $fresh, array $old): array
    {
        $old = collect(BlockFormat::normalize($old));
        $hero = (array) ($old->firstWhere('type', 'hero')['props'] ?? []);
        $shots = (array) ($old->firstWhere('type', 'phone_shots')['props']['items'] ?? []);

        foreach ($fresh as $i => $block) {
            if ($block['type'] === 'hero' && filled($hero['image'] ?? null)) {
                $fresh[$i]['props']['image'] = $hero['image'];
                $fresh[$i]['props']['image_alt'] = filled($hero['image_alt'] ?? null) ? $hero['image_alt'] : $block['props']['image_alt'];
            }

            if ($block['type'] === 'phone_shots' && $shots !== []) {
                $fresh[$i]['props']['items'] = $shots;
            }
        }

        return $fresh;
    }

    /** @return list<array<string, mixed>> */
    public static function blocks(int $formId, Property $product): array
    {
        $price = (int) ($product->price_from ?? self::PRICE);
        $strike = $product->compare_price !== null && (int) $product->compare_price > $price ? (int) $product->compare_price : null;

        return Templates::digitalBlocks([
            'hero' => ['headline' => 'Aplikasi permainan pasutri. Bukan PDF, tanpa print.',
                'subheadline' => 'Permainan papan untuk suami istri yang langsung dibuka di HP. Cukup Chrome, tanpa cetak dan tanpa pasang aplikasi.',
                'visual' => 'product', 'image_alt' => 'Tampilan permainan Ludo Pasutri di layar HP',
                'assurance' => 'Akses dikirim setelah bayar • Tanpa langganan • Bukan APK'],
            'comparison' => ['heading' => 'PDF biasa vs aplikasi', 'left_title' => 'PDF', 'left_items' => [
                ['text' => 'Harus diunduh lalu dicetak'], ['text' => 'Isinya diam, tidak bisa dimainkan langsung'], ['text' => 'Perlu kertas, gunting, dan waktu'],
            ], 'right_title' => self::PRODUCT, 'right_items' => [
                ['text' => 'Langsung dibuka di HP'], ['text' => 'Interaktif: lempar dadu dan jalan di layar'], ['text' => 'Tanpa print, siap dimainkan kapan saja'],
            ]],
            'kind_cards' => ['heading' => 'Empat jenis petak', 'intro' => 'Setiap petak membawa giliran yang berbeda, jadi permainan tetap seru dari awal sampai akhir.', 'items' => [
                ['icon_type' => 'emoji', 'emoji' => '🤲', 'title' => 'Sentuhan', 'text' => 'Giliran untuk saling memberi perhatian dengan cara yang lembut.', 'example' => 'Pijat bahu pasangan selama satu menit.', 'color' => 'merah_muda'],
                ['icon_type' => 'emoji', 'emoji' => '💬', 'title' => 'Truth', 'text' => 'Pertanyaan jujur untuk saling mengenal lebih dalam.', 'example' => 'Momen berdua apa yang paling kamu ingat?', 'color' => 'biru'],
                ['icon_type' => 'emoji', 'emoji' => '🎲', 'title' => 'Dare', 'text' => 'Tantangan kecil yang bikin tertawa bersama.', 'example' => 'Berdansa pelan diiringi satu lagu favorit.', 'color' => 'jingga'],
                ['icon_type' => 'emoji', 'emoji' => '✨', 'title' => 'Fantasi', 'text' => 'Ruang untuk berbagi keinginan dan rencana berdua.', 'example' => 'Ceritakan kencan impian yang ingin kita coba.', 'color' => 'ungu'],
            ]],
            'phone_shots' => ['heading' => 'Tampilan di HP', 'items' => []],
            'steps' => ['heading' => 'Cara pakainya', 'intro' => 'Ini bukan APK. Tidak perlu memasang apa pun dari luar Play Store.', 'items' => [
                ['title' => 'Bayar', 'text' => 'Pesan lewat formulir, lalu bayar sesuai petunjuk admin.'],
                ['title' => 'Terima tautan', 'text' => 'Tautan akses khusus Anda dikirim lewat WhatsApp.'],
                ['title' => 'Unduh', 'text' => 'Buka tautannya dan tekan tombol unduh.'],
                ['title' => 'Buka dengan Chrome dan mainkan', 'text' => 'Buka berkasnya di Chrome, lalu mulai bermain berdua.'],
            ]],
            'benefits' => ['heading' => 'Kenapa pasangan suka', 'intro' => 'Empat jenis petak dan tiga tingkat permainan.', 'items' => [
                ['title' => 'Mudah dimainkan', 'text' => 'Lempar dadu di layar, jalan ke petak, lalu ikuti gilirannya. Tanpa aturan yang rumit.'],
                ['title' => 'Tiga tingkat permainan', 'text' => 'Mulai dari yang ringan, naik pelan-pelan sesuai kenyamanan berdua.'],
                ['title' => 'Privat di HP Anda', 'text' => 'Dimainkan di perangkat sendiri. Data permainan tidak disimpan di server.'],
            ]],
            'bonus' => ['heading' => 'Bonus untuk Anda', 'badge' => 'Sudah termasuk di harga {harga}', 'show_compare' => true, 'items' => [
                ['title' => 'Bonus 1: Menu Kamasutra', 'text' => 'Daftar pilihan untuk dijelajahi berdua.'],
                ['title' => 'Bonus 2: Kartu buatan sendiri', 'text' => 'Tulis tantangan versi Anda berdua dan masukkan ke permainan.'],
            ]],
            'tiers' => ['heading' => 'Tiga tingkat permainan', 'intro' => 'Pilih tingkat yang cocok untuk malam ini.', 'items' => [
                ['name' => 'Tingkat 1', 'text' => 'Santai dan hangat, untuk mencairkan suasana.'],
                ['name' => 'Tingkat 2', 'text' => 'Lebih dekat, tantangan mulai seru.'],
                ['name' => 'Tingkat 3', 'text' => 'Paling berani, untuk yang sudah siap.'],
            ]],
            'faq' => ['heading' => 'Tanya jawab', 'items' => [
                ['q' => 'Bisa dibuka di HP apa?', 'a' => 'Android lewat Chrome. Di iPhone belum tentu berjalan baik, jadi kami tidak menjaminnya.'],
                ['q' => 'Apakah ini APK?', 'a' => 'Bukan. Tidak ada yang dipasang dari luar Play Store; berkasnya dibuka di Chrome.'],
                ['q' => 'Ada biaya langganan?', 'a' => 'Tidak. Sekali bayar, tanpa langganan.'],
                ['q' => 'Data kami disimpan di mana?', 'a' => 'Permainan berjalan di HP Anda. Data permainan tidak disimpan di server kami.'],
                ['q' => 'Bagaimana bila tidak bisa dibuka?', 'a' => 'Balas chat WhatsApp kami. Admin membantu sampai bisa dibuka, termasuk mengirim ulang tautan akses Anda.'],
            ]],
            'buy_bar' => ['name' => self::PRODUCT, 'price' => $price, 'compare_price' => $strike, 'button' => 'Beli sekarang'],
            'form' => ['form_id' => $formId, 'heading' => 'Pesan '.self::PRODUCT, 'intro' => 'Isi data singkat ini, admin kami lanjutkan lewat WhatsApp.'],
        ]);
    }
}
