# ADR: Database Production — SQLite pada satu server

- **Status:** Diterima (Tahap 14). Konfigurasi Production belum diterapkan; tidak ada perubahan pada database yang sedang dipakai.
- **Konteks:** Dapur Rantau adalah aplikasi Laravel dengan paket Premium Rp5.000–Rp15.000, pembayaran Midtrans, dan operasi refund lewat CLI oleh operator. Rantai migration saat ini hanya berjalan di SQLite (`2026_10_14_000006/7`, `2026_11_26`, `2026_11_27` menolak driver lain; `2026_10_14_000001`–`000005` menolak PostgreSQL).

## Keputusan

Production memakai **SQLite pada satu server aplikasi**, file di `/var/lib/dapur-rantau/database.sqlite` pada **persistent local storage**, dengan:

- `DB_JOURNAL_MODE=wal`, `DB_SYNCHRONOUS=normal`, `DB_BUSY_TIMEOUT=5000`;
- satu server aplikasi (web, scheduler, dan CLI operator di host yang sama);
- tidak ada network filesystem (NFS, SMB, EFS, GlusterFS) untuk file database;
- backup hanya lewat `sqlite3 .backup` (bukan `cp` saat aplikasi berjalan), checksum, salinan di luar server, uji restore berkala;
- pemantauan kapasitas disk dan kesehatan database.

Pada AWS EC2: letakkan `/var/lib/dapur-rantau` di **volume EBS persisten** (bukan *instance store* yang hilang saat instance berhenti), aktifkan snapshot EBS terjadwal sebagai lapisan tambahan (bukan pengganti `.backup`), dan pastikan volume tidak ikut terhapus saat instance diganti (`DeleteOnTermination=false`).

## Kelebihan

- Tidak ada server database terpisah yang harus dioperasikan, dipatch, dan diamankan.
- Semua invariant pembayaran yang sudah diuji (write lock lewat `UPDATE users`, compare-and-set status refund, unique `active_slot`, trigger append-only audit) terverifikasi pada SQLite.
- Backup/restore sederhana dan sudah disimulasikan (Tahap 12A–12.4).
- Volume tulis Dapur Rantau (checkout, webhook, rekonsiliasi terjadwal, operasi refund manual) jauh di bawah batas satu penulis SQLite.

## Keterbatasan dan risiko

- **Satu penulis pada satu waktu.** Setiap transaksi tulis mengambil write lock seluruh database. Penulis lain menunggu sampai `busy_timeout`; setelah itu aplikasi menjawab "database sibuk" (`SqliteBusyException`) tanpa mengubah data. Transaksi pembayaran sengaja diawali penulisan agar tidak terjadi upgrade lock yang macet.
- **WAL:** pembaca tidak memblokir penulis, tetapi file `-wal` dan `-shm` harus berada di direktori yang sama dan dapat ditulis akun aplikasi. Data yang belum di-checkpoint hanya ada di `-wal`; menyalin file utama saja menghasilkan backup yang hilang datanya (terbukti pada simulasi). WAL membutuhkan shared memory, sehingga **tidak bekerja lintas host**. WAL yang terus membesar menandakan pembaca panjang yang menahan checkpoint; pantau ukuran `-wal`.
- **Tidak bisa multi-server:** beberapa server aplikasi terhadap satu file, atau file di network filesystem, berisiko korupsi karena locking file tidak dapat diandalkan. Cache lock scheduler (`withoutOverlapping`, lock rekonsiliasi) juga mengasumsikan satu host.
- **Skalabilitas vertikal saja:** lebih banyak CPU/disk cepat membantu, replika baca/tulis tidak tersedia.
- **Proteksi audit bergantung pada izin file:** siapa pun dengan akses tulis file database dapat melewati trigger; hanya akun aplikasi yang boleh menulis.

## Backup, restore, dan korupsi

- Prosedur lengkap: [operasional-pembayaran.md](operasional-pembayaran.md) bagian 8–11.
- Pemeriksaan rutin: `sqlite3 <db> 'pragma integrity_check;'` dan `'pragma foreign_key_check;'`, ruang disk, ukuran `-wal`.
- **Penanganan korupsi:** `php artisan down`, hentikan scheduler/worker, salin database rusak beserta `-wal`/`-shm` ke folder insiden (jangan dihapus), restore backup terakhir yang lolos `integrity_check`, lalu rekonsiliasi dengan Dashboard Midtrans untuk periode sejak backup (`payments:reconcile`, `payments:gateway-refunds --reconcile`). Upaya `sqlite3 .recover` hanya dilakukan pada salinan, sebagai bahan investigasi, bukan pengganti restore.
- Rollback migration tidak dipakai untuk data Production; migration pembayaran menolak rollback bila tabel histori berisi data.

## Kondisi yang mewajibkan migrasi ke PostgreSQL

Rencanakan migrasi ke PostgreSQL (dengan porting migration SQLite-only dan pengujian ulang locking, unique nullable `active_slot`, compare-and-set, serta pengganti trigger audit) bila salah satu terjadi:

1. Dibutuhkan lebih dari satu server aplikasi, autoscaling, atau zero-downtime deploy dengan dua instance aktif bersamaan.
2. Respons "database sibuk" atau antrean tulis muncul berulang pada beban normal meskipun `busy_timeout` sudah dinaikkan.
3. Ukuran database atau waktu `.backup` mengganggu operasi (misalnya backup tidak selesai dalam jendela pemeliharaan).
4. Dibutuhkan replika baca, point-in-time recovery terkelola, atau pemisahan kewenangan di level database (role terpisah untuk audit).
5. Proses tulis berat baru (misalnya antrean worker paralel) ditambahkan.

## Konsekuensi

- Deployment Production terikat pada satu host dengan volume persisten; arsitektur multi-server memerlukan ADR baru.
- Keputusan ini tidak menyelesaikan blocker Production lain (lihat runbook bagian 13).
