# Langganan Premium di development

Halaman paket ada di `/paket`. Akun email terverifikasi dan akun Google dapat membuka `/langganan` untuk melihat kuota, masa aktif dalam WIB, serta pembayaran milik sendiri. Pembayaran satu kali dapat diulang secara manual untuk memperpanjang paket.

| Pengguna | Harga | Masa aktif | Pencarian berhasil | Koleksi |
| --- | ---: | --- | --- | --- |
| Guest | Rp0 | Aturan guest lama | 3 per identitas guest | — |
| Free | Rp0 | Selama akun aktif | 1 per hari WIB | — |
| Premium Mingguan | Rp5.000 | 7 hari | 30 per hari WIB | Favorit dan riwayat |
| Premium Bulanan | Rp15.000 | 1 bulan kalender | 30 per hari WIB | Favorit dan riwayat |

## Menyiapkan SQLite dan Sandbox

1. Gunakan checkout lokal branch `feature/premium-sandbox`, PHP ≥8.3, dan SQLite development. Pastikan `APP_ENV=local`, `DB_CONNECTION=sqlite`, dan `DB_DATABASE` menunjuk ke file proyek lokal. Periksa juga override `DB_URL`. Simpan salinan cadangan konsisten dengan fasilitas backup SQLite sebelum migration.
2. Pada instalasi existing, periksa `php artisan migrate:status`. Setelah migration fondasi aplikasi terpasang, jalankan perintah di bawah. Migration Premium menambahkan empat tabel dan satu kolom nullable pada operasi rekomendasi serta langsung mengisi dua paket. Pada instalasi development baru, jalankan seluruh migration dengan `php artisan migrate`.
3. Pilih environment **Sandbox** di dashboard merchant Midtrans. Dari **Settings → Access Keys**, tetapkan `MIDTRANS_ENVIRONMENT=sandbox` (wajib eksplisit; `MIDTRANS_IS_PRODUCTION` tidak dipakai lagi), salin Server Key ke `MIDTRANS_SERVER_KEY`, Client Key ke `MIDTRANS_CLIENT_KEY` dan Signature Secret Key ke `MIDTRANS_SIGNATURE_SECRET_KEY` dalam `.env` lokal. Gunakan kunci dari merchant Sandbox yang sama, dengan izin Snap Checkout Write dan Core API Read untuk Server Key. [Access Keys Midtrans](https://docs.midtrans.com/docs/access-keys). Server memakai Basic Auth dan host tetap per environment (lihat bagian Production di bawah). Browser hanya memerlukan Client Key untuk Snap. Pertahankan APP_KEY existing. Jalankan `php artisan config:clear` jika konfigurasi di-cache.
4. Bangun aset dengan Node versi proyek dan `npm run build`, lalu mulai Laravel lokal. Buka `/paket`, masuk, pilih paket, dan periksa modal Snap. Gunakan metode pembayaran simulasi Sandbox.
5. Untuk notifikasi, sediakan endpoint HTTPS publik dengan sertifikat valid yang meneruskan **hanya** POST `/api/midtrans/notification` ke aplikasi development. Atur **Payment Notification URL** pada pengaturan notifikasi Sandbox menjadi URL tersebut. Browser pembeli dapat tetap memakai localhost. Endpoint stateless selalu terdaftar tetapi mengembalikan 404 sampai `MIDTRANS_WEBHOOK_ENABLED=true`. Form pembelian memakai sesi login dan CSRF biasa. Hindari mengekspos halaman debug development melalui tunnel publik.

```sh
php artisan migrate --path=database/migrations/2026_11_21_000001_create_premium_tables.php
php artisan route:list --path=midtrans
```

Snap membuat order UUID dengan harga dari database. Callback browser kembali ke halaman langganan. Webhook mencocokkan signature SHA512 atas order, kode, nominal asli, dan Signature Secret Key, lalu memeriksa order dan nominal lagi melalui Get Transaction Status Sandbox. Transisi lunas dan penambahan masa aktif dilakukan dalam satu transaksi. Status sukses memerlukan settlement atau capture dengan kode 200, serta fraud accept bila tersedia (wajib pada capture). Jika gateway sementara gagal diverifikasi, endpoint mengembalikan 503 agar notifikasi dapat dikirim ulang. Pilih **Perbarui status** setelah notifikasi sampai. [Notifikasi Midtrans](https://docs.midtrans.com/docs/https-notification-webhooks) · [Get Transaction Status](https://docs.midtrans.com/reference/get-transaction-status) · [Snap JS](https://docs.midtrans.com/reference/snap-js)

## Rekonsiliasi dan status pembayaran

Selain webhook dan tombol **Perbarui status**, pembayaran dapat diperiksa ulang ke Midtrans Sandbox lewat perintah berikut. Ketiganya memakai klaim pembayaran yang sama, sehingga satu pembayaran hanya mengaktifkan Premium satu kali.

```sh
php artisan payments:reconcile
php artisan payments:reconcile --order=<uuid> --limit=20
php artisan schedule:work
```

Perintah terjadwal setiap lima menit, tetapi baru berjalan jika `schedule:work` atau cron `schedule:run` dinyalakan. Perintah memakai database pada environment tempat ia dijalankan. Yang diperiksa hanya pembayaran `pending` dan `review` yang berumur minimal 10 menit, paling banyak 20 per putaran, dengan jeda 10 menit (umur di bawah 2 jam) atau 60 menit per pembayaran. Pembayaran `pending` berumur lebih dari 7 hari hanya diperiksa lewat `--order`. Putaran berhenti jika Midtrans gagal tiga kali berturut-turut atau database sedang sibuk.

Hanya satu rekonsiliasi yang berjalan pada satu waktu per database, baik dari scheduler maupun manual: perintah mengambil kunci `payments:reconcile` di cache (berlaku lintas proses, kedaluwarsa sendiri setelah 120 detik jika proses mati). Perintah yang datang saat kunci masih dipegang berhenti dengan `skipped=1` tanpa menghubungi Midtrans. Angka pada keluaran hanya menghitung perubahan yang dilakukan proses itu sendiri:

| Keluaran | Arti |
| --- | --- |
| `paid`, `expired`, `failed`, `review` | Pembayaran yang statusnya diubah oleh proses ini. |
| `unchanged` | Diperiksa, tetapi statusnya tidak berubah atau sudah diubah proses lain (misalnya webhook). |
| `unrecorded` | Midtrans belum mencatat transaksinya dan umurnya belum 48 jam. |
| `unverified`, `error` | Tidak dapat diverifikasi ke Midtrans, atau gagal diproses (lihat log). |
| `skipped`, `busy`, `inactive` | Tidak berjalan: rekonsiliasi lain sedang berjalan, database sibuk, atau Sandbox tidak aktif. |

| Status | Arti |
| --- | --- |
| `pending` | Menunggu pembayaran. Jika Midtrans tidak pernah mencatat transaksinya, status menjadi `expired` setelah 48 jam. |
| `paid` | Lunas dan Premium sudah ditambahkan. Hanya refund, chargeback atau pembatalan dari Midtrans yang dapat mengubahnya (lihat bagian berikut). |
| `failed`, `expired` | Gagal atau kedaluwarsa. Settlement yang datang kemudian tetap dapat mengubahnya menjadi `paid`. |
| `refunded` | Dana dikembalikan penuh. Sisa Premium dari pembayaran ini dicabut. Status ini final. |
| `chargeback` | Bank menarik kembali seluruh dana. Premium dari pembayaran ini ditangguhkan sampai peninjauan ditutup. |
| `review` | Midtrans menyatakan lunas, tetapi kode paket tidak dikenali. Premium belum ditambahkan dan kejadian dicatat di log sebagai `payment.review`. Setelah paket pada pembayaran diperbaiki, rekonsiliasi mengaktifkannya satu kali. |

Saat database sibuk, webhook menjawab 503 berformat JSON dengan `Retry-After`, sedangkan sinkronisasi manual menampilkan pesan tanpa mengubah status. Jika kunci lebih dulu mengenai session atau pembatas laju, halaman pembayaran menampilkan halaman 503 bermerek dengan tautan kembali ke Langganan saya; halaman itu dirender tanpa query database. Kegagalan saat memproses pembayaran (webhook, sinkronisasi, rekonsiliasi) dicatat sebagai `payment.processing_failed` berisi ID pembayaran, jenis operasi, kategori, jenis exception, dan lokasi kode, tanpa SQL, nilai parameter, path, atau stack trace. Jika Snap gagal membuat transaksi, payment ditandai `failed` dan browser menerima halaman 503 bermerek (`errors/503.blade.php`) dengan tautan ke Pilihan paket dan Langganan saya; permintaan JSON tetap menerima pesan singkat.

## Refund, chargeback, dan peninjauan

Setiap pembayaran lunas mencatat periode yang dibiayainya di `subscription_periods`; `subscriptions` tetap menjadi ringkasan masa aktif per akun. Refund, chargeback dan pembatalan dicatat di `payment_adjustments`, satu baris per referensi sumber, sehingga notifikasi atau perintah yang diulang tidak mengubah data dua kali. Notifikasi Midtrans tetap melewati pemeriksaan signature, order, nominal dan mata uang, lalu statusnya dicek ulang ke Midtrans sebelum diterapkan.

| Kejadian | Pembayaran | Premium dari pembayaran itu | Peninjauan |
| --- | --- | --- | --- |
| Refund penuh (`refund`) | `refunded` | Sisa waktu dicabut segera | Ditutup (`resolved`) |
| Refund sebagian (`partial_refund`) | tetap `paid` | Tidak diubah | Terbuka (`pending_review`), nominal dicatat |
| Chargeback penuh (`chargeback`) | `chargeback` | Ditangguhkan | Terbuka |
| Chargeback sebagian (`partial_chargeback`) | tetap `paid` | Ditangguhkan | Terbuka |
| Lunas lalu `deny`, `cancel`, `expire` atau `failure` | tetap `paid` | Ditangguhkan | Terbuka |

Hanya waktu yang belum terpakai yang dicabut atau ditangguhkan. Hari yang sudah dipakai tidak ditarik kembali, dan periode dari pembayaran lain dipertahankan utuh: periode yang antre di belakangnya maju sebesar waktu yang dicabut. Waktu yang ditangguhkan disimpan dalam `held_seconds` dan tidak berkurang selama peninjauan. Pembayaran dengan peninjauan terbuka tampil sebagai "Sedang ditinjau". Pembayaran lunas dari sebelum fitur ini yang periodenya tidak dapat direkonstruksi tidak dikurangi otomatis; refundnya dicatat sebagai peninjauan terbuka.

Peninjauan ditutup operator dengan satu keputusan per pembayaran. Tanpa `--confirm` perintah hanya menampilkan rencana.

```sh
php artisan payments:review <id-pembayaran> --restore --confirm
php artisan payments:review <id-pembayaran> --revoke --confirm
```

`--restore` menutup peninjauan sebagai `rejected`: periode yang ditangguhkan dipulihkan dengan menambahkan `held_seconds` ke akhir masa aktif, satu kali, dan pembayaran `chargeback` kembali `paid`. `--revoke` menutupnya sebagai `resolved`: sisa periode dicabut, dan pembayaran yang dibatalkan Midtrans menjadi `failed`. Refund penuh tidak dapat dipulihkan.

Keduanya ditolak tanpa mengubah apa pun jika pembayaran tidak memiliki periode Premium yang tercatat; peninjauan tetap terbuka. Periode yang sudah dicabut tidak dapat dipulihkan dengan `--restore`, kecuali ada settlement baru yang sedang ditinjau.

Jika Midtrans menyatakan settlement lagi untuk pembayaran yang periodenya sudah dicabut atau yang pernah memiliki penyesuaian, pembayaran tidak kembali `paid` dan Premium tidak aktif otomatis. Settlement itu dicatat sebagai peninjauan terbuka: `--restore` mengaktifkan kembali periode yang sama dan pembayaran menjadi `paid`; `--revoke` menutupnya tanpa perubahan.

Midtrans tidak dapat me-refund Virtual Account. Setelah dana ditransfer manual ke pelanggan, catat dengan perintah berikut. Perintah ini tidak mengirim uang. Sejak Tahap 14, untuk pembayaran `paid` perintah ini **lebih dulu memanggil Get Transaction Status** (read-only, tidak pernah POST Refund API) agar refund yang dibuat langsung di Dashboard Midtrans tercatat sebelum sisa refund dihitung.

```sh
php artisan payments:refund <id-pembayaran> --amount=5000 --reason="Permintaan pengguna" --reference=<referensi-transfer> --confirm
```

Tanpa `--confirm` tidak ada yang dicatat, tetapi Get Status tetap dipanggil. Referensi yang sama tidak dicatat dua kali, dan total refund tidak boleh melebihi nominal pembayaran. Total itu mencakup refund manual sebelumnya, setiap entri `refunds[]` individual dari Get Status (per `refund_key`; dua refund dengan nominal sama tetapi `refund_key` berbeda adalah dua refund), refund Midtrans yang sudah tercatat, dan cadangan refund gateway yang belum pasti. `refund_amount` tingkat transaksi hanya dipakai untuk memeriksa konsistensi. Perintah menolak kelebihan dan menyebut sisa yang masih dapat direfund. Jika totalnya sama dengan nominal pembayaran, aturan refund penuh berlaku; jika kurang, refund masuk peninjauan tanpa mengubah Premium. Refund atau chargeback yang notifikasinya tidak sampai tidak terdeteksi oleh rekonsiliasi, karena rekonsiliasi hanya memeriksa pembayaran `pending` dan `review`.

## Perhitungan kuota dan masa aktif

Tanggal kuota mengikuti waktu reservasi di Asia/Jakarta. Transaksi mengambil kunci tulis akun, lalu menghitung operasi yang sedang direservasi dan pencarian berhasil hari itu. Proses Gemini berjalan setelah transaksi reservasi selesai. Penyimpanan hasil, penambahan kuota dan riwayat Premium terjadi bersama. Kegagalan melepas reservasi. Jika worker mati, reservasi dapat diambil kembali setelah lease 10 menit berakhir. Membuka detail dan mengirim ulang kunci operasi sukses yang sama tidak memakai kuota lagi.

Upgrade di tengah hari menggunakan baris kuota yang sama. Jika Free sudah mencari sekali, tersisa 29 pencarian setelah upgrade. Langganan aktif diperpanjang dari akhir periode tersimpan. Sesudah kedaluwarsa, periode baru dimulai saat pembayaran diaktifkan. Penambahan bulan mengikuti tanggal WIB dengan penyesuaian ke hari terakhir bulan tujuan. Seluruh batas waktu langganan disimpan dalam UTC. Kedaluwarsa langsung membatasi koleksi tanpa menghapus isinya.

## Pengujian

```sh
vendor/bin/phpunit tests/Feature/PremiumSubscriptionTest.php
```

Test memakai SQLite `:memory:`, data resep simulasi, HTTP fake, dan pemblokiran request liar. Simulasi dua reservasi yang tumpang tindih menguji slot terakhir. Pengujian serentak dengan dua worker sungguhan tercantum di checklist. Untuk pemeriksaan browser pencarian, gunakan cache valid dengan input identik pada instalasi uji terisolasi dan kosongkan kunci Gemini pada instalasi tersebut agar cache miss tidak mengirim permintaan. Gunakan pembatas 5 POST per menit/IP yang sudah berlaku. Pemeriksaan OAuth eksternal dan pembayaran Sandbox end-to-end memerlukan konfigurasi serta izin pengujian tersendiri.

| Skenario manual | Langkah dan hasil yang diharapkan |
| --- | --- |
| Free, pencarian 1 dan 2 | Login Free. Jalankan dua pencarian valid dari form baru. Pertama menghasilkan tiga kartu, kedua ditolak 429. Buka ulang hasil pertama, hitungan tetap satu. |
| Premium, pencarian 30 dan 31 | Pada akun uji Premium dengan 29 pencarian berhasil hari ini, kirim dua pencarian dengan kunci berbeda. Ke-30 berhasil, ke-31 mendapat 429. Periksa angka di Langganan saya. |
| Pergantian tanggal WIB | Pakai satu slot terakhir sebelum 00.00 WIB, ulangi sesudah 00.00 WIB. Hari baru punya kuota penuh. Test otomatis juga memajukan jam dari 23.59 ke 00.00 WIB. |
| Gemini gagal | Pada instalasi uji dengan kunci Gemini kosong, pilih input yang belum di-cache. Setelah pesan kegagalan, hitungan tetap. Pencarian berikut dengan input cache valid dapat memakai slot itu. |
| Request simultan | Dengan satu slot tersisa, gunakan dua sesi akun yang sama, dua worker lokal, dan dua kunci operasi. Kirim bersamaan. Periksa hanya satu hasil baru dan satu kenaikan hitungan. Dua IP uji berbeda memungkinkan pemeriksaan batas akun di balik limiter per-IP. |
| Pending, sukses, gagal, expired | Buat order Sandbox terpisah untuk tiap status. Pending menampilkan status menunggu dan tombol lanjutkan. Selesaikan satu transaksi, simulasikan penolakan dan expiry pada order terpisah. Hanya transaksi sukses memberi Premium. |
| Webhook berulang | Kirim ulang notifikasi sukses dari dashboard Sandbox untuk order yang sama. Periksa masa berakhir persis sama. Kirim ulang notifikasi lama setelah sukses. Status tetap berhasil. |
| Signature dan nominal | Jalankan test kritis untuk signature salah, nominal yang berbeda dari order, dan status fraud challenge. Pastikan akses tidak bertambah. |
| Perpanjangan aktif | Catat akhir periode, beli paket lagi. Mingguan menambah 7 hari dari akhir sebelumnya. Bulanan menambah satu bulan kalender. Periksa tanggal 31 menuju bulan yang lebih pendek. |
| Perpanjangan setelah expired | Gunakan akun uji dengan masa aktif sudah lewat. Pembelian baru memulai periode dari aktivasi baru. Periksa koleksi lama muncul kembali. |
| Proteksi koleksi | Sebagai Free, buka favorit, detail, riwayat, dan ulang riwayat. Kirim POST favorit serta DELETE koleksi dengan sesi dan CSRF valid. HTML dan JSON mendapat 403. Guest mendapat 401 untuk koleksi dan diminta masuk saat membeli. |
| Isolasi pembayaran | Masuk dengan akun kedua. Buka URL lanjut bayar dari akun pertama. Modal dan pembayaran akun pertama tidak tampil. |
| Regresi login dan hasil | Masuk dengan email terverifikasi. Di instalasi OAuth development yang telah dikonfigurasi, ulangi login Google dan logout. Pastikan guest masih memiliki aturan tiga pencarian, hasil normal tepat tiga resep, dan detail dapat dibuka tanpa Gemini. |

## Rollback development

Rollback hanya untuk **salinan SQLite development tanpa histori pembayaran**. Sejak Tahap 14, `down()` setiap migration pembayaran menolak ("Rollback dibatalkan…") bila `payments`, `subscription_periods`, `payment_adjustments`, tabel refund gateway, event, atau anomali berisi data; tidak ada cascade delete atau truncate, dan migration yang ditolak tetap tercatat di tabel `migrations`. Untuk menghapus fitur dari database yang sudah memiliki data billing, gunakan restore backup konsisten bersama versi aplikasi yang sesuai ([operasional-pembayaran.md](operasional-pembayaran.md) bagian 9–10). Urutan rollback (tabel kosong) mengikuti dependensi; akun, favorit, riwayat dan operasi lama dipertahankan.

```sh
php artisan migrate:rollback --step=1 --path=database/migrations/2026_11_27_000001_create_payment_refund_events_and_anomalies.php
php artisan migrate:rollback --step=1 --path=database/migrations/2026_11_26_000001_create_payment_gateway_refund_closures_table.php
php artisan migrate:rollback --step=1 --path=database/migrations/2026_11_25_000001_create_payment_gateway_refunds_table.php
php artisan migrate:rollback --step=1 --path=database/migrations/2026_11_22_000001_create_payment_reversal_tables.php
php artisan migrate:rollback --step=1 --path=database/migrations/2026_11_21_000001_create_premium_tables.php
```

## Daftar file implementasi pada branch fitur

- `.env.example` (diubah)
- `README.md` (diubah)
- `app/Http/Controllers/RecommendationController.php` (diubah)
- `app/Http/Controllers/SubscriptionController.php` (baru)
- `app/Http/Middleware/RequireCollectionAccount.php` (diubah)
- `app/Http/Requests/CheckoutRequest.php` (baru)
- `app/Http/Requests/MidtransNotificationRequest.php` (baru)
- `app/Http/Requests/PaymentStatusRequest.php` (baru)
- `app/Services/MidtransSandboxService.php` (baru)
- `app/Services/PaymentAdjustmentService.php` (baru)
- `app/Services/QuotaService.php` (baru)
- `app/Services/RecommendationOperationService.php` (diubah)
- `app/Services/SubscriptionService.php` (baru)
- `app/Support/SqliteContention.php` (diubah)
- `bootstrap/app.php` (diubah)
- `config/midtrans.php` (baru)
- `database/migrations/2026_11_21_000001_create_premium_tables.php` (baru)
- `database/migrations/2026_11_22_000001_create_payment_reversal_tables.php` (baru)
- `docs/premium-sandbox.md` (baru)
- `resources/js/app.js` (diubah)
- `resources/views/account/required.blade.php` (diubah)
- `resources/views/auth/login.blade.php` (diubah)
- `resources/views/components/account-navigation.blade.php` (diubah)
- `resources/views/components/favorite-button.blade.php` (diubah)
- `resources/views/components/visitor-notice.blade.php` (diubah)
- `resources/views/recommendations/operation-error.blade.php` (diubah)
- `resources/views/errors/503.blade.php` (baru)
- `resources/views/errors/payment-busy.blade.php` (baru)
- `resources/views/subscription/pricing.blade.php` (baru)
- `resources/views/subscription/required.blade.php` (baru)
- `resources/views/subscription/show.blade.php` (baru)
- `routes/console.php` (diubah)
- `routes/web.php` (diubah)
- `tests/Feature/PaymentReversalTest.php` (baru)
- `tests/Feature/PremiumSubscriptionTest.php` (baru)

Migration juga memperbarui skema SQLite development lokal di `database/database.sqlite`. Vite menghasilkan aset lokal di `public/build/`. Keduanya berada di luar Git.


## A3 — refund sebagian Midtrans

Detail status gateway memakai `refunds[].refund_key` sebagai identitas dan `refunds[].refund_amount` sebagai nominal individual. Referensi tersimpan memakai hash SHA-256 dari key lengkap. Pengulangan dan pembaruan metadata bank memakai baris yang sama. Konflik nominal untuk key sama ditolak 422 dan seluruh batch dibatalkan.

Jika detail tidak disertakan atau berupa daftar kosong, `refund_amount` tingkat transaksi disimpan pada satu referensi `partial_refund:cumulative`. Nilai hanya naik. Snapshot yang tidak melebihi total Midtrans yang sudah tercatat, termasuk dari detail `refund_key`, diabaikan tanpa membuat baris, peninjauan, atau log baru, juga setelah peninjauan ditutup. Detail lengkap yang berbeda totalnya dari snapshot, pecahan rupiah, nilai negatif, dan nominal yang tidak dapat ditafsirkan ditolak. Tanpa snapshot, hanya replay key yang sudah tersimpan dengan nominal identik yang diterima. Key baru atau konflik ditolak. Detail malformed ditolak meskipun snapshot valid tersedia.

Batas refund manual A5 memakai nilai terbesar antara jumlah detail individual dan maksimum snapshot kumulatif (termasuk referensi legacy `partial_refund:{amount}`), kemudian menambahkan refund manual. Snapshot dan detail tidak dijumlahkan bersama. Snapshot fallback bergantung pada total kumulatif gateway yang valid. Payload ambigu memerlukan pemeriksaan operator dan tidak dikoreksi otomatis.

Total refund lintas sumber adalah jumlah refund manual ditambah total Midtrans di atas, dan dipakai oleh perintah manual maupun webhook. Refund manual yang melebihi sisa ditolak. Webhook Midtrans yang valid tetap dicatat dengan nominal sebenarnya karena dana sudah dikembalikan gateway. Jika total lintas sumber mencapai nominal pembayaran, aturan refund penuh berlaku satu kali: pembayaran menjadi `refunded`, sisa Premium dicabut, dan peninjauan lain ditutup. Jika total melebihi nominal, baris Midtrans yang memicunya tetap `pending_review` dengan alasan diawali "Kelebihan refund Rp…" dan log `payment.refund_excess`; tutup dengan `payments:review <id> --revoke --confirm` setelah diperiksa. Referensi manual yang sama dengan `refund_key` Midtrans pada pembayaran yang sama ditolak. Jika `refund_key` Midtrans sama dengan referensi manual yang sudah ada, refund tetap dicatat dan dihitung, lalu ditandai "Referensi sama dengan refund manual" serta log `payment.refund_reference_conflict`. Selama baris Midtrans tersebut masih `pending_review`, konflik dianggap terbuka: refund penuh otomatis ditunda (alasan "Refund penuh ditunda…"), periode Premium tidak diubah, dan refund manual baru ditolak. Operator menyelesaikannya dengan `payments:review <id> --restore|--revoke --confirm`; keputusan `--revoke` mencabut sisa Premium tetapi status pembayaran tetap `paid`.

Webhook `partial_refund` untuk pembayaran yang sudah `refunded` tetap memeriksa status Midtrans. Refund baru dicatat satu kali sebagai `pending_review` dengan alasan "Refund setelah refund penuh terdeteksi…" dan log `payment.refund_after_full_refund`. Status `refunded`, `subscriptions`, dan `subscription_periods` tidak diubah. Nominalnya ikut dalam total refund; snapshot kumulatif tanpa detail hanya dicatat jika melebihi total Midtrans yang sudah tersimpan. Notifikasi lain untuk pembayaran `refunded` tetap diabaikan.

## R2 — settlement berikutnya setelah peninjauan ditutup

Peninjauan settlement memakai referensi `settlement:` diikuti hash SHA-256 dari `transaction_id` pada status Midtrans yang diambil ulang dengan server key. Midtrans memberi `transaction_id` baru ketika `order_id` yang expired atau gagal dibayar kembali, dan status `deny`, `cancel`, serta `expire` tidak berubah lagi. Karena itu, settlement dengan `transaction_id` berbeda membuka satu peninjauan baru, sedangkan replay settlement yang sama tidak mengubah apa pun. Pembayaran tidak otomatis menjadi `paid`; keputusan tetap lewat `payments:review`. Jika status tidak memuat `transaction_id`, atau pembayaran sudah punya peninjauan settlement lama berreferensi `settlement` tanpa identitas transaksi, settlement berikutnya tetap diabaikan karena tidak dapat dibedakan dari replay. Rekonsiliasi tidak memeriksa pembayaran `failed`, jadi settlement baru untuk pembayaran tersebut hanya datang lewat webhook atau `Perbarui status`.

## A6 — refund sebagian pada pembayaran pending

Jika pembayaran lokal masih `pending` tetapi status Midtrans `partial_refund`, webhook, sinkronisasi manual (`Perbarui status`), dan `payments:reconcile` memakai pencatatan A3 yang sama: detail `refund_key`, fallback kumulatif, konflik nominal, dan idempotensi. Refund dicatat sebagai `pending_review` dengan alasan "Refund sebagian pada pembayaran pending terdeteksi…" dan log `payment.refund_on_pending`. Pembayaran tetap `pending`, Premium tidak diaktifkan, dan `subscriptions` serta `subscription_periods` tidak diubah; daftar pembayaran menampilkan "Sedang ditinjau". Settlement yang datang kemudian mengikuti aturan lama: karena sudah ada adjustment, pembayaran tidak menjadi `paid` dan settlement dicatat sebagai peninjauan. Hasil `unverified` pada rekonsiliasi yang bukan gangguan Midtrans (HTTP 503) dicatat sebagai `payment.reconcile_unverified` berisi ID pembayaran dan kode status. Peninjauan ini ditutup dengan `payments:review <id> --revoke --confirm`: hanya berlaku untuk pembayaran `pending` tanpa periode Premium yang semua peninjauan terbukanya berupa refund sebagian Midtrans. Peninjauan menjadi `resolved`, pembayaran tetap `pending`, tidak ada periode atau Premium yang dibuat, dan log `payment.review_closed` ditulis setelah commit. `--restore` selalu ditolak karena tidak ada Premium yang dapat dipulihkan. Pembayaran tanpa periode lainnya (misalnya `chargeback`, `refunded`, atau pending dengan peninjauan settlement) tetap ditolak seperti sebelumnya.

Struktur field mengikuti [Get Transaction Status Midtrans](https://docs.midtrans.com/reference/get-transaction-status).

Sinkronisasi payment paid dan rekonsiliasi refund merupakan pekerjaan terpisah. A3 mempertahankan refresh() dan pemilihan payment existing, sehingga pembayaran paid masih bergantung pada webhook untuk deteksi refund.

## Sandbox dan Production (Tahap 9)

Environment Midtrans dipilih **hanya** lewat `MIDTRANS_ENVIRONMENT` (`sandbox` atau `production`). Nilai kosong atau lain menonaktifkan checkout, rekonsiliasi, webhook dan refund (gagal aman, 503). Tidak ada fallback antar environment, dan `APP_ENV=production` menolak `sandbox` agar pembayaran simulasi tidak dapat mengaktifkan Premium.

| | Sandbox | Production |
| --- | --- | --- |
| Server Key | `MIDTRANS_SERVER_KEY` (`SB-Mid-server-…`) | `MIDTRANS_PRODUCTION_SERVER_KEY` (`Mid-server-…`) |
| Client Key | `MIDTRANS_CLIENT_KEY` | `MIDTRANS_PRODUCTION_CLIENT_KEY` (`Mid-client-…`) |
| Signature Secret Key | `MIDTRANS_SIGNATURE_SECRET_KEY` | `MIDTRANS_PRODUCTION_SIGNATURE_SECRET_KEY` |
| Signature Secret Key lama (rotasi, opsional) | `MIDTRANS_SIGNATURE_SECRET_KEY_PREVIOUS` + `MIDTRANS_SIGNATURE_PREVIOUS_UNTIL` | `MIDTRANS_PRODUCTION_SIGNATURE_SECRET_KEY_PREVIOUS` + `MIDTRANS_PRODUCTION_SIGNATURE_PREVIOUS_UNTIL` |
| API (Get Status, Refund) | `https://api.sandbox.midtrans.com` | `https://api.midtrans.com` |
| Snap | `https://app.sandbox.midtrans.com` | `https://app.midtrans.com` |

Host ditetapkan di `config/midtrans.php`, bukan dari `.env`. Prefix kunci diperiksa: kunci Sandbox di slot Production (atau sebaliknya) ditolak. Signature Secret Key selalu terpisah dari Server Key; signature webhook tidak pernah dihitung dengan Server Key. Service membaca semuanya lewat `config()`; jalankan `php artisan config:clear` atau `config:cache` ulang setelah mengubah `.env`.

**Permission Server Key.** Checkout memerlukan Snap Checkout Write; Get Transaction Status memerlukan Core API Read; **Refund API memerlukan akses Write** pada Core API (refund). Tanpa izin Write, Midtrans menolak refund (dicatat `rejected`, tidak ada uang bergerak). Pastikan juga fitur refund aktif untuk merchant Production (aktivasi melalui Midtrans support bila belum tersedia).

### Refund langsung dari aplikasi: `payments:gateway-refund`

Refund dikirim lewat Midtrans Refund API dari CLI; tidak ada halaman admin web. `payments:refund` tetap hanya **mencatat** refund manual di luar Midtrans.

```sh
# Dry-run (default): tidak mengirim apa pun, menampilkan sisa dan dampak Premium
php artisan payments:gateway-refund 123 --amount=2000 --reason="Customer refund"
# Eksekusi Sandbox
php artisan payments:gateway-refund 123 --amount=2000 --reason="Customer refund" --confirm --refund-key=dr-refund-unique-001
# Eksekusi Production: konfirmasi tambahan berupa order_id pembayaran
php artisan payments:gateway-refund 123 --amount=2000 --reason="Customer refund" --confirm --refund-key=dr-refund-unique-001 --confirm-production=<order_id>
# Rekonsiliasi refund yang belum selesai (hanya Get Status, tidak mengirim ulang)
php artisan payments:gateway-refund 123 --reconcile
# Reattempt eksplisit refund pending/unknown dengan refund_key, nominal dan alasan yang sama (≤144 jam)
php artisan payments:gateway-refund 123 --reattempt --refund-key=dr-refund-unique-001 --confirm
# Penutupan refund unknown setelah konfirmasi tertulis Midtrans (hanya pengembangan; ditolak di Production)
php artisan payments:gateway-refund-close-request <id-refund> --ticket=<tiket> --confirmed-on=YYYY-MM-DD --order-id=<order_id> --refund-key=<key> --amount=<nominal> --midtrans-confirmed-not-processed --reason="..." --confirm
php artisan payments:gateway-refund-close-approve <id-permintaan> --ticket=<tiket> --confirm
# Daftar dan rekonsiliasi semua refund gateway (juga dijadwalkan tiap 15 menit, hanya Get Status)
php artisan payments:gateway-refunds --status=unknown
php artisan payments:gateway-refunds --reconcile
```

Prosedur operator lengkap (refund unknown, reattempt, rekonsiliasi, backup/restore SQLite, trusted proxy, webhook, emergency rollback) ada di [operasional-pembayaran.md](operasional-pembayaran.md).

Operator dicatat dari `--operator` (default: user sistem). Validasi sebelum mengirim: pembayaran lokal `paid`; Get Status `settlement`/`partial_refund` dengan fraud `accept`; `payment_type` termasuk metode Refund API (`credit_card`, `gopay`, `shopeepay`, `dana`, `ovo`, `qris`, `kredivo`, `akulaku`; ShopeePay/OVO hanya refund penuh); `refund_amount` kumulatif sama dengan jumlah `refunds[]`; dan nominal ≤ sisa = pembayaran − refund Midtrans − refund manual. Metode lain (mis. Virtual Account) tetap memakai `payments:refund`.

Alur dua fase:

1. Get Status, validasi, hitung sisa (refund individual dari `refunds[]`, kumulatif dari `refund_amount`).
2. Dalam transaksi database (lock baris akun/pembayaran) intent disimpan di `payment_gateway_refunds` dengan status `pending` dan `refund_key` sebelum request dikirim.
3. Satu POST `/v2/{order_id}/refund` tanpa retry otomatis.
4. Get Status lagi; entri `refunds[]` dicocokkan berdasarkan `refund_key`, nominal individualnya dibandingkan dengan intent, lalu status dan referensi Midtrans (`refund_chargeback_id`, `refund_chargeback_uuid`, `refund_method`, `created_at`, `bank_confirmed_at`) disimpan.
5. Payload Get Status yang sama diterapkan lewat aturan penyesuaian yang sudah ada (referensi A3 `partial_refund:key:<sha256(refund_key)>`, batas A5, A6, R1, R2), sehingga webhook, `payments:reconcile` dan CLI tidak menggandakan penyesuaian. Refund penuh mencabut sisa Premium; refund sebagian tidak.
6. Log `payment.gateway_refund` berisi ID, event, environment, nominal dan operator saja (tanpa credential, signature, reason atau payload).

| Status | Arti | Slot refund |
| --- | --- | --- |
| `pending` | Intent tersimpan; hasil request belum tercatat (mis. proses terhenti) | Ditahan |
| `submitted` | Midtrans menjawab 200, tetapi belum muncul di `refunds[]` | Ditahan |
| `unknown` | Timeout, 5xx, 406, 429 atau respons tidak jelas | Ditahan |
| `review` | Nominal di Midtrans berbeda dengan intent | Ditahan |
| `succeeded` | Ada di `refunds[]`, `bank_confirmed_at` belum ada; dana **belum tentu** diterima pelanggan | Dibuka |
| `confirmed` | `bank_confirmed_at` terisi | Dibuka |
| `rejected` | Penolakan definitif (400–404, 410–414) dan tidak ada di `refunds[]` | Dibuka |
| `closed_unprocessed` | Ditutup dua operator (hanya pengembangan) setelah Midtrans mengonfirmasi tertulis tidak diproses | Dibuka; nominal tetap dicadangkan |

**Idempotency.** `refund_key` unik global (unique index) dan tidak pernah dipakai ulang; kolom `active_slot` (unique, berisi `payment_id` selama request belum selesai) menjamin maksimal satu refund berjalan per pembayaran, termasuk saat dua operator menjalankan perintah bersamaan. `refund_key` yang sama dengan referensi refund manual atau refund Midtrans yang sudah tercatat ditolak. HTTP 200 tidak dianggap dana sudah diterima; hanya `bank_confirmed_at` yang menandai konfirmasi bank.

**Timeout dan rekonsiliasi.** Timeout menghasilkan `unknown`; aplikasi tidak pernah mengirim ulang otomatis dan tidak pernah menganggap hasil ambigu sebagai gagal. Rekonsiliasi (`--reconcile` per pembayaran, atau `payments:gateway-refunds --reconcile` yang dijadwalkan tiap 15 menit) hanya memanggil Get Status. Pembaruan status memakai compare-and-set, sehingga snapshot lama atau dua proses bersamaan tidak dapat memundurkan refund yang sudah tampil di Midtrans atau melepas slot. Bila refund tetap tidak tampil, operator dapat melakukan reattempt eksplisit dengan `refund_key`, nominal dan alasan yang sama. Midtrans memperlakukan `refund_key` yang sama sebagai pengulangan refund yang sama dan mengizinkannya maksimal 7 hari; aplikasi membatasi 144 jam sejak intent dibuat dengan jeda minimal 10 menit. Penolakan pada reattempt tidak mengubah status menjadi `rejected`. Selama slot ditahan, refund gateway baru dan pencatatan manual `payments:refund` untuk pembayaran itu ditolak. Setelah batas reattempt lewat, refund tetap terbuka sampai Midtrans memberi konfirmasi tertulis. Penutupan `closed_unprocessed` lewat dua akun sistem operasi (`payments:gateway-refund-close-request` lalu `payments:gateway-refund-close-approve`) hanya tersedia di lingkungan pengembangan dan **ditolak di Production**, karena dua UID tidak membuktikan dua orang (sudo/su/root) dan akses tulis SQLite dapat melewatinya. Nominal refund tertutup tetap dicadangkan dalam batas refund; bila Midtrans mencantumkannya kemudian, refund tercatat ulang dan alert dibuat. Kelebihan refund dicatat di `payment_refund_anomalies` dan memblokir refund berikutnya. Lihat runbook bagian 4A–4D.

**Refund manual.** `payments:refund` hanya mencatat transfer di luar Midtrans dan tidak pernah mengirim Refund API. Untuk pembayaran `paid` perintah memanggil Get Status, merekonsiliasi refund Dashboard lewat aturan A3 (`partial_refund:key:<sha256(refund_key)>`, tanpa adjustment ganda) serta refund gateway yang terbuka, lalu menghitung sisa dari ledger tunggal. **Fail-closed:** bila konfigurasi Midtrans tidak lengkap, Get Status gagal/timeout/tidak cocok, transaksi tidak settlement/capture/refund, `refunds[]` tidak lengkap (termasuk hanya `refund_amount` kumulatif tanpa rincian), `refund_key` ganda, atau jumlah `refunds[]` tidak sama dengan `refund_amount`, tidak ada yang dicatat dan sisa refund tidak dianggap tersedia. Pencatatan juga ditolak selama ada refund gateway yang belum pasti atau anomali `refund_excess`. Pembayaran yang tidak `paid` tetap mendapat jawaban lokal seperti sebelumnya (misalnya replay referensi yang sama).

Migration `2026_11_25_000001_create_payment_gateway_refunds_table.php` sudah disiapkan tetapi **belum dijalankan** pada database development/production. Jalankan hanya setelah backup, pada target yang benar.

### Webhook Production

`POST /api/midtrans/notification` selalu terdaftar (aman untuk `route:cache`) dan mengembalikan 404 sampai `MIDTRANS_WEBHOOK_ENABLED=true`. Signature SHA-512 atas `order_id + status_code + gross_amount + Signature Secret Key` environment aktif dibandingkan dengan `hash_equals`; selama rotasi kunci lama terbatas (maks. 168 jam) juga diterima, dan order yang bukan UUID aplikasi (Test Notification Dashboard) dijawab 200 tanpa pemrosesan setelah signature lolos — lihat [operasional-pembayaran.md](operasional-pembayaran.md) bagian 7A–7B. Payload notifikasi tidak dipercaya sebagai status: status selalu diambil ulang dari Get Status. Saat nonaktif, endpoint mengembalikan 404 sebelum validasi payload, sehingga request tidak valid tidak mengungkap keberadaannya lewat 422. Endpoint tidak memakai CSRF/sesi dan tidak mencatat signature atau credential. Rate limiter khusus `midtrans-webhook` (`MIDTRANS_WEBHOOK_RATE_LIMIT`, bawaan 120 request/menit per IP, key terpisah dari checkout) mengembalikan 429; Midtrans mengirim ulang notifikasi yang tidak dijawab 2xx. Di balik proxy, isi `TRUSTED_PROXIES` dengan IP/CIDR proxy yang sebenarnya (wildcard ditolak) agar IP asli dan HTTPS terdeteksi; lihat runbook. Webhook asli Midtrans belum pernah diterima karena belum ada endpoint publik yang disetujui.

### Prosedur aktivasi Production

1. Backup database target dengan `sqlite3 .backup` (bukan `cp`) dan verifikasi; jalankan migration yang tertunda (termasuk `payment_gateway_refunds`) dengan `php artisan migrate --force` pada `/var/lib/dapur-rantau/database.sqlite`. Atur `DB_JOURNAL_MODE=wal`, `DB_SYNCHRONOUS=normal`, `DB_BUSY_TIMEOUT=5000`, dan `TRUSTED_PROXIES` sesuai infrastruktur.
2. Di Dashboard Midtrans **Production**: salin Server Key, Client Key dan Signature Secret Key ke `MIDTRANS_PRODUCTION_*` di `.env` server (bukan Git). Pastikan permission Server Key: Snap Write, Core API Read, dan Write untuk refund.
3. Set `MIDTRANS_ENVIRONMENT=production`, `APP_ENV=production`, `APP_DEBUG=false`; biarkan `MIDTRANS_WEBHOOK_ENABLED=false` dulu. Jalankan `php artisan config:cache`.
4. Atur Payment Notification URL Production ke `https://<domain>/api/midtrans/notification`, lalu set `MIDTRANS_WEBHOOK_ENABLED=true` dan `config:cache` ulang.
5. Jadwalkan `php artisan schedule:run` (rekonsiliasi pembayaran tiap 5 menit dan refund gateway tiap 15 menit, keduanya hanya Get Status).
6. Uji refund pertama dengan dry-run, lalu satu refund kecil yang disetujui dengan `--confirm-production`, dan pantau `--reconcile` sampai `confirmed`.

Perilaku Production (mis. `refund_method` online/offline, waktu `bank_confirmed_at`) belum diverifikasi; fakta Sandbox (`refund_method=offline`, `bank_confirmed_at` langsung terisi) tidak boleh diasumsikan sama.
