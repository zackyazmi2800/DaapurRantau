# Deployment Dapur Rantau

Panduan persiapan, bukan deployment yang telah dilakukan. Hosting/domain belum ditentukan. Jalankan perintah server hanya setelah target, backup, dan akses disiapkan. Jangan menjalankannya pada direktori development sebagai pengganti deployment.

## 1. Persyaratan

- PHP >=8.3 sesuai composer.json dan Laravel 13. PHP CLI dan PHP web/FPM harus kompatibel. Composer 2; gunakan composer.lock, bukan composer update.
- Ekstensi PHP: Ctype, cURL, DOM, Fileinfo, Filter, Hash, Mbstring, OpenSSL, PCRE, PDO, Session, Tokenizer, XML; pdo_sqlite/sqlite3 untuk SQLite atau pdo_mysql jika kelak memilih MySQL. Periksa kebutuhan versi terkunci dengan `composer check-platform-reqs --no-dev` pada server.
- Node sesuai package.json: ^22.12.0 atau >=24.0.0; runtime proyek .nvmrc adalah 22.23.3. Node/npm hanya diperlukan di mesin build. Jangan unggah runtime macOS .tools ke Linux.
- Web server dengan PHP-FPM atau runtime PHP yang didukung hosting, HTTPS, disk persisten dan izin tulis terbatas. Izinkan koneksi HTTPS keluar ke Gemini serta DNS/sertifikat CA yang valid.
- Request rekomendasi sinkron mencakup hingga dua panggilan Gemini (masing-masing 30 detik), tanpa tahap pencarian gambar. Ukur batas waktu PHP/web server/proxy di staging sesuai alur aplikasi; jangan menaikkan timeout API secara otomatis.

## 2. Unduh source dan siapkan environment

Contoh direktori instalasi baru; semua path harus disesuaikan:

```bash
git clone --branch main https://github.com/zackyazmi2800/DaapurRantau.git dapur-rantau
cd dapur-rantau
cp .env.example .env
```

Perintah copy hanya untuk instalasi baru yang belum memiliki .env. Source lokal Step 9 belum otomatis di-commit/push; pilih commit yang telah ditinjau sebelum deployment. Simpan .env dan backup di luar akses publik, jangan di Git. Atur nilai server berikut lewat .env atau secret environment hosting:

```dotenv
APP_NAME="Dapur Rantau"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://domain-anda.example
APP_KEY=
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=info
DB_CONNECTION=sqlite
DB_DATABASE=/srv/dapur-rantau/shared/database/database.sqlite
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CACHE_STORE=database
CACHE_PREFIX=dapur-rantau-production-
GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.5-flash-lite
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta
GEMINI_MAX_OUTPUT_TOKENS=3000
GEMINI_THINKING_LEVEL=minimal
RECIPE_CACHE_ENABLED=true
RECIPE_CACHE_TTL=86400
```

