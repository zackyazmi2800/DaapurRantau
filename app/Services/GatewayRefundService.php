<?php

namespace App\Services;

use App\Support\OperatorIdentity;
use App\Support\PaymentAlert;
use App\Support\SqliteContention;
use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Two-phase Midtrans refunds: a durable intent is stored before the Refund API call,
 * and the result is only trusted after matching refunds[] in Get Status by refund_key.
 */
class GatewayRefundService
{
    /** Requests whose outcome is not settled yet; they hold the payment's refund slot. */
    public const UNRESOLVED = ['pending', 'submitted', 'unknown', 'review'];

    /** Every status reconciliation still needs to look at (confirmed and rejected are final). */
    public const OPEN = [...self::UNRESOLVED, 'succeeded'];

    /** Terminal: closed by two operators after Midtrans confirmed in writing it never processed the refund. */
    public const CLOSED = 'closed_unprocessed';

    public const STATUSES = [...self::OPEN, 'confirmed', 'rejected', self::CLOSED];

    public function __construct(private MidtransSandboxService $gateway, private PaymentAdjustmentService $adjustments, private OperatorIdentity $identity) {}

    public function refund(int $paymentId, mixed $amount, mixed $reason, mixed $key, string $operator, bool $confirm, mixed $production): array
    {
        if (! is_string($amount) || ! preg_match('/\A[1-9][0-9]{0,8}\z/', $amount)) {
            return [false, 'Opsi --amount wajib berupa bilangan bulat rupiah yang lebih dari nol.'];
        }
        if (! is_string($reason) || trim($reason) === '' || mb_strlen($reason) > 255) {
            return [false, 'Opsi --reason wajib diisi (maksimal 255 karakter).'];
        }
        if ($key !== null && (! is_string($key) || ! preg_match('/\A[A-Za-z0-9_-]{8,50}\z/', $key))) {
            return [false, 'Opsi --refund-key harus 8-50 karakter berupa huruf, angka, tanda - atau _.'];
        }
        if ($confirm && $key === null) {
            return [false, 'Eksekusi memerlukan --refund-key yang unik. Tidak ada refund dikirim.'];
        }
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (! $payment) {
            return [false, 'Pembayaran tidak ditemukan.'];
        }
        $environment = $this->gateway->environment();
        if ($environment === null || ! $this->gateway->serverKeyReady()) {
            return [false, 'Konfigurasi Midtrans (MIDTRANS_ENVIRONMENT dan Server Key) belum lengkap. Tidak ada refund dikirim.'];
        }
        if ($confirm && $environment === 'production' && $production !== $payment->order_id) {
            return [false, 'Refund Production memerlukan --confirm-production=<order_id pembayaran>. Tidak ada refund dikirim.'];
        }
        $amount = (int) $amount;
        if ($blocked = $this->localBlock($payment, $key)) {
            return [false, $blocked];
        }
        try {
            $current = $this->gateway->currentStatus($payment);
        } catch (HttpException) {
            return [false, 'Status transaksi Midtrans belum dapat diverifikasi. Tidak ada refund dikirim.'];
        }
        if ($current === null) {
            return [false, 'Transaksi tidak ditemukan di Midtrans. Tidak ada refund dikirim.'];
        }
        [$error, $plan] = $this->inspect($payment, $current, $amount, $key);
        if ($error !== null) {
            return [false, $error];
        }
        $summary = sprintf('Environment %s, metode %s, pembayaran %s, sudah direfund Midtrans %s, refund manual %s, dicadangkan %s, sisa %s, refund ini %s (%s).',
            $environment, $plan['payment_type'], $this->rupiah((int) $payment->amount), $this->rupiah($plan['gateway']),
            $this->rupiah($plan['manual']), $this->rupiah($plan['reserved']), $this->rupiah($plan['remaining']), $this->rupiah($amount),
            $plan['full'] ? 'refund penuh: Premium dari pembayaran ini dicabut' : 'refund sebagian: Premium tidak diubah, dicatat untuk ditinjau');
        if (! $confirm) {
            $hint = $key ?? 'dr-refund-'.str_replace('-', '', (string) Str::uuid());
            $extra = $environment === 'production' ? " --confirm-production={$payment->order_id}" : '';

            return [true, "DRY-RUN, belum ada refund dikirim. {$summary} Untuk mengirim: --confirm --refund-key={$hint}{$extra}"];
        }

        return $this->execute($payment, $environment, $key, $amount, trim($reason), $operator, $plan['listed']);
    }

    public function reconcile(int $paymentId): array
    {
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (! $payment) {
            return [false, 'Pembayaran tidak ditemukan.'];
        }
        $intents = $this->recheck()->where('payment_id', $paymentId)->orderBy('id')->get();
        if ($intents->isEmpty()) {
            return [true, 'Tidak ada refund Midtrans yang perlu direkonsiliasi untuk pembayaran ini.'];
        }
        if ($this->gateway->environment() === null || ! $this->gateway->serverKeyReady()) {
            return [false, 'Konfigurasi Midtrans belum lengkap. Tidak ada perubahan.'];
        }
        try {
            $lines = $this->reconcilePayment($payment, $intents);
        } catch (HttpException) {
            return [false, 'Status transaksi Midtrans belum dapat diverifikasi. Tidak ada perubahan.'];
        }

        return [true, 'Rekonsiliasi refund: '.implode(' ', array_map(fn ($line) => "{$line[0]}: {$line[1]} -> {$line[2]}.", $lines))];
    }

