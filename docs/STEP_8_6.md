# Step 8.6 — checklist dan menu tanpa bahan tambahan

Implementasi 11 Oktober 2026. Tidak melanjutkan Step 9.

## Keputusan mode normal

Instruksi tahap ini menyebut kembali rentang lama 0–2/1–3/minimal4, tetapi pengguna sebelumnya secara eksplisit meminta menghapus batas tersebut. Klarifikasi dikirim; belum ada jawaban saat implementasi. Mode normal dipertahankan sesuai keadaan terakhir: tepat3resep unik, urut tambahan wajib menaik, badge Paling Cocok/Cukup Cocok/Alternatif Menu sebagai peringkat relatif. Tidak mengaktifkan kembali penolakan rentang yang sebelumnya menyebabkan hasil kosong.

## Checklist dan state

RecipeMatchingService menghitung ketersediaan berdasarkan nama utuh ternormalisasi dan alias terkontrol, bukan klaim AI atau substring. Bahan awal tercentang, tambahan tidak tercentang, takaran ditampilkan, opsional dipisahkan dan tidak dihitung sebagai kekurangan wajib. Bahan awal yang tidak dipakai resep tetap dapat dikonfirmasi dalam bagian tersendiri.

IngredientChecklistService membentuk katalog dari input awal dan bahan seluruh resep snapshot. Identitas tiap bahan berupa SHA-256 nama kanonis. Browser mengirim ID yang dicentang saja; Form Request memeriksa array/list/duplikat/batas105 dan keanggotaan katalog snapshot milik session tersebut. Nama, alergi, porsi, preferensi dan mode tujuan tidak dipercaya dari payload checkbox. Konfirmasi tersimpan dalam session server per snapshot, maksimum30alur, tidak mengubah snapshot resep atau cache Gemini. Alur snapshot harus masih aktif dan dimiliki pengunjung.

Vanilla JavaScript memperbarui hitungan, status, daftar kekurangan, warna dan tombol seketika tanpa innerHTML. PATCH /rekomendasi/{search}/bahan menyimpan state, tanpa Gemini. Penulisan diurutkan client dan memakai session blocking server, CSRF dan throttle60/menit. keepalive membantu menyelesaikan penyimpanan ketika meninggalkan halaman. Kegagalan simpan ditampilkan; submit alternatif mengirim ulang pilihan saat ini. Jumlah stok belum dicatat, sehingga tidak mengklaim kecukupan takaran.

## Mode strict

POST /rekomendasi/{search}/alternatif menerima minimal1ID terkonfirmasi, memuat ulang snapshot milik pengguna, lalu meneruskan hanya nama terkonfirmasi serta porsi/preferensi/alergi snapshot ke mode STRICT_AVAILABLE_INGREDIENTS. Mode tidak ditentukan oleh browser untuk route ini. Limiter5/menit dan concurrency lock lama tetap berlaku. Satu klik memulai pencarian, checkbox dan GET detail tidak menghasilkan AI request.

GeminiRecipeService memiliki instruksi strict terpisah dan schema recipes0–3. Tidak menganggap air/bumbu tersedia, tidak memaksakan3resep, tidak ada repair semantik untuk mengejar jumlah. Transport retry500/503 tetap maksimal1tambahan sesuai aturan sebelumnya. Parser/field/type/safety/finishReason/duplikat tetap diperiksa. RecipeMatchingService menyaring setiap resep: minimal1bahan wajib, missing_required=0, tidak konflik alergi atau bahan gabungan. Tidak mengubah optional secara otomatis. Konflik alergi strict dibuang dari kandidat, termasuk pemeriksaan konservatif teks resep. Resep invalid struktural menghasilkan error aman. Nol hasil menggunakan pesan empty state Indonesia.

Backend memvalidasi daftar bahan yang dinyatakan dalam JSON; kemampuan menilai kewajaran teknik atau bahan tersembunyi dalam narasi tetap terbatas. Prompt melarang bahan tersembunyi dan trik optional. UI tetap meminta pemeriksaan alergi, takaran dan kematangan; tidak mengklaim verifikasi sempurna atas semua narasi AI.

## Cache dan hasil

Cache key mencakup mode NORMAL/STRICT_AVAILABLE_INGREDIENTS, bahan terurut, porsi, preferensi, alergi, model, prompt6/schema4 serta thinking/outputlimit. Cache normal tidak pernah dipakai langsung sebagai hasil strict. Hanya kandidat lolos yang dicache; cache strict diperiksa ulang dan entri yang mengandung kandidat tidaklayak diganti hasil bersih. TTL24jam, tidak flush global. Snapshot tetap60menit, now accepts0–3 onlystrict, exact3normal; owner/expiry tetap diperiksa.

