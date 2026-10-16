# Progres Dapur Rantau

> Status terkini: **Step 5 integrasi Google Gemini selesai secara implementasi dan HTTP fake**. Lihat bagian “Pembaruan Step 5” di akhir dokumen. Bagian Step 1–4 berikut dipertahankan sebagai riwayat dan tidak menggambarkan status terbaru integrasi AI.

Terakhir diperbarui: **8 Oktober 2026 (Asia/Jakarta)**.
Direktori proyek: `/Applications/MAMP/htdocs/Dapur Rantau`.

Dokumen ini adalah catatan serah terima untuk sesi Codex berikutnya. Pembaruan ini hanya membuat PROGRESS.md; tidak mengubah kode aplikasi atau menjalankan ulang pengujian. Hasil pengujian di bawah berasal dari tahap implementasi sebelumnya.

## 1. Tahap pengembangan saat ini

**Tahap input bahan interaktif dan validasi backend sudah selesai.**

Tahapan yang telah dikerjakan:
1. Analisis environment, rancangan arsitektur, dan dokumentasi awal.
2. Setup Laravel 13, Tailwind CSS 4, Vite, SQLite, serta migration bawaan.
3. Landing page responsif dengan Blade Components.
4. Input chip bahan terhubung ke backend Laravel dan halaman konfirmasi data tervalidasi.

Pekerjaan saat ini adalah pencatatan progres. **Jangan langsung melanjutkan integrasi AI atau tahap lain tanpa instruksi pengguna berikutnya.**

## 2. Fitur yang sudah selesai

### Fondasi proyek
- Laravel **13.35.0**, PHP CLI **8.3.13**, Composer **2.8.12** pada pemeriksaan terakhir.
- Blade, Vanilla JavaScript, Tailwind CSS **4.3.3**, `@tailwindcss/vite` **4.3.3**, Vite **8.3.3**, dan `laravel-vite-plugin` **3.2.0**.
- CSS dan JavaScript dimuat melalui `@vite`; tanpa CDN Tailwind, React, Vue, atau Inertia.
- SQLite development: `database/database.sqlite`. Ketiga migration bawaan Laravel sudah berjalan: users, cache, jobs (beserta tabel lain yang dibuat migration tersebut).
- Nama aplikasi Dapur Rantau, locale `id`, URL development `http://localhost:8000`.
- Model/tabel User bawaan tetap tersedia; tidak ada fitur atau route authentication.

### Landing page
- Navbar desktop/mobile, hero, input bahan, tiga langkah penggunaan, kartu contoh resep, CTA, dan footer.
- Warna utama hijau `#427A54`, krem `#FFF8EB`, oranye `#F2A65A`, teks `#25332B`.
- Komponen Blade untuk brand, button, icon, recipe-card, dan ingredient-input.
- Foto ilustrasi tersimpan lokal; kartu diberi label **Contoh tampilan**, bukan hasil AI.
- Focus/hover/loading state, label form, status aria-live, skip link, dialog native, serta dukungan reduced motion.
- Menu Favorit membuka dialog bahwa fitur belum tersedia; belum menyimpan data.

### Ingredient input
- Enter atau tombol tambah mengubah input menjadi chip.
- Beberapa bahan sekaligus dipisahkan koma; satu batch yang tidak valid ditolak seluruhnya.
- Normalisasi huruf kecil, trim, dan spasi berlebih; duplikat ditolak.
- Chip dapat dihapus, jumlah bahan ditampilkan, dan tombol submit nonaktif saat tidak ada bahan.
- Chip disinkronkan ke hidden input `ingredients[]`; render menggunakan DOM API dan `textContent`, bukan `innerHTML`.
- Batas aktual **1–15 bahan**, tiap bahan string maksimal **60 karakter**.
- Porsi opsional: integer **1–10**.
- Preferensi opsional: `bebas`, `hemat`, `cepat`, `pedas`.
- Alergi/bahan yang dihindari: teks opsional maksimal **600 karakter**, dengan koma sebagai pemisah untuk pengguna; belum diparsing menjadi array.
- Nama bahan menerima huruf Unicode, angka, spasi, titik, petik, kurung, dan tanda hubung. Markup/script dan karakter di luar aturan ditolak.
- `GenerateRecipeRequest` memisahkan normalisasi dan validasi dari Controller; pesan error Bahasa Indonesia.
- `POST /rekomendasi` memakai middleware `web` dan CSRF Laravel.
- `RecommendationController@store` hanya menerima `$request->validated()` dan menampilkan halaman konfirmasi; siap diperluas ke Service Layer.
- Error mengembalikan form beserta input lama. Chip dipulihkan dari hidden input yang di-escape Blade.
- Tidak ada panggilan API, hasil AI palsu, atau penyimpanan data bisnis ke database.

### Alur yang bekerja

`GET /` → chip/opsi → POST `/rekomendasi` + CSRF → `GenerateRecipeRequest` → `RecommendationController` → `recommendations.preview`.

Request tidak valid kembali ke halaman asal dengan pesan error dan old input. Respons sukses saat ini langsung berupa view atas POST, belum memakai POST–redirect–GET.

## 3. Fitur yang belum selesai

- Integrasi xAI Grok Responses API, konfigurasi kredensial/model, dan Service Layer AI.
- Kontrak JSON resep, validasi output provider, timeout, penanganan kegagalan, dan limiter generasi.
- Perhitungan kecocokan bahan dan pengurutan resep.
- Model/migration bisnis: IngredientSearch, Recipe, Favorite.
- Identitas tamu dan pembatasan kepemilikan data.
- Penyimpanan pencarian/resep, halaman hasil rekomendasi nyata, dan detail langkah memasak.
- Favorit nyata dan riwayat pencarian.
- Pemakaian porsi/preferensi/alergi pada prompt dan hasil AI.
- Pengujian database MySQL, konfigurasi web MAMP, dan pengujian menyeluruh setelah integrasi.

Authentication, admin, pembayaran, dan fitur lain di luar permintaan belum termasuk cakupan MVP yang disepakati.

## 4. File yang sudah dibuat atau diubah

Daftar ini merangkum file utama lintas tahap, bukan diff Git. Scaffold standar Laravel beserta vendor/node_modules telah diinstal sebelumnya.

| Area | File utama |
| --- | --- |
| Dokumentasi | `AGENTS.md`, `docs/arsitektur.md`, `docs/landing-page.md`, `README.md`, `PROGRESS.md` |
| Dependency/build | `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `vite.config.js`, `.nvmrc`, `.gitignore` |
| Environment | `.env`, `.env.example`, `config/app.php`, `database/database.sqlite` |
| Controller | `app/Http/Controllers/HomeController.php`, `app/Http/Controllers/RecommendationController.php` |
| Request | `app/Http/Requests/GenerateRecipeRequest.php` |
| Route | `routes/web.php` |
| Halaman | `resources/views/layouts/app.blade.php`, `resources/views/home.blade.php`, `resources/views/recommendations/preview.blade.php` |
| Komponen | `resources/views/components/brand.blade.php`, `button.blade.php`, `icon.blade.php`, `recipe-card.blade.php`, `ingredient-input.blade.php` |
| Frontend | `resources/css/app.css`, `resources/js/app.js`, `resources/js/ingredient-input.js` |
| Foto | `public/images/nasi-rumahan.jpg`, `telur-sederhana.jpg`, `sayur-segar.jpg` |
| Test baru | `tests/Feature/GenerateRecipeTest.php` |
| Test bawaan | `tests/Feature/ExampleTest.php`, `tests/Unit/ExampleTest.php` |
| Build lokal | `public/build/manifest.json`, aset CSS/JS dengan nama hash; dihasilkan Vite dan diabaikan Git |

`resources/views/welcome.blade.php` bawaan masih ada tetapi tidak digunakan route homepage. Tidak ada migration bisnis tambahan. Jangan commit `.env`, database lokal, `.tools`, vendor, atau node_modules.

## 5. Pengujian dan hasil terakhir

| Pengujian | Hasil |
| --- | --- |
| `php artisan test` setelah ingredient input | **12 test, 47 assertion lulus**: 10 Feature Test baru + 2 test bawaan |
| `vendor/bin/pint --test` | Lulus |
| `npm run build` terakhir | Berhasil; 4 modul, CSS sekitar 55,19 kB, JS sekitar 3,62 kB sebelum gzip |
| `php artisan route:list --path=rekomendasi -v` | POST `/rekomendasi`, nama `recommendations.store`, Controller dan middleware `web` benar |
| `php artisan migrate:status` pada setup | Ketiga migration bawaan selesai |
| `composer check-platform-reqs` pada setup | Lulus pada PHP CLI 8.3.13 |
| `composer validate --no-check-publish` pada setup | Lulus |
| `php artisan view:cache` pada tahap landing | Berhasil; compiled views kemudian dibersihkan |
| Audit npm pada setup | 0 kerentanan setelah dependency alat proses paralel yang tidak diperlukan dihapus; belum diaudit ulang pada pembaruan dokumentasi ini |
| HTTP homepage, CSS/JS hasil build, foto pada tahap landing | HTTP 200 |
| POST lokal tanpa token CSRF setelah ingredient input | **HTTP 419**, ditolak sebagaimana diharapkan |

Feature Test mencakup bahan kosong, satu/multiple bahan, normalisasi, duplikat, karakter berbahaya dan escaping old input, batas 15/16 bahan, payload setelah penghapusan, tipe/format/panjang tidak valid, pilihan opsional, dan pemulihan form setelah error. `Http::preventStrayRequests()` mencegah panggilan eksternal tak disengaja; tidak ada API yang dipanggil.

Pemeriksaan browser manual:
- Desktop dan mobile 390px pada tahap landing; menu mobile bekerja dan tidak ada overflow horizontal setelah perbaikan navbar.
- Pada tahap input: tambah tiga bahan sekaligus, tolak duplikat, hapus nasi sebelum submit → backend hanya menerima telur dan bawang merah.
- Porsi/preferensi/alergi tampil sesuai di halaman konfirmasi.
- Batch 16 bahan dan markup `<script>` ditolak di frontend.
- Porsi 11 ditolak server; error Indonesia terlihat dan chip telur kembali terisi.
- Menghapus chip terakhir membuat tombol Cari Ide Masakan nonaktif.
- Submit satu bahan menampilkan konfirmasi yang benar.

## 6. Error, kendala, dan batasan yang tersisa

Tidak ada kegagalan test/build yang belum terselesaikan pada hasil terakhir. Namun:

1. **Node global belum kompatibel:** versi global terakhir 22.10.0. Node resmi 22.23.3 dan npm 10.9.9 tersedia di `.tools/node-v22.23.3-darwin-arm64/`, checksum diverifikasi. Gunakan PATH lokal atau nvm; jangan mengandalkan Node global untuk Vite.
2. **PHP web MAMP belum diverifikasi:** PHP CLI berasal dari Herd. Localhost telah diuji melalui Artisan, bukan konfigurasi Apache MAMP. Untuk MAMP, document root harus `public/` dan PHP web minimal 8.3.
3. **AGENTS.md berbeda dari versi awal:** saat pemeriksaan terakhir, isinya bootstrap Laravel Boost, bukan aturan proyek Indonesia yang dibuat pada tahap analisis. Penyebab/perubahan asal belum ditentukan. Boost tidak dipasang pada tahap UI/input karena instruksi pengguna sebelumnya membatasi dependency yang tidak diperlukan. Baca file aktual dan instruksi pengguna sebelum tindakan berikutnya; jangan menganggap aturan lama masih berada di sana.
4. **Dokumen arsitektur adalah rancangan awal:** menyebut `GenerateRecipesRequest` (jamak) dan batas 20 bahan. Implementasi mengikuti permintaan terbaru: **GenerateRecipeRequest** (tunggal), **15 bahan**, ditambah porsi/preferensi/alergi. Nama dan batas aktual ini menjadi acuan kelanjutan.
5. **Konfirmasi sementara:** belum ada resep nyata; refresh halaman hasil POST dapat meminta submit ulang. Tombol kembali membuka form baru; pemulihan otomatis diwajibkan dan tersedia untuk kegagalan validasi, bukan penyimpanan lintas sesi.
6. **JavaScript diperlukan** untuk mengelola chip; pesan noscript tersedia. Tampilan mobile input dengan opsi baru belum diuji selengkap tahap landing.
7. **State server lokal tidak dijamin lintas sesi:** periksa server Artisan/Vite dan `public/hot` sebelum menguji. Jangan menghentikan proses milik pengguna sembarangan. Hot reload selama development dapat mengosongkan chip atau old input setelah halaman dimuat ulang.
8. Kredensial/model/kuota xAI belum diuji. Data alergi baru divalidasi sebagai input, belum digunakan untuk menyaring resep atau menilai keamanan hidangan.

Catatan untuk test selanjutnya: pada konfigurasi session JSON Laravel ini, rangkaian assertion session lalu request kedua dalam instance test yang sama sempat membuat error bag tidak tampil pada test. Test redirect saat ini menggunakan `followingRedirects()` untuk menguji pesan error dan old input end-to-end; jalur browser nyata juga sudah diverifikasi. Tidak ada perubahan vendor atau penurunan pengamanan session.

## 7. Langkah berikutnya

**Tunggu instruksi pengguna untuk tahap selanjutnya.** Saat diizinkan melanjutkan:

1. Baca dokumen ini, README, kode aktual, AGENTS.md, dan rancangan arsitektur; gunakan batas/nama terbaru di atas.
2. Selaraskan rancangan request/input dengan kontrak Service Layer, termasuk porsi, preferensi, dan alergi, sebelum integrasi AI.
3. Implementasikan fondasi data dan identitas tamu sesuai tahap yang disetujui sebelum menyimpan pencarian/resep.
4. Implementasikan GrokRecipeService melalui Laravel HTTP Client dan Responses API, dengan konfigurasi server, validasi output terstruktur, timeout, serta penanganan error. Jangan menaruh logika API di Controller atau menampilkan data dummy sebagai hasil provider.
5. Tambahkan pengujian menggunakan `Http::fake()`; jangan menjalankan panggilan berbayar dalam test otomatis. Uji nyata hanya setelah kredensial dan akses model tersedia serta sesuai instruksi pengguna.
6. Lanjutkan hasil/detail resep, kecocokan bahan, favorit, dan riwayat secara bertahap; perbarui PROGRESS.md setelah tiap tahap.

### Perintah melanjutkan development

Jalankan dari direktori proyek:

```sh
php artisan serve --host=127.0.0.1 --port=8000
```

Terminal frontend terpisah pada mesin macOS Apple Silicon ini:

```sh
export PATH="$PWD/.tools/node-v22.23.3-darwin-arm64/bin:$PATH"
npm run dev -- --host 127.0.0.1
```

Alternatif dengan nvm yang tersedia: `nvm install` lalu `nvm use`, mengikuti `.nvmrc`.

```sh
php artisan test
vendor/bin/pint --test
npm run build
```

URL utama: `http://localhost:8000/#cari-resep`. Route konfirmasi hanya menerima POST dari form, bukan GET langsung.


---

## Pembaruan Step 5 — Google Gemini (8 Oktober 2026)

### Tahap dan fitur selesai

Step 5 sudah diimplementasikan, tanpa melanjutkan Step 6/7. Provider aktif diganti menjadi Google Gemini; tidak ada kode integrasi Grok/xAI. Landing page dan kontrak input Step 4 dipertahankan. Tidak ada dependency baru, authentication, billing, atau layanan berbayar yang diaktifkan.

- `config/ai.php` dan `.env.example`: model gemini-3.5-flash-lite, REST generateContent, key melalui header server, thinking MINIMAL, batas output 3000 token.
- GeminiRecipeService dengan dependency injection, system instruction ringkas, input JSON satu kali, schema resmi, parsing text parts non-thought, finishReason STOP/safety checks, dan validasi tipe/field defensif.
- Maksimal 3 resep/8 langkah; output tidak lengkap, rusak, terpotong, kosong tanpa objek valid, atau konflik alergen literal ditolak. Array resep kosong dengan penjelasan valid diterima. Tidak ada fallback data contoh.
- Cache backend mengikuti driver database/file, TTL default 24 jam, toggle environment. SHA-256 atas input kanonis mencakup bahan/porsi/preferensi/alergi/model/versi prompt-schema/thinking/output limit. Cache hit tidak memanggil API, cache rusak dihapus, respons gagal tidak disimpan.
- Lock key + pemeriksaan cache ulang mencegah request identik serentak. Lock per IP mencegah concurrent request dengan input berbeda; lease 130 detik dan release finally. Driver tanpa lock gagal tertutup.
- RateLimiter 5 request/menit/IP, berlaku juga untuk cache hit dan input tidak valid. IP di-hash; NAT berbagi batas.
- Timeout koneksi 5 detik, request 30 detik; maksimal satu retry tambahan hanya HTTP 500/503 dengan Retry-After pendek yang dihormati. Tidak retry 429/kuota harian, timeout, koneksi gagal, akses/model salah, atau JSON rusak.
- Log aman untuk model, status HTTP, attempt, durasi, cache hit/miss/disabled, status validasi, dan prompt/candidates/thoughts/total token opsional. Tidak mencatat key, prompt, body mentah, bahan, atau alergi.
- Controller memanggil service menggunakan validated data. HTML menampilkan hasil sementara di Blade; Accept JSON mendapat message/recipes. Error Indonesia aman dengan status 429/503 dan input dipertahankan untuk kembali. Landing hanya mengubah teks bantuan/loading yang sebelumnya menyebut AI belum aktif.
- Template hasil mempertahankan ringkasan input dan menampilkan judul, waktu, porsi, kesulitan, bahan/takaran, langkah, tips. Bukan desain lengkap Step 6.

