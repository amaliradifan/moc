<?php
namespace App\Services\Analytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MerchantAnalyticsService
{
    protected const VALID_STATUSES = ['paid', 'completed'];

    protected const CACHE_TTL_HOURS = 24;

    public function getAnalytics(string $merchantId, string $startDate, string $endDate): array
    {
        $cacheKey = self::buildCacheKey($merchantId, $startDate, $endDate);

        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            $cached['generated_at'] = now()->toIso8601String();
            $cached['cached']       = true;
            return $cached;
        }

        $result = $this->computeAnalytics($merchantId, $startDate, $endDate);

        Cache::put($cacheKey, $result, now()->addHours(self::CACHE_TTL_HOURS));

        return $result;
    }

    private function computeAnalytics(string $merchantId, string $startDate, string $endDate): array
    {
        $summary = $this->querySummary($merchantId, $startDate, $endDate);

        $totalOrders   = (int)   $summary->total_orders;
        $totalRevenue  = (float) $summary->total_revenue;

        return [
            'merchant_id' => $merchantId,
            'range'       => [
                'start_date' => $startDate,
                'end_date'   => $endDate,
            ],
            'summary' => [
                'total_orders'        => $totalOrders,
                'total_revenue'       => $totalRevenue,
                'average_order_value' => $totalOrders > 0
                    ? round($totalRevenue / $totalOrders, 2)
                    : 0.0,
                'total_customers'     => (int) $summary->total_customers,
            ],
            'top_products' => $this->queryTopProducts($merchantId, $startDate, $endDate),
            'generated_at' => now()->toIso8601String(),
            'cached'       => false,
        ];
    }

    private function querySummary(string $merchantId, string $startDate, string $endDate): object
    {
        return DB::table('transactions')
            ->join(
                'transaction_items',
                'transaction_items.transaction_id', '=', 'transactions.id'
            )
            ->select([
                DB::raw('COUNT(DISTINCT transactions.id)       AS total_orders'),
                DB::raw('SUM(transaction_items.subtotal)        AS total_revenue'),
                DB::raw('COUNT(DISTINCT transactions.customer_id) AS total_customers'),
            ])
            ->where('transactions.merchant_id', $merchantId)
            ->whereIn('transactions.status', self::VALID_STATUSES)
            ->whereBetween('transactions.created_at', [
                $startDate . ' 00:00:00',
                $endDate   . ' 23:59:59',
            ])
            ->first();
    }

    private function queryTopProducts(string $merchantId, string $startDate, string $endDate): array
    {
        return DB::table('transaction_items')
            ->join('transactions', 'transaction_items.transaction_id', '=', 'transactions.id')
            ->join('products',     'transaction_items.product_id',    '=', 'products.id')
            ->select([
                'products.id   as product_id',
                'products.name as name',
                DB::raw('SUM(transaction_items.quantity) as total_quantity_sold'),
                DB::raw('SUM(transaction_items.subtotal) as total_revenue'),
            ])
            ->where('transactions.merchant_id', $merchantId)
            ->whereIn('transactions.status', self::VALID_STATUSES)
            ->whereBetween('transactions.created_at', [
                $startDate . ' 00:00:00',
                $endDate   . ' 23:59:59',
            ])
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_quantity_sold')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'product_id'         => $row->product_id,
                'name'               => $row->name,
                'total_quantity_sold' => (int)   $row->total_quantity_sold,
                'total_revenue'      => (float) $row->total_revenue,
            ])
            ->toArray();
    }

    public static function buildCacheKey(string $merchantId, string $startDate, string $endDate): string
    {
        return "merchant:{$merchantId}:analytics:{$startDate}:{$endDate}";
    }

    public static function invalidateCacheForMerchant(string $merchantId): void
    {
        $driver = config('cache.default');

        if ($driver === 'redis') {
            $redis  = Cache::getRedis();
            $prefix = config('cache.stores.redis.prefix', '');
            $pattern = $prefix . "merchant:{$merchantId}:analytics:*";

            $keys = $redis->keys($pattern);

            if (!empty($keys)) {
                $redis->del($keys);
            }
        } else {
            Cache::flush();
        }
    }
}
