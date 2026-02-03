<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetAtRiskCustomersRequest extends FormRequest
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
            'merchantId' => $this->route('merchantId'),
        ]);
    }

    public function rules(): array
    {
        return [
            'merchantId' => [
                'required',
                'uuid',
                'exists:merchants,id',
            ],
            'baseline_days' => [
                'required',
                'integer',
                'min:1',
            ],
            'compare_days' => [
                'required',
                'integer',
                'min:1',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'merchantId.exists'       => 'Merchant tidak ditemukan.',
            'merchantId.uuid'         => 'Format merchant ID tidak valid.',
            'baseline_days.required'  => 'baseline_days wajib diisi.',
            'baseline_days.integer'   => 'baseline_days harus angka bulat.',
            'baseline_days.min'       => 'baseline_days harus minimal 1.',
            'compare_days.required'   => 'compare_days wajib diisi.',
            'compare_days.integer'    => 'compare_days harus angka bulat.',
            'compare_days.min'        => 'compare_days harus minimal 1.',
        ];
    }
}
