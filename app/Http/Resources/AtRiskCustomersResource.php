<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AtRiskCustomersResource extends JsonResource
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
            'baseline_period' => [
                'start_date' => $data['baseline_period']['start_date'],
                'end_date'   => $data['baseline_period']['end_date'],
            ],
            'compare_period' => [
                'start_date' => $data['compare_period']['start_date'],
                'end_date'   => $data['compare_period']['end_date'],
            ],
            'summary' => [
                'total_at_risk_customers' => (int) $data['summary']['total_at_risk_customers'],
            ],
            'customers' => collect($data['customers'])->map(fn($customer) => [
                'customer_id' => $customer['customer_id'],
                'name'        => $customer['name'],
                'email'       => $customer['email'],
                'baseline'    => [
                    'order_count'     => (int)   $customer['baseline']['order_count'],
                    'total_spent'     => (float) $customer['baseline']['total_spent'],
                    'last_order_date' => $customer['baseline']['last_order_date'],
                ],
            ]),
            'generated_at' => $data['generated_at'],
        ];
    }
}
