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
        $message = 'Penyimpanan sedang sibuk. Tunggu sebentar lalu kirim ulang pencarian yang sama. Jangan membuat pencarian baru.';
        $headers = ['Retry-After' => '2', 'Cache-Control' => 'no-store'];

        // Do not render the application layout, read quota, or flash session here:
        // the database/session/cache may itself be the failing component.
        return $request->expectsJson()
            ? response()->json(['message' => $message], 503, $headers)
            : response('<!doctype html><html lang="id"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Penyimpanan sedang sibuk</title><main><h1>Penyimpanan sedang sibuk</h1><p>'.$message.'</p></main></html>', 503, $headers + ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