### File dibuat/diubah pada Step 5

Baru:
- `config/ai.php`
- `app/Services/GeminiRecipeService.php`
- `app/Support/RecipeCacheKey.php`
- `app/Support/RecipeFailureResponse.php`
- `app/Exceptions/RecipeGenerationException.php`
- `app/Http/Middleware/LimitConcurrentRecipes.php`
- `resources/views/recommendations/error.blade.php`
- `tests/Support/GeminiFixture.php`
- `tests/Unit/RecipeCacheKeyTest.php`
- `tests/Feature/GeminiRecipeServiceTest.php`
- `tests/Feature/GeminiRecommendationTest.php`
- `tests/Feature/GeminiDatabaseCacheTest.php`

Diubah:
- `.env.example`
- `app/Http/Controllers/RecommendationController.php`
- `app/Providers/AppServiceProvider.php`
- `routes/web.php`
- `resources/views/recommendations/preview.blade.php`
- `resources/views/components/ingredient-input.blade.php`
- `resources/js/ingredient-input.js`
- `tests/Feature/GenerateRecipeTest.php` (validasi Step 4 tetap diuji dengan mock service, limiter diuji terpisah)
- `README.md`, `docs/arsitektur.md`, `docs/landing-page.md`, `PROGRESS.md`
- Aset `public/build/` dihasilkan ulang, bukan source yang perlu di-commit.

Tidak ada perubahan dependency, migration, atau kredensial `.env` lokal.

### Pengujian berhasil

- `php artisan test`: **40 test, 191 assertion lulus**.
- `vendor/bin/pint`: lulus.
- Unit cache key: normalisasi, deduplikasi, urutan, batas antar-item, porsi, preferensi, alergi, model, dan versi.
- Service Http::fake: payload REST, schema, 3000 token/MINIMAL, JSON valid/rusak/kosong/terpotong, safety block, tipe boolean/integer ketat, field wajib/tambahan, jumlah resep/langkah, timeout, 400/401/403/404/429/500/503, Retry-After, metadata token parsial/hilang, multipart text, alergen eksplisit.
- Cache: hit/miss, TTL, disabled, tidak menyimpan error, penghapusan cache rusak, lock sibuk, release setelah gagal. Test SQLite :memory: memverifikasi tabel cache/lock dengan migration bawaan; database development tidak dipakai oleh test.
- Feature: HTML/JSON valid, input kosong, rate limit request ke-6, concurrent IP ditolak, escaping output HTML, key tidak bocor, input error dipertahankan.
- HTTP localhost dengan sesi/CSRF: POST tanpa key mendapat 503 dan pesan Indonesia yang benar, tanpa detail teknis/stack trace. Tidak memanggil live generation.

### Kegagalan selama pengerjaan

Pengujian awal mengalami 6 kegagalan karena pemanggilan `Http::fake()` berulang dalam satu test menumpuk callback, sehingga skenario berikutnya menerima respons fake lama. Fixture kini mereset HTTP factory dan mengaktifkan preventStrayRequests setiap skenario. Satu assertion log negatif juga diperbaiki menjadi pemeriksaan semua konteks log aman. **Tidak ada test gagal tersisa pada run akhir.**

### Kendala/batasan yang masih ada

1. **Live Gemini belum diverifikasi.** API key lokal belum dikonfigurasi; seluruh keberhasilan generasi menggunakan Http::fake. Tidak ada kuota generation sungguhan yang dipakai.
2. Model gemini-3.5-flash-lite dan MINIMAL didukung dokumentasi resmi yang diperiksa. Ketersediaan project dan alternatif yang benar-benar dapat diakses belum bisa ditentukan tanpa key. Tidak ada pergantian model otomatis. Setelah key tersedia, periksa daftar model project sebelum uji generation terkontrol.
3. Browser tidak tersedia untuk pemeriksaan visual ulang Step 5; alur HTTP/HTML/JSON diuji otomatis dan melalui localhost. Pemeriksaan visual landing sebelumnya tetap menjadi catatan historis.
4. Cache DB/file dan aturan limiter membutuhkan storage yang dapat ditulis. IP yang sama di NAT berbagi batas. Trusted proxy perlu dikonfigurasi sesuai deployment, bukan mempercayai header arbitrer.
5. Deteksi alergen tambahan hanya literal dan konservatif; tidak menjamin sinonim/turunan atau keamanan medis. Provider diinstruksikan menghindari alergen, dan view meminta pemeriksaan pengguna.
6. Respons sukses masih view POST; refresh bisa meminta submit ulang, dilindungi cache/limiter. Belum ada detail/favorit/riwayat atau penyimpanan tabel bisnis.
7. Node global/MAMP dan perbedaan AGENTS.md tetap sebagaimana catatan sebelumnya. Tidak memasang Boost karena tidak diperlukan untuk permintaan ini dan pengguna melarang package yang tidak diperlukan.

### Langkah berikutnya

- Isi GEMINI_API_KEY secara lokal sesuai README, lalu `php artisan config:clear`; jangan commit key atau memindahkannya ke frontend.
- Verifikasi akses model project dan lakukan paling banyak satu smoke generation terkontrol bila diperlukan. Jangan mengaktifkan billing atau mengganti model diam-diam.
- Backend siap menjadi dasar Step 6 setelah pengguna memberi instruksi; desain hasil lengkap, favorit, dan riwayat belum dikerjakan.
- Lanjutkan memakai `Http::fake()` untuk test otomatis dan perbarui catatan progres setiap tahap.

Validasi akhir Step 5 tambahan: `php artisan route:list` berhasil (5 route termasuk homepage dan POST rekomendasi); `npm run build` berhasil (CSS 55,84 kB, JS 3,63 kB sebelum gzip); `php artisan view:cache` berhasil dan cache view dibersihkan kembali. Tidak ada kegagalan build tersisa.

---

## Verifikasi live Gemini — 9 Oktober 2026

Dilakukan atas permintaan pengguna setelah GEMINI_API_KEY diisi. Tidak ada perubahan kode atau arsitektur aplikasi. Cache konfigurasi Laravel dibersihkan agar .env terbaru terbaca; nilai API key tidak ditampilkan, disalin ke dokumentasi, atau dicatat ke log.

Hasil:
- Laravel membaca key sebagai terkonfigurasi. Model aktif tetap `gemini-3.5-flash-lite`; cache database aktif dengan TTL 86400 detik.
- Percobaan metadata awal mengalami ConnectionException sebelum respons. Percobaan metadata berikutnya mendapatkan **HTTP 200** dan mencantumkan dukungan `generateContent`: model tersedia bagi key ini.
- Uji koneksi generation sederhana (`Balas hanya: OK`, maksimal 64 output token) mendapat **HTTP 503 UNAVAILABLE**. Pesan Google menyatakan model sedang mengalami permintaan tinggi. Tidak diulang.
- Satu request resep melalui GeminiRecipeService dengan `telur, nasi, cabai`, 1 porsi, preferensi bebas, tanpa alergi mendapat **HTTP 400 INVALID_ARGUMENT**: `Request contains an invalid argument.` Tidak ada detail penyebab field yang dapat dipastikan dari hasil pemeriksaan ini.
- Tidak ada structured JSON resep yang berhasil diterima/divalidasi. Cache untuk pencarian tersebut sebelumnya kosong dan tetap kosong setelah kegagalan: error tidak disimpan.
- Key untuk urutan `telur, nasi, cabai` dan `cabai, telur, nasi` terbukti sama. Cache database berhasil diuji baca/tulis memakai probe sementara yang sudah dihapus.
- Cache hit dengan hasil live belum dapat dibuktikan karena generasi resep gagal. Pencarian kedua tidak dikirim ke provider agar tidak menambah kuota; pemeriksaan key/cache dilakukan lokal tanpa HTTP eksternal.

Batas penggunaan: **2 request generation sungguhan total** (1 sederhana, 1 resep). Selain itu ada 2 percobaan GET metadata (1 gagal koneksi, 1 HTTP 200), tanpa generation. Retry generation tambahan dicegah oleh guard pada skrip pemeriksaan sementara; implementasi retry aplikasi tidak diubah. Penggunaan token aktual tidak diketahui karena kedua respons gagal tidak menyertakan usageMetadata.

Status: Step 5 lulus pengujian otomatis sebelumnya, tetapi **belum lulus verifikasi live dan belum dinyatakan siap melanjutkan Step 6**. Ketersediaan key/model sudah terbukti; perlu diagnosis kompatibilitas payload generateContent (schema/thinking/config) terhadap model untuk menyelesaikan HTTP 400, kemudian satu pengujian live terkontrol dan pembuktian cache hit. HTTP 503 pada uji sederhana merupakan gangguan kapasitas provider. Jangan menyimpulkan bahwa API key salah atau mengganti model tanpa bukti/instruksi.

---

## Debugging Step 5 — 9 Oktober 2026 (hasil terbaru)

Status: **belum selesai; HTTP 400 pada payload resep lengkap masih menjadi penghalang Step 6**. Catatan ini memperbarui status verifikasi sebelumnya, tanpa menghapus riwayat.

### Diagnosis dan bukti

- Konfigurasi key terbaca Laravel (hanya boolean diperiksa), model tetap `gemini-3.5-flash-lite`, database cache aktif, TTL 86400 detik.
- Dokumentasi resmi GenerateContent dan structured output diperiksa. Dokumentasi thinking GenerateContent menyatakan MINIMAL didukung Gemini 3.5 Flash-Lite. Belum ada bukti bahwa thinking/schema tertentu merupakan field penyebab, sehingga tidak mengganti model atau parameter produksi secara spekulatif.
- Payload minimal diuji offline dengan Http::fake sebelum live: system instruction singkat, satu input, responseMimeType application/json, responseJsonSchema root message/string dan recipes/array string maksimal 3, maxOutputTokens 128; tanpa thinkingConfig. Tidak meminta resep pada probe ini.
- **Satu request minimal live berhasil**, finishReason STOP, JSON message/string dan recipes kosong sesuai instruksi valid. Ini membuktikan akses key/model dan structured output sederhana bekerja saat pengujian.
- **Satu request resep lengkap live** melalui service dengan telur, nasi, cabai, porsi 1, preferensi bebas, tanpa alergi tetap mendapat HTTP 400, error.code=400, error.status=INVALID_ARGUMENT, error.message="Request contains an invalid argument." Respons tidak menyediakan rincian field penyebab. Tidak ada nama parameter ditolak yang bisa dibuktikan.
- Perbedaan probe minimal dan payload penuh meliputi kompleksitas schema, isi instruksi/input, thinkingConfig dan batas token. Keberhasilan probe tidak cukup untuk mengisolasi penyebab ke salah satunya. HTTP 400 belum diperbaiki dan tidak boleh diklaim berasal dari parameter tertentu.
- HTTP 503 pada pengujian sebelumnya adalah high demand provider; tidak terjadi pada dua respons live sesi debugging ini.
- Batas dipatuhi: **2 request generation live pada sesi debugging ini**, tidak ada retry live atau GET metadata tambahan. Tidak menampilkan key/full prompt/input sensitif dalam diagnostik. Seluruh test otomatis memakai fake.

### Perubahan selesai / file diubah

- `app/Support/GeminiErrorDiagnostics.php` (baru): konteks aman http_status/error.code/status; pesan hanya frasa diagnostik yang diizinkan; detail hanya tipe google.rpc yang diizinkan; daftar nama field konfigurasi yang disebut provider. Pesan bebas, metadata project, nilai input, dan rincian yang berpotensi sensitif tidak dicatat. Daftar referenced_fields tidak otomatis berarti field ditolak.
- `app/Services/GeminiRecipeService.php`: log gemini.error yang disaring; status RESOURCE_EXHAUSTED atau detail QuotaFailure diperlakukan sebagai quota, termasuk bila HTTP 503, sehingga tidak di-retry. Retry 500/503 lain tetap maksimal satu tambahan dengan Retry-After maksimal 2 detik; delay lebih panjang tidak di-retry dalam worker.
- `app/Exceptions/RecipeGenerationException.php`: pesan gangguan provider/timeout menjadi "Maaf, layanan rekomendasi sedang mengalami gangguan. Silakan coba kembali nanti." Pesan quota dan error lain tetap aman.
- `tests/Feature/GeminiDiagnosticsTest.php` (baru): penyaringan rahasia/data pengguna, quota pada 503 tanpa retry, pesan gangguan.
- `tests/Feature/GeminiDatabaseCacheTest.php`: pengujian bahan telur/nasi/cabai dan urutan terbalik pada SQLite test, lalu mengganti HTTP fake dengan callback yang melempar exception pada request apa pun; assertNothingSent membuktikan cache hit tanpa HTTP.
- `PROGRESS.md`: catatan ini.

Tidak mengubah parameter payload produksi, .env, model, UI, kontrak input, arsitektur, dependency, schema/validasi output, atau versi cache. Skrip probe sementara berada di /private/tmp, bukan bagian aplikasi.

### Hasil pengujian

- `php artisan test`: **43 passed, 0 failed, 200 assertions**.
- `php artisan route:list`: berhasil, 5 route termasuk POST rekomendasi.
- Pint pada kelima file PHP yang disentuh: lulus.
- Fake minimal payload: lulus sebelum live.
- Structured JSON minimal live: berhasil; structured JSON resep penuh live: gagal (HTTP 400 sebelum output resep).
- Cache miss/hit: lulus dengan Http::fake dan database SQLite test. Urutan berbeda mengakses cache tanpa HTTP, diuji dengan penolakan semua request.
- Cache production: key urutan berbeda sama; setelah HTTP 400, cache tetap kosong. Error tidak tersimpan. Cache hit untuk hasil resep **live belum terverifikasi**, karena belum ada hasil resep live valid. Tidak menanam fixture ke cache development untuk mengesankan keberhasilan live.
- npm run build tidak dijalankan pada sesi ini karena tidak ada perubahan frontend (sesuai instruksi debugging). Build sebelumnya tetap tercatat di atas.
- Percobaan awal skrip sementara mengalami syntax error sebelum request, lalu diperbaiki dan lolos fake sebelum live. Pint --dirty tidak berlaku karena direktori bukan Git; validasi memakai path file eksplisit. Tidak ada kegagalan automated test tersisa.

### Kendala dan langkah berikutnya

1. HTTP 400 payload penuh belum memberikan error.details. Logging aman kini tersedia untuk diagnosis berikutnya.
2. Lanjutkan isolasi perbedaan payload secara terkontrol pada sesi berikutnya: satu variabel per eksperimen (misalnya schema penuh dengan pengaturan generation probe), gunakan batas request baru yang jelas; jangan mengulangi request penuh yang sama atau mengganti model tanpa bukti.
3. Setelah perubahan terbukti, jalankan test fake regresi, satu uji resep live terbatas, kemudian cache hit dengan HTTP diblokir. Jangan mengklaim hasil fake sebagai hasil Gemini.
4. **Jangan lanjut Step 6 sebelum rekomendasi penuh dan cache hasil live berhasil diverifikasi.**

Referensi: https://ai.google.dev/api/generate-content ; https://ai.google.dev/gemini-api/docs/structured-output ; https://ai.google.dev/gemini-api/docs/generate-content/thinking .

---

## Step 5 — HTTP 400 diperbaiki dan live berhasil (9 Oktober 2026)

**Status terbaru: Step 5 selesai dan siap menjadi dasar Step 6. Step 6 belum dikerjakan.** Catatan ini menggantikan status belum selesai pada bagian sebelumnya; riwayat kegagalan dipertahankan.

### Bukti diagnosis dan perbaikan

