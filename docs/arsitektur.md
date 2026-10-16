# Arsitektur Dapur Rantau

Diperbarui setelah perbaikan Step 5, 9 Oktober 2026. Provider AI aktif yang dirancang sekarang adalah **Google Gemini**, menggantikan rancangan provider pada tahap awal. Riwayat tahap sebelumnya dipertahankan dalam PROGRESS.md. Tidak ada implementasi provider lama yang perlu dipertahankan.

## Kondisi dan cakupan

Laravel 13.35.0, PHP minimal 8.3, Blade, Tailwind CSS 4, Vanilla JavaScript, Vite, dan SQLite. Tidak ada framework frontend atau SDK AI tambahan. SQLite/cache database menggunakan migration bawaan. PHP CLI terakhir 8.3.13 melalui Herd; PHP web MAMP belum diverifikasi. Node lokal proyek 22.23.3 memenuhi Vite; Node global 22.10.0 belum memenuhi.

Step 1–4 menyediakan fondasi, landing page, dan input tervalidasi. Step 5 menghubungkan rekomendasi Gemini, caching, limiter, error handling, dan hasil Blade sementara. Desain hasil lengkap, detail, favorit, riwayat, dan tabel bisnis belum dibangun.

## Struktur dan tanggung jawab aktual

```text
app/
  Exceptions/RecipeGenerationException.php
  Http/
    Controllers/HomeController.php
    Controllers/RecommendationController.php
    Middleware/LimitConcurrentRecipes.php
    Requests/GenerateRecipeRequest.php
  Providers/AppServiceProvider.php
  Services/GeminiRecipeService.php
  Support/GeminiErrorDiagnostics.php
  Support/RecipeCacheKey.php
  Support/RecipeFailureResponse.php
config/ai.php
routes/web.php
resources/
  js/app.js
  js/ingredient-input.js
  css/app.css
  views/layouts/app.blade.php
  views/home.blade.php
  views/components/{brand,button,icon,recipe-card,ingredient-input}.blade.php
  views/recommendations/preview.blade.php
  views/recommendations/error.blade.php
```

- Form Request: normalisasi/validasi input serta pesan Indonesia.
- Controller: dependency injection GeminiRecipeService, hanya meneruskan validated data dan memilih HTML/JSON.
- Service: cache lookup/lock, system instruction, prompt, schema, HTTP generateContent, parsing/validasi defensif, retry terbatas, metrik aman.
- RecipeCacheKey: representasi input kanonis dan hash SHA-256 berversi.
- RateLimiter di provider: 5 request/menit/IP. Middleware concurrency: satu request aktif/IP dengan lock backend.
- RecipeFailureResponse: pesan error aman, status HTTP, dan old input pada form HTML.
- Model bisnis tidak dibuat pada Step 5. Eloquent User bawaan tetap tanpa authentication.

## Kontrak request (Step 4 dipertahankan)

`ingredients`: list 1–15 string, tiap string 1–60 karakter setelah trim/normalisasi spasi/lowercase. Duplikat ditolak Form Request; service menduplikasi ulang secara defensif untuk cache key. Nama bahan dibatasi karakter yang diizinkan tanpa HTML.

`servings`: nullable integer 1–10; null/missing memakai 1 pada prompt/cache.
`preference`: nullable enum bebas/hemat/cepat/pedas; default bebas.
`allergies`: nullable string maksimal 600 karakter; service memecah dengan koma, normalisasi/deduplikasi/sort untuk prompt/cache. Tidak mengubah format kiriman browser Step 4.

## Rute dan alur data

- GET `/` (`home`) → HomeController → landing page.
- POST `/rekomendasi` (`recommendations.store`) → web/CSRF → throttle recipes → concurrency lock → GenerateRecipeRequest → RecommendationController → GeminiRecipeService.
- Service kanonisasi input → periksa cache → lock key → periksa ulang cache → jika miss, request Gemini sekali (maksimal satu retry 500/503) → validasi hasil → simpan cache → kembalikan data.
- Accept JSON mendapat objek message/recipes; form browser menerima Blade preview dengan output escaped.
- Error input mengikuti redirect + old input atau JSON 422. Kegagalan layanan HTML/JSON memakai 503 atau 429, pesan Indonesia tanpa detail provider; HTML mempertahankan input.
- Respons sukses sementara masih hasil POST, belum pola POST–redirect–GET. Refresh dapat mengirim ulang POST tetapi hasil valid tersedia di cache selama TTL dan tetap dibatasi limiter.

