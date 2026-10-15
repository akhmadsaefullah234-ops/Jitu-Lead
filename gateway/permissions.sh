#!/usr/bin/env bash
# Hak akses folder data gateway, dipakai gateway/install.sh.
#
# Gateway (pengguna jitu-gw) menulis sesi WhatsApp. Backup CRM berjalan sebagai
# www-data dan hanya perlu membacanya, jadi www-data dimasukkan ke grup jitu-gw:
# folder 2750 (setgid, grup bisa masuk), berkas 0640 (grup bisa baca), orang lain
# tidak bisa apa-apa. Tidak memakai ACL, karena ACL tertutup mask saat berkas
# ditulis 0600 dan itu yang membuat backup gagal.

# normalize_data_perms <folder-data> <grup>
normalize_data_perms() {
  local dir=$1 group=$2

  command -v setfacl >/dev/null 2>&1 && setfacl -R -b "$dir" 2>/dev/null || true
  chgrp -R "$group" "$dir"
  find "$dir" -type d -exec chmod 2750 {} +
  find "$dir" -type f -exec chmod 0640 {} +
}