1. Satu probe resep penuh dengan hanya thinkingConfig dihilangkan tetap mendapat HTTP 400 INVALID_ARGUMENT. Tidak ada retry. Jadi penghilangan thinkingConfig tidak memperbaiki kasus ini; konfigurasi produksi tetap MINIMAL.
2. Schema generation kemudian disederhanakan: minimum/maximum pada cook_time_minutes dan servings, serta minItems/maxItems pada ingredients dan steps tidak dikirim ke provider. Bentuk object, seluruh field wajib, tipe data, enum difficulty, additionalProperties=false dan recipes.maxItems=3 tetap dikirim.
3. **Seluruh constraint semula tetap ada pada buildResponseSchema()/validateResponse() backend**, termasuk waktu 1–1440, porsi 1–10, bahan 1–30, langkah 1–8, field persis, string nonkosong, tipe integer/boolean ketat. Respons melanggar batas tetap ditolak dan tidak di-cache. Tidak beralih ke JSON bebas atau fallback contoh.
4. Dengan schema generation tersebut, prompt, model, input, thinking MINIMAL dan batas output 3000 tetap sama, satu request live resep berhasil **HTTP 200**. Hasil: 1 resep **Nasi Goreng Telur Cabai**. Respons lolos validasi ketat dan tersimpan ke database cache.
5. Bukti mengisolasi perbaikan pada kelompok constraint schema pengiriman, bukan key/model atau penghapusan thinking. Dugaan mekanismenya adalah kompleksitas constraint provider, selaras dokumentasi Google bahwa schema kompleks dapat ditolak. Google tidak memberi error.details, sehingga keyword tunggal penyebab atau batas internal pasti tidak diklaim telah diketahui. Tidak memakai kuota tambahan untuk membuktikan masing-masing keyword.

### Cache dan alur aplikasi

- Input pertama telur/nasi/cabai, porsi 1, bebas, tanpa alergi: cache miss, tepat 1 request Gemini sukses, JSON tervalidasi tersimpan, TTL 86400 detik.
- Input kedua cabai/telur/nasi: key sama dan hasil identik. HTTP factory diganti fake yang melempar exception untuk setiap request; assertNothingSent lulus. **0 panggilan Gemini tambahan**, bukan sekadar membandingkan key.
- Uji tambahan melalui HTTP Kernel Laravel (tanpa server eksternal): GET landing 200, ambil CSRF/session cookie, POST /rekomendasi 200, Blade menampilkan resep live yang sudah tersimpan. Semua outgoing HTTP diblokir dengan fake; tidak ada request Gemini. Ini memeriksa route, middleware, CSRF, Form Request, Controller, service/cache, dan view secara terhubung.
- Cache schema_version dinaikkan dari 1 ke 2, sehingga hasil konfigurasi lama tidak tercampur. Tidak menghapus seluruh cache atau database.
- Key tetap hanya di backend. Tidak mengubah .env atau menampilkan key.

### File dibuat/diubah pada perbaikan lanjutan ini

- `app/Services/GeminiRecipeService.php`: buildGenerationSchema terpisah dari kontrak validasi ketat; request memakai schema generation ringkas.
- `config/ai.php`: schema_version=2.
- `tests/Feature/GeminiRecipeServiceTest.php`: regresi payload ringkas dan validasi batas backend untuk waktu/porsi serta panjang bahan/langkah, termasuk cache tetap kosong saat hasil ditolak.
- `README.md`: status live aktual, schema generation/validasi, diagnostik dan retry quota.
- `docs/arsitektur.md`: mencatat dua peran schema, versi cache, diagnostik dan kebijakan retry yang aktual.
- `PROGRESS.md`: riwayat dan hasil ini.

Tidak ada perubahan frontend, kontrak input, framework, dependency, provider, model, sampling, maxOutputTokens atau thinking produksi. Skrip diagnosis sementara berada di /private/tmp dan bukan kode aplikasi.

### Hasil pengujian dan penggunaan API

- `php artisan test`: **44 passed, 0 failed, 244 assertions**. Seluruh automated test fake; tidak memakai API live.
- `php artisan route:list`: berhasil, 5 route.
- Pint pada file PHP yang diubah: lulus.
- HTTP fake sebelum live: lulus. JSON live resep: valid. Database cache miss/hit live: berhasil.
- Request generation sesi perbaikan lanjutan ini: **2 total** (1 diagnosis tanpa thinking mendapat 400; 1 validasi schema ringkas mendapat 200). Tidak ada GET metadata atau retry live tambahan.
- Respons sukses melaporkan promptTokenCount=237, candidatesTokenCount=328, totalTokenCount=565. thoughtsTokenCount tidak tersedia; tidak diasumsikan nol. Token percobaan 400 tidak dilaporkan provider.
- npm run build tidak dijalankan ulang karena tidak ada perubahan frontend, sesuai batas tugas debugging; hasil build sebelumnya tercatat di atas.

### Kendala tersisa dan langkah berikutnya

Tidak ada blocker Step 5 yang diketahui setelah verifikasi ini. Gangguan kapasitas/kuota provider tetap mungkin dan ditangani aman oleh kode; hasil satu live test tidak menjamin uptime. Validasi alergen tetap memiliki keterbatasan literal sebagaimana dokumentasi sebelumnya.

Pengembangan Step 6 boleh dimulai hanya pada instruksi berikutnya. Jangan membuat desain hasil lengkap, authentication, favorit, atau riwayat pada perbaikan ini. Untuk mencoba hasil yang sudah di-cache melalui halaman, masukkan telur, nasi, cabai (urutan bebas), porsi 1, preferensi bebas, tanpa alergi selama TTL belum habis.

Referensi batas kompleksitas schema: https://ai.google.dev/gemini-api/docs/structured-output#limitations .

---

## Step 6 — halaman rekomendasi dan detail selesai (9 Oktober 2026)

### Tahap dan fitur selesai

- Hasil rekomendasi maksimal 3 kartu responsif (1/2/3 kolom), judul/deskripsi, metadata, chip bahan, badge kesesuaian, jumlah bahan wajib cocok, daftar bahan tambahan dan tautan detail. Ikon generik tanpa foto yang mengklaim hidangan tertentu.
- RecipeMatchingService deterministik: normalisasi dan alias terkontrol, pencocokan nama utuh, tidak mengasumsikan minyak/garam/gula tersedia. Optional tidak menghalangi status siap; resep siap diurutkan pertama. Resep konflik avoidance terdeteksi disaring pada HTML dan JSON.
- Detail menampilkan informasi, bahan/takaran/status/opsional, checkbox persiapan, langkah berurutan dengan checkbox, tips, navigasi kembali/cari lain, dan tombol favorit disabled berlabel Step 7.
- Snapshot cache server 60 menit terpisah dari cache Gemini 24 jam. ID pencarian dan resep UUID acak. Pemilik diverifikasi dengan token session acak dan hash_equals. Token kecil saja di session, bukan keseluruhan resep. Detail/rekomendasi GET tidak memanggil Gemini.
- Ubah Bahan memulihkan semua input pencarian melalui flashInput. Snapshot kedaluwarsa/hilang/tidak berhak memakai halaman ramah tanpa kebocoran konten. ID resep harus menjadi anggota snapshot.
- Loading text sesuai permintaan, spinner dan pencegahan double-submit memakai JavaScript Step 4. Empty/error/expired state berbahasa Indonesia. Kuota tetap memiliki pesan khusus Step 5. Seluruh output Blade escaped; CSRF dan limiter POST tetap dipertahankan.

### File baru

- app/Services/RecipeMatchingService.php
- app/Services/RecommendationSnapshotService.php
- resources/views/components/match-badge.blade.php
- resources/views/components/recipe-meta.blade.php
- resources/views/components/recommendation-card.blade.php
- resources/views/recommendations/detail.blade.php
- resources/views/recommendations/expired.blade.php
- tests/Unit/RecipeMatchingTest.php
- tests/Feature/RecommendationPagesTest.php
- docs/screenshots/step-6-detail.jpg (bukti browser)

### File diubah

- app/Http/Controllers/RecommendationController.php
- routes/web.php
- resources/views/recommendations/preview.blade.php (menggantikan tampilan sementara)
- resources/views/recommendations/error.blade.php
- resources/js/ingredient-input.js
- tests/Feature/GenerateRecipeTest.php (judul hasil baru)
- tests/Feature/GeminiRecommendationTest.php (pesan error HTML baru)
- README.md, docs/arsitektur.md, PROGRESS.md
- public/build/manifest.json dan aset CSS/JS hasil Vite dihasilkan ulang.

### Pengujian aktual

- php artisan test: **55 passed, 0 failed, 310 assertions**. Seluruh pengujian otomatis memakai Http::fake/mock, tanpa kuota API.
- php artisan route:list: **8 route**, termasuk GET hasil, detail, dan edit input baru.
- npm run build: **lulus**, Node lokal 22.23.3 dan Vite 8.3.3; CSS 57.71 kB, JS 3.69 kB (sebelum gzip).
- Pint file PHP yang disentuh: lulus.
- Test tambahan: normalisasi/alias, no substring/no pantry assumptions, optional, pengurutan siap, missing ingredients, avoidance alias/qualified termasuk optional, hasil kosong, detail, akses session lain (tanpa token maupun token berbeda), expiry 61 menit, ID resep salah/lintas pencarian, pemulihan input, escaped HTML detail, snapshot server-side tanpa resep di session, dan detail tanpa request provider (fake melempar jika ada HTTP).
- Browser Chrome http://localhost:8000: input telur/nasi/cabai → hasil cache asli Gemini “Nasi Goreng Telur Cabai” → detail → centang langkah → kembali hasil → Ubah Bahan memulihkan 3 chip. Tampilan diperiksa pada desktop serta lebar 390 dan 768; viewport direset setelah pengujian. Bukti gambar docs/screenshots/step-6-detail.jpg.
- Tidak ada test gagal tersisa. Browser awal memiliki handle browser lama; memilih instance Chrome yang aktif memulihkan pemeriksaan tanpa perubahan aplikasi.

### Status Gemini dan batasan

- Integrasi live sukses pada Step 5 tetap berlaku; konfigurasi Gemini, schema generation, prompt, cache API dan retry tidak diubah. **0 request Gemini live baru pada Step 6.** Pemeriksaan UI menggunakan hasil live sebelumnya yang benar-benar tersimpan dalam cache, bukan fixture yang dipresentasikan sebagai AI.
- Matching sengaja konservatif: telur dan telur ayam, nasi dan nasi putih belum dianggap alias. Pengguna perlu memasukkan nama lebih spesifik bila ingin cocok; sinonim yang belum pasti dianggap tidak tersedia. Kesesuaian hanya berdasarkan nama, bukan jumlah/takaran; alergen turunan/sinonim tidak dijamin lengkap. Informasi ini ditampilkan kepada pengguna dan didokumentasikan.
- Checkbox hanya UI, tidak persisten atau mengubah snapshot. Snapshot tidak menjanjikan riwayat permanen dan hilang saat TTL habis/session pemilik hilang.
- POST HTML tetap menampilkan hasil langsung untuk menjaga kontrak yang telah berjalan. Refresh hasil POST bisa meminta submit ulang, tetap dilindungi cache/rate limiter; navigasi kembali dari detail memakai GET snapshot tanpa generation.

### Belum dikerjakan dan langkah berikutnya

Step 6 **siap dilanjutkan ke Step 7** setelah instruksi pengguna berikutnya. Favorit persisten, riwayat pencarian, model/migration bisnis dan login tidak diimplementasikan. Step 7 dapat mengambil struktur resep tervalidasi serta input dari snapshot tanpa mengulang integrasi provider. Tidak ada blocker Step 6 yang diketahui.

---

## Step 7 — Favorit dan Riwayat selesai (9 Oktober 2026)

### Fitur selesai

- Visitor UUID v4 dibuat server dan disimpan session. Semua koleksi dibatasi visitor_id; request tidak dapat memilih owner. IP hanya tetap digunakan limiter Step 5, bukan pemilik data.
- Migration dua tabel baru saved_recipes/recipe_searches, model Eloquent JSON casts, indeks pencarian pemilik dan unique visitor/fingerprint. Tidak ada tabel/model bisnis duplikat.
- Simpan/batalkan favorit pada kartu dan detail, status tersimpan, flash feedback; data diambil dari snapshot server yang diperiksa kepemilikannya dan ID resep, bukan JSON browser. Hash konten kanonis + unique index mencegah favorit identik ganda.
- Halaman Resep Favoritku berhalaman (12 item), detail dari database memakai view Step 6 tanpa request Gemini, tetap bekerja setelah cache hilang. Tidak mengklaim bahan tersimpan selalu tersedia; tidak menyimpan daftar bahan pencarian/alergi dalam favorit.
- Riwayat pencarian sukses dari Gemini maupun cache, maksimum 30 terakhir/pengunjung, bahan/preferensi/porsi/tanggal/jumlah hasil. Tidak menyimpan alergi, raw HTTP response, API key atau snapshot resep permanen pada history.
- Cari Lagi memulihkan input tanpa otomatis generate; alergi harus diisi lagi. Buka Hasil memakai snapshot berumur 60 menit jika masih tersedia. Setelah expired, halaman menjelaskan hasil lama tidak tersedia.
- Hapus satu favorit, satu riwayat atau semua riwayat menggunakan DELETE + CSRF. Penghapusan massal membutuhkan checkbox konfirmasi yang diverifikasi Form Request. Semua akses data asing ditolak tanpa mengungkap konten.
- Navbar desktop/mobile/footer menggunakan menu Favorit/Riwayat nyata, modal placeholder favorit lama dihapus. Halaman kosong, pesan sesi anonim, konfirmasi, flash feedback dan 404 berbahasa Indonesia.

### File baru

- database/migrations/2026_10_09_000001_create_recipe_collections.php
- app/Models/SavedRecipe.php, app/Models/RecipeSearch.php
- app/Services/VisitorIdentity.php, SavedRecipeService.php, SearchHistoryService.php
- app/Http/Controllers/FavoriteController.php, HistoryController.php
- app/Http/Requests/ClearHistoryRequest.php
- resources/views/favorites/index.blade.php, history/index.blade.php, errors/404.blade.php
- resources/views/components/favorite-button.blade.php, visitor-notice.blade.php
- tests/Feature/VisitorCollectionsTest.php
- docs/screenshots/step-7-favorites.jpg, step-7-history-mobile.jpg

### File diubah

- app/Http/Controllers/RecommendationController.php: mencatat history dan menampilkan status favorit; mempertahankan kontrak output JSON.
- routes/web.php: route GET/POST/DELETE koleksi dan repeat.
- resources/views/layouts/app.blade.php: menu dan flash feedback; placeholder modal dihapus.
- resources/views/components/recommendation-card.blade.php, recommendations/detail.blade.php: favorit aktif dan penggunaan ulang detail untuk data database.
- resources/js/app.js: menghapus handler dialog favorit yang sudah digantikan halaman nyata.
- tests/TestCase.php: RefreshDatabase agar fitur persistensi selalu memakai database test terisolasi.
- tests/Feature/RecommendationPagesTest.php: memisahkan status favorit UI dari snapshot cache dalam assertion.
- README.md, docs/arsitektur.md, PROGRESS.md; public/build/manifest.json dan aset hasil build.

### Pengujian

- php artisan migrate: berhasil membuat kedua tabel baru di SQLite development tanpa menghapus data lama.
- php artisan test: **64 passed, 0 failed, 400 assertions** sebelum penambahan halaman 404; validasi akhir dicatat setelah pemeriksaan terakhir di bawah.
- php artisan route:list: **16 route** terdaftar.
- npm run build: lulus dengan Node proyek 22.23.3 dan Vite 8.3.3, CSS 57.88 kB/JS 3.53 kB sebelum penambahan halaman 404; hasil build akhir dicatat di bawah.
- Pint seluruh file PHP yang disentuh: lulus.
- Test fitur mencakup simpan/deduplikasi/server-sourced data (payload manipulasi diabaikan), identitas UUID, hapus/detail favorit tanpa HTTP meski cache hilang, riwayat cache miss/hit, tidak menyimpan alergi, daftar/ulang/hapus/massal konfirmasi, batas30, isolasi pengunjung, resep sama untuk dua visitor, expired/ID salah, API gagal/input invalid tidak mencatat history.
- CSRF diverifikasi dengan subclass middleware Laravel yang menonaktifkan bypass testing saja: save/delete tanpa token mendapat 419, save dengan token valid berhasil. Konfigurasi middleware produksi tidak diubah.
- Browser localhost: pencarian telur/nasi/cabai memakai cache Gemini asli → simpan favorit sukses → halaman Favorit → detail database → Riwayat → Cari Lagi mengembalikan chip. Screenshot desktop Favorit dan mobile Riwayat disimpan. Viewport mobile 390 diperiksa lalu dikembalikan.
- Satu favorit dan satu riwayat hasil uji browser asli ditinggalkan pada sesi browser aktif agar dapat ditinjau pengguna; bukan fixture AI palsu.
- Tidak ada live request Gemini baru. Automated tests seluruhnya HTTP fake; pemeriksaan browser menggunakan cache live dari Step 5 yang masih valid.

### Kegagalan sementara yang diperbaiki

Directive Blade yang berdempetan menyebabkan yield tidak dikompilasi; dipisahkan baris baru. Assertion snapshot disesuaikan karena favorite_id adalah metadata UI, bukan data cache. Assertion old input alergi null diperiksa memakai array key/null karena helper has menganggap nilai null tidak ada. Tidak ada kegagalan test tersisa.

### Kendala dan tahap berikutnya

