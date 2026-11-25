<?php

use App\Services\GatewayRefundService;
use App\Services\MidtransSandboxService;
use App\Services\PaymentAdjustmentService;
use App\Support\SqliteContention;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('payments:reconcile {--order=} {--limit=20}', function (MidtransSandboxService $gateway) {
    $order = $this->option('order');
    if ($order !== null && ! Str::isUuid($order)) {
        $this->error('Order ID harus berupa UUID.');

        return 1;
    }
    $counts = $gateway->reconcile($order, max(1, min(100, (int) $this->option('limit'))));
    $this->line($counts === [] ? 'Tidak ada pembayaran yang perlu diperiksa.'
        : 'Hasil rekonsiliasi: '.collect($counts)->map(fn ($count, $outcome) => "$outcome=$count")->implode(', '));

    return 0;
})->purpose('Rekonsiliasi pembayaran pending dengan Midtrans');

Artisan::command('payments:refund {payment : ID pembayaran} {--amount=} {--reason=} {--reference=} {--confirm}', function (PaymentAdjustmentService $adjustments) {
    try {
        [$ok, $message] = $adjustments->manualRefund((int) $this->argument('payment'), $this->option('amount'), $this->option('reason'), $this->option('reference'), (bool) $this->option('confirm'));
    } catch (Throwable $exception) {
        [$ok, $message] = SqliteContention::isBusy($exception) ? [false, 'Database sedang sibuk. Tidak ada yang dicatat, coba lagi.'] : throw $exception;
    }
    $ok ? $this->line($message) : $this->error($message);

    return $ok ? 0 : 1;
})->purpose('Catat refund manual yang sudah ditransfer di luar Midtrans (tidak mengirim uang)');

Artisan::command('payments:gateway-refund {payment : ID pembayaran} {--amount=} {--reason=} {--refund-key= : Kunci unik refund (8-50 karakter)} {--operator= : Nama operator (default: user sistem)} {--confirm : Kirim refund sungguhan} {--confirm-production= : Ulangi order_id pembayaran untuk refund Production} {--reconcile : Cocokkan refund yang belum selesai dengan Get Status} {--reattempt : Ulangi refund pending/unknown dengan refund_key yang sama}', function (GatewayRefundService $refunds) {
    $payment = $this->argument('payment');
    $operator = $this->option('operator') ?? (function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null) ?? get_current_user();
    if (! ctype_digit((string) $payment) || ! is_string($operator) || trim($operator) === '' || mb_strlen($operator) > 100) {
        $this->error(ctype_digit((string) $payment) ? 'Opsi --operator wajib diisi (maksimal 100 karakter).' : 'ID pembayaran harus berupa angka.');

        return 1;
    }
    try {
        [$ok, $message] = match (true) {
            $this->option('reconcile') && $this->option('reattempt') => [false, 'Pilih salah satu: --reconcile atau --reattempt.'],
            (bool) $this->option('reconcile') => $refunds->reconcile((int) $payment),
            (bool) $this->option('reattempt') => $refunds->reattempt((int) $payment, $this->option('refund-key'), trim($operator),
                (bool) $this->option('confirm'), $this->option('confirm-production')),
            default => $refunds->refund((int) $payment, $this->option('amount'), $this->option('reason'), $this->option('refund-key'), trim($operator),
                (bool) $this->option('confirm'), $this->option('confirm-production')),
        };
    } catch (Throwable $exception) {
        [$ok, $message] = SqliteContention::isBusy($exception) ? [false, 'Database sedang sibuk. Jalankan --reconcile sebelum mencoba lagi.'] : throw $exception;
    }
    $ok ? $this->line($message) : $this->error($message);

    return $ok ? 0 : 1;
})->purpose('Refund melalui Midtrans Refund API (dry-run kecuali --confirm)');

Artisan::command('payments:gateway-refunds {--status= : Filter dipisah koma: pending,submitted,unknown,review,succeeded,confirmed,rejected,closed_unprocessed} {--reconcile : Rekonsiliasi melalui Get Status (tidak pernah mengirim refund)} {--limit=20}', function (GatewayRefundService $refunds) {
    $limit = max(1, min(100, (int) $this->option('limit')));
    try {
        if ($this->option('reconcile')) {
            $counts = $refunds->reconcileOpen($limit);
            $this->line($counts === [] ? 'Tidak ada refund Midtrans yang perlu direkonsiliasi.'
                : 'Hasil rekonsiliasi refund: '.collect($counts)->map(fn ($count, $outcome) => "$outcome=$count")->implode(', '));

            return 0;
        }
        $statuses = $this->option('status') === null ? GatewayRefundService::OPEN : array_values(array_filter(array_map('trim', explode(',', $this->option('status')))));
        $unknown = array_diff($statuses, GatewayRefundService::STATUSES);
        if ($statuses === [] || $unknown !== []) {
            $this->error('Status tidak dikenal: '.implode(', ', $unknown ?: ['(kosong)']).'.');

            return 1;
        }
        $rows = $refunds->open($statuses, $limit * 5);
    } catch (Throwable $exception) {
        if (! SqliteContention::isBusy($exception)) {
            throw $exception;
        }
        $this->error('Database sedang sibuk. Tidak ada perubahan, coba lagi.');

        return 1;
    }
    if ($rows->isEmpty()) {
        $this->line('Tidak ada refund Midtrans dengan status '.implode(', ', $statuses).'.');

        return 0;
    }
    $this->table(['ID', 'Pembayaran', 'Order ID', 'refund_key', 'Nominal', 'Status', 'Env', 'Percobaan', 'Dibuat (UTC)', 'Dicek terakhir', 'Batas reattempt'],
        $rows->map(fn ($row) => [$row->id, $row->payment_id, $row->order_id, $row->refund_key, 'Rp'.number_format($row->amount, 0, ',', '.'), $row->status,
            $row->environment, $row->attempts, $row->created_at, $row->last_checked_at ?? '-',
            in_array($row->status, ['pending', 'unknown'], true) ? $refunds->reattemptDeadline($row)->toDateTimeString() : '-'])->all());

    return 0;
})->purpose('Daftar dan rekonsiliasi refund Midtrans dari aplikasi (tidak mengirim refund)');