    /** Open gateway refunds for operators; read-only. */
    public function open(array $statuses, int $limit = 100): Collection
    {
        return DB::table('payment_gateway_refunds')->join('payments', 'payments.id', '=', 'payment_gateway_refunds.payment_id')
            ->whereIn('payment_gateway_refunds.status', $statuses)->orderBy('payment_gateway_refunds.id')->limit($limit)
            ->get(['payment_gateway_refunds.*', 'payments.order_id']);
    }

    public function reattemptDeadline(object $intent): Carbon
    {
        // Anchored on intent creation, which precedes the first POST, so it never exceeds Midtrans' 7 days.
        return Carbon::parse($intent->created_at)->addHours((int) config('midtrans.refund.reattempt_window_hours', 144));
    }

    /**
     * Get Status only: never sends a refund. Safe to run from the scheduler and in parallel.
     */
    public function reconcileOpen(int $limit = 20): array
    {
        if ($this->gateway->environment() === null || ! $this->gateway->serverKeyReady()) {
            return ['inactive' => 1];
        }
        $lock = Cache::lock('payments:gateway-refunds:reconcile', 120);
        try {
            if (! $lock->get()) {
                return ['skipped' => 1];
            }
        } catch (Throwable $exception) {
            return SqliteContention::isBusy($exception) ? ['busy' => 1] : throw $exception;
        }
        try {
            $counts = [];
            $failures = 0;
            $started = microtime(true);
            $paymentIds = $this->recheck()->where('environment', $this->gateway->environment())
                ->orderByRaw('last_checked_at is not null')->orderBy('last_checked_at')->orderBy('id')->limit($limit)->pluck('payment_id')->unique();
            foreach ($paymentIds as $paymentId) {
                if ($failures >= 3 || microtime(true) - $started > 40) {
                    break;
                }
                $payment = DB::table('payments')->where('id', $paymentId)->first();
                $intents = $this->recheck()->where('payment_id', $paymentId)->orderBy('id')->get();
                try {
                    foreach ($this->reconcilePayment($payment, $intents) as [, $before, $after]) {
                        $outcome = $before === $after ? 'unchanged' : $after;
                        $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                    }
                    $failures = 0;
                } catch (HttpException) {
                    $failures++;
                    $counts['unverified'] = ($counts['unverified'] ?? 0) + 1;
                }
            }

            return $counts;
        } finally {
            $lock->release();
        }
    }

    /**
     * Open refunds plus administrative closures, in case Midtrans lists one after all. A closure stays
     * uncertain forever: every run for the first 30 days, afterwards at most once a day.
     */
    private function recheck(): Builder
    {
        return DB::table('payment_gateway_refunds')->where(fn ($query) => $query->whereIn('status', self::OPEN)
            ->orWhere(fn ($query) => $query->where('status', self::CLOSED)->where(fn ($query) => $query
                ->where('updated_at', '>=', now()->subDays(30))->orWhereNull('last_checked_at')
                ->orWhere('last_checked_at', '<=', now()->subDay()))));
    }

    /** @return list<array{0: string, 1: string, 2: string}> refund_key, status before, status after */
    private function reconcilePayment(object $payment, Collection $intents): array
    {
        $current = $this->gateway->currentStatus($payment);
        $lines = [];
        foreach ($intents as $intent) {
            $after = $intent->environment !== $this->gateway->environment() ? $intent->status
                : ($current === null ? $this->touch($intent) : $this->verify($payment, $intent, $current));
            $lines[] = [$intent->refund_key, $intent->status, $after];
        }

        return $lines;
    }