Tidak ada blocker Step 7 yang diketahui. Migration memakai struktur standar Laravel yang kompatibel secara desain dengan SQLite/MySQL; runtime MySQL belum diuji. Batas session anonim ditampilkan: session/cookie hilang atau browser/perangkat berganti dapat membuat data tidak dapat diakses. Tidak ada login/sinkronisasi/pemulihan atau pembersihan otomatis data yatim. Alergi tidak disimpan di history dan wajib ditinjau/diisi lagi; snapshot Step 6 masih mengikuti TTL sendiri. Menghapus history tidak menghapus favorit atau cache Gemini bersama.

**Step 7 selesai; Step 8 belum dikerjakan.** Lanjutkan hanya sesuai instruksi pengguna berikutnya.

Validasi akhir setelah seluruh perubahan Step 7 termasuk halaman 404: **64 test / 400 assertions lulus**, build Vite lulus (CSS 57.88 kB, JS 3.53 kB), tanpa kegagalan tersisa. Tidak ada perubahan kode setelah run ini.

---

## Step 8 — finalisasi dan QA (9 Oktober 2026)

**Selesai: 67 test lulus, 439 assertions, tidak ada kegagalan tersisa. Aplikasi siap dipersiapkan untuk staging/production; belum dideploy.** Laporan lengkap temuan terprioritas dan matriks audit tersedia di docs/QA.md.

### Temuan/perbaikan

- P1: composer setup dapat mengganti APP_KEY instalasi aktif. Generasi kini hanya saat setup membuat .env baru; setup penuh tidak dijalankan pada data pengguna.
- P1 hardening: .gitignore diperluas untuk seluruh .env.* kecuali contoh; tidak ada key aktual ditemukan pada aset public/log yang diperiksa. Tidak ada key ditampilkan.
- P1 hardening: response web privat memiliki no-store dan header nosniff/frame same-origin/referrer same-origin; aset static tidak dipengaruhi.
- P2: query status favorit dibatasi fingerprint halaman (maksimal3), bukan seluruh koleksi.
- P2: source Tailwind compiled Blade membawa class error/debug dan membengkakkan CSS72.20kB; sumber tersebut dihapus, bundle akhir57.65kB.
- P2: navbar membungkus label pada tablet768; breakpoint desktop kini lg, menu ringkas berfungsi. Status aktif Favorit/Riwayat diperbaiki.
- P2: error419/500 memiliki tampilan Indonesia; test debugfalse membuktikan tidak ada exception/key pada HTML/JSON.
- Dokumentasi setup lama dan placeholder/status tahap awal dirapikan; README sekarang mencakup instalasi/env/key/server/build/migration/tests/cache/limiter/troubleshooting/persiapanproduction. AGENTS bukan lagi instruksi bootstrap memasang dependency tambahan.

### File baru

- app/Http/Middleware/PrivateWebResponse.php
- resources/views/errors/419.blade.php dan 500.blade.php
- tests/Feature/QualityAssuranceTest.php
- docs/QA.md
- docs/screenshots/step-8-qa.jpg

### File diubah

- bootstrap/app.php, app/Services/SavedRecipeService.php
- resources/views/layouts/app.blade.php, resources/css/app.css
- .gitignore, composer.json (skrip setup saja; dependency tidak berubah)
- AGENTS.md, README.md, docs/arsitektur.md, PROGRESS.md
- public/build/manifest.json dan aset hasil build.

### Hasil testing aktual

- php artisan test: **67 passed, 0 failed, 439 assertions**. Mencakup seluruh test tahap sebelumnya, private headers, query favorit terbatas, error production. HTTP fake dan SQLite :memory: terisolasi.
- php artisan route:list: **16 route**.
- php artisan migrate:status: 4 file migration Ran; tidak mengubah skema/data development atau menjalankan migrate:fresh. Migration test melalui RefreshDatabase di DB test.
- vendor/bin/pint --test seluruh proyek: lulus.
- composer validate --no-check-publish dan check-platform-reqs: lulus.
- Audit registry (9 Oktober2026): Composer tanpa advisory/abandoned; npm0 vulnerabilities. Tidak ada package diinstal/diubah.
- npm run build lulus: CSS57.65kB/gzip12.56kB, JS3.53kB/gzip1.42kB, Node22.23.3/Vite8.3.3.
- Browser localhost: landing/input, Enter/duplikat/hapus/disabled, hasilcache asli, detail, emptyfavorit, riwayat, menu768 dan tampilan320 diperiksa; tidak terlihat horizontal overflow pada hasil320. Screenshot desktop disimpan. Navbar768 yang bermasalah sudah diperbaiki dan diperiksa ulang. Viewport direset.
- Pemeriksaan key secara boolean menemukan key terkonfigurasi, tidak pada publicassets/log. Env lokal tetap local/debugtrue, sesuai development; production harus debugfalse sesuai README.
- **0 request Gemini live baru**, tidak mengubah config/model/schema/cache provider. Uji browser menggunakan cache live Step5 yang masih valid dan membuat satu history/snapshot biasa. Kelulusan API live hanya merujuk verifikasi Step5, bukan fake saat ini.

### Kegagalan/kendala dan langkah berikutnya

Tidak ada test gagal tersisa. Penghubung browser lama tidak tersedia; browser Chrome aktif berhasil digunakan setelah menyambungkan tab yang benar. Tidak ada perubahan aplikasi untuk workaround browser.

Belum dilakukan deployment, uji MySQL/MAMP PHP web/hosting, load/penetration test independen, uji backup restore atau multi-instance. Data anonim tetap bergantung session, cleanup data yatim belum ada, matching/alergen punya batasan yang telah didokumentasikan. Jangan mengklaim uptime/kuota Gemini terjamin.

Langkah berikutnya adalah penyiapan staging sesuai README: HTTPS/public document root, debugfalse/securecookie/proxy tepercaya, permission/backup/migration/configcache, lalu smoke test terbatas jika diizinkan. Jangan deploy otomatis atau menambah fitur tanpa instruksi pengguna.

## Step 8.5A — Perbaikan jumlah rekomendasi (9 Oktober 2026)

### Analisis dan fitur selesai

Penyebab terbukti: prompt sebelumnya meminta maksimal3, schema hanya maxItems3, validator menerima0–3, hasil tersebut dapat di-cache. Matching membuang konflik alergi setelah service menyimpan cache, sehingga jumlah juga dapat berkurang di Controller. View menampilkan seluruh array tanpa membatasi1–2; belum ada pemeriksaan duplikasi hasil.

- Prompt kini meminta tepat3 resep berbeda bermakna, ekonomis dan praktis; tambahan bahan diizinkan dan harus jelas. Tidak ada asumsi bumbu selalu tersedia atau resep produksi hardcode.
- Schema recipes minItems3/maxItems3 pada generateContent dan backend. Batas nested generation tetap ringkas seperti perbaikan Step5; model/endpoint/token limit tidak berubah. Dokumentasi resmi structured-output mendukung minItems/maxItems: https://ai.google.dev/gemini-api/docs/structured-output.
- Validasi seluruh field/bahan/langkah tetap; jumlah bukan3, judul duplikat setelah normalisasi, isi identik berdasarkan nama bahan+langkah (mengabaikan beda takaran), konflik alergi eksplisit/alias ditolak sebelum cache. Tidak ada hasil parsial sukses.
- Controller memeriksa lagi jumlah setelah matching sebelum snapshot/riwayat/JSON. Resep yang membutuhkan bahan tambahan tetap ditampilkan. Error ramah meminta pengguna menambah/mengubah bahan.
- Promptversion2/schemaversion3 memisahkan cachelama; TTL86400 dan urutan bahan kanonis tetap. Cache hit divalidasi ulang, entri invalid pada key aktif saja dibuang. Tidak flush cache development.
- Snapshot UI lama dengan jumlah bukan3 ditolak sebagai tidak tersedia (410); data favorit/riwayat tidak dihapus. Desain card/navbar/footer/landing tetap.
- Tidak menambahkan request perbaikan semantik otomatis demi penghematan token; retry transport500/503 maksimal satu tetap. Tidak lanjut8.5B/9 atau integrasi gambar.

### File dibuat/diubah

- app/Services/GeminiRecipeService.php: prompt, schema, jumlah, duplikasi, alias alergi sebelum cache.
- app/Services/RecommendationSnapshotService.php: guard snapshot lama berjumlah bukan3.
- app/Http/Controllers/RecommendationController.php: guard setelah matching.
- app/Exceptions/RecipeGenerationException.php dan resources/views/recommendations/error.blade.php: pesan kegagalan aman yang jelas.
- config/ai.php: versi prompt/schema baru.
- tests/Feature/ExactlyThreeRecipesTest.php (baru): jumlah, duplikasi, cache lama/baru, reordered cachehit, tiga cards, alergi, guardcontroller, snapshotlama.
- tests/Support/GeminiFixture.php: tiga resep berbeda hanya untuk fixture test.
- tests/Feature/GenerateRecipeTest.php, GeminiRecipeServiceTest.php, RecommendationPagesTest.php, VisitorCollectionsTest.php: sesuaikan kontrak sukses3, hasil kosong/alergi gagal, jumlahriwayat3.
- README.md, docs/arsitektur.md, PROGRESS.md: kontrak dan status terkini.
- public/build diproduksi ulang oleh Vite; hash CSS/JS tetap seperti Step8.

### Pengujian aktual

- php artisan test: **74 tests, 499 assertions lulus**. Http::fake/preventStrayRequests, SQLite :memory:; tidak menggunakan database development untuk test.
- Cakupan:3valid,0/1/2/4ditolak, title/contentduplicate, tambahanbahan, invalidJSON/field/truncated, aliasalergi, cached2versilama diisolasi tanpa penghapusan, cached2keyaktif diganti3valid, cachehit reordered tanpa HTTPbaru, tepat3 elemen article, guard postmatching, snapshotpartial410; fitur favorit/riwayat tetap lulus.
- php artisan route:list: **16 route** berhasil.
- vendor/bin/pint --test: **lulus**.
- npm run build: **lulus** dengan Node lokal22.23.3/Vite8.3.3; CSS57.65kB, JS3.53kB.
- Pembacaan config runtime aman: configuration_cached=false, prompt_version2, schema_version3, TTL86400. Tidak membaca/menampilkan APIkey.
- Percobaan test pertama:66/67 lulus,1gagal karena assertion riwayat masih mengharapkan1; diperbarui menjadi3. Tidak ada kegagalan tersisa pada final run.
- **0 request Gemini live** pada tahap ini. Tidak mengklaim schema/prompt baru telah lolos live; keberhasilan API Step5 sebelumnya memakai kontrak lama.

### Batasan, belum selesai, langkah berikutnya

Setiap pencarian sukses pada jalur aplikasi kini berisi tepat3 resep tervalidasi; hasil yang gagal memenuhi aturan ditolak dengan pesan jelas. Keunikan bermakna dan keamanan semantik AI tidak dapat dibuktikan sempurna oleh normalisasi/fingerprint: parafrasa berbeda atau turunan alergen yang tidak dikenal tetap membutuhkan pemeriksaan pengguna. Peringatan pada halaman tetap dipertahankan.

Tidak ada error otomatis tersisa. Smoke test Gemini live dengan kontrak baru belum dijalankan; jika dibutuhkan dan diizinkan, batasi satu panggilan lalu periksa pencarian ulang melalui cache. Jangan menurunkan aturan tepat3 bila provider menolak schema atau menghasilkan output terpotong; diagnosis error aman lebih dahulu. Tidak ada perubahan limit token/model diam-diam. Tunggu instruksi berikutnya sebelum8.5B/9.

## Step 8.5A.1 — Kesesuaian tiga kategori (9 Oktober 2026)

### Audit dan fitur selesai

Sebelumnya RecipeMatchingService hanya ready-first, menghitung setiap baris wajib termasuk duplikat, tanpa rentang kategori. GeminiRecipeService hanya memastikan tepat3 unik/valid/alergi. Card badge hanya siap/perlu tambahan, detail belum memisahkan tiga kelompok bahan. Cache/snapshot versi sebelumnya belum menjamin kategori.

- Prompt meminta tepat3 unik dengan kategori pertama0–2, kedua1–3, ketiga minimal4 tambahan wajib; bahan harus relevan, realistis, mengutamakan input, satu jenis per entri, tidak memanipulasi optional atau menambahkan bahan tidak perlu. Model/endpoint/JSON Schema tetap.
- RecipeMatchingService menjadi sumber tunggal: trim/spasi/lowercase/alias terkontrol, ketersediaan exact normalized match, wajib unik dihitung sekali, wajib menang atas duplikat optional, garam/minyak/air tidak diasumsikan tersedia. Optional terpisah dan tidak dihitung.
- Penggabungan nama bahan dengan separator/penghubung yang dikenal ditolak, bukan dipecah dengan tebakan. Alergi tetap diperiksa termasuk alias.
- Urutkan ascending jumlah missing lalu validasi0–2/1–3/4+. Label kategori diberikan hanya sesudah validasi. Hasil yang tidak memenuhi ditolak503 dengan pesan melengkapi tiga tingkat kesesuaian dan ajakan tambah/ubah bahan. Tidak ada hasil parsial, bahan palsu, recipefallback atau request perbaikan semantik otomatis.
- Service validasi kategori sebelum cache; cache hit divalidasi ulang. Promptversion3/schemaversion3, TTL86400 tetap; tidak flush cache aplikasi. Snapshot matching_version2 menolak snapshot lama yang belum memenuhi kontrak baru; favorit dan riwayat DB tetap utuh.
- Card menampilkan badge kategori, jumlah/daftar tambahan, metadata, detail dan favorit. Detail memakai match snapshot yang sama untuk Bahan tersedia, Bahan tambahan, Bahan opsional. Detail favorit tetap menjelaskan persediaan belum diperiksa tanpa kategori palsu. GET detail tidak memanggil Gemini. Navbar/footer/landing tidak diubah.

### File dibuat/diubah

- app/Services/RecipeMatchingService.php: deduplikasi hitungan, kelompok bahan, validasi kategori, urutan dan metadata.
- app/Services/GeminiRecipeService.php: prompt dan validasi kelompok sebelum cache.
- app/Services/RecommendationSnapshotService.php: versi snapshot untuk kontrak baru.
- app/Exceptions/RecipeGenerationException.php; config/ai.php: pesan kategori dan versi prompt.
- resources/views/components/match-badge.blade.php; recommendation-card.blade.php: badge dan jumlah tambahan.
- resources/views/recommendations/detail.blade.php; preview.blade.php; error.blade.php: kelompok detail, keterangan urutan, pesan aman.
- tests/Unit/RecipeCategoryTest.php (baru); tests/Feature/RecipeCategoriesTest.php (baru).
- tests/Support/GeminiFixture.php; tests/Feature/RecommendationPagesTest.php; ExactlyThreeRecipesTest.php: fixture/expectation kontrak kategori terbaru.
- README.md; docs/arsitektur.md; PROGRESS.md.
- public/build dibangun ulang; hash aset tetap.

### Pengujian aktual

- php artisan test: **82 tests, 573 assertions lulus**; Unit/Feature dan Http::fake, tidak memakai API live atau database development.
- Batas pertama0/1/2 diterima,3ditolak; kedua1/3diterima,4ditolak; ketiga4/5diterima,3ditolak; rentang overlap1/1 dan2/2; urutan terbalik; optional, alias, duplikat bahan/recipe; alergi; kategori invalid tidakdicache/tidakriwayat/tidakretry; cachelama diisolasi, cachebaru invalid diganti, cachehit tanpaHTTP; snapshotlama ditolak; card/detail sama; favorit/riwayat tetap lulus.
- php artisan route:list: **16 route** berhasil.
- vendor/bin/pint --test: **lulus**.
- npm run build: **lulus**, Node lokal22.23.3/Vite8.3.3, CSS57.65kB JS3.53kB.
- Tidak ada test gagal pada tahap ini. **0 request Gemini live**. Pengujian tampilan melalui respons Blade/Feature; tidak ada pemeriksaan visual browser baru pada tahap ini.

### Kendala dan langkah berikutnya

Tidak ada error otomatis tersisa. Aturan angka terjamin pada jalur sukses, tetapi parser nama terbatas pada alias/penghubung yang diketahui; tidak membuktikan semua turunan alergi, kecukupan takaran, realisme resep atau apakah optional benar secara kuliner. Prompt melarang manipulasi bahan dan peringatan pengguna tetap ada. Tidak mengklaim liveAPI berhasil dengan prompt baru. Smoke test live terbatas dapat dilakukan jika diminta; jangan lanjut8.5B/9 atau menambah fitur gambar sebelum instruksi berikutnya.

## Step 8.5B — Integrasi Unsplash dan gambar makanan

### Fitur selesai dan keputusan

Audit: GeminiRecipeService dan RecipeMatchingService sudah memastikan tepat3 dan kategori0–2/1–3/4+. Snapshot menyimpan seluruh resep; SavedRecipeService memfilter8field isi resep dan fingerprint. Kolom saved_recipes.recipe_data sudah JSON sehingga tidak memerlukan migration untuk image. Card lama memakai ikon; detail/favorit belum menampilkan foto.

