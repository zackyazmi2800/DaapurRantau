# Dapur Rantau

Platform ide masakan praktis dan hemat untuk mahasiswa, anak kos, dan perantau. Pengguna memasukkan bahan, mendapatkan tepat tiga resep pada pencarian yang berhasil dari Google Gemini, membaca detail, menyimpan favorit, dan melihat riwayat pencarian tanpa login.

## Fitur

- Landing page dan input chip bahan (1–15), porsi, preferensi dan bahan yang dihindari.
- Rekomendasi JSON tervalidasi, pencocokan bahan deterministik, urutan resep paling sesuai, detail dengan checkbox bahan/langkah.
- Cache Gemini 24 jam, snapshot halaman privat 60 menit, limiter dan proteksi concurrent request.
- Kartu rekomendasi, detail dan favorit berbasis teks tanpa gambar resep atau layanan gambar eksternal.
- Favorit dari snapshot server, deduplikasi per pengunjung, detail database tanpa Gemini.
- Riwayat 30 pencarian terakhir, termasuk cache hit; ulang input, hapus satu/semua dengan konfirmasi.
- Blade responsif, CSRF, escaping output, pembatasan kepemilikan dan error berbahasa Indonesia.

## Teknologi dan struktur

Laravel 13.35.0, PHP >=8.3, Composer 2, Blade, Tailwind CSS 4.3.3, @tailwindcss/vite, Vanilla JavaScript, Vite 8.3.3, SQLite development. Node mengikuti `.nvmrc` (22.23.3 saat QA); Vite membutuhkan Node yang sesuai engines package.json. Tidak ada SDK Gemini atau library UI tambahan.

```text
app/Http/Controllers/   Beranda, rekomendasi, favorit, riwayat
app/Http/Requests/      GenerateRecipeRequest, ClearHistoryRequest
app/Http/Middleware/    Concurrency dan header privasi web
app/Services/          Gemini, matching, snapshot, identitas, favorit, riwayat
app/Models/            SavedRecipe, RecipeSearch, User bawaan (tanpa auth)
app/Support/           Cache key, safe error response, diagnostik Gemini
config/ai.php          Konfigurasi provider, token, cache dan versi schema
resources/views/       Layout, komponen, halaman dan error
resources/js/          Menu mobile dan input chip
resources/css/app.css  Tailwind 4 dan token warna
routes/web.php         Endpoint aplikasi
database/migrations/  Tabel Laravel dan koleksi resep
tests/                Unit/Feature, HTTP fake, SQLite :memory:
docs/arsitektur.md    Tanggung jawab dan alur data
PROGRESS.md           Riwayat pengerjaan serta hasil pengujian
```

## Instalasi baru

Gunakan PHP >=8.3 dengan ekstensi Laravel, PDO SQLite, serta Composer dan Node yang kompatibel. Pada MAMP pastikan versi PHP web juga sesuai; PHP CLI yang diuji berasal dari Herd 8.3.13.

```sh
composer install
cp .env.example .env
php artisan key:generate
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate
npm ci
npm run build
```

Skrip composer setup kini hanya membuat key saat membuat .env baru; jika .env sudah ada tetapi APP_KEY masih kosong, isi key sekali secara sengaja dengan key:generate. Jangan gunakan setup sebagai perintah deployment.

Jalankan penyalinan `.env` dan key:generate hanya untuk instalasi baru. Jangan menimpa konfigurasi atau APP_KEY instalasi aktif. Jika memakai nvm, jalankan `nvm install` dan `nvm use` sebelum npm. Runtime lokal macOS ARM yang sudah tersedia dapat dipilih dengan:

```sh
export PATH="$PWD/.tools/node-v22.23.3-darwin-arm64/bin:$PATH"
```

`.tools` tidak ikut version control. Platform lain memakai instalasi Node masing-masing. Document root server harus menuju `public/`, bukan root proyek.

## Konfigurasi environment

`.env.example` adalah contoh development; nilai key kosong. File `.env`, varian `.env.*` selain contoh, log dan database SQLite lokal tidak boleh masuk Git. Jangan mengirim key ke variabel berawalan VITE_.