    /**
     * Explicit operator reattempt of an unresolved request with the SAME refund_key, amount and reason.
     * Midtrans treats the same refund_key as a retry of the same refund, for at most 7 days.
     */
    public function reattempt(int $paymentId, mixed $key, string $operator, bool $confirm, mixed $production): array
    {
        $intent = is_string($key) ? DB::table('payment_gateway_refunds')->where(['payment_id' => $paymentId, 'refund_key' => $key])->first() : null;
        if (! $intent) {
            return [false, 'Refund dengan --refund-key tersebut tidak ditemukan untuk pembayaran ini.'];
        }
        if (! in_array($intent->status, ['pending', 'unknown'], true)) {
            return [false, match ($intent->status) {
                'submitted' => 'Midtrans sudah menerima refund ini; jangan diulang. Jalankan --reconcile.',
                'review' => 'Refund ini menunggu peninjauan nominal; jangan diulang.',
                default => "Refund berstatus {$intent->status} tidak perlu diulang.",
            }];
        }
        $environment = $this->gateway->environment();
        if ($intent->environment !== $environment || ! $this->gateway->serverKeyReady()) {
            return [false, 'Environment Midtrans aktif tidak sesuai dengan refund ini atau konfigurasinya belum lengkap. Tidak ada refund dikirim.'];
        }
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if ($confirm && $environment === 'production' && $production !== $payment->order_id) {
            return [false, 'Reattempt Production memerlukan --confirm-production=<order_id pembayaran>. Tidak ada refund dikirim.'];
        }
        $deadline = $this->reattemptDeadline($intent);
        if (now()->greaterThanOrEqualTo($deadline)) {
            return [false, "Batas reattempt dengan refund_key yang sama sudah lewat ({$deadline->toDateTimeString()} UTC). Jangan membuat refund_key baru. Ikuti prosedur konfirmasi Midtrans di dokumentasi operasional; refund ini tetap menahan slot."];
        }
        $cooldown = (int) config('midtrans.refund.reattempt_cooldown_minutes', 10);
        if ($intent->last_attempt_at !== null && Carbon::parse($intent->last_attempt_at)->addMinutes($cooldown)->isFuture()) {
            return [false, "Percobaan terakhir kurang dari {$cooldown} menit yang lalu. Jalankan --reconcile dahulu, lalu ulangi bila masih perlu."];
        }
        try {
            $current = $this->gateway->currentStatus($payment);
        } catch (HttpException) {
            return [false, 'Status transaksi Midtrans belum dapat diverifikasi. Tidak ada refund dikirim.'];
        }
        if ($current === null) {
            return [false, 'Transaksi tidak ditemukan di Midtrans. Tidak ada refund dikirim.'];
        }
        if ($this->entry($current, $intent->refund_key) !== null) {
            return [true, 'refund_key ini sudah tercatat di Midtrans; tidak perlu diulang. Status: '.$this->verify($payment, $intent, $current).'.'];
        }
        if ($payment->status !== 'paid' || $this->adjustments->refundAnomalyOpen($payment->id)) {
            return [false, 'Pembayaran tidak lagi berstatus paid atau memiliki anomali kelebihan refund. Jangan ulangi; konfirmasikan refund ini ke Midtrans.'];
        }
        [$error] = $this->inspect($payment, $current, (int) $intent->amount, null, $intent->refund_key);
        if ($error !== null) {
            return [false, $error.' Refund ini tetap menahan slot; konfirmasikan ke Midtrans.'];
        }
        if (! $confirm) {
            $extra = $environment === 'production' ? " --confirm-production={$payment->order_id}" : '';

            return [true, "DRY-RUN, belum ada refund dikirim. Mengulang refund_key {$intent->refund_key} dengan nominal {$this->rupiah((int) $intent->amount)} dan alasan yang sama (percobaan ke-".($intent->attempts + 1).", batas {$deadline->toDateTimeString()} UTC). Untuk mengirim: --reattempt --confirm --refund-key={$intent->refund_key}{$extra}"];
        }
        $claimed = DB::transaction(function () use ($payment, $intent) {
            DB::table('users')->where('id', $payment->user_id)->update(['id' => DB::raw('id')]);

            return DB::table('payment_gateway_refunds')->where('id', $intent->id)->where('attempts', $intent->attempts)
                ->whereIn('status', ['pending', 'unknown'])
                ->update(['status' => 'pending', 'attempts' => $intent->attempts + 1, 'last_attempt_at' => now(), 'updated_at' => now()]);
        }, 3);
        if (! $claimed) {
            return [false, 'Status refund berubah bersamaan. Tidak ada refund dikirim; jalankan --reconcile.'];
        }
        $this->audit($intent, 'reattempt', ['operator' => $operator, 'attempt' => $intent->attempts + 1]);

        return $this->dispatch($payment, DB::table('payment_gateway_refunds')->where('id', $intent->id)->first(), true);
    }

    /**
     * Step 1 of the unknown-refund closure: the first operator records Midtrans' written
     * confirmation. Nothing about the refund itself changes and the slot stays held.
     */
    public function requestClosure(int $refundId, array $evidence, bool $confirm): array
    {
        if ($disabled = $this->closureDisabled()) {
            return [false, $disabled];
        }
        $operator = $this->identity->current();
        if ($operator === null) {
            return [false, 'Identitas operator dari akun sistem operasi tidak dapat dibuktikan (root, sudo/setuid, atau POSIX tidak tersedia). Tidak ada yang dicatat.'];
        }
        $intent = DB::table('payment_gateway_refunds')->where('id', $refundId)->first();
        if ($blocked = $this->closable($intent)) {
            return [false, $blocked];
        }
        $payment = DB::table('payments')->where('id', $intent->payment_id)->first();
        if ($invalid = $this->invalidEvidence($intent, $payment, $evidence)) {
            return [false, $invalid.' Tidak ada yang dicatat.'];
        }
        if ($this->openClosureRequest($intent->id)) {
            return [false, 'Sudah ada permintaan penutupan yang menunggu persetujuan untuk refund ini.'];
        }
        if ($unlisted = $this->confirmUnlisted($payment, $intent)) {
            return [false, $unlisted];
        }
        $ticket = trim($evidence['ticket']);
        if (! $confirm) {
            return [true, "DRY-RUN, belum ada yang dicatat. Permintaan penutupan refund #{$intent->id} (order {$payment->order_id}, refund_key {$intent->refund_key}, {$this->rupiah((int) $intent->amount)}) oleh {$operator['name']} (UID {$operator['uid']}), tiket Midtrans {$ticket}. Slot refund tetap ditahan sampai operator lain menyetujui. Tambahkan --confirm untuk mencatat."];
        }
        $id = DB::transaction(function () use ($intent, $payment, $evidence, $operator, $ticket) {
            DB::table('users')->where('id', $payment->user_id)->update(['id' => DB::raw('id')]);
            if (DB::table('payment_gateway_refunds')->where('id', $intent->id)->value('status') !== 'unknown' || $this->openClosureRequest($intent->id)) {
                return null;
            }

            return DB::table('payment_gateway_refund_closures')->insertGetId([
                'kind' => 'request', 'gateway_refund_id' => $intent->id, 'payment_id' => $payment->id, 'order_id' => $payment->order_id,
                'refund_key' => $intent->refund_key, 'amount' => $intent->amount, 'environment' => $intent->environment, 'midtrans_ticket' => $ticket,
                'midtrans_confirmed_on' => $evidence['confirmed_on'], 'reason' => trim($evidence['reason']),
                'operator_uid' => $operator['uid'], 'operator_name' => $operator['name'], 'created_at' => now(),
            ]);
        }, 3);
        if ($id === null) {
            return [false, 'Status refund atau permintaan penutupan berubah bersamaan. Tidak ada yang dicatat.'];
        }
        $this->audit($intent, 'closure_requested', ['closure_id' => $id, 'operator_uid' => $operator['uid'], 'operator' => $operator['name']]);

        return [true, "Permintaan penutupan #{$id} dicatat. Slot refund masih ditahan. Operator lain (akun OS berbeda) menyetujui dengan: php artisan payments:gateway-refund-close-approve {$id} --ticket={$ticket} --confirm"];
    }