- RecipeImageService baru memakai Laravel HTTP Client/Public Auth server-side, GET search/photos resmi, Accept-Versionv1, per_page5, landscape/relevant/high. Model/endpoint/cache/schema Gemini dan algoritma kategori tidak diubah.
- Normalisasi nama, mapping exact hidangan populer, prioritas foto lokal terverifikasi (mapping kosong saat ini), cache, lalu pencarian. Metadata description/alt harus mendukung frasa judul/query; hasil pertama yang tidak relevan dilewati. Null metadata ditangani. URL HTTPS host resmi, ID, nama/kredit divalidasi. Foto ditandai ilustrasi, bukan klaim100%cocok.
- Metadata image_url regular, image_small_url, image_alt, image_source, photographer_name/url, photo_url dan unsplash_photo_id disimpan. URL gambar API/ixid utuh; tautan fotografer/foto diberi UTM dapur_rantau/referral. Tidak mengunduh foto Unsplash ke disk.
- Cache gambar terpisah versi1+SHA256nama, TTL7hari; hasil tidak ditemukan/tidakcukuprelevan placeholder1jam. Error tidak disimpan sebagai foto valid. Lock global perkey serial, budget internal45/jam, remaining<=2/429 cooldown minimal1jam dan Retry-After lebih panjang, auth1jam, errorlain60detik. Tidak retry. Cache/local tetap tersedia selama cooldown.
- Controller menambah image sesudah3kategori lolos, sebelum snapshot. Kegagalan gambar menjadi placeholder tanpa menggagalkan Gemini. Detail/snapshot/favorit GET tidak mencari gambar/Gemini lagi.
- SavedRecipeService menyimpan image dalam JSON, fingerprint tetap hanya isi resep agar deduplikasi/favorit lama tidak berubah. Favorit lama tanpa image tetap dapat dibuka dengan placeholder. Tidak ada migration atau penghapusan favorit/riwayat.
- Blade component reusable gambar4:3/object-cover, lazycard/eagerdetail, altIndonesia, atribusi. JS fallback satu kali dan sembunyikan kredit saat foto gagal. Placeholder SVG proyek, bukan foto makanan palsu.
- Konfigurasi services.unsplash dan .env.example lengkap. .env asli tidak ditimpa; pembacaan boolean runtime menunjukkan Access Key belum terkonfigurasi dan configuration_cached=false. Tidak membuat aplikasi/akun Unsplash secara otomatis.

### File dibuat/diubah

Baru:
- app/Services/RecipeImageService.php
- resources/views/components/recipe-image.blade.php
- resources/js/recipe-images.js
- public/images/recipe-placeholder.svg
- tests/Support/UnsplashFixture.php
- tests/Feature/RecipeImageServiceTest.php
- tests/Feature/RecipeImagePagesTest.php
- docs/UNSPLASH_SETUP.md

Diubah:
- config/services.php dan .env.example
- app/Http/Controllers/RecommendationController.php
- app/Services/SavedRecipeService.php
- resources/views/components/recommendation-card.blade.php
- resources/views/recommendations/detail.blade.php
- resources/views/favorites/index.blade.php
- resources/js/app.js
- tests/TestCase.php (key Unsplash kosong default untuk isolasi tests)
- README.md, docs/arsitektur.md, PROGRESS.md
- public/build/manifest.json dan aset Vite hasil build.

### Pengujian aktual

- php artisan test: **93 tests, 686 assertions lulus**. Termasuk seluruh regresi sebelumnya, foto valid/tidakrelevan/kosong, metadata null, URLinvalid/kredithilang, keykosong/baseURLinvalid,400/401/403/429/500/503/timeout/JSONinvalid, positiveTTL7hari/negativeTTL1jam, rateheader/lock/budget, lokalpriority, cachehit, atribusi/keytidakdiHTML, card/detail/favorit, snapshot/fingerprint, kegagalanUnsplash tidakmengubah3kategori, GET tanpaHTTP, favoritlama tetap ada.
- php artisan route:list: **16 routes** berhasil; tidak menambah endpoint publik pencarian foto.
- vendor/bin/pint --test: **lulus**.
- npm run build: **lulus**, Node22.23.3/Vite8.3.3; CSS57.41kB(gzip12.53),JS3.93kB(gzip1.57).
- Uji Node VM pada recipe-images.js: **lulus** untuk cachedbrokenimage, penggantianplaceholder, sembunyikredit, gambarvalid tetap, guarderrorberulang. Tidak menambah librarytes.
- **0 API live Gemini/Unsplash**. Automated tests memakai Http::fake/preventStrayRequests dan database SQLite :memory:. Tidak ada kegagalan test tersisa. Pemeriksaan UI berupa render Blade Feature+JS terisolasi, bukan smoke test visual browser baru.

### Belum selesai/kendala/langkah berikutnya

Implementasi Step8.5B dan pengujian fake selesai. Access Key Unsplash belum tersedia; pemilik perlu membuat aplikasi manual dengan nama/deskripsi sesuai docs/UNSPLASH_SETUP.md, mengisi .env dan config:clear. Setelah itu satu smoke test terbatas dapat dijalankan jika diminta; tidak mengklaim integrasi live sudah berhasil.

Tidak ada foto lokal makanan yang telah diverifikasi/disediakan. Placeholder digunakan jika metadata tidak cukup; matching teks tidak membuktikan kecocokan visual. Cold request hingga3gambar dapat menambah latensi sekitar30detik pada timeoutmaksimal; tidak menambah queue/asinkron di luar scope. Kuota demo tetap terbatas. Download/pemilihanfoto belum ada; fitur itu kelak perlu downloadtracking sesuai guidelines. Tidak ada deployment, auth, Pexels, atau kelanjutan Step9.

## Validasi live Unsplash setelah Access Key diisi

- Laravel membaca Access Key (dicek boolean saja), configuration_cached=false, baseURL resmi, per_page5, timeout10, cache database. Tidak menampilkan credential atau mengubah .env.
- Tepat **1 request live** melalui RecipeImageService untuk Nasi Goreng Telur → Indonesian egg fried rice. Pengaman request middleware membatasi host Unsplash dan maksimal satu request; tidak ada retry.
- Respons **HTTP200**, JSON valid,5hasil. Header limit50,remaining49. Semua hasil memiliki ID, small/regularURL dan metadata atribusi; description pada sebagian null dan alt tersedia, ditangani tanpa error.
- Tidak ada foto yang lolos pemeriksaan frasa relevansi service untuk judul/query tersebut. Hasil yang dipilih **placeholder**, bukan foto Unsplash. Tidak melonggarkan filter atau melakukan pencarian tambahan untuk memaksakan foto.
- Metadata fallback valid tersimpan di Laravel database cache sebagai hasil negatif TTL3600. Pengulangan nama dengan spasi/case berbeda mengembalikan data identik, **0 request tambahan**. Cache foto positif7hari belum terbukti dengan respons live ini; jalur tersebut tercakup automated test.
- php artisan test: **93 tests,686 assertions lulus**, termasuk kategori, foto positif/cache/atribusi via Http::fake, detail/favorit dan kegagalan provider.
- Browser memeriksa render Blade card/detail dari file pratinjau sementara dengan resep fixture dan fallback hasil live:3gambar card loaded=true naturalWidth800,1gambar detail loaded=true. Ketiga badge tetap Paling Cocok/Cukup Cocok/Alternatif Menu dengan counts0/1/4. Ini bukan pencarian Gemini live atau snapshot pengguna baru.
- Localhost8000 tidak aktif saat verifikasi. Server pratinjau sementara127.0.0.1:8766 digunakan untuk memeriksa HTML hasil Blade/aset build, lalu dihentikan. Tidak mengubah route atau kode aplikasi.
- Total request live: Unsplash1, Gemini0. Tidak ada perubahan integrasi Gemini, algoritma kategori, deployment atau Step9.

Status: autentikasi/koneksi Unsplash, parsing, fallback, negativecache dan cachehit berhasil. Kendala yang tersisa adalah metadata foto query uji belum cukup relevan; foto Unsplash live belum dipilih/ditampilkan. Tidak perlu mengubah key untuk kasus ini. Foto lokal terkurasi atau evaluasi strategi relevansi bisa menjadi pekerjaan terpisah jika diminta; jangan mengklaim foto100%cocok berdasarkan metadata saja.

## Debugging Step8.5B — Foto ditolak oleh filter frasa lengkap

Penyebab terverifikasi: filter lama mensyaratkan frasa judul/query utuh (egg fried rice/nasi goreng telur), sedangkan hasil live memiliki metadata seperti fried rice with peas and carrots. Metadata publik mendukung identitas nasi goreng namun tidak urutan/seluruh modifier judul. Bukan AccessKey/URLendpoint/Controller/Blade yang salah. Cache negatif versi1 mempertahankan fallback1jam. Log diperiksa hanya angka status/remaining/limit, tanpa credential/rawexception.

Perubahan terarah:
- app/Services/RecipeImageService.php: strategi cache versi2; normalisasi token/batas kata; keluarga hidangan/alias terkontrol; identitas hidangan wajib, modifier meningkatkan skor; kandidat valid dengan skor terbaik dipilih. Nama takdikenal minimal2token bermakna dan75%coverage. Foto generik/substring keliru tetap ditolak. Localpriority/URLvalidation/attribution/errorhandling tidak diubah.
- tests/Feature/RecipeImageServiceTest.php: regression urutan kata berbeda, variasi judul, kandidatspesifik lebihbaik, ricecake/eggplant/generic ditolak, cache negatif versi1 tidakmenghalangi v2.
- docs/UNSPLASH_SETUP.md, docs/arsitektur.md, PROGRESS.md: mekanisme aktual dan bukti verifikasi.
- Build ulang public/build; hash aset tetap. Tidak mengubah Gemini, schema resep, jumlah/kategori, matchingbahan, Controller/Blade/favorit/riwayat atau landingpage.

Verifikasi live (maksimal1request, benar-benar dijalankan):
- Key terbacatrue,baseURLresmi; tepat1pencarian NasiGorengTelur melalui service; HTTP200,5hasil,limit50remaining49. Metadata mengandung friedrice, rice/friedmeat, foodgenerik dan seafoodfriedrice. Service memilih kandidat beridentitas friedrice yang valid, bukan memaksakan foodgenerik.
- image_source=unsplash, metadata validuntukdisplay, tersimpan positif pada cacheversi2 TTL7hari. Pencarianulang spasi/case berbeda menghasilkanmetadataidentik dan0HTTPtambahan. Tidak flush cachelama.
- Foto Christopher Alvarenga dari Unsplash berhasil dimuat viahotlink di browser pada card (naturalWidth400) dan detail (naturalWidth1080), kredit/UTM tampil. Pratinjau memakai render Blade dengan resepfixture, bukan hasil Gemini live; dua cardlain memakaiplaceholder untuk tidakmemanggilAPIbaru. Foto adalahilustrasi identitas nasi goreng, bukan jaminan seluruh ingredient judul terlihat.
- php artisan test: **96tests,698assertions lulus**; termasuk Controller→snapshot→card/detail/favorit, APIfailure, duplikasi/favorit/history dan3kategori tetap.
- php artisan route:list: **16routes** berhasil; npm run build dan Pint --test **lulus**. CSS57.41kB JS3.93kB.
- Total live Unsplash1, Gemini0 pada debuggingini. AsetfotoCDN dimuatolehbrowser sebagaihotlink, bukan requestsearch tambahan. Tidak ada testgagal tersisa.

Batasan: foto yang tidak cukup mendukung identitashidangan tetapplaceholder. Snapshot/favorit lama tidak dimutasi, sehingga dapat tetapplaceholder; lakukan pencarian baru untukstrategiv2 tanpa membukaulang API diGET. Tidak melakukan livepencarian semua judul/semuakategori untukmenghematkuota. Tidak melanjutkanStep9. Serverpratinjausementara dihentikan setelahpemeriksaan.

## Perbaikan rekomendasi gagal invalid_categories

Empat pencarian live terakhir yang diperiksa sebelumnya menerima HTTP200 dari Gemini lalu gagal invalid_categories. Sebelumnya kegagalan ini langsung ditampilkan tanpa upaya perbaikan; log juga tidak membedakan rentang hitungan dan nama bahan gabungan.

Selesai:
- RecipeMatchingService menambahkan diagnosis aman kind+missing_counts atau kind+recipe_index tanpa mengubah rentang/algoritma kesesuaian.
- GeminiRecipeService mencatat diagnosis tanpa data sensitif dan memperbaiki prompt agar nama bahan sesuaiinput, satujenis perentri, keterangan pengolahan di langkah dan cekulanghitungan.
- Maksimal satu permintaan koreksi untuk invalid_categories pada respons pertama. Feedback berupa angka/jenis kegagalan, bukan seluruh riwayat/respons. Tetap meminta set3 melalui schema sama. Total maksimal2panggilan termasukretry500/503; tidak ada retry tambahan di requestkoreksi. Kuota, timeout, JSONinvalid, terpotong, alergi dan duplikasi tidak memicu koreksi.
- Hasil kedua wajib lolos semua validasi sebelumcache/snapshot/history. Gagal lagi menampilkan pesanaman; tidak menampilkan parsial atau mengganti dengan resepbuatansendiri.
- Promptversion4 mengisolasicachelama; schemaversion3, model, tokenlimit dan Unsplash tetap. Tidak flushcache.

File: app/Services/GeminiRecipeService.php, app/Services/RecipeMatchingService.php, app/Exceptions/RecipeGenerationException.php, config/ai.php, tests/Feature/GeminiCategoryRepairTest.php(baru), tests/Feature/RecipeCategoriesTest.php, README.md, docs/arsitektur.md, PROGRESS.md. Buildasetdiulang tanpa perubahanhash.

Pengujian aktual: php artisan test **101tests735assertions lulus**; recovery0/1/3→0/1/4, diagnosisingredientgabungan, suksesdicache, retrybudgetbersama, kegagalankoreksi/429/503/alergi/truncated tidakmengirimrequestketiga, Controllersukses3kategori+1history. Testlama kategori gagal diperbarui dari1menjadi2request sesuai kebijakan baru; tidak ada kegagalantersisa. route:list16route, Pint--test dan npmrunbuild lulus.

Tidak ada request live Gemini/Unsplash pada perbaikan ini; bukti recovery memakai Http::fake. Belum membuktikan semua input pengguna akanberhasil live: model masih dapat gagal memenuhi aturan setelah satu koreksi dan harus ditolak. Langkah berikutnya coba pencarianbaru di UI; jika tetapgagal, diagnosis kini dapat membedakan hitungan kategori dan ingredientgabungan tanpa membuka data sensitif. Tidak melanjutkan Step9.

## Step8.5B — Parallel fetch tiga gambar

Audit: Controller sebelumnya memanggil getRecipeImage dalamforeach secara serial, bukan hardlimit satu foto. Namun error pertama mengaktifkan globalcooldown dan dapat menghentikan pencarianjudul berikutnya. Cache negatif/relevansi juga dapat menyebabkanplaceholder. Implementasi baru tetapmenolak fototidakrelevan, tidakmemakai satufoto untuktigaresep.

Selesai:
- getImagesForRecipes menerima maksimal3 resep, menghasilkanmetadataperindeks. Periksa lokal/cachemasing-masing (positif/negatif), deduplikasinamanormal sama, kumpulkanmiss lalu Http::pool(concurrency:3) denganrequestbernama cachekey. Tiaprequestmempunyaiqueryjudul sendiri, per_page5, landscape, relevansi/metadata/atribusi yang sama. Controller hanya memanggil batch sekali.
- Global lock melindungi satu batch untukreservasibudget45/jam; requestdidalambatch concurrent. Sisaquotaheader disimpan/reserved dan cadangan2dipertahankan. Quota429/auth mengaktifkancooldownbatchselanjutnya tanpa membuangresponsbaikdalambatchsaatini.
- Respons/timeout/errorperrequest diprosesterpisah. Photo valid7hari, notfound1jam, errorsementara placeholdernegativecache maksimal60detik, auth/quota sesuaijeda. Tidakretry, tidakflushcache. Keystrategirelevansi tetapversi2.
- Urutanresep/kategori tidakberubah. Snapshot/detail/favorit tetapmenggunakan imageyangsudahdisimpan dan GET tidakmemanggilAPI. Gemini/schema/matchingbahan/favorit/history/UI tidakdiubah.

Filediubah: app/Services/RecipeImageService.php; app/Http/Controllers/RecommendationController.php; tests/Feature/RecipeImageBatchTest.php(baru); tests/Feature/RecipeImageServiceTest.php; tests/Feature/RecipeImagePagesTest.php; docs/UNSPLASH_SETUP.md; docs/arsitektur.md; README.md; PROGRESS.md. Buildasetdiulang, hashCSS/JS tetap.

