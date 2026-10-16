# Penerapan ownership SQLite — rencana, belum dijalankan

Urutan: migration lama koleksi → 2026_10_14_000001 sampai 000005 → 000006 → 000007.
000005 menambahkan kolom/index/FK ownership; 000006 menguatkan operasi dan OTP;
000007 merekonstruksi kedua tabel koleksi dengan visitor_id nullable dan CHECK XOR.
Migration 000001–000006 tidak diubah. 000006 menolak tabel operasi/OTP berisi data.

## Prasyarat sebelum penerapan

- Persetujuan terpisah untuk penerapan dan verifikasi; hentikan seluruh penulisan aplikasi.
- Backup SQLite konsisten (termasuk keadaan WAL melalui mekanisme backup SQLite),
  disimpan aman di luar document root. Verifikasi prosedur pemulihannya terlebih dahulu.
- Sediakan ruang disk untuk salinan kedua tabel, index dan journal transaksi.
- Aktifkan FK pada koneksi sebelum migration; 000007 tidak menonaktifkan FK.
- Tinjau dan verifikasi pada salinan terisolasi terlebih dahulu setelah mendapat izin.
  Source ini belum dijalankan ataupun diuji.

## Preflight dan pelestarian

000007 memeriksa kedua tabel sebelum perubahan: DDL pasca-000005, nama/urutan
kolom, definisi semua index, FK, nama tabel sementara, dependensi FK masuk,
trigger/view terkait, ownership existing dan metadata sqlite_sequence.
Skema ekuivalen dengan format yang tidak dikenali pun dapat ditolak; jangan
melemahkan pemeriksaan tanpa audit ulang. CHECK/trigger tambahan tidak dibuang:
migration menolak skema yang tidak diharapkan.

Saat migration kelak dijalankan, pemeriksaan ownership dan FK membaca data secara
internal, serta memakai COUNT dan EXCEPT dua arah untuk membandingkan salinan.
Tidak ada nilai record yang ditampilkan. Daftar kolom copy selalu eksplisit.

Kedua rekonstruksi berada dalam satu transaksi. Copy diverifikasi sebelum DROP
terkontrol, kemudian nama tabel, seluruh index lama/baru, FK outgoing dan
high-water mark AUTOINCREMENT dipertahankan. Tidak memakai INSERT OR REPLACE.
Baris anonim tidak diklaim atau dihapus. Relasi user/operation dari 000005 tetap ada.

Atomicity ini berlaku pada 000007, bukan seluruh rangkaian migration: kegagalan
000007 tidak membatalkan migration sebelumnya yang sudah selesai. Periksa
metadata status migration sebelum melanjutkan setelah kegagalan.

## Verifikasi setelah penerapan yang disetujui

Periksa metadata final, integritas SQLite dan foreign_key_check, jumlah baris,
kesetaraan data, index, serta sqlite_sequence tanpa menampilkan data pengguna.
Uji penolakan dua pemilik/tanpa pemilik dan penerimaan owner user/guest legacy
hanya di salinan terisolasi. Guest baru tetap harus dilarang oleh auth/service;
CHECK XOR tidak membedakan guest lama dari insert guest baru.

## Rollback

Rollback otomatis ditolak karena visitor_id NULL sah untuk user login.
Jangan menjalankan rollback/fresh untuk pemulihan. Pulihkan hanya dari backup
konsisten melalui prosedur yang disetujui, saat semua penulisan dihentikan dan
kode sesuai dengan versi skema backup. Restore dapat menghilangkan data yang
tercipta setelah backup; rekonsiliasi dan persetujuan diperlukan terlebih dahulu.
