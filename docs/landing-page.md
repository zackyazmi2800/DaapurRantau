# Landing page Dapur Rantau

Implementasi UI menggunakan Blade dan Tailwind CSS 4. Palet utama: #427A54, #FFF8EB, #F2A65A, #25332B. Font sistem Avenir Next/Segoe UI; tidak ada request font eksternal.

## Komponen

- `brand`: identitas/logo, varian footer.
- `button`: tombol dan tautan CTA, varian utama/sekunder.
- `icon`: SVG inline dekoratif, tanpa library ikon.
- `recipe-card`: foto, label contoh, judul, deskripsi, waktu dan tingkat kesulitan.

Layout menyediakan header/menu mobile, footer, skip link, dan dialog informasi favorit. Homepage berisi seluruh section. Input bahan kini terhubung ke POST /rekomendasi melalui CSRF. Vanilla JavaScript menyinkronkan chip ke ingredients[], mendukung beberapa bahan dipisahkan koma, dan membatasi 15 bahan/60 karakter. Porsi (1–10), preferensi, dan alergi bersifat opsional. Form Request menormalisasi dan memvalidasi semua data. Kesalahan mengembalikan input lama; keberhasilan kini diteruskan ke GeminiRecipeService (Step 5). Favorit belum menyimpan resep.

Aksesibilitas: semantic landmarks, urutan heading, label input, aria-expanded/controls, tombol berlabel, status aria-live, dialog native, focus-visible, dukungan Escape dan prefers-reduced-motion.

## Foto

Aset foto disimpan lokal di public/images agar tampilan tidak bergantung pada request pihak ketiga. Foto bersifat ilustratif, bukan hasil AI atau dokumentasi resep aktual.

- nasi-rumahan.jpg: https://images.unsplash.com/photo-1512058564366-18510be2db19
- telur-sederhana.jpg: https://images.unsplash.com/photo-1510693206972-df098062cb71
- sayur-segar.jpg: https://images.unsplash.com/photo-1547592180-85f173990554

## Verifikasi

- Build Vite dan 12 test Laravel (termasuk 10 Feature Test ingredient input) lulus.
- Desktop dan mobile 390px diperiksa melalui browser; navbar mobile membuka/menutup dan navigasi anchor bekerja.
- Tambah chip, duplikasi, hapus chip, validasi bahan kosong, submit backend, pemulihan error, dan dialog Favorit diperiksa manual.
- Pada mobile 390px tidak ditemukan overflow horizontal setelah penyesuaian navbar.
- Step 5 menambahkan Gemini dan cache backend, tanpa authentication atau penyimpanan favorit/riwayat. Hasil sementara memakai Blade; desain landing tetap.

Status Step 5: lihat README.md dan PROGRESS.md untuk konfigurasi Gemini, hasil pengujian terbaru, dan batasan live API.
