<p style="margin-top:1rem;text-align:center;font-size:.8rem;color:#6b7280">
    @if ($register ?? false)Dengan mendaftar, Anda menyetujui <a href="{{ url('/syarat-layanan') }}" target="_blank" rel="noopener" style="text-decoration:underline">Syarat Layanan</a> dan <a href="{{ url('/kebijakan-privasi') }}" target="_blank" rel="noopener" style="text-decoration:underline">Kebijakan Privasi</a> kami.
    @else <a href="{{ url('/syarat-layanan') }}" target="_blank" rel="noopener" style="text-decoration:underline">Syarat Layanan</a> · <a href="{{ url('/kebijakan-privasi') }}" target="_blank" rel="noopener" style="text-decoration:underline">Kebijakan Privasi</a>@endif
</p>
