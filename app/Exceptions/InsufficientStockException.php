<?php

namespace App\Exceptions;

use Exception;

class InsufficientStockException extends Exception
{
    protected array $payload;

    public function __construct(string $productId, int $availableStock, int $requested)
    {
        $this->payload = [
            'product_id'     => $productId,
            'available_stock' => $availableStock,
            'requested'      => $requested,
            'status'         => 'failed',
        ];

        parent::__construct('Stok tidak cukup.');
    }

    public function getPayload(): array
    {
        return $this->payload;
    }
}
