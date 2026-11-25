<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MidtransSandboxService;
use App\Services\PaymentAdjustmentService;
use App\Services\SubscriptionService;
use ArrayObject;
use DomainException;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PaymentReversalTest extends TestCase
{
    private array $gateway = [];

    private array $during = [];

    private ArrayObject $logged;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate')->assertSuccessful();
        Http::preventStrayRequests();
        config(['midtrans.environment' => 'sandbox', 'midtrans.webhook_enabled' => true, 'midtrans.sandbox.signature_secret_key' => 'fixture-signature', 'midtrans.sandbox.server_key' => 'SB-Mid-server-fixture',
            'midtrans.sandbox.client_key' => 'SB-Mid-client-fixture', 'logging.default' => 'null', 'app.debug' => false]);
        $this->logged = new ArrayObject;
        Event::listen(MessageLogged::class, fn (MessageLogged $event) => $this->logged->append(['level' => $event->level, 'message' => $event->message] + $event->context));
        Http::fake(function ($request) {
            $order = explode('/', $request->url())[4];
            $this->assertSame('GET', $request->method());
            $this->assertSame("https://api.sandbox.midtrans.com/v2/{$order}/status", $request->url());
            if ($hook = $this->during[$order] ?? null) {
                unset($this->during[$order]);
                $hook();
            }

            return Http::response($this->gateway[$order] + ['order_id' => $order, 'currency' => 'IDR', 'status_code' => '200', 'fraud_status' => 'accept']);
        });
        $this->travelTo(Carbon::parse('2026-01-01 00:00:00 UTC'));
    }

    private function order(User $user, string $code = 'weekly', string $status = 'pending'): object
    {
        $plan = DB::table('subscription_plans')->where('code', $code)->first();
        $order = (string) Str::uuid();
        $id = DB::table('payments')->insertGetId(['order_id' => $order, 'user_id' => $user->id, 'plan_id' => $plan->id, 'amount' => $plan->price,
            'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        $this->gateway[$order] = ['transaction_status' => 'settlement', 'gross_amount' => $plan->price.'.00'];

        return DB::table('payments')->find($id);
    }

    private function pay(User $user, string $code = 'weekly'): object
    {
        $payment = $this->order($user, $code);
        $this->notice($payment->order_id, 'settlement')->assertOk();
        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'status' => 'paid']);

        return $payment;
    }

    private function notice(string $order, string $status, array $with = []): TestResponse
    {
        $payload = $with + ['order_id' => $order, 'status_code' => '200', 'gross_amount' => $this->gateway[$order]['gross_amount'] ?? '5000.00', 'transaction_status' => $status];
        $payload['signature_key'] ??= hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].config('midtrans.sandbox.signature_secret_key'));

        return $this->postJson('/api/midtrans/notification', $payload);
    }

    private function midtrans(object $payment, string $status, array $with = []): TestResponse
    {
        $this->gateway[$payment->order_id] = $with + ['transaction_status' => $status] + $this->gateway[$payment->order_id];

        return $this->notice($payment->order_id, $status);
    }

    private function period(object $payment): ?array
    {
        $period = DB::table('subscription_periods')->where('payment_id', $payment->id)->first();

        return $period ? [$period->status, $period->starts_at, $period->ends_at, $period->held_seconds] : null;
    }

    private function ends(User $user): ?string
    {
        return DB::table('subscriptions')->where('user_id', $user->id)->value('ends_at');
    }

    private function state(object $payment): string
    {
        return DB::table('payments')->where('id', $payment->id)->value('status');
    }

    private function snapshot(): string
    {
        return json_encode([DB::table('payments')->orderBy('id')->get(['id', 'order_id', 'status', 'amount', 'updated_at']), DB::table('subscription_periods')->orderBy('id')->get(),
            DB::table('payment_adjustments')->orderBy('id')->get(), DB::table('subscriptions')->orderBy('user_id')->get()]);
    }

    private function assertConsistent(): void
    {
        foreach (DB::table('payments')->get() as $payment) {
            $period = DB::table('subscription_periods')->where('payment_id', $payment->id)->value('status');
            if ($payment->status === 'paid' && DB::table('subscription_periods')->where('user_id', $payment->user_id)->exists()) {
                $this->assertNotNull($period, "Payment {$payment->id} lunas tanpa periode.");
            }
            if ($payment->status !== 'paid') {
                $this->assertNotSame('active', $period, "Payment {$payment->id} berstatus {$payment->status} dengan periode aktif.");
            }
        }
        foreach (DB::table('subscriptions')->get() as $subscription) {
            $end = DB::table('subscription_periods')->where('user_id', $subscription->user_id)->where('status', 'active')->max('ends_at');
            if ($end !== null) {
                $this->assertSame($end, $subscription->ends_at, "Ringkasan user {$subscription->user_id} tidak sama dengan periode aktif terakhir.");
            }
        }
        $this->assertSame(0, DB::table('subscription_periods')->where('status', '!=', 'active')->whereNull('held_seconds')->count());
        $this->assertSame(0, DB::table('subscription_periods')->where('status', 'active')->whereNotNull('held_seconds')->count());
    }

    private function assertLogsAreSafe(array $messages): void
    {
        $this->assertSame($messages, collect($this->logged)->pluck('message')->unique()->values()->all());
        foreach ($this->logged as $entry) {
            $this->assertSame([], array_diff(array_keys($entry), ['level', 'message', 'payment_id', 'kind', 'source', 'status', 'decision', 'closed', 'user_id', 'gross', 'refunded', 'excess', 'alert']));
        }
        $text = json_encode($this->logged->getArrayCopy());
        foreach (array_keys($this->gateway) as $order) {
            $this->assertStringNotContainsString($order, $text);
        }
        $this->assertDoesNotMatchRegularExpression('/fixture-signature|SB-Mid|TRF-|select |insert |\.php/i', $text);
    }

    public function test_full_refund_revokes_only_the_unused_time_of_its_own_payment(): void
    {
        $user = User::factory()->create();
        [$a, $b] = [$this->pay($user), $this->pay($user)];
        $this->assertSame(['active', '2026-01-01 00:00:00', '2026-01-08 00:00:00', null], $this->period($a));
        $this->assertSame(['active', '2026-01-08 00:00:00', '2026-01-15 00:00:00', null], $this->period($b));
        $this->assertSame('2026-01-15 00:00:00', $this->ends($user));

        $this->travel(3)->days();
        $this->midtrans($a, 'refund', ['refund_amount' => '5000.00'])->assertOk();
        $this->assertSame(['refunded', 'paid'], [$this->state($a), $this->state($b)]);
        $this->assertSame(['revoked', '2026-01-01 00:00:00', '2026-01-04 00:00:00', 345600], $this->period($a));
        $this->assertSame(['active', '2026-01-04 00:00:00', '2026-01-11 00:00:00', null], $this->period($b));
        $this->assertSame('2026-01-11 00:00:00', $this->ends($user));
        $this->assertNotNull(app(SubscriptionService::class)->active($user->id));
        $this->assertDatabaseHas('payment_adjustments', ['payment_id' => $a->id, 'kind' => 'refund', 'source' => 'midtrans', 'status' => 'resolved', 'amount' => 5000, 'reference' => 'refund']);

        $before = $this->snapshot();
        $sent = Http::recorded()->count();
        $this->notice($a->order_id, 'refund')->assertOk();
        $this->notice($a->order_id, 'settlement')->assertOk();
        $this->notice($b->order_id, 'settlement')->assertOk();
        $this->assertSame($sent, Http::recorded()->count());
        $this->assertSame([], app(MidtransSandboxService::class)->reconcile($a->order_id));
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('payment_adjustments', 1);
        $this->assertDatabaseCount('subscriptions', 1);

        $this->travel(1)->days();
        $this->midtrans($b, 'refund')->assertOk();
        $this->assertSame(['revoked', '2026-01-04 00:00:00', '2026-01-05 00:00:00', 518400], $this->period($b));
        $this->assertSame(['revoked', '2026-01-01 00:00:00', '2026-01-04 00:00:00', 345600], $this->period($a));
        $this->assertSame('2026-01-05 00:00:00', $this->ends($user));
        $this->assertNull(app(SubscriptionService::class)->active($user->id));
        $this->assertDatabaseCount('payments', 2);
        $this->actingAs($user)->get('/langganan')->assertOk()->assertSee('Akun Free')->assertSee('Dana dikembalikan')
            ->assertDontSee('Berhasil')->assertDontSee('Perbarui status')->assertDontSee('Lanjutkan pembayaran');
        $this->assertLogsAreSafe(['payment.adjustment']);
        $this->assertCount(2, $this->logged);
        $this->assertConsistent();
    }

    public function test_refunds_of_queued_and_finished_periods_keep_time_paid_by_other_payments(): void
    {
        $user = User::factory()->create();
        [$a, $b, $c] = [$this->pay($user), $this->pay($user), $this->pay($user)];
        $this->assertSame('2026-01-22 00:00:00', $this->ends($user));

        $this->travel(3)->days();
        $this->midtrans($b, 'refund')->assertOk();
        $this->assertSame(['active', '2026-01-01 00:00:00', '2026-01-08 00:00:00', null], $this->period($a));
        $this->assertSame(['revoked', '2026-01-08 00:00:00', '2026-01-08 00:00:00', 604800], $this->period($b));
        $this->assertSame(['active', '2026-01-08 00:00:00', '2026-01-15 00:00:00', null], $this->period($c));
        $this->assertSame('2026-01-15 00:00:00', $this->ends($user));

        $this->travel(1)->days();
        $this->midtrans($a, 'refund')->assertOk();
        $this->assertSame(['revoked', '2026-01-01 00:00:00', '2026-01-05 00:00:00', 259200], $this->period($a));
        $this->assertSame(['revoked', '2026-01-08 00:00:00', '2026-01-08 00:00:00', 604800], $this->period($b));
        $this->assertSame(['active', '2026-01-05 00:00:00', '2026-01-12 00:00:00', null], $this->period($c));
        $this->assertSame('2026-01-12 00:00:00', $this->ends($user));

        $this->travel(8)->days();
        $this->assertNull(app(SubscriptionService::class)->active($user->id));
        $d = $this->pay($user, 'monthly');
        $this->assertSame(['active', '2026-01-13 00:00:00', '2026-02-13 00:00:00', null], $this->period($d));
        $this->travel(1)->days();
        $this->midtrans($c, 'refund')->assertOk();
        $this->assertSame('refunded', $this->state($c));
        $this->assertSame(['revoked', '2026-01-05 00:00:00', '2026-01-12 00:00:00', 0], $this->period($c));
        $this->assertSame(['active', '2026-01-13 00:00:00', '2026-02-13 00:00:00', null], $this->period($d));
        $this->assertSame('2026-02-13 00:00:00', $this->ends($user));
        $this->assertSame(['refunded', 'refunded', 'refunded', 'paid'], DB::table('payments')->orderBy('id')->pluck('status')->all());
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertConsistent();
    }

    public function test_refund_details_and_snapshots_share_one_manual_limit(): void
    {
        $payment = $this->pay(User::factory()->create());
        $first = ['refund_key' => 'one', 'refund_amount' => '2000.00'];
        $second = ['refund_key' => 'two', 'refund_amount' => '1500.00'];
        $this->midtrans($payment, 'partial_refund', ['refund_amount' => '2000.00'])->assertOk();
        $this->midtrans($payment, 'partial_refund', ['refund_amount' => '3500.00', 'refunds' => [$first, $second]])->assertOk();
        $before = $this->snapshot();
        $this->midtrans($payment, 'partial_refund', ['refund_amount' => '3500.00', 'refunds' => [$first, $second + ['bank_confirmed_at' => '2026-01-02 00:00:00']]])->assertOk();
        $this->midtrans($payment, 'partial_refund', ['refund_amount' => '1000.00', 'refunds' => []])->assertOk();
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('payment_adjustments', 3);
        $service = app(PaymentAdjustmentService::class);
        $this->assertFalse($service->manualRefund($payment->id, '1501', 'uji', 'manual', false)[0]);
        $this->assertTrue($service->manualRefund($payment->id, '1500', 'uji', 'manual', false)[0]);
        $this->midtrans($payment, 'partial_refund', ['refund_amount' => '3000.00', 'refunds' => [array_replace($first, ['refund_amount' => '1500.00']), $second]])->assertUnprocessable();
        $this->midtrans($payment, 'partial_refund', ['refund_amount' => '3000.00', 'refunds' => [$first, $second]])->assertUnprocessable();
        $this->midtrans($payment, 'partial_refund', ['refund_amount' => '1000.50'])->assertUnprocessable();
        $this->assertSame($before, $this->snapshot());
        DB::table('payment_adjustments')->insert(['payment_id' => $payment->id, 'source' => 'midtrans', 'kind' => 'partial_refund',
            'reference' => 'partial_refund:3500', 'amount' => 3500, 'status' => 'resolved']);
        $this->assertTrue($service->manualRefund($payment->id, '1500', 'uji', 'manual', false)[0]);
    }

    public function test_partial_refund_rejects_ambiguous_batches_and_rolls_back_inserted_keys(): void
    {
        $payment = $this->pay(User::factory()->create());
        $service = app(PaymentAdjustmentService::class);
        $a = ['refund_key' => 'a', 'refund_amount' => '2000.00'];
        $b = ['refund_key' => 'b', 'refund_amount' => '1500.00'];
        $reject = function (array $payload) use ($payment, $service) {
            $before = $this->snapshot();
            try {
                $service->recordMidtransPartialRefund($payment, $payload);
                $this->fail('Payload ambigu diterima.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }
            $this->assertSame($before, $this->snapshot());
        };
        $service->recordMidtransPartialRefund($payment, ['refund_amount' => '2000.00']);
        $reject(['refunds' => [$b]]);
        $service->recordMidtransPartialRefund($payment, ['refund_amount' => '2000.00', 'refunds' => [$a]]);
        $before = $this->snapshot();
        $this->assertSame('unchanged', $service->recordMidtransPartialRefund($payment, ['refunds' => [$a]]));
        $this->assertSame('unchanged', $service->recordMidtransPartialRefund($payment, ['refunds' => [$a + ['bank_confirmed_at' => '2026-01-02 00:00:00']]]));
        $this->assertSame($before, $this->snapshot());
        $reject(['refunds' => [array_replace($a, ['refund_amount' => '2500.00'])]]);
        foreach ([null, 'invalid', [['refund_amount' => '1000.00']], [$a, []],
            [array_replace($a, ['refund_amount' => '2500.00']), []]] as $details) {
            $reject(['refund_amount' => '3500.00', 'refunds' => $details]);
        }
        $inserted = false;
        DB::listen(function ($query) use (&$inserted) {
            if (str_starts_with(strtolower($query->sql), 'insert into "payment_adjustments"')) {
                $inserted = true;
            }
        });
        // B is inserted before A conflicts. The outer transaction must remove B.
        $this->midtrans($payment, 'partial_refund', ['refund_amount' => '4000.00',
            'refunds' => [$b, array_replace($a, ['refund_amount' => '2500.00'])]])->assertUnprocessable();
        $this->assertTrue($inserted);
        $this->assertSame($before, $this->snapshot());
        $service->recordMidtransPartialRefund($payment, ['refund_amount' => '3500.00', 'refunds' => [$a, $b]]);
        $after = $this->snapshot();
        $service->recordMidtransPartialRefund($payment, ['refund_amount' => '1000.00']);
        $service->recordMidtransPartialRefund($payment, ['refunds' => [$a]]);
        $this->assertSame($after, $this->snapshot());
    }

    public function test_a5_manual_refunds_after_individual_details_preserve_other_payment(): void
    {
        foreach (['1500', '1000'] as $manual) {
            $user = User::factory()->create();
            $payment = $this->pay($user);
            $other = $this->pay($user);
            $service = app(PaymentAdjustmentService::class);
            DB::table('payment_adjustments')->insert(['payment_id' => $payment->id, 'source' => 'midtrans',
                'kind' => 'partial_refund', 'reference' => 'partial_refund:2000', 'amount' => 2000, 'status' => 'resolved']);
            $service->recordMidtransPartialRefund($payment, ['refund_amount' => '3500.00']);
            $service->recordMidtransPartialRefund($payment, ['refund_amount' => '3500.00', 'refunds' => [
                ['refund_key' => 'one', 'refund_amount' => '2000.00'],
                ['refund_key' => 'two', 'refund_amount' => '1500.00'],
            ]]);
            $this->assertFalse($service->manualRefund($payment->id, '1501', 'uji', 'manual', true)[0]);
            $this->assertTrue($service->manualRefund($payment->id, $manual, 'uji', 'manual', true)[0]);
            $this->assertSame($manual === '1500' ? 'refunded' : 'paid', $this->state($payment));
            $this->assertSame('active', $this->period($other)[0]);
            $period = $this->period($other);
            $this->assertSame(604800, strtotime($period[2]) - strtotime($period[1]));
            $before = $this->snapshot();
            $this->assertTrue($service->manualRefund($payment->id, $manual, 'uji', 'manual', true)[0]);
            $this->assertSame($before, $this->snapshot());
            $this->assertConsistent();
        }
    }

    public function test_partial_refund_opens_a_review_without_changing_premium(): void
    {
        $user = User::factory()->create();
        [$a, $b] = [$this->pay($user), $this->pay($user)];
        $this->travel(2)->days();
        $this->midtrans($a, 'partial_refund', ['refund_amount' => '2000.00', 'refunds' => [['refund_key' => 'k1', 'refund_amount' => '2000.00', 'reason' => 'Salah paket']]])->assertOk();
        $this->assertSame('paid', $this->state($a));
        $this->assertDatabaseHas('payment_adjustments', ['payment_id' => $a->id, 'kind' => 'partial_refund', 'source' => 'midtrans', 'status' => 'pending_review',
            'amount' => 2000, 'reference' => 'partial_refund:key:'.hash('sha256', 'k1'), 'reason' => 'Salah paket']);
        $this->assertSame(['active', '2026-01-01 00:00:00', '2026-01-08 00:00:00', null], $this->period($a));
        $this->assertSame('2026-01-15 00:00:00', $this->ends($user));

        $before = $this->snapshot();
        $this->notice($a->order_id, 'partial_refund')->assertOk();
        $sent = Http::recorded()->count();
        $this->assertSame([], app(MidtransSandboxService::class)->reconcile($a->order_id));
        $this->assertSame([], app(MidtransSandboxService::class)->reconcile());
        $this->assertSame($sent, Http::recorded()->count());
        $this->assertSame($before, $this->snapshot());
        $this->actingAs($user)->get('/langganan')->assertOk()->assertSee('Premium aktif')->assertSeeInOrder(['Berhasil', 'Sedang ditinjau']);

        foreach (['5000.00', '6000.00', '0', '-1', 'abc', null] as $invalid) {
            $this->midtrans($a, 'partial_refund', ['refund_amount' => $invalid])->assertUnprocessable();
        }
        $this->assertSame($before, $this->snapshot());
        $this->midtrans($a, 'partial_refund', ['refund_amount' => '3500.00', 'refunds' => []])->assertOk();
        $this->assertSame(['partial_refund:key:'.hash('sha256', 'k1'), 'partial_refund:cumulative'], DB::table('payment_adjustments')->orderBy('id')->pluck('reference')->all());
        $this->assertSame(['active', '2026-01-01 00:00:00', '2026-01-08 00:00:00', null], $this->period($a));

        $this->midtrans($a, 'refund')->assertOk();
        $this->assertSame('refunded', $this->state($a));
        $this->assertSame(['resolved', 'resolved', 'resolved'], DB::table('payment_adjustments')->orderBy('id')->pluck('status')->all());
        $this->assertSame(['revoked', '2026-01-01 00:00:00', '2026-01-03 00:00:00', 432000], $this->period($a));
        $this->assertSame(['active', '2026-01-03 00:00:00', '2026-01-10 00:00:00', null], $this->period($b));
        $this->assertSame('2026-01-10 00:00:00', $this->ends($user));
        $this->assertLogsAreSafe(['payment.adjustment']);
        $this->assertConsistent();
    }

    public function test_chargeback_and_reversal_suspend_premium_and_restore_it_once(): void
    {
        $user = User::factory()->create();
        [$a, $b] = [$this->pay($user), $this->pay($user)];
        $this->travel(2)->days();
        $this->midtrans($a, 'chargeback')->assertOk();
        $this->assertSame(['chargeback', 'paid'], [$this->state($a), $this->state($b)]);
        $this->assertSame(['suspended', '2026-01-01 00:00:00', '2026-01-03 00:00:00', 432000], $this->period($a));
        $this->assertSame(['active', '2026-01-03 00:00:00', '2026-01-10 00:00:00', null], $this->period($b));
        $this->assertSame('2026-01-10 00:00:00', $this->ends($user));
        $this->assertDatabaseHas('payment_adjustments', ['payment_id' => $a->id, 'kind' => 'chargeback', 'status' => 'pending_review', 'amount' => null, 'reference' => 'chargeback']);
        $before = $this->snapshot();
        $this->notice($a->order_id, 'chargeback')->assertOk();
        $this->notice($a->order_id, 'settlement')->assertOk();
        $this->assertSame([], app(MidtransSandboxService::class)->reconcile($a->order_id));
        $this->assertSame($before, $this->snapshot());
        $this->actingAs($user)->get('/langganan')->assertOk()->assertSee('Premium aktif')->assertSee('Sedang ditinjau');

        $this->travel(3)->days();
        $review = fn (object $payment, array $options) => $this->artisan('payments:review', $options + ['payment' => $payment->id]);
        $review($a, [])->assertFailed();
        $review($a, ['--restore' => true, '--revoke' => true, '--confirm' => true])->assertFailed();
        $this->artisan('payments:review', ['payment' => 999, '--restore' => true])->assertFailed();
        $review($a, ['--restore' => true])->expectsOutputToContain('DRY-RUN')->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        $review($a, ['--restore' => true, '--confirm' => true])->expectsOutputToContain('Ditutup: 1')->assertSuccessful();
        $this->assertSame('paid', $this->state($a));
        $this->assertSame(['active', '2026-01-10 00:00:00', '2026-01-15 00:00:00', null], $this->period($a));
        $this->assertSame('2026-01-15 00:00:00', $this->ends($user));
        $this->assertSame(['rejected'], DB::table('payment_adjustments')->pluck('status')->all());
        $restored = $this->snapshot();
        $review($a, ['--restore' => true, '--confirm' => true])->expectsOutputToContain('Tidak ada peninjauan terbuka')->assertSuccessful();
        $review($a, ['--revoke' => true, '--confirm' => true])->expectsOutputToContain('Tidak ada peninjauan terbuka')->assertSuccessful();
        $this->assertFalse(app(SubscriptionService::class)->restore($a->id));
        $this->assertSame($restored, $this->snapshot());

        $this->midtrans($b, 'partial_chargeback')->assertOk();
        $this->assertSame('paid', $this->state($b));
        $this->assertSame(['suspended', '2026-01-03 00:00:00', '2026-01-06 00:00:00', 345600], $this->period($b));
        $this->assertSame(['active', '2026-01-06 00:00:00', '2026-01-11 00:00:00', null], $this->period($a));
        $this->assertSame('2026-01-11 00:00:00', $this->ends($user));
        $review($b, ['--revoke' => true, '--confirm' => true])->expectsOutputToContain('Ditutup: 1')->assertSuccessful();
        $this->assertSame(['revoked', '2026-01-03 00:00:00', '2026-01-06 00:00:00', 345600], $this->period($b));
        $this->assertSame('paid', $this->state($b));
        $this->assertSame('2026-01-11 00:00:00', $this->ends($user));

        $c = $this->pay($user);
        $this->assertSame(['active', '2026-01-11 00:00:00', '2026-01-18 00:00:00', null], $this->period($c));
        $this->midtrans($c, 'deny', ['status_code' => '202'])->assertOk();
        $this->assertSame('paid', $this->state($c));
        $this->assertSame(['suspended', '2026-01-11 00:00:00', '2026-01-11 00:00:00', 604800], $this->period($c));
        $this->assertSame('2026-01-11 00:00:00', $this->ends($user));
        $this->assertDatabaseHas('payment_adjustments', ['payment_id' => $c->id, 'kind' => 'reversal', 'status' => 'pending_review', 'reference' => 'reversal:deny']);
        $this->notice($c->order_id, 'deny')->assertOk();
        $this->assertDatabaseCount('payment_adjustments', 3);
        $review($c, ['--revoke' => true, '--confirm' => true])->assertSuccessful();
        $this->assertSame('failed', $this->state($c));
        $this->assertSame(['revoked', '2026-01-11 00:00:00', '2026-01-11 00:00:00', 604800], $this->period($c));

        $d = $this->pay($user);
        $this->midtrans($d, 'chargeback')->assertOk();
        $this->assertSame(['suspended', '2026-01-11 00:00:00', '2026-01-11 00:00:00', 604800], $this->period($d));
        $this->midtrans($d, 'refund')->assertOk();
        $this->assertSame('refunded', $this->state($d));
        $this->assertSame(['revoked', '2026-01-11 00:00:00', '2026-01-11 00:00:00', 604800], $this->period($d));
        $this->assertSame(['resolved', 'resolved'], DB::table('payment_adjustments')->where('payment_id', $d->id)->orderBy('id')->pluck('status')->all());
        $review($d, ['--restore' => true, '--confirm' => true])->expectsOutputToContain('Tidak ada peninjauan terbuka')->assertSuccessful();
        $this->assertSame(['revoked', '2026-01-11 00:00:00', '2026-01-11 00:00:00', 604800], $this->period($d));
        $this->assertSame('2026-01-11 00:00:00', $this->ends($user));
        $this->assertSame(['active', '2026-01-06 00:00:00', '2026-01-11 00:00:00', null], $this->period($a));
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('payments', 4);
        $this->assertLogsAreSafe(['payment.adjustment', 'payment.review_closed']);
        $this->assertConsistent();
    }

    public function test_reversal_notices_are_verified_and_late_settlement_keeps_existing_rules(): void
    {
        $user = User::factory()->create();
        $a = $this->pay($user);
        $before = $this->snapshot();
        $sent = Http::recorded()->count();
        $this->notice($a->order_id, 'refund', ['signature_key' => str_repeat('0', 128)])->assertForbidden();
        $this->notice($a->order_id, 'refund', ['signature_key' => hash('sha512', $a->order_id.'2005000.00'.'SB-Mid-server-fixture')])->assertForbidden();
        $this->notice((string) Str::uuid(), 'refund')->assertNotFound();
        $this->notice($a->order_id, 'refund', ['gross_amount' => '1.00'])->assertUnprocessable();
        $this->notice($a->order_id, 'dibatalkan')->assertUnprocessable();
        $this->notice($a->order_id, 'settlement')->assertOk();
        $this->assertSame($sent, Http::recorded()->count());

        $this->notice($a->order_id, 'refund')->assertOk();
        $this->notice($a->order_id, 'chargeback')->assertOk();
        $this->assertSame($sent + 2, Http::recorded()->count());
        foreach ([['gross_amount' => '9000.00'], ['order_id' => (string) Str::uuid()], ['currency' => 'USD']] as $mismatch) {
            $this->gateway[$a->order_id] = $mismatch + ['transaction_status' => 'refund', 'gross_amount' => '5000.00'];
            $this->notice($a->order_id, 'refund', ['gross_amount' => '5000.00'])->assertUnprocessable();
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('payment_adjustments', 0);
        $this->assertSame('2026-01-08 00:00:00', $this->ends($user));

        $late = User::factory()->create();
        $expired = $this->order($late, 'weekly', 'expired');
        $this->notice($expired->order_id, 'settlement')->assertOk();
        $this->notice($expired->order_id, 'settlement')->assertOk();
        $this->assertSame('paid', $this->state($expired));
        $this->assertSame(['active', '2026-01-01 00:00:00', '2026-01-08 00:00:00', null], $this->period($expired));
        $this->assertSame('2026-01-08 00:00:00', $this->ends($late));
        $this->assertDatabaseCount('subscription_periods', 2);

        $never = User::factory()->create();
        $pending = $this->order($never);
        $this->midtrans($pending, 'partial_refund', ['refund_amount' => '1000.00'])->assertOk();
        $this->assertSame('pending', $this->state($pending));
        $this->assertDatabaseHas('payment_adjustments', ['payment_id' => $pending->id, 'kind' => 'partial_refund', 'status' => 'pending_review', 'amount' => 1000]);
        $this->midtrans($pending, 'refund')->assertOk();
        $this->assertSame('refunded', $this->state($pending));
        $this->assertNull($this->period($pending));
        $this->assertNull($this->ends($never));
        $this->assertDatabaseHas('payment_adjustments', ['payment_id' => $pending->id, 'kind' => 'refund', 'status' => 'resolved']);
        $sent = Http::recorded()->count();
        $this->midtrans($pending, 'settlement')->assertOk();
        $this->assertSame($sent, Http::recorded()->count());
        $this->assertSame('refunded', $this->state($pending));
        $this->assertSame([], app(MidtransSandboxService::class)->reconcile($pending->order_id));
        $this->assertNull($this->ends($never));
        $this->assertDatabaseCount('subscriptions', 2);
        $this->assertConsistent();
    }

    public function test_manual_refund_command_records_only_confirmed_transfers_once(): void
    {
        $user = User::factory()->create();
        [$a, $b] = [$this->pay($user), $this->pay($user)];
        $unpaid = $this->order($user, 'weekly', 'failed');
        $this->travel(1)->days();
        $sent = Http::recorded()->count();
        $before = $this->snapshot();
        $refund = fn (object $payment, array $options) => $this->artisan('payments:refund', $options + ['payment' => $payment->id]);
        $transfer = ['--reason' => 'Permintaan pengguna', '--reference' => 'TRF-1'];

        $refund($a, [])->assertFailed();
        $refund($a, ['--amount' => '2000'])->assertFailed();
        $refund($a, ['--amount' => '2000', '--reason' => 'Permintaan pengguna'])->assertFailed();
        $refund($a, ['--amount' => '2000', '--reference' => 'TRF-1'])->assertFailed();
        foreach (['0', '-5', 'abc', '20.5', ''] as $invalid) {
            $refund($a, ['--amount' => $invalid, '--confirm' => true] + $transfer)->assertFailed();
        }
        $refund($a, ['--amount' => '5001', '--confirm' => true] + $transfer)->expectsOutputToContain('melebihi')->assertFailed();
        $refund($unpaid, ['--amount' => '5000', '--confirm' => true] + $transfer)->expectsOutputToContain('paid')->assertFailed();
        $this->artisan('payments:refund', ['payment' => 999, '--amount' => '5000', '--confirm' => true] + $transfer)->assertFailed();
        $refund($a, ['--amount' => '2000'] + $transfer)->expectsOutputToContain('DRY-RUN')->assertSuccessful();
        $refund($a, ['--amount' => '5000'] + $transfer)->expectsOutputToContain('DRY-RUN')->assertSuccessful();
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('payment_adjustments', 0);

        $refund($a, ['--amount' => '2000', '--confirm' => true] + $transfer)->expectsOutputToContain('Dicatat')->assertSuccessful();
        $this->assertSame('paid', $this->state($a));
        $this->assertDatabaseHas('payment_adjustments', ['payment_id' => $a->id, 'kind' => 'partial_refund', 'source' => 'manual', 'status' => 'pending_review',
            'amount' => 2000, 'reference' => 'TRF-1', 'reason' => 'Permintaan pengguna']);
        $this->assertSame(['active', '2026-01-01 00:00:00', '2026-01-08 00:00:00', null], $this->period($a));
        $this->assertSame('2026-01-15 00:00:00', $this->ends($user));
        $partial = $this->snapshot();
        $refund($a, ['--amount' => '2000', '--confirm' => true] + $transfer)->expectsOutputToContain('sudah dicatat')->assertSuccessful();
        $refund($a, ['--amount' => '2000'] + $transfer)->expectsOutputToContain('sudah dicatat')->assertSuccessful();
        $refund($a, ['--amount' => '3000', '--confirm' => true] + $transfer)->expectsOutputToContain('nominal berbeda')->assertFailed();
        $refund($a, ['--amount' => '3001', '--confirm' => true, '--reference' => 'TRF-2'] + $transfer)->expectsOutputToContain('melebihi')->assertFailed();
        $this->assertSame([], app(MidtransSandboxService::class)->reconcile());
        $this->assertSame([], app(MidtransSandboxService::class)->reconcile($a->order_id));
        $this->notice($a->order_id, 'settlement')->assertOk();
        $this->assertSame($partial, $this->snapshot());

        $refund($a, ['--amount' => '3000', '--confirm' => true, '--reference' => 'TRF-2'] + $transfer)->expectsOutputToContain('refund penuh')->assertSuccessful();
        $this->assertSame(['refunded', 'paid'], [$this->state($a), $this->state($b)]);
        $this->assertSame([['partial_refund', 'resolved', 2000], ['refund', 'resolved', 3000]],
            DB::table('payment_adjustments')->orderBy('id')->get()->map(fn ($row) => [$row->kind, $row->status, $row->amount])->all());
        $this->assertSame(['revoked', '2026-01-01 00:00:00', '2026-01-02 00:00:00', 518400], $this->period($a));
        $this->assertSame(['active', '2026-01-02 00:00:00', '2026-01-09 00:00:00', null], $this->period($b));
        $this->assertSame('2026-01-09 00:00:00', $this->ends($user));
        $full = $this->snapshot();
        $refund($a, ['--amount' => '3000', '--confirm' => true, '--reference' => 'TRF-2'] + $transfer)->expectsOutputToContain('sudah dicatat')->assertSuccessful();
        $refund($a, ['--amount' => '1', '--confirm' => true, '--reference' => 'TRF-9'] + $transfer)->assertFailed();
        $this->notice($a->order_id, 'settlement')->assertOk();
        $this->assertSame([], app(MidtransSandboxService::class)->reconcile($a->order_id));
        $this->assertSame($full, $this->snapshot());

        $refund($b, ['--amount' => '5000', '--confirm' => true, '--reference' => 'TRF-3'] + $transfer)->assertSuccessful();
        $this->assertSame('refunded', $this->state($b));
        $this->assertSame(['revoked', '2026-01-02 00:00:00', '2026-01-02 00:00:00', 604800], $this->period($b));
        $this->assertNull(app(SubscriptionService::class)->active($user->id));
        $this->assertSame($sent, Http::recorded()->count());
        $this->assertDatabaseCount('payments', 3);
        $this->assertLogsAreSafe(['payment.adjustment']);
        $this->assertConsistent();
    }

    public function test_manual_refund_limit_counts_refunds_already_recorded_from_midtrans(): void
    {
        $pair = function () {
            $user = User::factory()->create();

            return [$user, $this->pay($user), $this->pay($user)];
        };
        [$plain, $a, $a2] = $pair();
        [$mixed, $b, $b2] = $pair();
        [$twice, $c, $c2] = $pair();
        [$before, $d, $d2] = $pair();
        [$over, $e] = $pair();
        [$kept, $f, $f2] = $pair();
        $this->travel(1)->days();
        $refund = fn (object $payment, string $amount, string $reference, bool $confirm = true) => $this->artisan('payments:refund',
            ['payment' => $payment->id, '--amount' => $amount, '--reason' => 'Permintaan pengguna', '--reference' => $reference] + ($confirm ? ['--confirm' => true] : []));
        $rows = fn (object $payment) => DB::table('payment_adjustments')->where('payment_id', $payment->id)->orderBy('id')->get()
            ->map(fn ($row) => [$row->kind, $row->source, $row->status, $row->amount])->all();
        $revoked = ['revoked', '2026-01-01 00:00:00', '2026-01-02 00:00:00', 518400];
        $moved = ['active', '2026-01-02 00:00:00', '2026-01-09 00:00:00', null];

        $refund($a, '5000', 'TRF-A', false)->expectsOutputToContain('Rencana: refund penuh')->assertSuccessful();
        $this->assertSame([], $rows($a));
        $refund($a, '5000', 'TRF-A')->expectsOutputToContain('Dicatat (refund penuh')->assertSuccessful();
        $this->assertSame(['refunded', $revoked, $moved, '2026-01-09 00:00:00'], [$this->state($a), $this->period($a), $this->period($a2), $this->ends($plain)]);
        $once = $this->snapshot();
        $refund($a, '5000', 'TRF-A')->expectsOutputToContain('sudah dicatat')->assertSuccessful();
        $refund($a, '5000', 'TRF-A2')->expectsOutputToContain('paid')->assertFailed();
        $this->midtrans($a, 'refund')->assertOk();
        $this->assertSame($once, $this->snapshot());
        $this->assertSame([['refund', 'manual', 'resolved', 5000]], $rows($a));

        $this->midtrans($b, 'partial_refund', ['refund_amount' => '3500.00', 'refunds' => []])->assertOk();
        $open = $this->snapshot();
        $sent = Http::recorded()->count();
        foreach (['5000', '1501'] as $excess) {
            $refund($b, $excess, 'TRF-B', false)->expectsOutputToContain('Sisa yang dapat direfund: Rp1.500')->assertFailed();
            $refund($b, $excess, 'TRF-B')->expectsOutputToContain('Sisa yang dapat direfund: Rp1.500')->assertFailed();
        }
        $refund($b, '1500', 'TRF-B', false)->expectsOutputToContain('Rencana: refund penuh')->assertSuccessful();
        $this->assertSame($open, $this->snapshot());
        $refund($b, '1500', 'TRF-B')->expectsOutputToContain('Dicatat (refund penuh')->assertSuccessful();
        $this->assertSame($sent, Http::recorded()->count());
        $this->assertSame([['partial_refund', 'midtrans', 'resolved', 3500], ['refund', 'manual', 'resolved', 1500]], $rows($b));
        $this->assertSame(['refunded', $revoked, $moved, '2026-01-09 00:00:00'], [$this->state($b), $this->period($b), $this->period($b2), $this->ends($mixed)]);

        $this->midtrans($c, 'partial_refund', ['refund_amount' => '2000.00'])->assertOk();
        $this->midtrans($c, 'partial_refund', ['refund_amount' => '3500.00', 'refunds' => []])->assertOk();
        $this->assertSame([3500], DB::table('payment_adjustments')->where('payment_id', $c->id)->orderBy('id')->pluck('amount')->all());
        $refund($c, '1501', 'TRF-C1')->expectsOutputToContain('Sisa yang dapat direfund: Rp1.500')->assertFailed();
        $refund($c, '1000', 'TRF-C1', false)->expectsOutputToContain('Rencana: refund sebagian')->assertSuccessful();
        $refund($c, '1000', 'TRF-C1')->expectsOutputToContain('Dicatat (refund sebagian')->assertSuccessful();
        $this->assertSame(['paid', 'active', '2026-01-15 00:00:00'], [$this->state($c), $this->period($c)[0], $this->ends($twice)]);
        $refund($c, '501', 'TRF-C2')->expectsOutputToContain('Sisa yang dapat direfund: Rp500')->assertFailed();
        $refund($c, '500', 'TRF-C2')->expectsOutputToContain('Dicatat (refund penuh')->assertSuccessful();
        $this->assertSame(['refunded', $revoked, $moved], [$this->state($c), $this->period($c), $this->period($c2)]);

        $refund($d, '1000', 'TRF-D1')->expectsOutputToContain('Dicatat (refund sebagian')->assertSuccessful();
        $this->midtrans($d, 'partial_refund', ['refund_amount' => '3500.00', 'refunds' => []])->assertOk();
        $partial = $this->snapshot();
        $refund($d, '1000', 'TRF-D1')->expectsOutputToContain('sudah dicatat')->assertSuccessful();
        $refund($d, '1000', 'TRF-D1', false)->expectsOutputToContain('sudah dicatat')->assertSuccessful();
        $refund($d, '999', 'TRF-D1')->expectsOutputToContain('nominal berbeda')->assertFailed();
        $refund($d, '501', 'TRF-D2')->expectsOutputToContain('Sisa yang dapat direfund: Rp500')->assertFailed();
        $refund($d, '500', 'TRF-D2', false)->expectsOutputToContain('Rencana: refund penuh')->assertSuccessful();
        $this->assertSame($partial, $this->snapshot());
        $refund($d, '500', 'TRF-D2')->expectsOutputToContain('Dicatat (refund penuh')->assertSuccessful();
        $this->assertSame([['partial_refund', 'manual', 'resolved', 1000], ['partial_refund', 'midtrans', 'resolved', 3500], ['refund', 'manual', 'resolved', 500]], $rows($d));
        $this->assertSame(['refunded', $revoked, $moved], [$this->state($d), $this->period($d), $this->period($d2)]);

        $refund($e, '2000', 'TRF-E1')->assertSuccessful();
        $this->midtrans($e, 'partial_refund', ['refund_amount' => '3500.00', 'refunds' => []])->assertOk();
        $refund($e, '1', 'TRF-E2', false)->expectsOutputToContain('paid')->assertFailed();
        $refund($e, '1', 'TRF-E2')->expectsOutputToContain('paid')->assertFailed();
        $this->assertSame([['partial_refund', 'manual', 'resolved', 2000], ['partial_refund', 'midtrans', 'pending_review', 3500]], $rows($e));
        $this->assertSame('refunded', $this->state($e));

        $this->midtrans($f, 'partial_refund', ['refund_amount' => '3500.00', 'refunds' => []])->assertOk();
        $this->artisan('payments:review', ['payment' => $f->id, '--restore' => true, '--confirm' => true])->assertSuccessful();
        $this->assertSame([['partial_refund', 'midtrans', 'rejected', 3500]], $rows($f));
        $refund($f, '1501', 'TRF-F')->expectsOutputToContain('Sisa yang dapat direfund: Rp1.500')->assertFailed();
        $refund($f, '1500', 'TRF-F')->expectsOutputToContain('Dicatat (refund penuh')->assertSuccessful();
        $this->assertSame(['refunded', $revoked, $moved, '2026-01-09 00:00:00'], [$this->state($f), $this->period($f), $this->period($f2), $this->ends($kept)]);

        $this->assertSame(6, DB::table('payments')->where('status', 'refunded')->count());
        $this->assertSame(6, DB::table('subscription_periods')->where('status', 'revoked')->count());
        $this->assertSame(0, DB::table('subscription_periods')->where('status', 'revoked')->where('held_seconds', '!=', 518400)->count());
        $this->assertConsistent();
        $this->assertLogsAreSafe(['payment.adjustment', 'payment.refund_excess', 'payment.review_closed']);
    }

    public function test_midtrans_refund_after_manual_refund_applies_the_combined_limit_once(): void
    {
        $refund = fn (object $payment, string $amount, string $reference) => $this->artisan('payments:refund',
            ['payment' => $payment->id, '--amount' => $amount, '--reason' => 'Permintaan pengguna', '--reference' => $reference, '--confirm' => true]);
        $rows = fn (object $payment) => DB::table('payment_adjustments')->where('payment_id', $payment->id)->orderBy('id')->get()
            ->map(fn ($row) => [$row->source, $row->kind, $row->status, $row->amount, $row->reason])->all();
        $cases = ['2500' => 'Kelebihan refund Rp500: total manual dan Midtrans Rp5.500 melebihi pembayaran Rp5.000. Salah paket', '2000' => null];
        $accounts = [];
        foreach (array_keys($cases) as $gateway) {
            $user = User::factory()->create();
            $accounts[$gateway] = [$user, $this->pay($user), $this->pay($user)];
        }
        $outsider = User::factory()->create();
        $foreign = $this->pay($outsider);
        $this->travel(1)->days();
        $kept = [$this->state($foreign), $this->period($foreign), $this->ends($outsider)];

        foreach ($cases as $gateway => $note) {
            [$user, $payment, $next] = $accounts[$gateway];
            $refund($payment, '3000', "TRF-$gateway")->expectsOutputToContain('Dicatat (refund sebagian')->assertSuccessful();
            $this->midtrans($payment, 'partial_refund', ['refund_amount' => "$gateway.00",
                'refunds' => [['refund_key' => "key-$gateway", 'refund_amount' => "$gateway.00", 'reason' => 'Salah paket']]])->assertOk();
            $this->assertSame([['manual', 'partial_refund', 'resolved', 3000, 'Permintaan pengguna'],
                ['midtrans', 'partial_refund', $note ? 'pending_review' : 'resolved', (int) $gateway, $note ?? 'Salah paket']], $rows($payment));
            $this->assertSame(['refunded', ['revoked', '2026-01-01 00:00:00', '2026-01-02 00:00:00', 518400], ['active', '2026-01-02 00:00:00', '2026-01-09 00:00:00', null], '2026-01-09 00:00:00'],
                [$this->state($payment), $this->period($payment), $this->period($next), $this->ends($user)]);

            $once = $this->snapshot();
            $sent = Http::recorded()->count();
            $this->notice($payment->order_id, 'partial_refund')->assertOk();
            $this->assertSame('unchanged', app(PaymentAdjustmentService::class)->recordMidtransPartialRefund($payment, ['refund_amount' => "$gateway.00",
                'refunds' => [['refund_key' => "key-$gateway", 'refund_amount' => "$gateway.00"]]]));
            $refund($payment, '1', "TRF-$gateway-2")->expectsOutputToContain('paid')->assertFailed();
            $this->assertSame($sent + 1, Http::recorded()->count());
            $this->assertSame($once, $this->snapshot());
        }
        $this->actingAs($accounts['2500'][0])->get('/langganan')->assertOk()->assertSee('Sedang ditinjau');
        $this->artisan('payments:review', ['payment' => $accounts['2500'][1]->id, '--revoke' => true, '--confirm' => true])
            ->expectsOutputToContain('sudah dicabut sebelumnya')->assertSuccessful();
        $this->assertSame('resolved', DB::table('payment_adjustments')->where('payment_id', $accounts['2500'][1]->id)->where('source', 'midtrans')->value('status'));
        $this->assertSame(['refunded', 'revoked'], [$this->state($accounts['2500'][1]), $this->period($accounts['2500'][1])[0]]);
        $this->assertSame($kept, [$this->state($foreign), $this->period($foreign), $this->ends($outsider)]);
        $this->assertSame(0, DB::table('payment_adjustments')->where('payment_id', $foreign->id)->count());
        $this->assertSame(2, DB::table('subscription_periods')->where('status', 'revoked')->count());
        $this->assertConsistent();
        $this->assertLogsAreSafe(['payment.adjustment', 'payment.refund_excess', 'payment.review_closed']);
    }

    public function test_manual_refund_after_midtrans_refunds_uses_only_the_remaining_amount(): void
    {
        $refund = fn (object $payment, string $amount, string $reference) => $this->artisan('payments:refund',
            ['payment' => $payment->id, '--amount' => $amount, '--reason' => 'Permintaan pengguna', '--reference' => $reference, '--confirm' => true]);
        $rows = fn (object $payment) => DB::table('payment_adjustments')->where('payment_id', $payment->id)->orderBy('id')->get()
            ->map(fn ($row) => [$row->source, $row->kind, $row->status, $row->amount, $row->reason])->all();
        [$a, $b, $c] = [$this->pay(User::factory()->create()), $this->pay(User::factory()->create()), $this->pay(User::factory()->create())];
        $this->travel(1)->days();

        $this->midtrans($a, 'partial_refund', ['refund_amount' => '2500.00', 'refunds' => [['refund_key' => 'key-a', 'refund_amount' => '2500.00']]])->assertOk();
        $before = $this->snapshot();
        $refund($a, '3000', 'TRF-A')->expectsOutputToContain('Sisa yang dapat direfund: Rp2.500')->assertFailed();
        $refund($a, '1000', 'key-a')->expectsOutputToContain('sudah tercatat sebagai refund Midtrans')->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(['paid', 'active'], [$this->state($a), $this->period($a)[0]]);

        $this->midtrans($b, 'partial_refund', ['refund_amount' => '3500.00', 'refunds' => [
            ['refund_key' => 'key-b1', 'refund_amount' => '2000.00'], ['refund_key' => 'key-b2', 'refund_amount' => '1500.00']]])->assertOk();
        $refund($b, '1501', 'TRF-B')->expectsOutputToContain('Sisa yang dapat direfund: Rp1.500')->assertFailed();
        $refund($b, '1500', 'TRF-B')->expectsOutputToContain('Dicatat (refund penuh')->assertSuccessful();
        $this->assertSame([['midtrans', 'partial_refund', 'resolved', 2000, null], ['midtrans', 'partial_refund', 'resolved', 1500, null],
            ['manual', 'refund', 'resolved', 1500, 'Permintaan pengguna']], $rows($b));
        $this->assertSame(['refunded', ['revoked', '2026-01-01 00:00:00', '2026-01-02 00:00:00', 518400]], [$this->state($b), $this->period($b)]);
        $once = $this->snapshot();
        $this->midtrans($b, 'partial_refund')->assertOk();
        $this->assertSame($once, $this->snapshot());

        $refund($c, '1000', 'key-c')->assertSuccessful();
        $this->midtrans($c, 'partial_refund', ['refund_amount' => '1000.00', 'refunds' => [['refund_key' => 'key-c', 'refund_amount' => '1000.00', 'reason' => 'Salah paket']]])->assertOk();
        $this->assertSame([['manual', 'partial_refund', 'pending_review', 1000, 'Permintaan pengguna'],
            ['midtrans', 'partial_refund', 'pending_review', 1000, 'Referensi sama dengan refund manual; periksa kemungkinan tercatat ganda. Salah paket']], $rows($c));
        $refund($c, '1', 'TRF-C')->expectsOutputToContain('konflik referensi')->assertFailed();
        $this->assertSame(['paid', 'active'], [$this->state($c), $this->period($c)[0]]);
        $this->assertConsistent();
        $this->assertLogsAreSafe(['payment.adjustment', 'payment.refund_reference_conflict']);
    }

    public function test_reference_conflict_between_manual_and_midtrans_fails_closed(): void
    {
        $refund = fn (object $payment, string $amount, string $reference, bool $confirm = true) => $this->artisan('payments:refund',
            ['payment' => $payment->id, '--amount' => $amount, '--reason' => 'Permintaan pengguna', '--reference' => $reference] + ($confirm ? ['--confirm' => true] : []));
        $rows = fn (object $payment) => DB::table('payment_adjustments')->where('payment_id', $payment->id)->orderBy('id')->get()
            ->map(fn ($row) => [$row->source, $row->kind, $row->status, $row->amount, $row->reason])->all();
        $user = User::factory()->create();
        [$payment, $next] = [$this->pay($user), $this->pay($user)];
        $other = User::factory()->create();
        [$plain, $plainNext] = [$this->pay($other), $this->pay($other)];
        $outsider = User::factory()->create();
        $foreign = $this->pay($outsider);
        $this->travel(1)->days();
        $kept = [$this->state($foreign), $this->period($foreign), $this->ends($outsider)];
        $premium = [$this->period($payment), $this->period($next), $this->ends($user)];
        $revoked = ['revoked', '2026-01-01 00:00:00', '2026-01-02 00:00:00', 518400];
        $moved = ['active', '2026-01-02 00:00:00', '2026-01-09 00:00:00', null];

        $refund($payment, '2000', 'key-x')->assertSuccessful();
        $refund($payment, '1000', 'TRF-1')->assertSuccessful();
        $details = ['refund_amount' => '4000.00', 'refunds' => [
            ['refund_key' => 'key-x', 'refund_amount' => '2000.00', 'reason' => 'Salah paket'], ['refund_key' => 'key-y', 'refund_amount' => '2000.00']]];
        $this->midtrans($payment, 'partial_refund', $details)->assertOk();
        $deferred = 'Refund penuh ditunda: konflik referensi manual dan Midtrans belum ditinjau.';
        $this->assertSame([
            ['manual', 'partial_refund', 'pending_review', 2000, 'Permintaan pengguna'],
            ['manual', 'partial_refund', 'pending_review', 1000, 'Permintaan pengguna'],
            ['midtrans', 'partial_refund', 'pending_review', 2000, "Referensi sama dengan refund manual; periksa kemungkinan tercatat ganda. $deferred Salah paket"],
            ['midtrans', 'partial_refund', 'pending_review', 2000, $deferred],
        ], $rows($payment));
        $this->assertSame(['paid', ...$premium], [$this->state($payment), $this->period($payment), $this->period($next), $this->ends($user)]);
        $this->actingAs($user)->get('/langganan')->assertOk()->assertSee('Sedang ditinjau');

        $once = $this->snapshot();
        $this->midtrans($payment, 'partial_refund', $details)->assertOk();
        $this->notice($payment->order_id, 'partial_refund')->assertOk();
        $this->assertSame('unchanged', app(PaymentAdjustmentService::class)->recordMidtransPartialRefund($payment, $details));
        $refund($payment, '1', 'TRF-2', false)->expectsOutputToContain('konflik referensi')->assertFailed();
        $refund($payment, '1', 'TRF-2')->expectsOutputToContain('konflik referensi')->assertFailed();
        $refund($payment, '1000', 'key-y')->expectsOutputToContain('sudah tercatat sebagai refund Midtrans')->assertFailed();
        $refund($payment, '2000', 'key-x')->expectsOutputToContain('sudah dicatat')->assertSuccessful();
        $this->assertSame($once, $this->snapshot());

        $this->artisan('payments:review', ['payment' => $payment->id, '--revoke' => true, '--confirm' => true])->expectsOutputToContain('Ditutup: 4 peninjauan')->assertSuccessful();
        $this->assertSame(['paid', $revoked, $moved, '2026-01-09 00:00:00'], [$this->state($payment), $this->period($payment), $this->period($next), $this->ends($user)]);
        $closed = $this->snapshot();
        $refund($payment, '1', 'TRF-3')->expectsOutputToContain('Sisa yang dapat direfund: Rp0')->assertFailed();
        $this->midtrans($payment, 'partial_refund', $details)->assertOk();
        $this->assertSame($closed, $this->snapshot());

        $this->midtrans($plain, 'partial_refund', ['refund_amount' => '2000.00', 'refunds' => [['refund_key' => 'key-p', 'refund_amount' => '2000.00']]])->assertOk();
        $before = $this->snapshot();
        $refund($plain, '2000', 'key-p', false)->expectsOutputToContain('sudah tercatat sebagai refund Midtrans')->assertFailed();
        $refund($plain, '2000', 'key-p')->expectsOutputToContain('sudah tercatat sebagai refund Midtrans')->assertFailed();
        $this->assertSame($before, $this->snapshot());
        $refund($plain, '3000', 'TRF-P')->expectsOutputToContain('Dicatat (refund penuh')->assertSuccessful();
        $this->assertSame([['midtrans', 'partial_refund', 'resolved', 2000, null], ['manual', 'refund', 'resolved', 3000, 'Permintaan pengguna']], $rows($plain));
        $this->assertSame(['refunded', $revoked, $moved, '2026-01-09 00:00:00'], [$this->state($plain), $this->period($plain), $this->period($plainNext), $this->ends($other)]);

        $this->assertSame($kept, [$this->state($foreign), $this->period($foreign), $this->ends($outsider)]);
        $this->assertSame(0, DB::table('payment_adjustments')->where('payment_id', $foreign->id)->count());
        $this->assertSame(2, DB::table('subscription_periods')->where('status', 'revoked')->count());
        $this->assertConsistent();
        $this->assertLogsAreSafe(['payment.adjustment', 'payment.refund_reference_conflict', 'payment.review_closed']);
    }

    public function test_midtrans_refund_after_full_manual_refund_is_recorded_once_for_review(): void
    {
        $refund = fn (object $payment, string $amount, string $reference) => $this->artisan('payments:refund',
            ['payment' => $payment->id, '--amount' => $amount, '--reason' => 'Permintaan pengguna', '--reference' => $reference, '--confirm' => true]);
        $rows = fn (object $payment) => DB::table('payment_adjustments')->where('payment_id', $payment->id)->orderBy('id')->get()
            ->map(fn ($row) => [$row->source, $row->kind, $row->status, $row->amount, $row->reason])->all();
        $user = User::factory()->create();
        [$payment, $next] = [$this->pay($user), $this->pay($user)];
        $outsider = User::factory()->create();
        $foreign = $this->pay($outsider);
        $this->travel(1)->days();
        $kept = [$this->state($foreign), $this->period($foreign), $this->ends($outsider)];

        $refund($payment, '2000', 'key-x')->expectsOutputToContain('Dicatat (refund sebagian')->assertSuccessful();
        $refund($payment, '3000', 'TRF-1')->expectsOutputToContain('Dicatat (refund penuh')->assertSuccessful();
        $premium = [['revoked', '2026-01-01 00:00:00', '2026-01-02 00:00:00', 518400], ['active', '2026-01-02 00:00:00', '2026-01-09 00:00:00', null], '2026-01-09 00:00:00'];
        $this->assertSame(['refunded', ...$premium], [$this->state($payment), $this->period($payment), $this->period($next), $this->ends($user)]);

        $note = 'Refund setelah refund penuh terdeteksi; periksa kemungkinan kelebihan refund.';
        $details = ['refund_amount' => '2000.00', 'refunds' => [['refund_key' => 'key-x', 'refund_amount' => '2000.00', 'reason' => 'Salah paket']]];
        $this->midtrans($payment, 'partial_refund', $details)->assertOk();
        $manual = [['manual', 'partial_refund', 'resolved', 2000, 'Permintaan pengguna'], ['manual', 'refund', 'resolved', 3000, 'Permintaan pengguna']];
        $this->assertSame([...$manual, ['midtrans', 'partial_refund', 'pending_review', 2000, "$note Salah paket"]], $rows($payment));
        $this->assertSame(['refunded', ...$premium], [$this->state($payment), $this->period($payment), $this->period($next), $this->ends($user)]);
        $this->actingAs($user)->get('/langganan')->assertOk()->assertSee('Sedang ditinjau');

        $once = $this->snapshot();
        $this->midtrans($payment, 'partial_refund', $details)->assertOk();
        $this->notice($payment->order_id, 'partial_refund')->assertOk();
        $this->assertSame('unchanged', app(PaymentAdjustmentService::class)->recordMidtransPartialRefund($payment, $details));
        $this->notice($payment->order_id, 'refund')->assertOk();
        $this->notice($payment->order_id, 'settlement')->assertOk();
        $this->midtrans($payment, 'partial_refund', ['refund_amount' => '2500.00', 'refunds' => [['refund_key' => 'key-x', 'refund_amount' => '2500.00']]])->assertUnprocessable();
        $this->midtrans($payment, 'partial_refund', ['refund_amount' => '3500.00', 'refunds' => [['refund_key' => 'key-z', 'refund_amount' => '3500.00']]])->assertUnprocessable();
        $refund($payment, '1', 'TRF-2')->expectsOutputToContain('paid')->assertFailed();
        $this->assertSame($once, $this->snapshot());

        $this->midtrans($payment, 'partial_refund', ['refund_amount' => '3000.00', 'refunds' => [
            ['refund_key' => 'key-x', 'refund_amount' => '2000.00'], ['refund_key' => 'key-y', 'refund_amount' => '1000.00']]])->assertOk();
        $this->assertSame([...$manual, ['midtrans', 'partial_refund', 'pending_review', 2000, "$note Salah paket"], ['midtrans', 'partial_refund', 'pending_review', 1000, $note]], $rows($payment));
        $twice = $this->snapshot();
        $this->midtrans($payment, 'partial_refund')->assertOk();
        $this->assertSame($twice, $this->snapshot());
        $this->assertSame(['refunded', ...$premium], [$this->state($payment), $this->period($payment), $this->period($next), $this->ends($user)]);
        $this->assertSame($kept, [$this->state($foreign), $this->period($foreign), $this->ends($outsider)]);
        $this->assertSame(0, DB::table('payment_adjustments')->where('payment_id', $foreign->id)->count());
        $this->assertSame(2, collect($this->logged)->where('message', 'payment.refund_after_full_refund')->count());
        $this->assertConsistent();
        $this->assertLogsAreSafe(['payment.adjustment', 'payment.refund_after_full_refund']);
    }

    public function test_partial_refund_of_a_pending_payment_is_reviewed_without_granting_premium(): void
    {
        $user = User::factory()->create();
        $paid = $this->pay($user);
        [$pending, $synced, $reconciled] = [$this->order($user), $this->order($user), $this->order($user)];
        $outsider = User::factory()->create();
        $foreign = $this->pay($outsider);
        $this->travel(1)->days();
        $kept = [$this->period($paid), $this->ends($user), $this->state($foreign), $this->period($foreign), $this->ends($outsider)];
        $current = fn () => [$this->period($paid), $this->ends($user), $this->state($foreign), $this->period($foreign), $this->ends($outsider)];
        $rows = fn (object $payment) => DB::table('payment_adjustments')->where('payment_id', $payment->id)->orderBy('id')->get()
            ->map(fn ($row) => [$row->source, $row->kind, $row->status, $row->amount, $row->reason])->all();
        $note = 'Refund sebagian pada pembayaran pending terdeteksi; Premium tidak diaktifkan sampai ditinjau.';
        $first = ['refund_key' => 'k1', 'refund_amount' => '2000.00', 'reason' => 'Salah paket'];

        $this->midtrans($pending, 'partial_refund', ['refund_amount' => '2000.00', 'refunds' => [$first]])->assertOk();
        $this->assertSame([['midtrans', 'partial_refund', 'pending_review', 2000, "$note Salah paket"]], $rows($pending));
        $this->assertSame(['pending', null], [$this->state($pending), $this->period($pending)]);
        $this->actingAs($user)->get('/langganan')->assertOk()->assertSee('Sedang ditinjau');

        $once = $this->snapshot();
        $this->midtrans($pending, 'partial_refund')->assertOk();
        $this->notice($pending->order_id, 'settlement')->assertOk();
        $this->notice($pending->order_id, 'pending')->assertOk();
        $this->assertSame('pending', app(MidtransSandboxService::class)->sync($user->id, $pending->order_id));
        $this->midtrans($pending, 'partial_refund', ['refund_amount' => '2500.00', 'refunds' => [array_replace($first, ['refund_amount' => '2500.00'])]])->assertUnprocessable();
        $this->midtrans($pending, 'partial_refund', ['refund_amount' => '2000.50', 'refunds' => []])->assertUnprocessable();
        $this->midtrans($pending, 'partial_refund', ['refund_amount' => '2000.00', 'refunds' => [['refund_amount' => '2000.00']]])->assertUnprocessable();
        $this->midtrans($pending, 'partial_refund', ['refund_amount' => '5000.00', 'refunds' => []])->assertUnprocessable();
        $this->artisan('payments:refund', ['payment' => $pending->id, '--amount' => '1000', '--reason' => 'uji', '--reference' => 'TRF-1', '--confirm' => true])
            ->expectsOutputToContain('paid')->assertFailed();
        $this->artisan('payments:review', ['payment' => $pending->id, '--restore' => true, '--confirm' => true])->expectsOutputToContain('belum pernah mengaktifkan Premium')->assertFailed();
        $this->assertSame($once, $this->snapshot());
        $this->assertSame(['unchanged' => 1], app(MidtransSandboxService::class)->reconcile($pending->order_id));
        $this->assertSame([['midtrans', 'partial_refund', 'pending_review', 2000, "$note Salah paket"]], $rows($pending));

        $this->midtrans($pending, 'partial_refund', ['refund_amount' => '3500.00', 'refunds' => []])->assertOk();
        $this->midtrans($pending, 'partial_refund', ['refund_amount' => '1000.00', 'refunds' => []])->assertOk();
        $this->midtrans($pending, 'partial_refund', ['refund_amount' => '2000.00', 'refunds' => [$first]])->assertOk();
        $this->assertSame([['midtrans', 'partial_refund', 'pending_review', 2000, "$note Salah paket"], ['midtrans', 'partial_refund', 'pending_review', 3500, $note]], $rows($pending));

        $this->gateway[$synced->order_id] = ['transaction_status' => 'partial_refund', 'refund_amount' => '1500.00', 'refunds' => [['refund_key' => 'k2', 'refund_amount' => '1500.00']]] + $this->gateway[$synced->order_id];
        $this->assertSame('pending', app(MidtransSandboxService::class)->sync($user->id, $synced->order_id));
        $this->assertSame([['midtrans', 'partial_refund', 'pending_review', 1500, $note]], $rows($synced));
        $this->gateway[$reconciled->order_id] = ['transaction_status' => 'partial_refund', 'refund_amount' => '1000.00', 'refunds' => [['refund_key' => 'k3', 'refund_amount' => '1000.00']]] + $this->gateway[$reconciled->order_id];
        $this->assertSame(['disputed' => 1], app(MidtransSandboxService::class)->reconcile($reconciled->order_id));
        $this->assertSame(['unchanged' => 1], app(MidtransSandboxService::class)->reconcile($reconciled->order_id));
        $this->gateway[$reconciled->order_id]['refunds'][0]['refund_amount'] = '1200.00';
        $this->gateway[$reconciled->order_id]['refund_amount'] = '1200.00';
        $this->assertSame(['unverified' => 1], app(MidtransSandboxService::class)->reconcile($reconciled->order_id));
        $this->assertSame([['midtrans', 'partial_refund', 'pending_review', 1000, $note]], $rows($reconciled));

        $this->midtrans($pending, 'settlement', ['refunds' => []])->assertOk();
        $this->assertSame('settlement', DB::table('payment_adjustments')->where('payment_id', $pending->id)->orderByDesc('id')->value('kind'));
        $this->assertSame(['pending', null], [$this->state($pending), $this->period($pending)]);

        $this->assertSame($kept, $current());
        $this->assertSame(['pending', 'pending', 'pending'], [$this->state($pending), $this->state($synced), $this->state($reconciled)]);
        $this->assertSame(0, DB::table('subscription_periods')->whereIn('payment_id', [$pending->id, $synced->id, $reconciled->id])->count());
        $this->assertSame(0, DB::table('payment_adjustments')->whereIn('payment_id', [$paid->id, $foreign->id])->count());
        $this->assertSame(4, collect($this->logged)->where('message', 'payment.refund_on_pending')->count());
        $this->assertConsistent();
        $this->assertLogsAreSafe(['payment.adjustment', 'payment.refund_on_pending', 'payment.reconcile_unverified']);
    }

    public function test_pending_refund_review_without_a_period_can_only_be_revoked(): void
    {
        $review = fn (object $payment, array $options) => $this->artisan('payments:review', $options + ['payment' => $payment->id]);
        $user = User::factory()->create();
        $paid = $this->pay($user);
        [$pending, $settled] = [$this->order($user), $this->order($user)];
        $tracked = $this->pay(User::factory()->create());
        DB::table('payment_adjustments')->insert(['payment_id' => $settled->id, 'kind' => 'settlement', 'source' => 'midtrans', 'reference' => 'settlement', 'status' => 'pending_review']);
        $this->travel(1)->days();
        $note = 'Refund sebagian pada pembayaran pending terdeteksi; Premium tidak diaktifkan sampai ditinjau.';
        $first = ['refund_key' => 'k1', 'refund_amount' => '2000.00'];
        $this->midtrans($pending, 'partial_refund', ['refund_amount' => '2000.00', 'refunds' => [$first]])->assertOk();
        $this->midtrans($tracked, 'partial_refund', ['refund_amount' => '1000.00', 'refunds' => [['refund_key' => 'k9', 'refund_amount' => '1000.00']]])->assertOk();
        $statuses = fn (object $payment) => DB::table('payment_adjustments')->where('payment_id', $payment->id)->orderBy('id')->pluck('status')->all();
        $premium = fn () => json_encode([DB::table('subscriptions')->orderBy('user_id')->get(), DB::table('subscription_periods')->orderBy('id')->get(),
            DB::table('payments')->orderBy('id')->pluck('status')->all()]);
        $kept = $premium();
        $ends = $this->ends($user);
        $closed = fn () => collect($this->logged)->where('message', 'payment.review_closed')->count();

        $open = $this->snapshot();
        $review($pending, ['--restore' => true])->expectsOutputToContain('belum pernah mengaktifkan Premium')->assertFailed();
        $review($pending, ['--restore' => true, '--confirm' => true])->expectsOutputToContain('belum pernah mengaktifkan Premium')->assertFailed();
        $review($pending, ['--revoke' => true])->expectsOutputToContain('DRY-RUN, belum ada perubahan. Rencana: menutup 1 peninjauan; pembayaran tetap pending tanpa periode Premium')->assertSuccessful();
        try {
            DB::transaction(function () use ($pending) {
                $this->assertTrue(app(PaymentAdjustmentService::class)->close($pending, false, true)[0]);
                throw new DomainException('batal');
            });
            $this->fail('Transaksi luar seharusnya batal.');
        } catch (DomainException) {
        }
        $this->assertSame($open, $this->snapshot());
        $this->assertSame(0, $closed());

        $review($pending, ['--revoke' => true, '--confirm' => true])
            ->expectsOutputToContain('Ditutup: 1 peninjauan; pembayaran tetap pending tanpa periode Premium. Premium tidak diaktifkan.')->assertSuccessful();
        $this->assertSame(['resolved'], $statuses($pending));
        $this->assertSame(['pending', null, $ends], [$this->state($pending), $this->period($pending), $this->ends($user)]);
        $this->assertSame($kept, $premium());
        $this->assertSame(1, $closed());

        $done = $this->snapshot();
        $review($pending, ['--revoke' => true, '--confirm' => true])->expectsOutputToContain('Tidak ada peninjauan terbuka')->assertSuccessful();
        $review($pending, ['--restore' => true, '--confirm' => true])->expectsOutputToContain('Tidak ada peninjauan terbuka')->assertSuccessful();
        $this->midtrans($pending, 'partial_refund', ['refund_amount' => '2000.00', 'refunds' => [$first]])->assertOk();
        $this->notice($pending->order_id, 'settlement')->assertOk();
        $this->assertSame($done, $this->snapshot());
        $this->assertSame(1, $closed());

        $this->midtrans($pending, 'partial_refund', ['refund_amount' => '3500.00', 'refunds' => [$first, ['refund_key' => 'k2', 'refund_amount' => '1500.00']]])->assertOk();
        $this->assertSame(['resolved', 'pending_review'], $statuses($pending));
        $this->assertSame($note, DB::table('payment_adjustments')->where('payment_id', $pending->id)->orderByDesc('id')->value('reason'));
        $review($pending, ['--restore' => true, '--confirm' => true])->expectsOutputToContain('belum pernah mengaktifkan Premium')->assertFailed();
        $review($pending, ['--revoke' => true, '--confirm' => true])->expectsOutputToContain('Ditutup: 1 peninjauan')->assertSuccessful();
        $this->assertSame(['resolved', 'resolved'], $statuses($pending));
        $this->assertSame(2, $closed());

        $review($settled, ['--revoke' => true, '--confirm' => true])->expectsOutputToContain('Tidak ada periode Premium yang tercatat')->assertFailed();
        $review($settled, ['--restore' => true, '--confirm' => true])->expectsOutputToContain('Tidak ada periode Premium yang tercatat')->assertFailed();
        $this->assertSame(['pending_review'], $statuses($settled));
        $review($tracked, ['--revoke' => true])->expectsOutputToContain('sisa Premium dari pembayaran ini dicabut')->assertSuccessful();
        $review($tracked, ['--restore' => true, '--confirm' => true])->expectsOutputToContain('Premium dari pembayaran ini dipertahankan')->assertSuccessful();
        $this->assertSame(['rejected'], $statuses($tracked));
        $this->assertSame(['active', 'pending', 'pending'], [$this->period($tracked)[0], $this->state($pending), $this->state($settled)]);
        $this->assertSame($kept, $premium());
        $this->assertSame([], DB::table('payment_adjustments')->where('payment_id', $paid->id)->pluck('id')->all());
        $this->assertConsistent();
        $this->assertLogsAreSafe(['payment.adjustment', 'payment.refund_on_pending', 'payment.review_closed']);
    }

    public function test_cumulative_snapshot_replays_do_not_reopen_closed_refund_reviews(): void
    {
        $owner = User::factory()->create();
        $pending = $this->order($owner);
        $buyer = User::factory()->create();
        $paid = $this->pay($buyer);
        $outsider = User::factory()->create();
        $foreign = $this->pay($outsider);
        $this->travel(1)->days();
        $kept = [$this->state($foreign), $this->period($foreign), $this->ends($outsider)];
        $refundLogs = fn () => collect($this->logged)->whereIn('message', ['payment.adjustment', 'payment.refund_on_pending'])->count();
        $first = ['refund_key' => 'k1', 'refund_amount' => '2000.00'];
        $second = ['refund_key' => 'k2', 'refund_amount' => '1500.00'];

        foreach ([[$pending, '--revoke'], [$paid, '--restore']] as [$payment, $decision]) {
            $premium = [$this->state($payment), $this->period($payment), $this->ends($payment === $paid ? $buyer : $owner)];
            $this->midtrans($payment, 'partial_refund', ['refund_amount' => '2000.00', 'refunds' => [$first]])->assertOk();
            $this->artisan('payments:review', ['payment' => $payment->id, $decision => true, '--confirm' => true])->expectsOutputToContain('Ditutup: 1 peninjauan')->assertSuccessful();
            $closed = $this->snapshot();
            $logs = $refundLogs();

            $this->midtrans($payment, 'partial_refund', ['refund_amount' => '2000.00', 'refunds' => []])->assertOk();
            $this->midtrans($payment, 'partial_refund', ['refund_amount' => '1500.00', 'refunds' => []])->assertOk();
            $this->midtrans($payment, 'partial_refund', ['refund_amount' => '2000.00', 'refunds' => [$first]])->assertOk();
            $this->midtrans($payment, 'partial_refund', ['refund_amount' => '2500.00', 'refunds' => [array_replace($first, ['refund_amount' => '2500.00'])]])->assertUnprocessable();
            $this->midtrans($payment, 'partial_refund', ['refund_amount' => '4000.00', 'refunds' => [$second, array_replace($first, ['refund_amount' => '2500.00'])]])->assertUnprocessable();
            $this->assertSame($closed, $this->snapshot());
            $this->assertSame($logs, $refundLogs());

            $this->midtrans($payment, 'partial_refund', ['refund_amount' => '3500.00', 'refunds' => []])->assertOk();
            $this->assertSame([['partial_refund:key:'.hash('sha256', 'k1'), 2000, $decision === '--revoke' ? 'resolved' : 'rejected'], ['partial_refund:cumulative', 3500, 'pending_review']],
                DB::table('payment_adjustments')->where('payment_id', $payment->id)->orderBy('id')->get()->map(fn ($row) => [$row->reference, $row->amount, $row->status])->all());
            $this->assertGreaterThan($logs, $refundLogs());
            $raised = $this->snapshot();
            $logs = $refundLogs();
            $this->midtrans($payment, 'partial_refund', ['refund_amount' => '3500.00', 'refunds' => []])->assertOk();
            $this->midtrans($payment, 'partial_refund', ['refund_amount' => '3000.00', 'refunds' => []])->assertOk();
            $this->assertSame($raised, $this->snapshot());
            $this->assertSame($logs, $refundLogs());

            $this->midtrans($payment, 'partial_refund', ['refund_amount' => '3500.00', 'refunds' => [$first, $second]])->assertOk();
            $this->assertSame(3, DB::table('payment_adjustments')->where('payment_id', $payment->id)->count());
            $this->assertSame($premium, [$this->state($payment), $this->period($payment), $this->ends($payment === $paid ? $buyer : $owner)]);
        }
        $this->artisan('payments:refund', ['payment' => $paid->id, '--amount' => '1501', '--reason' => 'uji', '--reference' => 'TRF-1', '--confirm' => true])
            ->expectsOutputToContain('Sisa yang dapat direfund: Rp1.500')->assertFailed();
        $this->assertNull($this->period($pending));
        $this->assertSame($kept, [$this->state($foreign), $this->period($foreign), $this->ends($outsider)]);
        $this->assertSame(0, DB::table('payment_adjustments')->where('payment_id', $foreign->id)->count());
        $this->assertConsistent();
        $this->assertLogsAreSafe(['payment.adjustment', 'payment.refund_on_pending', 'payment.review_closed']);
    }

    public function test_interleaved_webhook_refresh_and_reconcile_record_a_pending_refund_once(): void
    {
        $user = User::factory()->create();
        $pending = $this->order($user);
        $this->gateway[$pending->order_id] = ['transaction_status' => 'partial_refund', 'refund_amount' => '3500.00', 'refunds' => [
            ['refund_key' => 'k1', 'refund_amount' => '2000.00'], ['refund_key' => 'k2', 'refund_amount' => '1500.00']]] + $this->gateway[$pending->order_id];
        $this->during[$pending->order_id] = function () use ($user, $pending) {
            $this->assertSame(['disputed' => 1], app(MidtransSandboxService::class)->reconcile($pending->order_id));
            $this->assertSame('pending', app(MidtransSandboxService::class)->sync($user->id, $pending->order_id));
        };
        $this->notice($pending->order_id, 'partial_refund')->assertOk();
        $this->assertSame([2000, 1500], DB::table('payment_adjustments')->where('payment_id', $pending->id)->orderBy('id')->pluck('amount')->all());
        $this->assertSame(['pending', null, null], [$this->state($pending), $this->period($pending), $this->ends($user)]);
        $this->assertSame(1, collect($this->logged)->where('message', 'payment.refund_on_pending')->count());
        $this->assertConsistent();
    }

    public function test_interleaved_webhooks_and_manual_refund_keep_one_combined_limit(): void
    {
        $user = User::factory()->create();
        [$payment, $next] = [$this->pay($user), $this->pay($user)];
        $outsider = User::factory()->create();
        $foreign = $this->pay($outsider);
        $this->travel(1)->days();
        $kept = [$this->state($foreign), $this->period($foreign), $this->ends($outsider)];
        $this->gateway[$payment->order_id] = ['transaction_status' => 'partial_refund', 'refund_amount' => '3500.00', 'refunds' => [
            ['refund_key' => 'key-1', 'refund_amount' => '2000.00'], ['refund_key' => 'key-2', 'refund_amount' => '1500.00']]] + $this->gateway[$payment->order_id];
        $payload = ['order_id' => $payment->order_id, 'status_code' => '200', 'gross_amount' => '5000.00', 'transaction_status' => 'partial_refund',
            'signature_key' => hash('sha512', $payment->order_id.'2005000.00'.config('midtrans.sandbox.signature_secret_key'))];
        $this->during[$payment->order_id] = function () use ($payment, $payload) {
            $this->assertSame([true, 'Dicatat (refund sebagian: dicatat untuk ditinjau, masa aktif Premium tidak diubah).'],
                app(PaymentAdjustmentService::class)->manualRefund($payment->id, '2000', 'Permintaan pengguna', 'TRF-P', true));
            app(MidtransSandboxService::class)->notify($payload);
        };
        $this->notice($payment->order_id, 'partial_refund')->assertOk();

        $this->assertSame([['manual', 'partial_refund', 'resolved', 2000], ['midtrans', 'partial_refund', 'pending_review', 2000], ['midtrans', 'partial_refund', 'pending_review', 1500]],
            DB::table('payment_adjustments')->orderBy('id')->get()->map(fn ($row) => [$row->source, $row->kind, $row->status, $row->amount])->all());
        $this->assertSame(['refunded', ['revoked', '2026-01-01 00:00:00', '2026-01-02 00:00:00', 518400], ['active', '2026-01-02 00:00:00', '2026-01-09 00:00:00', null], '2026-01-09 00:00:00'],
            [$this->state($payment), $this->period($payment), $this->period($next), $this->ends($user)]);
        $this->assertSame($kept, [$this->state($foreign), $this->period($foreign), $this->ends($outsider)]);
        $this->assertSame(2, DB::table('payment_adjustments')->where('reason', 'like', 'Kelebihan refund Rp500:%')->count());
        $this->assertConsistent();
        $this->assertLogsAreSafe(['payment.adjustment', 'payment.refund_excess']);
    }

    public function test_settlement_after_an_upheld_reversal_waits_for_review(): void
    {
        $user = User::factory()->create();
        [$a, $b] = [$this->pay($user), $this->pay($user)];
        $review = fn (object $payment, array $options) => $this->artisan('payments:review', $options + ['payment' => $payment->id]);
        $this->travel(2)->days();
        $this->midtrans($a, 'deny', ['status_code' => '202'])->assertOk();
        $review($a, ['--revoke' => true, '--confirm' => true])->expectsOutputToContain('Ditutup: 1')->assertSuccessful();
        $this->assertSame('failed', $this->state($a));
        $this->assertSame(['revoked', '2026-01-01 00:00:00', '2026-01-03 00:00:00', 432000], $this->period($a));
        $this->assertSame('2026-01-10 00:00:00', $this->ends($user));

        $this->travel(1)->days();
        $this->midtrans($a, 'settlement', ['status_code' => '200'])->assertOk();
        $this->assertSame('failed', $this->state($a));
        $this->assertSame(['revoked', '2026-01-01 00:00:00', '2026-01-03 00:00:00', 432000], $this->period($a));
        $this->assertSame(['active', '2026-01-03 00:00:00', '2026-01-10 00:00:00', null], $this->period($b));
        $this->assertSame('2026-01-10 00:00:00', $this->ends($user));
        $this->assertSame([['reversal', 'resolved'], ['settlement', 'pending_review']],
            DB::table('payment_adjustments')->orderBy('id')->get()->map(fn ($row) => [$row->kind, $row->status])->all());
        $waiting = $this->snapshot();
        $this->notice($a->order_id, 'settlement')->assertOk();
        $this->actingAs($user)->post('/langganan/status', ['order_id' => $a->order_id])->assertRedirect('/langganan');
        $this->assertSame([], app(MidtransSandboxService::class)->reconcile($a->order_id));
        $this->assertSame([], app(MidtransSandboxService::class)->reconcile());
        $this->assertSame($waiting, $this->snapshot());
        $this->get('/langganan')->assertOk()->assertSee('Premium aktif')->assertSeeInOrder(['Berhasil', 'Sedang ditinjau'])->assertDontSee('Perbarui status');
        $this->assertConsistent();

        $review($a, ['--revoke' => true])->expectsOutputToContain('sudah dicabut sebelumnya')->assertSuccessful();
        $this->assertSame($waiting, $this->snapshot());
        $review($a, ['--revoke' => true, '--confirm' => true])->expectsOutputToContain('Ditutup: 1')->assertSuccessful();
        $this->assertSame('failed', $this->state($a));
        $this->assertSame(['resolved', 'rejected'], DB::table('payment_adjustments')->orderBy('id')->pluck('status')->all());
        $closed = $this->snapshot();
        $this->notice($a->order_id, 'settlement')->assertOk();
        $review($a, ['--restore' => true, '--confirm' => true])->expectsOutputToContain('Tidak ada peninjauan terbuka')->assertSuccessful();
        $this->assertSame($closed, $this->snapshot());
        $this->assertSame('2026-01-10 00:00:00', $this->ends($user));

        $c = $this->pay($user);
        $this->assertSame(['active', '2026-01-10 00:00:00', '2026-01-17 00:00:00', null], $this->period($c));
        $this->midtrans($c, 'cancel', ['status_code' => '202'])->assertOk();
        $review($c, ['--revoke' => true, '--confirm' => true])->assertSuccessful();
        $this->assertSame(['failed', '2026-01-10 00:00:00'], [$this->state($c), $this->ends($user)]);
        $this->travel(2)->days();
        $this->midtrans($c, 'settlement', ['status_code' => '200'])->assertOk();
        $this->assertSame('failed', $this->state($c));
        $this->assertSame('2026-01-10 00:00:00', $this->ends($user));
        $review($c, ['--restore' => true])->expectsOutputToContain('DRY-RUN')->assertSuccessful();
        $this->assertSame('failed', $this->state($c));
        $review($c, ['--restore' => true, '--confirm' => true])->expectsOutputToContain('Ditutup: 1')->assertSuccessful();
        $this->assertSame('paid', $this->state($c));
        $this->assertSame(['active', '2026-01-10 00:00:00', '2026-01-17 00:00:00', null], $this->period($c));
        $this->assertSame('2026-01-17 00:00:00', $this->ends($user));
        $this->assertSame(['resolved', 'resolved'], DB::table('payment_adjustments')->where('payment_id', $c->id)->orderBy('id')->pluck('status')->all());
        $restored = $this->snapshot();
        $review($c, ['--restore' => true, '--confirm' => true])->expectsOutputToContain('Tidak ada peninjauan terbuka')->assertSuccessful();
        $this->notice($c->order_id, 'settlement')->assertOk();
        $this->assertSame($restored, $this->snapshot());
        $this->assertSame(1, DB::table('subscription_periods')->where('payment_id', $c->id)->count());
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseCount('payments', 3);
        $this->assertConsistent();

        $other = User::factory()->create();
        $raced = $this->order($other);
        $payload = ['order_id' => $raced->order_id, 'status_code' => '200', 'gross_amount' => '5000.00', 'transaction_status' => 'settlement',
            'signature_key' => hash('sha512', $raced->order_id.'2005000.00'.config('midtrans.sandbox.signature_secret_key'))];
        $this->during[$raced->order_id] = fn () => app(MidtransSandboxService::class)->notify($payload);
        $this->notice($raced->order_id, 'settlement')->assertOk();
        $this->assertSame('paid', $this->state($raced));
        $this->assertSame(['active', '2026-01-06 00:00:00', '2026-01-13 00:00:00', null], $this->period($raced));
        $this->assertSame('2026-01-13 00:00:00', $this->ends($other));
        $this->assertSame(0, DB::table('payment_adjustments')->where('payment_id', $raced->id)->count());
        $this->during[$raced->order_id] = fn () => $this->assertSame('paid', app(MidtransSandboxService::class)->sync($other->id, $raced->order_id));
        $this->assertSame([], app(MidtransSandboxService::class)->reconcile($raced->order_id));
        $this->assertSame('unchanged', app(PaymentAdjustmentService::class)->record($raced, 'settlement', 'midtrans', null, 'settlement'));
        $this->assertSame('unchanged', app(PaymentAdjustmentService::class)->record($b, 'settlement', 'midtrans', null, 'settlement'));
        $this->assertSame(0, DB::table('payment_adjustments')->whereIn('payment_id', [$raced->id, $b->id])->count());
        $this->assertSame(1, DB::table('subscription_periods')->where('payment_id', $raced->id)->count());
        $this->assertConsistent();
        $this->assertLogsAreSafe(['payment.adjustment', 'payment.review_closed']);
    }

    public function test_a_new_settlement_transaction_after_a_closed_review_opens_one_new_review(): void
    {
        $review = fn (object $payment, array $options) => $this->artisan('payments:review', $options + ['payment' => $payment->id]);
        $user = User::factory()->create();
        [$a, $b, $legacy] = [$this->pay($user), $this->pay($user), $this->pay($user)];
        $outsider = User::factory()->create();
        $foreign = $this->pay($outsider);
        $this->travel(2)->days();
        $rows = fn (object $payment) => DB::table('payment_adjustments')->where('payment_id', $payment->id)->orderBy('id')->get()
            ->map(fn ($row) => [$row->kind, $row->reference, $row->status])->all();
        $closed = fn () => collect($this->logged)->where('message', 'payment.review_closed')->count();
        $adjusted = fn () => collect($this->logged)->where('message', 'payment.adjustment')->count();
        $kept = [$this->state($foreign), $this->period($foreign), $this->ends($outsider)];
        $deny = ['reversal', 'reversal:deny', 'resolved'];
        foreach ([$a, $legacy] as $payment) {
            $this->midtrans($payment, 'deny', ['status_code' => '202', 'transaction_id' => 'T1'])->assertOk();
            $review($payment, ['--revoke' => true, '--confirm' => true])->expectsOutputToContain('Ditutup: 1')->assertSuccessful();
        }
        $revoked = $this->period($a);
        $this->assertSame(['failed', 'revoked'], [$this->state($a), $revoked[0]]);
        $ends = $this->ends($user);

        $this->midtrans($a, 'settlement', ['status_code' => '200', 'transaction_id' => 'T2'])->assertOk();
        $this->assertSame([$deny, ['settlement', 'settlement:'.hash('sha256', 'T2'), 'pending_review']], $rows($a));
        $this->assertSame(['failed', $revoked, $ends], [$this->state($a), $this->period($a), $this->ends($user)]);
        $open = $this->snapshot();
        $this->notice($a->order_id, 'settlement')->assertOk();
        $this->assertSame('failed', app(MidtransSandboxService::class)->sync($user->id, $a->order_id));
        $this->assertSame([], app(MidtransSandboxService::class)->reconcile($a->order_id));
        $this->assertSame($open, $this->snapshot());

        $review($a, ['--revoke' => true, '--confirm' => true])->expectsOutputToContain('Ditutup: 1')->assertSuccessful();
        $review($a, ['--revoke' => true, '--confirm' => true])->expectsOutputToContain('Tidak ada peninjauan terbuka')->assertSuccessful();
        $this->assertSame([$deny, ['settlement', 'settlement:'.hash('sha256', 'T2'), 'rejected']], $rows($a));
        $done = $this->snapshot();
        $logs = [$adjusted(), $closed()];
        $this->notice($a->order_id, 'settlement')->assertOk();
        $this->assertSame('failed', app(MidtransSandboxService::class)->sync($user->id, $a->order_id));
        $this->gateway[$a->order_id]['transaction_id'] = 'T3';
        try {
            DB::transaction(function () use ($user, $a) {
                $this->assertSame('failed', app(MidtransSandboxService::class)->sync($user->id, $a->order_id));
                throw new DomainException('batal');
            });
            $this->fail('Transaksi luar seharusnya batal.');
        } catch (DomainException) {
        }
        $this->assertSame($done, $this->snapshot());
        $this->assertSame($logs, [$adjusted(), $closed()]);

        $this->notice($a->order_id, 'settlement')->assertOk();
        $this->notice($a->order_id, 'settlement')->assertOk();
        $this->assertSame([$deny, ['settlement', 'settlement:'.hash('sha256', 'T2'), 'rejected'], ['settlement', 'settlement:'.hash('sha256', 'T3'), 'pending_review']], $rows($a));
        $this->assertSame(['failed', $revoked, $ends], [$this->state($a), $this->period($a), $this->ends($user)]);
        $this->assertSame($logs[0] + 1, $adjusted());
        $review($a, ['--restore' => true])->expectsOutputToContain('DRY-RUN')->assertSuccessful();
        $this->assertSame('failed', $this->state($a));
        $review($a, ['--restore' => true, '--confirm' => true])->expectsOutputToContain('Ditutup: 1 peninjauan; Premium dari pembayaran ini dipulihkan')->assertSuccessful();
        $review($a, ['--restore' => true, '--confirm' => true])->expectsOutputToContain('Tidak ada peninjauan terbuka')->assertSuccessful();
        $this->assertSame(['paid', 'active'], [$this->state($a), $this->period($a)[0]]);
        $restored = $this->snapshot();
        $this->midtrans($a, 'settlement', ['status_code' => '200', 'transaction_id' => 'T4'])->assertOk();
        $this->assertSame($restored, $this->snapshot());

        $this->midtrans($legacy, 'settlement', ['status_code' => '200', 'transaction_id' => null])->assertOk();
        $review($legacy, ['--revoke' => true, '--confirm' => true])->expectsOutputToContain('Ditutup: 1')->assertSuccessful();
        $this->midtrans($legacy, 'settlement', ['status_code' => '200', 'transaction_id' => 'T5'])->assertOk();
        $this->assertSame([$deny, ['settlement', 'settlement', 'rejected']], $rows($legacy));
        $this->assertSame('failed', $this->state($legacy));

        $this->assertSame([], $rows($b));
        $this->assertSame($kept, [$this->state($foreign), $this->period($foreign), $this->ends($outsider)]);
        $this->assertConsistent();
        $this->assertLogsAreSafe(['payment.adjustment', 'payment.review_closed']);
    }

    public function test_reviews_without_a_usable_period_are_refused_and_stay_open(): void
    {
        $review = fn (object $payment, array $options) => $this->artisan('payments:review', $options + ['payment' => $payment->id]);
        $never = User::factory()->create();
        $pending = $this->order($never);
        $this->midtrans($pending, 'chargeback')->assertOk();
        $this->assertSame('chargeback', $this->state($pending));
        $this->assertNull($this->period($pending));

        $mixed = User::factory()->create();
        $untracked = $this->order($mixed, 'weekly', 'paid');
        DB::table('subscriptions')->insert(['user_id' => $mixed->id, 'plan_id' => 1, 'starts_at' => '2026-01-01 00:00:00', 'ends_at' => '2026-01-08 00:00:00']);
        $tracked = $this->pay($mixed);
        $this->midtrans($untracked, 'refund')->assertOk();
        $this->assertSame('refunded', $this->state($untracked));
        $this->assertSame(['active', '2026-01-08 00:00:00', '2026-01-15 00:00:00', null], $this->period($tracked));

        $before = $this->snapshot();
        foreach ([$pending, $untracked] as $payment) {
            foreach (['--restore', '--revoke'] as $decision) {
                $review($payment, [$decision => true])->expectsOutputToContain('Tidak ada periode Premium yang tercatat')->assertFailed();
                $review($payment, [$decision => true, '--confirm' => true])->expectsOutputToContain('Tidak ada periode Premium yang tercatat')->assertFailed();
            }
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(['pending_review', 'pending_review'], DB::table('payment_adjustments')->orderBy('id')->pluck('status')->all());
        $this->assertSame(['chargeback', 'refunded', 'paid'], DB::table('payments')->orderBy('id')->pluck('status')->all());
        $this->assertNull($this->ends($never));
        $this->assertNull(app(SubscriptionService::class)->active($never->id));
        $this->assertSame('2026-01-15 00:00:00', $this->ends($mixed));
        $this->assertNotNull(app(SubscriptionService::class)->active($mixed->id));

        $user = User::factory()->create();
        $a = $this->pay($user);
        $this->midtrans($a, 'partial_chargeback')->assertOk();
        $review($a, ['--revoke' => true, '--confirm' => true])->expectsOutputToContain('sisa Premium dari pembayaran ini dicabut')->assertSuccessful();
        $this->midtrans($a, 'partial_refund', ['refund_amount' => '1000.00', 'refunds' => []])->assertOk();
        $open = $this->snapshot();
        $review($a, ['--restore' => true])->expectsOutputToContain('sudah dicabut dan tidak dapat dipulihkan')->assertFailed();
        $review($a, ['--restore' => true, '--confirm' => true])->expectsOutputToContain('sudah dicabut dan tidak dapat dipulihkan')->assertFailed();
        $this->assertSame($open, $this->snapshot());
        $review($a, ['--revoke' => true, '--confirm' => true])->expectsOutputToContain('sudah dicabut sebelumnya')->assertSuccessful();
        $this->assertSame(['revoked', '2026-01-01 00:00:00', '2026-01-01 00:00:00', 604800], $this->period($a));
        $this->assertNull(app(SubscriptionService::class)->active($user->id));
        $this->assertSame(0, DB::table('payment_adjustments')->where('payment_id', $a->id)->where('status', 'pending_review')->count());
        $this->assertSame(['chargeback', 'refunded', 'paid', 'paid'], DB::table('payments')->orderBy('id')->pluck('status')->all());
        $this->assertSame(['pending_review', 'pending_review'], DB::table('payment_adjustments')->whereIn('payment_id', [$pending->id, $untracked->id])->orderBy('id')->pluck('status')->all());
        $this->assertSame(['active', '2026-01-08 00:00:00', '2026-01-15 00:00:00', null], $this->period($tracked));
        $this->assertSame('2026-01-15 00:00:00', $this->ends($mixed));
        $this->assertNull($this->ends($never));
        $this->assertConsistent();
        $this->assertLogsAreSafe(['payment.adjustment', 'payment.review_closed']);
    }

    public function test_adjustment_log_is_written_once_and_only_after_the_outermost_commit(): void
    {
        $adjustments = app(PaymentAdjustmentService::class);
        $user = User::factory()->create();
        [$a, $b, $c, $d, $e] = [$this->pay($user), $this->pay($user), $this->pay($user), $this->pay($user), $this->pay($user)];
        $seen = new ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $event) use ($seen) {
            if ($event->message === 'payment.adjustment') {
                $seen->append([$event->context['payment_id'], $event->context['kind'], DB::transactionLevel(),
                    DB::table('payment_adjustments')->where('payment_id', $event->context['payment_id'])->count()]);
            }
        });
        $logs = fn () => collect($this->logged)->where('message', 'payment.adjustment')->count();
        $rows = fn (object $payment) => DB::table('payment_adjustments')->where('payment_id', $payment->id)->count();

        $this->assertSame('disputed', $adjustments->record($a, 'partial_refund', 'manual', 1000, 'TRF-1', 'uji'));
        $this->assertSame([[$a->id, 'partial_refund', 0, 1]], $seen->getArrayCopy());
        $this->assertSame('unchanged', $adjustments->record($a, 'partial_refund', 'manual', 1000, 'TRF-1', 'uji'));
        $this->assertSame([1, 1], [$logs(), $rows($a)]);

        try {
            DB::transaction(function () use ($adjustments, $b, $logs, $rows) {
                $this->assertSame('refunded', $adjustments->record($b, 'refund', 'manual', 5000, 'TRF-2', 'uji'));
                $this->assertSame([1, 1], [$rows($b), $logs()]);
                throw new DomainException('batal');
            });
            $this->fail('Transaksi luar seharusnya batal.');
        } catch (DomainException) {
        }
        DB::beginTransaction();
        $this->assertSame('chargeback', $adjustments->record($b, 'chargeback', 'midtrans', null, 'chargeback'));
        DB::rollBack();
        $this->assertSame([0, 1, 'paid', 'active'], [$rows($b), $logs(), $this->state($b), $this->period($b)[0]]);

        DB::transaction(function () use ($adjustments, $c, $logs, $rows) {
            DB::transaction(function () use ($adjustments, $c, $logs) {
                $this->assertSame('chargeback', $adjustments->record($c, 'chargeback', 'midtrans', null, 'chargeback'));
                $this->assertSame(1, $logs());
            });
            $this->assertSame([1, 1], [$rows($c), $logs()]);
        });
        $this->assertSame(2, $logs());
        $this->assertSame([$c->id, 'chargeback', 0, 1], $seen[1]);

        $broken = new class extends SubscriptionService
        {
            public function withdraw(int $paymentId, string $status): bool
            {
                throw new DomainException('gagal');
            }
        };
        try {
            (new PaymentAdjustmentService($broken))->record($d, 'refund', 'midtrans', 5000, 'refund');
            $this->fail('Pencatatan seharusnya gagal.');
        } catch (DomainException) {
        }
        $this->app->instance(SubscriptionService::class, $broken);
        $this->midtrans($d, 'refund')->assertStatus(500);
        $this->assertSame([0, 2, 'paid', 'active'], [$rows($d), $logs(), $this->state($d), $this->period($d)[0]]);
        $this->assertSame(1, collect($this->logged)->where('message', 'payment.processing_failed')->count());
        $this->app->forgetInstance(SubscriptionService::class);
        $this->midtrans($d, 'refund')->assertOk();
        $this->assertSame([1, 3, 'refunded'], [$rows($d), $logs(), $this->state($d)]);
        $this->assertSame([$d->id, 'refund', 0, 1], $seen[2]);

        $this->midtrans($e, 'deny', ['status_code' => '202'])->assertOk();
        $this->artisan('payments:review', ['payment' => $e->id, '--revoke' => true, '--confirm' => true])->assertSuccessful();
        $this->assertSame([4, 'failed'], [$logs(), $this->state($e)]);
        $this->gateway[$e->order_id] = ['transaction_status' => 'settlement', 'status_code' => '200', 'gross_amount' => '5000.00'];
        $payload = ['order_id' => $e->order_id, 'status_code' => '200', 'gross_amount' => '5000.00', 'transaction_status' => 'settlement',
            'signature_key' => hash('sha512', $e->order_id.'2005000.00'.config('midtrans.sandbox.signature_secret_key'))];
        try {
            DB::transaction(function () use ($payload, $e, $logs, $rows) {
                app(MidtransSandboxService::class)->notify($payload);
                $this->assertSame([2, 4], [$rows($e), $logs()]);
                throw new DomainException('batal');
            });
            $this->fail('Transaksi luar seharusnya batal.');
        } catch (DomainException) {
        }
        $this->assertSame([1, 4], [$rows($e), $logs()]);
        $this->notice($e->order_id, 'settlement')->assertOk();
        $this->assertSame([2, 5, 'failed'], [$rows($e), $logs(), $this->state($e)]);
        $this->assertSame([$e->id, 'settlement', 0, 2], $seen[4]);
        $this->notice($e->order_id, 'settlement')->assertOk();
        $this->assertSame(5, $logs());

        $this->artisan('payments:refund', ['payment' => $a->id, '--amount' => '4000', '--reason' => 'uji', '--reference' => 'TRF-9', '--confirm' => true])->assertSuccessful();
        $this->artisan('payments:refund', ['payment' => $a->id, '--amount' => '4000', '--reason' => 'uji', '--reference' => 'TRF-9', '--confirm' => true])->assertSuccessful();
        $this->assertSame([2, 6, 'refunded'], [$rows($a), $logs(), $this->state($a)]);
        $this->assertSame([$a->id, 'refund', 0, 2], $seen[5]);
        $this->assertCount(6, $seen);
        foreach (collect($this->logged)->where('message', 'payment.adjustment') as $entry) {
            $this->assertSame(['level', 'message', 'payment_id', 'kind', 'source', 'status'], array_keys($entry));
            $this->assertSame('warning', $entry['level']);
        }
        $this->assertDoesNotMatchRegularExpression('/fixture-signature|SB-Mid|TRF-|select |insert |update |\.php|'.$a->order_id.'/i', json_encode($this->logged->getArrayCopy()));
        $this->assertConsistent();
    }

    public function test_review_logs_are_written_once_and_only_after_the_outermost_commit(): void
    {
        $adjustments = app(PaymentAdjustmentService::class);
        $gateway = app(MidtransSandboxService::class);
        $user = User::factory()->create();
        [$a, $b, $c, $d] = [$this->pay($user), $this->pay($user), $this->pay($user), $this->pay($user)];
        foreach ([$a, $b, $c, $d] as $payment) {
            $this->midtrans($payment, 'chargeback')->assertOk();
        }
        $seen = new ArrayObject;
        Event::listen(MessageLogged::class, function (MessageLogged $event) use ($seen) {
            if (in_array($event->message, ['payment.review_closed', 'payment.review'], true)) {
                $seen->append([$event->message, $event->context['payment_id'], DB::transactionLevel(),
                    DB::table('payments')->where('id', $event->context['payment_id'])->value('status')]);
            }
        });
        $logs = fn (string $message) => collect($this->logged)->where('message', $message)->count();
        $open = fn (object $payment) => DB::table('payment_adjustments')->where('payment_id', $payment->id)->where('status', 'pending_review')->count();

        $this->assertSame([true, 'Ditutup: 1 peninjauan; Premium dari pembayaran ini dipulihkan.'], $adjustments->close($a, true, true));
        $this->assertSame([['payment.review_closed', $a->id, 0, 'paid']], $seen->getArrayCopy());
        $this->assertSame([true, 'Tidak ada peninjauan terbuka untuk pembayaran ini.'], $adjustments->close($a, true, true));
        $this->assertTrue($adjustments->close($b, true, false)[0]);
        $this->assertSame([1, 1], [$logs('payment.review_closed'), $open($b)]);

        try {
            DB::transaction(function () use ($adjustments, $b, $logs, $open) {
                $this->assertTrue($adjustments->close($b, false, true)[0]);
                $this->assertSame([0, 1], [$open($b), $logs('payment.review_closed')]);
                throw new DomainException('batal');
            });
            $this->fail('Transaksi luar seharusnya batal.');
        } catch (DomainException) {
        }
        DB::beginTransaction();
        $this->assertTrue($adjustments->close($b, true, true)[0]);
        DB::rollBack();
        $this->assertSame([1, 1, 'chargeback', 'suspended'], [$open($b), $logs('payment.review_closed'), $this->state($b), $this->period($b)[0]]);

        DB::transaction(function () use ($adjustments, $c, $logs, $open) {
            DB::transaction(function () use ($adjustments, $c, $logs) {
                $this->assertTrue($adjustments->close($c, false, true)[0]);
                $this->assertSame(1, $logs('payment.review_closed'));
            });
            $this->assertSame([0, 1], [$open($c), $logs('payment.review_closed')]);
        });
        $this->assertSame(2, $logs('payment.review_closed'));
        $this->assertSame(['payment.review_closed', $c->id, 0, 'chargeback'], $seen[1]);

        $broken = new class extends SubscriptionService
        {
            public function restore(int $paymentId, bool $revoked = false): bool
            {
                throw new DomainException('gagal');
            }
        };
        try {
            (new PaymentAdjustmentService($broken))->close($d, true, true);
            $this->fail('Penutupan seharusnya gagal.');
        } catch (DomainException) {
        }
        $this->assertSame([1, 2, 'chargeback'], [$open($d), $logs('payment.review_closed'), $this->state($d)]);
        $this->artisan('payments:review', ['payment' => $b->id, '--restore' => true, '--confirm' => true])->assertSuccessful();
        $this->artisan('payments:review', ['payment' => $b->id, '--restore' => true, '--confirm' => true])->assertSuccessful();
        $this->assertSame([3, 'paid'], [$logs('payment.review_closed'), $this->state($b)]);
        $this->assertSame(['payment.review_closed', $b->id, 0, 'paid'], $seen[2]);
        foreach (collect($this->logged)->where('message', 'payment.review_closed') as $entry) {
            $this->assertSame(['level', 'message', 'payment_id', 'decision', 'closed'], array_keys($entry));
            $this->assertSame(['warning', 1], [$entry['level'], $entry['closed']]);
        }
        $this->assertSame(['restore', 'revoke', 'restore'], collect($this->logged)->where('message', 'payment.review_closed')->pluck('decision')->all());

        DB::table('subscription_plans')->insert(['code' => 'legacy', 'name' => 'Paket Lama', 'price' => 5000]);
        $other = User::factory()->create();
        [$p, $q, $r] = [$this->order($other, 'legacy'), $this->order($other, 'legacy'), $this->order($other, 'legacy')];
        $payload = fn (object $payment) => ['order_id' => $payment->order_id, 'status_code' => '200', 'gross_amount' => '5000.00', 'transaction_status' => 'settlement',
            'signature_key' => hash('sha512', $payment->order_id.'2005000.00'.config('midtrans.sandbox.signature_secret_key'))];

        $this->notice($p->order_id, 'settlement')->assertOk();
        $this->assertSame(['payment.review', $p->id, 0, 'review'], $seen[3]);
        $this->notice($p->order_id, 'settlement')->assertOk();
        $this->assertSame([1, 'review'], [$logs('payment.review'), $this->state($p)]);

        try {
            DB::transaction(function () use ($gateway, $payload, $q, $logs) {
                $gateway->notify($payload($q));
                $this->assertSame(['review', 1], [$this->state($q), $logs('payment.review')]);
                throw new DomainException('batal');
            });
            $this->fail('Transaksi luar seharusnya batal.');
        } catch (DomainException) {
        }
        $this->assertSame(['pending', 1], [$this->state($q), $logs('payment.review')]);

        DB::transaction(function () use ($gateway, $payload, $r, $logs) {
            DB::transaction(fn () => $gateway->notify($payload($r)));
            $this->assertSame(['review', 1], [$this->state($r), $logs('payment.review')]);
        });
        $this->assertSame(2, $logs('payment.review'));
        $this->assertSame(['payment.review', $r->id, 0, 'review'], $seen[4]);

        $this->assertSame(['review' => 1], $gateway->reconcile($q->order_id));
        $this->assertSame(['unchanged' => 1], $gateway->reconcile($q->order_id));
        $this->assertSame([3, 'review'], [$logs('payment.review'), $this->state($q)]);
        $this->assertSame(['payment.review', $q->id, 0, 'review'], $seen[5]);
        $this->assertCount(6, $seen);
        foreach (collect($this->logged)->where('message', 'payment.review') as $entry) {
            $this->assertSame(['level', 'message', 'payment_id', 'plan_id'], array_keys($entry));
            $this->assertSame('warning', $entry['level']);
        }
        $this->assertNull($this->ends($other));
        $this->assertSame(0, DB::table('subscription_periods')->where('user_id', $other->id)->count());
        $this->assertDoesNotMatchRegularExpression('/fixture-signature|SB-Mid|select |insert |update |\.php|'.$p->order_id.'|'.$a->order_id.'/i', json_encode($this->logged->getArrayCopy()));
        $this->assertConsistent();
    }

    public function test_migration_backfills_periods_only_when_paid_history_reproduces_the_subscription(): void
    {
        $migration = require database_path('migrations/2026_11_22_000001_create_payment_reversal_tables.php');
        $migration->down();
        [$chain, $gap, $drifted, $free] = User::factory()->count(4)->create()->all();
        $paid = function (User $user, string $code, string $at) {
            $payment = $this->order($user, $code, 'paid');
            DB::table('payments')->where('id', $payment->id)->update(['updated_at' => $at]);

            return $payment;
        };
        $weekly = $paid($chain, 'weekly', '2026-01-01 00:00:00');
        $monthly = $paid($chain, 'monthly', '2026-01-03 10:00:00');
        $old = $paid($gap, 'weekly', '2025-12-01 05:00:00');
        $new = $paid($gap, 'weekly', '2026-01-01 00:00:00');
        $lost = $paid($drifted, 'weekly', '2026-01-01 00:00:00');
        $this->order($free);
        $this->order($chain, 'weekly', 'expired');
        DB::table('subscriptions')->insert([
            ['user_id' => $chain->id, 'plan_id' => 2, 'starts_at' => '2026-01-01 00:00:00', 'ends_at' => '2026-02-08 00:00:00'],
            ['user_id' => $gap->id, 'plan_id' => 1, 'starts_at' => '2026-01-01 00:00:00', 'ends_at' => '2026-01-08 00:00:00'],
            ['user_id' => $drifted->id, 'plan_id' => 1, 'starts_at' => '2026-01-01 00:00:00', 'ends_at' => '2026-01-20 00:00:00'],
        ]);
        $tables = fn () => json_encode([DB::table('users')->orderBy('id')->get(), DB::table('payments')->orderBy('id')->get(),
            DB::table('subscriptions')->orderBy('user_id')->get(), DB::table('subscription_plans')->orderBy('id')->get()]);
        $before = $tables();

        $migration->up();
        $this->assertSame($before, $tables());
        $this->assertSame(['active', '2026-01-01 00:00:00', '2026-01-08 00:00:00', null], $this->period($weekly));
        $this->assertSame(['active', '2026-01-08 00:00:00', '2026-02-08 00:00:00', null], $this->period($monthly));
        $this->assertSame(['active', '2025-12-01 05:00:00', '2025-12-08 05:00:00', null], $this->period($old));
        $this->assertSame(['active', '2026-01-01 00:00:00', '2026-01-08 00:00:00', null], $this->period($new));
        $this->assertNull($this->period($lost));
        $this->assertDatabaseCount('subscription_periods', 4);
        $this->assertDatabaseCount('payment_adjustments', 0);
        $this->assertSame(['payment.periods_not_backfilled'], collect($this->logged)->pluck('message')->all());

        $this->travel(3)->days();
        $this->midtrans($lost, 'refund')->assertOk();
        $this->assertSame('refunded', $this->state($lost));
        $this->assertDatabaseHas('payment_adjustments', ['payment_id' => $lost->id, 'kind' => 'refund', 'status' => 'pending_review']);
        $this->assertSame('2026-01-20 00:00:00', $this->ends($drifted));
        $this->midtrans($weekly, 'refund')->assertOk();
        $this->assertSame(['revoked', '2026-01-01 00:00:00', '2026-01-04 00:00:00', 345600], $this->period($weekly));
        $this->assertSame(['active', '2026-01-04 00:00:00', '2026-02-04 00:00:00', null], $this->period($monthly));
        $this->assertSame('2026-02-04 00:00:00', $this->ends($chain));
        $this->midtrans($old, 'refund')->assertOk();
        $this->assertSame(['revoked', '2025-12-01 05:00:00', '2025-12-08 05:00:00', 0], $this->period($old));
        $this->assertSame('2026-01-08 00:00:00', $this->ends($gap));
    }
}
