<div class="text-sm" style="line-height:1.6">
    <p>Nomor resmi butuh akun Meta (Facebook) Business. Ikuti 6 langkah ini dan isi kolom di bawah satu per satu:</p>
    <ol style="list-style:decimal;padding-left:1.25rem;margin-top:.5rem">
        <li>Buka <strong>developers.facebook.com</strong> &rarr; <em>My Apps</em> &rarr; <em>Create App</em> &rarr; pilih tipe <em>Business</em>, lalu tambahkan produk <strong>WhatsApp</strong>.</li>
        <li>Di menu <em>WhatsApp &rarr; API Setup</em>, salin <strong>Phone number ID</strong> (angka panjang) ke kolom Phone number ID.</li>
        <li>Buat <strong>Access token</strong> permanen: <em>business.facebook.com &rarr; Settings &rarr; System users</em>, buat pengguna, beri akses ke app, klik <em>Generate token</em> dengan izin <code>whatsapp_business_messaging</code> dan <code>whatsapp_business_management</code>. Tempel di kolom Access token.</li>
        <li>Di <em>App settings &rarr; Basic</em>, klik <em>Show</em> pada <strong>App secret</strong>, lalu tempel di kolom App secret.</li>
        <li>Klik <strong>Simpan</strong> di halaman ini. Alamat webhook nomor ini akan tampil setelah tersimpan.</li>
        <li>Kembali ke Meta, buka <em>WhatsApp &rarr; Configuration</em>. Isi <strong>Callback URL</strong> dengan alamat webhook tadi, <strong>Verify token</strong> dengan nilai di kolom Verify token, klik <em>Verify and save</em>, lalu centang <strong>messages</strong> pada Webhook fields.</li>
    </ol>
    <p style="margin-top:.5rem">Selesai? Klik <strong>Tes koneksi</strong> di daftar nomor. Pesan ke klien di luar 24 jam wajib memakai template yang disetujui Meta.</p>
</div>