    /**
     * Step 2: a different OS account approves; only then is the refund closed and its slot released,
     * atomically and only if nothing changed since the request.
     */
    public function approveClosure(int $requestId, mixed $ticket, bool $confirm): array
    {
        if ($disabled = $this->closureDisabled()) {
            return [false, $disabled];
        }
        $operator = $this->identity->current();
        if ($operator === null) {
            return [false, 'Identitas operator dari akun sistem operasi tidak dapat dibuktikan (root, sudo/setuid, atau POSIX tidak tersedia). Tidak ada perubahan.'];
        }
        $request = DB::table('payment_gateway_refund_closures')->where(['id' => $requestId, 'kind' => 'request'])->first();
        if (! $request) {
            return [false, 'Permintaan penutupan tidak ditemukan.'];
        }
        if (DB::table('payment_gateway_refund_closures')->where('request_id', $request->id)->exists()) {
            return [false, 'Permintaan penutupan ini sudah disetujui sebelumnya.'];
        }
        $ttl = (int) config('midtrans.refund.closure_request_ttl_hours', 72);
        if (Carbon::parse($request->created_at)->addHours($ttl)->isPast()) {
            return [false, "Permintaan penutupan lebih dari {$ttl} jam yang lalu dan kedaluwarsa. Ajukan permintaan baru."];
        }
        if ((int) $request->operator_uid === $operator['uid']) {
            return [false, 'Operator yang mengajukan tidak boleh menyetujui permintaannya sendiri. Persetujuan harus dari akun OS lain.'];
        }
        if (! is_string($ticket) || ! hash_equals($request->midtrans_ticket, trim($ticket))) {
            return [false, 'Opsi --ticket harus sama dengan referensi tiket Midtrans pada permintaan (periksa bukti aslinya). Tidak ada perubahan.'];
        }
        $intent = DB::table('payment_gateway_refunds')->where('id', $request->gateway_refund_id)->first();
        if ($blocked = $this->closable($intent)) {
            return [false, $blocked];
        }
        $payment = DB::table('payments')->where('id', $intent->payment_id)->first();
        if ([(int) $intent->payment_id, $payment->order_id, $intent->refund_key, (int) $intent->amount, $intent->environment]
            !== [(int) $request->payment_id, $request->order_id, $request->refund_key, (int) $request->amount, $request->environment]) {
            return [false, 'Data refund tidak lagi sesuai dengan konfirmasi Midtrans pada permintaan. Tidak ada perubahan.'];
        }
        if ($unlisted = $this->confirmUnlisted($payment, $intent)) {
            return [false, $unlisted];
        }
        if (! $confirm) {
            return [true, "DRY-RUN, belum ada perubahan. {$operator['name']} (UID {$operator['uid']}) akan menyetujui permintaan #{$request->id} dari {$request->operator_name} (UID {$request->operator_uid}): refund #{$intent->id} (order {$request->order_id}, refund_key {$request->refund_key}, {$this->rupiah((int) $request->amount)}) ditutup sebagai ".self::CLOSED.' dan slot refund pembayaran dilepas. Tambahkan --confirm untuk menjalankan.'];
        }
        try {
            DB::transaction(function () use ($request, $intent, $payment, $operator) {
                DB::table('users')->where('id', $payment->user_id)->update(['id' => DB::raw('id')]);
                $now = now();
                $closed = DB::table('payment_gateway_refunds')->where(['id' => $intent->id, 'status' => 'unknown', 'active_slot' => $intent->payment_id,
                    'refund_key' => $request->refund_key, 'amount' => $request->amount])
                    ->update(['status' => self::CLOSED, 'active_slot' => null, 'last_checked_at' => $now, 'updated_at' => $now]);
                if ($closed !== 1) {
                    throw new RuntimeException('closure lost a race');
                }
                DB::table('payment_gateway_refund_closures')->insert([
                    'kind' => 'approval', 'gateway_refund_id' => $intent->id, 'request_id' => $request->id, 'payment_id' => $request->payment_id,
                    'order_id' => $request->order_id, 'refund_key' => $request->refund_key, 'amount' => $request->amount, 'environment' => $request->environment,
                    'midtrans_ticket' => $request->midtrans_ticket, 'midtrans_confirmed_on' => $request->midtrans_confirmed_on, 'reason' => $request->reason,
                    'operator_uid' => $operator['uid'], 'operator_name' => $operator['name'], 'closed_at' => $now, 'created_at' => $now,
                ]);
            }, 1);
        } catch (UniqueConstraintViolationException|RuntimeException $exception) {
            if (SqliteContention::isBusy($exception)) {
                throw $exception;
            }

            return [false, 'Refund atau permintaan berubah bersamaan; transaksi dibatalkan dan slot refund tetap ditahan. Tidak ada perubahan.'];
        }
        $this->audit($intent, self::CLOSED, ['closure_id' => $request->id, 'operator_uid' => $operator['uid'], 'operator' => $operator['name'],
            'requested_by_uid' => (int) $request->operator_uid]);

        return [true, "Refund #{$intent->id} ditutup sebagai ".self::CLOSED.' (dikonfirmasi Midtrans tidak diproses). Slot refund pembayaran dilepas, tetapi nominalnya tetap dicadangkan dalam batas refund; refund berikutnya wajib memakai refund_key baru.'];
    }

