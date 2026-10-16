<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleLoginException extends RuntimeException
{
    public const UNAVAILABLE = 'Login Google belum tersedia. Gunakan email dan password.';
    public const FAILED = 'Login Google belum berhasil. Silakan mulai kembali dari halaman masuk.';
    public const EMAIL_USED = 'Email ini sudah digunakan akun lain. Masuk dengan metode sebelumnya. Akun tidak ditautkan otomatis.';
}