Isi API key Gemini secara privat di server. Jangan gunakan variabel VITE_* untuk secret, jangan mencetak config lengkap, dan jangan menaruh key dalam argumen shell/history. Jangan mengganti model/provider. Jangan mengaktifkan billing otomatis. APP_URL tidak menggantikan konfigurasi HTTPS web server.

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
php artisan key:generate
```

`key:generate` hanya sekali untuk instalasi baru dengan APP_KEY kosong. Jika environment dikelola panel hosting, simpan key melalui secret manager hosting secara privat. Saat update/rollback, pertahankan APP_KEY yang sama, domain/cookie session dan database. Mengganti key dapat memutus session/akses favorit pengunjung dan data terenkripsi. Jangan memakai `composer run setup` pada production karena script tersebut menggabungkan langkah development/migration.

## 3. Database, session, cache dan backup

SQLite tetap pilihan proyek, cocok untuk satu instance dengan disk lokal persisten dan beban tulis yang sesuai. Jangan letakkan database dalam public/, filesystem sementara, atau release yang akan dihapus. Multi-instance/hosting ephemeral memerlukan keputusan storage/database bersama; jangan mengganti driver otomatis. MySQL dapat dikonfigurasi lewat DB_CONNECTION/DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD, tetapi migrasi data dan pengujian harus direncanakan terpisah.

Untuk instalasi SQLite baru, buat direktori privat dan file kosong pada path DB_DATABASE yang dipilih. Jangan menimpa database yang sudah ada. User PHP memerlukan akses tulis file **dan direktori induknya** untuk journal/WAL. Gunakan backup konsisten SQLite (backup API atau sqlite3 `.backup`), bukan menyalin file database aktif tanpa memperhatikan WAL. Backup harus mencakup database, .env/APP_KEY secara terenkripsi, storage persisten, serta commit/artifact release. Uji pemulihan backup.

Migration tersedia untuk sessions, cache, cache_locks, jobs/job_batches/failed_jobs, saved_recipes dan recipe_searches. Tabel users bawaan tidak berarti fitur login ditambahkan. Favorit/riwayat tetap dibatasi visitor session; sesi kedaluwarsa/hilang tidak otomatis dapat dipulihkan pengguna karena aplikasi tidak memakai login.

Database cache mendukung lock yang dibutuhkan limiter/concurrency/checklist. Cache file hanya cocok pada satu instance dengan storage persisten. Jangan memakai array cache atau cookie session untuk production ini. Semua instance harus berbagi cache/lock/session jika nanti scale-out. Cache snapshot 60 menit berbeda dari cache Gemini 24 jam; jangan flush cache saat update biasa.

Setelah backup dan konfirmasi koneksi database tujuan pada server:

```bash
php artisan config:clear
php artisan migrate:status
php artisan migrate --force
php artisan migrate:status
```

Instalasi baru dapat melaporkan tabel migration belum ada pada pemeriksaan pertama. Jangan memakai migrate:fresh, db:wipe atau rollback massal. Jangan menjalankan migration production sebelum server ditentukan. Tidak ada kebutuhan worker baru untuk rekomendasi karena proses saat ini sinkron.

## 4. Build frontend

Di mesin build/server release dengan Node yang sesuai:

```bash
npm ci --ignore-scripts
npm run build
```

Dependency Vite/Tailwind adalah devDependencies sehingga **jangan** menggunakan npm ci --omit=dev saat build. Upload hasil lengkap public/build termasuk manifest.json bila build di mesin lain; directory tersebut sengaja tidak masuk Git. Pastikan public/hot tidak ada dalam release production agar @vite memakai manifest, bukan development server. Tidak perlu menjalankan npm run dev atau php artisan serve di production.

Blade menggunakan @vite; Tailwind 4 memakai @tailwindcss/vite. Halaman resep dan koleksi tidak menggunakan gambar atau placeholder. Hero menggunakan aset lokal. Tidak perlu storage:link untuk fitur saat ini; jangan mengekspos storage privat.

## 5. Web root, HTTPS dan permission

Set document root domain ke **/path/release/public**, bukan root repository. Pada Apache gunakan public/.htaccess dan mod_rewrite/AllowOverride yang sesuai. Pada Nginx arahkan file yang tidak ditemukan ke /index.php?$query_string dan PHP ke FPM sesuai server. Tolak dotfiles kecuali challenge .well-known yang diperlukan sertifikat; nonaktifkan directory listing dan eksekusi PHP selain entry point yang dibutuhkan. Jangan memindahkan index.php ke root proyek.

Aktifkan sertifikat HTTPS melalui hosting dan redirect HTTP ke HTTPS. Jika TLS berhenti di reverse proxy, tentukan trusted proxy/IP dan forwarded headers sesuai dokumentasi hosting sebelum go-live agar skema HTTPS dan IP rate limiter benar. Jangan mempercayai semua proxy/header dari Internet. Konfigurasi ini sengaja belum ditambahkan karena topologi belum diketahui.

User PHP-FPM harus dapat menulis storage/ dan bootstrap/cache/, serta file/direktori SQLite. Source lainnya cukup dapat dibaca. Contoh setelah ownership/group PHP ditentukan: directory storage/bootstrap-cache 0770, file 0660, .env 0600 (atau 0640 jika group PHP perlu membacanya). Sesuaikan shared hosting; jangan chmod 777 seluruh proyek. Cache konfigurasi memuat secret sehingga bootstrap/cache juga harus privat. Log perlu rotasi/retensi dan akses admin terbatas.

CSRF middleware web, validasi request/hasil AI, limiter 5/menit per IP, lock concurrency dan ownership visitor tetap aktif. Jangan memakai cache HTML publik/CDN untuk halaman dengan session; respons web sudah private,no-store. Static assets dapat dicache. Error 404/419/500 memakai Blade Indonesia; APP_DEBUG=false menyembunyikan detail internal.

## 6. Optimasi setelah environment final

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan route:list --except-vendor
```

