<?php

namespace Tests\Feature;

use App\Exceptions\RecommendationOperationException;
use App\Models\User;
use App\Services\GoogleLoginService;
use App\Services\MidtransSandboxService;
use App\Services\RecommendationOperationService;
use App\Services\SubscriptionService;
use App\Support\SqliteContention;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDOException;
use RuntimeException;
use Tests\Support\GeminiFixture;
use Throwable;

class PremiumSubscriptionTest extends TestCase
{
    use GeminiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate')->assertSuccessful();
        Http::preventStrayRequests();
        config(['midtrans.is_production' => false, 'midtrans.signature_secret_key' => 'fixture-signature', 'midtrans.server_key' => 'SB-Mid-server-fixture', 'midtrans.client_key' => 'SB-Mid-client-fixture',
            'ai.gemini.api_key' => 'fake-only']);
    }

    public function test_checkout_and_notifications_are_verified_and_applied_once(): void
    {
        $this->travelTo(Carbon::parse('2026-01-30 18:00 UTC')); // Jan 31 in Jakarta.
        $user = User::factory()->create();
        $this->postJson('/langganan/checkout', ['plan' => 'monthly'])->assertUnauthorized();
        $this->actingAs($user);
        $this->postJson('/langganan/checkout', ['plan' => 'invalid'])->assertUnprocessable();
        $state = [];
        Http::fake(function ($request) use (&$state) {
            if ($request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions') {
                $this->assertSame(15000, $request['transaction_details']['gross_amount']);

                return Http::response(['token' => 'fixture-token']);
            }
            $this->assertSame('https://api.sandbox.midtrans.com/v2/'.$state['order_id'].'/status', $request->url());

            return Http::response($state);
        });

        $buy = function () {
            $response = $this->post('/langganan/checkout', ['plan' => 'monthly', 'price' => 1])->assertStatus(303);
            $this->get($response->headers->get('Location'))->assertOk()->assertSee('fixture-token')->assertDontSee('SB-Mid-server-');

            return DB::table('payments')->latest('id')->value('order_id');
        };
        $order = $buy();
        $this->actingAs(User::factory()->create())->get('/langganan?bayar='.$order)
            ->assertOk()->assertDontSee('fixture-token')->assertSee('Belum ada pembayaran');
        $this->actingAs($user);
        $notice = function ($status, $code, $fraud = 'accept') use (&$state, &$order) {
            $state = ['order_id' => $order, 'gross_amount' => '15000.00', 'status_code' => $code,
                'transaction_status' => $status, 'fraud_status' => $fraud];

            return $state + ['signature_key' => hash('sha512', $order.$code.'15000.00'.config('midtrans.signature_secret_key'))];
        };
        $url = '/api/midtrans/notification';
        $this->postJson($url, array_merge($notice('settlement', '200'), ['signature_key' => str_repeat('0', 128)]))->assertForbidden();
        $this->postJson($url, array_merge($state, ['gross_amount' => '1.00', 'signature_key' => hash('sha512', $order.'2001.00'.config('midtrans.signature_secret_key'))]))->assertUnprocessable();
        foreach (['pending' => 'pending', 'deny' => 'failed', 'expire' => 'expired'] as $gateway => $expected) {
            $this->postJson($url, $notice($gateway, '201'))->assertOk();
            $this->assertDatabaseHas('payments', ['order_id' => $order, 'status' => $expected]);
            $this->assertDatabaseCount('subscriptions', 0);
            $order = $buy();
        }
        $message = $notice('capture', '200', 'challenge');
        $this->postJson($url, array_merge($message, ['fraud_status' => 'accept']))->assertOk();
        $this->assertDatabaseCount('subscriptions', 0);
        $message = $notice('settlement', '200');
        $state['gross_amount'] = '1.00';
        $this->postJson($url, $message)->assertUnprocessable();
        $this->assertDatabaseCount('subscriptions', 0);
        $message = $notice('settlement', '200');
        $this->postJson($url, $message)->assertOk();
        $this->postJson($url, $message)->assertOk();
        $this->postJson($url, $notice('pending', '201'))->assertOk();
        $this->assertDatabaseHas('payments', ['order_id' => $order, 'status' => 'paid']);
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'ends_at' => '2026-02-27 18:00:00']);
        $order = $buy();
        $this->postJson($url, $notice('capture', '200'))->assertOk();
        $this->assertDatabaseHas('subscriptions', ['ends_at' => '2026-03-27 18:00:00']);
        $this->travelTo(Carbon::parse('2026-04-01 12:00 UTC'));
        $this->getJson('/riwayat')->assertForbidden();
        $order = $buy();
        $this->postJson($url, $notice('settlement', '200'))->assertOk();
        $this->assertDatabaseHas('subscriptions', ['starts_at' => '2026-04-01 12:00:00', 'ends_at' => '2026-05-01 12:00:00']);
        $this->get('/langganan')->assertOk()->assertSee('Premium aktif');
        $this->actingAs(User::factory()->create())->get('/langganan?bayar='.$order)->assertDontSee('fixture-token')->assertSee('Belum ada pembayaran');
    }

    public function test_weekly_checkout_and_sandbox_configuration_guards(): void
    {
        $this->actingAs(User::factory()->create());
        Http::fake(['https://app.sandbox.midtrans.com/snap/v1/transactions' => Http::response(['token' => 'weekly-fixture'])]);
        $this->post('/langganan/checkout', ['plan' => 'weekly', 'price' => 1])->assertStatus(303);
        Http::assertSent(fn ($request) => $request['transaction_details']['gross_amount'] === 5000);
        $this->assertDatabaseHas('payments', ['amount' => 5000, 'status' => 'pending']);
        $order = DB::table('payments')->value('order_id');
        $notice = ['order_id' => $order, 'status_code' => '200', 'gross_amount' => '5000.00', 'transaction_status' => 'settlement'];
        $notice['signature_key'] = hash('sha512', $order.'2005000.00'.config('midtrans.server_key'));
        $this->postJson('/api/midtrans/notification', $notice)->assertForbidden();
        config(['midtrans.client_key' => 'Mid-client-fixture']);
        $this->get('/langganan?bayar='.$order)->assertOk()->assertSee('weekly-fixture')
            ->assertSee('data-client-key="Mid-client-fixture"', false);
        config(['midtrans.client_key' => 'invalid-format']);
        $this->get('/langganan?bayar='.$order)->assertOk()->assertDontSee('weekly-fixture')->assertDontSee('invalid-format');
        $this->postJson('/langganan/checkout', ['plan' => 'weekly'])->assertServiceUnavailable();
        config(['midtrans.client_key' => 'SB-Mid-client-fixture', 'midtrans.is_production' => true]);
        $this->postJson('/langganan/checkout', ['plan' => 'weekly'])->assertServiceUnavailable();
        $this->postJson('/api/midtrans/notification', $notice)->assertServiceUnavailable();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_status_sync_is_owner_only_verified_and_idempotent(): void
    {
        $this->travelTo(Carbon::parse('2026-01-30 18:00 UTC'));
        $user = User::factory()->create();
        $state = null;
        Http::fake(function ($request) use (&$state) {
            if ($request->url() === 'https://app.sandbox.midtrans.com/snap/v1/transactions') {
                return Http::response(['token' => 'sync-fixture']);
            }

            return Http::response(...$state);
        });
        $this->actingAs($user)->post('/langganan/checkout', ['plan' => 'monthly'])->assertStatus(303);
        $order = DB::table('payments')->value('order_id');
        $sync = fn () => $this->post('/langganan/status', ['order_id' => $order]);

        $this->actingAs(User::factory()->create());
        $sync()->assertNotFound();
        $this->post('/langganan/status', ['order_id' => 'bukan-uuid'])->assertSessionHasErrors('order_id');
        $this->actingAs($user);
        $this->get('/langganan')->assertSee(route('subscription.sync'), false);

        $state = [['status_code' => '404', 'status_message' => "Transaction doesn't exist."], 404];
        $sync()->assertRedirect('/langganan')->assertSessionHas('status_type', 'info');
        $paid = ['order_id' => $order, 'gross_amount' => '15000.00', 'currency' => 'IDR', 'status_code' => '200', 'transaction_status' => 'settlement', 'fraud_status' => 'accept'];
        foreach ([['gross_amount' => '1.00'], ['currency' => 'USD'], ['order_id' => (string) Str::uuid()], ['transaction_status' => 'refund']] as $tampered) {
            $state = [array_merge($paid, $tampered)];
            $sync()->assertSessionHas('status_type', 'error');
        }
        $state = [[], 500];
        $sync()->assertSessionHas('status_type', 'error');
        $state = [array_merge($paid, ['status_code' => '201', 'transaction_status' => 'pending'])];
        $sync()->assertSessionHas('status_type', 'info');
        $state = [array_merge($paid, ['transaction_status' => 'capture', 'fraud_status' => 'challenge'])];
        $sync()->assertSessionHas('status_type', 'info');
        $this->assertDatabaseHas('payments', ['order_id' => $order, 'status' => 'pending']);
        $this->assertDatabaseCount('subscriptions', 0);
        $this->travel(2)->minutes();

        $state = [$paid];
        $sync()->assertSessionHas('status_type', 'success');
        $sync()->assertSessionHas('status_type', 'success');
        $state = [array_merge($paid, ['status_code' => '202', 'transaction_status' => 'expire'])];
        $sync();
        $this->assertDatabaseHas('payments', ['order_id' => $order, 'status' => 'paid']);
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'ends_at' => '2026-02-27 18:02:00']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'midtrans.com') && ! str_contains($request->url(), 'sandbox'));
    }

    public function test_payment_failures_return_safe_responses_and_keep_state(): void
    {
        Exceptions::fake();
        config(['logging.default' => 'null', 'app.debug' => false]);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = ['level' => $event->level, 'message' => $event->message] + $event->context;
        });
        $user = User::factory()->create();
        $state = null;
        Http::fake(function ($request) use (&$state) {
            return str_contains($request->url(), '/snap/v1/transactions') ? Http::response(['token' => 'failure-fixture']) : Http::response($state);
        });
        $subscriptions = new class extends SubscriptionService
        {
            public ?Throwable $failure = null;

            public function extend(object $payment): void
            {
                if ($this->failure) {
                    throw $this->failure;
                }
                parent::extend($payment);
            }
        };
        $this->app->instance(SubscriptionService::class, $subscriptions);
        $this->actingAs($user)->post('/langganan/checkout', ['plan' => 'weekly'])->assertStatus(303);
        $order = DB::table('payments')->value('order_id');
        $state = ['order_id' => $order, 'gross_amount' => '5000.00', 'currency' => 'IDR', 'status_code' => '200', 'transaction_status' => 'settlement', 'fraud_status' => 'accept'];
        $notice = json_encode($state + ['signature_key' => hash('sha512', $order.'2005000.00'.config('midtrans.signature_secret_key'))]);
        $webhook = fn () => $this->call('POST', '/api/midtrans/notification', [], [], [], ['CONTENT_TYPE' => 'application/json'], $notice);
        $sync = fn () => $this->post('/langganan/status', ['order_id' => $order])->assertRedirect('/langganan');

        $locked = new PDOException('database is locked');
        $locked->errorInfo = ['HY000', 5, 'database is locked'];
        $subscriptions->failure = new QueryException('sqlite', 'update', [], $locked);
        $webhook()->assertStatus(503)->assertHeader('Retry-After', '2')
            ->assertJsonPath('message', 'Layanan pembayaran sedang sibuk. Tunggu sebentar lalu coba kembali.');
        $sync()->assertSessionHas('status_type', 'warning');
        DB::enableQueryLog();
        $page = SqliteContention::response(Request::create('/langganan/status', 'POST'));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame([503, '2'], [$page->getStatusCode(), $page->headers->get('Retry-After')]);
        $this->assertStringContainsString('<h1 class="section-title">Pembayaran sedang sibuk</h1>', $page->getContent());
        $this->assertStringContainsString('href="'.route('subscription.show').'"', $page->getContent());
        $this->assertStringNotContainsString('Pembayaran', SqliteContention::response(Request::create('/rekomendasi', 'POST'))->getContent());
        $subscriptions->failure = new RuntimeException('fixture');
        $webhook()->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
        $sync()->assertSessionHas('status_type', 'error');
        $subscriptions->failure = null;
        DB::statement("CREATE TRIGGER deny_subscription BEFORE INSERT ON subscriptions BEGIN SELECT RAISE(ABORT, 'fixture-denied'); END");
        $webhook()->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
        $this->travel(11)->minutes();
        $this->assertSame(['error' => 1], app(MidtransSandboxService::class)->reconcile());
        DB::statement('DROP TRIGGER deny_subscription');
        Exceptions::assertNotReported(RuntimeException::class);
        Exceptions::assertNotReported(fn (QueryException $exception) => ! SqliteContention::isBusy($exception));
        $failures = array_values(array_filter($logged, fn (array $entry) => $entry['message'] === 'payment.processing_failed'));
        $this->assertSame(['webhook', 'sync', 'webhook', 'reconcile'], array_column($failures, 'operation'));
        $this->assertSame(['unexpected', 'unexpected', 'database', 'database'], array_column($failures, 'category'));
        $this->assertSame([['error'], [DB::table('payments')->value('id')]], [array_values(array_unique(array_column($failures, 'level'))), array_values(array_unique(array_column($failures, 'payment_id')))]);
        $this->assertSame([QueryException::class, '23000', 19, SubscriptionService::class.'::extend'], [$failures[2]['exception'], $failures[2]['sqlstate'], $failures[2]['driver_code'], Str::beforeLast($failures[2]['origin'], ':')]);
        $this->assertDoesNotMatchRegularExpression('/insert into|subscriptions|fixture|:memory:|\.php|#\d|'.preg_quote(base_path(), '/').'/', json_encode($failures, JSON_UNESCAPED_SLASHES));
        $this->assertDatabaseHas('payments', ['order_id' => $order, 'status' => 'pending']);
        $this->assertDatabaseCount('subscriptions', 0);

        $webhook()->assertOk();
        $this->assertDatabaseHas('payments', ['order_id' => $order, 'status' => 'paid']);
        $sent = Http::recorded()->count();
        $webhook()->assertOk();
        $sync()->assertSessionHas('status_type', 'success');
        Http::assertSentCount($sent);
        $this->assertDatabaseCount('subscriptions', 1);
    }

    public function test_reconciliation_and_unknown_plan_review_activate_once(): void
    {
        config(['logging.default' => 'null']);
        $this->travelTo(Carbon::parse('2026-01-10 00:00 UTC'));
        $user = User::factory()->create();
        $states = [];
        Http::fake(function ($request) use (&$states) {
            $order = explode('/', $request->url())[4];

            return isset($states[$order]) ? Http::response($states[$order]) : Http::response(['status_code' => '404'], 404);
        });
        $weekly = DB::table('subscription_plans')->where('code', 'weekly')->value('id');
        $legacy = DB::table('subscription_plans')->insertGetId(['code' => 'legacy', 'name' => 'Paket Lama', 'price' => 5000]);
        $payment = function (int $plan, string $status = 'pending') use ($user) {
            $order = (string) Str::uuid();
            DB::table('payments')->insert(['order_id' => $order, 'user_id' => $user->id, 'plan_id' => $plan, 'amount' => 5000, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

            return $order;
        };
        $settled = fn (string $order) => ['order_id' => $order, 'gross_amount' => '5000.00', 'currency' => 'IDR', 'status_code' => '200', 'transaction_status' => 'settlement', 'fraud_status' => 'accept'];
        $notify = fn (string $order) => $this->postJson('/api/midtrans/notification', $settled($order) + ['signature_key' => hash('sha512', $order.'2005000.00'.config('midtrans.signature_secret_key'))]);
        $status = fn (string $order) => DB::table('payments')->where('order_id', $order)->value('status');
        $reconcile = fn () => app(MidtransSandboxService::class)->reconcile();
        [$review, $lunas, $kosong, $gagal, $lewat] = [$payment($legacy), $payment($weekly), $payment($weekly), $payment($weekly, 'failed'), $payment($weekly, 'expired')];
        $states = [$review => $settled($review), $lunas => $settled($lunas), $gagal => $settled($gagal), $lewat => $settled($lewat)];

        $notify($review)->assertOk();
        $this->assertSame('review', $status($review));
        $this->assertDatabaseCount('subscriptions', 0);
        $this->actingAs($user)->get('/langganan')->assertOk()->assertSee('Sedang ditinjau');
        $this->assertSame([], $reconcile());

        $this->travel(11)->minutes();
        $this->assertEquals(['review' => 1, 'paid' => 1, 'unrecorded' => 1], $reconcile());
        $this->assertSame(['review', 'paid', 'pending', 'failed', 'expired'], array_map($status, [$review, $lunas, $kosong, $gagal, $lewat]));
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'ends_at' => '2026-01-17 00:11:00']);
        $this->assertSame([], $reconcile());
        Http::assertSentCount(3);

        DB::table('payments')->where('order_id', $review)->update(['plan_id' => $weekly]);
        $this->travel(11)->minutes();
        $this->assertEquals(['paid' => 1, 'unrecorded' => 1], $reconcile());
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'ends_at' => '2026-01-24 00:11:00']);
        $this->artisan('payments:reconcile', ['--order' => $review])->expectsOutputToContain('Tidak ada pembayaran')->assertSuccessful();
        $this->artisan('payments:reconcile', ['--order' => 'bukan-uuid'])->assertFailed();

        $this->travelTo(Carbon::parse('2026-01-12 01:00 UTC'));
        $this->assertEquals(['expired' => 1], $reconcile());
        $this->assertSame('expired', $status($kosong));
        $states[$kosong] = $settled($kosong);
        $notify($kosong)->assertOk();
        $this->assertSame('paid', $status($kosong));
        $this->assertDatabaseCount('subscriptions', 1);
        $this->assertDatabaseHas('subscriptions', ['user_id' => $user->id, 'ends_at' => '2026-01-31 00:11:00']);
        $this->assertSame(['failed', 'expired'], array_map($status, [$gagal, $lewat]));
    }

    public function test_failed_generation_retry_replay_and_wib_daily_reset(): void
    {
        $this->travelTo(Carbon::parse('2026-11-20 16:59 UTC'));
        $user = User::factory()->create();
        $this->post('/masuk', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
        $this->get('/favorit')->assertForbidden();
        $this->deleteJson('/riwayat')->assertForbidden();
        $data = ['ingredients' => ['telur', 'air'], 'idempotency_key' => (string) Str::uuid()];
        $invalid = $this->recipeData();
        $invalid['recipes'] = [];
        $this->fakeProvider(['*' => Http::response($this->geminiBody($invalid))]);
        $this->postJson('/rekomendasi', $data)->assertStatus(503);
        $this->assertDatabaseHas('daily_usages', ['successful_count' => 0]);

        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $url = $this->post('/rekomendasi', $data)->assertStatus(303)->headers->get('Location');
        $this->assertCount(3, $this->get($url)->assertOk()->viewData('snapshot')['recipes']);
        $this->post('/rekomendasi', $data)->assertRedirect($url);
        $data['idempotency_key'] = (string) Str::uuid();
        $this->postJson('/rekomendasi', $data)->assertStatus(429)->assertJsonFragment(['account_url' => route('pricing')]);
        $this->assertDatabaseCount('recipe_searches', 0);
        $this->travel(1)->minute();
        $this->post('/rekomendasi', $data)->assertStatus(303);
        $this->assertDatabaseHas('daily_usages', ['usage_date' => '2026-11-20', 'successful_count' => 1]);
        $this->assertDatabaseHas('daily_usages', ['usage_date' => '2026-11-21', 'successful_count' => 1]);
    }

    public function test_google_callback_starts_a_free_session_and_guest_keeps_three_searches(): void
    {
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $this->withCredentials()->withCookie('dapur_guest', str_repeat('a', 64));
        foreach (range(1, 3) as $number) {
            $this->post('/rekomendasi', ['ingredients' => ['telur', 'air'], 'idempotency_key' => (string) Str::uuid()])->assertStatus(303);
        }
        $this->postJson('/rekomendasi', ['ingredients' => ['telur', 'air'], 'idempotency_key' => (string) Str::uuid()])->assertStatus(429);
        $this->getJson('/favorit')->assertUnauthorized();
        $this->assertDatabaseCount('daily_usages', 0);
        $user = User::factory()->create();
        $this->mock(GoogleLoginService::class, function ($mock) use ($user) {
            $mock->shouldReceive('configured')->once()->andReturn(true);
            $mock->shouldReceive('resolveUser')->once()->andReturn($user);
        });
        $this->withSession(['state' => 'fixture-state', 'google_oauth_started_at' => now()->timestamp])
            ->get('/auth/google/callback?state=fixture-state&code=fixture')->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($user);
        $this->get('/langganan')->assertOk()->assertSee('Akun Free');
        $this->assertDatabaseHas('guest_quotas', ['successful_count' => 3]);
    }

    public function test_weekly_expiry_and_feature_rollback_preserve_existing_accounts(): void
    {
        $user = User::factory()->create();
        $this->travelTo(Carbon::parse('2026-03-01 03:00 UTC'));
        $this->actingAs($user);
        $order = (string) Str::uuid();
        DB::table('payments')->insert(['user_id' => $user->id, 'plan_id' => 1, 'order_id' => $order, 'amount' => 5000]);
        $payload = ['status_code' => '200', 'gross_amount' => '5000.00', 'transaction_status' => 'settlement',
            'order_id' => $order, 'signature_key' => hash('sha512', $order.'2005000.00'.config('midtrans.signature_secret_key'))];
        Http::fake(["https://api.sandbox.midtrans.com/v2/{$order}/status" => Http::response($payload)]);
        $this->postJson('/api/midtrans/notification', $payload)->assertOk();
        $this->assertDatabaseHas('subscriptions', ['ends_at' => '2026-03-08 03:00:00']);
        $this->travel(7)->days();
        $this->getJson('/riwayat')->assertForbidden();
        $path = 'database/migrations/2026_11_21_000001_create_premium_tables.php';
        $this->artisan('migrate:rollback', ['--path' => $path, '--step' => 1])->assertSuccessful();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $this->assertFalse(Schema::hasTable('payments'));
        $this->artisan('migrate', ['--path' => $path])->assertSuccessful();
        $this->assertDatabaseCount('subscription_plans', 2);
    }

    public function test_outstanding_lease_claims_last_premium_slot_and_expiry_preserves_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        DB::table('subscriptions')->insert(['user_id' => $user->id, 'plan_id' => 1, 'starts_at' => now(), 'ends_at' => now()->addWeek()]);
        DB::table('daily_usages')->insert(['user_id' => $user->id, 'usage_date' => now('Asia/Jakarta')->toDateString(), 'successful_count' => 29]);
        $operations = app(RecommendationOperationService::class);
        $request = Request::create('/', 'POST', ['idempotency_key' => (string) Str::uuid()]);
        $request->setUserResolver(fn () => $user);
        $input = ['ingredients' => ['telur', 'air']];
        $lease = $operations->reserve($request, $input);
        $request->merge(['idempotency_key' => (string) Str::uuid()]);
        try {
            $operations->reserve($request, $input);
            $this->fail('A second operation claimed the last slot.');
        } catch (RecommendationOperationException $e) {
            $this->assertSame(429, $e->status);
        }
        $operations->release($lease);
        $this->fakeProvider(['*' => Http::response($this->geminiBody())]);
        $url = $this->post('/rekomendasi', $input + ['idempotency_key' => (string) Str::uuid()])->assertStatus(303)->headers->get('Location');
        $snapshot = $this->get($url)->assertOk()->viewData('snapshot');
        $this->post(route('favorites.store', [$snapshot['id'], $snapshot['recipes'][0]['id']]))->assertRedirect();
        $this->assertDatabaseHas('daily_usages', ['successful_count' => 30]);
        $this->postJson('/rekomendasi', $input + ['idempotency_key' => (string) Str::uuid()])->assertStatus(429);
        $this->get('/riwayat')->assertOk();
        $this->travel(8)->days();
        $this->get('/favorit')->assertForbidden();
        $this->deleteJson('/riwayat')->assertForbidden();
        $this->assertDatabaseCount('recipe_searches', 1);
        $this->assertDatabaseCount('saved_recipes', 1);
    }
}