Artisan::command('payments:gateway-refund-close-request {refund : ID refund gateway (kolom ID pada payments:gateway-refunds)} {--ticket= : Referensi tiket/bukti tertulis Midtrans} {--confirmed-on= : Tanggal konfirmasi Midtrans (YYYY-MM-DD)} {--order-id= : Order ID sesuai konfirmasi Midtrans} {--refund-key= : refund_key sesuai konfirmasi Midtrans} {--amount= : Nominal sesuai konfirmasi Midtrans} {--midtrans-confirmed-not-processed : Midtrans menyatakan tertulis bahwa refund tidak pernah diproses} {--reason=} {--confirm : Catat permintaan}', function (GatewayRefundService $refunds) {
    try {
        [$ok, $message] = ctype_digit((string) $this->argument('refund')) ? $refunds->requestClosure((int) $this->argument('refund'), [
            'ticket' => $this->option('ticket'), 'confirmed_on' => $this->option('confirmed-on'), 'order_id' => $this->option('order-id'),
            'refund_key' => $this->option('refund-key'), 'amount' => $this->option('amount'), 'reason' => $this->option('reason'),
            'statement' => (bool) $this->option('midtrans-confirmed-not-processed'),
        ], (bool) $this->option('confirm')) : [false, 'ID refund harus berupa angka.'];
    } catch (Throwable $exception) {
        [$ok, $message] = SqliteContention::isBusy($exception) ? [false, 'Database sedang sibuk. Tidak ada yang dicatat, coba lagi.'] : throw $exception;
    }
    $ok ? $this->line($message) : $this->error($message);

    return $ok ? 0 : 1;
})->purpose('Ajukan penutupan refund unknown yang dikonfirmasi Midtrans tidak diproses (langkah 1 dari 2, tidak mengirim refund)');

Artisan::command('payments:gateway-refund-close-approve {request : ID permintaan penutupan} {--ticket= : Ulangi referensi tiket Midtrans dari bukti asli} {--confirm : Tutup refund dan lepaskan slot}', function (GatewayRefundService $refunds) {
    try {
        [$ok, $message] = ctype_digit((string) $this->argument('request'))
            ? $refunds->approveClosure((int) $this->argument('request'), $this->option('ticket'), (bool) $this->option('confirm'))
            : [false, 'ID permintaan harus berupa angka.'];
    } catch (Throwable $exception) {
        [$ok, $message] = SqliteContention::isBusy($exception) ? [false, 'Database sedang sibuk. Tidak ada perubahan, coba lagi.'] : throw $exception;
    }
    $ok ? $this->line($message) : $this->error($message);

    return $ok ? 0 : 1;
})->purpose('Setujui penutupan refund unknown oleh operator kedua (akun OS berbeda; langkah 2 dari 2, tidak mengirim refund)');

Artisan::command('payments:review {payment : ID pembayaran} {--restore} {--revoke} {--confirm}', function (PaymentAdjustmentService $adjustments) {
    $payment = DB::table('payments')->where('id', (int) $this->argument('payment'))->first();
    if (! $payment || $this->option('restore') === $this->option('revoke')) {
        $this->error($payment ? 'Pilih tepat satu keputusan: --restore atau --revoke.' : 'Pembayaran tidak ditemukan.');

        return 1;
    }
    try {
        [$ok, $message] = $adjustments->close($payment, (bool) $this->option('restore'), (bool) $this->option('confirm'));
    } catch (Throwable $exception) {
        [$ok, $message] = SqliteContention::isBusy($exception) ? [false, 'Database sedang sibuk. Tidak ada perubahan, coba lagi.'] : throw $exception;
    }
    $ok ? $this->line($message) : $this->error($message);

    return $ok ? 0 : 1;
})->purpose('Tutup peninjauan refund atau chargeback sebuah pembayaran');

Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping(10);
// Read-only towards Midtrans (Get Status); refunds are only ever sent by an operator.
Schedule::command('payments:gateway-refunds --reconcile')->everyFifteenMinutes()->withoutOverlapping(10);
