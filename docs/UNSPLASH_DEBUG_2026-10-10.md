> Arsip historis: integrasi Unsplash dan gambar resep telah dihapus. Instruksi konfigurasi/pengujian di bawah tidak berlaku untuk source saat ini.

# Diagnosis Unsplash — 10 Oktober 2026

## Bukti dan batas pengujian

Snapshot terbaru masih menyimpan tiga placeholder. Respons lengkap untuk ketiga judul tersebut belum tersedia, sehingga dilakukan satu batch tiga pencarian. Percobaan awal dari sandbox mengalami connection_or_timeout tanpa respons HTTP; batch dengan akses jaringan memperoleh tiga HTTP200. Tidak ada retry setelah batch sukses, tidak ada panggilan Gemini. Budget internal untuk percobaan koneksi gagal tidak direset.

Semua 15 kandidat memiliki urls.small dan urls.regular. Parser dan field image konsisten. Ketiga respons awal ditolak relevansi sebelum Controller; bukan URL yang hilang di Blade. Snapshot lama tetap menyimpan placeholder secara sengaja.

| Resep | HTTP | Total / kandidat | URL terekstrak | Filter setelah perbaikan | Controller / Blade | Browser |
|---|---|---|---|---|---|---|
| Sup Segar Jagung Wortel Tahu | 200 | 822 / 5 | Ya | Lolos, HQNhc58_6mI | Metadata foto utuh | Foto 400px dan detail 1080px loaded |
| Sayur Bening Bayam Jagung | 200 | 630 / 5 | Ya | Tidak ada kandidat relevan | Placeholder utuh | Placeholder SVG dimuat |
| Tumis Tahu Wortel Pedas | 200 | 48 / 5 | Ya | Tidak ada kandidat relevan | Placeholder utuh | Placeholder SVG dimuat |

Controller diverifikasi melalui Feature Test dengan HTTP fake. Browser memakai pratinjau hasil render Blade dari snapshot asli + metadata hasil replay offline; bukan submit Gemini baru dan bukan perubahan snapshot pengguna. Elemen img sup memakai URL Unsplash asli, complete=true, naturalWidth=400 di kartu/1080 di detail, fallbackUsed=false. Dua placeholder naturalWidth=800, fallbackUsed=false. Tidak ada CSP img-src dalam kode aplikasi; tidak ada bukti pemblokiran gambar terpilih atau CSS tersembunyi. Status jaringan CDN tidak dicatat terpisah; decoding gambar sukses terverifikasi.

## Penyebab dan koreksi

- Sup: filter sebelumnya mensyaratkan frasa berurutan tofu soup; metadata asli berbunyi Bowl of miso soup with tofu and seaweed. Kini kata inti soup + tofu boleh terpisah. Foto merupakan ilustrasi keluarga sup tahu, bukan foto persis resep jagung/wortel. Noodle soup tetap ditolak sebagai hidangan berbeda.
- Sayur bening: query Indonesia mengembalikan ikan, sayur mentah dan tanaman; penolakan benar. Query baru clear vegetable soup corn spinach.
- Tumis tahu: hasil lama mencakup tahu gejrot, kue, salad buah, daging; tidak cukup membuktikan tumis tahu. Query baru stir fried tofu carrot.
- Query baru belum dipanggil live. Tidak mengklaim dua foto sisanya ditemukan.
- Cache gambar versi5 mengisolasi hasil negatif lama tanpa menyentuh cache Gemini. Hanya foto sup tervalidasi hasil replay yang disimpan ke cache versi5; respons kosong lama tidak disimpan sebagai jawaban untuk query baru. Cache hit sudah dibuktikan tanpa request tambahan.
- Diagnostic log per kandidat berisi indeks dan enum alasan invalid_metadata, conflicting_dish, dish_not_identified, lower_rank, invalid_url_or_attribution, eligible. Tidak ada credential, judul/input, body, atau prompt dalam log ini.

## Kandidat respons asli