## Integrasi Gemini

Config `config/ai.php`, environment GEMINI_API_KEY/MODEL/BASE_URL/MAX_OUTPUT_TOKENS/THINKING_LEVEL. Default gemini-3.5-flash-lite, 3000 token, MINIMAL thinking, tanpa sampling override. Key hanya dikirim melalui x-goog-api-key di backend; base URL dibatasi endpoint HTTPS Google resmi dan redirect HTTP dinonaktifkan.

POST `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` dengan systemInstruction, satu contents berisi JSON input, dan generationConfig.responseMimeType/responseJsonSchema/thinkingConfig. Tidak ada riwayat chat, tools, Context Caching, atau Interactions API.

Schema root: message string dan recipes list maksimal 3. Resep: title, description, cook_time_minutes positif (maksimal 1440), servings positif (maksimal 10), difficulty enum mudah/sedang/sulit, ingredients list 1–30 berisi name/quantity/optional boolean, steps list 1–8, tips. Semua field wajib; field tambahan ditolak. `buildResponseSchema()` adalah kontrak validasi backend yang tetap ketat. `buildGenerationSchema()` menurunkan schema tersebut untuk provider dengan menghilangkan minimum/maximum pada waktu/porsi dan minItems/maxItems pada bahan/langkah. Batas root maksimal 3 resep tetap dikirim; batas lainnya tetap ditegakkan Laravel sebelum hasil ditampilkan atau di-cache. Penyederhanaan constraint pengiriman ini mengatasi HTTP 400 dalam uji live, tanpa mengubah kontrak hasil. Versi schema untuk cache dinaikkan menjadi 2. Backend memeriksa tipe JSON asli, teks tidak kosong dan maksimal 1500 karakter per field. recipes kosong diperbolehkan dengan message penjelasan.

Parser memeriksa prompt block, satu candidate, finishReason STOP, safety block, dan text parts non-thought. JSON terpotong/rusak, output kosong/berlebihan, field hilang, serta tipe salah ditolak tanpa fallback contoh. Pemeriksaan alergen literal konservatif menolak konflik yang terdeteksi; sinonim/turunan belum dapat dijamin. Output selalu di-escape.

## Cache, proteksi, dan log

TTL default 86400 detik; dapat dimatikan melalui RECIPE_CACHE_ENABLED. Cache key memuat bahan terurut, porsi, preferensi, alergi terurut, model, versi prompt/schema, thinking dan batas output. Hash memakai JSON terstruktur untuk mempertahankan pemisahan antar-item. Hanya hasil valid di-cache; cache hit divalidasi kembali. Cache rusak dihapus.

Lock key dan lock IP memakai lease 130 detik, dilepas dalam finally; lock sibuk menghasilkan 429 tanpa request duplikat. Driver database/file direkomendasikan; endpoint gagal tertutup jika driver tidak mendukung lock. Lock per IP melindungi concurrent request dengan key berbeda, tetapi pengguna di NAT berbagi batas IP.

Connect timeout 5 detik, request timeout 30 detik (maksimum konfigurasi 60). Maksimal dua attempt hanya untuk 500/503, Retry-After dihormati jika jeda <=2 detik; jika lebih lama tidak retry. Tanpa header tunggu 1 detik. Tidak retry 429, RESOURCE_EXHAUSTED/QuotaFailure termasuk bila HTTP 503, timeout, connection error, input/access/model error, atau output rusak. Tidak menaikkan batas token otomatis.

Log metrik memuat model, hit/miss/disabled, status, durasi, attempt, status HTTP, dan metadata token numerik/null. GeminiErrorDiagnostics mencatat kode/status error, frasa pesan serta tipe detail yang diizinkan, dan nama field konfigurasi yang disebut provider; nilai input/metadata bebas dibuang. Jangan log API key, prompt, body provider, input bahan, alergi, atau stack exception provider. Status aman seperti model_unavailable/missing_api_key membantu diagnosis tanpa mengganti model secara otomatis.

