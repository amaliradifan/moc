<?php

namespace App\Providers;

use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Observers\TransactionItemObserver;
use App\Observers\TransactionObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Transaction::observe(TransactionObserver::class);
        TransactionItem::observe(TransactionItemObserver::class);
    }
}