Hasil akhir: **php artisan test107tests789assertions lulus**. Testmemastikan3requestketujuan/queryberbeda, barrierfakepromise3dispatchsebelumresponse, completionterbalik/urutanoutputtetap, 2cache+1miss,1cache+2miss,semuacache0request,negativecache,500/429/timeout/relevansigagalhanya1placeholder,quotareserve,judulduplikat,cap3,detail/favorit danregresikategori. Ujibarrier awal gagal karenahooktransferstatsLaravel Http::fake melakukan wait sinkron; hook tersebut dinonaktifkan ditestbarrier saja, tidakmengubahHTTPproduksi. Expectationteslama serial/cooldown disesuaikan. Tidakada kegagalantersisa.

route:list16routes, Pint--test dan npmrunbuild lulus (CSS57.41kB/JS3.93kB). **0requestlive** Gemini/Unsplash. Tidak mengklaim benchmarklatensi provider. Implementasi pool merujuk dokumentasi Laravel13 https://laravel.com/framework/docs/http-client dan kode vendor terpasang; tidakmenambahpackage.

Status: cachemiss3→3requestconcurrent,miss2→2,miss1→1,allhit→0 selama budget/quota/lock mengizinkan. Saatrate limit/lockbatchlain, miss tetapplaceholder; fototidakrelevan tetapditolak. Tidak menjamin semua judul memiliki foto. Tidak lanjutStep9.

## Live API batch Unsplash — tiga query asli dan koreksi berbasis bukti

Persiapan: AGENTS/arsitektur/progres/service/pool/controller/Blade diperiksa. Environmentlocal, AccessKeyterbaca boolean, configuration_cached=false, baseURLresmi, cachedatabase; keytidakditampilkan. Sebelumtest budget3,remainingknown48,cooldownfalse. Hanya3keygambar target diinvalidasi sesuai izin, tidakflushcache/menonaktifkanproteksikuota. Satu batch maksimal3request dilindungi middlewarehost+counter; Gemini0.

Hasil live:
- Nasi Goreng Telur / Indonesian egg fried rice: HTTP200,5kandidat,405ms,start23ms,limit50remaining49.
- Mie Goreng / Indonesian fried noodles: HTTP200,5kandidat,1018ms,start25ms,limit50remaining47.
- Telur Dadar / Indonesian omelette: HTTP200,5kandidat,1018ms,start26ms,limit50remaining46.
- Totalbatch1074ms, requests3. Seluruhkandidatmemiliki urls.small. ID/alt/description/skor/alasanpenolakanperfoto dirinci docs/UNSPLASH_LIVE_REPORT.md tanpa rawJSON/credential.

Diagnosis dan perubahan:
- Mie sebelumnya placeholder karena description memakai "Mi Goreng Jawa"/"Mi Goreng Ulang Tahun", alias lama hanya "mie goreng"/friednoodles. Tambahkan aliasmi.
- Telurdadar sebelumnya memilih captionomelette denganalt soup. Rejectkonflikalt sup/soup/quiche untukidentitas telur dadar; kandidat denganaltpendukunghidangan mendapat skorlebihbesar. Tidakmelonggarkan foto food/pasta/saladgenerik menjadicocok.
- app/Services/RecipeImageService.php: photoRelevanceScore dan aliasmi, cacheversi3. Tests/Feature/RecipeImageServiceTest.php: regressionmetadataasli. docs/UNSPLASH_SETUP.md, docs/arsitektur.md, PROGRESS.md diperbarui; docs/UNSPLASH_LIVE_REPORT.md baru.
- Tidakubah Controller/Blade/Gemini/kategori/favorit/history. Cacheversi2 tetapada; snapshot/favoritlama tidakdirewrite.

Sesudahkoreksi, responseasli disimpan dan diprosesulang OFFLINE tanpa pencarianbaru. Ketigaresep image_sourceunsplash: rQX9eVpSFz8, i5XurHSjE1M, M5IajiQxoeo. Metadatavalidmasukcacheversi3TTL7hari. Http::fake membuktikanrepeatcacheidentik dan0requesttambahan.

Verifikasi: ketigafoto dimuatdibrowsercard pada400px (loadedtrue), detailmie/telur1080px loadedtrue; atribusihotlinktampil. Pratinjau merenderBlade denganresepfixture/dummy danimageasli, bukan hasilGemini live baru. GambarCDNhotlink dimuatbrowser (bukan JSONAPIsearchtambahan). Fotoilustrasibukanklaim seluruhbahanjudul terlihat. Serverpratinjaudihentikan sesudahselesai.

Automated: **php artisan test109tests792assertions lulus**; route:list16route; Pint--testlulus. Frontendtidakdiubah,sehinggabuildtidakdiulang. Tidakadatestgagal. Tidakadasoftwarepackagebaru.

Status:3requestparalelasliterbukti, parsing/relevansi/caching/pemetaanBladeberhasil setelahduaperbaikankecil. Tetapfallbackuntukmetadatayangtidakcukup/bertentangan; tidakmenjaminsemuajudul memiliki foto. Tidakadarequestlivekeempat, tidakadaGemini, tidaklanjutStep9. Laporanrinci dapatdigunakanuntukauditlanjutan tanpa mengulangAPI.


## Revisi kesesuaian — tanpa kuota bahan tambahan (9 Oktober 2026)

Sesuai instruksi pengguna, batas tambahan per kategori dihapus. Sebelumnya hasil seperti 2–4–4 atau 2–5–8 ditolak karena tidak memenuhi rentang kategori. Kini setiap hasil valid diurutkan stabil berdasarkan jumlah bahan wajib unik yang belum tersedia, dari paling sedikit. Ketiga resep boleh memiliki nol tambahan atau jumlah yang sama; badge menunjukkan peringkat relatif. Tepat tiga resep unik, pengecekan alergi, validasi JSON dan nama bahan tetap dipertahankan.

Selesai: RecipeMatchingService tidak menolak jumlah tambahan; prompt versi 5 tidak meminta kuota; cache prompt lama terisolasi tanpa flush. Koreksi semantik maksimal sekali hanya untuk nama bahan gabungan, bukan hitungan tambahan, dengan total maksimal dua panggilan termasuk retry transport. Unsplash, UI, favorit dan riwayat tidak dirombak.

File diubah: app/Services/RecipeMatchingService.php, app/Services/GeminiRecipeService.php, app/Exceptions/RecipeGenerationException.php, config/ai.php, tests/Unit/RecipeCategoryTest.php, tests/Feature/RecipeCategoriesTest.php, tests/Feature/GeminiCategoryRepairTest.php, README.md, docs/arsitektur.md, PROGRESS.md. Build aset dijalankan ulang.

Pengujian: php artisan test **111 tes / 805 assertions lulus**; Pint --test lulus; route:list 16 route; npm run build lulus memakai Node lokal 22.23.3. Regresi mencakup 0–0–0, 2–4–4 dan 2–5–8, urutan menaik, cache hit pada input terbalik tanpa request tambahan, riwayat, serta penolakan bahan gabungan/alergi. Tidak ada tes gagal tersisa.

Batasan: tidak melakukan request live Gemini/Unsplash, sehingga belum memverifikasi keluaran live prompt baru. Error provider, format rusak, konflik alergi atau jumlah resep selain tiga tetap dapat menghasilkan pesan gagal yang aman. Selanjutnya pengguna dapat mengirim pencarian baru dari form; tidak perlu menghapus database/cache atau mengganti API key. Tidak melanjutkan Step 9.

## Perbaikan foto yang belum tersedia — variasi judul (9 Oktober 2026)

Audit read-only snapshot terbaru menemukan placeholder pada Nasi Goreng Seafood Merdeka, Telur Dadar Gulung Seafood, Sup Segar Cumi Udang, Omelet Tahu Bayam, dan Sup Tahu Bayam Telur. Log lokal terakhir menunjukkan tiga respons Unsplash HTTP200, sehingga keberhasilan HTTP saja tidak menjamin foto lolos relevansi. Log lama tidak menyimpan hasil seleksi/kandidat, jadi penyebab setiap foto belum dapat dipastikan dari log tersebut. Kelemahan kode yang terbukti: mapping query hanya exact judul dan alias omelet belum dikenali pada judul Indonesia variasi.

Selesai: pemetaan keluarga masakan/sinonim dipakai bersama oleh query dan relevansi; modifier terkontrol diterjemahkan, kata dekoratif tidak ikut query keluarga dikenal. Tambah sup tahu/seafood, omelet dan mi goreng pada pengenalan judul. Foto tidak relevan/konflik tetap ditolak. Cache gambar versi 4 memisahkan negative cache lama; tidak flush database. Tambah log aman selected/no_relevant_photo dan candidate_count. Gemini/pengurutan/UI/favorit tidak diubah, tidak ada package baru atau tahap baru.

File diubah: app/Services/RecipeImageService.php, tests/Feature/RecipeImageServiceTest.php, README.md, docs/UNSPLASH_SETUP.md, docs/arsitektur.md, PROGRESS.md.

Pengujian akhir: 113 tests / 840 assertions lulus; Pint --test lulus; route:list 16 routes. Satu expectation lama mapping exact gagal pada run awal, diperbarui sesuai perilaku baru dan ditambah cek judul tak dikenal tetap utuh. Tidak ada kegagalan tersisa. Uji baru mencakup enam variasi judul, query terjemahan, foto relevan, cache hit tanpa request ulang, serta foto tidak relevan/konflik. Frontend tidak berubah, build tidak diulang.

Batasan: nol request live Gemini/Unsplash; belum mengklaim foto dari pencarian baru tersedia live. Unsplash tetap dapat tidak memiliki kandidat relevan atau terkena kuota. Snapshot/favorit lama mempertahankan foto lama; kirim pencarian baru dari form untuk memakai strategi baru. Jika masih placeholder, periksa log seleksi untuk membedakan tidak ada kandidat relevan dari kegagalan API. Step 9 tidak dikerjakan.


## Debugging respons Unsplash sampai browser — 10 Oktober 2026

Selesai: audit service/parser/filter/cache/controller/Blade/JS. Tiga judul terbaru memperoleh HTTP200 dengan masing-masing5 kandidat (total822/630/48). Semua memiliki small/regular URL, tetapi filter lama menolak seluruhnya. Penyebab terbukti: sup mensyaratkan frasa tofu soup, tidak menerima soup with tofu; dua query Indonesia menghasilkan kandidat tidak relevan. Snapshot menyimpan placeholder dari hasil tersebut. Tidak ditemukan field metadata hilang di Controller/Blade.

Perbaikan: kata inti sup tahu boleh terpisah, query sayur bening/tumis tahu dipetakan Inggris beserta modifier jagung/wortel. Noodle soup dan foto hidangan lain tetap ditolak. Log alasan kandidat aman ditambahkan; cache khusus gambar versi5 tanpa flush cache Gemini. Foto sup HQNhc58_6mI dari respons asli diproses offline dan dicache. Dua respons lama tidak disimpan untuk query baru. Snapshot/favorit lama tidak ditulis ulang.

Jaringan: percobaan awal sandbox gagal koneksi tanpa respons HTTP; satu batch berizin jaringan menghasilkan3HTTP200. Tidak ada batch tambahan setelah itu; nol Gemini. Replay dan tests memakai Http::fake/preventStrayRequests. Anggaran lokal untuk koneksi gagal tetap konservatif, tidak direset.

Browser: render Blade memakai snapshot asli + metadata replay offline. Foto sup termuat400px pada kartu dan1080px detail, complete=true/fallbackUsed=false; dua placeholder SVG termuat800px. Ini bukan submit ulang pada session pengguna. Ketiga foto untuk judul terbaru belum tersedia: dua pencarian baru belum diuji live demi batas request. Tidak ada klaim 3foto live sukses. Jalur 3foto valid sampai Controller/Blade/detail/favorit dibuktikan Feature Test.

File diubah: app/Services/RecipeImageService.php; tests/Feature/RecipeImageServiceTest.php; tests/Feature/RecipeImagePagesTest.php; docs/arsitektur.md; docs/UNSPLASH_SETUP.md; PROGRESS.md. Laporan baru docs/UNSPLASH_DEBUG_2026-10-10.md memuat seluruh15kandidat/URL dan tabel trace. Build aset diulang tanpa perubahan frontend.

Verifikasi: php artisan test116tests870assertions lulus; Pint--testlulus; route:list16; npmrunbuildlulus. Tidak ada test gagal tersisa. Gemini/pengurutan/jumlah/UI/favorit/history tidak diubah. Selanjutnya pencarian baru memakai query baru; hasil tersimpan sebelumnya tetapfoto lama. Tidak lanjut Step9.


## Urutan pencarian telur dadar — 10 Oktober 2026

Sesuai urutan pengguna, variasi judul berawalan telur dadar dicari dengan kata Indonesia asli: nama lengkap → hapus kata terakhir → telur dadar. Contoh: telur dadar wortel tahu → telur dadar wortel → telur dadar. Query duplikat dihapus, maksimal tiga tahap. Keluarga masakan lain mempertahankan strategi sebelumnya.

Tahap berikutnya hanya berjalan setelah respons sukses dengan results valid tetapi tanpa foto relevan. Berhenti saat foto ditemukan; error HTTP/koneksi/JSON tidak memicu perluasan. Tiap tahap menjalankan resep yang masih belum memperoleh foto secara paralel (maksimal3), jadi batas terburuk9 pencarian untuk3 variasi, bukan3 total. Budget45/jam, cadangan quota2 dan cooldown diperiksa kembali setiap tahap. Lock45detik meliputi maksimal3 tahap timeout10detik. URL, atribusi dan relevansi tetap divalidasi. Tidak ada query tambahan di GET detail/favorit.

Cache gambar versi6 menyimpan hasil akhir; placeholder tahap sementara tidak disimpan sebagai hasil akhir. Jika budget/cooldown menghentikan proses, placeholder tetap ditampilkan dan proteksi tidak dilewati. Cache Gemini/snapshot lama tidak dihapus. Tidak ada request live pada perubahan ini.

File diubah: app/Services/RecipeImageService.php; tests/Feature/RecipeImageSearchOrderTest.php (baru); tests/Feature/RecipeImageServiceTest.php; tests/Feature/RecipeImagePagesTest.php; README.md; docs/arsitektur.md; docs/UNSPLASH_SETUP.md; PROGRESS.md.

Pengujian php artisan test:121tests906assertions lulus. Cakupan: urutan persis pengguna, berhenti pada masing-masing tahap1/2/3, cachehit, seluruh hasil kosong, errorprovider/JSON tanpa perluasan, budget habis, dan hanya resep belum selesai yang berlanjut tanpa menukar foto. Dua expectation lama gagal pada runawal karena perubahan query/jumlahpencarian yang disengaja; disesuaikan dengan kontrak baru, tidak ada kegagalan tersisa. Tidak mengubah frontend sehingga build tidak diulang. Tidak ada klaim ketersediaan foto live, tidak lanjutStep9.


## Foto sejenis Unsplash — 10 Oktober 2026

Urutan variasi telur dadar kini: nama lengkap → hapus kata terakhir → telur dadar → vegetable omelette → omelette. Query duplikat dihapus (judul tiga kata memiliki empat tahap). Tahap berhenti ketika foto valid ditemukan. Relevansi tetap mensyaratkan identitas omelette dan menolak konflik seperti soup/quiche; tidak wajib seluruh bahan tambahan terlihat. Label ilustrasi dan atribusi tetap memakai komponen yang ada.

Cache khusus gambar versi7; cache sejenis recipe-image-similar:1:omelette menyimpan metadata foto Unsplash yang sudah tervalidasi dengan TTL gambar. Lookup mendahulukan hasil perjudul, lalu foto sejenis untuk keluarga telur dadar, sebelum HTTP. Alt disesuaikan dengan judul tujuan; URL/atribusi asli dipertahankan. Foto telur tidak dipakai untuk keluarga nasi/sup. Negative cache perjudul tetap dihormati sampai kedaluwarsa; tidak flush Gemini atau snapshot/favorit.

Maksimal5 tahap perjudul (15 request untuk3 judul pada kondisi terburuk),3 request paralel per tahap. Budget45/jam dan cadangan2 tetap dicek tiap tahap; cooldown/error HTTP/koneksi/JSON menghentikan perluasan. Lock gambar65detik mencakup5 tahap timeout10detik. Jumlah request aktual berhenti lebih awal saat cachehit/fotosukses/kuota. Tidak ada request live dalam perubahan ini; tidak menjamin provider selalu tersedia.

File diubah: app/Services/RecipeImageService.php; tests/Feature/RecipeImageSearchOrderTest.php; tests/Feature/RecipeImageServiceTest.php; tests/Feature/RecipeImagePagesTest.php; README.md; docs/arsitektur.md; docs/UNSPLASH_SETUP.md; PROGRESS.md.

php artisan test:122tests920assertions lulus. Test membuktikan urutan/stop pada tahap1 sampai5, placeholder ketika semua kosong, budget/error menghentikan pencarian, cache sejenis lintas judul tanpa request tambahan dan tidak bocor ke kategori nasi, serta kartu/detail/favorit tetap berjalan. Tidak ada test gagal. Tidak mengubah integrasi Gemini, jumlah/urutan resep, Controller, Blade, atau fitur favorit/riwayat. Pencarian baru memakai strategi ini; snapshot lama tetapmetadata lama. Tidak lanjut Step9.