## Tahap selanjutnya (belum dikerjakan)

Step 6: hasil rekomendasi lengkap dan detail sesuai instruksi berikutnya. Favorit, riwayat, identitas tamu, serta model IngredientSearch/Recipe/Favorite akan membutuhkan migration, pembatasan kepemilikan, dan test isolasi. Rancangan ini tidak menyatakan tabel bisnis tersebut sudah ada. Kompatibilitas MySQL harus diuji saat tabel bisnis dibuat. Tidak ada authentication dalam cakupan saat ini.

## Referensi

- https://ai.google.dev/api/generate-content
- https://ai.google.dev/gemini-api/docs/structured-output
- https://ai.google.dev/gemini-api/docs/models
- https://ai.google.dev/gemini-api/docs/rate-limits
- https://ai.google.dev/gemini-api/docs/thinking

## Step 6 — halaman rekomendasi dan detail (implementasi aktual)

Step 6 kini diimplementasikan. POST HTML tetap menampilkan hasil langsung untuk mempertahankan kontrak Step 4/5. Hasil tervalidasi dari GeminiRecipeService diteruskan ke RecipeMatchingService untuk pencocokan, penyaringan alergen dan pengurutan. Kontrak JSON tetap message/recipes tanpa metadata UI, dengan penyaringan/pengurutan yang sama.

RecipeMatchingService memakai trim, lowercase, normalisasi spasi dan alias terbatas: cabe/cabai, cabe merah/cabai merah, cabe rawit/cabai rawit, garlic/bawang putih. Ketersediaan memakai persamaan nama utuh; bahan dasar tidak diasumsikan tersedia. Bahan opsional tidak menghalangi status siap. Pemeriksaan avoidance lebih konservatif memakai batas kata setelah normalisasi (termasuk bahan opsional). Ini bukan deteksi lengkap turunan/sinonim alergen. Daftar alias dapat diperluas secara terkontrol dengan test.

RecommendationSnapshotService menyimpan data UI di Laravel Cache terpisah dengan prefix recommendation-snapshot, TTL 3600 detik, UUID v4 pencarian dan UUID v4 per resep. Session hanya menyimpan token pemilik acak 64 karakter; cache menyimpan hash token itu. Pemeriksaan hash_equals dan expiry dilakukan pada setiap GET. Tidak ada JSON resep dalam URL/cookie session. Input lama di-flash hanya saat pengguna memilih Ubah Bahan. Cache Gemini 24 jam dan konfigurasi generation tidak diubah.

GET /rekomendasi/{search} menampilkan ulang hasil; GET /rekomendasi/{search}/resep/{recipe} membuka detail setelah memverifikasi pemilik dan keanggotaan ID resep. GET /rekomendasi/{search}/ubah mengembalikan input ke beranda. Ketiganya tidak menginjeksi/memanggil GeminiRecipeService. Snapshot hilang/kedaluwarsa/tidak dimiliki menghasilkan halaman ramah 410; ID resep yang tidak cocok menghasilkan halaman ramah 404. Identifier valid milik orang lain tidak mengungkapkan judul atau isi.

Blade components match-badge, recipe-meta, recommendation-card digunakan hasil/detail. Checkbox native hanya mengubah tampilan saat itu, tanpa request atau perubahan data. Step 7 (favorit/riwayat/login) belum dikerjakan; Simpan Favorit disabled dengan keterangan jelas. Tampilan hasil memakai ikon generik, bukan foto hidangan yang tidak terkait.

## Step 7 — persistensi anonim (implementasi aktual)

VisitorIdentity membuat UUID v4 server-side pada session key visitor_id. Token recommendation_owner Step 6 tetap dipertahankan agar snapshot sesi yang sudah berfungsi tidak dibatalkan. Identitas database dan pemilik snapshot keduanya berasal dari session server, bukan request input atau IP. Session ID asli tidak dijadikan parameter URL.

