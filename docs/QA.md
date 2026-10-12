# QA akhir Dapur Rantau — 9 Oktober 2026

Status: seluruh automated test lulus; siap dipersiapkan untuk staging/production, **belum dideploy**. Ini QA lokal, bukan sertifikasi keamanan, jaminan uptime atau hasil load test.

## Temuan terprioritas

| Prioritas | Temuan | Tindakan / bukti |
| --- | --- | --- |
| P1 | composer setup selalu menjalankan key:generate walau .env sudah ada; session/ciphertext dapat terdampak | Generasi key hanya pada cabang pembuatan .env baru. Setup penuh tidak dijalankan pada instalasi aktif. Composer validation lulus. |
| P1 hardening | .env.local/.env.testing dan varian lain belum seluruhnya diabaikan | .env.* diabaikan kecuali .env.example. Tidak ditemukan key aktual pada public assets/log yang diperiksa. Tidak ada klaim kebocoran yang terjadi. |
| P1 hardening | Halaman privat belum eksplisit memakai no-store | Middleware web private/no-store, nosniff, SAMEORIGIN, referrer same-origin; regression test. Static Vite assets tidak melalui middleware ini. |
| P2 | Mark favorit memuat seluruh fingerprint koleksi meski hanya <=3 resep tampil | Query dibatasi whereIn fingerprint yang sedang ditampilkan, dengan regression test. |
| P2 | Pemindaian compiled Blade membawa class error/debug ke CSS | Hapus source storage/framework/views dari Tailwind. Build turun 72.20 kB → 57.65 kB tanpa menghapus source komponen aplikasi. |
| P2 | Navbar desktop membungkus label pada lebar 768 | Menu ringkas sampai breakpoint lg; diuji tablet. Status aktif Favorit/Riwayat tidak lagi menyorot Beranda. |
| P2 | Halaman 419/500 default belum konsisten berbahasa Indonesia | Blade error ramah; test app.debug=false memastikan exception/key tidak bocor HTML/JSON. |
| P2 | README/AGENTS memuat instruksi/status tahap awal yang kedaluwarsa | README menjadi panduan kondisi aktual; AGENTS menjadi aturan proyek, tanpa kewajiban memasang Boost yang tidak dibutuhkan. Riwayat tetap di PROGRESS. |

## Audit fitur/backend

- Route web, CSRF, throttle recipes 5/menit, ownership query dan UUID snapshot diperiksa. Controller tidak memanggil HTTP provider langsung; Gemini memakai service/DI/config.
- Input 1–15 bahan, distinct/normalisasi/tipe/panjang, opsi dan regex diuji. Vanilla JS memakai textContent/createElement, bukan innerHTML input pengguna. Blade escaped.
- Key dikirim lewat header backend pada host HTTPS allowlist, redirect dinonaktifkan; tidak dipakai frontend. Tidak ada env() pada service/controller.
- Structured JSON, finishReason/safety checks, tipe asli, field wajib, bounds, ukuran output, validasi cache dan alergen terdeteksi dipertahankan. Parser menolak truncated/invalid; tanpa fallback resep palsu.
- Token 3000/thinking minimal/model tetap. Cache key kanonis memasukkan alergi/model/versi; hit tidak request. Lock/retry maksimal satu tambahan dan quota/timeout tidak replay.
- Detail snapshot/favorit tanpa request Gemini; cache API24jam dan snapshotUI60menit terpisah. Favorit memakai unique visitor/fingerprint. Semua operasi koleksi di-scope visitor; mass assignment tidak menggunakan request all/input recipe.
- Riwayat sukses/cache dan pemangkasan30, tidak menyimpan alergi eksplisit, delete massal accepted confirmation diuji.
- Session default HTTPOnly/lax; cookie secure harus diaktifkan pada HTTPS production. Local env tetap local/debugtrue, bukan production.
- Data gambar landing diberi keterangan ilustrasi/contoh; hasil nyata menggunakan ikon generik. Tidak menambah gambar acak.

## Hasil perintah

- php artisan test: **67 passed / 0 failed / 439 assertions**.
- php artisan route:list: **16 route**, tidak ada login/registrasi.
- php artisan migrate:status: seluruh 4 file migration Ran pada development (read-only).
- Migration dijalankan otomatis di SQLite :memory: terisolasi melalui RefreshDatabase; tidak menjalankan migrate:fresh pada database pengguna.
- vendor/bin/pint --test: lulus seluruh proyek.
- composer validate --no-check-publish dan composer check-platform-reqs: lulus (PHP CLI8.3.13).
- composer audit: advisories kosong, abandoned kosong; npm audit: 0 vulnerabilities pada tanggal pemeriksaan.
- npm run build: lulus; CSS57.65 kB (gzip12.56), JS3.53 kB (gzip1.42).
- Pembacaan aman key env: terkonfigurasi, tidak ditemukan pada aset public atau log; nilainya tidak ditampilkan/disalin.

## Browser dan live API

Browser Chrome localhost: landing/input, chip Enter, duplikat ditolak, hapus mengembalikan tombol disabled, rekomendasi cache asli, detail, favorit kosong, riwayat dan menu tablet diperiksa. Lebar320 diperiksa termasuk screenshot hasil tanpa horizontal overflow (scrollWidth305 <= viewport320); lebar768 memperlihatkan masalah navbar yang kemudian diperbaiki dan diperiksa ulang; desktop navigasi aktif Riwayat diperiksa. Viewport dikembalikan.

Detail yang valid berhasil dibuka melalui URL yang ditampilkan tautan halaman. Salah satu klik tooling setelah full-page screenshot tidak berpindah halaman; DOM diperiksa lalu navigasi ke href yang sama berhasil. Tidak mengklaim klik tersebut berhasil.

Satu pencarian browser memakai cache live asli Step5 yang dipastikan masih ada, sehingga membuat history/snapshot baru normal. Tidak menyajikan fixture sebagai hasil Gemini. **0 live generation baru pada Step8**. Keberhasilan API live berasal dari verifikasi Step5, bukan test fake saat ini. Alur save/delete/detail dan penghapusan history kembali diuji otomatis dengan HTTP fake serta isolasi DB.

Bukti screenshot: screenshots/step-8-qa.jpg. Pemeriksaan desktop/mobile tahap6/7 juga tercatat di PROGRESS.md, tanpa menggantikan status test saat ini.

## Batas kesiapan deployment

Tidak ada kegagalan test atau blocker fungsi lokal yang diketahui. Sebelum rilis perlu konfigurasi nyata HTTPS, public document root, APP_ENV=production, APP_DEBUG=false, secure cookies, proxy, izin file, backup/migration, config/route/view cache dan smoke test di staging. Tidak dilakukan deployment atau perubahan env lokal.

Belum diuji: database MySQL, runtime PHP web MAMP/hosting, multi-instance shared cache, load/penetration testing independen, pemulihan backup. Data anonim tidak sinkron dan bisa tidak terakses setelah session hilang; cleanup data yatim belum ada. Matching/alergen tetap konservatif dan tidak menjamin semua sinonim/turunan. Kapasitas/kuota Gemini dapat berubah. Hal-hal ini harus dipertimbangkan saat menyiapkan production, bukan disamarkan sebagai kelulusan test lokal.
