<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetMerchantAnalyticsRequest extends FormRequest
{
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
            'start_date' => [
                'required',
                'date_format:Y-m-d',
                'before_or_equal:end_date',
            ],
            'end_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'merchantId.exists'          => 'Merchant tidak ditemukan.',
            'merchantId.uuid'            => 'Format merchant ID tidak valid.',
            'start_date.required'        => 'start_date wajib diisi.',
            'start_date.date_format'     => 'Format start_date harus YYYY-MM-DD.',
            'start_date.before_or_equal' => 'start_date harus sebelum atau sama dengan end_date.',
            'end_date.required'          => 'end_date wajib diisi.',
            'end_date.date_format'       => 'Format end_date harus YYYY-MM-DD.',
            'end_date.after_or_equal'    => 'end_date harus setelah atau sama dengan start_date.',
        ];
    }
}
