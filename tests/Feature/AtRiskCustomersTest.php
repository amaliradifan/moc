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
    $this->merchant = Merchant::factory()->create();
    $this->product  = Product::factory()->create(['merchant_id' => $this->merchant->id]);

    Cache::flush();

    Carbon::setTestNow('2026-01-29 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow(); // Reset time
});

// ----------------------------------------------------------
// Helper: Create transaction with items
// ----------------------------------------------------------

function createTransactionForAtRisk(
    string $date,
    Customer $customer,
    Merchant $merchant,
    Product $product,
    int $quantity = 1,
    float $price = 100000
): Transaction {
    $transaction = Transaction::factory()->create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'status'      => 'paid',
        'created_at'  => Carbon::parse($date),
    ]);

    TransactionItem::factory()->create([
        'transaction_id' => $transaction->id,
        'product_id'     => $product->id,
        'quantity'       => $quantity,
        'unit_price'     => $price,
        'subtotal'       => $quantity * $price,
    ]);

    return $transaction;
}

// ----------------------------------------------------------
// SUCCESS CASES
// ----------------------------------------------------------

test('it detects at-risk customers correctly', function () {
    // Setup customers
    $atRiskCustomer   = Customer::factory()->create();  // Has baseline, no compare
    $activeCustomer   = Customer::factory()->create();  // Has both
    $newCustomer      = Customer::factory()->create();  // Only compare (not at-risk)
    $inactiveCustomer = Customer::factory()->create();  // Neither (not at-risk)

    // baseline_days=30, compare_days=30
    // Today: 2026-01-29
    // Compare period: 2025-12-31 to 2026-01-29
    // Baseline period: 2025-12-01 to 2025-12-30

    createTransactionForAtRisk('2025-12-15', $atRiskCustomer, $this->merchant, $this->product);

    createTransactionForAtRisk('2025-12-10', $activeCustomer, $this->merchant, $this->product);
    createTransactionForAtRisk('2026-01-15', $activeCustomer, $this->merchant, $this->product);

    createTransactionForAtRisk('2026-01-20', $newCustomer, $this->merchant, $this->product);

    // Act
    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 30,
        'compare_days'  => 30,
    ]));

    // Assert
    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'merchant_id',
                'baseline_period' => ['start_date', 'end_date'],
                'compare_period'  => ['start_date', 'end_date'],
                'summary' => ['total_at_risk_customers'],
                'customers' => [
                    '*' => [
                        'customer_id',
                        'name',
                        'email',
                        'baseline' => [
                            'order_count',
                            'total_spent',
                            'last_order_date',
                        ],
                    ],
                ],
                'generated_at',
            ],
        ]);

    $data = $response->json('data');

    // Verify period calculation
    expect($data['baseline_period']['start_date'])->toBe('2025-12-01');
    expect($data['baseline_period']['end_date'])->toBe('2025-12-30');
    expect($data['compare_period']['start_date'])->toBe('2025-12-31');
    expect($data['compare_period']['end_date'])->toBe('2026-01-29');

    // Should have exactly 1 at-risk customer
    expect($data['summary']['total_at_risk_customers'])->toBe(1);
    expect($data['customers'])->toHaveCount(1);
    expect($data['customers'][0]['customer_id'])->toBe($atRiskCustomer->id);
});

test('it calculates baseline metrics correctly', function () {
    $customer = Customer::factory()->create();

    // Create 3 transactions in baseline period with different amounts
    createTransactionForAtRisk('2025-12-05', $customer, $this->merchant, $this->product, 2, 100000);  // 200k
    createTransactionForAtRisk('2025-12-15', $customer, $this->merchant, $this->product, 1, 150000);  // 150k
    createTransactionForAtRisk('2025-12-25', $customer, $this->merchant, $this->product, 3, 50000);   // 150k

    // No transaction in compare period → at-risk

    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 30,
        'compare_days'  => 30,
    ]));

    $data = $response->json('data.customers.0.baseline');

    expect($data['order_count'])->toBe(3);
    expect($data['total_spent'])->toBe(500000);  // 200k + 150k + 150k
    expect($data['last_order_date'])->toContain('2025-12-25');  // Latest transaction
});

test('it only counts paid and completed transactions', function () {
    $customer = Customer::factory()->create();

    // Baseline: 2 paid + 1 pending (should count only 2)
    createTransactionForAtRisk('2025-12-10', $customer, $this->merchant, $this->product, 1, 100000);  // paid
    createTransactionForAtRisk('2025-12-15', $customer, $this->merchant, $this->product, 1, 100000);  // paid

    Transaction::factory()->create([
        'merchant_id' => $this->merchant->id,
        'customer_id' => $customer->id,
        'status'      => 'pending',  // <- should be ignored
        'created_at'  => '2025-12-20',
    ]);

    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 30,
        'compare_days'  => 30,
    ]));

    $data = $response->json('data.customers.0.baseline');

    expect($data['order_count'])->toBe(2);  // Not 3
    expect($data['total_spent'])->toBe(200000);  // Not 300k
});

test('it handles different period lengths', function () {
    $customer = Customer::factory()->create();

    // baseline_days=60, compare_days=15
    // Today: 2026-01-29
    // Compare:  2026-01-15 to 2026-01-29 (15 days)
    // Baseline: 2025-11-16 to 2026-01-14 (60 days)

    createTransactionForAtRisk('2025-12-01', $customer, $this->merchant, $this->product);  // In baseline

    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 60,
        'compare_days'  => 15,
    ]));

    $data = $response->json('data');

    expect($data['baseline_period']['start_date'])->toBe('2025-11-16');
    expect($data['baseline_period']['end_date'])->toBe('2026-01-14');
    expect($data['compare_period']['start_date'])->toBe('2026-01-15');
    expect($data['compare_period']['end_date'])->toBe('2026-01-29');

    expect($data['summary']['total_at_risk_customers'])->toBe(1);
});

