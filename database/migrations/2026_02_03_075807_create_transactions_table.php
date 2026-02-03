<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('merchant_id')
                ->constrained('merchants')
                ->onDelete('cascade');
            $table->foreignUuid('customer_id')
                ->constrained('customers')
                ->onDelete('cascade');
            $table->string('transaction_code', 50)->unique();
            $table->enum('status', ['pending', 'paid', 'cancelled', 'completed'])
                ->default('pending');
            $table->decimal('total_amount', 12, 2);
            $table->timestamps();

            $table->index(['merchant_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
