<?php

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\getJson;

beforeEach(function () {
    Cache::flush();

    $this->merchant = Merchant::factory()->create();
    $this->customer = Customer::factory()->create();
    $this->product1 = Product::factory()->create(['merchant_id' => $this->merchant->id]);
    $this->product2 = Product::factory()->create(['merchant_id' => $this->merchant->id]);
});

// ----------------------------------------------------------
// Helper function
// ----------------------------------------------------------

function createTransaction(
    string $date,
    string $status,
    array $items,
    ?Merchant $merchant = null,
    ?Customer $customer = null
): Transaction {
    $merchant = $merchant ?? test()->merchant;
    $customer = $customer ?? test()->customer;

    $transaction = Transaction::factory()->create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'status'      => $status,
        'created_at'  => Carbon::parse($date),
    ]);

    foreach ($items as $item) {
        $subtotal = $item['quantity'] * $item['price'];

        TransactionItem::factory()->create([
            'transaction_id' => $transaction->id,
            'product_id'     => $item['product']->id,
            'quantity'       => $item['quantity'],
            'unit_price'          => $item['price'],
            'subtotal'       => $subtotal,
        ]);
    }

    return $transaction->fresh();
}

// ----------------------------------------------------------
// SUCCESS CASES
// ----------------------------------------------------------

test('it returns merchant analytics successfully', function () {
    createTransaction('2026-01-05', 'paid', [
        ['product' => $this->product1, 'quantity' => 2, 'price' => 100000],
        ['product' => $this->product2, 'quantity' => 1, 'price' => 50000],
    ]);

    createTransaction('2026-01-15', 'completed', [
        ['product' => $this->product1, 'quantity' => 3, 'price' => 100000],
    ]);

    createTransaction('2026-01-25', 'paid', [
        ['product' => $this->product2, 'quantity' => 2, 'price' => 50000],
    ]);

    $response = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?" . http_build_query([
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
    ]));

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'merchant_id',
                'range' => ['start_date', 'end_date'],
                'summary' => [
                    'total_orders',
                    'total_revenue',
                    'average_order_value',
                    'total_customers',
                ],
                'top_products',
                'generated_at',
                'cached',
            ]
        ]);

    $data = $response->json('data');

    expect($data['summary']['total_orders'])->toBe(3);
    expect($data['summary']['total_revenue'])->toBe(650000);
    expect($data['summary']['total_customers'])->toBe(1);
    expect($data['cached'])->toBeFalse();
    expect($data['top_products'])->toHaveCount(2);
    expect($data['top_products'][0]['product_id'])->toBe($this->product1->id);
    expect($data['top_products'][0]['total_quantity_sold'])->toBe(5);
});

test('it only counts paid and completed transactions', function () {
    createTransaction('2026-01-10', 'paid', [
        ['product' => $this->product1, 'quantity' => 1, 'price' => 100000],
    ]);

    createTransaction('2026-01-11', 'pending', [
        ['product' => $this->product1, 'quantity' => 10, 'price' => 100000],
    ]);

    createTransaction('2026-01-12', 'cancelled', [
        ['product' => $this->product1, 'quantity' => 10, 'price' => 100000],
    ]);

    createTransaction('2026-01-13', 'completed', [
        ['product' => $this->product1, 'quantity' => 1, 'price' => 100000],
    ]);

    $response = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?" . http_build_query([
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
    ]));

    $data = $response->json('data');

    expect($data['summary']['total_orders'])->toBe(2);
    expect($data['summary']['total_revenue'])->toBe(200000);
});

test('it filters transactions by date range', function () {
    createTransaction('2025-12-31', 'paid', [
        ['product' => $this->product1, 'quantity' => 10, 'price' => 100000],
    ]);

    createTransaction('2026-01-15', 'paid', [
        ['product' => $this->product1, 'quantity' => 1, 'price' => 100000],
    ]);

    createTransaction('2026-02-01', 'paid', [
        ['product' => $this->product1, 'quantity' => 10, 'price' => 100000],
    ]);

    $response = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?" . http_build_query([
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
    ]));

    $data = $response->json('data');

    expect($data['summary']['total_orders'])->toBe(1);
    expect($data['summary']['total_revenue'])->toBe(100000);
});

test('it returns top 5 products only', function () {
    collect(range(1, 10))->each(function ($i) {
        $product = Product::factory()->create(['merchant_id' => $this->merchant->id]);

        createTransaction('2026-01-10', 'paid', [
            ['product' => $product, 'quantity' => 11 - $i, 'price' => 10000],
        ]);
    });

    $response = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?" . http_build_query([
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
    ]));

    $data = $response->json('data');

    expect($data['top_products'])->toHaveCount(5);
    expect($data['top_products'][0]['total_quantity_sold'])->toBe(10);
    expect($data['top_products'][4]['total_quantity_sold'])->toBe(6);
});

// ----------------------------------------------------------
// CACHING TESTS
// ----------------------------------------------------------

test('it caches analytics result', function () {
    createTransaction('2026-01-10', 'paid', [
        ['product' => $this->product1, 'quantity' => 1, 'price' => 100000],
    ]);

    $queryString = http_build_query([
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
    ]);

    $response1 = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?{$queryString}");
    $data1 = $response1->json('data');
    expect($data1['cached'])->toBeFalse();

    $response2 = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?{$queryString}");
    $data2 = $response2->json('data');
    expect($data2['cached'])->toBeTrue();

    expect($data1['summary'])->toBe($data2['summary']);
});

