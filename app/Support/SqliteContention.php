<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use PDOException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SqliteContention
{
    public static function isBusy(Throwable $exception, bool $sqliteConnection = false): bool
    {
        if ($exception instanceof \App\Exceptions\SqliteBusyException) {
            return true;
        }
        if ($exception instanceof QueryException) {
            $sqliteConnection = config('database.connections.'.$exception->getConnectionName().'.driver') === 'sqlite';
        }

        if (! $sqliteConnection || ! $exception instanceof PDOException) {
            return false;
        }

        // SQLite primary codes BUSY (5) and LOCKED (6), including extended codes.
        $code = $exception->errorInfo[1] ?? null;

        return is_numeric($code) && in_array(((int) $code) & 0xff, [5, 6], true);
    }

    public static function response(Request $request): Response
    {
        $payment = $request->is('api/midtrans/*', 'langganan', 'langganan/*');
        $message = $payment
            ? 'Layanan pembayaran sedang sibuk. Tunggu sebentar lalu coba kembali.'
            : 'Penyimpanan sedang sibuk. Tunggu sebentar lalu kirim ulang pencarian yang sama. Jangan membuat pencarian baru.';
        $headers = ['Retry-After' => '2', 'Cache-Control' => 'no-store'];

        // Do not render the application layout, read quota, or flash session here:
        // the database/session/cache may itself be the failing component.
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $message], 503, $headers);
        }
        $title = $payment ? 'Pembayaran sedang sibuk' : 'Penyimpanan sedang sibuk';
        $html = '<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>'.$title.'</title><main><h1>'.$title.'</h1><p>'.$message.'</p></main></html>';
        if ($payment) {
            $html = rescue(fn () => view('errors.payment-busy', ['message' => $message])->render(), $html, false);
        }

        return response($html, 503, $headers + ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
