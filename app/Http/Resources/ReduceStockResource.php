<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReduceStockResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        if ($data['status'] === 'failed') {
            return [
                'product_id'     => $data['product_id'],
                'available_stock' => (int) $data['available_stock'],
                'requested'      => (int) $data['requested'],
                'status'         => 'failed',
            ];
        }

        return [
            'product_id' => $data['product_id'],
            'before'     => (int) $data['before'],
            'after'      => (int) $data['after'],
            'reduced_by' => (int) $data['reduced_by'],
            'status'     => 'success',
        ];
    }
}