```dotenv
APP_NAME="Dapur Rantau"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
DB_CONNECTION=sqlite
SESSION_DRIVER=database
CACHE_STORE=database
GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.5-flash-lite
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta
GEMINI_MAX_OUTPUT_TOKENS=3000
GEMINI_THINKING_LEVEL=minimal
RECIPE_CACHE_ENABLED=true
RECIPE_CACHE_TTL=86400
```

SQLite default menggunakan `database/database.sqlite`. Untuk MySQL gunakan DB_CONNECTION/DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD sesuai server, lalu migration. Migration memakai JSON/UUID/index standar Laravel; MySQL belum diuji langsung.

### Membuat key Gemini

Buka halaman [API Keys Google AI Studio](https://aistudio.google.com/api-keys), pilih/import project yang berhak Anda akses, lalu buat key untuk project tersebut. Simpan hanya ke GEMINI_API_KEY pada server. Ikuti pengaturan akses/restriksi yang dijelaskan [dokumentasi key resmi](https://ai.google.dev/gemini-api/docs/api-key). Pembuatan/konfigurasi billing tidak dilakukan aplikasi.

Setelah mengubah `.env` development, jalankan `php artisan config:clear`. Jangan mencetak konfigurasi ai secara utuh. Pastikan model tersedia di project; jangan mengganti model otomatis atau melakukan live request berulang untuk memeriksa UI.

## Menjalankan aplikasi

Terminal backend:

```sh
php artisan serve --host=127.0.0.1 --port=8000
```

Buka http://localhost:8000 dan gunakan hostname yang konsisten agar session/cookie tidak berganti. Untuk frontend development, terminal kedua:

```sh
npm run dev -- --host 127.0.0.1
```

Alternatif tanpa dev server: `npm run build`. Layout memakai @vite; Tailwind menggunakan plugin Vite versi 4, tanpa CDN. Jika Vite sudah berhenti tetapi browser masih meminta port 5173, periksa stale `public/hot` dan hapus hanya marker dev server tersebut, kemudian gunakan build. Jangan menghapus marker ketika dev server memang sedang berjalan.

## Alur resep, cache, token dan limiter

POST /rekomendasi → Form Request → GeminiRecipeService → cache/API → validasi → matching → snapshot privat dan history. Favorit disimpan dari ID snapshot/recipe yang diverifikasi server, bukan JSON browser. GET hasil/detail/favorit/riwayat tidak menghasilkan request Gemini.

- generateContent REST memakai systemInstruction, satu input JSON, responseMimeType application/json dan responseJsonSchema. Model tetap dapat dikonfigurasi; thinking MINIMAL, output 3000 token, sampling default. Tidak ada riwayat chat atau request terpisah per resep.
- Schema generation mempertahankan tipe/field/enum dan tepat 3 resep (`minItems: 3`, `maxItems: 3`). Batas numeric/array internal ditegakkan validator backend secara ketat (waktu 1–1440, porsi 1–10, bahan 1–30, langkah 1–8). Pendekatan ini memperbaiki HTTP400 schema sebelumnya; prompt_version=5, schema_version=3. Jangan mengembalikan schema generation lama tanpa bukti kompatibilitas.
- Parser menolak output terpotong, safety block, JSON rusak, tipe salah, field hilang/tambahan dan konflik alergen terdeteksi. Tidak ada fallback contoh ketika API gagal.
- Hasil harus tepat tiga resep unik dan bebas konflik alergi. Resep diurutkan berdasarkan jumlah bahan wajib tambahan terkecil, tanpa minimum atau kuota tambahan per kategori. Label Paling Cocok, Cukup Cocok, dan Alternatif Menu menunjukkan peringkat relatif, bukan rentang jumlah tambahan. Hasil 0–0–0, 2–4–4, atau 2–5–8 tetap valid. Duplikat bahan dihitung sekali dengan alias terkontrol; bahan opsional tidak dihitung. Nama yang menggabungkan beberapa bahan tetap ditolak dan dapat dikoreksi sekali, dalam anggaran total dua request termasuk retry transport. Tidak ada resep pengganti buatan.
- Cache key SHA-256 mencakup bahan lowercase/trim/unik/terurut, porsi, preferensi, alergi, model, versi prompt/schema, thinking dan token limit. Urutan bahan berbeda memakai key sama. Hanya hasil valid disimpan; cache hit divalidasi ulang. TTL 86400 detik, dapat dinonaktifkan dengan RECIPE_CACHE_ENABLED=false.
- Snapshot UI prefix terpisah, UUID pencarian/resep, owner token session, TTL 3600 detik. Tidak ada seluruh JSON dalam cookie atau URL. Halaman web memakai private/no-store; aset build tetap dapat di-cache web server.
- RateLimiter di AppServiceProvider: **5 POST rekomendasi per menit/IP** (IP di-hash), termasuk cache hit. Middleware mengunci satu request aktif/IP; service mengunci input identik dengan lease 130 detik. Database/file cache mendukung lock. Pengguna NAT dapat berbagi limit; trusted proxy harus sesuai infrastruktur nyata.
- Connect timeout 5 detik, request timeout 30 detik (maksimum config 60). Maksimal satu retry tambahan hanya HTTP500/503, dengan Retry-After <=2 detik; delay lebih lama tidak diulang lebih awal. Tidak retry timeout/connection error/429/RESOURCE_EXHAUSTED/QuotaFailure, JSON rusak/terpotong, alergi, atau error akses/input/model. Hasil dengan nama bahan gabungan boleh dikoreksi sekali dalam batas total dua panggilan; jumlah tambahan tidak memicu koreksi. Tidak ada kenaikan token otomatis.
- Log mencatat metrik model, token numerik/null, status, durasi, cache hit/miss dan diagnostik yang diizinkan. Key, prompt, input alergi dan raw provider body tidak dicatat.

## Identitas anonim dan batasan

Visitor UUID dibuat server dan disimpan session, bukan dari IP atau request input. Semua query favorit/riwayat menyertakan visitor_id. Fingerprint + unique index per visitor mencegah resep identik ganda. Favorit menyimpan recipe_data saja; riwayat menyimpan bahan/porsi/preferensi/jumlah hasil dan pointer snapshot, tanpa alergi atau respons provider lengkap.

Sesi/cookie hilang, browser berganti atau perangkat berbeda dapat membuat data tidak bisa diakses lagi. Tidak ada akun, sinkronisasi atau pemulihan. Kebijakan pembersihan data yatim belum diimplementasikan. Alergi perlu diisi kembali melalui Cari Lagi. Hapus history tidak menghapus favorit atau cache Gemini bersama.

Matching nama utuh memakai alias terkontrol (cabe/cabai, garlic/bawang putih), bukan substring. Telur tidak otomatis sama dengan telur ayam. Kesesuaian tidak menghitung kecukupan takaran dan bukan jaminan keamanan alergen/turunannya. Checkbox hanya UI, tidak persisten. POST hasil masih langsung menampilkan halaman; refresh POST bisa meminta submit ulang, dilindungi limiter/cache.

## Testing dan QA

```sh
php artisan test
php artisan route:list
php artisan migrate:status
vendor/bin/pint --test
composer validate --no-check-publish
composer check-platform-reqs
npm run build
```

Automated tests memakai Http::fake/preventStrayRequests dan SQLite :memory: dengan RefreshDatabase. Migration diuji di database terpisah; **jangan menjalankan migrate:fresh pada database pengguna**. CSRF dites dengan mematikan bypass unit-test di test saja. Audit advisory saat membutuhkan koneksi registry: `composer audit` dan `npm audit` (bukan auto-fix).

QA Step 8: 67 test / 439 assertions lulus, 16 route, Pint/platform requirements/Composer validation lulus. Audit dependency 9 Oktober 2026: tidak ada advisory Composer/abandoned package atau kerentanan npm. Build CSS 57.65 kB, JS 3.53 kB sebelum gzip. Hasil dan pemeriksaan browser lengkap ada diPROGRESS.md.

Live Gemini **sudah berhasil pada Step 5** dengan JSON resep valid dan cache hit terbukti; Step 8 tidak melakukan live generation baru. Keberhasilan fake bukan bukti uptime/kuota provider saat deployment.

## Troubleshooting

| Gejala | Pemeriksaan |
| --- | --- |
| Key/model tidak terbaca | Isi env server lalu config:clear development; jangan mencetak key. |
| HTTP400 provider | Baca gemini.error yang disaring; pertahankan schema ringkas dan validasi backend. Jangan menebak field atau retry berulang. |
| 401/403/404 provider | Periksa izin/key dan model project; tidak ada fallback model diam-diam. |
| 429 | Tunggu limiter/kuota; jangan retry kuota harian atau mengaktifkan billing otomatis. |
| 500/503/timeout provider | Pesan aman tampil; retry hanya sesuai aturan di atas. Coba nanti, tanpa loop request. |
| Tidak ada tabel cache/session/koleksi | Periksa koneksi DB lalu php artisan migrate; jangan reset database. |
| 419 | Buka ulang halaman; periksa hostname, HTTPS, domain cookie dan session storage. |
| Favorit/riwayat kosong setelah browser berubah | Data berbasis session anonim, bukan akun. |
| Snapshot tidak tersedia | Cari Lagi memulihkan input; hasil lama hanya bertahan 60 menit. |
| CSS/JS tidak ter-load | Periksa Node, npm run build atau dev server, public/hot dan document root public/. |
| SQLite terkunci/permission | Periksa izin direktori database dan file, storage serta bootstrap/cache; jangan chmod777. |

## Persiapan production (belum deployment)

Aplikasi lulus QA lokal dan siap dipersiapkan untuk staging. Sebelum deployment:

- Atur APP_ENV=production, **APP_DEBUG=false**, APP_URL HTTPS, SESSION_SECURE_COOKIE=true, SESSION_HTTP_ONLY=true, SESSION_SAME_SITE=lax. Simpan APP_KEY tetap rahasia dan stabil; jangan generate ulang key instalasi aktif.
- Konfigurasikan HTTPS, document root public/, proxy tepercaya sesuai server, izin storage/bootstrap/cache/database dan backup database. Jangan mengekspos .env, storage log, vendor, .git atau SQLite lewat web.
- Gunakan composer install --no-dev --optimize-autoloader, npm ci dan npm run build dalam proses rilis. Jangan menjalankan Vite dev server/public/hot pada production.
- Backup sebelum migration; jalankan php artisan migrate --force pada target yang benar, lalu config:cache, route:cache, view:cache setelah konfigurasi final. Hindari cache:clear bersama tanpa kebutuhan.
- Uji HTTPS/cookie/proxy, ownership, cache, permission, versi PHP web dan database target di staging. Lakukan maksimal satu smoke API yang disepakati bila diperlukan; jangan menganggap fake membuktikan koneksi live.

Deployment, pengujian MySQL, konfigurasi infrastruktur, load test dan audit penetrasi independen belum dilakukan. Mode lokal tetap APP_ENV=local/APP_DEBUG=true; itu bukan konfigurasi production.


## Resep tanpa gambar

Integrasi Unsplash telah dihapus. Setelah Gemini dan pengurutan resep selesai, aplikasi langsung menyiapkan snapshot tanpa pencarian gambar. Kartu, detail, favorit dan riwayat tidak menampilkan gambar atau placeholder resep. Checklist, panduan memasak, kategori dan tombol aksi tetap tersedia.

Metadata gambar dalam snapshot/favorit lama dibiarkan tersimpan dan diabaikan oleh tampilan. Fingerprint favorit tetap berdasarkan isi resep. Tidak diperlukan migration atau penghapusan cache/data lama. Gambar hero lokal dan aset identitas visual tetap digunakan.

Variabel `UNSPLASH_*` dan `RECIPE_IMAGE_*` tidak lagi dibaca aplikasi; pemilik dapat menghapusnya sendiri dari `.env`. Dokumen `docs/UNSPLASH_*.md` merupakan arsip historis, bukan panduan integrasi aktif.

## Step 8.6 — bahan terkonfirmasi dan menu strict

Detail resep kini memiliki checklist otomatis berdasarkan stok awal. Perubahan disimpan per alur pencarian, tanpa memanggil Gemini. Tombol Cari Menu dari Bahan yang Ada mengirim hanya bahan yang dicentang ke mode STRICT_AVAILABLE_INGREDIENTS; backend menyaring resep dengan tambahan wajib dan menampilkan0–3hasil valid. Mode normal tetap tiga resep diurutkan tanpa kuota tambahan. Cache dibedakan per mode (prompt6/schema4); favorit dan riwayat mempertahankan layanan yang ada. Takaran stok belum dicatat. Rincian dan daftar file ada di [docs/STEP_8_6.md](docs/STEP_8_6.md).

## Login Google (Socialite 5.31.0)

Source tersedia, tetapi belum diuji melalui OAuth asli. Tanpa konfigurasi lengkap tombol Google tetap nonaktif. Email/password + OTP tetap menjadi metode terpisah; akun Google tidak otomatis digabung berdasarkan email dan tidak dapat memakai reset password lokal. Account linking terautentikasi belum tersedia: jika email sudah dipakai, masuk dengan metode sebelumnya.

Persiapan manual Google Cloud Console:

1. Pilih project milik sendiri, siapkan Google Auth Platform / consent screen (nama aplikasi, email dukungan, audience). Jika masih berstatus Testing, daftarkan akun penguji pada Test users.
2. Buat OAuth Client tipe **Web application**. Gunakan scope `openid`, `email`, dan `profile`; tidak perlu scope Gmail atau akses offline.
3. Tambahkan **Authorized redirect URI** yang cocok persis, misalnya `http://127.0.0.1:8000/auth/google/callback`. Jangan mengganti `127.0.0.1` dengan `localhost` di tengah alur. Path, scheme, host, port dan trailing slash harus konsisten. Login server-side ini tidak memerlukan Google JavaScript SDK.
4. Isi `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, dan `GOOGLE_REDIRECT_URI` sendiri dalam `.env` lokal. Jangan commit secret. `APP_URL` harus menggunakan origin yang sama dengan redirect URI (contoh `http://127.0.0.1:8000`). Source tidak mengubah `.env` otomatis.
5. Jika konfigurasi pernah di-cache, bersihkan/perbarui config cache pada tahap penerapan yang disetujui. Di production wajib HTTPS; HTTP hanya diizinkan pada loopback lokal. Cookie session harus mendukung redirect balik (`SameSite=Lax`), HttpOnly, serta Secure pada HTTPS. Jangan merekam query callback, authorization code, atau token pada access/debug log.

Route masuk: `GET /auth/google/redirect`. Route callback: `GET /auth/google/callback`. State session hanya berlaku 10 menit dan dikonsumsi Socialite; tidak menggunakan `stateless()`. GoogleProvider menukar code dan mengambil userinfo melalui HTTPS. Backend memeriksa subject, email, dan `email_verified === true`; data identitas dari browser tidak dipakai. Access/refresh token tidak disimpan. Transaksi menyimpan user terverifikasi beserta `social_accounts(provider, provider_subject, user_id)` dengan unique constraint yang sudah ada. Session diregenerasi; cookie dan ledger kuota guest tetap dipertahankan.

Tombol aktif hanya membuktikan konfigurasi lokal memenuhi pemeriksaan bentuk/origin, **bukan** membuktikan Client Secret diterima Google. Setelah mendapat izin pengujian, verifikasi login baru/ulang, penolakan email bentrok, state salah/kedaluwarsa, pembatalan consent, logout, dan isolasi favorit/riwayat. Gunakan pengujian terisolasi untuk skenario race sebelum penggunaan production.
