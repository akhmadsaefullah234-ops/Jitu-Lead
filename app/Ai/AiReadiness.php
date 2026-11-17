<?php

namespace App\Ai;

use App\Ai\Facts\Facts;
use App\Ai\Facts\PaymentModel;
use App\Models\AiEvaluation;
use App\Models\AiFact;
use App\Models\Property;
use Illuminate\Support\Collection;

/**
 * "Kesiapan AI": what a property agency's AI still lacks before it may answer clients by itself, as a list and a
 * score out of 100. "Naikkan ke Auto" is pressed only once the score is at least PASS and every required item is
 * there (App\Ai\AutoGate). The facts come from the "Isi properti" wizard and approved documents (App\Ai\Facts).
 * Must run inside the tenant.
 */
final class AiReadiness
{
    public const PASS = 80;

    /** Share of the test questions ("Uji AI saya") that must pass. */
    public const TEST_PASS = 80;

    /**
     * @return array{score: int, passed: bool, items: list<array{key: string, label: string, ok: bool, weight: int, required: bool, hint: string}>}
     */
    public static function check(): array
    {
        $facts = Facts::approved();
        $project = $facts->whereNull('subject')->keyBy('key');
        $types = $facts->whereNotNull('subject')->groupBy(fn (AiFact $f) => Facts::subjectKey($f->subject));
        $every = fn (string ...$keys) => $types->isNotEmpty() && $types->every(fn (Collection $t) => collect($keys)->every(fn (string $k) => $t->contains('key', $k)));
        $available = Property::query()->where('status', '!=', 'sold')->get();
        $latest = AiEvaluation::query()->latest('id')->first();

        $items = [
            ['catalogue', 'Properti tersedia di Katalog', $available->isNotEmpty(), 15, true, 'Isi tipe unit di "Isi properti" atau tambahkan properti di Katalog.'],
            ['prices', 'Harga tiap tipe unit', $available->isNotEmpty() && $available->every(fn (Property $p) => (int) $p->price_from > 0), 15, true, 'Isi harga setiap tipe unit yang masih tersedia.'],
            ['areas', 'Luas bangunan dan tanah tiap tipe', $every('building_area', 'land_area'), 10, false, 'Isi luas bangunan dan luas tanah setiap tipe di "Isi properti".'],
            ['availability', 'Ketersediaan tiap tipe', $every('availability'), 5, false, 'Pilih ketersediaan setiap tipe (tersedia, inden, terjual).'],
            ['location', 'Lokasi proyek', isset($project['location']) || $available->contains(fn (Property $p) => filled($p->location)), 10, true, 'Isi lokasi proyek di "Isi properti".'],
            ['payment', 'Model pembayaran (cara bayar dan uang muka)', PaymentModel::from($facts->whereNull('subject'))->filled(), 10, true, 'Centang cara bayar yang tersedia dan pilih model uang muka di langkah Pembayaran "Isi properti".'],
            ['survey', 'Jam survei', isset($project['survey_hours']), 10, true, 'Isi jam survei di "Isi properti", supaya AI bisa menawarkan jadwal.'],
            ['contact', 'Kontak', isset($project['contact']), 5, false, 'Isi kontak kantor atau agen di "Isi properti".'],
            ['legal', 'Legalitas dan serah terima', isset($project['legal'], $project['handover']), 5, false, 'Isi legalitas dan perkiraan serah terima di "Isi properti".'],
            ['review', 'Tidak ada data dokumen yang menunggu tinjauan', ! AiFact::query()->where('status', AiFact::PENDING)->exists(), 5, false, 'Setujui atau tolak data dari dokumen di "Tinjau data AI".'],
            ['conflicts', 'Tidak ada data yang bertentangan', Facts::conflicts() === [], 5, true, 'Putuskan data yang bertentangan di "Tinjau data AI".'],
            ['test', 'Uji AI lulus (minimal '.self::TEST_PASS.'%)', $latest !== null && $latest->score() >= self::TEST_PASS, 5, true, 'Tekan "Uji AI saya" di Kesehatan AI dan perbaiki pengetahuan bila ada yang gagal.'],
        ];

        $items = array_map(fn (array $i) => array_combine(['key', 'label', 'ok', 'weight', 'required', 'hint'], $i), $items);
        $score = array_sum(array_map(fn (array $i) => $i['ok'] ? $i['weight'] : 0, $items));

        return [
            'score' => $score,
            'passed' => $score >= self::PASS && collect($items)->every(fn (array $i) => $i['ok'] || ! $i['required']),
            'items' => $items,
        ];
    }

    /** @return list<string> what to do before "Naikkan ke Auto"; [] once the agency is ready */
    public static function missing(): array
    {
        $check = self::check();

        if ($check['passed']) {
            return [];
        }

        return collect($check['items'])->reject(fn (array $i) => $i['ok'])->sortByDesc('required')->pluck('hint')->values()->all();
    }
}