    /**
     * Two OS accounts are not proof of two people (sudo/su/root can act as any account) and anyone who
     * can write the SQLite file can bypass the flow, so closures are development-only for now.
     */
    private function closureDisabled(): ?string
    {
        return app()->environment('production') || config('midtrans.environment') === 'production'
            ? 'Penutupan refund unknown dinonaktifkan untuk Production: pemisahan kewenangan belum dapat dibuktikan. Refund tetap terbuka; lanjutkan --reconcile dan eskalasi ke Midtrans. Tidak ada perubahan.'
            : null;
    }

    private function closable(?object $intent): ?string
    {
        if (! $intent) {
            return 'Refund tidak ditemukan.';
        }
        if ($this->adjustments->refundAnomalyOpen($intent->payment_id)) {
            return 'Pembayaran ini memiliki anomali kelebihan refund (refund_excess). Penutupan diblokir. Tidak ada perubahan.';
        }
        if ($intent->status !== 'unknown') {
            return "Hanya refund berstatus unknown yang dapat ditutup; status saat ini {$intent->status}."
                .($intent->status === 'pending' ? ' Jalankan --reconcile; percobaan yang hasilnya tidak tercatat menjadi unknown setelah '.config('midtrans.refund.pending_stale_minutes', 30).' menit.' : '');
        }
        $deadline = $this->reattemptDeadline($intent);
        if (now()->lessThan($deadline)) {
            return "Batas reattempt belum lewat ({$deadline->toDateTimeString()} UTC). Gunakan --reconcile atau --reattempt dengan refund_key yang sama.";
        }
        if ($intent->environment !== $this->gateway->environment() || ! $this->gateway->serverKeyReady()) {
            return 'Environment Midtrans aktif tidak sesuai dengan refund ini atau konfigurasinya belum lengkap. Tidak ada perubahan.';
        }

        return null;
    }

    private function invalidEvidence(object $intent, object $payment, array $evidence): ?string
    {
        $ticket = is_string($evidence['ticket'] ?? null) ? trim($evidence['ticket']) : '';
        $date = is_string($evidence['confirmed_on'] ?? null) ? $evidence['confirmed_on'] : '';
        $parsed = preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        $reason = is_string($evidence['reason'] ?? null) ? trim($evidence['reason']) : '';

        return match (true) {
            ! preg_match('/\A[A-Za-z0-9#._\/-]{3,100}\z/', $ticket) => 'Opsi --ticket wajib berisi referensi tiket/bukti Midtrans (3-100 karakter: huruf, angka, # . _ / -).',
            ! $parsed || $parsed->format('Y-m-d') !== $date => 'Opsi --confirmed-on wajib berupa tanggal konfirmasi Midtrans dengan format YYYY-MM-DD.',
            // Midtrans dates are local (WIB); allow the current WIB day.
            $date > now('Asia/Jakarta')->toDateString() || $date < Carbon::parse($intent->created_at)->toDateString() => 'Tanggal konfirmasi Midtrans tidak boleh di masa depan atau sebelum refund dibuat.',
            ($evidence['statement'] ?? false) !== true => 'Opsi --midtrans-confirmed-not-processed wajib: Midtrans menyatakan tertulis bahwa refund ini tidak pernah diproses.',
            ($evidence['order_id'] ?? null) !== $payment->order_id => 'Opsi --order-id harus sama dengan Order ID pada konfirmasi Midtrans dan refund ini.',
            ($evidence['refund_key'] ?? null) !== $intent->refund_key => 'Opsi --refund-key harus sama dengan refund_key pada konfirmasi Midtrans dan refund ini.',
            ($evidence['amount'] ?? null) !== (string) $intent->amount => 'Opsi --amount harus sama dengan nominal refund pada konfirmasi Midtrans dan refund ini.',
            mb_strlen($reason) < 10 || mb_strlen($reason) > 255 => 'Opsi --reason wajib diisi (10-255 karakter).',
            default => null,
        };
    }

    private function openClosureRequest(int $refundId): bool
    {
        $since = now()->subHours((int) config('midtrans.refund.closure_request_ttl_hours', 72));

        return DB::table('payment_gateway_refund_closures as requests')->where(['requests.kind' => 'request', 'requests.gateway_refund_id' => $refundId])
            ->where('requests.created_at', '>', $since)
            ->whereNotExists(fn ($query) => $query->from('payment_gateway_refund_closures as approvals')->whereColumn('approvals.request_id', 'requests.id'))
            ->exists();
    }

    /** Midtrans must still not list the refund_key; if it does, record that instead. */
    private function confirmUnlisted(object $payment, object $intent): ?string
    {
        try {
            $current = $this->gateway->currentStatus($payment);
        } catch (HttpException) {
            return 'Status transaksi Midtrans belum dapat diverifikasi. Tidak ada perubahan.';
        }
        if ($current === null) {
            return 'Transaksi tidak ditemukan di Get Status Midtrans; tidak sesuai dengan konfirmasi. Tidak ada perubahan.';
        }
        if ($this->entry($current, $intent->refund_key) !== null) {
            return 'refund_key ini tercatat di Midtrans, bertentangan dengan konfirmasi. Penutupan ditolak; status diperbarui menjadi '.$this->verify($payment, $intent, $current).'.';
        }

        return null;
    }