Tabel saved_recipes: id, visitor_id UUID, recipe_title, recipe_data JSON, recipe_fingerprint SHA-256, timestamps; unique(visitor_id, recipe_fingerprint) dan indeks visitor/created_at. SavedRecipeService menyimpan hanya delapan field kontrak resep dari snapshot tervalidasi yang telah diperiksa pemilik/keanggotaan ID. Fingerprint mengurutkan key object rekursif dan mempertahankan urutan list; id snapshot, status matching, bahan pencarian dan alergi tidak ikut tersimpan. Ini deduplikasi konten resep identik, bukan penyamaan semua resep dengan judul sama.

Tabel recipe_searches: id, visitor_id, ingredients JSON, preferences JSON (porsi/preferensi saja), recipe_count, snapshot_id UUID, timestamps dan indeks(visitor_id,id). SearchHistoryService mencatat setelah generation/cache dan filtering berhasil; transaction memotong riwayat lama hingga 30 item. Tidak ada result_snapshot permanen atau alergi eksplisit. Snapshot 60 menit tetap terpisah di cache.

FavoriteController memverifikasi snapshot server pada POST simpan, lalu SavedRecipeService firstOrCreate dan constraint database mencegah duplikasi. Semua query baca/hapus koleksi menyertakan visitor_id. Favorite detail membaca recipe_data database, menggunakan view detail Step 6, tanpa klaim persediaan saat ini. SavedRecipeService mark mengisi status favorit UI dengan query per halaman tanpa mengubah cache Gemini/snapshot.

HistoryController membatasi daftar, ulang input, hapus satu, dan hapus semua dengan visitor_id. ClearHistoryRequest mewajibkan confirm accepted. Tidak menggunakan route model binding tanpa scope pemilik. Operasi DELETE memerlukan CSRF. Data asing/tidak ditemukan mendapat 404 ramah yang sama; snapshot asing tetap 410 seperti Step 6. Hapus riwayat menghapus catatan DB saja; cache hasil sementara tetap mengikuti TTL dan perlindungan sesi.

POST rekomendasi HTML dan JSON sama-sama mencatat history dan membuat snapshot setelah hasil berhasil. Respons JSON tetap message/recipes seperti sebelumnya. Semua GET favorit/riwayat/detail/repeat bebas request Gemini. Pengujian Feature kini menggunakan RefreshDatabase melalui Tests\TestCase, dengan SQLite :memory: terpisah dari database development.

Batas anonim: data mungkin tidak dapat dipulihkan setelah session/cookie hilang; tidak ada sinkronisasi antarbrowser/perangkat, login, maupun klaim pemulihan akun. Data yatim akibat session hilang belum memiliki kebijakan pembersihan otomatis. Step 8 belum dikerjakan.

## Step 8 — QA dan hardening

Arsitektur dan kontrak provider dipertahankan. PrivateWebResponse pada web middleware menambahkan private/no-store, nosniff, SAMEORIGIN dan referrer same-origin. Aset static Vite tetap terpisah/cacheable. SavedRecipeService mark membatasi query fingerprint pada resep yang ditampilkan. Error419/500 memiliki Blade ramah; test debugfalse memastikan detail internal tidak dirender.

Tailwind hanya memindai source resources dan pagination vendor, bukan compiled storage views, agar class debug tidak membengkakkan bundle. Navbar desktop mulai lg; tablet menggunakan menu ringkas. Setup Composer tidak meregenerasi APP_KEY jika .env telah tersedia. Aturan AGENTS dan README kini menggambarkan kondisi proyek aktual.

Hasil final dan batas kesiapan production dirinci di docs/QA.md. Tidak ada deployment, login, perubahan provider/schema Gemini, atau fitur baru pada QA ini.


## Step 8.5A — Tepat tiga rekomendasi

Aturan ini menggantikan kontrak sebelumnya “maksimal 3”/hasil kosong sukses. GeminiRecipeService meminta tepat tiga resep yang berbeda bermakna, dengan bahan tambahan diizinkan. Schema generation dan backend memiliki recipes minItems=maxItems=3; batas nested generation tetap ringkas sesuai perbaikan Step5. Backend memeriksa seluruh field, jumlah, judul ternormalisasi, fingerprint bahan+langkah dan alergi termasuk alias RecipeMatchingService sebelum cache.