Jalankan dalam release target, bukan membagikan cache konfigurasi dari komputer lokal. Saat environment berubah, jalankan config:clear lalu config:cache kembali dan restart/reload proses PHP yang menetap sesuai hosting. Route/view cache dibangun ulang setelah perubahan source. Hindari optimize:clear dan cache:clear rutin: dapat menghapus cache aplikasi/snapshot dan menambah penggunaan API. Jangan mengunggah config cache ke Git.

## 7. Pemeriksaan sebelum membuka trafik

- Buka / dan /up, cek CSS/JS dari /build dengan status 200, tanpa URL localhost:5173. /up hanya membuktikan boot aplikasi, bukan kesehatan database/API.
- Cek desktop/mobile, input chips, katalog, favorit/riwayat dan halaman 404. Browser Console tidak boleh error.
- Periksa cookie session: Secure, HttpOnly, SameSite=Lax; cek halaman tidak dicache publik. URL /.env, /.git/config, /composer.json dan /storage/logs/laravel.log harus ditolak/tidak menyajikan isi.
- Uji rekomendasi live hanya dengan izin dan batas request. Periksa tiga kategori/strict, checklist, cooking guide, favorit/riwayat serta pengulangan cache tanpa panggilan tambahan. Sampai uji dilakukan, jangan mengklaim provider production terverifikasi.
- Bila gagal, periksa storage/logs/laravel-YYYY-MM-DD.log untuk LOG_STACK=daily dan log PHP/web server. Jangan membagikan raw log/config tanpa penyamaran. Error timeout/kuota provider tetap mungkin meskipun deployment benar.

## 8. Update dan rollback

Siapkan direktori release baru agar source/vendor/aset tidak berubah sebagian saat melayani pengguna. Catat commit aktif, backup database konsisten serta konfigurasi privat; fetch GitHub lalu checkout commit yang telah disetujui pada release baru. Untuk pola satu direktori gunakan maintenance window dan `git pull --ff-only origin main` hanya jika working tree bersih, bukan force/reset yang menghapus perubahan.

Pasangkan .env, storage dan SQLite persisten yang sama (di luar release); pastikan path DB_DATABASE absolut. Ulang composer install production, build aset, cek platform, lalu migration yang telah ditinjau dan optimasi. Jika schema tidak kompatibel dengan kode lama, hentikan trafik melalui mekanisme hosting/maintenance (`php artisan down` pada release aktif), tunggu request selesai, kemudian migrasi dan alihkan document root/symlink current secara atomik. Restart PHP sesuai hosting, `php artisan up`, lalu smoke test. Maintenance state harus konsisten antar-release/instance; jangan membuka trafik sebelum release siap.

Rollback kode: kembalikan document root/symlink ke release sebelumnya beserta vendor/asetnya, pertahankan APP_KEY dan rebuild config/route/view cache dengan environment server. Rollback ini hanya aman jika schema database masih kompatibel. Jika migrasi tidak kompatibel, hentikan trafik dan susun restore backup database yang sesuai; pemulihan dapat kehilangan data setelah waktu backup sehingga memerlukan keputusan operator. Jangan otomatis migrate:rollback. Simpan release dan backup lama sampai hasil pemeriksaan stabil.

## Keputusan yang masih diperlukan

Hosting/domain, web server/PHP process, disk persisten, pilihan tetap SQLite atau migrasi database, reverse proxy, timeout platform, lokasi secret/backup serta mekanisme release/restart. Panduan ini tidak membuat akun, membeli hosting, mengubah DNS, menjalankan migration server, maupun melakukan deployment.

Rujukan resmi: [Deployment Laravel 13](https://laravel.com/docs/13.x/deployment). Detail khusus aplikasi di atas mengikuti kode proyek; konfigurasi server final mengikuti hosting yang dipilih.