### Sup Segar Jagung Wortel Tahu

Query saat live: `tofu soup`. Query setelah perbaikan: `tofu soup corn carrot`.

- ID: `5DQpeAZfVUE`; diterima setelah perbaikan: False
  - alt_description: A bowl of vietnamese noodle soup with tofu and herbs
  - description: None
  - urls.small: https://images.unsplash.com/photo-1775213371176-41291be474ba?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8MXx8dG9mdSUyMHNvdXB8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=400
  - urls.regular: https://images.unsplash.com/photo-1775213371176-41291be474ba?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8MXx8dG9mdSUyMHNvdXB8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=1080
- ID: `iDlZaCORBO0`; diterima setelah perbaikan: False
  - alt_description: vegetable salad in white ceramic bowl
  - description: Vegan and gluten-free buckwheat noodles are bathed in a deep, dark, complex broth of roasted onions, meadowsweet vinegar and thyme.   The garnishing can change although we are aiming to always have salt-baked Roscoff onions, a type of mushroom, and a wintery but tender green. 
  - urls.small: https://images.unsplash.com/photo-1601974915460-f55ea921f8ad?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8Mnx8dG9mdSUyMHNvdXB8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=400
  - urls.regular: https://images.unsplash.com/photo-1601974915460-f55ea921f8ad?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8Mnx8dG9mdSUyMHNvdXB8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=1080
- ID: `_EwHC4gv59Q`; diterima setelah perbaikan: False
  - alt_description: A bowl of delicious soup with garnish
  - description: A steaming bowl of Bún Riêu packed with minced crab, fried soft-shell crab, tofu, and vibrant herbs in a rich, tomato-based broth. The bright red-orange soup contrasts beautifully with the fresh green onions and tender pork pieces. This comforting Vietnamese noodle soup delivers bold flavors and texture in every spoonful.
  - urls.small: https://images.unsplash.com/photo-1745817078506-bfc70df458b5?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8M3x8dG9mdSUyMHNvdXB8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=400
  - urls.regular: https://images.unsplash.com/photo-1745817078506-bfc70df458b5?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8M3x8dG9mdSUyMHNvdXB8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=1080
- ID: `MyIBscTsMBU`; diterima setelah perbaikan: False
  - alt_description: Seaweed soup and porridge served on a plaid tablecloth
  - description: None
  - urls.small: https://images.unsplash.com/photo-1784448678101-51e8b1dfcee3?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8NHx8dG9mdSUyMHNvdXB8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=400
  - urls.regular: https://images.unsplash.com/photo-1784448678101-51e8b1dfcee3?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8NHx8dG9mdSUyMHNvdXB8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=1080
- ID: `HQNhc58_6mI`; diterima setelah perbaikan: True
  - alt_description: Bowl of miso soup with tofu and seaweed
  - description: None
  - urls.small: https://images.unsplash.com/photo-1763470260582-894ae15f43bb?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8NXx8dG9mdSUyMHNvdXB8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=400
  - urls.regular: https://images.unsplash.com/photo-1763470260582-894ae15f43bb?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8NXx8dG9mdSUyMHNvdXB8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=1080
### Sayur Bening Bayam Jagung

Query saat live: `sayur bening bayam jagung`. Query setelah perbaikan: `clear vegetable soup corn spinach`.

- ID: `p6QILADC6Wo`; diterima setelah perbaikan: False
  - alt_description: Fried fish with corn and greens
  - description: None
  - urls.small: https://images.unsplash.com/photo-1777483997258-7bb960d39ebb?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8MXx8c2F5dXIlMjBiZW5pbmclMjBiYXlhbSUyMGphZ3VuZ3xlbnwxfDB8fHwxNzkxNjE5MTEwfDA&ixlib=rb-4.1.0&q=80&w=400
  - urls.regular: https://images.unsplash.com/photo-1777483997258-7bb960d39ebb?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8MXx8c2F5dXIlMjBiZW5pbmclMjBiYXlhbSUyMGphZ3VuZ3xlbnwxfDB8fHwxNzkxNjE5MTEwfDA&ixlib=rb-4.1.0&q=80&w=1080