    private function execute(object $payment, string $environment, string $key, int $amount, string $reason, string $operator, array $listed): array
    {
        try {
            [$id, $error] = DB::transaction(function () use ($payment, $environment, $key, $amount, $reason, $operator, $listed) {
                DB::table('users')->where('id', $payment->user_id)->update(['id' => DB::raw('id')]);
                $fresh = DB::table('payments')->where('id', $payment->id)->lockForUpdate()->first();
                if ($blocked = $this->localBlock($fresh, $key)) {
                    return [null, $blocked];
                }
                $remaining = $this->adjustments->refundExposure($fresh, $listed)['remaining'];
                if ($amount > $remaining) {
                    return [null, 'Nominal melebihi sisa yang dapat direfund ('.$this->rupiah(max(0, $remaining)).'). Tidak ada refund dikirim.'];
                }
                $now = now();

                return [DB::table('payment_gateway_refunds')->insertGetId([
                    'payment_id' => $payment->id, 'refund_key' => $key, 'amount' => $amount, 'reason' => $reason, 'status' => 'pending',
                    'operator' => $operator, 'environment' => $environment, 'active_slot' => $payment->id, 'attempts' => 1, 'last_attempt_at' => $now,
                    'created_at' => $now, 'updated_at' => $now,
                ]), null];
            }, 3);
        } catch (UniqueConstraintViolationException) {
            return [false, 'Refund lain untuk pembayaran ini sedang diproses atau refund_key sudah dipakai. Tidak ada refund dikirim.'];
        }
        if ($id === null) {
            return [false, $error];
        }
        $intent = DB::table('payment_gateway_refunds')->where('id', $id)->first();
        $this->audit($intent, 'intent');

        return $this->dispatch($payment, $intent, false);
    }

    /** One POST for an intent that is "pending" and already durable. Never retries. */
    private function dispatch(object $payment, object $intent, bool $reattempt): array
    {
        try {
            $result = $this->gateway->requestRefund($payment, $intent->refund_key, (int) $intent->amount, $intent->reason);
        } catch (Throwable $exception) {
            // Whatever happened, the request may have reached Midtrans: never resend automatically.
            $result = ['outcome' => 'unknown', 'http_status' => null, 'status_code' => null];
            Log::warning('payment.gateway_refund_request_failed', ['payment_id' => $payment->id, 'refund_id' => $intent->id, 'exception' => $exception::class]);
        }
        // A rejected reattempt says nothing certain about the earlier attempt, so it stays unknown.
        $rejected = $result['outcome'] === 'rejected' && ! $reattempt;
        $after = match ($result['outcome']) {
            'accepted' => 'submitted',
            'rejected' => $reattempt ? 'unknown' : 'pending',
            default => 'unknown',
        };
        DB::table('payment_gateway_refunds')->where('id', $intent->id)->where('status', 'pending')->update([
            'status' => $after, 'http_status' => $result['http_status'], 'provider_status_code' => $result['status_code'],
            'midtrans_refund_chargeback_id' => $result['refund_chargeback_id'] ?? $intent->midtrans_refund_chargeback_id,
            'submitted_at' => $intent->submitted_at ?? now(), 'updated_at' => now(),
        ]);
        $intent = DB::table('payment_gateway_refunds')->where('id', $intent->id)->first();
        $this->audit($intent, 'request_'.$result['outcome'], ['http_status' => $result['http_status'], 'provider_status_code' => $result['status_code']]);
        try {
            $current = $this->gateway->currentStatus($payment);
        } catch (HttpException) {
            $current = null;
        }
        $status = $current === null ? $this->touch($intent, $rejected ? 'unknown' : null)
            : $this->verify($payment, $intent, $current, $rejected);
        $key = $intent->refund_key;

        return match ($status) {
            'succeeded' => [true, 'Refund diterima Midtrans dan tercatat di refunds[]. Dana belum tentu diterima pelanggan; menunggu bank_confirmed_at. Rekonsiliasi terjadwal akan memperbaruinya.'],
            'confirmed' => [true, 'Refund tercatat di Midtrans dan sudah dikonfirmasi bank (bank_confirmed_at).'],
            'rejected' => [false, "Refund ditolak Midtrans (kode {$result['status_code']}). refund_key ini tidak dipakai lagi."],
            'review' => [false, 'Nominal refund di Midtrans berbeda dengan permintaan. Refund ditahan untuk ditinjau; jangan kirim ulang.'],
            default => [false, "Hasil refund belum dapat dipastikan (status {$status}). Jangan membuat refund_key baru; jalankan --reconcile, lalu bila perlu --reattempt --refund-key={$key}."],
        };
    }

