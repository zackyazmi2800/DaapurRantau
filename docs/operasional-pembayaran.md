# Runbook operasional pembayaran dan refund (Production)

Panduan operator untuk Midtrans dan database SQLite Production. Semua langkah memakai Artisan CLI atau `sqlite3` untuk backup; tidak ada langkah yang mengubah isi tabel secara manual. Desain refund dan konfigurasi environment dijelaskan di [premium-sandbox.md](premium-sandbox.md#sandbox-dan-production-tahap-9).

Konvensi: aplikasi berada di `/var/www/dapur-rantau` (sesuaikan), database Production `/var/lib/dapur-rantau/database.sqlite`, dan semua perintah dijalankan sebagai user aplikasi dari direktori aplikasi.

## 1. Konfigurasi server

| Variabel | Nilai Production | Keterangan |
| --- | --- | --- |
| `DB_CONNECTION` | `sqlite` | Satu-satunya database yang didukung rantai migration (migration `2026_10_14_000006/7` khusus SQLite). |
| `DB_DATABASE` | `/var/lib/dapur-rantau/database.sqlite` | Di luar `public/`. Direktori harus dapat ditulis user aplikasi karena WAL membuat file `-wal` dan `-shm` di sampingnya. |
| `DB_JOURNAL_MODE` | `wal` | Pembaca tidak memblokir penulis. Hanya `wal`/`delete` yang diterima; kosong = bawaan file. |
| `DB_SYNCHRONOUS` | `normal` | Aman untuk WAL; `full` bila ingin durabilitas maksimum. |
| `DB_BUSY_TIMEOUT` | `5000` | Milidetik menunggu lock (dibatasi 100–30000). |
| `TRUSTED_PROXIES` | IP/CIDR proxy yang benar-benar ada | Lihat bagian 6. Kosong bila TLS diterminasi langsung oleh web server aplikasi. |
| `TRUSTED_PROXY_HEADERS` | `x-forwarded` atau `forwarded` | Sesuai header yang dikirim proxy. |
| `MIDTRANS_ENVIRONMENT` | `production` | Wajib eksplisit. |
| `MIDTRANS_PRODUCTION_*` | dari Dashboard Production | Server Key: Snap Checkout **Write**, Core API **Read** (Get Status), capability **Refund transaction: Write**; tanpa izin Midtrans menjawab HTTP 403. Perhatikan tanggal kedaluwarsa Server Key. |
| `PAYMENT_ALERT_EMAIL` | alamat operator | Opsional. Email alert hanya dikirim lewat transport nyata (`smtp`, `sendmail`, `ses`, `ses-v2`, `postmark`, `resend`, `mailgun`) dengan konfigurasi lengkap dan `MAIL_FROM_ADDRESS` nyata. Periksa dengan `php artisan payments:alert-status`. |
| `MIDTRANS_WEBHOOK_ENABLED` | `false` sampai bagian 7 selesai | |
| `MIDTRANS_WEBHOOK_RATE_LIMIT` | `120` | Request per menit per IP klien asli (10–1000). |

**SQLite hanya untuk satu server aplikasi.** Jangan menjalankan beberapa server aplikasi terhadap file yang sama, jangan meletakkannya di network filesystem (NFS/SMB), dan jangan memakai load balancer ke beberapa host. Locking dan compare-and-set aplikasi hanya terverifikasi pada SQLite satu host. Rantai migration saat ini tidak berjalan di MySQL/PostgreSQL (lihat bagian 12). Setelah mengubah `.env`, jalankan `php artisan config:cache`.

Scheduler wajib aktif (cron setiap menit):

```cron
* * * * * cd /var/www/dapur-rantau && php artisan schedule:run >> /dev/null 2>&1
```

Jadwal yang relevan: `payments:reconcile` (pembayaran pending/review, 5 menit) dan `payments:gateway-refunds --reconcile` (refund gateway, 15 menit). Keduanya hanya memanggil Get Status; **tidak ada refund yang pernah dikirim otomatis**.

## 2. Status refund gateway

| Status | Arti | Tindakan operator |
| --- | --- | --- |
| `pending` | Intent tersimpan; percobaan sedang/baru dikirim atau proses terhenti sebelum hasilnya tercatat | Tunggu rekonsiliasi; bila tetap `pending`, ikuti bagian 4 |
| `submitted` | Midtrans menjawab 200, belum tampil di `refunds[]` | Tunggu rekonsiliasi; **jangan** reattempt |
| `unknown` | Timeout, 5xx, 406, 429, atau respons tidak jelas | Bagian 4 |
| `review` | Nominal di Midtrans berbeda dari permintaan | Bagian 5 |
| `succeeded` | Tampil di `refunds[]`, `bank_confirmed_at` belum ada; dana belum tentu sampai | Tidak perlu tindakan; rekonsiliasi berjalan otomatis |
| `confirmed` | `bank_confirmed_at` terisi | Selesai |
| `rejected` | Penolakan definitif pada percobaan pertama dan tidak tampil di `refunds[]` | Selesai; refund baru memerlukan `refund_key` baru |
| `closed_unprocessed` | Ditutup administratif (hanya di lingkungan pengembangan, bagian 4A) setelah konfirmasi tertulis Midtrans | Slot dilepas, **nominal tetap dicadangkan** dalam batas refund. Dicek ulang setiap rekonsiliasi selama 30 hari, lalu sekali sehari tanpa batas. Bila Midtrans ternyata mencantumkannya: bagian 4B |

Percobaan `pending` yang hasilnya tidak pernah tercatat (misalnya proses mati di tengah POST) otomatis menjadi `unknown` oleh rekonsiliasi setelah 30 menit (`midtrans.refund.pending_stale_minutes`); slotnya tetap ditahan.

Status yang sudah tampil di Midtrans tidak pernah mundur: rekonsiliasi memakai compare-and-set, sehingga snapshot Get Status yang lebih lama atau dua proses bersamaan tidak dapat mengembalikan `succeeded`/`confirmed` ke status sebelumnya maupun melepas slot refund.

## 3. Pemantauan dan rekonsiliasi

```sh
# Semua refund yang masih perlu dipantau (pending, submitted, unknown, review, succeeded)
php artisan payments:gateway-refunds
# Kategori tertentu
php artisan payments:gateway-refunds --status=unknown
php artisan payments:gateway-refunds --status=pending,submitted
php artisan payments:gateway-refunds --status=succeeded      # sudah di Midtrans, belum dikonfirmasi bank
php artisan payments:gateway-refunds --status=review
php artisan payments:gateway-refunds --status=closed_unprocessed
# Rekonsiliasi semua (sama dengan jadwal) atau satu pembayaran
php artisan payments:gateway-refunds --reconcile --limit=20
php artisan payments:gateway-refund <id-pembayaran> --reconcile
```

Daftar menampilkan ID, pembayaran, order ID, `refund_key`, nominal, status, environment, jumlah percobaan, waktu dibuat, waktu dicek terakhir, dan batas reattempt. Hasil rekonsiliasi dicatat sebagai hitungan per status (`unchanged`, `succeeded`, `confirmed`, `unverified`, `inactive`, `skipped`, `busy`).

**Batas refund** dihitung satu kali untuk refund gateway maupun manual: refund Midtrans dihitung per `refund_key` (nominal individual `refunds[]` bila Get Status tersedia, lalu adjustment Midtrans, lalu baris refund gateway), ditambah refund manual, ditambah **cadangan** untuk refund gateway yang belum tercantum di Midtrans (`pending`, `submitted`, `unknown`, `closed_unprocessed`). Satu `refund_key` tidak pernah dihitung dua kali. Selama ada cadangan, refund manual tidak pernah dianggap refund penuh.

**Peringatan (alert):** `payment.refund_excess`, `payment.gateway_refund_closed_unprocessed_but_listed`, dan `payment.gateway_refund_rejected_but_listed` dicatat di level **error** (`alert=true`) — ini **alert lokal** yang selalu aktif. Email alert (`PaymentAlertMail`, hanya ID dan nominal) dicatat jujur di log `payment.alert_mail` dengan `mail_status`:
- `sent`: transport email nyata menerima pesan (belum berarti sampai ke inbox);
- `logged_only`: mailer `log`/`array`, email **tidak** benar-benar dikirim;
- `failed`: konfigurasi tidak lengkap, transport tidak didukung (`failover`/`roundrobin` dapat berakhir di log), atau pengiriman gagal. Kegagalan alert tidak pernah menggagalkan proses pembayaran atau refund.

`php artisan payments:alert-status` menampilkan kesiapan tanpa mengirim email dan tanpa menampilkan credential (exit 1 bila email diminta tetapi tidak dapat dikirim). Tidak ada integrasi alert eksternal lain; untuk Slack, tambahkan channel `slack` ke `LOG_STACK` dan isi `LOG_SLACK_WEBHOOK_URL` (channel bawaan Laravel, belum diuji di proyek ini). Riwayat status setiap refund gateway tersimpan append-only di `payment_gateway_refund_events`.

Pantau log berikut (tanpa credential atau payload): `payment.gateway_refund` (level info; set `LOG_LEVEL=info`), `payment.gateway_refund_request_failed`, `payment.gateway_refund_adjustment_unverified`, `payment.gateway_refund_rejected_but_listed`, `payment.processing_failed`, `payment.refund_excess`, `payment.refund_reference_conflict`.

**Refund `pending`/`submitted`:** biasanya selesai pada rekonsiliasi berikutnya. Jangan membuat refund baru; perintah refund akan menolak selama slot pembayaran masih ditahan.

**Refund `succeeded`:** dana sudah diproses Midtrans tetapi konfirmasi bank belum ada. Penyesuaian Premium (refund penuh mencabut sisa Premium; refund sebagian membuka peninjauan) sudah diterapkan. Rekonsiliasi terjadwal memperbarui ke `confirmed`.

**Refund `confirmed`:** selesai. Untuk refund sebagian, tutup peninjauan dengan `payments:review` bila keputusan bisnis sudah ada.

## 4. Refund `unknown` atau `pending` yang menggantung

Prinsip: hasil yang ambigu tidak pernah dianggap gagal, dan request yang hasilnya belum diketahui **tidak boleh** diganti dengan `refund_key` baru. Selama refund ini terbuka, refund gateway baru dan pencatatan manual `payments:refund` untuk pembayaran yang sama ditolak.

1. Rekonsiliasi dulu: `php artisan payments:gateway-refund <id> --reconcile`. Bila Midtrans sudah menampilkan `refund_key`, status langsung menjadi `succeeded`/`confirmed`.
2. Periksa Dashboard Midtrans (transaksi → riwayat refund) untuk `refund_key` tersebut.
3. Bila tetap tidak tampil, lakukan **reattempt dengan `refund_key` yang sama**. Dokumentasi Midtrans menyatakan request dengan `refund_key` yang sama diperlakukan sebagai pengulangan refund yang sama, dan reattempt hanya boleh maksimal 7 hari setelah percobaan pertama.

```sh
# Dry-run: memeriksa ulang Get Status, sisa nominal, dan batas waktu
php artisan payments:gateway-refund <id> --reattempt --refund-key=<refund_key>
# Kirim (Sandbox)
php artisan payments:gateway-refund <id> --reattempt --refund-key=<refund_key> --confirm
# Kirim (Production)
php artisan payments:gateway-refund <id> --reattempt --refund-key=<refund_key> --confirm --confirm-production=<order_id>
```

Pengaman reattempt:
- Nominal dan alasan selalu diambil dari intent tersimpan; operator tidak dapat mengubahnya.
- Hanya untuk status `pending`/`unknown`, environment yang sama, dan pembayaran yang masih `paid`.
- Bila `refund_key` sudah tampil di Get Status, tidak ada POST; status langsung diperbarui.
- Sisa nominal diperiksa ulang terhadap `refunds[]`, refund Midtrans lokal, dan refund manual.
- Jeda minimal 10 menit antar percobaan (`midtrans.refund.reattempt_cooldown_minutes`).
- Batas waktu 144 jam (6 hari) dihitung dari pembuatan intent, yang selalu sebelum POST pertama, sehingga ada margin 24 jam dari batas 7 hari Midtrans (`midtrans.refund.reattempt_window_hours`).
- Klaim percobaan memakai compare-and-set atas `attempts`, sehingga dua operator tidak dapat mengirim reattempt yang sama bersamaan.
- Penolakan definitif pada reattempt tidak membuat status `rejected`: refund tetap `unknown`, karena penolakan itu tidak membuktikan nasib percobaan sebelumnya.

4. **Setelah batas reattempt lewat** dan refund masih `unknown`: perintah reattempt menolak dan refund tetap menahan slot. Hubungi Midtrans support dengan order ID, `refund_key`, waktu dibuat, dan nominal (lihat `payments:gateway-refunds --status=unknown`), lalu minta konfirmasi **tertulis** apakah refund tersebut diproses. Jangan membuat `refund_key` baru dan jangan mengubah tabel secara manual.
   - Midtrans menyatakan refund **diproses**: jalankan `--reconcile`; bila Get Status belum mencantumkannya, eskalasikan ke Midtrans. Jangan menutup refund.
   - Midtrans menyatakan tertulis refund **tidak pernah diproses**: ikuti bagian 4A.

## 4A. Kebijakan penutupan refund `unknown` (two-person approval)

> **Status: dinonaktifkan untuk Production.** Kedua perintah menolak bila `APP_ENV=production` atau `MIDTRANS_ENVIRONMENT=production`, tanpa variabel untuk menyalakannya. Di Production refund `unknown` tetap terbuka (slot ditahan) sampai Midtrans mencantumkannya dan `--reconcile` menyelesaikannya, atau sampai pemisahan kewenangan yang benar tersedia (bagian 4C). Alur di bawah hanya untuk lingkungan pengembangan dan pengujian prosedur.

Penutupan hanya untuk refund yang **benar-benar** dikonfirmasi Midtrans tidak diproses. Tidak ada cara menutup refund karena operator menganggapnya gagal. Penutupan bukan bukti bahwa refund gagal: nominalnya tetap dicadangkan.

Syarat (semuanya diperiksa oleh aplikasi):
1. Status refund `unknown` (refund `pending` basi menjadi `unknown` lewat rekonsiliasi setelah 30 menit).
2. Batas reattempt 144 jam sudah lewat.
3. Konfirmasi tertulis Midtrans bahwa refund tidak pernah diproses, yang menyebut **Order ID, refund_key, dan nominal**; ketiganya diketik ulang operator dan harus sama persis dengan data refund.
4. Referensi tiket/bukti Midtrans (3–100 karakter: huruf, angka, `#`, `.`, `_`, `/`, `-`) dan tanggal konfirmasi (`YYYY-MM-DD`, tidak di masa depan menurut WIB, tidak sebelum refund dibuat).
5. Get Status Midtrans saat pengajuan dan saat persetujuan **tidak** mencantumkan `refund_key` tersebut. Bila tercantum, penutupan ditolak dan status refund diperbarui dari Midtrans.
6. Dua akun sistem operasi yang berbeda: pengaju dan penyetuju.
7. Tidak ada anomali `refund_excess` pada pembayaran.

**Identitas operator** diambil dari UID nyata akun sistem operasi yang menjalankan perintah (`posix_getuid`), bukan dari argumen CLI atau variabel environment. Perintah menolak root (UID 0), proses dengan UID nyata berbeda dari UID efektif (setuid), konteks di luar CLI, atau tanpa ekstensi POSIX. `SUDO_UID` dan variabel serupa tidak dipercaya. Persetujuan dengan UID yang sama dengan pengaju selalu ditolak, meskipun nama berbeda.

**Batas keamanan yang diketahui (alasan dinonaktifkan di Production):**
- Yang diidentifikasi adalah **akun**, bukan orang. `sudo -u <akun>` dan `su <akun>` mengubah UID nyata *dan* efektif ke akun target, sehingga **tidak** terdeteksi; root dapat menjadi akun mana pun tanpa password. Satu orang dengan hak sudo atau dua akun dapat memenuhi syarat sendirian.
- Alur ini mengharuskan operator memiliki izin tulis ke file SQLite. Dengan izin yang sama, siapa pun dapat menutup refund lewat `UPDATE` langsung tanpa baris audit, menyisipkan baris audit palsu (trigger hanya menolak `UPDATE`/`DELETE`), menghapus trigger, atau mem-bootstrap aplikasi dengan identitas tiruan. Ini sudah dibuktikan pada database sementara di Tahap 12.2.

**Langkah 1 — operator pertama mengajukan** (dry-run tanpa `--confirm`):

```sh
php artisan payments:gateway-refunds --status=unknown     # kolom ID = ID refund
php artisan payments:gateway-refund-close-request <id-refund> \
  --ticket=<referensi-tiket-midtrans> --confirmed-on=YYYY-MM-DD \
  --order-id=<order_id> --refund-key=<refund_key> --amount=<nominal> \
  --midtrans-confirmed-not-processed --reason="<alasan, 10-255 karakter>" --confirm
```

Pengajuan hanya mencatat permintaan; status dan slot refund belum berubah. Hanya satu permintaan terbuka per refund. Perintah mencetak ID permintaan.

**Langkah 2 — operator kedua (akun OS lain) memeriksa bukti asli lalu menyetujui** dalam 72 jam (`midtrans.refund.closure_request_ttl_hours`); setelah itu permintaan kedaluwarsa dan harus diajukan ulang:

```sh
php artisan payments:gateway-refund-close-approve <id-permintaan> --ticket=<referensi-tiket-midtrans>            # dry-run
php artisan payments:gateway-refund-close-approve <id-permintaan> --ticket=<referensi-tiket-midtrans> --confirm
```

`--ticket` harus diketik ulang dari bukti asli dan sama dengan pengajuan. Saat persetujuan aplikasi memeriksa ulang status refund, kesesuaian payment/order/refund_key/nominal/environment dengan pengajuan, dan Get Status Midtrans. Penutupan dilakukan dalam satu transaksi database dengan compare-and-set (status `unknown`, slot masih dipegang, refund_key dan nominal sama). Bila ada yang berubah bersamaan, transaksi dibatalkan, tidak ada catatan persetujuan, dan slot tetap ditahan. Hasil: status `closed_unprocessed`, `active_slot` dilepas, nominal dan `refund_key` historis tidak berubah. Kedua perintah tidak pernah mengirim Refund API.

**Audit trail** ada di tabel `payment_gateway_refund_closures` (satu baris per pengajuan dan per persetujuan): ID refund, ID permintaan, payment, Order ID, refund_key, nominal, environment, tiket Midtrans, tanggal konfirmasi, alasan, UID dan nama akun OS, waktu pengajuan (`created_at` baris `request`), waktu persetujuan dan waktu penutupan (`created_at`/`closed_at` baris `approval`). Trigger SQLite menolak setiap `UPDATE`/`DELETE` pada tabel ini, menolak perubahan `payment_id`, `refund_key`, `amount`, `reason`, `environment`, `operator`, `created_at` serta penghapusan baris di `payment_gateway_refunds`, dan menolak memindahkan refund yang sudah ditutup ke status selain yang dicantumkan Midtrans. Proteksi ini dapat dilewati oleh siapa pun yang memiliki akses tulis langsung ke file database (misalnya membuka `sqlite3` dan menghapus trigger); batasi akses file dan simpan backup (bagian 8) sebagai salinan audit.

Setelah ditutup, refund berikutnya untuk pembayaran itu memakai `refund_key` baru. Nominal yang ditutup **tetap dicadangkan**: sisa refund berkurang sebesar nominal tersebut untuk refund gateway maupun manual, karena penutupan administratif tidak membuktikan Midtrans tidak akan memprosesnya.

## 4B. Refund tertutup atau ditolak yang muncul kembali di Midtrans

Rekonsiliasi mengenali refund berdasarkan `refund_key` dan memindahkan baris yang sama ke `succeeded`/`confirmed` (atau `review` bila nominal berbeda), dengan nominal individual dari `refunds[]` tercatat di `payment_gateway_refund_events.listed_amount`. Baris pengajuan/persetujuan penutupan dan nominal historis tidak berubah. Adjustment Midtrans dicatat sekali per `refund_key`; bila pembayaran sudah `refunded`, refund tersebut tetap masuk buku refund sebagai peninjauan, tidak lagi gagal dengan HTTP 422. Log/alert error `payment.gateway_refund_closed_unprocessed_but_listed` dibuat.

Tindakan operator: periksa Dashboard Midtrans, hubungi pelanggan bila dana sudah dikembalikan dua kali, dan catat temuan insiden. Jangan mencoba menghapus atau mengubah baris refund.

## 4C. Kelebihan refund (`refund_excess`)

Bila uang yang diketahui sudah dikembalikan (Midtrans per `refund_key` + manual; cadangan tidak ikut) melebihi nominal pembayaran, aplikasi mencatat baris append-only di `payment_refund_anomalies` (nominal pembayaran, total, kelebihan, dan bukti per sumber dengan hash `refund_key`), membuat alert error `payment.refund_excess`, dan **memblokir** refund gateway, refund manual, reattempt, dan penutupan untuk pembayaran tersebut. Refund yang benar-benar terjadi tetap dicatat; tidak ada histori yang dihapus. Referensi manual yang sama dengan `refund_key` Midtrans dihitung sekali dalam deteksi (kemungkinan transfer yang sama tercatat dua kali dan ditangani `payments:review`).

Belum ada perintah untuk menutup anomali; refund untuk pembayaran tersebut tetap terblokir sampai kebijakan peninjauan anomali diputuskan.

## 4D. Rancangan pemisahan kewenangan untuk Production (belum diimplementasikan)

Penutupan refund `unknown` di Production memerlukan keputusan dan persetujuan khusus sebelum dibangun. Rancangan yang disarankan:
1. Hanya akun aplikasi yang dapat menulis database; operator tidak memiliki akses file database maupun kode yang dapat dieksekusi sebagai akun aplikasi.
2. Operator menjalankan perintah lewat aturan sudoers khusus ke wrapper milik root yang mencatat UID asal dan meneruskannya ke aplikasi; tidak ada akses shell sebagai akun aplikasi.
3. Trigger `INSERT` yang memvalidasi baris persetujuan (pengajuan ada, UID berbeda, status refund) dan hash chain antarbaris audit.
4. Salinan audit ke penyimpanan eksternal append-only (misalnya remote syslog) yang tidak dapat diubah administrator server aplikasi.
5. Akses root dibatasi, diaudit, dan tidak dipegang oleh operator yang menyetujui penutupan.

## 5. Refund `review`

Midtrans menampilkan `refund_key` dengan nominal berbeda dari intent. Slot pembayaran tetap ditahan. Bandingkan dengan Dashboard Midtrans, lalu eskalasikan ke Midtrans. Jangan mengirim refund lain untuk pembayaran tersebut.

## 6. Trusted proxy dan HTTPS

Isi `TRUSTED_PROXIES` **hanya** dengan alamat proxy yang benar-benar berada di depan aplikasi, setelah memeriksa infrastruktur (misalnya alamat Nginx lokal `127.0.0.1`, IP load balancer, atau rentang resmi CDN yang dipakai). Aturan konfigurasi:

- Format: daftar dipisah koma berisi IPv4/IPv6 atau CIDR, contoh `TRUSTED_PROXIES=127.0.0.1,10.0.0.0/24`.
- Wildcard (`*`, `**`), rentang menyeluruh (`0.0.0.0/0`, `::/0`, mask IPv4 < /8 atau IPv6 < /32), `REMOTE_ADDR`, dan nilai tidak valid diabaikan. Nilai kosong berarti tidak ada proxy yang dipercaya dan framework tidak pernah otomatis mempercayai `*`.
- `TRUSTED_PROXY_HEADERS=x-forwarded` memercayai `X-Forwarded-For/Host/Port/Proto`; `forwarded` memercayai header RFC 7239. Proxy wajib **menimpa** (bukan menambahkan) header tersebut dari klien.
- Deteksi HTTPS memakai `X-Forwarded-Proto`/`Forwarded` dari proxy tepercaya. Bila TLS diterminasi langsung oleh web server aplikasi, `TRUSTED_PROXIES` dibiarkan kosong. Set `APP_URL` ke URL HTTPS.

Verifikasi setelah deploy: request dari luar harus tercatat dengan IP klien asli (bukan IP proxy) di log web server, dan URL yang dibuat aplikasi (misalnya tautan kembali dari Snap) memakai `https://`.

## 7. Persiapan webhook Production

1. Pastikan bagian 6 selesai; tanpa trusted proxy semua notifikasi berbagi satu kuota rate limit.
2. Konfirmasi rumus signature. Aplikasi memakai SHA-512 dari `order_id + status_code + gross_amount + Signature Secret Key` (`MIDTRANS_PRODUCTION_SIGNATURE_SECRET_KEY`) dan menolak signature berbasis Server Key; tidak ada fallback ke dua jenis signature.
   - Dokumentasi Midtrans **Access Keys** (diperbarui 2026-11-03) menyatakan: sejak 28 Oktober 2026 kunci yang memverifikasi HTTP notification adalah kunci terpisah, **Signature Secret Key** ("Verifies the signature_key field in HTTP notifications and API responses"), dan Server Key dapat lebih dari satu dengan izin serta tanggal kedaluwarsa masing-masing.
   - Dokumentasi **HTTP(S) Notification** (diperbarui 2026-11-16) masih menulis `SHA512(order_id+status_code+gross_amount+ServerKey)`.
   - Aplikasi mengikuti perubahan 28 Oktober yang eksplisit dan Dashboard merchant Sandbox (menampilkan Signature Secret Key terpisah). Karena kedua halaman bertentangan dan belum ada notifikasi asli yang diterima, **webhook Production tetap nonaktif** sampai notifikasi uji dari Dashboard Production terbukti lolos verifikasi. Bila ternyata ditolak 403, jangan mengaktifkan fallback; perubahan rumus memerlukan perubahan kode dan review terpisah.
3. Saat webhook nonaktif, endpoint mengembalikan 404 untuk semua request (sebelum validasi), sehingga keberadaannya tidak terlihat.
4. Atur Payment Notification URL ke `https://<domain>/api/midtrans/notification`, lalu `MIDTRANS_WEBHOOK_ENABLED=true` dan `php artisan config:cache`.
5. Kirim notifikasi uji dari Dashboard dan pastikan responsnya 200 atau 404 (order uji tidak ada di database), bukan 403 atau 503.

Notifikasi tidak dipercaya sebagai status: aplikasi selalu memanggil Get Status. Replay notifikasi tidak berdampak karena semua transisi idempotent.

## 8. Backup SQLite

Gunakan API backup SQLite. **Jangan** menyalin file dengan `cp` saat aplikasi berjalan: pada mode WAL, data yang belum di-checkpoint berada di `database.sqlite-wal`, dan salinan mentah file utama kehilangan data tersebut (terbukti pada simulasi: salinan `cp` berisi 1 dari 2 baris, `.backup` berisi 2 dari 2).

```sh
set -euo pipefail
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
DEST=/var/backups/dapur-rantau/database-$STAMP.sqlite
install -d -m 700 /var/backups/dapur-rantau
sqlite3 /var/lib/dapur-rantau/database.sqlite ".backup '$DEST'"
sqlite3 "$DEST" 'pragma integrity_check;'          # harus: ok
sqlite3 "$DEST" 'pragma foreign_key_check;'        # harus: kosong
sqlite3 "$DEST" 'select count(*) from migrations;'
sha256sum "$DEST" > "$DEST.sha256"
chmod 600 "$DEST" "$DEST.sha256"
```

`.backup` aman dijalankan saat aplikasi berjalan dan menyertakan isi WAL. File backup bersifat mandiri (tanpa `-wal`/`-shm`). Simpan juga salinan di luar server dan uji restore secara berkala pada direktori sementara.

Pemeriksaan backup sebelum dipakai:

```sh
sha256sum -c "$DEST.sha256"
sqlite3 "$DEST" 'pragma integrity_check;'
sqlite3 "$DEST" "select count(*) from payments; select count(*) from payment_adjustments;"
```

## 9. Restore SQLite

1. Hentikan akses tulis: `php artisan down`, hentikan cron scheduler dan PHP-FPM/worker yang memakai database.
2. Pindahkan database saat ini beserta `-wal`/`-shm` ke folder insiden (jangan dihapus):
   ```sh
   install -d -m 700 /var/lib/dapur-rantau/incident-$STAMP
   mv /var/lib/dapur-rantau/database.sqlite* /var/lib/dapur-rantau/incident-$STAMP/
   ```
3. Verifikasi backup (bagian 8), lalu salin: `install -m 600 -o <user-aplikasi> "$DEST" /var/lib/dapur-rantau/database.sqlite`.
4. `sqlite3 /var/lib/dapur-rantau/database.sqlite 'pragma integrity_check;'` dan `php artisan migrate:status`.
5. `php artisan config:cache`, nyalakan kembali PHP-FPM dan scheduler, lalu `php artisan up`.
6. **Rekonsiliasi pembayaran setelah restore:** refund atau pembayaran yang terjadi setelah waktu backup tidak ada di database hasil restore, tetapi tetap ada di Midtrans. Jalankan `php artisan payments:reconcile` dan bandingkan Dashboard Midtrans untuk periode sejak backup. Refund yang dikirim setelah backup tidak dapat dicatat ulang lewat `payments:gateway-refund` (refund_key-nya akan terlihat sebagai refund Midtrans di `refunds[]` dan dihitung dalam batas sisa); catat sebagai temuan insiden.

## 10. Migration dan pemulihan migration gagal

Urutan pada instalasi yang sudah berjalan: backup (bagian 8) → `php artisan down` → `php artisan migrate --force` → `php artisan migrate:status` → `config:cache`/`route:cache`/`view:cache` → `php artisan up`.

Migration SQLite yang gagal **tidak** dikembalikan otomatis. Pada simulasi, migration yang membuat tabel lalu gagal meninggalkan tabel parsial tanpa baris di tabel `migrations`, sehingga `migrate` berikutnya akan gagal di tempat yang sama. Pemulihan yang benar adalah restore backup pra-migration (bagian 9), bukan `migrate:rollback` atau menghapus tabel manual. Setelah restore, perbaiki penyebab di kode dan ulangi dari langkah backup.

**Perlindungan histori saat rollback (Tahap 14):** `down()` milik migration berikut **menolak** (exception "Rollback dibatalkan…", tanpa menghapus apa pun) bila tabel yang dilindunginya berisi data:

| Migration | Ditolak bila berisi data |
| --- | --- |
| `2026_11_27_000001` | `payment_refund_anomalies`, `payment_gateway_refund_events` |
| `2026_11_26_000001` | `payment_gateway_refund_closures`, atau `payment_gateway_refunds` (agar trigger immutabilitas tidak terlepas dari histori yang ada) |
| `2026_11_25_000001` | `payment_gateway_refunds` |
| `2026_11_22_000001` | `payment_adjustments`, `subscription_periods` |
| `2026_11_21_000001` | `payments` |

Tidak ada cascade delete atau truncate; foreign key dan urutan dependensi tetap. Pemeriksaan dilakukan sebagai langkah pertama `down()`, sebelum perubahan skema apa pun, sehingga migration yang ditolak tetap tercatat di tabel `migrations` (tidak dianggap sudah di-rollback). Bila semua tabel kosong, rollback berjalan sesuai urutan dependensi (`27` → `26` → `25` → `22` → `21`). Karena SQLite tidak membungkus migration dalam satu transaksi, rollback beberapa langkah yang ditolak di tengah tetap meninggalkan migration yang sudah dibatalkan sebelumnya dalam keadaan rollback (hanya yang tabelnya kosong); selalu backup dulu (bagian 8). Tabel `subscriptions` (status Premium saat ini, dapat dibangun ulang dari `subscription_periods`) tidak termasuk daftar dan tetap terlindungi secara tidak langsung karena `2026_11_21` ditolak selama `payments` berisi data.

**Archival dan pemulihan:** histori keuangan tidak pernah dihapus lewat aplikasi. Untuk mengembalikan skema, (1) backup dengan `sqlite3 .backup` beserta checksum, (2) simpan backup itu sebagai arsip permanen di luar server, (3) pulihkan dengan restore backup pra-migration (bagian 9), bukan `migrate:rollback`. Untuk membaca histori lama, buka salinan arsip secara read-only: `sqlite3 'file:/arsip/database-<waktu>.sqlite?mode=ro'`.

Urutan migration pembayaran: `2026_11_21_000001` (premium) → `2026_11_22_000001` (reversal) → `2026_11_25_000001` (refund gateway) → `2026_11_26_000001` (audit penutupan dan trigger) → `2026_11_27_000001` (riwayat status refund dan anomali). `2026_11_26`, `2026_11_27`, dan migration Oktober `000006`/`000007` sengaja menolak driver selain SQLite. `down()` seluruh migration pembayaran menolak bila histori berisi data (tabel di atas); jangan mengandalkan rollback untuk Production, gunakan restore backup.

## 11. Emergency rollback

Pilih tingkat paling ringan yang cukup.

1. **Matikan fitur pembayaran tanpa deploy ulang:** set `MIDTRANS_WEBHOOK_ENABLED=false` dan/atau kosongkan `MIDTRANS_ENVIRONMENT`, lalu `php artisan config:cache`. Checkout, webhook, rekonsiliasi, dan refund gateway gagal aman (503/404); data tidak berubah. Refund yang sedang `unknown` tetap tercatat dan dapat dilanjutkan setelah fitur dinyalakan kembali.
2. **Rollback kode:** deploy commit sebelumnya dan jalankan `config:cache`. Tabel baru yang tidak dipakai kode lama aman dibiarkan; jangan jalankan `migrate:rollback`. Catatan: kode sebelum Tahap 9 hanya mengenal Sandbox di environment local/testing, sehingga pembayaran Production otomatis nonaktif.
3. **Restore database:** hanya bila data rusak atau migration gagal (bagian 9 dan 10), diikuti rekonsiliasi dengan Dashboard Midtrans.

Setelah rollback apa pun, jalankan `php artisan payments:gateway-refunds` dan selesaikan refund terbuka melalui bagian 4.

## 12. Database Production dan kompatibilitas

Kode yang ada mendukung **SQLite saja**. Keputusan disetujui pada Tahap 14: SQLite satu server pada persistent local storage, tanpa network filesystem; alasan, risiko, dan syarat migrasi ke PostgreSQL ada di [adr-database-production.md](adr-database-production.md). Status kompatibilitas:

| Database | Status terverifikasi | Konsekuensi |
| --- | --- | --- |
| SQLite | Rantai migration penuh, rollback, WAL, `.backup`/restore, trigger audit, CAS dan unique `active_slot` diuji pada database sementara | Satu host; write lock seluruh database; `busy_timeout` menentukan antrean tulis |
| MySQL | **Belum diverifikasi.** Migration `2026_10_14_000006/000007`, `2026_11_26_000001`, dan `2026_11_27_000001` melempar exception | Perlu porting trigger dan migration SQLite-only, lalu pengujian locking (`UPDATE users` + `lockForUpdate`), unique nullable `active_slot`, dan CAS |
| PostgreSQL | **Belum diverifikasi.** Migration `2026_10_14_000001` s.d. `000007`, `2026_11_26_000001`, dan `2026_11_27_000001` melempar exception | Porting seluruh migration fondasi dan trigger |

## 13. Checklist sebelum Production

Sudah tersedia di kode (terverifikasi dengan test/simulasi lokal):
- [x] Environment Midtrans eksplisit, credential terpisah, host tetap, prefix kunci diperiksa, gagal aman bila kosong.
- [x] Intent refund durable sebelum POST, tanpa retry otomatis, reattempt dengan `refund_key` sama dalam 144 jam, jeda 10 menit.
- [x] Rekonsiliasi hanya Get Status, terjadwal 15 menit, CAS tanpa regresi status.
- [x] Satu ledger refund (gateway + manual + cadangan `unknown`/`closed_unprocessed`), deteksi dan blokir `refund_excess`, refund tertutup yang muncul kembali tercatat.
- [x] Penutupan `unknown` **dinonaktifkan di Production** (alur dua akun OS hanya untuk pengembangan).
- [x] Webhook nonaktif default, 404 sebelum validasi, SHA-512 + `hash_equals`, limiter khusus.
- [x] Trusted proxy eksplisit tanpa wildcard.
- [x] Prosedur backup `.backup`, restore, dan pemulihan migration gagal diuji pada database sementara.
- [x] `payments:refund` merekonsiliasi refund Dashboard lewat Get Status sebelum mencatat (fail-closed).
- [x] Rollback migration menolak menghapus histori pembayaran, periode langganan, refund, dan adjustment yang berisi data.
- [x] `laravel/tinker` hanya dependensi development (`composer install --no-dev` tidak memasangnya).
- [x] Status email alert jujur (`sent`/`logged_only`/`failed`) dan `payments:alert-status`.

Belum diverifikasi / wajib dikerjakan saat persiapan:
- [ ] Server Production: path `/var/lib/dapur-rantau/`, izin direktori hanya untuk akun aplikasi.
- [ ] Nilai `TRUSTED_PROXIES`/`TRUSTED_PROXY_HEADERS` dari pemeriksaan infrastruktur nyata; HTTPS dan `APP_URL`.
- [ ] Credential Production, permission Write Refund API, aktivasi refund merchant.
- [ ] Rumus signature webhook Production dibuktikan dengan notifikasi uji Dashboard (dokumentasi Midtrans bertentangan, bagian 7).
- [ ] `PAYMENT_ALERT_EMAIL` dan mailer Production, atau channel log alert lain; alert eksternal belum aktif.
- [ ] Keputusan kebijakan peninjauan anomali `refund_excess` dan rancangan pemisahan kewenangan (bagian 4D).
- [ ] Rotasi Signature Secret Key (N5): kode baru menerima satu kunci; notifikasi ulang dengan kunci lama ditolak 403 selama rotasi.
- [ ] Volume persisten untuk `/var/lib/dapur-rantau` (EBS bila di EC2), pemantauan disk dan ukuran `-wal`.
- [ ] Deploy Production memakai `composer install --no-dev --optimize-autoloader`; `php artisan tinker` tidak tersedia di Production.
- [ ] Cron `schedule:run`, `LOG_LEVEL=info`, pemantauan log bagian 3, backup terjadwal di luar server, uji restore.
- [ ] Webhook asli dan perilaku refund Production (`refund_method`, `bank_confirmed_at`).
- [ ] Remediasi `composer audit` (Tahap 15, audit 2026-11-25: 9 advisory pada 6 package runtime). Naikkan secara terarah dengan `composer update <package> --with-dependencies` lalu jalankan test pembayaran/auth: `symfony/http-foundation`, `symfony/http-kernel`, `symfony/routing` 7.4.20 → ≥7.4.23 (host/proxy/path bypass; relevan bila `TRUSTED_PROXIES` diisi, `private_ranges` sudah tidak diterima `config/trustedproxy.php`), `symfony/mime` 7.4.19 → ≥7.4.22 (header injection email, medium), `laravel/framework` 13.35.0 → ≥13.40.0 (low; `Concurrency` tidak dipakai), `phpseclib/phpseclib` 4.0.2 → ≥4.0.3 (DoS parsing kunci, via `laravel/socialite`). Semua masih dalam constraint `composer.json` saat ini.

Dilarang sebelum persetujuan deployment: mengisi credential Production, mengaktifkan webhook Production, menjalankan migration atau restore pada database Production, mengirim transaksi atau refund Production.
