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

| Status | Arti |
| --- | --- |
| `pending` | Menunggu pembayaran. Jika Midtrans tidak pernah mencatat transaksinya, status menjadi `expired` setelah 48 jam. |
| `paid` | Lunas dan Premium sudah ditambahkan. Status ini final. |
| `failed`, `expired` | Gagal atau kedaluwarsa. Settlement yang datang kemudian tetap dapat mengubahnya menjadi `paid`. |
| `review` | Midtrans menyatakan lunas, tetapi kode paket tidak dikenali. Premium belum ditambahkan dan kejadian dicatat di log sebagai `payment.review`. Setelah paket pada pembayaran diperbaiki, rekonsiliasi mengaktifkannya satu kali. |

Saat database sibuk, webhook menjawab 503 berformat JSON dengan `Retry-After`, sedangkan sinkronisasi manual menampilkan pesan tanpa mengubah status. Jika kunci lebih dulu mengenai session atau pembatas laju, halaman pembayaran menampilkan halaman 503 bermerek dengan tautan kembali ke Langganan saya; halaman itu dirender tanpa query database. Kegagalan saat memproses pembayaran (webhook, sinkronisasi, rekonsiliasi) dicatat sebagai `payment.processing_failed` berisi ID pembayaran, jenis operasi, kategori, jenis exception, dan lokasi kode, tanpa SQL, nilai parameter, path, atau stack trace.

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

Perintah berikut menghapus paket, pembayaran, masa aktif, kuota harian dan tautan kuota pada operasi rekomendasi. Gunakan hanya pada **salinan SQLite development** yang boleh kehilangan data fitur tersebut. Pastikan migration Premium adalah migration terbaru dengan `migrate:status`. Akun, favorit, riwayat dan operasi lama dipertahankan. Untuk mempertahankan data billing, gunakan backup konsisten dan pulihkan bersama versi aplikasi yang sesuai.

```sh
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
- `app/Services/QuotaService.php` (baru)
- `app/Services/RecommendationOperationService.php` (diubah)
- `app/Services/SubscriptionService.php` (baru)
- `app/Support/SqliteContention.php` (diubah)
- `bootstrap/app.php` (diubah)
- `config/midtrans.php` (baru)
- `database/migrations/2026_11_21_000001_create_premium_tables.php` (baru)
- `docs/premium-sandbox.md` (baru)
- `resources/js/app.js` (diubah)
- `resources/views/account/required.blade.php` (diubah)
- `resources/views/auth/login.blade.php` (diubah)
- `resources/views/components/account-navigation.blade.php` (diubah)
- `resources/views/components/favorite-button.blade.php` (diubah)
- `resources/views/components/visitor-notice.blade.php` (diubah)
- `resources/views/recommendations/operation-error.blade.php` (diubah)
- `resources/views/errors/payment-busy.blade.php` (baru)
- `resources/views/subscription/pricing.blade.php` (baru)
- `resources/views/subscription/required.blade.php` (baru)
- `resources/views/subscription/show.blade.php` (baru)
- `routes/console.php` (diubah)
- `routes/web.php` (diubah)
- `tests/Feature/PremiumSubscriptionTest.php` (baru)

Migration juga memperbarui skema SQLite development lokal di `database/database.sqlite`. Vite menghasilkan aset lokal di `public/build/`. Keduanya berada di luar Git.
