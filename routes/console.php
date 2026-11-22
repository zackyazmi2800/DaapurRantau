<?php

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
})->purpose('Rekonsiliasi pembayaran pending dengan Midtrans Sandbox');

Artisan::command('payments:refund {payment : ID pembayaran} {--amount=} {--reason=} {--reference=} {--confirm}', function (PaymentAdjustmentService $adjustments) {
    try {
        [$ok, $message] = $adjustments->manualRefund((int) $this->argument('payment'), $this->option('amount'), $this->option('reason'), $this->option('reference'), (bool) $this->option('confirm'));
    } catch (Throwable $exception) {
        [$ok, $message] = SqliteContention::isBusy($exception) ? [false, 'Database sedang sibuk. Tidak ada yang dicatat, coba lagi.'] : throw $exception;
    }
    $ok ? $this->line($message) : $this->error($message);

    return $ok ? 0 : 1;
})->purpose('Catat refund manual yang sudah ditransfer di luar Midtrans (tidak mengirim uang)');

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