test('it sorts customers by total spent descending', function () {
    $customer1 = Customer::factory()->create(['name' => 'Low Spender']);
    $customer2 = Customer::factory()->create(['name' => 'High Spender']);
    $customer3 = Customer::factory()->create(['name' => 'Mid Spender']);

    createTransactionForAtRisk('2025-12-10', $customer1, $this->merchant, $this->product, 1, 100000);   // 100k
    createTransactionForAtRisk('2025-12-10', $customer2, $this->merchant, $this->product, 5, 200000);   // 1M
    createTransactionForAtRisk('2025-12-10', $customer3, $this->merchant, $this->product, 3, 150000);   // 450k

    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 30,
        'compare_days'  => 30,
    ]));

    $customers = $response->json('data.customers');

    expect($customers)->toHaveCount(3);
    expect($customers[0]['name'])->toBe('High Spender');     // 1M
    expect($customers[1]['name'])->toBe('Mid Spender');      // 450k
    expect($customers[2]['name'])->toBe('Low Spender');      // 100k
});

// ----------------------------------------------------------
// EDGE CASES
// ----------------------------------------------------------

test('it returns empty list when no at-risk customers', function () {
    $customer = Customer::factory()->create();

    createTransactionForAtRisk('2025-12-10', $customer, $this->merchant, $this->product);
    createTransactionForAtRisk('2026-01-10', $customer, $this->merchant, $this->product);

    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 30,
        'compare_days'  => 30,
    ]));

    $data = $response->json('data');

    expect($data['summary']['total_at_risk_customers'])->toBe(0);
    expect($data['customers'])->toBeEmpty();
});

test('it returns empty when no transactions at all', function () {
    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 30,
        'compare_days'  => 30,
    ]));

    $data = $response->json('data');

    expect($data['summary']['total_at_risk_customers'])->toBe(0);
    expect($data['customers'])->toBeEmpty();
});

test('it isolates data per merchant', function () {
    $otherMerchant = Merchant::factory()->create();
    $otherProduct  = Product::factory()->create(['merchant_id' => $otherMerchant->id]);
    $customer      = Customer::factory()->create();

    createTransactionForAtRisk('2025-12-10', $customer, $otherMerchant, $otherProduct);

    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 30,
        'compare_days'  => 30,
    ]));

    $data = $response->json('data');

    expect($data['summary']['total_at_risk_customers'])->toBe(0);
});

test('it handles customer with multiple baseline transactions', function () {
    $customer = Customer::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        createTransactionForAtRisk(
            "2025-12-" . str_pad($i + 5, 2, '0', STR_PAD_LEFT),
            $customer,
            $this->merchant,
            $this->product,
            2,
            100000
        );
    }

    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 30,
        'compare_days'  => 30,
    ]));

    $data = $response->json('data.customers.0.baseline');

    expect($data['order_count'])->toBe(5);
    expect($data['total_spent'])->toBe(1000000);
});

// ----------------------------------------------------------
// CACHING TESTS
// ----------------------------------------------------------

test('it caches results', function () {
    $customer = Customer::factory()->create();
    createTransactionForAtRisk('2025-12-10', $customer, $this->merchant, $this->product);

    $queryString = http_build_query([
        'baseline_days' => 30,
        'compare_days'  => 30,
    ]);

    $response1 = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?{$queryString}");
    $response2 = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?{$queryString}");

    expect($response1->json('data.summary'))->toBe($response2->json('data.summary'));
    expect($response1->json('data.customers'))->toBe($response2->json('data.customers'));
});

test('it uses different cache for different parameters', function () {
    $customer = Customer::factory()->create();
    createTransactionForAtRisk('2025-12-10', $customer, $this->merchant, $this->product);

    $response1 = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 30,
        'compare_days'  => 30,
    ]));

    $response2 = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 60,
        'compare_days'  => 30,
    ]));

    expect($response1->json('data.baseline_period'))->not->toBe($response2->json('data.baseline_period'));
});

// ----------------------------------------------------------
// VALIDATION TESTS
// ----------------------------------------------------------

test('it validates baseline_days is required', function () {
    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'compare_days' => 30,
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['baseline_days']);
});

test('it validates compare_days is required', function () {
    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 30,
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['compare_days']);
});

test('it validates baseline_days is integer', function () {
    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 'not-a-number',
        'compare_days'  => 30,
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['baseline_days']);
});

test('it validates compare_days is integer', function () {
    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 30,
        'compare_days'  => 'invalid',
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['compare_days']);
});

test('it validates baseline_days is positive', function () {
    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 0,
        'compare_days'  => 30,
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['baseline_days']);

    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => -10,
        'compare_days'  => 30,
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['baseline_days']);
});

test('it validates compare_days is positive', function () {
    $response = getJson("/api/v1/merchants/{$this->merchant->id}/at-risk-customers?" . http_build_query([
        'baseline_days' => 30,
        'compare_days'  => 0,
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['compare_days']);
});

test('it validates merchant exists', function () {
    $fakeUuid = '00000000-0000-0000-0000-000000000000';

    $response = getJson("/api/v1/merchants/{$fakeUuid}/at-risk-customers?" . http_build_query([
        'baseline_days' => 30,
        'compare_days'  => 30,
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['merchantId']);
});

test('it validates merchant id format', function () {
    $response = getJson("/api/v1/merchants/invalid-uuid/at-risk-customers?" . http_build_query([
        'baseline_days' => 30,
        'compare_days'  => 30,
    ]));

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['merchantId']);
});
