<?php

namespace App\Observers;

use App\Models\TransactionItem;
use App\Services\Analytics\AtRiskCustomersService;
use App\Services\Analytics\MerchantAnalyticsService;

class TransactionItemObserver
{
    public function created(TransactionItem $item): void
    {
        $this->invalidateCache($item);
    }

    public function updated(TransactionItem $item): void
    {
        $this->invalidateCache($item);
    }

    public function deleted(TransactionItem $item): void
    {
        $this->invalidateCache($item);
    }


    private function invalidateCache(TransactionItem $item): void
    {
        $merchantId = $item->transaction->merchant_id;

        MerchantAnalyticsService::invalidateCacheForMerchant($merchantId);
        AtRiskCustomersService::invalidateCacheForMerchant($merchantId);
    }
}