Controller memeriksa kembali jumlah setelah matching sebelum snapshot/history/JSON. Missing ingredients bukan alasan filtering. Kegagalan jumlah/duplikasi/alergi menampilkan pesan aman untuk menambah/mengubah bahan, tanpa hasil parsial atau request perbaikan otomatis. Retry transport 500/503 maksimal satu tetap dipertahankan. Tidak ada resep hardcode produksi.

Prompt version2/schema version3 mengisolasi cache lama tanpa flush; TTL24jam dan kanonisasi urutan tetap. Cache hit divalidasi ulang. Snapshot lama dengan jumlah bukan tiga ditolak (410); favorit database dan catatan riwayat tidak dihapus. Blade cards tetap menggunakan data server dan desain lama. Pemeriksaan kemiripan deterministik tidak membuktikan kesetaraan semantik semua parafrasa; pemeriksaan alergi juga tetap punya batas alias/nama yang dikenal.


## Step 8.5A.1 — Tiga tingkat kesesuaian bahan

RecipeMatchingService kini menjadi satu sumber penghitungan dan pengelompokan. Nama bahan dinormalisasi trim/spasi/lowercase dan kamus alias terkontrol; ketersediaan wajib exact match setelah normalisasi, bukan substring. Bahan wajib unik yang tidak tersedia menjadi missing; garam/minyak/air tetap dihitung. Jika nama yang sama muncul sebagai wajib dan opsional, status wajib menang tanpa mengubah data resep. Optional dipisahkan, tidak dihitung. Entri gabungan dengan separator koma/semicolon/slash/ampersand/plus atau penghubung dan/atau/serta ditandai dan ditolak sebelum cache; ini bukan parser semantik semua bahasa.

rank menghitung ulang match dari data resep, menolak alergi/entri gabungan, mengurutkan jumlah tambahan menaik lalu memvalidasi rentang [0..2], [1..3], [4..30]. Dengan tepat tiga resep, pengurutan ini memberi penempatan valid yang meminimalkan tambahan kategori pertama, termasuk rentang yang tumpang tindih. Hanya setelah lolos, metadata category diisi Paling Cocok, Cukup Cocok, Alternatif Menu. Tidak ada penambahan bahan, duplikasi resep, pengubahan optional, atau pemaksaan kategori.

GeminiRecipeService memanggil rank setelah validasi struktur/duplikasi/alergi, sebelum penyimpanan cache. Cache hanya menyimpan kontrak recipe asli yang telah diurutkan, bukan metadata klaim AI. Cache hit menghitung/validasi kembali; Controller juga memakai rank untuk menghasilkan metadata snapshot. Promptversion3 (schemaversion3 tetap karena bentuk JSON tidak berubah) mengisolasi cache versi sebelumnya tanpa flush. TTL24jam tetap. Snapshot matching_version2 membatasi akses snapshot lama yang belum divalidasi kategori; koleksi DB favorit/riwayat tidak dihapus.

Card menampilkan category, jumlah dan daftar tambahan. Detail snapshot menggunakan match yang sama untuk bagian Bahan tersedia (wajib), Bahan tambahan (wajib), Bahan opsional. Detail favorit tetap menampilkan peringatan persediaan belum diperiksa karena tidak punya konteks input saat ini; tidak diberi badge kategori palsu. Semua GET detail bebas Gemini.

Kegagalan kategori menghasilkan503 dan pesan belum dapat melengkapi tiga tingkat kesesuaian, meminta tambah/ubah bahan. Tidak ada request perbaikan otomatis; batas retry transport lama tetap. Model, endpoint, schema structured output, output token dan safety checks tetap. Realisme/keperluan bahan serta alergen turunan tidak dapat dibuktikan sempurna melalui pemeriksaan nama deterministik; instruksi AI dan peringatan UI tetap diperlukan.


## Perbaikan kegagalan kategori Gemini

