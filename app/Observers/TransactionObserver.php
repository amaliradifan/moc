<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Services\Analytics\MerchantAnalyticsService;
use App\Services\Analytics\AtRiskCustomersService;

class TransactionObserver
{
    public function created(Transaction $transaction): void
    {
        $this->invalidateIfNeeded($transaction);
    }

    public function updated(Transaction $transaction): void
    {
        $validStatuses  = ['paid', 'completed'];
        $currentlyValid = in_array($transaction->status, $validStatuses);
        $wasValid       = in_array($transaction->getOriginal('status'), $validStatuses);

        if ($currentlyValid || $wasValid) {
            $this->invalidateAll($transaction->merchant_id);
        }
    }

    private function invalidateIfNeeded(Transaction $transaction): void
    {
        if (in_array($transaction->status, ['paid', 'completed'])) {
            $this->invalidateAll($transaction->merchant_id);
        }
    }

    private function invalidateAll(string $merchantId): void
    {
        MerchantAnalyticsService::invalidateCacheForMerchant($merchantId);
        AtRiskCustomersService::invalidateCacheForMerchant($merchantId);
    }
}
