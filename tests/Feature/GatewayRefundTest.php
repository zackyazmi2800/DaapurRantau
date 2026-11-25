<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MidtransSandboxService;
use App\Services\SubscriptionService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GatewayRefundTest extends TestCase
{
    private array $status = [];

    /** accept | reject | timeout | ghost (timeout, but Midtrans recorded it) */
    private string $mode = 'accept';

    private int $posts = 0;

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
                return Http::response($this->status[$order]);
            }
            $this->posts++;
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
        $this->refund($other, ['--confirm' => true, '--refund-key' => 'dr-refund-lost-01'])->expectsOutputToContain('Jangan kirim ulang')->assertFailed();
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
            $this->postJson('/api/midtrans/notification', ['order_id' => $payment->order_id])->assertUnprocessable();
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
}