Log live menunjukkan HTTP200 tetapi invalid_categories. RecipeMatchingService kini memberi diagnostics aman: kind=missing_count_ranges dengan missing_counts terurut, atau kind=combined_ingredient dengan indeks resep. Kriteria penghitungan/rentang tidak berubah. GeminiRecipeService mencatat diagnostics ini tanpa nama bahan, judul, alergi, prompt atau key.

Loop request memakai batas total2panggilan untuk retry transport ATAU satu perbaikan kategori. Hanya invalid_categories pada respons pertama yang sudah lolos struktur, jumlah, duplikasi dan alergi memicu perbaikan. Input tetap bahan/porsi/preferensi/alergi ditambah feedback hitungan/jenis kegagalan; tidak mengirim respons panjang/riwayat percakapan. Meminta set3resep terkoreksi dengan schema yang sama agar kompatibel, tanpa menaikkan tokenlimit. Respons kedua divalidasi penuh; tidak ada panggilan ketiga meski503 atau kategori masih salah. Kuota/timeout/JSONinvalid/truncated/alergi tidak diperbaiki otomatis.

Prompt menegaskan penggunaan nama bahan input yang sama, keterangan pengolahan pada langkah, satu jenis bahan perentri, dan pemeriksaan ulang hitungan sebelum output. Promptversion4/schemaversion3; cachelama terisolasi tanpa flush. Hanya hasil akhir valid disimpan dan dicatat sebagai riwayat; satu pencarian sukses tetap satu snapshot/history. Kebijakan ini menggantikan catatan lama tentang tidak adanya repair kategori.


## Revisi pengurutan tanpa kuota bahan tambahan

Revisi ini menggantikan ketentuan rentang tambahan pada catatan sebelumnya. RecipeMatchingService tetap mensyaratkan tepat tiga resep unik yang aman, tetapi tidak membatasi minimum/maksimum jumlah bahan tambahan pada setiap peringkat. Pengurutan stabil menaik berdasarkan jumlah bahan wajib unik yang belum tersedia; bahan opsional tidak dihitung. Badge kategori merupakan peringkat relatif. Semua resep boleh memiliki nol tambahan atau jumlah tambahan yang sama.

Prompt Gemini versi 5 meminta bahan tambahan sesedikit mungkin tanpa memaksakan kuota. Cache berversi mengisolasi hasil prompt lama tanpa menghapus cache pengguna. Koreksi semantik satu kali hanya untuk nama bahan gabungan (invalid_ingredient_names), bukan jumlah tambahan; batas total dua request tetap berlaku. Controller, tampilan, snapshot, favorit, dan riwayat mempertahankan alur yang ada.


## Step 8.6 — checklist dan strict

IngredientChecklistService menyimpan konfirmasi ID bahan per snapshot di session. PATCH /rekomendasi/{search}/bahan memakai UpdateIngredientChecklistRequest + CSRF/owner/list validation dan session blocking. POST /rekomendasi/{search}/alternatif memakai GenerateAvailableRecipeRequest, mengambil nama dari katalog server dan opsi/alergi snapshot, kemudian memakai jalur generate Controller yang sama. Tidak ada API pada perubahan checkbox. Mode strict memiliki prompt/schema0–3, RecipeMatchingService::strict menyaring missing_required>0/alergen/gabungan/semuaopsional, kemudian hanya hasil lolos dicache. Mode NORMAL tetapexact3 danurut tanpa kuota, mengikuti revisi pengguna sebelumnya.

RecipeMode menjadi definisi mode; RecipeCacheKey memuat mode; prompt6/schema4. Snapshot accepts0–3strict/exact3normal, owner/expiry tetap. History preferences JSON menyimpan mode tanpa migration, repeat/edit mempertahankan mode. Normalinputmax15 tetap, strictinputmax105 untuk mendukung katalog konfirmasi. Gambar, favorit, provider dan struktur database tidak berubah. Lihat STEP_8_6.md untuk filelist, pengujian, batasan stok/narasi AI serta keputusan konflik instruksi kategori.

## Prioritas Paling Cocok — 12 Oktober 2026

