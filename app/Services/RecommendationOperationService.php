<?php

namespace App\Services;

use App\Exceptions\RecommendationOperationException;
use App\Models\GuestQuota;
use App\Models\RecommendationOperation;
use App\Models\User;
use App\Support\SqliteContention;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecommendationOperationService
{
    private const LEASE_SECONDS = 600;

    public function __construct(private SearchHistoryService $history) {}

    public function remaining(Request $request): int
    {
        $hash = $request->attributes->get('guest_identity_hash');
        $used = is_string($hash) ? GuestQuota::where('identity_hash', $hash)->value('successful_count') : 0;

        return max(0, 3 - (int) $used);
    }

    public function owned(Request $request)
    {
        if ($request->user()) {
            return RecommendationOperation::where('user_id', $request->user()->getAuthIdentifier())->whereNull('guest_quota_id');
        }
        $hash = $request->attributes->get('guest_identity_hash');
        abort_unless(is_string($hash) && strlen($hash) === 64, 403);

        return RecommendationOperation::whereNull('user_id')->whereIn('guest_quota_id', GuestQuota::where('identity_hash', $hash)->select('id'));
    }

    public function reserve(Request $request, array $input): RecommendationOperation
    {
        $key = $request->input('idempotency_key');
        if (! is_string($key) || ! \Illuminate\Support\Str::isUuid($key)) {
            throw new RecommendationOperationException('Kunci pencarian tidak valid. Buka kembali form pencarian.', 422);
        }
        $key = hash('sha256', strtolower($key));
        $hash = $this->inputHash($input);

        return $this->transaction(function () use ($request, $key, $hash) {
            $user = $request->user()?->getAuthIdentifier();
            $guest = null;
            if ($user !== null) {
                // First statement acquires a write lock even under SQLite's deferred transactions.
                User::whereKey($user)->toBase()->update(['id' => DB::raw('id')]);
            } else {
                $identity = $request->attributes->get('guest_identity_hash');
                abort_unless(is_string($identity) && strlen($identity) === 64, 403);
                GuestQuota::insertOrIgnore(['identity_hash' => $identity, 'successful_count' => 0, 'reserved_count' => 0, 'created_at' => now(), 'updated_at' => now()]);
                GuestQuota::where('identity_hash', $identity)->update(['updated_at' => now()]);
                $guest = GuestQuota::where('identity_hash', $identity)->firstOrFail();
            }
            $scope = $this->owned($request);
            // Recover crashed workers lazily. Expired leases can never commit later.
            $expired = (clone $scope)->where('status', 'reserved')->where('lease_expires_at', '<=', now())
                ->update(['status' => 'expired', 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
            if ($guest && $expired) {
                GuestQuota::whereKey($guest->id)->decrement('reserved_count', $expired);
                $guest->refresh();
            }
            $operation = (clone $scope)->where('idempotency_key', $key)->first();
            if ($operation && ! hash_equals($operation->input_hash, $hash)) {
                throw new RecommendationOperationException('Kunci pencarian sudah dipakai untuk bahan atau pilihan berbeda. Mulai pencarian baru.');
            }
            if ($operation?->status === 'succeeded') {
                if (! $operation->result_payload || $operation->result_expires_at->isPast()) {
                    throw new RecommendationOperationException('Hasil pencarian ini telah kedaluwarsa. Kuota tidak dipotong lagi.', 410);
                }

                return $operation;
            }
            if ($operation?->status === 'reserved') {
                throw new RecommendationOperationException('Pencarian ini masih diproses. Tunggu sebentar lalu kirim ulang pencarian yang sama.');
            }
            if ($guest && $guest->successful_count + $guest->reserved_count >= 3) {
                throw new RecommendationOperationException($guest->successful_count >= 3
                    ? 'Kuota tiga pencarian guest sudah habis. Login atau daftar diperlukan untuk melanjutkan.'
                    : 'Kuota sedang digunakan pencarian lain. Tunggu hingga pencarian selesai.', 429);
            }
            $operation ??= new RecommendationOperation;
            $operation->forceFill(['guest_quota_id' => $guest?->id, 'user_id' => $user, 'idempotency_key' => $key, 'input_hash' => $hash,
                'status' => 'reserved', 'generation' => ($operation->generation ?? 0) + 1,
                'lease_token' => bin2hex(random_bytes(32)), 'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS)])->save();
            if ($guest) {
                GuestQuota::whereKey($guest->id)->increment('reserved_count');
            }

            return $operation;
        });
    }

    public function complete(RecommendationOperation $lease, array $snapshot): void
    {
        $this->transaction(function () use ($lease, $snapshot) {
            $this->lockOwner($lease);
            $operation = $this->leaseQuery($lease)->where('lease_expires_at', '>', now())->first();
            if (! $operation) {
                throw new RecommendationOperationException('Waktu pencarian telah berakhir. Kirim ulang pencarian yang sama.');
            }
            $completed = now()->startOfSecond();
            $snapshot['expires_at'] = $completed->copy()->addDay()->timestamp;
            $operation->forceFill(['status' => 'succeeded', 'result_id' => $snapshot['id'], 'result_payload' => $snapshot,
                'completed_at' => $completed, 'result_expires_at' => $completed->copy()->addDay(),
                'lease_token' => null, 'lease_expires_at' => null])->save();
            if ($operation->guest_quota_id) {
                GuestQuota::whereKey($operation->guest_quota_id)->update([
                    'reserved_count' => DB::raw('reserved_count - 1'), 'successful_count' => DB::raw('successful_count + 1'), 'updated_at' => now(),
                ]);
            } else {
                // History and successful result commit together; never write guest history.
                $this->history->recordUser($operation->user_id, $snapshot, $operation->id);
            }
        });
    }

    public function release(RecommendationOperation $lease): void
    {
        $this->transaction(function () use ($lease) {
            $this->lockOwner($lease);
            $changed = $this->leaseQuery($lease)->update(['status' => 'failed', 'lease_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
            if ($changed && $lease->guest_quota_id) {
                GuestQuota::whereKey($lease->guest_quota_id)->decrement('reserved_count');
            }
        });
    }

    private function transaction(Closure $callback): mixed
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            return $connection->transaction($callback, 3);
        }

        // Never replay a nested transaction or any provider call. These callbacks
        // contain only transactional database writes and local snapshot processing.
        if ($connection->transactionLevel() !== 0) {
            throw new \LogicException('Recommendation operations require their own transaction.');
        }

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $connection->beginTransaction();
                $result = $callback();
                $connection->commit();

                return $result;
            } catch (\Throwable $exception) {
                // Explicit commit keeps Laravel's transaction level intact on a
                // failed COMMIT, so rollback also releases that pending transaction.
                if ($connection->transactionLevel() > 0) {
                    $connection->rollBack();
                }
                if (! SqliteContention::isBusy($exception, true) || $attempt === 3) {
                    if (SqliteContention::isBusy($exception, true)) {
                        // Convert a raw PDO BEGIN/COMMIT error to a connection-aware
                        // exception without SQL, bindings, or the original exception.
                        throw new \App\Exceptions\SqliteBusyException;
                    }
                    throw $exception;
                }
                // Back off only after rollback; no lock is held while sleeping.
                usleep(($attempt * 50 + random_int(0, 50)) * 1000);
            }
        }

        throw new \LogicException('Transaction attempt limit reached.');
    }

    private function lockOwner(RecommendationOperation $operation): void
    {
        if ($operation->guest_quota_id) {
            GuestQuota::whereKey($operation->guest_quota_id)->update(['updated_at' => now()]);
        } else {
            User::whereKey($operation->user_id)->toBase()->update(['id' => DB::raw('id')]);
        }
    }

    private function leaseQuery(RecommendationOperation $lease)
    {
        return RecommendationOperation::whereKey($lease->id)->where('status', 'reserved')
            ->where('generation', $lease->generation)->where('lease_token', $lease->lease_token);
    }

    private function inputHash(array $input): string
    {
        $ingredients = array_values(array_unique($input['ingredients']));
        sort($ingredients, SORT_STRING);

        return hash('sha256', json_encode(['ingredients' => $ingredients, 'servings' => (int) ($input['servings'] ?? 1),
            'preference' => $input['preference'] ?? 'bebas', 'allergies' => $input['allergies'] ?? '',
            'mode' => $input['mode'] ?? \App\Support\RecipeMode::NORMAL], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