- ID: `JTRwFtkS6d0`; diterima setelah perbaikan: False
  - alt_description: a pile of green leafy vegetables sitting on top of a table
  - description: Asterids, pomoea aquatic at a market hall in Chongqing in a market hall in Chongqing
  - urls.small: https://images.unsplash.com/photo-1707225405402-924a5fcafced?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8Mnx8c2F5dXIlMjBiZW5pbmclMjBiYXlhbSUyMGphZ3VuZ3xlbnwxfDB8fHwxNzkxNjE5MTEwfDA&ixlib=rb-4.1.0&q=80&w=400
  - urls.regular: https://images.unsplash.com/photo-1707225405402-924a5fcafced?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8Mnx8c2F5dXIlMjBiZW5pbmclMjBiYXlhbSUyMGphZ3VuZ3xlbnwxfDB8fHwxNzkxNjE5MTEwfDA&ixlib=rb-4.1.0&q=80&w=1080
- ID: `3sAeEfNuGx8`; diterima setelah perbaikan: False
  - alt_description: A woven basket filled with green and orange chili peppers
  - description: None
  - urls.small: https://images.unsplash.com/photo-1782973048100-15f03e42788d?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8M3x8c2F5dXIlMjBiZW5pbmclMjBiYXlhbSUyMGphZ3VuZ3xlbnwxfDB8fHwxNzkxNjE5MTEwfDA&ixlib=rb-4.1.0&q=80&w=400
  - urls.regular: https://images.unsplash.com/photo-1782973048100-15f03e42788d?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8M3x8c2F5dXIlMjBiZW5pbmclMjBiYXlhbSUyMGphZ3VuZ3xlbnwxfDB8fHwxNzkxNjE5MTEwfDA&ixlib=rb-4.1.0&q=80&w=1080
- ID: `qW3_HRK3ZxA`; diterima setelah perbaikan: False
  - alt_description: green corn plant during daytime
  - description: None
  - urls.small: https://images.unsplash.com/photo-1601593768799-7433c2447c29?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8NHx8c2F5dXIlMjBiZW5pbmclMjBiYXlhbSUyMGphZ3VuZ3xlbnwxfDB8fHwxNzkxNjE5MTEwfDA&ixlib=rb-4.1.0&q=80&w=400
  - urls.regular: https://images.unsplash.com/photo-1601593768799-7433c2447c29?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8NHx8c2F5dXIlMjBiZW5pbmclMjBiYXlhbSUyMGphZ3VuZ3xlbnwxfDB8fHwxNzkxNjE5MTEwfDA&ixlib=rb-4.1.0&q=80&w=1080
- ID: `z9yQF70Hv9A`; diterima setelah perbaikan: False
  - alt_description: a close up of a bunch of green plants
  - description: Corn filled.
  - urls.small: https://images.unsplash.com/photo-1704641915394-9c136b9b9a1d?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8NXx8c2F5dXIlMjBiZW5pbmclMjBiYXlhbSUyMGphZ3VuZ3xlbnwxfDB8fHwxNzkxNjE5MTEwfDA&ixlib=rb-4.1.0&q=80&w=400
  - urls.regular: https://images.unsplash.com/photo-1704641915394-9c136b9b9a1d?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8NXx8c2F5dXIlMjBiZW5pbmclMjBiYXlhbSUyMGphZ3VuZ3xlbnwxfDB8fHwxNzkxNjE5MTEwfDA&ixlib=rb-4.1.0&q=80&w=1080
### Tumis Tahu Wortel Pedas

Query saat live: `tumis tahu wortel pedas`. Query setelah perbaikan: `stir fried tofu carrot`.

