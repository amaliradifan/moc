<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $merchants = Merchant::factory(5)->create();

        $customers = Customer::factory(100)->create();

        $products = new Collection();

        $merchants->each(function (Merchant $merchant) use (&$products) {
            $merchantProducts = Product::factory(10)->create([
                'merchant_id' => $merchant->id,
            ]);
            $products = $products->merge($merchantProducts);
        });

        for ($i = 0; $i < 2000; $i++) {
            $merchant        = $merchants->random();
            $merchantProducts = $products->where('merchant_id', $merchant->id);

            $transaction = Transaction::factory()->create([
                'merchant_id' => $merchant->id,
                'customer_id' => $customers->random()->id,
            ]);

            $itemCount       = rand(1, 5);
            $selectedProducts = $merchantProducts->random($itemCount);

            if (!$selectedProducts instanceof Collection) {
                $selectedProducts = new Collection([$selectedProducts]);
            }

            $totalAmount = 0;

            $selectedProducts->each(function (Product $product) use ($transaction, &$totalAmount) {
                $quantity   = rand(1, 10);
                $unitPrice  = $product->price;
                $subtotal   = $quantity * $unitPrice;
                $totalAmount += $subtotal;

                TransactionItem::factory()->create([
                    'transaction_id' => $transaction->id,
                    'product_id'     => $product->id,
                    'quantity'       => $quantity,
                    'unit_price'     => $unitPrice,
                    'subtotal'       => $subtotal,
                ]);
            });

            $transaction->update([
                'total_amount' => $totalAmount,
            ]);
        }
    }
}