    private function localBlock(object $payment, ?string $key): ?string
    {
        if ($payment->status !== 'paid') {
            return 'Hanya pembayaran berstatus paid yang dapat direfund melalui Midtrans. Tidak ada refund dikirim.';
        }
        if ($this->adjustments->refundAnomalyOpen($payment->id)) {
            return 'Pembayaran ini memiliki anomali kelebihan refund (refund_excess). Refund baru diblokir sampai ditinjau. Tidak ada refund dikirim.';
        }
        if ($key !== null) {
            $existing = DB::table('payment_gateway_refunds')->where('refund_key', $key)->first();
            if ($existing) {
                return "refund_key ini sudah tercatat (status {$existing->status}). Jangan kirim ulang; gunakan --reconcile.";
            }
            if (DB::table('payment_adjustments')->where('payment_id', $payment->id)->where(fn ($query) => $query
                ->where(['source' => 'manual', 'reference' => $key])
                ->orWhere(fn ($query) => $query->where(['source' => 'midtrans', 'reference' => 'partial_refund:key:'.hash('sha256', $key)])))->exists()) {
                return 'refund_key ini sudah dipakai sebagai referensi refund lain. Gunakan refund_key baru.';
            }
        }
        $open = DB::table('payment_gateway_refunds')->where('payment_id', $payment->id)->whereIn('status', self::UNRESOLVED)->first();

        return $open ? "Masih ada refund Midtrans yang belum selesai (refund_key {$open->refund_key}, status {$open->status}). Jalankan --reconcile (dan bila perlu --reattempt dengan refund_key yang sama); jangan membuat refund_key baru." : null;
    }

    private function inspect(object $payment, array $current, int $amount, ?string $key, ?string $exclude = null): array
    {
        $status = $current['transaction_status'] ?? null;
        if (! in_array($status, ['settlement', 'partial_refund'], true)) {
            return ['Status transaksi Midtrans ('.(is_string($status) ? $status : 'tidak dikenal').') belum settlement. Tidak ada refund dikirim.', null];
        }
        if (($current['fraud_status'] ?? 'accept') !== 'accept') {
            return ['Status fraud transaksi bukan accept. Tidak ada refund dikirim.', null];
        }
        $type = $current['payment_type'] ?? null;
        if (! is_string($type) || ! in_array($type, (array) config('midtrans.refund.payment_types'), true)) {
            return ['Metode pembayaran '.(is_string($type) ? $type : 'tidak dikenal').' tidak didukung Refund API. Gunakan refund manual (payments:refund).', null];
        }
        $refunds = $current['refunds'] ?? [];
        if (! is_array($refunds) || ! array_is_list($refunds)) {
            return ['Rincian refunds[] dari Midtrans tidak valid. Tinjau manual; tidak ada refund dikirim.', null];
        }
        $sum = 0;
        $listed = [];
        foreach ($refunds as $refund) {
            $value = is_array($refund) ? $this->rupiahValue($refund['refund_amount'] ?? null) : null;
            if ($value === null || ! is_string($refund['refund_key'] ?? null)) {
                return ['Rincian refunds[] dari Midtrans tidak lengkap. Tinjau manual; tidak ada refund dikirim.', null];
            }
            if ($key !== null && $refund['refund_key'] === $key) {
                return ['refund_key ini sudah ada di Midtrans. Gunakan --reconcile atau refund_key baru.', null];
            }
            $sum += $value;
            $listed[$refund['refund_key']] = $value;
        }
        $cumulative = array_key_exists('refund_amount', $current) ? $this->rupiahValue($current['refund_amount']) : 0;
        if ($cumulative !== $sum) {
            return ['refund_amount kumulatif Midtrans tidak sama dengan jumlah refunds[]. Tinjau manual; tidak ada refund dikirim.', null];
        }
        $exposure = $this->adjustments->refundExposure($payment, $listed, $exclude);
        $remaining = $exposure['remaining'];
        if ($amount > $remaining) {
            return ['Nominal melebihi sisa yang dapat direfund ('.$this->rupiah(max(0, $remaining)).($exposure['reserved'] > 0
                ? '; '.$this->rupiah($exposure['reserved']).' dicadangkan untuk refund Midtrans yang belum pasti atau ditutup administratif' : '').'). Tidak ada refund dikirim.', null];
        }
        if (in_array($type, (array) config('midtrans.refund.full_only'), true) && ($exposure['total'] > 0 || $amount !== (int) $payment->amount)) {
            return ["Metode {$type} hanya mendukung refund penuh tanpa refund sebelumnya.", null];
        }

        return [null, ['payment_type' => $type, 'gateway' => $exposure['midtrans'], 'manual' => $exposure['manual'], 'reserved' => $exposure['reserved'],
            'remaining' => $remaining, 'full' => $amount === $remaining && $exposure['reserved'] === 0, 'listed' => $listed]];
    }

