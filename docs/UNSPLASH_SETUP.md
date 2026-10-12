# Konfigurasi gambar Unsplash — Dapur Rantau

## Registrasi manual

Buka [Unsplash Developers / Your Applications](https://unsplash.com/oauth/applications), masuk dengan akun milikmu, baca/penuhi API Guidelines, lalu pilih **New Application**. Langkah ini dilakukan pemilik proyek secara manual, bukan oleh Codex.

- Application name: **Dapur Rantau**
- Description: **AI-powered recipe recommendation website that helps users discover meals based on available ingredients and displays food photography.**
- Gunakan Demo Mode selama development. Dokumentasi saat implementasi menyebut batas demo **50 request JSON API/jam**; perhatikan header limit aktual pada akun. Jangan mengaktifkan production atau billing otomatis.
- Salin **Access Key** ke `.env` lokal. **Secret Key tidak digunakan**. Keduanya tetap rahasia; jangan commit, kirim ke browser atau log.
- Aplikasi hanya mencari foto publik memakai Public Authentication (`Authorization: Client-ID ...`); tidak ada OAuth pengguna atau Redirect URI untuk pencarian ini.

Referensi: [dokumentasi resmi](https://unsplash.com/documentation), [API Guidelines](https://help.unsplash.com/en/articles/2511245-unsplash-api-guidelines).

## Environment

Tambahkan variabel yang belum ada ke `.env` tanpa menimpa isinya:

```dotenv
UNSPLASH_ACCESS_KEY=
UNSPLASH_BASE_URL=https://api.unsplash.com
UNSPLASH_PER_PAGE=5
UNSPLASH_TIMEOUT=10
RECIPE_IMAGE_CACHE_TTL=604800
RECIPE_IMAGE_NEGATIVE_CACHE_TTL=3600
```

Isi `UNSPLASH_ACCESS_KEY` dengan Access Key milikmu. `config/services.php` membaca variabel ini; Controller/Service hanya memakai `config()`. Secret Key tidak perlu diisi. Key kosong tetap menghasilkan rekomendasi dengan placeholder.

Sesudah mengubah environment:

```sh
php artisan config:clear
```

Ini membersihkan cache konfigurasi, **bukan** seluruh cache resep/gambar. Jangan jalankan cache:clear hanya untuk integrasi ini. Untuk production kelak gunakan config:cache mengikuti prosedur deployment; tahap ini tidak melakukan deployment.

## Cara kerja dan batas request

POST rekomendasi memvalidasi tiga kategori lebih dahulu, lalu menambahkan `recipe.image` di luar hasil/cache Gemini. Service mencari foto lokal terkurasi, cache gambar, lalu Unsplash. Query memakai mapping exact dan keluarga masakan beserta sinonim terkontrol untuk variasi judul. Modifier seperti seafood, tahu, bayam, dan sayur diterjemahkan secara terkontrol; judul yang tidak dikenal tetap dipertahankan. Tidak memakai Gemini untuk terjemahan. Penerimaan foto memerlukan identitas hidangan (misalnya fried rice/nasi goreng) dalam description/alt_description; kata tambahan seperti egg/telur menaikkan skor tanpa mewajibkan urutan judul penuh. Nama tak dikenal memerlukan minimal dua token utuh dan 75% kata judul bermakna. Kandidat valid dengan skor tertinggi dipilih dan URL serta atribusi valid; bukan sekadar hasil pertama. Foto tetap diberi label ilustrasi, bukan jaminan kecocokan visual.

Cache `recipe-image:3:{sha256(nama ternormalisasi)}` menyimpan metadata7hari; pencarian sukses tanpa foto yang dapat diterima menyimpan placeholder1jam. Error HTTP/koneksi/JSON bukan foto valid; placeholder kegagalan disimpan singkat maksimal60detik perjudul (auth/kuota mengikuti jeda lebih lama). Metadata cache dan favorit disaring lagi sebelum ditampilkan. Perubahan mapping/relevansi harus menaikkan versi strategi service.

Satu pencarian resep dapat memerlukan sampai3 pencarian gambar saat semua miss. getImagesForRecipes mengumpulkan miss lalu Http::pool(concurrency:3) menjalankan request secara paralel tanpa retry. Global lock melindungi satu batch per Access Key, tidak menunggu dan menggunakan placeholder untuk miss saat batch lain sibuk; hasil cache/lokal tetap dipakai. Batas internal konservatif45/jam per Access Key, dihitung dari awal jendela lokal, tetap membatasi jika header hilang. Lock membutuhkan driver Laravel yang mendukungnya (database/file/array mendukung); tanpa dukungan lock service memilih placeholder.

Header X-Ratelimit-Limit dan X-Ratelimit-Remaining dicatat hanya sebagai angka. Remaining<=2 atau429 menunda request baru minimal1jam dan menghormati Retry-After yang lebih panjang. Error401/403 cooldown1jam; error lain/koneksi/JSON memakai negative cache perjudul maksimal60detik tanpa memblokir judul lain. Respons lain dalam batch tetap diproses saat429 mengaktifkan cooldown batch berikutnya. Cache/local tetap dapat dipakai saat cooldown. Mengganti atau membagikan key untuk mengakali kuota tidak didukung. Tidak ada dashboard tambahan.

## Satu smoke test live yang aman

Jalankan hanya setelah Access Key dikonfigurasi dan kamu mengizinkan pemakaian kuota. Perintah berikut meminta **satu judul**, tidak memanggil Gemini dan tidak retry. Bila sudah cached/local/cooldown, dapat menghasilkan **nol request live**. Jangan flush cache untuk memaksa panggilan. Output hanya status sumber dan keberadaan cache, bukan key/payload HTTP.

```sh
php artisan tinker --execute='$service = app(\App\Services\RecipeImageService::class); $image = $service->getRecipeImage("Nasi Goreng Telur"); dump(["source" => $image["image_source"], "cached" => \Illuminate\Support\Facades\Cache::has($service->cacheKey("Nasi Goreng Telur"))]); \Illuminate\Support\Facades\Http::fake(fn () => \Illuminate\Support\Facades\Http::response([], 500)); $again = $service->getRecipeImage(" NASI   GORENG TELUR "); \Illuminate\Support\Facades\Http::assertNothingSent(); dump(["same_result" => $again === $image]);'
```

Pastikan `cached=true` untuk membuktikan cache positif/negatif; `same_result=true` saja tidak membuktikan cache hit saat key kosong/cooldown. Satu entri `unsplash.search` di log dengan statusHTTP menunjukkan pencarian sudah dikirim; tidak adanya log ini berarti tidak ada respons HTTP tercatat (misalnya key kosong, cache, atau timeout). Jangan tampilkan seluruh log atau credential untuk diagnosis.

Untuk pengujian tanpa kuota:

```sh
php artisan test --filter=RecipeImage
```

Tests memakai Http::fake/preventStrayRequests dan key dummy. Test dasar mengosongkan konfigurasi Unsplash agar key `.env` developer tidak terpakai tanpa sengaja.

## Foto lokal dan hotlink

`services.unsplash.local_images` adalah mapping kosong yang siap diisi **setelah** foto diverifikasi relevan dan hak pakainya tersedia. Gunakan nama masakan ternormalisasi sebagai key dan path `/images/recipes/nama-file.webp` (juga jpg/jpeg/png) sebagai value. Tidak ada foto makanan lokal yang diklaim terkurasi saat ini. Placeholder SVG buatan proyek bukan foto makanan.

Foto Unsplash memakai `urls.small` pada card dan `urls.regular` pada detail; URL tidak diganti atau diunduh ulang, termasuk parameter ixid. Cache hanya metadata/URL. Tautan fotografer dan halaman foto dipertahankan dengan tambahan `utm_source=dapur_rantau&utm_medium=referral`. Atribusi muncul di rekomendasi, detail, dan favorit. Jika gambar gagal di browser, JavaScript mengganti dengan placeholder satu kali dan menyembunyikan atribusi untuk gambar yang tidak tampil.

Tidak ada fitur memilih/mengunduh foto. Simpan Favorit menyimpan resep beserta referensi gambar yang sudah tampil, bukan pemilihan foto oleh pengguna. Jika kelak dibuat tindakan pemilihan/penggunaan foto yang tergolong download, implementasikan tracking melalui `links.download_location` sesuai pedoman sebelum merilis fitur itu.

## Troubleshooting

| Gejala | Pemeriksaan |
|---|---|
| Placeholder tanpa HTTP | Key kosong, cache negatif, foto lokal tidak tersedia, lock sibuk, cooldown atau budget internal. |
| Foto hasil pencarian tidak dipilih | Metadata tidak cukup spesifik, URL tidak valid atau atribusi tidak lengkap; placeholder disengaja. |
| 400 | Periksa base URL resmi dan parameter; jangan replay berulang. |
| 401/403 | Periksa Access Key/aplikasi/izin di dashboard sendiri; jangan kirim key ke chat/log. |
| 429 / remaining<=2 | Tunggu cooldown atau gunakan cache/placeholder; jangan mengakali limit. |
| 500/503/timeout | Rekomendasi tetap tampil dengan placeholder; tidak retry otomatis. |
| Foto hilang saat browser memuat | Periksa jaringan/CDN; fallback frontend memakai SVG lokal. |
| Favorit lama tidak punya foto | Placeholder normal, tidak melakukan pencarian tambahan di GET. |
| Config baru belum terbaca | Jalankan config:clear; jangan mengganti file .env atau APP_KEY. |

Batasan: pencocokan teks tidak membuktikan gambar 100% cocok. Variasi nama yang tidak ada dalam mapping bisa menghasilkan lebih banyak placeholder. Tiga miss gambar kini berjalan paralel, masing-masing dibatasi maksimal10detik; waktu batch mengikuti request terlambat ditambah overhead, bukan penjumlahan ketiganya. Belum dilakukan benchmark latency provider live. Optimasi queue/asinkron tidak ditambahkan pada tahap ini.


Validasi batch live dan diagnosis kandidat tersedia di [UNSPLASH_LIVE_REPORT.md](UNSPLASH_LIVE_REPORT.md). Strategi3 mengenali ejaan mi/mie goreng, menolak konflik alt sup/quiche untuk telur dadar, dan memprioritaskan kandidat yang didukung alt. Snapshot/favorit lama tetap mempertahankan metadata lama; pencarian baru memakai keyversi3.

Strategi terbaru memakai cache gambar versi 4 dan pemetaan keluarga masakan/sinonim untuk judul variasi. Pencarian baru menggunakan strategi ini; snapshot dan favorit lama tidak otomatis mengganti foto. Log `unsplash.selection` membedakan respons sukses dengan foto terpilih dari respons sukses tanpa kandidat relevan.


Revisi 10 Oktober 2026: cache gambar versi5, pengenalan sayur bening/tumis tahu dan kata inti sup tahu yang tidak harus bersebelahan. Noodle soup tetap ditolak untuk sup sayur/tahu. Log kandidat memakai enum alasan aman. Bukti respons asli dan hasil browser ada di docs/UNSPLASH_DEBUG_2026-10-10.md. Satu foto nyata dimuat, dua kandidat lama tidak relevan; query baru belum diuji live.


## Urutan pencarian telur dadar — 10 Oktober 2026

Sesuai urutan pengguna, variasi judul berawalan telur dadar dicari dengan kata Indonesia asli: nama lengkap → hapus kata terakhir → telur dadar. Contoh: telur dadar wortel tahu → telur dadar wortel → telur dadar. Query duplikat dihapus, maksimal tiga tahap. Keluarga masakan lain mempertahankan strategi sebelumnya.

Tahap berikutnya hanya berjalan setelah respons sukses dengan results valid tetapi tanpa foto relevan. Berhenti saat foto ditemukan; error HTTP/koneksi/JSON tidak memicu perluasan. Tiap tahap menjalankan resep yang masih belum memperoleh foto secara paralel (maksimal3), jadi batas terburuk9 pencarian untuk3 variasi, bukan3 total. Budget45/jam, cadangan quota2 dan cooldown diperiksa kembali setiap tahap. Lock45detik meliputi maksimal3 tahap timeout10detik. URL, atribusi dan relevansi tetap divalidasi. Tidak ada query tambahan di GET detail/favorit.

Cache gambar versi6 menyimpan hasil akhir; placeholder tahap sementara tidak disimpan sebagai hasil akhir. Jika budget/cooldown menghentikan proses, placeholder tetap ditampilkan dan proteksi tidak dilewati. Cache Gemini/snapshot lama tidak dihapus. Tidak ada request live pada perubahan ini.


## Foto sejenis Unsplash — 10 Oktober 2026

Urutan variasi telur dadar kini: nama lengkap → hapus kata terakhir → telur dadar → vegetable omelette → omelette. Query duplikat dihapus (judul tiga kata memiliki empat tahap). Tahap berhenti ketika foto valid ditemukan. Relevansi tetap mensyaratkan identitas omelette dan menolak konflik seperti soup/quiche; tidak wajib seluruh bahan tambahan terlihat. Label ilustrasi dan atribusi tetap memakai komponen yang ada.

Cache khusus gambar versi7; cache sejenis recipe-image-similar:1:omelette menyimpan metadata foto Unsplash yang sudah tervalidasi dengan TTL gambar. Lookup mendahulukan hasil perjudul, lalu foto sejenis untuk keluarga telur dadar, sebelum HTTP. Alt disesuaikan dengan judul tujuan; URL/atribusi asli dipertahankan. Foto telur tidak dipakai untuk keluarga nasi/sup. Negative cache perjudul tetap dihormati sampai kedaluwarsa; tidak flush Gemini atau snapshot/favorit.

Maksimal5 tahap perjudul (15 request untuk3 judul pada kondisi terburuk),3 request paralel per tahap. Budget45/jam dan cadangan2 tetap dicek tiap tahap; cooldown/error HTTP/koneksi/JSON menghentikan perluasan. Lock gambar65detik mencakup5 tahap timeout10detik. Jumlah request aktual berhenti lebih awal saat cachehit/fotosukses/kuota. Tidak ada request live dalam perubahan ini; tidak menjamin provider selalu tersedia.


## Pencarian bertahap seluruh judul — 10 Oktober 2026

Menggantikan batas lingkup telur dadar: buildSearchQueries menerima semua judul, selalu mulai dari nama asli ternormalisasi. Selanjutnya nama tanpa kata terakhir (hanya jika keluarga masakan tidak berubah), nama keluarga, dan padanan Inggris terkontrol. Maksimal5 query unik. Telur dadar tetap mempertahankan vegetable omelette → omelette. Nasi goreng, mi, sup, tumis, ayam goreng/bakar, ikan goreng, telur kukus, bakwan, perkedel, capcay, soto, sate, rendang memiliki pemetaan. Nama tak dikenal menggunakan nama lengkap → hapus kata terakhir → dua kata pertama; judul satu kata tetap dicari. Tidak menjanjikan terjemahan semua kata atau menebak bahasa Inggris via Gemini. Filter nama tak dikenal memerlukan kata inti pada metadata; keluarga dikenal memerlukan identitas hidangan sesuai alias.

Cache foto versi8, cache keluarga versi2 berdasarkan hash keluarga, terisolasi antarkeluarga. Nama tak dikenal tidak berbagi cache sejenis. Cache Gemini/snapshot/favorit tidak dihapus. Batas5 tahap/15request terburuk, pool3, lock65detik, budget45/jam, cadangan2, cooldown dan stop pada error tetap. Contoh sup ... tahu tidak disederhanakan menjadi sup ... tanpa tahu karena identitas berubah. Foto tetap ilustrasi, tidak menjamin seluruh bahan terlihat.
