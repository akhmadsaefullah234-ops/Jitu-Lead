<?php

/*
 * Text of /kebijakan-privasi and /syarat-layanan. This is the default; the
 * platform owner can replace either text in /admin (Halaman hukum). Placeholders:
 * {perusahaan} {kontak} {alamat} {aplikasi} {uji_coba_hari} {backup_hari} {backup_luar_hari} {tanggal}.
 *
 * IMPORTANT: this is a draft written from how the app actually works. It is NOT
 * legal advice and must be reviewed by a lawyer before release.
 */
return [

    'company' => env('LEGAL_COMPANY', 'JITU LEAD'),
    'contact' => env('LEGAL_CONTACT_EMAIL', env('SUPPORT_EMAIL')),
    'address' => env('LEGAL_ADDRESS'),
    // Date of the last change to the default text.
    'updated' => '2026-10-16',

    'documents' => [

        'kebijakan-privasi' => [
            'title' => 'Kebijakan Privasi',
            'intro' => 'Kebijakan ini menjelaskan data pribadi apa yang disimpan {aplikasi}, untuk apa, berapa lama, dan hak Anda atas data itu, sesuai Undang-Undang No. 27 Tahun 2022 tentang Pelindungan Data Pribadi (UU PDP).',
            'sections' => [
                ['Siapa kami dan apa peran kami', [
                    ['p', '{aplikasi} adalah aplikasi CRM untuk agen dan agensi properti, dikelola oleh {perusahaan}. Pengelola dapat dihubungi di {kontak}{alamat}.'],
                    ['p', 'Untuk data akun pengguna (nama, email, kata sandi), kami adalah Pengendali Data Pribadi. Untuk data calon pembeli (lead) dan percakapan WhatsApp yang dimasukkan atau diterima oleh sebuah agensi, agensi tersebut adalah Pengendali Data Pribadi dan kami bertindak sebagai Prosesor Data Pribadi yang menyimpan dan memprosesnya sesuai instruksi agensi.'],
                ]],
                ['Data yang kami simpan', [
                    ['ul', [
                        'Akun pengguna: nama, alamat email, nomor WhatsApp, kata sandi (disimpan dalam bentuk hash, bukan teks asli), peran dalam agensi, dan waktu pendaftaran.',
                        'Persetujuan dihubungi: apakah Anda bersedia dihubungi lewat WhatsApp atau email tentang akun dan penawaran {aplikasi}, beserta waktu persetujuan itu diberikan, dan waktu Anda berhenti menerima email masa percobaan.',
                        'Data agensi: nama agensi, pengaturan, paket langganan, dan riwayat permintaan paket.',
                        'Kontak lead: nama, nomor telepon, email, kebutuhan properti, anggaran, lokasi yang diminati, jadwal survei, catatan agen, tahap penjualan, dan riwayat aktivitasnya.',
                        'Percakapan WhatsApp: isi pesan, lampiran, waktu, dan status pengiriman antara agensi dan lead.',
                        'Data pelacakan iklan yang dikirim lewat formulir: parameter kampanye (UTM), identitas peramban untuk pelacakan iklan (misalnya fbp, fbc, ttclid), alamat IP, dan jenis peramban.',
                        'Pesan bantuan yang Anda kirim lewat chat support di dalam aplikasi.',
                        'Data teknis: log akses dan kesalahan untuk keamanan dan perbaikan, tanpa isi pesan.',
                    ]],
                ]],
                ['Untuk apa data dipakai', [
                    ['ul', [
                        'Menjalankan layanan: masuk ke akun, mengelola lead, mengirim dan menerima pesan WhatsApp, mengatur tindak lanjut otomatis.',
                        'Membantu menyusun draf balasan dengan AI bila fitur itu diaktifkan oleh agensi.',
                        'Mengirim email layanan: verifikasi akun, atur ulang kata sandi, pengingat masa percobaan dan langganan.',
                        'Menghubungi admin agensi tentang akunnya dan penawaran {aplikasi}, hanya bila admin tersebut menyetujuinya, sebagaimana dijelaskan di bagian berikut.',
                        'Menagih langganan dan menanggapi permintaan bantuan.',
                        'Menjaga keamanan, mencegah penyalahgunaan, dan membuat cadangan data.',
                    ]],
                    ['p', 'Kami tidak menjual data pribadi dan tidak memakai data lead sebuah agensi untuk kepentingan agensi lain.'],
                ]],
                ['Nomor WhatsApp, email masa percobaan, dan cara berhenti', [
                    ['p', 'Saat mendaftar kami meminta nomor WhatsApp Anda. Nomor itu dipakai untuk informasi penting tentang akun (misalnya masa percobaan atau pembayaran) dan untuk membantu Anda memakai {aplikasi}.'],
                    ['p', 'Bila Anda mencentang persetujuan "Saya bersedia dihubungi via WhatsApp/email tentang akun dan penawaran {aplikasi}", nama, email, nomor WhatsApp, nama agensi, paket, dan tanggal berakhirnya masa percobaan Anda dicatat sebagai calon pelanggan di CRM tim {aplikasi}. Tim kami dapat mengirim pesan WhatsApp tentang akun dan penawaran paket; pesan itu berhenti sendiri setelah agensi Anda berlangganan.'],
                    ['p', 'Selama dan sesudah masa percobaan, admin agensi yang emailnya terverifikasi menerima beberapa email berseri: tips memulai, ringkasan pemakaian, pengingat sebelum masa percobaan berakhir, dan penawaran setelahnya. Email ini berhenti sendiri setelah agensi membayar.'],
                    ['p', 'Bila Anda menyetujui dihubungi, sesekali kami juga mengirim email penawaran {aplikasi}. Email itu hanya dikirim ke yang menyetujui dan emailnya terverifikasi.'],
                    ['ul', [
                        'Berhenti menerima email masa percobaan dan email penawaran: klik tautan "Berhenti menerima email ini" di bagian bawah email mana pun. Email penting tentang akun, seperti verifikasi dan atur ulang kata sandi, tetap dikirim.',
                        'Berhenti menerima pesan WhatsApp: balas pesan kami dan minta berhenti, atau hubungi {kontak}. Kami akan menghentikan pesan penawaran dan mencatat penarikan persetujuan Anda.',
                        'Menarik persetujuan tidak memengaruhi akun atau layanan Anda.',
                    ]],
                ]],
                ['Dengan siapa data dibagikan', [
                    ['p', 'Data hanya diberikan kepada pihak yang diperlukan untuk menjalankan layanan:'],
                    ['ul', [
                        'Penyedia server dan penyimpanan tempat aplikasi dijalankan, termasuk penyimpanan cadangan di luar server.',
                        'Penyedia AI (Anthropic): bila fitur AI Asisten aktif, isi percakapan dan informasi yang diperlukan untuk membuat draf balasan dikirim ke layanan tersebut, yang dapat berada di luar Indonesia. Pengiriman ke luar negeri dilakukan dengan tetap menjaga kerahasiaan dan keamanan data sebagaimana disyaratkan UU PDP.',
                        'WhatsApp (Meta), sebagai saluran pesan yang Anda sambungkan sendiri.',
                        'Platform iklan (Meta, TikTok, Google) hanya bila agensi mengaktifkan pelacakan: data kontak yang dikirim dalam bentuk ter-hash serta identitas peramban untuk mengukur konversi iklan.',
                        'Penyedia email untuk mengirim email layanan.',
                        'Pihak berwenang bila diwajibkan oleh hukum.',
                    ]],
                ]],
                ['Berapa lama data disimpan', [
                    ['ul', [
                        'Data akun dan data lead disimpan selama agensi memakai layanan. Agensi dapat menghapus lead, dan dapat menghapus seluruh agensinya beserta isinya kapan saja dari menu Pengaturan; penghapusan itu berlaku seketika pada data aktif dan tidak perlu persetujuan kami.',
                        'Cadangan harian disimpan {backup_hari} hari di server dan {backup_luar_hari} hari di penyimpanan luar server, lalu dihapus otomatis. Data yang sudah dihapus dari aplikasi karena itu baru hilang seluruhnya dari cadangan setelah masa tersebut berlalu.',
                        'Pesan chat support disimpan selama diperlukan untuk menangani bantuan dan riwayatnya.',
                        'Bila agensi berhenti berlangganan, akunnya menjadi hanya-baca dan datanya tetap tersimpan sampai agensi memilih paket kembali atau menghapusnya.',
                    ]],
                ]],
                ['Hak Anda atas data pribadi', [
                    ['p', 'Sesuai UU PDP, Anda berhak: mendapatkan informasi tentang pemrosesan data Anda; mengakses dan memperoleh salinan data Anda; memperbaiki data yang keliru; meminta data Anda dihapus atau dimusnahkan; menarik persetujuan; mengajukan keberatan atas pemrosesan tertentu; dan meminta data Anda dipindahkan dalam format yang dapat dibaca.'],
                    ['ul', [
                        'Pengguna aplikasi: admin agensi dapat mengekspor seluruh data agensinya (lead, aktivitas, chat, properti) sebagai berkas ZIP berisi CSV, dan dapat menghapus agensinya, dari menu Pengaturan. Untuk hak lain, hubungi {kontak}.',
                        'Calon pembeli yang datanya ada di sebuah agensi: hubungi agensi tersebut terlebih dulu karena agensi adalah pengendali datanya. Bila Anda tidak dapat menghubunginya, kirim permintaan ke {kontak} dan kami akan meneruskannya serta membantu agensi memenuhinya.',
                    ]],
                    ['p', 'Kami menanggapi permintaan dalam waktu yang wajar dan sesuai batas waktu dalam UU PDP. Kami dapat meminta bukti identitas untuk memastikan permintaan datang dari orang yang berhak.'],
                ]],
                ['Keamanan', [
                    ['p', 'Kami menjaga data dengan koneksi terenkripsi (HTTPS), kata sandi ter-hash, pemisahan data antaragensi, pembatasan akses menurut peran, penyimpanan kredensial integrasi dalam bentuk terenkripsi, dan cadangan berkala. Tidak ada sistem yang sepenuhnya bebas risiko. Bila terjadi kegagalan pelindungan data yang berdampak pada Anda, kami akan memberi tahu pihak terdampak dan pihak berwenang sesuai ketentuan UU PDP.'],
                ]],
                ['Anak-anak', [
                    ['p', 'Layanan ditujukan bagi pelaku usaha dan tidak ditujukan bagi anak. Kami tidak dengan sengaja mengumpulkan data anak.'],
                ]],
                ['Perubahan kebijakan', [
                    ['p', 'Kebijakan ini dapat diubah. Perubahan penting akan diumumkan di aplikasi atau lewat email. Tanggal pembaruan terakhir: {tanggal}.'],
                ]],
                ['Kontak', [
                    ['p', 'Pertanyaan atau permintaan terkait data pribadi: {kontak}.'],
                ]],
            ],
        ],

        'syarat-layanan' => [
            'title' => 'Syarat dan Ketentuan Layanan',
            'intro' => 'Dengan membuat akun atau memakai {aplikasi}, Anda dan agensi Anda menyetujui syarat berikut.',
            'sections' => [
                ['Layanan', [
                    ['p', '{aplikasi} adalah CRM berbasis web untuk mengelola lead properti, percakapan WhatsApp, tindak lanjut otomatis, landing page, dan fitur pendukung lain. Fitur dan batas pemakaian tiap paket tertera di halaman harga dan menu Langganan.'],
                ]],
                ['Akun dan keamanan', [
                    ['ul', [
                        'Anda wajib memberi data yang benar dan menjaga kerahasiaan kata sandi. Aktivitas di akun Anda menjadi tanggung jawab Anda.',
                        'Admin agensi bertanggung jawab atas pengguna yang ia tambahkan dan atas data yang dimasukkan agensinya.',
                    ]],
                ]],
                ['Uji coba dan langganan', [
                    ['ul', [
                        'Agensi baru mendapat masa percobaan gratis selama 14 hari. Setelah itu, akun menjadi hanya-baca (data tetap aman dan bisa dilihat) sampai admin agensi memilih paket.',
                        'Langganan dibayar di muka lewat transfer bank sesuai paket dan siklus (bulanan atau tahunan) yang dipilih. Paket aktif setelah pembayaran kami konfirmasi.',
                        'Bila masa langganan berakhir, akun menjadi hanya-baca setelah masa tenggang yang tertera di aplikasi. AI dan tindak lanjut otomatis berhenti selama hanya-baca.',
                        'Lead dari formulir dan WhatsApp tetap diterima walau batas paket tercapai.',
                        'Ketentuan pengembalian dana ada di {tautan:kebijakan-pengembalian-dana}.',
                    ]],
                ]],
                ['Penggunaan yang diperbolehkan', [
                    ['ul', [
                        'Anda hanya boleh menyimpan dan menghubungi data pribadi yang Anda peroleh secara sah, dengan dasar yang sesuai UU PDP (misalnya persetujuan calon pembeli).',
                        'Dilarang mengirim spam, pesan menyesatkan, penipuan, atau konten yang melanggar hukum; dilarang menjanjikan keuntungan investasi yang tidak dapat dipertanggungjawabkan lewat landing page atau pesan.',
                        'Dilarang mengganggu keamanan layanan, mencoba mengakses data agensi lain, atau menyalahgunakan sistem.',
                    ]],
                ]],
                ['WhatsApp', [
                    ['p', 'Penyambungan nomor lewat scan QR memakai jalur tidak resmi, dan WhatsApp dapat membatasi atau memblokir nomor yang dianggap melanggar ketentuannya (misalnya mengirim pesan massal tanpa izin). Penggunaan sepenuhnya menjadi risiko dan tanggung jawab agensi; kami tidak menjamin nomor tidak diblokir. Gunakan nomor dan kebiasaan kirim yang wajar.'],
                ]],
                ['Fitur AI', [
                    ['p', 'AI membantu membuat draf balasan dan bisa keliru. Agensi bertanggung jawab atas isi pesan yang dikirim, termasuk yang dikirim otomatis, dan wajib memeriksa informasi seperti harga, ketersediaan unit, dan janji yang diberikan.'],
                ]],
                ['Data milik agensi', [
                    ['p', 'Data yang Anda masukkan tetap milik agensi Anda. Kami hanya memprosesnya untuk menjalankan layanan sebagaimana dijelaskan dalam Kebijakan Privasi. Admin agensi dapat mengekspor seluruh datanya dan menghapus agensinya kapan saja; penghapusan tidak dapat dibatalkan.'],
                ]],
                ['Ketersediaan dan cadangan', [
                    ['p', 'Kami berusaha menjaga layanan tetap tersedia dan membuat cadangan harian, tetapi tidak menjamin layanan bebas gangguan. Pemeliharaan dapat dilakukan sewaktu-waktu dan kami berusaha melakukannya dengan gangguan sekecil mungkin.'],
                ]],
                ['Penangguhan dan penghentian', [
                    ['p', 'Kami dapat menangguhkan akun yang melanggar syarat ini, menyalahgunakan layanan, atau tidak membayar. Anda dapat berhenti kapan saja dengan tidak memperpanjang atau menghapus agensi.'],
                ]],
                ['Batas tanggung jawab', [
                    ['p', 'Sejauh diizinkan hukum, kami tidak bertanggung jawab atas kerugian tidak langsung, hilangnya peluang penjualan, atau kerugian akibat tindakan agensi, pihak ketiga (termasuk WhatsApp dan platform iklan), atau keadaan di luar kendali kami. Tanggung jawab kami terbatas pada biaya langganan yang Anda bayar untuk periode berjalan.'],
                ]],
                ['Perubahan dan hukum yang berlaku', [
                    ['p', 'Syarat ini dapat diubah; perubahan penting diberitahukan lewat aplikasi atau email. Memakai layanan setelah perubahan berarti menyetujuinya. Syarat ini tunduk pada hukum Republik Indonesia. Pembaruan terakhir: {tanggal}.'],
                ]],
                ['Kontak', [
                    ['p', 'Pertanyaan tentang syarat ini: {kontak}.'],
                ]],
            ],
        ],
        'kebijakan-pengembalian-dana' => [
            'title' => 'Kebijakan Pengembalian Dana',
            'intro' => 'Kebijakan ini menjelaskan kapan pembayaran langganan {aplikasi} dapat dikembalikan dan cara mengajukannya.',
            'sections' => [
                ['Produk', [
                    ['p', '{aplikasi} dijual sebagai langganan layanan perangkat lunak (SaaS) bulanan atau tahunan, dikelola oleh {perusahaan}. Pembayaran dilakukan di muka untuk periode yang dipilih.'],
                ]],
                ['Uji coba gratis', [
                    ['p', 'Setiap agensi baru mendapat masa percobaan gratis selama {uji_coba_hari} hari untuk mencoba semua fitur paket percobaan sebelum membayar. Karena itu, pembayaran langganan pada umumnya tidak dapat dikembalikan setelah dilakukan.'],
                ]],
                ['Pengecualian', [
                    ['p', 'Pengembalian dana dapat diberikan dalam keadaan berikut:'],
                    ['ul', [
                        'Tagihan ganda: Anda membayar lebih dari satu kali untuk periode yang sama.',
                        'Salah nominal: jumlah yang Anda bayarkan lebih besar dari tagihan; selisihnya dikembalikan.',
                        'Layanan tidak dapat dipakai karena kesalahan sistem kami dan tidak dapat kami pulihkan dalam waktu yang wajar.',
                    ]],
                ]],
                ['Cara mengajukan', [
                    ['p', 'Kirim email ke {kontak} paling lambat 7 hari setelah tanggal pembayaran. Pengajuan setelah itu tidak dapat kami proses. Cantumkan:'],
                    ['ul', [
                        'nama agensi dan email akun Anda,',
                        'bukti pembayaran (struk atau mutasi rekening) beserta tanggal dan nominalnya,',
                        'alasan pengajuan.',
                    ]],
                ]],
                ['Proses pengembalian', [
                    ['p', 'Kami memeriksa pengajuan Anda dan memberi kabar lewat email. Bila disetujui, dana dikembalikan dalam 7 sampai 14 hari kerja ke rekening atau metode pembayaran asal.'],
                ]],
                ['Berhenti berlangganan', [
                    ['p', 'Anda tidak perlu mengajukan apa pun untuk berhenti: cukup tidak memperpanjang. Setelah masa langganan dan masa tenggang berakhir, akun menjadi hanya-baca. Data Anda tetap aman dan bisa dilihat, dan akun bisa dipakai penuh lagi kapan saja dengan memilih paket.'],
                ]],
                ['Kontak', [
                    ['p', 'Pertanyaan tentang kebijakan ini: {kontak}{alamat}. Pembaruan terakhir: {tanggal}.'],
                ]],
            ],
        ],
    ],
];
