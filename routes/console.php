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

Artisan::command('payments:review {payment : ID pembayaran} {--restore} {--revoke} {--confirm}', function (PaymentAdjustmentService $adjustments) {
    $payment = DB::table('payments')->where('id', (int) $this->argument('payment'))->first();
    if (! $payment || $this->option('restore') === $this->option('revoke')) {
        $this->error($payment ? 'Pilih tepat satu keputusan: --restore atau --revoke.' : 'Pembayaran tidak ditemukan.');

        return 1;
    }
    $open = DB::table('payment_adjustments')->where('payment_id', $payment->id)->where('status', 'pending_review')->count();
    $plan = $this->option('restore') ? 'Premium dari pembayaran ini dipertahankan atau dipulihkan' : 'sisa Premium dari pembayaran ini dicabut';
    if ($open === 0 || ! $this->option('confirm')) {
        $this->line($open === 0 ? 'Tidak ada peninjauan terbuka untuk pembayaran ini.' : "DRY-RUN, belum ada perubahan. Rencana: menutup {$open} peninjauan; {$plan}. Tambahkan --confirm untuk menjalankan.");

        return 0;
    }
    try {
        $closed = $adjustments->close($payment, (bool) $this->option('restore'));
    } catch (Throwable $exception) {
        SqliteContention::isBusy($exception) ? $this->error('Database sedang sibuk. Tidak ada perubahan, coba lagi.') : throw $exception;

        return 1;
    }
    $this->line($closed === 0 ? 'Tidak ada peninjauan terbuka untuk pembayaran ini.' : "Ditutup: {$closed} peninjauan; {$plan}.");

    return 0;
})->purpose('Tutup peninjauan refund atau chargeback sebuah pembayaran');

Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping(10);