Halaman strict memakai komponen lama dengan judul Masak dari Bahan yang Kamu Punya, badge Tanpa Bahan Tambahan, daftar bahan dan0–3kartu tanpa slotpalsu. Gambar memakai RecipeImageService lama, termasuk placeholder jika gagal. Detail/favorit tidak mengambil Gemini atau Unsplash ulang.

Mode riwayat disimpan pada JSON preferences tanpa migration. Cari Lagi mempertahankan mode melalui old input/hidden field; tampilan landing tidak dirombak. Mode normal tetap maksimal15bahan. Edit/ulang strict mengizinkan105bahan agar konfirmasi dari katalog tidak hilang. Batas frontend mengikuti mode; validasi nama60karakter dan larangan HTML tetap berlaku pada form input. Favorit menyimpan resep tervalidasi dan image; tidak menganggap stok pencarian lama masih tersedia saat membuka favorit terpisah. Favorit tersimpan menampilkan checklist pemeriksaan manual yang sudah ada, tanpa mengklaim stok aktif.

## File baru

- app/Support/RecipeMode.php
- app/Services/IngredientChecklistService.php
- app/Http/Requests/UpdateIngredientChecklistRequest.php
- app/Http/Requests/GenerateAvailableRecipeRequest.php
- resources/views/components/ingredient-checklist.blade.php
- resources/js/ingredient-checklist.js
- tests/Feature/AvailableRecipesTest.php
- tests/Unit/StrictRecipeMatchingTest.php
- docs/STEP_8_6.md

## File diubah

- app/Http/Controllers/RecommendationController.php
- app/Http/Requests/GenerateRecipeRequest.php
- app/Services/GeminiRecipeService.php
- app/Services/RecipeMatchingService.php
- app/Services/RecommendationSnapshotService.php
- app/Services/SearchHistoryService.php
- app/Support/RecipeCacheKey.php
- app/Support/RecipeFailureResponse.php
- config/ai.php, routes/web.php
- resources/views/recommendations/detail.blade.php dan preview.blade.php
- resources/views/components/recommendation-card.blade.php dan ingredient-input.blade.php
- resources/views/history/index.blade.php
- resources/js/app.js dan ingredient-input.js
- tests/Feature/RecipeCategoriesTest.php
- README.md, docs/arsitektur.md, PROGRESS.md
- public/build/manifest.json dan bundle Vite hasil build

## QA

139tests1103assertions lulus. Unit mencakup alias, nama tidakparsial, opsional, semuaopsional ditolak, alergen, bahan gabungan. Feature mencakup checkbox awal/ubah, stok awal dilepas, ID asing/duplikat/empty/owner lain ditolak,0–3hasil strict,cacheterpisah/hit/cachetercemar disaring, alergi, JSONinvalid aman, favorit/history/modeulang/gambarplaceholder. Regresi normal dan Unsplash tetap lulus. Satu expectationteks detail lama diubah untuk mengikuti pesan checklist baru; tidak ada kegagalantersisa.

Browser QA memakai database/session/cache terisolasi di /private/tmp/dapur86 dan Http::fake/preventStrayRequests. Aplikasi/database development tidak dimigrasi atau dihapus. Minyak dicentang: kekurangan4→3, reload tetap tersimpan;0requestfake sebelumalternatif. Air dilepas: pilihan telur/minyak, hasilkosong; air dicentanglagi:1TelurRebus dengan0tambahan. Detail strict semua tersedia membuat tombolnonaktif. Dua aksi alternatif menghasilkan2requestfake; tidak ada requestlive. Screenshot hasil /private/tmp/dapur86/hasil-strict.png. Gambar placeholder sengaja digunakan karena Unsplash nonaktif di QA, bukan bukti integrasi gambar rusak.

Belum dilakukan pengujian Gemini live untuk prompt strict atau jaminan kebenaran memasak seluruh keluaran AI. Tidak deploy, tidak login baru, tidak menambahdependency. Checklist perlu JavaScript untuk sinkronisasi instan; fitur alternatif tetap menggunakan POST CSRF.

Verifikasi akhir: php artisan test **139 tes / 1103 assertions lulus**; Pint --test lulus; route:list **18 route**; npm run build lulus menggunakan Node lokal22 (CSS57.41kB, JS6.40kB). Tidak ada kegagalan tersisa. Server QA dihentikan setelah pemeriksaan, tidak mengubah server development pengguna.
