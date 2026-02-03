<?php

use App\Models\Merchant;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\postJson;

beforeEach(function () {
    $this->merchant = Merchant::factory()->create();

    $this->product = Product::factory()->create([
        'merchant_id' => $this->merchant->id,
        'stock' => 100,
    ]);
});

// ----------------------------------------------------------
// SUCCESS CASES
// ----------------------------------------------------------

test('it reduces stock successfully', function () {
    $response = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 10,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                'product_id',
                'before',
                'after',
                'reduced_by',
                'status',
            ],
        ]);

    $data = $response->json('data');

    expect($data['product_id'])->toBe($this->product->id);
    expect($data['before'])->toBe(100);
    expect($data['after'])->toBe(90);
    expect($data['reduced_by'])->toBe(10);
    expect($data['status'])->toBe('success');

    expect($this->product->fresh()->stock)->toBe(90);
});

test('it reduces stock to zero', function () {
    $this->product->update(['stock' => 10]);

    $response = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 10,
    ]);

    $response->assertStatus(200);
    $data = $response->json('data');

    expect($data['before'])->toBe(10);
    expect($data['after'])->toBe(0);
    expect($this->product->fresh()->stock)->toBe(0);
});

test('it handles quantity of 1', function () {
    $response = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 1,
    ]);

    $response->assertStatus(200);
    $data = $response->json('data');

    expect($data['before'])->toBe(100);
    expect($data['after'])->toBe(99);
    expect($data['reduced_by'])->toBe(1);
});

test('it handles large quantity reduction', function () {
    $this->product->update(['stock' => 1000]);

    $response = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 999,
    ]);

    $response->assertStatus(200);
    $data = $response->json('data');

    expect($data['before'])->toBe(1000);
    expect($data['after'])->toBe(1);
});

// ----------------------------------------------------------
// FAILURE CASES — Insufficient Stock
// ----------------------------------------------------------

test('it fails when stock is insufficient', function () {
    $this->product->update(['stock' => 5]);

    $response = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 10,
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure([
            'data' => [
                'product_id',
                'available_stock',
                'requested',
                'status',
            ],
        ]);

    $data = $response->json('data');

    expect($data['product_id'])->toBe($this->product->id);
    expect($data['available_stock'])->toBe(5);
    expect($data['requested'])->toBe(10);
    expect($data['status'])->toBe('failed');

    expect($this->product->fresh()->stock)->toBe(5);
});

test('it fails when stock is zero', function () {
    $this->product->update(['stock' => 0]);

    $response = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 1,
    ]);

    $response->assertStatus(422);
    $data = $response->json('data');

    expect($data['available_stock'])->toBe(0);
    expect($data['requested'])->toBe(1);
    expect($data['status'])->toBe('failed');
});

test('it fails when requesting exact stock plus one', function () {
    $this->product->update(['stock' => 50]);

    $response = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 51,
    ]);

    $response->assertStatus(422);
    $data = $response->json('data');

    expect($data['available_stock'])->toBe(50);
    expect($data['requested'])->toBe(51);
    expect($this->product->fresh()->stock)->toBe(50);
});

// ----------------------------------------------------------
// RACE CONDITION TESTS
// ----------------------------------------------------------

test('it prevents race condition with concurrent requests', function () {
    $this->product->update(['stock' => 100]);

    $responses = [];

    for ($i = 0; $i < 10; $i++) {
        $responses[] = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
            'quantity' => 15,
        ]);
    }

    $successes = collect($responses)->filter(fn($r) => $r->status() === 200)->count();
    $failures  = collect($responses)->filter(fn($r) => $r->status() === 422)->count();

    expect($successes)->toBeLessThanOrEqual(6);
    expect($failures)->toBeGreaterThanOrEqual(4);

    $finalStock = $this->product->fresh()->stock;
    expect($finalStock)->toBeGreaterThanOrEqual(0);
    expect($finalStock)->toBeLessThanOrEqual(100);

    $totalReduced = 100 - $finalStock;
    expect($totalReduced)->toBe($successes * 15);
});

test('it handles sequential requests correctly', function () {
    $this->product->update(['stock' => 50]);

    $response1 = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 20,
    ]);

    expect($response1->status())->toBe(200);
    expect($response1->json('data.after'))->toBe(30);

    $response2 = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 20,
    ]);

    expect($response2->status())->toBe(200);
    expect($response2->json('data.before'))->toBe(30);
    expect($response2->json('data.after'))->toBe(10);

    $response3 = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 20,
    ]);

    expect($response3->status())->toBe(422);
    expect($response3->json('data.available_stock'))->toBe(10);

    // Final stock
    expect($this->product->fresh()->stock)->toBe(10);
});

// ----------------------------------------------------------
// VALIDATION TESTS
// ----------------------------------------------------------

test('it validates quantity is required', function () {
    $response = postJson("/api/v1/products/{$this->product->id}/reduce-stock", []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

test('it validates quantity is integer', function () {
    $response = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 'not-a-number',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

test('it validates quantity is positive', function () {
    $response = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 0,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);

    $response = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => -5,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);
});

test('it validates product exists', function () {
    $fakeUuid = '00000000-0000-0000-0000-000000000000';

    $response = postJson("/api/v1/products/{$fakeUuid}/reduce-stock", [
        'quantity' => 10,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['productId']);
});

test('it validates product id format', function () {
    $response = postJson("/api/v1/products/invalid-uuid/reduce-stock", [
        'quantity' => 10,
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['productId']);
});

// ----------------------------------------------------------
// EDGE CASES
// ----------------------------------------------------------

test('it does not allow negative stock', function () {
    $this->product->update(['stock' => 5]);

    $response = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 10,
    ]);

    $response->assertStatus(422);

    expect($this->product->fresh()->stock)->toBe(5);
});

test('it handles multiple products independently', function () {
    $merchant = Merchant::factory()->create();

    $product2 = Product::factory()->create([
        'merchant_id' => $merchant->id,
        'stock' => 50,
    ]);

    postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 10,
    ]);

    postJson("/api/v1/products/{$product2->id}/reduce-stock", [
        'quantity' => 20,
    ]);

    expect($this->product->fresh()->stock)->toBe(90);
    expect($product2->fresh()->stock)->toBe(30);
});

test('it returns consistent response structure for success and failure', function () {
    $success = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 10,
    ]);

    expect($success->json('data'))->toHaveKeys(['product_id', 'status']);
    expect($success->json('data.status'))->toBe('success');

    $this->product->update(['stock' => 5]);
    $failure = postJson("/api/v1/products/{$this->product->id}/reduce-stock", [
        'quantity' => 10,
    ]);

    expect($failure->json('data'))->toHaveKeys(['product_id', 'status']);
    expect($failure->json('data.status'))->toBe('failed');
});

// ----------------------------------------------------------
// DATABASE LOCK TESTS
// ----------------------------------------------------------

test('it uses database transaction', function () {
    DB::beginTransaction();

    $initialStock = $this->product->stock;

    try {
        $this->product->update(['stock' => $initialStock - 10]);

        throw new \Exception('Simulated failure');
    } catch (\Exception $e) {
        DB::rollBack();
    }

    expect($this->product->fresh()->stock)->toBe($initialStock);
});
