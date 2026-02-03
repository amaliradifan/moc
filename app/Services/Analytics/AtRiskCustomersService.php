<?php

namespace App\Services\Analytics;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AtRiskCustomersService
{
    protected const VALID_STATUSES = ['paid', 'completed'];
    protected const CACHE_TTL_HOURS = 24;

    public function getAtRiskCustomers(string $merchantId, int $baselineDays, int $compareDays): array
    {
        $cacheKey = "merchant:{$merchantId}:at-risk:{$baselineDays}:{$compareDays}";

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            $cached['generated_at'] = now()->toIso8601String();
            $cached['cached']       = true;
            return $cached;
        }

        $periods = $this->calculatePeriods($baselineDays, $compareDays);

        $customers = $this->queryAtRiskCustomers($merchantId, $periods);

        $result = [
            'merchant_id'     => $merchantId,
            'baseline_period' => [
                'start_date' => $periods['baseline_start']->format('Y-m-d'),
                'end_date'   => $periods['baseline_end']->format('Y-m-d'),
            ],
            'compare_period' => [
                'start_date' => $periods['compare_start']->format('Y-m-d'),
                'end_date'   => $periods['compare_end']->format('Y-m-d'),
            ],
            'summary' => [
                'total_at_risk_customers' => count($customers),
            ],
            'customers'    => $customers,
            'generated_at' => now()->toIso8601String(),
            'cached'       => false,
        ];

        Cache::put($cacheKey, $result, now()->addHours(self::CACHE_TTL_HOURS));

        return $result;
    }

    private function calculatePeriods(int $baselineDays, int $compareDays): array
    {
        $today = Carbon::now()->startOfDay();

        $compareEnd   = $today->clone();
        $compareStart = $today->clone()->subDays($compareDays - 1);

        $baselineEnd   = $compareStart->clone()->subDay();
        $baselineStart = $baselineEnd->clone()->subDays($baselineDays - 1);

        return [
            'baseline_start' => $baselineStart,
            'baseline_end'   => $baselineEnd,
            'compare_start'  => $compareStart,
            'compare_end'    => $compareEnd,
        ];
    }

    private function queryAtRiskCustomers(string $merchantId, array $periods): array
    {
        $baselineStart = $periods['baseline_start']->format('Y-m-d') . ' 00:00:00';
        $baselineEnd   = $periods['baseline_end']->format('Y-m-d')   . ' 23:59:59';
        $compareStart  = $periods['compare_start']->format('Y-m-d')  . ' 00:00:00';
        $compareEnd    = $periods['compare_end']->format('Y-m-d')    . ' 23:59:59';

        return DB::table('transactions as baseline_tx')
            ->join(
                'transaction_items',
                'transaction_items.transaction_id',
                '=',
                'baseline_tx.id'
            )
            ->join(
                'customers',
                'customers.id',
                '=',
                'baseline_tx.customer_id'
            )
            ->select([
                'customers.id    as customer_id',
                'customers.name  as name',
                'customers.email as email',
                DB::raw('COUNT(DISTINCT baseline_tx.id)              AS order_count'),
                DB::raw('SUM(transaction_items.subtotal)             AS total_spent'),
                DB::raw('MAX(baseline_tx.created_at)                 AS last_order_date'),
            ])
            ->where('baseline_tx.merchant_id', $merchantId)
            ->whereIn('baseline_tx.status', self::VALID_STATUSES)
            ->whereBetween('baseline_tx.created_at', [$baselineStart, $baselineEnd])

            ->whereNotExists(function ($subquery) use ($merchantId, $compareStart, $compareEnd) {
                $subquery
                    ->select(DB::raw('1'))
                    ->from('transactions as compare_tx')
                    ->whereColumn('compare_tx.customer_id', 'baseline_tx.customer_id')
                    ->where('compare_tx.merchant_id', $merchantId)
                    ->whereIn('compare_tx.status', self::VALID_STATUSES)
                    ->whereBetween('compare_tx.created_at', [$compareStart, $compareEnd]);
            })

            ->groupBy('customers.id', 'customers.name', 'customers.email')
            ->orderByDesc('total_spent')
            ->get()
            ->map(fn($row) => [
                'customer_id' => $row->customer_id,
                'name'        => $row->name,
                'email'       => $row->email,
                'baseline'    => [
                    'order_count'    => (int)    $row->order_count,
                    'total_spent'    => (float)  $row->total_spent,
                    'last_order_date' => Carbon::parse($row->last_order_date)->toIso8601String(),
                ],
            ])
            ->toArray();
    }

    public static function invalidateCacheForMerchant(string $merchantId): void
    {
        $driver = config('cache.default');

        if ($driver === 'redis') {
            $redis   = Cache::getRedis();
            $prefix  = config('cache.stores.redis.prefix', '');
            $pattern = $prefix . "merchant:{$merchantId}:at-risk:*";

            $keys = $redis->keys($pattern);

            if (!empty($keys)) {
                $redis->del($keys);
            }
        } else {
            Cache::flush();
        }
    }
}