Menggantikan batas tanpa maksimum khusus badge Paling Cocok: mode normal mengurutkan missing wajib ascending, lalu matched wajib descending, dan mewajibkan kandidat teratas memiliki bahan wajib serta missing<=2. Dua badge lain tetap peringkat relatif tanpa kuota sesuai kode berjalan; tidak mengembalikan rentang lama. Structured steps Step8.7 tetap object5–10 (nomor,judul,instruksi,durasi nullable,tips), schema5/prompt7.

Gemini normal memprioritaskan kandidat realistis0→1→2. Bila respons awal valid belum0, satu koreksi dalam budget total2request meminta set3resep lengkap; pilih set dengan kandidat terbaik, pertahankan set awal valid jika koreksi gagal. Koreksi no_best_match dan nama gabungan berbagi budget dengan retry transport. Cache normal menambahkan normal_recommendation_version=zero-first-1; cache strict dan schema tidak berubah, tanpa flush. Snapshot/favorit historis tidak ditulis ulang; pencarian baru memakai aturan baru. Kelayakan semantik tetap bergantung pada keluaran provider dan pemeriksaan pengguna, bukan disimpulkan dari hitungan bahan.

## Prioritas kategori terbaru — 12 Oktober 2026

Aturan aktif setelah pembatalan bug fix sebelumnya: Paling Cocok dipilih dengan algoritma semula0→1→2; keluarkan kandidat itu, pilih Cukup dari0–3 dengan urutan3→2→1→0, lalu Alternatif>=4 ascending. Tie-break memakai jumlah bahan wajib tersedia. Semua count bersumber matching backend, bukan urutan provider. Normal tetap3resep unik; jika rentang tidak lengkap, incomplete_categories memicu koreksi maksimal sekali dalam budget total2request, lalu gagal aman bila tetap tidak lengkap. Prompt menegaskan resep realistis dan larangan manipulasi bahan.

Versi khusus cache normal category-priority-3; strict/schema/model tetap, tidak flush. Snapshot normal lama dihitung ulang saat GET tanpa provider; data tidak memenuhi rentang ditangani halaman410, bukan badge salah. Card dan detail memakai metadata match yang sama. Favorit/data permanen tidak ditulis ulang.

## Fallback Alternatif Menu — 12 Oktober 2026

Alternatif memilih kandidat sisa setelah Paling/Cukup: prioritas>=4 ascending, lalu jika tidaktersedia fallback<=3 descending. Service menahan setvalidfallback sebelum satu upaya repair (budgettotal2); setelahbudget/gagalrepair, setvalidtetap bolehditampilkan. Kandidatutama>=4 dari setvalid tidak diganti fallback; penggabungan respons divalidasi ulang untukmencegahduplikasi/alergi/perubahanposisikategoriterpilih. Jika tidakbisa digabung, setlengkapvalid dipertahankan. Normalcache alternative-fallback-4, strict/schema tidakberubah. Tidakmenjaminprovider telahmenjelajahi semuaresep; fallbackberdasarkan kandidat dan upayaterbatas.

## Alur tanpa gambar resep (terkini)

RecipeImageService, konfigurasi Unsplash, komponen gambar dan fallback resep telah dihapus. RecommendationController memanggil Gemini → rank/validasi resep → prepare snapshot → complete operation → redirect hasil, tanpa layanan gambar. Reservasi/komit kuota, idempotensi, prompt, cache Gemini dan aturan kategori tidak berubah.

SavedRecipeService menyimpan isi resep saja untuk favorit baru. Fingerprint tetap berdasarkan field resep yang sama; favorit lama yang memiliki metadata gambar tetap dapat dibaca tanpa modifikasi database. Blade tidak mengakses metadata gambar lama. Detail bersama untuk hasil dan favorit tetap menyediakan checklist serta panduan memasak; riwayat tidak membutuhkan gambar.

Tidak ada pencarian atau pemuatan gambar eksternal pada halaman resep/koleksi. Hero memakai aset lokal. Catatan foto pada laporan tahap lama dan dokumen UNSPLASH_* adalah arsip yang tidak lagi menggambarkan integrasi aktif.
