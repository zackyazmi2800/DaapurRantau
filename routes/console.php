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

Artisan::command('payments:gateway-refund {payment : ID pembayaran} {--amount=} {--reason=} {--refund-key= : Kunci unik refund (8-50 karakter)} {--operator= : Nama operator (default: user sistem)} {--confirm : Kirim refund sungguhan} {--confirm-production= : Ulangi order_id pembayaran untuk refund Production} {--reconcile : Cocokkan refund yang belum selesai dengan Get Status}', function (GatewayRefundService $refunds) {
    $payment = $this->argument('payment');
    $operator = $this->option('operator') ?? (function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null) ?? get_current_user();
    if (! ctype_digit((string) $payment) || ! is_string($operator) || trim($operator) === '' || mb_strlen($operator) > 100) {
        $this->error(ctype_digit((string) $payment) ? 'Opsi --operator wajib diisi (maksimal 100 karakter).' : 'ID pembayaran harus berupa angka.');

        return 1;
    }
    try {
        [$ok, $message] = $this->option('reconcile') ? $refunds->reconcile((int) $payment)
            : $refunds->refund((int) $payment, $this->option('amount'), $this->option('reason'), $this->option('refund-key'), trim($operator),
                (bool) $this->option('confirm'), $this->option('confirm-production'));
    } catch (Throwable $exception) {
        [$ok, $message] = SqliteContention::isBusy($exception) ? [false, 'Database sedang sibuk. Jalankan --reconcile sebelum mencoba lagi.'] : throw $exception;
    }
    $ok ? $this->line($message) : $this->error($message);

    return $ok ? 0 : 1;
})->purpose('Refund melalui Midtrans Refund API (dry-run kecuali --confirm)');

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