Verifikasi akhir: Pint --test lulus; route:list 16 route; npm run build lulus (Node lokal22). Aset tidak berubah. Tidak ada pengujian live Unsplash/Gemini.


## Pencarian bertahap seluruh judul — 10 Oktober 2026

Menggantikan batas lingkup telur dadar: buildSearchQueries menerima semua judul, selalu mulai dari nama asli ternormalisasi. Selanjutnya nama tanpa kata terakhir (hanya jika keluarga masakan tidak berubah), nama keluarga, dan padanan Inggris terkontrol. Maksimal5 query unik. Telur dadar tetap mempertahankan vegetable omelette → omelette. Nasi goreng, mi, sup, tumis, ayam goreng/bakar, ikan goreng, telur kukus, bakwan, perkedel, capcay, soto, sate, rendang memiliki pemetaan. Nama tak dikenal menggunakan nama lengkap → hapus kata terakhir → dua kata pertama; judul satu kata tetap dicari. Tidak menjanjikan terjemahan semua kata atau menebak bahasa Inggris via Gemini. Filter nama tak dikenal memerlukan kata inti pada metadata; keluarga dikenal memerlukan identitas hidangan sesuai alias.

Cache foto versi8, cache keluarga versi2 berdasarkan hash keluarga, terisolasi antarkeluarga. Nama tak dikenal tidak berbagi cache sejenis. Cache Gemini/snapshot/favorit tidak dihapus. Batas5 tahap/15request terburuk, pool3, lock65detik, budget45/jam, cadangan2, cooldown dan stop pada error tetap. Contoh sup ... tahu tidak disederhanakan menjadi sup ... tanpa tahu karena identitas berubah. Foto tetap ilustrasi, tidak menjamin seluruh bahan terlihat.

File diubah: app/Services/RecipeImageService.php; tests/Feature/RecipeImageSearchOrderTest.php; tests/Feature/RecipeImageBatchTest.php; tests/Feature/RecipeImageServiceTest.php; tests/Feature/RecipeImagePagesTest.php; README.md; docs/arsitektur.md; docs/UNSPLASH_SETUP.md; PROGRESS.md.

php artisan test:125tests978assertions lulus. Test mencakup9rencana judul,6jenis pencarian sampai tahap terakhir termasuk nama tak dikenal, cache tanpa request ulang, pemisahan cache nasi/mi, nama unknown tidak berbagi cache, keamanan identitas sup tahu,5tahap telur, kuota/error, pool paralel, dan halaman detail/favorit. Sebelas expectation/fake lama awalnya gagal karena mengharapkan query Inggris langsung, jumlah request satu tahap, serta metadata fake berdasar query lama; disesuaikan dengan kontrak nama asli lebih dahulu. Semua tes kini lulus; tidak ada error tersisa.

Tidak ada live Unsplash/Gemini. Gemini, jumlah/kategori rekomendasi, UI, favorit dan riwayat tidak diubah. Hasil foto untuk setiap kata kunci tetap bergantung pada kandidat relevan; bukan jaminan foto selalu tersedia. Tidak lanjutStep9.

Verifikasi akhir: Pint --test lulus, route:list16route, npm run build lulus. Tidak ada perubahan aset frontend.


## Step 8.6 — Smart Ingredient Checklist & menu tanpa tambahan — 11 Oktober 2026

Selesai: checkbox otomatis dari input nyata/alias, tambahan tidaktercentang, optional terpisah, takaran ditampilkan, stok awal lain tetapdapat dikonfirmasi. State JS memperbarui count/status/daftarkekurangan, PATCH menyimpan konfirmasi ke session per snapshot (max30alur) dengan CSRF, owner, validasi ID katalog, throttle dan serialwrites. TidakadaGemini saatcentang/GET. Tombol alternatif aktif hanya saat masihkurang dan stok>0, loadingstate dan submitsekali.

Strict0–3: promptterpisah, schema0–3, canonicalcachekeybermode, prompt6/schema4. Backendmembuang resep dengan tambahanwajib/alergi/bahangabungan/semuaopsional tanpa mengubah optional atau memaksa3. Hanya hasilbersih dicache; empty state jelas. Snapshot mendukung0–3strict. Favorit/history/gambarlama berfungsi, modehistory disimpan JSON tanpa migration. Repeat/edit strict mendukung105bahan katalog; normal tetap15. UIlanding/navbar/footer tidakdirombak, hanya hiddenmode dan kapasitasform kondisional untukulangstrict.

Mode normal dipertahankan tanpa kuota tambahan sesuai revisi pengguna sebelumnya. File instruksi baru menyebut rentanglama; klarifikasi telahditanyakan dan belumdijawab, sehingga mengikuti keadaan berjalan (sudah dikomunikasikan) alih-alih mengembalikan bug penolakankategori.

File lengkap yangdibuat/diubah dan penjelasan arsitektur di docs/STEP_8_6.md. Ringkas: RecipeMode, IngredientChecklistService,2FormRequest, Bladecomponent/JSchecklist baru; GeminiRecipeService,Matching,Snapshot,CacheKey,Controller,History,GenerateRequest,FailureResponse,config/routes,detail/preview/card/history/forminput,JSapp/input;2testbaru dan1testteksdisesuaikan;README/arsitektur/progres diperbarui. Tidak ada packagebaru atau migration.

Tes saatini139tests1103assertions lulus; testtekslama sempatgagal karena pesan checklistdiganti, sudahdisesuaikan. BrowserQAterisolasi membuktikan count4→3 saatminyakdicentang, reloadpersist,0requestfake sebelumalternatif. Uncheckair→hanya telur/minyak→empty;checkair→1resepvalid; detailreadytombolnonaktif.2requestfake untuk2klik pencarian,0APIlive. Database/cache/sessionQA di/tmp, development tidakditimpa. Screenshot /private/tmp/dapur86/hasil-strict.png.

Batasan: belumujilivepromptstrict; stoktidakmemilikikuantitas; validasi daftar bahan tidakmembuktikan seluruh narasimasakAIrealistis. Favorit terpisah tetapmanual tanpa stokaktif. BelumlanjutStep9/deploy. Langkahberikut: pengguna mencoba alur detail→checklist→alternatif, serta mengonfirmasi bila memang ingin mengembalikan kuotakategorilama.

Verifikasi akhir: php artisan test **139 tes / 1103 assertions lulus**; Pint --test lulus; route:list **18 route**; npm run build lulus menggunakan Node lokal22 (CSS57.41kB, JS6.40kB). Tidak ada kegagalan tersisa. Server QA dihentikan setelah pemeriksaan, tidak mengubah server development pengguna.

## Step 8.7 — Interactive Cooking Guide — 12 Oktober 2026

Selesai: prompt kedua mode meminta 5–10 langkah untuk pemula, dari persiapan sampai penyajian, tingkat api/tanda matang/durasi bila relevan, dan tips khusus per langkah. Schema steps kini object dengan step_number, title, instruction, duration_minutes (integer positif/null), cooking_tip. Backend memvalidasi field, tipe, batas jumlah dan nomor berurutan; fingerprint duplikasi memakai instruksi. Prompt7/schema5 memisahkan cache baru tanpa flush cache lama. Batas output tetap 3000, tidak dinaikkan otomatis.

Normalizer khusus tampilan membaca string lama maupun object baru; tidak mengubah favorit, fingerprint, database atau snapshot lama dan tidak mengarang tips yang tidak ada. Penyimpanan JSON favorit/cache yang sudah ada mempertahankan tips per langkah tanpa migration.

Detail memakai Blade component dan Vanilla JS: checkbox wajib mengontrol panduan, opsional tidak menghalangi, jumlah/progres persiapan dan daftar kekurangan tampil, satu langkah aktif dengan durasi/tips, navigasi berbatas, pesan selesai dan ulangi panduan. Menghapus centang menyembunyikan panduan sambil mempertahankan posisi sementara. Favorit tanpa konteks stok memakai checklist manual; tidak mengasumsikan bahan tersedia. Perubahan checkbox tetap memakai mekanisme session Step8.6, tanpa Gemini. Kategori, matching, alternatif, gambar, navbar dan landing tidak dirombak.

File baru: app/Support/RecipeStepNormalizer.php, resources/views/components/cooking-guide.blade.php, resources/js/cooking-guide.js. File berubah: app/Services/GeminiRecipeService.php, config/ai.php, resources/views/recommendations/detail.blade.php, resources/js/app.js, public/build/manifest.json dan aset hasil build, PROGRESS.md. Fixture dan dua test yang sudah ada disesuaikan dengan kontrak baru: tests/Support/GeminiFixture.php, tests/Feature/GeminiRecipeServiceTest.php, tests/Feature/RecommendationPagesTest.php. Tidak membuat Unit/Feature Test baru.

Verifikasi: npm run build lulus (Node lokal22); Pint pada file PHP terkait lulus. Pengujian terbatas dua file terkait schema/halaman: 27 tes, 199 assertions lulus. Pemeriksaan lokal tanpa API membuktikan data baru valid, nomor salah/durasi bertipe salah/tips kosong ditolak, dan string lama dinormalisasi tanpa tips tambahan. Satu kegagalan awal karena label judul halaman telah diperbaiki dan pengujian ulang lulus. Seluruh suite tidak dijalankan. Tidak ada request Gemini/Unsplash sungguhan atau perubahan data development.

Belum diverifikasi: interaksi browser end-to-end dan keluaran prompt baru dari API live. Structured output yang lebih rinci dapat mencapai batas 3000 token; respons terpotong tetap ditolak, jangan mengklaim live berhasil. Teks/nalar memasak AI tetap memerlukan pemeriksaan pengguna.

Manual testing berikutnya: buka detail resep tersimpan/hasil pencarian; pastikan stok otomatis, bahan kurang tidak dicentang dan opsional tidak menghalangi. Centang seluruh wajib → panduan muncul; maju/mundur sampai selesai, ulangi; hapus satu centang → panduan tersembunyi, centang lagi → posisi tetap. Periksa favorit lama tanpa tips per langkah, tampilan mobile, dan tombol Cari Menu dari Bahan yang Ada. Pencarian baru/alternatif dapat memakai kuota API jika cache miss. Belum masuk Step9.

## Prioritas nol tambahan untuk Paling Cocok — 12 Oktober 2026

Normal: prompt meminta pencarian kandidat realistis 0 → 1 → 2 tambahan wajib. Backend mengurutkan ascending berdasarkan bahan wajib unik yang kurang, tie-break jumlah bahan wajib pengguna yang terpakai; opsi tidak dihitung. Kandidat teratas lebih dari2 tambahan atau tanpa bahan wajib ditolak. Alergi, schema langkah dan nama bahan gabungan tetap divalidasi. Card sudah menampilkan Semua bahan tersedia/daftar kekurangan, sehingga UI tidak diubah.

Jika hasil normal pertama valid tetapi belum nol, maksimal satu perbaikan mencari nol. Hasil kedua digunakan hanya bila lebih baik berdasarkan kekurangan lalu jumlah bahan tersedia; jika perbaikan gagal, hasil pertama yang valid1–2 tambahan dipertahankan. Jika awal belum ada kandidat<=2, perbaikan satu kali boleh dijalankan; kegagalan tidak dicache. Retry transport, koreksi nama gabungan dan optimasi nol berbagi batas total2request. Strict tidak mengikuti optimasi ini. Cache normal mendapat versi zero-first-1; schema5/model/parameter dan cache strict tetap, tanpa flush. Snapshot historis/favorit tetap dibaca sebagai hasil terdahulu; lakukan pencarian baru untuk aturan baru.

Keputusan lingkup: instruksi lampiran menyebut rentang lama Cukup1–3/Alternatif>=4, tetapi kode dan revisi pengguna sebelumnya tanpa minimum. Klarifikasi diajukan, belum dijawab; mengikuti instruksi fokus Paling Cocok dan tidak mengubah kategori lain, sehingga kedua kategori lain tetap peringkat relatif tanpa minimum. Perubahan ini tidak mengembalikan kuota lama.

File: GeminiRecipeService, RecipeMatchingService, RecipeCacheKey, RecipeGenerationException, config/ai.php, tests/Feature/GeminiCategoryRepairTest.php, docs/arsitektur.md, PROGRESS.md. Tidak ada perubahan frontend, gambar, Controller, checklist, panduan atau database.

Pemeriksaan: 39 tes terkait service/repair/strict lulus (308assertions), lalu dua skenario ranking tambahan; rerun file repair 10tes/50assertions lulus. Pint file terkait lulus. Tidak menjalankan fullsuite, build, atau API live. Browser/live belum diuji. Manual: pencarian baru telur,nasi,cabai,minyak → periksa Paling Cocok dan daftar kekurangan; ulang urutan bahan → gunakan cache; opsi tambahan tidak menambah hitungan. Pencarian baru dapat memakai API; belum dilakukan oleh agen.

Batasan: jumlah stok tidak diketahui; pemeriksaan struktural/backend tidak membuktikan kelayakan semua narasi masak atau bahwa resep nol lain tidak ada di dunia nyata. Sistem memprioritaskan kandidat valid yang dihasilkan dan melakukan satu pencarian perbaikan, tanpa mengklaim pencarian menyeluruh. Tidak lanjut Step9.

## Bug fix rentang Cukup Cocok dan Alternatif Menu — 12 Oktober 2026

Penyebab terkonfirmasi: backend hanya memeriksa batas Paling Cocok, lalu memberi badge dua kategori lain berdasarkan posisi; prompt masih tanpa minimum. Sesuai instruksi terbaru, kategori normal kini wajib lengkap: Paling0–2 dengan prioritas/tie-break semula, Cukup1–3 dari resep berbeda, Alternatif>=4. Matching tetap menghitung wajib unik, alias/normalisasi, tidak mengasumsikan bumbu tersedia, opsional tidak dihitung. Pilihan Paling Cocok dipesan terlebih dahulu dan tidak dipindahkan untuk mengisi kategori lain.

Perbaikan kategori maksimal sekali, berbagi batas2request dengan retry/optimasi nol. Backend membawa resep valid dalam repairContext terpisah dari diagnostics/log, meminta hanya kategori kosong diperbaiki (envelope3resep/schema tetap), mempertahankan data asli kategori valid, dan memvalidasi ulang schema/alergi/keunikan/rentang/urutan sebelum cache. Perbaikan yang tetap tidak lengkap gagal aman tanpa cache. Tidak ada bahan palsu atau perubahan optional otomatis.

Cache normal versi category-ranges-2; cache strict, schema/model/timeout/token, Unsplash dan database tidak berubah. Snapshot normal dibuka ulang dengan matching terbaru tanpa API; snapshot salah rentang menghasilkan halaman kedaluwarsa410, bukan badge salah. Favorit tidak dihapus. UI/Controller/checklist/guide tetap.

File berubah: app/Services/{GeminiRecipeService,RecipeMatchingService,RecommendationSnapshotService}.php; app/Exceptions/RecipeGenerationException.php; config/ai.php; tests/Feature/RecipeCategoriesTest.php; docs/arsitektur.md; PROGRESS.md.

Verifikasi terbatas karena perubahan logika utama: 32tes/234assertions lulus pada RecipeCategoriesTest, GeminiCategoryRepairTest, AvailableRecipesTest; Pint file terkait lulus. Kasus mencakup pengurutan acak, count card/detail,0–0–4, alternatif3, mempertahankan resep valid, penolakan setelah retry, cachehit, snapshot lama, dan cache strict tetap. Tidak menjalankan fullsuite/build atau API live. Browser/live belum diuji untuk perubahan ini.

Manual berikut: pencarian baru → periksa card Paling0–2, Cukup1–3, Alternatif>=4 dan cocokkan daftar dengan detail; ulang bahan terbalik untukcache; periksa mode hanya bahan tersedia. Cache miss dapat memakai maksimal2requestGemini. Batasan: kelayakan masakan dan perbedaan bermakna secara semantik tidak dapat dijamin hanya melalui hitungan/nama/fingerprint; keluaran AI tetap perlu diperiksa pengguna. Belum Step9.

## Pembatalan bug fix rentang kategori — 12 Oktober 2026

Atas permintaan pengguna, perubahan bug fix Cukup Cocok/Alternatif terakhir dibatalkan. Backend/prompt, snapshot, exception, versi cache normal zero-first-1 dan test kategori dikembalikan ke keadaan sebelum bug fix. Catatan bug fix di atas dipertahankan sebagai riwayat, bukan keadaan aktif. Paling Cocok tetap prioritas0→1→2; dua kategori lain kembali peringkat relatif tanpa minimum. Tidak mengubah UI, strict, Unsplash, favorit atau database; tidak flush cache dan tidak melakukan API live.

## Prioritas kategori terbaru — 12 Oktober 2026

