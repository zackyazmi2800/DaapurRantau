# Aturan pengembangan Dapur Rantau

Utamakan instruksi pengguna dan kondisi kode yang benar-benar tersedia. Baca bagian relevan README.md, docs/arsitektur.md, atau bagian terbaru PROGRESS.md hanya jika diperlukan untuk perubahan yang sedang dikerjakan; jangan membaca ulang seluruh proyek untuk perubahan satu komponen.

## Efisiensi pengerjaan dan token Codex

- Untuk debugging, fokus pada file dan dependensi yang berkaitan langsung dengan masalah. Perluas pemeriksaan hanya jika temuan memerlukannya.
- Jangan melakukan refactor besar yang tidak diminta. Pertahankan fitur yang sudah berjalan dan batasi perubahan pada kebutuhan tugas.
- Jangan membuat file dokumentasi tambahan tanpa kebutuhan. Perbarui dokumentasi yang ada hanya jika relevan atau diminta.
- Berikan laporan akhir yang singkat, mencakup perubahan, verifikasi aktual, kendala jika ada, dan langkah manual testing yang dapat dilakukan pengguna di browser untuk perubahan fitur.

## Stack dan lingkup

- Laravel 13, PHP >=8.3, Blade, Tailwind CSS 4 dengan @tailwindcss/vite, Vanilla JavaScript, Vite, SQLite development.
- AI aktif Google Gemini generateContent melalui Laravel HTTP Client. Jangan mengganti model/provider, memasang SDK/package baru, mengaktifkan billing, atau membangun ulang proyek tanpa kebutuhan yang disetujui.
- Pertahankan MVC dan Service Layer. HTTP Gemini bukan tanggung jawab Controller. Validasi input memakai Form Request.
- Tidak menggunakan React, Vue, Inertia, CDN Tailwind, atau konfigurasi Tailwind 3. Semua UI Bahasa Indonesia dan escaped Blade.
- Perubahan harus terarah, mempertahankan fitur lama, semantic HTML, focus state, mobile layout serta dukungan reduced motion.

## Data dan keamanan

- Semua favorit/riwayat dibatasi visitor UUID dari session server; jangan percaya visitor_id atau JSON resep dari browser.
- Favorit berasal dari snapshot server yang sudah divalidasi dan diotorisasi. Detail favorit/snapshot tidak memanggil AI.
- Cache Gemini 24 jam dan snapshot UI 60 menit adalah mekanisme berbeda. Hanya hasil valid yang boleh di-cache. Versikan key jika kontrak prompt/schema berubah.
- Jangan log/menampilkan API key, .env, full prompt, input alergi, atau raw exception HTTP provider. Gunakan config(), bukan env() dalam Controller/Service.
- Jangan menonaktifkan CSRF, ownership, limiter, backend validation, atau batas retry untuk memperbaiki test.
- Jangan menjalankan migrate:fresh/rollback destruktif pada database pengguna. Jangan mengubah APP_KEY pada instalasi yang sudah berjalan. Jangan mengaktifkan APP_DEBUG production.

## Verifikasi dan dokumentasi

- Utamakan manual testing melalui browser untuk setiap penambahan atau perbaikan fitur. Jika tidak dapat dilakukan, jelaskan keterbatasannya dan berikan langkah pengujian kepada pengguna; jangan mengklaim sudah diuji.
- Jangan otomatis membuat Unit Test atau Feature Test kecuali diminta pengguna atau perubahan berisiko tinggi. Untuk autentikasi, keamanan, database, atau logika utama rekomendasi, sarankan pengujian otomatis yang terbatas dan relevan.
- Jangan otomatis menjalankan seluruh test suite setelah perubahan kecil. Pilih pemeriksaan atau test yang berkaitan langsung dengan perubahan.
- Jangan menjalankan npm run build jika tidak ada perubahan frontend yang memerlukannya.
- Gunakan Http::fake dan preventStrayRequests dalam automated tests; database test SQLite :memory: terisolasi. Jangan menganggap fixture sebagai hasil AI live.
- Jangan melakukan request API sungguhan untuk testing tanpa izin pengguna, termasuk melalui browser. Jika diizinkan dan dibutuhkan, tentukan batas request dan hindari retry yang menghabiskan kuota. Utamakan cache untuk pemeriksaan UI.
- Jalankan pengecekan route, format kode, dan pengujian otomatis hanya sesuai kebutuhan perubahan; gunakan filter atau cakupan file yang relevan. Tests tidak boleh menggunakan database development.
- Node global bisa terlalu lama untuk Vite. Gunakan versi .nvmrc atau runtime lokal .tools/node-v22.23.3-darwin-arm64/bin pada macOS ARM; jangan mengganti framework diam-diam.
- Jangan memindai storage/framework/views untuk Tailwind: compiled views termasuk error debug dapat membengkakkan build.
- Jika diminta atau diperlukan untuk mencatat perkembangan yang berarti, perbarui PROGRESS.md dengan hasil aktual dan batasan, tanpa menghapus riwayat. README adalah panduan keadaan terkini; arsitektur ada di docs/arsitektur.md.
- Jangan deploy, melanjutkan tahap baru, atau menambah fitur di luar permintaan.
