<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MerchantAnalyticsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return [
            'merchant_id' => $data['merchant_id'],
            'range' => [
                'start_date' => $data['range']['start_date'],
                'end_date'   => $data['range']['end_date'],
            ],
            'summary' => [
                'total_orders'        => (int)   $data['summary']['total_orders'],
                'total_revenue'       => (float) $data['summary']['total_revenue'],
                'average_order_value' => (float) $data['summary']['average_order_value'],
                'total_customers'     => (int)   $data['summary']['total_customers'],
            ],
            'top_products' => collect($data['top_products'])->map(fn($product) => [
                'product_id'         => $product['product_id'],
                'name'               => $product['name'],
                'total_quantity_sold' => (int)   $product['total_quantity_sold'],
                'total_revenue'      => (float) $product['total_revenue'],
            ]),
            'generated_at' => $data['generated_at'],
            'cached'       => (bool) $data['cached'],
        ];
    }
}
