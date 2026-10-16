<footer><div class="wrap"><nav aria-label="Tautan bawah">
    <a href="{{ url('/') }}">Beranda</a>
    <a href="{{ url('/faq') }}">FAQ</a>
    <a href="{{ url('/kontak') }}">Kontak</a>
    <a href="{{ \App\Legal\LegalDocs::url('kebijakan-privasi') }}">Kebijakan Privasi</a>
    <a href="{{ \App\Legal\LegalDocs::url('syarat-layanan') }}">Syarat Layanan</a>
    <a href="{{ \App\Legal\LegalDocs::url('kebijakan-pengembalian-dana') }}">Kebijakan Pengembalian Dana</a>
</nav></div></footer>
