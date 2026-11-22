<?php

namespace App\Http\Controllers;

use App\Http\Requests\CheckoutRequest;
use App\Http\Requests\MidtransNotificationRequest;
use App\Http\Requests\PaymentStatusRequest;
use App\Services\MidtransSandboxService;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubscriptionController extends Controller
{
    public function pricing(MidtransSandboxService $gateway)
    {
        return view('subscription.pricing', ['checkoutReady' => $gateway->ready(), 'plans' => DB::table('subscription_plans')->orderBy('price')->get()]);
    }

    public function show(Request $request, SubscriptionService $subscriptions, MidtransSandboxService $gateway)
    {
        $userId = $request->user()->id;
        $payments = DB::table('payments')->join('subscription_plans', 'subscription_plans.id', '=', 'payments.plan_id')
            ->where('payments.user_id', $userId)->orderByDesc('payments.id')
            ->paginate(10, ['payments.order_id', 'payments.amount', 'payments.status', 'payments.created_at', 'subscription_plans.name']);
        $order = $request->query('bayar');
        $checkout = is_string($order) && $gateway->ready() ? DB::table('payments')->where(['order_id' => $order, 'user_id' => $userId, 'status' => 'pending'])->value('snap_token') : null;

        return view('subscription.show', [
            'active' => $subscriptions->active($userId), 'payments' => $payments, 'checkout' => $checkout,
            'used' => DB::table('daily_usages')->where('user_id', $userId)->where('usage_date', now('Asia/Jakarta')->toDateString())->value('successful_count') ?? 0,
        ]);
    }

    public function checkout(CheckoutRequest $request, MidtransSandboxService $gateway)
    {
        $order = $gateway->checkout($request->user()->id, $request->validated('plan'));

        return redirect()->route('subscription.show', ['bayar' => $order], 303);
    }

    public function sync(PaymentStatusRequest $request, MidtransSandboxService $gateway)
    {
        $status = $gateway->sync($request->user()->id, $request->validated('order_id'));
        [$message, $type] = match ($status) {
            'paid' => ['Pembayaran terverifikasi. Premium aktif.', 'success'],
            'pending' => ['Pembayaran masih menunggu penyelesaian.', 'info'],
            'failed' => ['Pembayaran gagal atau dibatalkan.', 'warning'],
            'expired' => ['Pembayaran telah kedaluwarsa.', 'warning'],
            null => ['Transaksi belum tercatat di Midtrans. Selesaikan pembayaran terlebih dahulu.', 'info'],
            default => ['Status pembayaran belum dapat diperiksa. Silakan coba kembali.', 'error'],
        };

        return redirect()->route('subscription.show')->with(['status' => $message, 'status_type' => $type]);
    }

    public function notification(MidtransNotificationRequest $request, MidtransSandboxService $gateway)
    {
        $gateway->notify($request->validated());

        return response()->json(['ok' => true]);
    }
}