- ID: `2CvGJqeZHNw`; diterima setelah perbaikan: False
  - alt_description: a wooden table topped with bowls of food
  - description: Indonesian Local Delicacy, Tahu Gejrot. Fried tofu with sweet and spicy Grinded Bird eye Sambal.
  - urls.small: https://images.unsplash.com/photo-1680173073730-852e0ec93bec?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8MXx8dHVtaXMlMjB0YWh1JTIwd29ydGVsJTIwcGVkYXN8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=400
  - urls.regular: https://images.unsplash.com/photo-1680173073730-852e0ec93bec?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8MXx8dHVtaXMlMjB0YWh1JTIwd29ydGVsJTIwcGVkYXN8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=1080
- ID: `SCsexJD-Z0Q`; diterima setelah perbaikan: False
  - alt_description: round cakes with syrup and egg
  - description: None
  - urls.small: https://images.unsplash.com/photo-1546077500-45f1ac492543?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8Mnx8dHVtaXMlMjB0YWh1JTIwd29ydGVsJTIwcGVkYXN8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=400
  - urls.regular: https://images.unsplash.com/photo-1546077500-45f1ac492543?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8Mnx8dHVtaXMlMjB0YWh1JTIwd29ydGVsJTIwcGVkYXN8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=1080
- ID: `KavO42o05ao`; diterima setelah perbaikan: False
  - alt_description: A fruit salad with mint garnish in a white bowl
  - description: A close-up of a stir-fried dish on a white plate. It contains chicken and vegetables, with a glossy sauce and a mint garnish. Spices and fresh ingredients surround the plate.
  - urls.small: https://images.unsplash.com/photo-1757715376673-2540c53becb0?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8M3x8dHVtaXMlMjB0YWh1JTIwd29ydGVsJTIwcGVkYXN8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=400
  - urls.regular: https://images.unsplash.com/photo-1757715376673-2540c53becb0?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8M3x8dHVtaXMlMjB0YWh1JTIwd29ydGVsJTIwcGVkYXN8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=1080
- ID: `xZY4PlG6fNc`; diterima setelah perbaikan: False
  - alt_description: a white plate topped with meat and veggies
  - description: None
  - urls.small: https://images.unsplash.com/photo-1653666351563-6e636c00b38e?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8NHx8dHVtaXMlMjB0YWh1JTIwd29ydGVsJTIwcGVkYXN8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=400
  - urls.regular: https://images.unsplash.com/photo-1653666351563-6e636c00b38e?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8NHx8dHVtaXMlMjB0YWh1JTIwd29ydGVsJTIwcGVkYXN8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=1080
- ID: `f0YPb4TJxSM`; diterima setelah perbaikan: False
  - alt_description: sliced orange and green fruit
  - description: Ready to fix a salat
  - urls.small: https://images.unsplash.com/photo-1583506955278-b24cb3b0c9b5?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8NXx8dHVtaXMlMjB0YWh1JTIwd29ydGVsJTIwcGVkYXN8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=400
  - urls.regular: https://images.unsplash.com/photo-1583506955278-b24cb3b0c9b5?crop=entropy&cs=tinysrgb&fit=max&fm=jpg&ixid=M3wxMDk3MTY1fDB8MXxzZWFyY2h8NXx8dHVtaXMlMjB0YWh1JTIwd29ydGVsJTIwcGVkYXN8ZW58MXwwfHx8MTc5MTYxOTExMHww&ixlib=rb-4.1.0&q=80&w=1080

## Verifikasi akhir

116 tes / 870 assertions lulus; Pint --test lulus; 16 routes; npm run build lulus (Node lokal22). Tests mencakup tiga foto unik sampai Controller/Blade/detail/favorit, cache hit, relevansi dan isolasi kegagalan per foto. Gemini, jumlah/kategori resep, Controller, Blade, favorit dan riwayat tidak diubah.

Tiga foto untuk tiga judul terbaru belum terbukti tersedia: 1 foto nyata berhasil dimuat, 2 placeholder tetap benar. Jalur tiga foto valid terbukti melalui fake. Tidak lanjut Step9.
