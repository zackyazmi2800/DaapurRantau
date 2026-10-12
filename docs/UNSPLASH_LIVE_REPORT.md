# Laporan live Unsplash — satu batch tiga request

Request asli menggunakan RecipeImageService/Http::pool; Gemini tidak dipanggil. Cache dilewati dengan invalidasi hanya tiga key gambar. Environment local, key terbaca boolean, database cache.

Durasi batch: 1074 ms. Total request asli: 3; request tambahan saat replay/cache: 0. Perbaikan dievaluasi terhadap respons asli yang disimpan, tanpa pencarian baru.

| Resep | Query | HTTP | Mulai (ms) | Durasi (ms) | Kandidat | Lolos setelah perbaikan | Limit/Remaining | Hasil |
|---|---|---:|---:|---:|---:|---:|---|---|
| Nasi Goreng Telur | Indonesian egg fried rice | 200 | 23 | 405 | 5 | 2 | 50/49 | unsplash — rQX9eVpSFz8 |
| Mie Goreng | Indonesian fried noodles | 200 | 25 | 1018 | 5 | 2 | 50/47 | unsplash — i5XurHSjE1M |
| Telur Dadar | Indonesian omelette | 200 | 26 | 1018 | 5 | 2 | 50/46 | unsplash — M5IajiQxoeo |

## Diagnosis

Mie memakai ejaan **Mi Goreng** pada description; kamus lama tidak mengenali variasi ini. Telur dadar sebelumnya memilih caption omelette dengan alt sup: metadata yang bertentangan perlu ditolak dan kandidat dengan dukungan alt perlu diprioritaskan. Aliran Controller→snapshot→Blade sudah benar. Cache lama dapat menyimpan hasil placeholder; versi strategi3 mengisolasinya tanpa flush.

Foto adalah ilustrasi hidangan, bukan bukti seluruh bahan/takaran cocok. HTTP200 menghasilkan URL small/regular; tidak ditemukan kegagalan autentikasi. Header remaining adalah nilai perrespons, bukan jumlah yang boleh dijumlahkan.

## Nasi Goreng Telur

Sebelum: unsplash / rQX9eVpSFz8. Sesudah: unsplash / rQX9eVpSFz8. Cache sama: True.

| ID kandidat | alt_description | description | small | Lolos | Alasan |
|---|---|---|---|---|---|
| rQX9eVpSFz8 | A mound of fried rice with peas and carrots on a white square plate | — | Ya | Ya | Identitas hidangan dan metadata valid (skor 20) |
| szQFiGZH8wU | rice and fried meat with egg in plate | Chilli mania | Ya | Tidak | Identitas tidak cukup cocok atau alt bertentangan (skor 0) |
| 4WCnkZ0m9-4 | a plate of food that is on a table | — | Ya | Tidak | Identitas tidak cukup cocok atau alt bertentangan (skor 0) |
| o6Oq7rBMqVc | a plate of rice with shrimp and vegetables | Nasi Goreng Seafood  /  /  Seafood Fried Rice | Ya | Ya | Identitas hidangan dan metadata valid (skor 10) |
| bEUZfy7m-NU | a plate of food | Rice, Food, Vegetable | Ya | Tidak | Identitas tidak cukup cocok atau alt bertentangan (skor 0) |

## Mie Goreng

Sebelum: placeholder / None. Sesudah: unsplash / i5XurHSjE1M. Cache sama: True.

| ID kandidat | alt_description | description | small | Lolos | Alasan |
|---|---|---|---|---|---|
| i5XurHSjE1M | a plate of food on a table with utensils | Mi Goreng Jawa, Indonesian Food | Ya | Ya | Identitas hidangan dan metadata valid (skor 10) |
| IGk3j32SrKU | pasta dish on white ceramic plate | Pad Thai With Shirataki Noodles Recipe  /  Low Calorie  /  Shirataki Pad Thai  /  Diet Food | Ya | Tidak | Identitas tidak cukup cocok atau alt bertentangan (skor 0) |
| zvHFbU945jI | A white plate topped with a salad and an egg | — | Ya | Tidak | Identitas tidak cukup cocok atau alt bertentangan (skor 0) |
| qpikY2wPP-g | a white plate topped with noodles and veggies | Mi Goreng Ulang Tahun with vegetables and egg topping | Ya | Ya | Identitas hidangan dan metadata valid (skor 10) |
| 1Sn8ONatKFw | Noodles topped with fried egg and green vegetables | INDONESIAN NOODLE | Ya | Tidak | Identitas tidak cukup cocok atau alt bertentangan (skor 0) |

## Telur Dadar

Sebelum: unsplash / C2HUZQPZazw. Sesudah: unsplash / M5IajiQxoeo. Cache sama: True.

| ID kandidat | alt_description | description | small | Lolos | Alasan |
|---|---|---|---|---|---|
| C2HUZQPZazw | a bowl of soup | Omelette with cheese | Ya | Tidak | Identitas tidak cukup cocok atau alt bertentangan (skor 0) |
| ivFdoHieX08 | a slice of quiche on a white plate on a wooden table | Omelette  | Ya | Tidak | Identitas tidak cukup cocok atau alt bertentangan (skor 0) |
| M5IajiQxoeo | a white plate topped with an omelet next to a glass of orange juice | A Sri Lankan style omlette. | Ya | Ya | Identitas hidangan dan metadata valid (skor 20) |
| iuYv5W88rLo | a white plate topped with an omelet covered in sauce | — | Ya | Ya | Identitas hidangan dan metadata valid (skor 20) |
| thA19YMtgt4 | cooked food on white ceramic plate | Nasi ulam | Ya | Tidak | Identitas tidak cukup cocok atau alt bertentangan (skor 0) |

## Verifikasi akhir

Ketiga gambar pada card berhasil dimuat browser (naturalWidth400); detail mie dan telur dadar berhasil pada1080px. Pratinjau memakai Blade aplikasi, data resep fixture, dan metadata foto asli di cache; bukan hasil Gemini live baru. Atribusi tampil. Cache ulang memakai HTTP fake dan menghasilkan0request tambahan.

php artisan test:109tests792assertions lulus; route:list16; Pintlulus. Tidak ada perubahan frontend, build tidak diulang. Total JSON API request live tetap3, Gemini0. Pemanggilan CDN foto oleh browser hanya hotlink. Kategori resep/favorit/riwayat tetap lolos regressiontests.
