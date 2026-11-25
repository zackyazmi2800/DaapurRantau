<?php

namespace Tests\Feature;

use App\Mail\PaymentAlertMail;
use App\Models\User;
use App\Services\GatewayRefundService;
use App\Services\MidtransSandboxService;
use App\Services\SubscriptionService;
use App\Support\OperatorIdentity;
use App\Support\PaymentAlert;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GatewayRefundTest extends TestCase
{
    private array $status = [];

    /** accept | reject | timeout | ghost (timeout, but Midtrans recorded it) */
    private string $mode = 'accept';

    private int $posts = 0;

    private int $gets = 0;

    /** @var list<array> Refund API request bodies */
    private array $bodies = [];

    /** Runs once inside the next Get Status, to simulate a concurrent process. */
    private ?\Closure $during = null;

    private string $api = 'https://api.sandbox.midtrans.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate')->assertSuccessful();
        Http::preventStrayRequests();
        config(['midtrans.environment' => 'sandbox', 'midtrans.webhook_enabled' => false, 'logging.default' => 'null',
            'midtrans.sandbox.server_key' => 'SB-Mid-server-fixture', 'midtrans.sandbox.client_key' => 'SB-Mid-client-fixture',
            'midtrans.sandbox.signature_secret_key' => 'fixture-signature']);
        $this->travelTo(Carbon::parse('2026-01-01 00:00:00 UTC'));
        Http::fake(function (Request $request) {
            $order = explode('/', $request->url())[4];
            $this->assertStringStartsWith($this->api.'/v2/', $request->url());
            if ($request->method() === 'GET') {
                $this->gets++;
                $state = $this->status[$order];
                if ($hook = $this->during) {
                    $this->during = null;
                    $hook();
                }

                return Http::response($state);
            }
            $this->posts++;
            $this->bodies[] = $request->data();
            $this->assertSame("{$this->api}/v2/{$order}/refund", $request->url());
            if ($this->mode === 'reject') {
                return Http::response(['status_code' => '412', 'status_message' => 'Merchant cannot modify the status of the transaction']);
            }
            if ($this->mode === 'timeout') {
                return Http::failedConnection()($request);
            }
            $this->record($order, $request['refund_key'], $request['amount'], $request['reason']);
            if ($this->mode === 'ghost') {
                return Http::failedConnection()($request);
            }

            return Http::response(['status_code' => '200', 'order_id' => $order, 'refund_key' => $request['refund_key'], 'refund_chargeback_id' => 7,
                'refund_amount' => $this->status[$order]['refund_amount'], 'transaction_status' => $this->status[$order]['transaction_status']]);
        });
    }

    private function record(string $order, string $key, int $amount, string $reason): void
    {
        $state = &$this->status[$order];
        $state['refunds'][] = ['refund_chargeback_id' => count($state['refunds'] ?? []) + 1, 'refund_chargeback_uuid' => (string) Str::uuid(),
            'refund_amount' => $amount.'.00', 'reason' => $reason, 'refund_key' => $key, 'refund_method' => 'offline', 'created_at' => '2026-01-01 07:00:00'];
        $total = array_sum(array_map(fn ($refund) => (int) $refund['refund_amount'], $state['refunds']));
        $state['refund_amount'] = $total.'.00';
        $state['transaction_status'] = $total >= (int) $state['gross_amount'] ? 'refund' : 'partial_refund';
    }

    private function paid(string $type = 'credit_card'): object
    {
        $user = User::factory()->create();
        $order = (string) Str::uuid();
        $id = DB::table('payments')->insertGetId(['order_id' => $order, 'user_id' => $user->id, 'plan_id' => 1, 'amount' => 5000,
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->status[$order] = ['order_id' => $order, 'status_code' => '200', 'gross_amount' => '5000.00', 'currency' => 'IDR',
            'transaction_status' => 'settlement', 'fraud_status' => 'accept', 'payment_type' => $type, 'transaction_id' => (string) Str::uuid()];
        $this->assertSame('paid', app(MidtransSandboxService::class)->sync($user->id, $order));

        return DB::table('payments')->find($id);
    }

    private function refund(object $payment, array $options = [])
    {
        return $this->artisan('payments:gateway-refund', $options + ['payment' => $payment->id, '--amount' => '2000', '--reason' => 'Customer refund', '--operator' => 'ops-test']);
    }

    public function test_dry_run_is_default_and_confirmed_refund_is_verified_by_refund_key(): void
    {
        $payment = $this->paid();
        $this->refund($payment)->expectsOutputToContain('sisa Rp5.000, refund ini Rp2.000')->assertSuccessful();
        $this->refund($payment, ['--confirm' => true])->expectsOutputToContain('--refund-key')->assertFailed();
        $this->assertSame(0, $this->posts);
        $this->assertDatabaseCount('payment_gateway_refunds', 0);

        $this->refund($payment, ['--confirm' => true, '--refund-key' => 'dr-refund-0001'])->expectsOutputToContain('menunggu bank_confirmed_at')->assertSuccessful();
        $this->assertSame(1, $this->posts);
        $this->assertDatabaseHas('payment_gateway_refunds', ['payment_id' => $payment->id, 'refund_key' => 'dr-refund-0001', 'amount' => 2000,
            'status' => 'succeeded', 'operator' => 'ops-test', 'environment' => 'sandbox', 'active_slot' => null, 'refund_method' => 'offline', 'midtrans_refund_chargeback_id' => '1']);
        $this->assertDatabaseHas('payment_adjustments', ['payment_id' => $payment->id, 'source' => 'midtrans', 'kind' => 'partial_refund',
            'amount' => 2000, 'reference' => 'partial_refund:key:'.hash('sha256', 'dr-refund-0001')]);
        $this->assertSame('paid', DB::table('payments')->where('id', $payment->id)->value('status'));
        $this->assertNotNull(app(SubscriptionService::class)->active($payment->user_id));

        // Replay with the same key never reaches the Refund API.
        $this->refund($payment, ['--confirm' => true, '--refund-key' => 'dr-refund-0001'])->expectsOutputToContain('sudah tercatat')->assertFailed();
        // A5: the remaining amount includes earlier refunds.
        $this->refund($payment, ['--amount' => '3001'])->expectsOutputToContain('sisa yang dapat direfund (Rp3.000)')->assertFailed();
        $this->assertSame(1, $this->posts);

        $order = $payment->order_id;
        $this->status[$order]['refunds'][0]['bank_confirmed_at'] = '2026-01-02 08:00:00';
        $this->refund($payment, ['--reconcile' => true])->expectsOutputToContain('succeeded -> confirmed')->assertSuccessful();
        $this->assertDatabaseHas('payment_gateway_refunds', ['refund_key' => 'dr-refund-0001', 'status' => 'confirmed', 'bank_confirmed_at' => '2026-01-02 08:00:00']);
        $this->assertSame(1, DB::table('payment_adjustments')->where('payment_id', $payment->id)->count());

        $this->refund($payment, ['--amount' => '3000', '--confirm' => true, '--refund-key' => 'dr-refund-0002'])->assertSuccessful();
        $this->assertSame('refunded', DB::table('payments')->where('id', $payment->id)->value('status'));
        $this->assertNull(app(SubscriptionService::class)->active($payment->user_id));
        $this->refund($payment, ['--amount' => '1'])->expectsOutputToContain('berstatus paid')->assertFailed();
        $this->assertSame(2, $this->posts);
    }

    public function test_timeout_is_unknown_blocks_new_keys_and_is_reconciled_without_resending(): void
    {
        $payment = $this->paid();
        $this->mode = 'ghost';
        $this->refund($payment, ['--confirm' => true, '--refund-key' => 'dr-refund-timeout'])->expectsOutputToContain('tercatat di refunds[]')->assertSuccessful();
        // The follow-up Get Status already shows the refund_key.
        $this->assertDatabaseHas('payment_gateway_refunds', ['refund_key' => 'dr-refund-timeout', 'status' => 'succeeded', 'http_status' => null]);

        $other = $this->paid();
        $this->mode = 'timeout';
        $this->refund($other, ['--confirm' => true, '--refund-key' => 'dr-refund-lost-01'])->expectsOutputToContain('Jangan membuat refund_key baru')->assertFailed();
        $this->refund($other, ['--reconcile' => true])->expectsOutputToContain('unknown -> unknown')->assertSuccessful();
        $this->assertDatabaseHas('payment_gateway_refunds', ['refund_key' => 'dr-refund-lost-01', 'status' => 'unknown', 'active_slot' => $other->id]);
        $this->refund($other, ['--confirm' => true, '--refund-key' => 'dr-refund-new-01'])->expectsOutputToContain('belum selesai')->assertFailed();
        $manual = fn (string $amount) => $this->artisan('payments:refund', ['payment' => $other->id, '--amount' => $amount, '--reason' => 'Transfer',
            '--reference' => 'TRF-LOST-1', '--confirm' => true]);
        $manual('1000')->expectsOutputToContain('hasilnya belum pasti')->assertFailed();

        // Absence from Get Status never proves the request will not be processed: the slot stays held.
        $this->travel(8)->days();
        $this->refund($other, ['--reconcile' => true])->expectsOutputToContain('unknown -> unknown')->assertSuccessful();
        $this->refund($other, ['--confirm' => true, '--refund-key' => 'dr-refund-new-02'])->expectsOutputToContain('belum selesai')->assertFailed();
        $manual('1000')->expectsOutputToContain('hasilnya belum pasti')->assertFailed();
        $this->assertDatabaseCount('payment_adjustments', 1);
        $this->assertSame(2, $this->posts);

        // Midtrans lists it late: reconciliation resolves it without resending.
        $this->record($other->order_id, 'dr-refund-lost-01', 2000, 'Customer refund');
        $this->refund($other, ['--reconcile' => true])->expectsOutputToContain('unknown -> succeeded')->assertSuccessful();
        $this->assertDatabaseHas('payment_gateway_refunds', ['refund_key' => 'dr-refund-lost-01', 'status' => 'succeeded', 'active_slot' => null]);
        $manual('3001')->expectsOutputToContain('Sisa yang dapat direfund: Rp3.000')->assertFailed();
        $this->assertSame(2, $this->posts);
    }

    public function test_manual_refund_limit_counts_gateway_refunds_even_without_an_adjustment_row(): void
    {
        $payment = $this->paid();
        DB::table('payment_gateway_refunds')->insert(['payment_id' => $payment->id, 'refund_key' => 'dr-refund-done-01', 'amount' => 4000, 'reason' => 'x',
            'status' => 'confirmed', 'operator' => 'x', 'environment' => 'sandbox', 'active_slot' => null, 'created_at' => now(), 'updated_at' => now()]);
        $this->artisan('payments:refund', ['payment' => $payment->id, '--amount' => '1001', '--reason' => 'Transfer', '--reference' => 'TRF-9'])
            ->expectsOutputToContain('Sisa yang dapat direfund: Rp1.000')->assertFailed();
        $this->artisan('payments:refund', ['payment' => $payment->id, '--amount' => '1000', '--reason' => 'Transfer', '--reference' => 'TRF-9'])
            ->expectsOutputToContain('DRY-RUN')->assertSuccessful();
        $this->refund($payment, ['--amount' => '1001'])->expectsOutputToContain('(Rp1.000)')->assertFailed();
    }

    public function test_webhook_has_a_dedicated_rate_limit_that_does_not_touch_checkout(): void
    {
        $payment = $this->paid();
        for ($i = 0; $i < 120; $i++) {
            $this->postJson('/api/midtrans/notification', ['order_id' => $payment->order_id])->assertNotFound();
        }
        $this->postJson('/api/midtrans/notification', ['order_id' => $payment->order_id])->assertTooManyRequests();
        $this->actingAs(User::factory()->create())->postJson('/langganan/checkout', ['plan' => 'invalid'])->assertUnprocessable();
    }

    public function test_rejections_unsupported_methods_and_one_in_flight_refund_per_payment(): void
    {
        $payment = $this->paid();
        $this->mode = 'reject';
        $this->refund($payment, ['--confirm' => true, '--refund-key' => 'dr-refund-reject'])->expectsOutputToContain('ditolak Midtrans (kode 412)')->assertFailed();
        $this->assertDatabaseHas('payment_gateway_refunds', ['refund_key' => 'dr-refund-reject', 'status' => 'rejected', 'active_slot' => null]);

        $transfer = $this->paid('bank_transfer');
        $this->refund($transfer, ['--confirm' => true, '--refund-key' => 'dr-refund-bank-01'])->expectsOutputToContain('payments:refund')->assertFailed();
        $wallet = $this->paid('shopeepay');
        $this->refund($wallet)->expectsOutputToContain('hanya mendukung refund penuh')->assertFailed();
        $this->assertSame(1, $this->posts);

        DB::table('payment_gateway_refunds')->insert(['payment_id' => $payment->id, 'refund_key' => 'dr-refund-held-01', 'amount' => 1000, 'reason' => 'x',
            'status' => 'pending', 'operator' => 'x', 'environment' => 'sandbox', 'active_slot' => $payment->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('payment_gateway_refunds')->insert(['payment_id' => $payment->id, 'refund_key' => 'dr-refund-held-02', 'amount' => 1000, 'reason' => 'x',
            'status' => 'pending', 'operator' => 'x', 'environment' => 'sandbox', 'active_slot' => $payment->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_production_requires_its_own_credentials_and_explicit_confirmation(): void
    {
        $payment = $this->paid();
        config(['midtrans.environment' => 'production']);
        $this->refund($payment)->expectsOutputToContain('belum lengkap')->assertFailed();
        // A Sandbox key in the Production slot is never accepted.
        config(['midtrans.production.server_key' => 'SB-Mid-server-fixture']);
        $this->refund($payment)->expectsOutputToContain('belum lengkap')->assertFailed();
        config(['midtrans.environment' => null]);
        $this->refund($payment)->expectsOutputToContain('belum lengkap')->assertFailed();
        Http::assertSentCount(1);

        config(['midtrans.environment' => 'production', 'midtrans.production.server_key' => 'Mid-server-fixture']);
        $this->api = 'https://api.midtrans.com';
        $this->refund($payment, ['--confirm' => true, '--refund-key' => 'dr-refund-prod-01'])->expectsOutputToContain('--confirm-production')->assertFailed();
        $this->refund($payment, ['--confirm' => true, '--refund-key' => 'dr-refund-prod-01', '--confirm-production' => 'wrong'])->assertFailed();
        Http::assertSentCount(1);
        $this->refund($payment, ['--confirm' => true, '--refund-key' => 'dr-refund-prod-01', '--confirm-production' => $payment->order_id])->assertSuccessful();
        $this->assertDatabaseHas('payment_gateway_refunds', ['refund_key' => 'dr-refund-prod-01', 'environment' => 'production', 'status' => 'succeeded']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sandbox') && $request->method() === 'POST');
    }

    public function test_webhook_is_disabled_by_default_and_uses_the_active_signature_secret(): void
    {
        $payment = $this->paid();
        $notice = ['order_id' => $payment->order_id, 'status_code' => '200', 'gross_amount' => '5000.00', 'transaction_status' => 'settlement'];
        $sign = fn (string $secret) => $notice + ['signature_key' => hash('sha512', $payment->order_id.'2005000.00'.$secret)];
        $this->postJson('/api/midtrans/notification', $sign('fixture-signature'))->assertNotFound();

        config(['midtrans.webhook_enabled' => true]);
        $this->postJson('/api/midtrans/notification', $sign('SB-Mid-server-fixture'))->assertForbidden();
        $this->postJson('/api/midtrans/notification', $sign('fixture-signature'))->assertOk();

        config(['midtrans.environment' => 'production', 'midtrans.production.signature_secret_key' => 'prod-signature']);
        $this->postJson('/api/midtrans/notification', $sign('fixture-signature'))->assertForbidden();
        Http::assertSentCount(1);
    }

    public function test_unknown_refund_is_reattempted_only_with_the_same_key_amount_and_reason(): void
    {
        $payment = $this->paid();
        $this->mode = 'timeout';
        $this->refund($payment, ['--confirm' => true, '--refund-key' => 'dr-refund-retry-01'])->assertFailed();
        $this->assertDatabaseHas('payment_gateway_refunds', ['refund_key' => 'dr-refund-retry-01', 'status' => 'unknown', 'attempts' => 1]);
        $reattempt = fn (array $options = []) => $this->artisan('payments:gateway-refund', $options + ['payment' => $payment->id, '--reattempt' => true,
            '--refund-key' => 'dr-refund-retry-01', '--operator' => 'ops-test']);

        $reattempt(['--confirm' => true])->expectsOutputToContain('kurang dari 10 menit')->assertFailed();
        $this->travel(11)->minutes();
        $reattempt()->expectsOutputToContain('percobaan ke-2')->assertSuccessful();
        $this->assertSame(1, $this->posts);

        // A definitive rejection of a reattempt says nothing about the first attempt.
        $this->mode = 'reject';
        $reattempt(['--confirm' => true])->expectsOutputToContain('Jangan membuat refund_key baru')->assertFailed();
        $this->assertDatabaseHas('payment_gateway_refunds', ['refund_key' => 'dr-refund-retry-01', 'status' => 'unknown', 'attempts' => 2,
            'active_slot' => $payment->id, 'provider_status_code' => '412']);

        $this->travel(11)->minutes();
        $this->mode = 'accept';
        $reattempt(['--confirm' => true])->expectsOutputToContain('tercatat di refunds[]')->assertSuccessful();
        $this->assertSame(3, $this->posts);
        $this->assertSame(array_fill(0, 3, ['refund_key' => 'dr-refund-retry-01', 'amount' => 2000, 'reason' => 'Customer refund']), $this->bodies);
        $this->assertDatabaseHas('payment_gateway_refunds', ['refund_key' => 'dr-refund-retry-01', 'status' => 'succeeded', 'attempts' => 3, 'active_slot' => null]);
        $this->assertSame(1, DB::table('payment_gateway_refunds')->count());
        $this->assertSame(1, DB::table('payment_adjustments')->where('payment_id', $payment->id)->count());
        $reattempt(['--confirm' => true])->expectsOutputToContain('tidak perlu diulang')->assertFailed();
        $this->assertSame(3, $this->posts);
    }

    public function test_reattempt_resolves_listed_refunds_without_posting_and_stops_after_the_window(): void
    {
        $payment = $this->paid();
        $this->mode = 'timeout';
        $this->refund($payment, ['--confirm' => true, '--refund-key' => 'dr-refund-late-01'])->assertFailed();
        $this->record($payment->order_id, 'dr-refund-late-01', 2000, 'Customer refund');
        $this->travel(11)->minutes();
        $this->artisan('payments:gateway-refund', ['payment' => $payment->id, '--reattempt' => true, '--refund-key' => 'dr-refund-late-01', '--confirm' => true])
            ->expectsOutputToContain('sudah tercatat di Midtrans')->assertSuccessful();
        $this->assertSame(1, $this->posts);

        $other = $this->paid();
        $this->refund($other, ['--confirm' => true, '--refund-key' => 'dr-refund-late-02'])->assertFailed();
        $this->travel(144)->hours();
        $this->artisan('payments:gateway-refund', ['payment' => $other->id, '--reattempt' => true, '--refund-key' => 'dr-refund-late-02', '--confirm' => true])
            ->expectsOutputToContain('prosedur konfirmasi Midtrans')->assertFailed();
        $this->assertSame(2, $this->posts);
        $this->assertDatabaseHas('payment_gateway_refunds', ['refund_key' => 'dr-refund-late-02', 'status' => 'unknown', 'active_slot' => $other->id]);

        config(['midtrans.environment' => 'production', 'midtrans.production.server_key' => 'Mid-server-fixture']);
        $this->artisan('payments:gateway-refund', ['payment' => $other->id, '--reattempt' => true, '--refund-key' => 'dr-refund-late-02', '--confirm' => true])
            ->expectsOutputToContain('Environment Midtrans aktif tidak sesuai')->assertFailed();
        $this->assertSame(2, $this->posts);
    }

    public function test_listing_and_scheduled_reconciliation_only_read_midtrans(): void
    {
        $payment = $this->paid();
        $this->mode = 'timeout';
        $this->refund($payment, ['--confirm' => true, '--refund-key' => 'dr-refund-list-01'])->assertFailed();
        $listed = $this->paid();
        $this->mode = 'accept';
        $this->refund($listed, ['--confirm' => true, '--refund-key' => 'dr-refund-list-02'])->assertSuccessful();

        $this->artisan('payments:gateway-refunds')->expectsOutputToContain('dr-refund-list-01')->assertSuccessful();
        $this->artisan('payments:gateway-refunds', ['--status' => 'confirmed'])->expectsOutputToContain('Tidak ada refund Midtrans')->assertSuccessful();
        $this->artisan('payments:gateway-refunds', ['--status' => 'bogus'])->expectsOutputToContain('Status tidak dikenal')->assertFailed();

        $this->record($payment->order_id, 'dr-refund-list-01', 2000, 'Customer refund');
        $this->status[$listed->order_id]['refunds'][0]['bank_confirmed_at'] = '2026-01-02 08:00:00';
        $posts = $this->posts;
        $this->artisan('payments:gateway-refunds', ['--reconcile' => true])->expectsOutputToContain('confirmed=1')->assertSuccessful();
        $this->artisan('payments:gateway-refunds', ['--reconcile' => true])->expectsOutputToContain('unchanged=1')->assertSuccessful();
        $this->assertSame($posts, $this->posts);
        $this->assertSame(['succeeded', 'confirmed'], DB::table('payment_gateway_refunds')->orderBy('id')->pluck('status')->all());
        $this->assertSame(2, DB::table('payment_adjustments')->where('kind', 'partial_refund')->count());

        $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command);
        $this->assertTrue($events->contains(fn ($command) => str_contains((string) $command, 'payments:gateway-refunds --reconcile')));
    }

    public function test_concurrent_reconciliation_never_moves_a_listed_refund_back(): void
    {
        $payment = $this->paid();
        $this->mode = 'timeout';
        $this->refund($payment, ['--confirm' => true, '--refund-key' => 'dr-refund-race-01'])->assertFailed();
        $order = $payment->order_id;

        // Process A reads "unknown" and a stale Get Status; meanwhile process B verifies the listed refund.
        $this->during = function () use ($payment, $order) {
            $state = $this->status[$order];
            $this->record($order, 'dr-refund-race-01', 2000, 'Customer refund');
            $this->assertStringContainsString('unknown -> succeeded', app(GatewayRefundService::class)->reconcile($payment->id)[1]);
            $this->status[$order] = $state;
        };
        $this->refund($payment, ['--reconcile' => true])->expectsOutputToContain('unknown -> succeeded')->assertSuccessful();
        $this->assertDatabaseHas('payment_gateway_refunds', ['refund_key' => 'dr-refund-race-01', 'status' => 'succeeded', 'active_slot' => null]);

        // A stale snapshot without bank_confirmed_at never downgrades a confirmed refund.
        $this->record($order, 'dr-refund-race-01', 2000, 'Customer refund');
        $this->status[$order]['refunds'] = [$this->status[$order]['refunds'][0] + ['bank_confirmed_at' => '2026-01-02 08:00:00']];
        $this->status[$order]['refund_amount'] = '2000.00';
        $this->refund($payment, ['--reconcile' => true])->expectsOutputToContain('succeeded -> confirmed')->assertSuccessful();
        $this->status[$order]['refunds'][0] = array_diff_key($this->status[$order]['refunds'][0], ['bank_confirmed_at' => 1]);
        $this->assertSame([true, 'Tidak ada refund Midtrans yang perlu direkonsiliasi untuk pembayaran ini.'], app(GatewayRefundService::class)->reconcile($payment->id));
        $this->assertDatabaseHas('payment_gateway_refunds', ['refund_key' => 'dr-refund-race-01', 'status' => 'confirmed', 'bank_confirmed_at' => '2026-01-02 08:00:00']);
        $this->assertSame(1, DB::table('payment_adjustments')->where('payment_id', $payment->id)->count());
    }

    public function test_disabled_webhook_hides_itself_before_validation_and_proxies_must_be_explicit(): void
    {
        $this->postJson('/api/midtrans/notification', [])->assertNotFound();
        $this->postJson('/api/midtrans/notification', ['order_id' => 'x', 'signature_key' => 'y'])->assertNotFound();
        config(['midtrans.webhook_enabled' => true]);
        $this->postJson('/api/midtrans/notification', [])->assertUnprocessable();

        $parse = function (string $proxies, string $headers = '') {
            putenv("TRUSTED_PROXIES={$proxies}");
            putenv("TRUSTED_PROXY_HEADERS={$headers}");
            try {
                return require config_path('trustedproxy.php');
            } finally {
                putenv('TRUSTED_PROXIES');
                putenv('TRUSTED_PROXY_HEADERS');
            }
        };
        $this->assertSame(['proxies' => [], 'headers' => 'x-forwarded'], $parse(''));
        $this->assertSame(['10.0.0.5', '192.168.0.0/16', '2001:db8::/48'],
            $parse('*, **, 0.0.0.0/0, ::/0, 10.0.0.5, 192.168.0.0/16, 2001:db8::/48, not-an-ip, 10.0.0.0/40, REMOTE_ADDR')['proxies']);
        $this->assertSame('forwarded', $parse('', 'Forwarded')['headers']);

        // Untrusted clients cannot pick their own IP (and thus their rate-limit key) or force HTTPS.
        Route::get('/__proxy-probe', fn (\Illuminate\Http\Request $request) => [$request->ip(), $request->isSecure()]);
        $route = 'http://localhost/__proxy-probe';
        $headers = ['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Proto' => 'https'];
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->getJson($route, $headers)->assertExactJson(['10.0.0.5', false]);
        config(['trustedproxy.proxies' => ['10.0.0.5']]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])->getJson($route, $headers)->assertExactJson(['203.0.113.9', true]);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.6'])->getJson($route, $headers)->assertExactJson(['10.0.0.6', false]);
    }

    private function operator(?int $uid, string $name = 'ops'): void
    {
        $this->app->instance(OperatorIdentity::class, new class($uid, $name) extends OperatorIdentity
        {
            public function __construct(private ?int $uid, private string $name) {}

            public function current(): ?array
            {
                return $this->uid === null ? null : ['uid' => $this->uid, 'name' => $this->name];
            }
        });
    }

    /** A refund that stayed unknown past the 144 hour reattempt window. */
    private function expiredUnknown(string $key = 'dr-refund-close-01'): array
    {
        $payment = $this->paid();
        $this->mode = 'timeout';
        $this->refund($payment, ['--confirm' => true, '--refund-key' => $key])->assertFailed();
        $this->mode = 'accept';

        return [$payment, DB::table('payment_gateway_refunds')->where('refund_key', $key)->first()];
    }

    private function closeRequest(object $payment, object $refund, array $options = [])
    {
        return $this->artisan('payments:gateway-refund-close-request', $options + ['refund' => $refund->id, '--ticket' => 'MT-CASE-12345',
            '--confirmed-on' => now('Asia/Jakarta')->toDateString(), '--order-id' => $payment->order_id, '--refund-key' => $refund->refund_key,
            '--amount' => (string) $refund->amount, '--midtrans-confirmed-not-processed' => true, '--reason' => 'Midtrans menyatakan refund tidak diproses']);
    }

    public function test_unknown_refund_closure_needs_expiry_complete_evidence_and_an_os_identity(): void
    {
        $this->operator(1001, 'alice');
        [$payment, $refund] = $this->expiredUnknown();
        $this->closeRequest($payment, $refund, ['--confirm' => true])->expectsOutputToContain('Batas reattempt belum lewat')->assertFailed();
        $this->travel(145)->hours();

        $this->operator(null);
        $this->closeRequest($payment, $refund, ['--confirm' => true])->expectsOutputToContain('tidak dapat dibuktikan')->assertFailed();
        $this->operator(1001, 'alice');
        foreach ([
            ['--ticket' => ''], ['--ticket' => 'x'], ['--confirmed-on' => '2026-13-01'], ['--confirmed-on' => '2030-01-01'], ['--confirmed-on' => '2025-12-31'],
            ['--midtrans-confirmed-not-processed' => false], ['--order-id' => (string) Str::uuid()], ['--refund-key' => 'dr-refund-other-1'],
            ['--amount' => '1999'], ['--reason' => 'singkat'],
        ] as $broken) {
            $this->closeRequest($payment, $refund, $broken + ['--confirm' => true])->expectsOutputToContain('Tidak ada yang dicatat')->assertFailed();
        }
        $this->closeRequest($payment, $refund)->expectsOutputToContain('DRY-RUN')->assertSuccessful();
        $this->assertDatabaseCount('payment_gateway_refund_closures', 0);

        $listed = $this->expiredUnknown('dr-refund-close-02');
        $this->closeRequest(...$listed)->expectsOutputToContain('Batas reattempt belum lewat')->assertFailed();
        $this->assertSame(2, $this->posts);

        $done = $this->paid();
        $this->refund($done, ['--confirm' => true, '--refund-key' => 'dr-refund-close-03'])->assertSuccessful();
        $this->closeRequest($done, DB::table('payment_gateway_refunds')->where('refund_key', 'dr-refund-close-03')->first())
            ->expectsOutputToContain('status saat ini succeeded')->assertFailed();
        $this->assertDatabaseCount('payment_gateway_refund_closures', 0);
    }

    public function test_two_different_os_accounts_close_an_unknown_refund_atomically_with_an_append_only_trail(): void
    {
        [$payment, $refund] = $this->expiredUnknown();
        $this->travel(145)->hours();
        $this->operator(1001, 'alice');
        $this->closeRequest($payment, $refund, ['--confirm' => true])->expectsOutputToContain('Slot refund masih ditahan')->assertSuccessful();
        $this->closeRequest($payment, $refund, ['--confirm' => true])->expectsOutputToContain('menunggu persetujuan')->assertFailed();
        $this->assertDatabaseHas('payment_gateway_refunds', ['id' => $refund->id, 'status' => 'unknown', 'active_slot' => $payment->id]);
        $request = DB::table('payment_gateway_refund_closures')->first();
        $approve = fn (array $options = []) => $this->artisan('payments:gateway-refund-close-approve', $options + ['request' => $request->id, '--ticket' => 'MT-CASE-12345']);

        $approve(['--confirm' => true])->expectsOutputToContain('tidak boleh menyetujui permintaannya sendiri')->assertFailed();
        $this->operator(1001, 'someone-else');
        $approve(['--confirm' => true])->expectsOutputToContain('tidak boleh menyetujui permintaannya sendiri')->assertFailed();
        $this->operator(1002, 'bob');
        $approve(['--ticket' => 'MT-CASE-99999', '--confirm' => true])->expectsOutputToContain('--ticket harus sama')->assertFailed();
        $approve()->expectsOutputToContain('DRY-RUN')->assertSuccessful();
        $this->assertDatabaseHas('payment_gateway_refunds', ['id' => $refund->id, 'status' => 'unknown', 'active_slot' => $payment->id]);

        $approve(['--confirm' => true])->expectsOutputToContain('ditutup sebagai closed_unprocessed')->assertSuccessful();
        $this->assertDatabaseHas('payment_gateway_refunds', ['id' => $refund->id, 'status' => 'closed_unprocessed', 'active_slot' => null,
            'amount' => 2000, 'refund_key' => 'dr-refund-close-01']);
        $rows = DB::table('payment_gateway_refund_closures')->orderBy('id')->get();
        $this->assertSame([['request', null, 1001, 'alice', null], ['approval', $request->id, 1002, 'bob', now()->toDateTimeString()]],
            $rows->map(fn ($row) => [$row->kind, $row->request_id, (int) $row->operator_uid, $row->operator_name, $row->closed_at])->all());
        $this->assertSame([$payment->order_id, 'dr-refund-close-01', 2000, 'MT-CASE-12345'], [$rows[1]->order_id, $rows[1]->refund_key, (int) $rows[1]->amount, $rows[1]->midtrans_ticket]);
        $approve(['--confirm' => true])->expectsOutputToContain('sudah disetujui')->assertFailed();
        $this->assertSame(1, $this->posts);

        // The slot is free again and a new refund needs a new key, but the closed amount stays reserved.
        $this->refund($payment, ['--amount' => '5000'])->expectsOutputToContain('Rp2.000 dicadangkan')->assertFailed();
        $this->refund($payment, ['--amount' => '3000'])->expectsOutputToContain('dicadangkan Rp2.000, sisa Rp3.000')->assertSuccessful();
        $this->refund($payment, ['--confirm' => true, '--refund-key' => 'dr-refund-close-01'])->expectsOutputToContain('sudah tercatat')->assertFailed();
        $this->artisan('payments:gateway-refunds', ['--status' => 'closed_unprocessed'])->expectsOutputToContain('dr-refund-close-01')->assertSuccessful();

        foreach ([
            fn () => DB::table('payment_gateway_refund_closures')->where('id', $request->id)->update(['midtrans_ticket' => 'forged']),
            fn () => DB::table('payment_gateway_refund_closures')->delete(),
            fn () => DB::table('payment_gateway_refunds')->where('id', $refund->id)->update(['amount' => 1]),
            fn () => DB::table('payment_gateway_refunds')->where('id', $refund->id)->delete(),
            fn () => DB::table('payment_gateway_refunds')->where('id', $refund->id)->update(['status' => 'unknown']),
        ] as $tamper) {
            try {
                $tamper();
                $this->fail('Tampering should be rejected.');
            } catch (QueryException $exception) {
                $this->assertMatchesRegularExpression('/append-only|immutable|listed by Midtrans/', $exception->getMessage());
            }
        }

        // If Midtrans lists the closed refund after all, reconciliation surfaces it.
        $this->record($payment->order_id, 'dr-refund-close-01', 2000, 'Customer refund');
        $this->artisan('payments:gateway-refunds', ['--reconcile' => true])->expectsOutputToContain('succeeded=1')->assertSuccessful();
        $this->assertDatabaseHas('payment_gateway_refunds', ['id' => $refund->id, 'status' => 'succeeded', 'active_slot' => null]);
        $this->assertSame(1, $this->posts);
    }

    public function test_closure_is_refused_when_midtrans_lists_the_refund_or_state_changes_concurrently(): void
    {
        [$payment, $refund] = $this->expiredUnknown();
        $this->travel(145)->hours();
        $this->operator(1001, 'alice');
        $this->closeRequest($payment, $refund, ['--confirm' => true])->assertSuccessful();
        $request = DB::table('payment_gateway_refund_closures')->first();
        $this->operator(1002, 'bob');

        // Race: another process verifies the refund between the pre-check and the atomic close.
        $this->during = fn () => DB::table('payment_gateway_refunds')->where('id', $refund->id)->update(['status' => 'succeeded', 'active_slot' => null]);
        $this->artisan('payments:gateway-refund-close-approve', ['request' => $request->id, '--ticket' => 'MT-CASE-12345', '--confirm' => true])
            ->expectsOutputToContain('berubah bersamaan')->assertFailed();
        $this->assertDatabaseHas('payment_gateway_refunds', ['id' => $refund->id, 'status' => 'succeeded']);
        $this->assertSame(1, DB::table('payment_gateway_refund_closures')->count());

        [$other, $second] = $this->expiredUnknown('dr-refund-close-04');
        $this->travel(145)->hours();
        $this->operator(1001, 'alice');
        $this->closeRequest($other, $second, ['--confirm' => true])->assertSuccessful();
        $this->operator(1002, 'bob');
        $this->record($other->order_id, 'dr-refund-close-04', 2000, 'Customer refund');
        $this->artisan('payments:gateway-refund-close-approve', ['request' => DB::table('payment_gateway_refund_closures')->max('id'), '--ticket' => 'MT-CASE-12345', '--confirm' => true])
            ->expectsOutputToContain('bertentangan dengan konfirmasi')->assertFailed();
        $this->assertDatabaseHas('payment_gateway_refunds', ['id' => $second->id, 'status' => 'succeeded', 'active_slot' => null]);

        $this->travel(73)->hours();
        [$third, $refund3] = $this->expiredUnknown('dr-refund-close-05');
        $this->travel(145)->hours();
        $this->operator(1001, 'alice');
        $this->closeRequest($third, $refund3, ['--confirm' => true])->assertSuccessful();
        $this->travel(73)->hours();
        $this->operator(1002, 'bob');
        $this->artisan('payments:gateway-refund-close-approve', ['request' => DB::table('payment_gateway_refund_closures')->max('id'), '--ticket' => 'MT-CASE-12345', '--confirm' => true])
            ->expectsOutputToContain('kedaluwarsa')->assertFailed();
        $this->assertDatabaseHas('payment_gateway_refunds', ['id' => $refund3->id, 'status' => 'unknown', 'active_slot' => $third->id]);
        $this->assertSame(3, $this->posts);
    }

    public function test_pending_attempt_without_a_recorded_outcome_becomes_unknown_but_keeps_its_slot(): void
    {
        $payment = $this->paid();
        DB::table('payment_gateway_refunds')->insert(['payment_id' => $payment->id, 'refund_key' => 'dr-refund-crash-01', 'amount' => 1000, 'reason' => 'x',
            'status' => 'pending', 'operator' => 'x', 'environment' => 'sandbox', 'active_slot' => $payment->id, 'attempts' => 1,
            'last_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->refund($payment, ['--reconcile' => true])->expectsOutputToContain('pending -> pending')->assertSuccessful();
        $this->travel(31)->minutes();
        $this->refund($payment, ['--reconcile' => true])->expectsOutputToContain('pending -> unknown')->assertSuccessful();
        $this->assertDatabaseHas('payment_gateway_refunds', ['refund_key' => 'dr-refund-crash-01', 'status' => 'unknown', 'active_slot' => $payment->id]);
        $this->assertSame(0, $this->posts);
    }

    /** Unknown refund dr-refund-close-01 (Rp2.000 of Rp5.000) closed by two OS accounts. */
    private function closedRefund(): array
    {
        [$payment, $refund] = $this->expiredUnknown();
        $this->travel(145)->hours();
        $this->operator(1001, 'alice');
        $this->closeRequest($payment, $refund, ['--confirm' => true])->assertSuccessful();
        $this->operator(1002, 'bob');
        $this->artisan('payments:gateway-refund-close-approve', ['request' => DB::table('payment_gateway_refund_closures')->max('id'), '--ticket' => 'MT-CASE-12345', '--confirm' => true])->assertSuccessful();

        return [$payment, DB::table('payment_gateway_refunds')->find($refund->id)];
    }

    private function manual(object $payment, string $amount, string $reference, bool $confirm = true)
    {
        return $this->artisan('payments:refund', ['payment' => $payment->id, '--amount' => $amount, '--reason' => 'Transfer', '--reference' => $reference, '--confirm' => $confirm]);
    }

    public function test_closed_refund_stays_reserved_and_a_resurrected_refund_is_recorded_once_with_its_history(): void
    {
        $logged = new \ArrayObject;
        Event::listen(MessageLogged::class, fn ($event) => $logged->append([$event->level, $event->message]));
        Mail::fake();
        // Faked SMTP mailer: nothing leaves the process.
        config(['midtrans.alerts.mail_to' => 'ops@example.test', 'mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.invalid', 'mail.mailers.smtp.port' => 587,
            'mail.from.address' => 'payments@dapurrantau.test']);
        [$payment, $closed] = $this->closedRefund();
        $this->assertSame(['closed_unprocessed', null], [$closed->status, $closed->active_slot]);

        // One ledger for manual and gateway refunds: releasing the slot never released the reserved 2000.
        $this->manual($payment, '5000', 'TRF-AFTER-1')->expectsOutputToContain('Rp2.000 dicadangkan')->assertFailed();
        $this->refund($payment, ['--amount' => '3001'])->expectsOutputToContain('Rp2.000 dicadangkan')->assertFailed();
        $this->manual($payment, '3000', 'TRF-AFTER-1')->expectsOutputToContain('refund sebagian')->assertSuccessful();
        $this->assertSame('paid', DB::table('payments')->where('id', $payment->id)->value('status'));

        // Midtrans lists the "unprocessed" refund after all.
        $this->record($payment->order_id, 'dr-refund-close-01', 2000, 'Customer refund');
        $this->artisan('payments:gateway-refunds', ['--reconcile' => true])->expectsOutputToContain('succeeded=1')->assertSuccessful();
        $this->assertDatabaseHas('payment_gateway_refunds', ['id' => $closed->id, 'status' => 'succeeded', 'amount' => 2000, 'active_slot' => null]);
        $this->assertSame(2, DB::table('payment_gateway_refund_closures')->where('gateway_refund_id', $closed->id)->count());
        $this->assertDatabaseHas('payment_gateway_refund_events', ['gateway_refund_id' => $closed->id, 'from_status' => 'closed_unprocessed', 'to_status' => 'succeeded', 'listed_amount' => 2000]);
        $this->assertDatabaseHas('payment_adjustments', ['payment_id' => $payment->id, 'source' => 'midtrans', 'amount' => 2000,
            'reference' => 'partial_refund:key:'.hash('sha256', 'dr-refund-close-01')]);
        $this->assertContains(['error', 'payment.gateway_refund_closed_unprocessed_but_listed'], $logged->getArrayCopy());
        $this->assertDatabaseCount('payment_refund_anomalies', 0);
        $this->artisan('payments:gateway-refunds', ['--reconcile' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('payment_adjustments')->where(['payment_id' => $payment->id, 'source' => 'midtrans'])->count());

        // A refund made outside the app (e.g. Dashboard) pushes the total over the payment.
        $this->record($payment->order_id, 'dashboard-refund-1', 1000, 'Dashboard');
        $this->artisan('payments:gateway-refunds', ['--reconcile' => true])->assertSuccessful();
        $anomaly = DB::table('payment_refund_anomalies')->where('payment_id', $payment->id)->first();
        $this->assertSame([5000, 6000, 1000], [(int) $anomaly->gross_amount, (int) $anomaly->refunded_amount, (int) $anomaly->excess_amount]);
        $this->assertSame(['manual' => 3000, 'midtrans' => 3000], array_intersect_key(json_decode($anomaly->evidence, true), ['manual' => 1, 'midtrans' => 1]));
        $this->assertContains(['error', 'payment.refund_excess'], $logged->getArrayCopy());
        Mail::assertSent(PaymentAlertMail::class, fn ($mail) => $mail->event === 'payment.refund_excess' && $mail->hasTo('ops@example.test'));
        Mail::assertSent(PaymentAlertMail::class, fn ($mail) => $mail->event === 'payment.gateway_refund_closed_unprocessed_but_listed');
        $this->artisan('payments:gateway-refunds', ['--reconcile' => true])->assertSuccessful();
        $this->assertDatabaseCount('payment_refund_anomalies', 1);

        // The combined total reached the payment, so the existing full-refund lifecycle applied.
        $this->assertSame('refunded', DB::table('payments')->where('id', $payment->id)->value('status'));
        // Further refunds are blocked; nothing is deleted.
        $history = DB::table('payment_adjustments')->where('payment_id', $payment->id)->orderBy('id')->get(['source', 'kind', 'amount', 'reference']);
        $this->manual($payment, '1', 'TRF-AFTER-2')->expectsOutputToContain('berstatus paid')->assertFailed();
        $this->refund($payment, ['--amount' => '1'])->expectsOutputToContain('berstatus paid')->assertFailed();
        $this->assertEquals($history, DB::table('payment_adjustments')->where('payment_id', $payment->id)->orderBy('id')->get(['source', 'kind', 'amount', 'reference']));
        $this->assertSame([['manual', 3000], ['midtrans', 2000], ['midtrans', 1000]], $history->where('kind', 'partial_refund')->map(fn ($row) => [$row->source, (int) $row->amount])->values()->all());
        $this->assertSame(1, $this->posts);
        foreach ([fn () => DB::table('payment_refund_anomalies')->delete(), fn () => DB::table('payment_gateway_refund_events')->update(['event' => 'x'])] as $tamper) {
            try {
                $tamper();
                $this->fail('Tampering should be rejected.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('append-only', $exception->getMessage());
            }
        }
    }

    public function test_a_refund_listed_after_a_full_manual_refund_reaches_the_ledger_instead_of_failing_with_422(): void
    {
        $payment = $this->paid();
        $this->manual($payment, '5000', 'TRF-FULL-1')->assertSuccessful();
        $this->assertSame('refunded', DB::table('payments')->where('id', $payment->id)->value('status'));
        $this->record($payment->order_id, 'dashboard-late-1', 2000, 'Dashboard');

        app(MidtransSandboxService::class)->applyGatewayStatus($payment->id, $this->status[$payment->order_id]);
        $this->assertDatabaseHas('payment_adjustments', ['payment_id' => $payment->id, 'source' => 'midtrans', 'kind' => 'partial_refund', 'amount' => 2000, 'status' => 'pending_review']);
        $this->assertSame([5000, 7000, 2000], array_values((array) DB::table('payment_refund_anomalies')->first(['gross_amount', 'refunded_amount', 'excess_amount'])));
        $this->assertSame('refunded', DB::table('payments')->where('id', $payment->id)->value('status'));
        app(MidtransSandboxService::class)->applyGatewayStatus($payment->id, $this->status[$payment->order_id]);
        $this->assertSame(1, DB::table('payment_adjustments')->where(['payment_id' => $payment->id, 'source' => 'midtrans'])->count());
        $this->assertDatabaseCount('payment_refund_anomalies', 1);
    }

    public function test_closed_refunds_are_rechecked_daily_after_thirty_days_without_extra_polling(): void
    {
        [$payment, $closed] = $this->closedRefund();
        $this->travel(31)->days();
        $count = fn () => tap($this->gets, fn () => $this->artisan('payments:gateway-refunds', ['--reconcile' => true])->assertSuccessful());
        $before = $this->gets;
        $this->artisan('payments:gateway-refunds', ['--reconcile' => true])->assertSuccessful();
        $this->assertSame($before + 1, $this->gets);
        $this->travel(6)->hours();
        $this->artisan('payments:gateway-refunds', ['--reconcile' => true])->assertSuccessful();
        $this->assertSame($before + 1, $this->gets);
        $this->travel(19)->hours();
        $this->record($payment->order_id, 'dr-refund-close-01', 2000, 'Customer refund');
        $this->artisan('payments:gateway-refunds', ['--reconcile' => true])->expectsOutputToContain('succeeded=1')->assertSuccessful();
        $this->assertSame($before + 2, $this->gets);
        $this->assertDatabaseHas('payment_gateway_refunds', ['id' => $closed->id, 'status' => 'succeeded']);
    }

    public function test_unknown_refund_closure_is_disabled_for_production(): void
    {
        [$payment, $refund] = $this->expiredUnknown();
        $this->travel(145)->hours();
        $this->operator(1001, 'alice');
        config(['midtrans.environment' => 'production', 'midtrans.production.server_key' => 'Mid-server-fixture']);
        $this->closeRequest($payment, $refund, ['--confirm' => true])->expectsOutputToContain('dinonaktifkan untuk Production')->assertFailed();
        config(['midtrans.environment' => 'sandbox']);
        $this->app['env'] = 'production';
        $this->closeRequest($payment, $refund, ['--confirm' => true])->expectsOutputToContain('dinonaktifkan untuk Production')->assertFailed();
        $this->artisan('payments:gateway-refund-close-approve', ['request' => 1, '--ticket' => 'MT-CASE-12345', '--confirm' => true])
            ->expectsOutputToContain('dinonaktifkan untuk Production')->assertFailed();
        $this->app['env'] = 'testing';
        $this->assertDatabaseCount('payment_gateway_refund_closures', 0);
        $this->assertDatabaseHas('payment_gateway_refunds', ['id' => $refund->id, 'status' => 'unknown', 'active_slot' => $payment->id]);
    }

    public function test_manual_refund_reconciles_dashboard_refunds_first_and_fails_closed(): void
    {
        $payment = $this->paid();
        // Two Dashboard refunds with the same amount but different refund_key values, webhook off.
        $this->record($payment->order_id, 'dashboard-n1-a', 1500, 'Dashboard');
        $this->record($payment->order_id, 'dashboard-n1-b', 1500, 'Dashboard');
        $gets = $this->gets;
        $this->manual($payment, '2001', 'TRF-N1-1')->expectsOutputToContain('Sisa yang dapat direfund: Rp2.000')->assertFailed();
        $this->assertSame($gets + 1, $this->gets);
        $this->assertSame([1500, 1500], DB::table('payment_adjustments')->where(['payment_id' => $payment->id, 'source' => 'midtrans'])->orderBy('id')->pluck('amount')->all());
        $this->assertDatabaseHas('payment_adjustments', ['payment_id' => $payment->id, 'reference' => 'partial_refund:key:'.hash('sha256', 'dashboard-n1-b')]);
        $this->manual($payment, '2000', 'TRF-N1-1', false)->expectsOutputToContain('DRY-RUN')->assertSuccessful();
        // Dashboard 1500 + 1500 + manual 2000 = the full payment: the existing full-refund lifecycle applies.
        $this->manual($payment, '2000', 'TRF-N1-1')->expectsOutputToContain('Dicatat (refund penuh')->assertSuccessful();
        $this->assertSame('refunded', DB::table('payments')->where('id', $payment->id)->value('status'));
        $this->manual($payment, '2000', 'TRF-N1-1')->expectsOutputToContain('sudah dicatat')->assertSuccessful();
        $this->assertSame(2, DB::table('payment_adjustments')->where(['payment_id' => $payment->id, 'source' => 'midtrans'])->count());
        $this->assertSame(0, $this->posts);

        // Fail closed: unreachable, cumulative-only, inconsistent, duplicate key, missing config.
        $other = $this->paid();
        $order = $other->order_id;
        $state = $this->status[$order];
        foreach ([
            'unreachable' => null,
            'cumulative only' => ['transaction_status' => 'partial_refund', 'refund_amount' => '1000.00', 'refunds' => []],
            'sum mismatch' => ['transaction_status' => 'partial_refund', 'refund_amount' => '2000.00', 'refunds' => [['refund_key' => 'x-1', 'refund_amount' => '1000.00']]],
            'duplicate key' => ['transaction_status' => 'partial_refund', 'refund_amount' => '2000.00', 'refunds' => [['refund_key' => 'x-1', 'refund_amount' => '1000.00'], ['refund_key' => 'x-1', 'refund_amount' => '1000.00']]],
            'missing key' => ['transaction_status' => 'partial_refund', 'refund_amount' => '1000.00', 'refunds' => [['refund_amount' => '1000.00']]],
            'not settled' => ['transaction_status' => 'pending'],
        ] as $case => $override) {
            $this->during = $override === null ? fn () => throw new ConnectionException('timeout') : null;
            $this->status[$order] = $override === null ? $state : $override + $state;
            $this->manual($other, '100', 'TRF-N1-2')->expectsOutputToContain('sisa refund tidak dianggap tersedia')->assertFailed();
        }
        $this->status[$order] = $state;
        config(['midtrans.environment' => null]);
        $this->manual($other, '100', 'TRF-N1-2')->expectsOutputToContain('Konfigurasi Midtrans belum lengkap')->assertFailed();
        config(['midtrans.environment' => 'sandbox']);
        $this->assertSame(0, DB::table('payment_adjustments')->where('payment_id', $other->id)->count());
        $this->assertSame(0, $this->posts);
    }

    public function test_rollback_refuses_to_drop_refund_history(): void
    {
        $payment = $this->paid();
        $this->mode = 'timeout';
        $this->refund($payment, ['--confirm' => true, '--refund-key' => 'dr-refund-keep-01'])->assertFailed();
        foreach (['2026_11_27_000001_create_payment_refund_events_and_anomalies', '2026_11_26_000001_create_payment_gateway_refund_closures_table',
            '2026_11_25_000001_create_payment_gateway_refunds_table'] as $migration) {
            try {
                (require database_path("migrations/{$migration}.php"))->down();
                $this->fail("{$migration} should refuse to drop history.");
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Rollback dibatalkan', $exception->getMessage());
            }
        }
        try {
            Artisan::call('migrate:rollback', ['--step' => 1]);
            $this->fail('migrate:rollback should refuse.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Rollback dibatalkan', $exception->getMessage());
        }
        $this->assertSame([1, 2], [DB::table('payment_gateway_refunds')->count(), DB::table('payment_gateway_refund_events')->count()]);
        $this->assertTrue(Schema::hasTable('payment_refund_anomalies'));

        $this->manual($payment, '1', 'TRF-N2')->assertFailed();
        DB::table('payment_adjustments')->insert(['payment_id' => $payment->id, 'kind' => 'partial_refund', 'source' => 'manual', 'status' => 'pending_review',
            'amount' => 1, 'reference' => 'TRF-N2', 'created_at' => now(), 'updated_at' => now()]);
        try {
            (require database_path('migrations/2026_11_22_000001_create_payment_reversal_tables.php'))->down();
            $this->fail('payment_adjustments should be kept.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('payment_adjustments', $exception->getMessage());
        }
    }

    public function test_alert_mail_status_never_claims_a_log_only_delivery(): void
    {
        Mail::fake();
        $logged = new \ArrayObject;
        Event::listen(MessageLogged::class, fn ($event) => $logged->append([$event->message, $event->context['mail_status'] ?? null]));
        $raise = fn () => PaymentAlert::raise('payment.refund_excess', ['payment_id' => 1]);

        $this->artisan('payments:alert-status')->expectsOutputToContain('Alert email: nonaktif')->assertSuccessful();
        config(['midtrans.alerts.mail_to' => 'ops@example.test', 'mail.default' => 'log']);
        $raise();
        $this->artisan('payments:alert-status')->expectsOutputToContain('HANYA LOG')->assertFailed();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '', 'mail.from.address' => 'hello@example.com']);
        $raise();
        $this->artisan('payments:alert-status')->expectsOutputToContain('tidak dapat dikirim (incomplete')->assertFailed();
        config(['mail.default' => 'failover']);
        $this->artisan('payments:alert-status')->expectsOutputToContain('unsupported')->assertFailed();
        Mail::assertNothingSent();

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.invalid', 'mail.from.address' => 'payments@dapurrantau.test']);
        $this->artisan('payments:alert-status')->expectsOutputToContain('siap melalui mailer smtp')->assertSuccessful();
        $raise();
        Mail::assertSent(PaymentAlertMail::class, 1);
        $this->assertSame([['payment.alert_mail', 'logged_only'], ['payment.alert_mail', 'failed'], ['payment.alert_mail', 'sent']],
            array_values(array_filter($logged->getArrayCopy(), fn ($row) => $row[0] === 'payment.alert_mail')));

        // A failing transport is reported as failed and never breaks the caller.
        Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('smtp down'));
        $raise();
        $this->assertSame(['payment.alert_mail', 'failed'], array_values(array_filter($logged->getArrayCopy(), fn ($row) => $row[0] === 'payment.alert_mail'))[3]);
    }
}