test('it invalidates cache when new transaction created', function () {
    createTransaction('2026-01-10', 'paid', [
        ['product' => $this->product1, 'quantity' => 1, 'price' => 100000],
    ]);

    $queryString = http_build_query([
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
    ]);

    $response1 = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?{$queryString}");
    expect($response1->json('data.summary.total_orders'))->toBe(1);

    createTransaction('2026-01-20', 'paid', [
        ['product' => $this->product1, 'quantity' => 1, 'price' => 100000],
    ]);

    $response2 = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?{$queryString}");
    expect($response2->json('data.cached'))->toBeFalse();
    expect($response2->json('data.summary.total_orders'))->toBe(2);
});

test('it invalidates cache when transaction status updated', function () {
    $transaction = createTransaction('2026-01-10', 'pending', [
        ['product' => $this->product1, 'quantity' => 1, 'price' => 100000],
    ]);

    $queryString = http_build_query([
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
    ]);

    $response1 = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?{$queryString}");
    expect($response1->json('data.summary.total_orders'))->toBe(0);

    $transaction->update(['status' => 'paid']);

    $response2 = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?{$queryString}");
    expect($response2->json('data.cached'))->toBeFalse();
    expect($response2->json('data.summary.total_orders'))->toBe(1);
});

test('it invalidates cache when transaction item updated', function () {
    $transaction = createTransaction('2026-01-10', 'paid', [
        ['product' => $this->product1, 'quantity' => 1, 'price' => 100000],
    ]);

    $queryString = http_build_query([
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
    ]);

    $response1 = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?{$queryString}");
    expect($response1->json('data.summary.total_revenue'))->toBe(100000);

    $transaction->transactionItems()->first()->update(['subtotal' => 200000]);

    $response2 = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?{$queryString}");
    expect($response2->json('data.cached'))->toBeFalse();
    expect($response2->json('data.summary.total_revenue'))->toBe(200000);
});

// ----------------------------------------------------------
// VALIDATION TESTS
// ----------------------------------------------------------

test('it validates required parameters', function () {
    $response = getJson("/api/v1/merchants/{$this->merchant->id}/analytics");

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['start_date', 'end_date']);
});

test('it validates date format', function () {
    $response = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?" . http_build_query([
        'start_date' => '01-01-2026',
        'end_date'   => '2026/01/31',
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['start_date', 'end_date']);
});

test('it validates end date after start date', function () {
    $response = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?" . http_build_query([
        'start_date' => '2026-01-31',
        'end_date'   => '2026-01-01',
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['end_date']);
});

test('it validates merchant exists', function () {
    $fakeUuid = '00000000-0000-0000-0000-000000000000';

    $response = getJson("/api/v1/merchants/{$fakeUuid}/analytics?" . http_build_query([
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['merchantId']);
});

test('it validates merchant id format', function () {
    $response = getJson("/api/v1/merchants/invalid-uuid/analytics?" . http_build_query([
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['merchantId']);
});

// ----------------------------------------------------------
// EDGE CASES
// ----------------------------------------------------------

test('it returns empty analytics when no transactions', function () {
    $response = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?" . http_build_query([
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
    ]));

    $response->assertStatus(200);
    $data = $response->json('data');

    expect($data['summary']['total_orders'])->toBe(0);
    expect($data['summary']['total_revenue'])->toBe(0);
    expect($data['summary']['average_order_value'])->toBe(0);
    expect($data['summary']['total_customers'])->toBe(0);
    expect($data['top_products'])->toBeEmpty();
});

test('it handles multiple customers', function () {
    $customer1 = Customer::factory()->create();
    $customer2 = Customer::factory()->create();

    createTransaction('2026-01-10', 'paid', [
        ['product' => $this->product1, 'quantity' => 1, 'price' => 100000],
    ], $this->merchant, $customer1);

    createTransaction('2026-01-15', 'paid', [
        ['product' => $this->product1, 'quantity' => 1, 'price' => 100000],
    ], $this->merchant, $customer2);

    createTransaction('2026-01-20', 'paid', [
        ['product' => $this->product1, 'quantity' => 1, 'price' => 100000],
    ], $this->merchant, $customer1);

    $response = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?" . http_build_query([
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
    ]));

    $data = $response->json('data');

    expect($data['summary']['total_orders'])->toBe(3);
    expect($data['summary']['total_customers'])->toBe(2);
});

test('it isolates data per merchant', function () {
    $otherMerchant = Merchant::factory()->create();
    $otherProduct  = Product::factory()->create(['merchant_id' => $otherMerchant->id]);

    createTransaction('2026-01-10', 'paid', [
        ['product' => $this->product1, 'quantity' => 1, 'price' => 100000],
    ], $this->merchant);

    $otherTransaction = Transaction::factory()->create([
        'merchant_id' => $otherMerchant->id,
        'customer_id' => $this->customer->id,
        'status'      => 'paid',
        'created_at'  => '2026-01-15',
    ]);

    TransactionItem::factory()->create([
        'transaction_id' => $otherTransaction->id,
        'product_id'     => $otherProduct->id,
        'quantity'       => 100,
        'unit_price'          => 100000,
        'subtotal'       => 10000000,
    ]);

    $response = getJson("/api/v1/merchants/{$this->merchant->id}/analytics?" . http_build_query([
        'start_date' => '2026-01-01',
        'end_date'   => '2026-01-31',
    ]));

    $data = $response->json('data');

    expect($data['summary']['total_orders'])->toBe(1);
    expect($data['summary']['total_revenue'])->toBe(100000);
});