    private function verify(object $payment, object $intent, array $current, bool $rejected = false): string
    {
        $entry = $this->entry($current, $intent->refund_key);
        if ($entry === null) {
            return $this->touch($intent, $rejected ? 'rejected' : null);
        }
        $amount = $this->rupiahValue($entry['refund_amount'] ?? null);
        $confirmed = is_string($entry['bank_confirmed_at'] ?? null) && trim($entry['bank_confirmed_at']) !== '';
        $text = fn (string $field, int $limit) => is_scalar($entry[$field] ?? null) ? mb_substr((string) $entry[$field], 0, $limit) : null;
        // Compare-and-set against the status this process read; on a lost race re-read and recompute.
        for ($try = 0; $try < 3 && $intent; $try++) {
            $status = match (true) {
                $amount !== (int) $intent->amount => 'review',
                $confirmed || $intent->status === 'confirmed' => 'confirmed',
                default => 'succeeded',
            };
            $changed = DB::table('payment_gateway_refunds')->where('id', $intent->id)->where('status', $intent->status)->update([
                // review keeps whatever slot it had; listed refunds release it.
                'status' => $status, 'active_slot' => $status === 'review' ? $intent->active_slot : null,
                'midtrans_refund_chargeback_id' => $text('refund_chargeback_id', 100) ?? $intent->midtrans_refund_chargeback_id,
                'midtrans_refund_chargeback_uuid' => $text('refund_chargeback_uuid', 100) ?? $intent->midtrans_refund_chargeback_uuid,
                'refund_method' => $text('refund_method', 30) ?? $intent->refund_method, 'gateway_created_at' => $text('created_at', 32) ?? $intent->gateway_created_at,
                'bank_confirmed_at' => $confirmed ? $text('bank_confirmed_at', 32) : $intent->bank_confirmed_at,
                'verified_at' => $intent->verified_at ?? now(), 'last_checked_at' => now(), 'updated_at' => now(),
            ]);
            if ($changed) {
                if ($status !== $intent->status) {
                    $this->audit($intent, $status, ['refund_method' => $text('refund_method', 30), 'listed_amount' => $amount]);
                }
                if (in_array($intent->status, ['rejected', self::CLOSED], true)) {
                    // The closure/rejection history stays; Midtrans moved money after all.
                    PaymentAlert::raise("payment.gateway_refund_{$intent->status}_but_listed", ['payment_id' => $intent->payment_id,
                        'refund_id' => $intent->id, 'amount' => (int) $intent->amount, 'listed_amount' => $amount, 'status' => $status]);
                }
                break;
            }
            $intent = DB::table('payment_gateway_refunds')->where('id', $intent->id)->first();
            $status = $intent->status;
        }
        try {
            // Same A3/A5/A6/R1/R2 rules as the webhook and payments:reconcile; idempotent per refund_key.
            $this->gateway->applyGatewayStatus($payment->id, $current);
        } catch (HttpException $exception) {
            // The refund row and its event stay the evidence and still count in the refund ledger.
            $this->audit($intent, 'adjustment_unverified', ['http_status' => $exception->getStatusCode()]);
            Log::warning('payment.gateway_refund_adjustment_unverified', ['payment_id' => $payment->id, 'refund_id' => $intent->id, 'status' => $exception->getStatusCode()]);
        }
        $this->adjustments->flagRefundExcess(DB::table('payments')->where('id', $payment->id)->first(), $this->adjustments->listedRefunds($current), 'gateway_refund_reconcile');

        return $status;
    }

    /**
     * Midtrans does not (yet) list the refund_key. Only the request that just got a definitive
     * rejection may change the status; everyone else just records the check, so a stale
     * Get Status can never move a listed refund back or free its slot.
     */
    private function touch(object $intent, ?string $status = null): string
    {
        $query = DB::table('payment_gateway_refunds')->where('id', $intent->id);
        if ($status !== null && $status !== $intent->status
            && (clone $query)->where('status', 'pending')->update(['status' => $status, 'last_checked_at' => now(), 'updated_at' => now()]
                + ($status === 'rejected' ? ['active_slot' => null] : []))) {
            $this->audit($intent, $status);

            return $status;
        }
        // An attempt whose outcome was never recorded (e.g. the process died) is indeterminate, not failed.
        $stale = now()->subMinutes((int) config('midtrans.refund.pending_stale_minutes', 30))->toDateTimeString();
        if ($status === null && $intent->status === 'pending' && $intent->last_attempt_at !== null && $intent->last_attempt_at <= $stale
            && (clone $query)->where('status', 'pending')->where('last_attempt_at', $intent->last_attempt_at)
                ->update(['status' => 'unknown', 'last_checked_at' => now(), 'updated_at' => now()])) {
            $this->audit($intent, 'unknown');

            return 'unknown';
        }
        $query->update(['last_checked_at' => now()]);

        return (string) DB::table('payment_gateway_refunds')->where('id', $intent->id)->value('status');
    }

    private function entry(array $current, string $key): ?array
    {
        $refunds = $current['refunds'] ?? [];
        foreach (is_array($refunds) ? $refunds : [] as $refund) {
            if (is_array($refund) && ($refund['refund_key'] ?? null) === $key) {
                return $refund;
            }
        }

        return null;
    }

    private function audit(object $intent, string $event, array $context = []): void
    {
        // refund_key and reason stay in the database; the log carries only bounded identifiers.
        Log::info('payment.gateway_refund', ['payment_id' => $intent->payment_id, 'refund_id' => $intent->id, 'event' => $event,
            'environment' => $intent->environment, 'amount' => (int) $intent->amount, 'operator' => $context['operator'] ?? $intent->operator] + $context);
        if (Schema::hasTable('payment_gateway_refund_events')) {
            DB::table('payment_gateway_refund_events')->insert([
                'gateway_refund_id' => $intent->id, 'payment_id' => $intent->payment_id, 'event' => mb_substr($event, 0, 40),
                'from_status' => $intent->status, 'to_status' => in_array($event, self::STATUSES, true) ? $event : null,
                'listed_amount' => is_int($context['listed_amount'] ?? null) ? $context['listed_amount'] : null,
                'operator' => isset($context['operator']) ? mb_substr((string) $context['operator'], 0, 100) : null, 'created_at' => now(),
            ]);
        }
    }

    private function rupiahValue(mixed $value): ?int
    {
        return (is_string($value) || is_int($value)) && preg_match('/\A[0-9]{1,9}(?:\.00)?\z/', (string) $value) ? (int) $value : null;
    }

    private function rupiah(int $value): string
    {
        return 'Rp'.number_format($value, 0, ',', '.');
    }
}
