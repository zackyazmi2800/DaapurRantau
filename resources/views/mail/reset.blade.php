<!DOCTYPE html>
<html lang="id">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reset password Dapur Rantau</title></head>
<body style="margin:0;padding:24px;background:#FFF8EB;color:#25332B;font-family:Arial,sans-serif;line-height:1.6">
    <main style="max-width:520px;margin:auto;padding:24px;background:#fff;border-radius:16px">
        <p style="color:#427A54;font-weight:bold">Dapur Rantau</p>
        <h1 style="font-size:24px">Buat password baru</h1>
        <p>Kami menerima permintaan reset password untuk akun Dapur Rantau kamu.</p>
        <p><a href="{{ $url }}" style="display:inline-block;padding:12px 20px;border-radius:12px;background:#427A54;color:#fff;text-decoration:none;font-weight:bold">Reset Password</a></p>
        <p>Link ini berlaku {{ $expiresInMinutes }} menit dan hanya bisa digunakan sekali. Setelah berhasil, masuk menggunakan password barumu.</p>
        <p>Jika tombol tidak dapat dibuka, salin link berikut ke browser:</p>
        <p style="overflow-wrap:anywhere;word-break:break-all">{{ $url }}</p>
        <p>Jika kamu tidak meminta reset password, abaikan email ini. Password kamu tidak berubah sampai proses reset berhasil.</p>
    </main>
</body>
</html>
