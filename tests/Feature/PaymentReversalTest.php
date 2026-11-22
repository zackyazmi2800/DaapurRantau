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
        config(['midtrans.is_production' => false, 'midtrans.signature_secret_key' => 'fixture-signature', 'midtrans.server_key' => 'SB-Mid-server-fixture',
            'midtrans.client_key' => 'SB-Mid-client-fixture', 'logging.default' => 'null', 'app.debug' => false]);
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
        $payload['signature_key'] ??= hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].config('midtrans.signature_secret_key'));

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
            $this->assertSame([], array_diff(array_keys($entry), ['level', 'message', 'payment_id', 'kind', 'source', 'status', 'decision', 'closed', 'user_id']));
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

    public function test_partial_refund_opens_a_review_without_changing_premium(): void
    {
        $user = User::factory()->create();
        [$a, $b] = [$this->pay($user), $this->pay($user)];
        $this->travel(2)->days();
        $this->midtrans($a, 'partial_refund', ['refund_amount' => '2000.00', 'refunds' => [['refund_key' => 'k1', 'refund_amount' => '2000.00', 'reason' => 'Salah paket']]])->assertOk();
        $this->assertSame('paid', $this->state($a));
        $this->assertDatabaseHas('payment_adjustments', ['payment_id' => $a->id, 'kind' => 'partial_refund', 'source' => 'midtrans', 'status' => 'pending_review',
            'amount' => 2000, 'reference' => 'partial_refund:2000', 'reason' => 'Salah paket']);
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
        $this->midtrans($a, 'partial_refund', ['refund_amount' => '3500.00'])->assertOk();
        $this->assertSame(['partial_refund:2000', 'partial_refund:3500'], DB::table('payment_adjustments')->orderBy('id')->pluck('reference')->all());
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
        $this->midtrans($pending, 'partial_refund', ['refund_amount' => '1000.00'])->assertUnprocessable();
        $this->assertSame('pending', $this->state($pending));
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
            'signature_key' => hash('sha512', $raced->order_id.'2005000.00'.config('midtrans.signature_secret_key'))];
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
        $this->midtrans($a, 'partial_refund', ['refund_amount' => '1000.00'])->assertOk();
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
            'signature_key' => hash('sha512', $e->order_id.'2005000.00'.config('midtrans.signature_secret_key'))];
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
            'signature_key' => hash('sha512', $payment->order_id.'2005000.00'.config('midtrans.signature_secret_key'))];

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
