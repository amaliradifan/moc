<?php

namespace App\Services\Stock;

use App\Exceptions\InsufficientStockException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class StockReduceService
{
    protected const LOCK_TTL_SECONDS = 10;

    protected const LOCK_RETRY_TIMES = 3;

    protected const LOCK_RETRY_DELAY_MS = 100;

    public function reduce(string $productId, int $quantity): array
    {
        $lockKey = "stock_lock:{$productId}";

        $result = Cache::lock($lockKey, self::LOCK_TTL_SECONDS)
            ->block(
                self::LOCK_RETRY_TIMES * self::LOCK_RETRY_DELAY_MS / 1000,
                function () use ($productId, $quantity) {
                    return $this->reduceWithDatabaseLock($productId, $quantity);
                }
            );

        return $result;
    }

    private function reduceWithDatabaseLock(string $productId, int $quantity): array
    {
        return DB::transaction(function () use ($productId, $quantity) {

            $product = DB::table('products')
                ->where('id', $productId)
                ->lockForUpdate()
                ->first();

            $currentStock = $product->stock;

            if ($currentStock < $quantity) {
                throw new InsufficientStockException(
                    productId: $productId,
                    availableStock: $currentStock,
                    requested: $quantity,
                );
            }

            $newStock = $currentStock - $quantity;

            DB::table('products')
                ->where('id', $productId)
                ->update(['stock' => $newStock]);

            return [
                'product_id' => $productId,
                'before'     => $currentStock,
                'after'      => $newStock,
                'reduced_by' => $quantity,
                'status'     => 'success',
            ];
        });
    }
}
