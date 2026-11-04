<div style="display:grid;gap:.75rem;font-size:.875rem">
    <p style="margin:0">Untuk website yang sudah punya desain sendiri. Kirim dengan metode POST; kolom tersembunyi "lf_hp_x" adalah jebakan robot, jangan dihapus. Kiriman yang mengisinya tetap disimpan dengan tanda "dicurigai spam", kecuali nomornya tidak valid.</p>
    <textarea readonly rows="9" onclick="this.select()" style="width:100%;font:.8rem/1.5 ui-monospace,monospace;padding:.6rem;border:1px solid var(--gray-300);border-radius:.5rem;background:var(--gray-50)">&lt;form action="{{ $endpoint }}" method="post"&gt;
  &lt;input name="name" placeholder="Nama" required&gt;
  &lt;input name="phone" placeholder="No. WhatsApp" required&gt;
  &lt;input name="note" placeholder="Pesan (opsional)"&gt;
  &lt;div style="display:none" aria-hidden="true"&gt;&lt;input type="text" name="lf_hp_x" tabindex="-1" autocomplete="off" data-lpignore="true"&gt;&lt;/div&gt;
  &lt;button type="submit"&gt;Kirim&lt;/button&gt;
&lt;/form&gt;</textarea>
    <p style="margin:0;color:var(--gray-600)">Tautan lama <code>{{ $legacy }}</code> tetap menampilkan Formulir utama.</p>
</div>
