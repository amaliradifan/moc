<?php

namespace Database\Factories;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'transaction_code' => fake()->unique()->bothify('TRX-####-???-###'),
            'status'           => fake()->randomElement(['pending', 'paid', 'cancelled', 'completed']),
            'total_amount'     => 0,
            'created_at'       => fake()->dateTimeBetween('-60 days', 'now'),
        ];
    }
}
