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
3. Pilih environment **Sandbox** di dashboard merchant Midtrans. Dari **Settings → Access Keys**, tetapkan `MIDTRANS_IS_PRODUCTION=false`, salin Server Key ke `MIDTRANS_SERVER_KEY`, Client Key ke `MIDTRANS_CLIENT_KEY` dan Signature Secret Key ke `MIDTRANS_SIGNATURE_SECRET_KEY` dalam `.env` lokal. Gunakan kunci dari merchant Sandbox yang sama, dengan izin Snap Checkout Write dan Core API Read untuk Server Key. [Access Keys Midtrans](https://docs.midtrans.com/docs/access-keys). Server memakai Basic Auth dan host Sandbox tetap. Browser hanya memerlukan Client Key untuk Snap. Pertahankan APP_KEY existing. Jalankan `php artisan config:clear` jika konfigurasi di-cache.
4. Bangun aset dengan Node versi proyek dan `npm run build`, lalu mulai Laravel lokal. Buka `/paket`, masuk, pilih paket, dan periksa modal Snap. Gunakan metode pembayaran simulasi Sandbox.
5. Untuk notifikasi, sediakan endpoint HTTPS publik dengan sertifikat valid yang meneruskan **hanya** POST `/api/midtrans/notification` ke aplikasi development. Atur **Payment Notification URL** pada pengaturan notifikasi Sandbox menjadi URL tersebut. Browser pembeli dapat tetap memakai localhost. Endpoint stateless terdaftar untuk local/testing. Form pembelian memakai sesi login dan CSRF biasa. Hindari mengekspos halaman debug development melalui tunnel publik.

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

Midtrans tidak dapat me-refund Virtual Account. Setelah dana ditransfer manual ke pelanggan, catat dengan perintah berikut. Perintah ini tidak mengirim uang dan tidak menghubungi Midtrans.

```sh
php artisan payments:refund <id-pembayaran> --amount=5000 --reason="Permintaan pengguna" --reference=<referensi-transfer> --confirm
```

Tanpa `--confirm` tidak ada yang dicatat. Referensi yang sama tidak dicatat dua kali, dan total refund tidak boleh melebihi nominal pembayaran. Total itu mencakup refund manual sebelumnya dan refund sebagian yang sudah tercatat dari Midtrans; karena Midtrans melaporkan jumlah kumulatif, yang dihitung adalah nilai terbesarnya. Perintah menolak kelebihan dan menyebut sisa yang masih dapat direfund. Jika totalnya sama dengan nominal pembayaran, aturan refund penuh berlaku; jika kurang, refund masuk peninjauan tanpa mengubah Premium. Refund atau chargeback yang notifikasinya tidak sampai tidak terdeteksi oleh rekonsiliasi, karena rekonsiliasi hanya memeriksa pembayaran `pending` dan `review`.

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

Perintah berikut menghapus paket, pembayaran, masa aktif, kuota harian dan tautan kuota pada operasi rekomendasi. Gunakan hanya pada **salinan SQLite development** yang boleh kehilangan data fitur tersebut. Tabel refund dan periode bergantung pada tabel pembayaran, jadi migration-nya dibatalkan lebih dulu; langkah itu menghapus riwayat refund dan peninjauan. Akun, favorit, riwayat dan operasi lama dipertahankan. Untuk mempertahankan data billing, gunakan backup konsisten dan pulihkan bersama versi aplikasi yang sesuai.

```sh
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

## A6 — refund sebagian pada pembayaran pending

Jika pembayaran lokal masih `pending` tetapi status Midtrans `partial_refund`, webhook, sinkronisasi manual (`Perbarui status`), dan `payments:reconcile` memakai pencatatan A3 yang sama: detail `refund_key`, fallback kumulatif, konflik nominal, dan idempotensi. Refund dicatat sebagai `pending_review` dengan alasan "Refund sebagian pada pembayaran pending terdeteksi…" dan log `payment.refund_on_pending`. Pembayaran tetap `pending`, Premium tidak diaktifkan, dan `subscriptions` serta `subscription_periods` tidak diubah; daftar pembayaran menampilkan "Sedang ditinjau". Settlement yang datang kemudian mengikuti aturan lama: karena sudah ada adjustment, pembayaran tidak menjadi `paid` dan settlement dicatat sebagai peninjauan. Hasil `unverified` pada rekonsiliasi yang bukan gangguan Midtrans (HTTP 503) dicatat sebagai `payment.reconcile_unverified` berisi ID pembayaran dan kode status. Peninjauan ini ditutup dengan `payments:review <id> --revoke --confirm`: hanya berlaku untuk pembayaran `pending` tanpa periode Premium yang semua peninjauan terbukanya berupa refund sebagian Midtrans. Peninjauan menjadi `resolved`, pembayaran tetap `pending`, tidak ada periode atau Premium yang dibuat, dan log `payment.review_closed` ditulis setelah commit. `--restore` selalu ditolak karena tidak ada Premium yang dapat dipulihkan. Pembayaran tanpa periode lainnya (misalnya `chargeback`, `refunded`, atau pending dengan peninjauan settlement) tetap ditolak seperti sebelumnya.

Struktur field mengikuti [Get Transaction Status Midtrans](https://docs.midtrans.com/reference/get-transaction-status).

Sinkronisasi payment paid dan rekonsiliasi refund merupakan pekerjaan terpisah. A3 mempertahankan refresh() dan pemilihan payment existing, sehingga pembayaran paid masih bergantung pada webhook untuk deteksi refund.
