<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReduceStockRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'productId' => $this->route('productId'),
        ]);
    }

    public function rules(): array
    {
        return [
            'productId' => [
                'required',
                'uuid',
                'exists:products,id',
            ],
            'quantity' => [
                'required',
                'integer',
                'min:1',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'productId.exists'  => 'Produk tidak ditemukan.',
            'productId.uuid'    => 'Format product ID tidak valid.',
            'quantity.required' => 'quantity wajib diisi.',
            'quantity.integer'  => 'quantity harus angka bulat.',
            'quantity.min'      => 'quantity harus minimal 1.',
        ];
    }
}