Menggantikan aturan setelah pembatalan sebelumnya: Paling Cocok tetap0→1→2 dengan kode seleksi/tie-break awal. Setelah kandidat pertama dikeluarkan, Cukup Cocok memilih rentang0–3 secara descending3→2→1→0, tie-break jumlah bahan wajib tersedia. Alternatif memilih sisa rentang>=4 ascending4→5→6→seterusnya dengan tie-break sama. Rentang Cukup/Alternatif tidak tumpang tindih. Hitungan normalisasi/alias/wajib unik/opsional tetap RecipeMatchingService. Schema/API masih menghasilkan tepat3 kandidat, hanya sukses jika ketiganya unik dan semua kategori terpenuhi; bukan pencarian seluruh kemungkinan resep.

Prompt normal diperbarui untuk prioritas ini dan melarang bahan palsu/manipulasi optional. Kategori kosong memicu satu perbaikan dengan feedback nama kategori dalam budget bersama maksimal2request; hasil tidak lengkap tidak dicache. Optimasi Paling Cocok dan fallback hasil awal hanya berlaku pada set yang sudah valid seluruh kategori. Mode strict, schema, model, Unsplash, UI/checklist/guide/favorit/database tidak diubah.

Cache normal category-priority-3, strict key tetap; tidak flush. Snapshot normal lama dihitung ulang saat GET tanpa API; yang tidak memenuhi aturan baru mendapat halaman410 yang ada. Hasil lama tidak otomatis dipercaya berdasarkan badge tersimpan.

File berubah: app/Services/RecipeMatchingService.php, GeminiRecipeService.php, RecommendationSnapshotService.php; app/Exceptions/RecipeGenerationException.php; config/ai.php; tests/Feature/RecipeCategoriesTest.php; docs/arsitektur.md; PROGRESS.md.

Pemeriksaan terbatas:31tes/250assertions lulus (RecipeCategoriesTest, GeminiCategoryRepairTest, AvailableRecipesTest), Pint file terkait lulus. Tidak fullsuite/build/API live. Browser/live belum diuji. Manual berikut: pencarian baru, periksa urutan card dan daftar tambahan dengan detail (Paling<=2, Cukup<=3, Alternatif>=4); ulang input terbalik untukcache; cek mode tanpa tambahan tetap0. Cache miss dapat memakai kuotaAPI. Kewajaran resep/keunikan semantik masih perlu pemeriksaan pengguna; backend tidak menganggap hitungan sebagai bukti keamanan/kelayakan memasak. Tidak lanjut Step9.

## Katalog pilihan bahan dan bumbu dapur — 12 Oktober 2026

Selesai: katalog statis137bahan dalam8kategori sesuai daftar pengguna, pencarian sebagian kata tanpa membedakan kapital, filterkategori, tombol terpilih/aria-pressed, klik ulang menghapus, empty state dengan tambahmanual. Inputmanual/chip/katalog memakai satu stateJS dan hiddeningredients[] yang sama; count Bahan dipilih:X, batas normal15/strict105 tetap, tidak otomatis memilih bumbu. Drafinputmanual tetap saat memilih dari katalog.

config/ingredients.php mudah ditambah: masukkan string bahan ke array kategori terkait. Tidak ada tabel/packagebaru. Alias existing RecipeMatchingService diekspos melalui aliases() dan dibaca Blade→JS; GenerateRecipeRequest menormalisasi bahan memakai service yang sama. Cabe/cabai, cabe merah/cabai merah, cabe rawit/cabai rawit, garlic/bawang putih konsisten; jenis telur/susu/tepung/cabai berbeda tidak disamakan. Tidak menambah pemetaan minyak sayur/goreng karena konteks belum dibatasi.

File baru: config/ingredients.php, resources/views/components/ingredient-catalog.blade.php. File berubah: resources/views/components/ingredient-input.blade.php, resources/js/ingredient-input.js, app/Services/RecipeMatchingService.php (akses alias saja), app/Http/Requests/GenerateRecipeRequest.php, public/build/manifest.json/aset, PROGRESS.md. Algoritma kategori/provider/strict/checklist/guide/favorit/riwayat/gambar tetap.

Verifikasi manual browser:137pilihan tampil, BAWANG→5hasil, cabe rawit→1hasil; tambahpilihan/sinonim manual→chip+statussinkron; duplikat kapital/spasi ditolak; hapuschip melepas pilihan; Daun kari tidakditemukan→tambahmanual berhasil; filterBumbuDasar23pilihan; bahan ke16 ditolak dan form tepat15hiddeninputs. Desktop1440/mobile390 tanpa horizontaloverflow; konsol tidakmencatat errorJS. Kontrol browser pada tablama timeout, diselesaikan dengan tabpengujianterpisah. npm run build lulus (Node lokal22), PintPHPterkait lulus. Tidak membuat/menjalankan automatedtest atau melakukan APIlive.

Langkah manual pengguna: cari dan pilih Bawang putih, coba inputmanual BAWANG PUTIH (ditolakduplikat), hapuschip, cek statuspilihan; cari bahan di luarkatalog lalu tambahmanual; pilihkategori dan periksa count. Submitrekomendasi tidakdiuji untuk menghindari API tanpaizin. Tidak lanjutStep9.

## UI bahan terpadu — 12 Oktober 2026

Bahan Tersedia kini satu section dalam cardform yang ada: judul/count, satu input pencarian+manual, kategori tombol (scrollhorizontalmobile), katalog max-h56, satu daftar chip terpilih. Judul/inputpencarian kedua dan bordercardkatalog dihapus. Seluruh137bahan/8kategori tetap; labelRempah/MinyakdanCairan diringkas hanya di UI.

Stateingredients tunggal tetap mencakup manual/katalog/oldinput; hiddeningredients[] hanya pilihan. Input memfilter katalog; Enter/Tambah mengonfirmasi; klikpilihan menambah dan mengosongkan input. Hapus menyinkronkan status/count. Submit saat ada teks belumdikonfirmasi ditahan dengan petunjuk, tidak otomatis menambahkan teks pencarian. Backend/normalisasi/session/provider/kategori/fiturlain tidak diubah.

File: resources/views/components/ingredient-input.blade.php, ingredient-catalog.blade.php, resources/js/ingredient-input.js, public/build/manifest.json/aset, PROGRESS.md. Build lulus. Manualbrowser: BAWANG5hasil, pilih→inputkosong, Daunkari+Enter→chip, duplikatspasi/kapital ditolak, submitteksbelumkonfirmasi tertahan, hapuschip mereset aria-pressed, BumbuDasar23hasil, satuinputhidden untuk satupilihan. Mobile390 tidakoverflow, konsol tanpaerror. Tidak automatedtest/APIlive. Submit nyata tidak dilakukan. Coba sendiri: cari bahan→pilih→ketik bahanmanual→Enter→hapuschip; pastikan count/status sinkron. Tidak lanjutStep9.

## Fallback Alternatif Menu — 12 Oktober 2026

Aturan Alternatif terbaru menggantikan penolakan otomatis saat tidak ada kandidat>=4. Seleksi Paling0→1→2 dan Cukup3→2→1→0 tetap. Setelah keduanya dikeluarkan, Alternatif memakai4→5→6→seterusnya; jika tidak tersedia dari hasil valid/upaya terbatas, gunakan3→2→1→0. Bahan wajib/opsional tidak diubah; jumlah card/detail tetap angka matching asli.

Service menyimpan sementara set3resep valid dengan alternatif<4, lalu melakukan satu upaya mencari alternatif>=4 dalam budget total2request (berbagi dengan optimasi nol/retry). Jika perbaikan gagal termasuk kuota/timeout/JSONrusak, setawal valid tetap dapat dipakai; tidak ada requestketiga. Hasil kedua valid dibandingkan, alternatifutama>=4 tidak dikalahkan fallback. Penggabungan dengan resep terpilih divalidasi ulang penuh (schema,unik,alergi,matching); jika kombinasi tidakvalid, pilih setlengkapvalid tanpa duplikat. Cache hanya hasilvalidakhir; cachehit tidakmengulangrepair. Jika kategoriPaling/Cukup atau3resepunik tidakterpenuhi, tetapgagalaman.

Versi cache normal alternative-fallback-4, strict/Unsplash/schema/model/TTL tetap, tanpa flush. File: app/Services/RecipeMatchingService.php, GeminiRecipeService.php, config/ai.php, tests/Feature/RecipeCategoriesTest.php, docs/arsitektur.md, PROGRESS.md. UI/backendrequest/checklist/guide/favorit/riwayat tidakdiubah.

Verifikasi terbatas33tes270assertions lulus (RecipeCategoriesTest, GeminiCategoryRepairTest, AvailableRecipesTest), Pintfileterkaitlulus. Duakegagalan awal menunjukkan kombinasi duplikat menyebabkan hilangnya prioritas>=4; diperbaiki dan rerunlulus. Kasus fallback0–3,setsemua0,repairmenemukan4,pertahankan4vsfallback,cachehit,budget,strict. Tidak fullsuite/build/APIlive. Browser/live belumdiuji. Manual: pencarianbaru, pastikan ketigacardunik; bilaAlternatif<4, daftar/card/detail tetapcountasli; ulanginputmemakaicache. Kelayakan narasi/keunikan semantik tetap bergantung kualitasAI dan pemeriksaanpengguna, bukan hitungan saja. BelumStep9.

## Debug rekomendasi belum tersedia — 12 Oktober 2026

Log terbaru menunjukkan connection_or_timeout sekitar 5 detik, serta request lain HTTP 200 yang ditolak dengan invalid_item_count. Log lama tidak mencatat field/jumlah sehingga belum dapat memastikan apakah jumlah resep, bahan, atau langkah yang salah. Jumlah bahan pilihan pengguna bukan bukti penyebab kegagalan tersebut.

GeminiRecipeService kini mencatat path schema dan jumlah/minimum/maksimum secara aman tanpa isi input, prompt, atau API key. Pada mode normal, invalid_item_count dari upaya pertama dapat diperbaiki sekali dengan feedback struktur; tetap berbagi budget maksimal dua request dengan retry/repair lain. Validasi tidak dilonggarkan, hasil rusak tidak dicache, dan fallback hasil awal yang valid tetap dipertahankan. Timeout tetap gagal aman tanpa retry otomatis; tidak mengubah timeout, model, UI, Unsplash, atau database.

File berubah: app/Services/GeminiRecipeService.php, tests/Feature/GeminiCategoryRepairTest.php, PROGRESS.md. Pint lulus. Pengujian terbatas GeminiCategoryRepairTest dan GeminiRecipeServiceTest: 31 tes / 203 assertions lulus. Skenario simulasi empat langkah membuktikan repair struktur dan cache hasil valid; bukan bukti field yang salah pada respons live sebelumnya. Tidak menjalankan full suite, build, atau API sungguhan.

Manual berikut: kirim satu pencarian dari browser. Jika tetap gagal, periksa log gemini.structure_validation untuk field/jumlah aktual dan metrik kegagalan terbaru. Keberhasilan live setelah perbaikan belum diverifikasi; koneksi timeout masih dapat terjadi. Tidak lanjut tahap berikutnya.

## Step 9 — Persiapan deployment — 12 Oktober 2026

Selesai: audit konfigurasi environment/database/session/cache, migration, middleware/routes, service API dan aset. .env.example diberi petunjuk production dan opsi HttpOnly/SameSite tanpa mengubah .env lokal. DEPLOYMENT.md memuat kebutuhan runtime, clone/install/build, konfigurasi secret, APP_KEY instalasi baru, SQLite persisten, backup/migration, public web root/HTTPS/proxy/permission, optimasi, pemeriksaan, update dan rollback. Driver/model/provider, fitur serta algoritma rekomendasi tidak berubah.

Verifikasi: composer check-platform-reqs --no-dev lulus pada PHP lokal 8.3.13; npm run build lulus (Node lokal22, CSS57.60kB/JS9.60kB). config:cache, route:cache, view:cache dan route:list lulus menggunakan path sementara, environment simulasi production dan API key kosong; cache lokal tidak dihapus. QualityAssuranceTest yang sudah ada:3tes39assertions lulus. Tidak ada tes baru/fullsuite/API live. Referensi key API tidak ditemukan di resources/public build. git diff --check lulus. Pemeriksaan HTTP localhost:8000 tidak terhubung (HTTP000 dari lingkungan tool); browser/responsivitas tidak diuji ulang, UI tidak berubah.

File: .env.example, DEPLOYMENT.md, PROGRESS.md; artefak build lokal diabaikan Git. Belum deploy/commit/push, tidak ada migration pada DB development/production, tidak mengubah secret. Kesiapan server belum dibuktikan: hosting/domain, disk persisten/database, HTTPS/trusted proxy, timeout sinkron, backup dan release perlu keputusan pengguna. Manual berikut: ikuti checklist DEPLOYMENT.md setelah target disepakati; uji localhost dengan server aktif, cek homepage/chips/favorit/riwayat tanpa submit AI; live smoke test menunggu izin. Masalah provider timeout/struktur dari tahap sebelumnya masih memerlukan verifikasi live terpisah, tidak diklaim selesai melalui persiapan deployment.

## Bug mode STRICT terbawa ke Cari Ide — 13 Oktober 2026

Penyebab: edit snapshot dan ulang riwayat memulihkan mode STRICT melalui old input; Blade menerbitkan hidden mode tersebut dan batas105; GenerateRecipeRequest menerima mode dari browser. Cache sudah memasukkan mode dalam hash, bukan sumber bug.

Perbaikan: GenerateRecipeRequest menetapkan NORMAL sebelum validasi dan batas15, mengabaikan pilihan mode klien termasuk nilai tidak valid. Endpoint alternatif tetap menetapkan STRICT dari checklist server. Edit/ulang riwayat memulihkan bahan/opsi dengan modeNORMAL tanpa mengubah snapshot/history asli. Hidden mode di form utama dihapus; batas15 konsisten dan JS menahan submit daftar pemulihan >15 dengan pesan untuk mengurangi bahan, tanpa menghapus bahan otomatis. Tidak menghapus session/cache atau mengubah kategori, provider, favorit, checklist, panduan memasak, maupun persiapan deployment.

File: app/Http/Requests/GenerateRecipeRequest.php; app/Http/Controllers/RecommendationController.php dan HistoryController.php; resources/views/components/ingredient-input.blade.php; resources/js/ingredient-input.js; tests/Feature/AvailableRecipesTest.php; PROGRESS.md. Artefak public/build diperbarui lokal (ignored Git).

Verifikasi:25tes195assertions lulus (AvailableRecipesTest, GenerateRecipeTest, RecipeCacheKeyTest), Pint dan git diff --check lulus, npm run build lulus. Kesalahan awal penempatan fixture test diperbaiki sebelum rerun. Regresi meliputi normal→strict→edit/history→normal3unik, cache tanpa request tambahan, snapshotdetail kedua mode tetap dapat diakses, strict0–3 melalui endpointchecklist dan modeklien tak dipercaya. Browser localhost berhasil membuka form normal batas15; alur API penuh tidak diuji live. Tidak API sungguhan/fullsuite/deploy/commit/push.

Manual pengguna: Cari Ide → Lihat Resep → konfirmasi checklist → Cari Menu dari Bahan yang Ada → Ubah Bahan → ubah daftar → Cari Ide. Hasil sukses terakhir harus3resep/kategoriNORMAL; strict sebelumnya tetap dapat dibuka. Bila bahan yang dipulihkan >15, kurangi pilihan sebelum Cari Ide. Kegagalan provider/kuota masih mungkin; perbaikan ini tidak menjamin live API selalu berhasil. Step9.5 ditunda.


## Penghapusan integrasi Unsplash dan gambar resep — 15 Oktober 2026

Source rekomendasi kini langsung menyiapkan snapshot setelah Gemini/ranking tanpa pengambilan gambar. Service gambar, komponen Blade, JavaScript fallback, placeholder SVG serta empat file test gambar dan fixture khusus Unsplash dihapus. Konfigurasi dan .env.example dibersihkan; .env pribadi tidak diubah. Kartu rekomendasi/favorit dan detail hanya memakai informasi teks; checklist, panduan memasak dan seluruh kategori tetap dipertahankan. Riwayat sebelumnya sudah tanpa gambar.

SavedRecipeService tidak menyertakan metadata gambar pada favorit baru; fingerprint tetap sama. Data gambar lama, cache, favorit dan riwayat tidak dihapus atau dimigrasi. Tidak ada dependency khusus Unsplash. README, arsitektur dan panduan deployment diperbarui; laporan Unsplash lama diberi penanda arsip.

Belum menjalankan build, pengujian otomatis/manual, migration atau deployment sesuai batas tugas. Berikutnya: build frontend dan pengujian terisolasi alur rekomendasi normal/alternatif, detail/checklist/panduan, favorit lama/baru, riwayat, kuota/idempotensi dan verifikasi tidak ada request gambar eksternal. Pemilik dapat menghapus variabel UNSPLASH_* serta RECIPE_IMAGE_* sendiri dari .env; aplikasi tidak lagi membacanya.
