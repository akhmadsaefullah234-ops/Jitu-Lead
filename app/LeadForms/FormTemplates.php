<?php

namespace App\LeadForms;

/**
 * Starting points for a new form: fields, look and what happens after sending. All of it is editable.
 */
class FormTemplates
{
    public const BLANK = 'kosong';

    /** @return array<string, array{name: string, description: string}> */
    public static function options(): array
    {
        return array_map(fn ($t) => ['name' => $t['name'], 'description' => $t['description']], self::all());
    }

    /** @return array<string, mixed> the form's attributes for this template */
    public static function attributes(?string $key): array
    {
        $t = self::all()[$key] ?? self::all()[self::BLANK];

        return [
            'template' => array_key_exists((string) $key, self::all()) ? $key : self::BLANK,
            'design' => $t['design'] + ['color' => '#dc2626', 'font' => 'modern', 'background' => 'soft'],
            'fields' => FormFields::normalize($t['fields']),
            'submit' => $t['submit'] + ['color' => 'brand', 'size' => 'lg', 'icon' => 'none'],
            'after_submit' => $t['after'],
            'buttons' => [],
        ];
    }

    private static function f(string $key, string $type, string $label, array $extra = []): array
    {
        return ['key' => $key, 'type' => $type, 'label' => $label] + $extra;
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $name = self::f('nama', 'text', 'Nama lengkap', ['required' => true, 'maps_to' => 'name', 'placeholder' => 'Nama Anda']);
        $phone = self::f('whatsapp', 'phone', 'Nomor WhatsApp', ['required' => true, 'maps_to' => 'phone', 'placeholder' => '0812xxxxxxxx']);
        $utm = [
            self::f('utm_source', 'hidden', 'Sumber iklan', ['source' => 'utm_source']),
            self::f('utm_campaign', 'hidden', 'Kampanye iklan', ['source' => 'utm_campaign']),
        ];
        $thanks = ['type' => 'message', 'message' => 'Terima kasih! Tim kami akan segera menghubungi Anda lewat WhatsApp.'];

        return [
            self::BLANK => [
                'name' => 'Formulir kosong', 'description' => 'Hanya nama dan nomor WhatsApp. Tambahkan kolom sendiri.',
                'design' => ['title' => 'Dapatkan informasi lengkap', 'description' => 'Isi data Anda, tim kami akan menghubungi lewat WhatsApp.'],
                'fields' => [$name, $phone, ...$utm],
                'submit' => ['label' => 'Kirim'],
                'after' => $thanks,
            ],
            'properti' => [
                'name' => 'Minat properti', 'description' => 'Tipe yang diminati, budget, dan cara bayar. Cocok untuk iklan perumahan.',
                'design' => ['title' => 'Dapatkan brosur dan daftar harga', 'description' => 'Isi data singkat ini, marketing kami kirim brosur lengkap lewat WhatsApp.'],
                'fields' => [
                    $name, $phone,
                    self::f('tipe', 'select', 'Tipe yang diminati', ['options' => ['Tipe 36', 'Tipe 45', 'Tipe 60', 'Belum tahu'], 'maps_to' => 'variant']),
                    self::f('budget', 'radio', 'Perkiraan budget', ['options' => ['Di bawah Rp 500 juta', 'Rp 500 juta - 1 miliar', 'Di atas Rp 1 miliar'], 'maps_to' => 'custom']),
                    self::f('cara_bayar', 'radio', 'Rencana cara bayar', ['options' => ['KPR', 'Cash bertahap', 'Cash keras'], 'maps_to' => 'custom']),
                    self::f('pesan', 'textarea', 'Pertanyaan (opsional)', ['maps_to' => 'note', 'placeholder' => 'Lokasi, jadwal survei, atau pertanyaan lain']),
                    ...$utm,
                ],
                'submit' => ['label' => 'Kirim brosur ke WhatsApp saya'],
                'after' => $thanks,
            ],
            'pesanan' => [
                'name' => 'Pemesanan produk', 'description' => 'Produk, warna/ukuran, jumlah, dan alamat kirim lengkap, lalu langsung lanjut chat WhatsApp admin.',
                'design' => ['title' => 'Form pemesanan', 'description' => 'Isi pesanan Anda. Setelah dikirim, Anda langsung tersambung ke WhatsApp admin untuk konfirmasi ongkir dan pembayaran.'],
                'fields' => [
                    $name, $phone,
                    self::f('produk', 'select', 'Produk', ['required' => true, 'options' => ['Produk A', 'Produk B'], 'maps_to' => 'variant']),
                    self::f('warna', 'radio', 'Warna', ['options' => ['Hitam', 'Putih'], 'maps_to' => 'variant']),
                    self::f('ukuran', 'radio', 'Ukuran', ['options' => ['S', 'M', 'L', 'XL'], 'maps_to' => 'variant']),
                    self::f('jumlah', 'number', 'Jumlah', ['required' => true, 'maps_to' => 'quantity', 'placeholder' => '1']),
                    self::f('alamat', 'address', 'Alamat pengiriman', ['required' => true, 'maps_to' => 'shipping_address']),
                    self::f('catatan', 'textarea', 'Catatan (opsional)', ['maps_to' => 'note']),
                    ...$utm,
                ],
                'submit' => ['label' => 'Pesan sekarang', 'icon' => 'whatsapp', 'color' => 'wa'],
                'after' => ['type' => 'whatsapp', 'wa_target' => 'rotate',
                    'wa_message' => "Halo admin, saya {nama} mau pesan:\n{produk} warna {warna} ukuran {ukuran}, jumlah {jumlah}.\nAlamat: {alamat}\nMohon info ongkir dan totalnya."],
            ],
            'janji' => [
                'name' => 'Janji temu / survei', 'description' => 'Pengunjung memilih tanggal dan waktu kunjungan.',
                'design' => ['title' => 'Jadwalkan kunjungan', 'description' => 'Pilih tanggal yang cocok, kami konfirmasi lewat WhatsApp.'],
                'fields' => [
                    $name, $phone,
                    self::f('tanggal', 'date', 'Tanggal kunjungan', ['required' => true, 'maps_to' => 'custom']),
                    self::f('waktu', 'radio', 'Waktu', ['options' => ['Pagi', 'Siang', 'Sore'], 'maps_to' => 'custom']),
                    self::f('catatan', 'textarea', 'Catatan (opsional)', ['maps_to' => 'note']),
                    ...$utm,
                ],
                'submit' => ['label' => 'Buat jadwal', 'icon' => 'calendar'],
                'after' => $thanks + ['message' => 'Jadwal Anda kami terima. Tim kami akan mengonfirmasi lewat WhatsApp.'],
            ],
        ];
    }
}
