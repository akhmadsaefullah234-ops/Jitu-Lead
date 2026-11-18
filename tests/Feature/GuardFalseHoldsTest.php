<?php

namespace Tests\Feature;

use App\Ai\BuyerFacts;
use App\Ai\PropertyGuard;
use App\Privacy\PersonalData;
use Tests\TestCase;

/**
 * PR 11: the last false holds of the guards. A word that starts a sentence after "Kak." is not a name, and "siap huni"
 * in a refusal, a doubt or a topic is not a claim. The sentences are the shapes ai:evaluate held across repeated runs
 * (agensi-demo #23, bertahap #20, tanda-jadi #5) and their close variants. Every name and number is made up.
 */
class GuardFalseHoldsTest extends TestCase
{
    /** Correct answers that were held: a refusal, a doubt or a topic. */
    private const DENIALS = [
        'Mohon maaf Kak, saya tidak bisa menjanjikan unit 45/90 siap huni bulan depan karena masih inden.',
        'Saya tidak bisa menjanjikan unitnya sudah siap huni bulan depan ya Kak.',
        'Status siap huni belum bisa saya pastikan ya Kak.',
        'Untuk status siap huni, tipe 36/72 tersedia dan tipe 45/90 masih inden.',
        'Status siap huninya belum bisa saya pastikan Kak.',
        'Maaf Kak, untuk siap huni bulan depan belum bisa saya janjikan.',
        'Kalau soal siap huni bulan depan, saya belum bisa memastikan ya Kak.',
        'Tipe 45/90 masih inden, jadi belum siap huni Kak.',
        'Tipe 45/90 masih inden ya Kak, belum bisa dipastikan kapan siap huni.',
        'Mohon maaf, saya belum bisa menjanjikan siap huni di bulan depan, karena unit 45/90 masih dalam tahap inden.',
        'Untuk tipe 45/90 masih inden Kak, sehingga untuk ditempati bulan depan dan status siap huni-nya saya belum bisa pastikan.',
        'Wah, kalau target siap huni bulan depan, saya tidak bisa menjanjikan ya Kak.',
        'Untuk ditempati bulan depan belum bisa saya janjikan Kak, karena status siap huni tipe 45/90 belum ada informasinya di saya.',
        'Halo Kak, untuk status siap huni, unit Tipe 36/72 tersedia, sedangkan Tipe 45/90 masih inden ... untuk ditempati bulan depan saya belum bisa memastikan dari informasi yang ada.',
        'Status siap huni untuk tipe 45/90 saya belum punya info pastinya di sini Kak, saya bantu tanyakan ke tim ya.',
        'Untuk tipe 45/90 saya tidak bisa memastikan siap huni bulan depan Kak, unitnya masih inden.',
    ];

    /** Real claims: still held. */
    private const CLAIMS = [
        'Untuk unit siap huni, saat ini yang ada tipe 36/72 Kak.',
        'Unit ini sudah siap huni Kak.',
        'Tipe 36/72 siap huni dan bisa langsung ditempati Kak.',
        'Saya pastikan unitnya siap huni bulan depan Kak.',
        'Rumahnya siap huni tanpa renovasi Kak.',
        'Tidak perlu khawatir, unitnya siap huni Kak.',
    ];

    public function test_siap_huni_in_a_refusal_a_doubt_or_a_topic_is_not_a_claim(): void
    {
        foreach (self::DENIALS as $answer) {
            $this->assertSame([], PropertyGuard::problems($answer, '', ['siap huni']), $answer);
        }

        foreach (self::CLAIMS as $answer) {
            $this->assertNotSame([], PropertyGuard::problems($answer, '', ['siap huni']), $answer);
        }
    }

    public function test_a_word_that_starts_a_sentence_after_kak_is_not_a_name(): void
    {
        // agensi-demo #23: "AI hampir menyebut nama atau tempat yang bukan milik pembeli ini: Saat".
        foreach ([
            'Tipe 45/90 harganya Rp620 juta ya Kak. Saat ini unitnya masih inden.',
            'Siap Kak. Untuk tipe 45/90 harganya Rp620 juta.',
            "Halo Kak\nKalau mau survei bisa Sabtu pagi.",
            'Baik Kak. Setelah survei saya kabari lagi ya.',
            'Iya Kak. Jika berminat, Sabtu bisa survei.',
            'Oke Kak. Dengan DP 30% cicilannya ringan.',
            'Boleh Kak. Bila cocok, kita jadwalkan survei.',
            'Maaf Kak. Karena masih inden, belum bisa ditempati.',
            'Siap Kak. Sambil menunggu, saya kirim brosurnya.',
            'Baik Kak. Selama masa promo, tanda jadi Rp5 juta.',
        ] as $answer) {
            $this->assertSame([], PersonalData::names($answer), $answer);
            $this->assertSame([], (new BuyerFacts(null))->foreign($answer, 'Harga tipe 45/90?'), $answer);
        }

        // Another buyer's name and address are still held.
        $foreign = (new BuyerFacts(null))->foreign('Halo Pak Budi dari Jl. Mawar No. 5, unitnya siap dikirim.', 'Harga tipe 45/90?');
        $this->assertContains('Budi', $foreign);
        $this->assertSame(['Budi Santoso'], PersonalData::names('Halo Kak Budi Santoso, Sabtu ya'));
        $this->assertSame([], (new BuyerFacts(null))->foreign('Halo Kak Budi, Sabtu ya', 'Nama saya Budi'), 'the buyer\'s own name is fine');
    }
}
