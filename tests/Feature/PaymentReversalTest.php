<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MidtransSandboxService;
use App\Services\SubscriptionService;
use ArrayObject;
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
