<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePaymentMethodRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name'           => [
                'required', 'string', 'max:100',
                Rule::unique('payment_methods', 'name')->ignore($this->route('id')),
            ],
            'is_cash'        => 'nullable|boolean',
            'icon'           => 'nullable|string|max:2048',
            'logo'           => 'nullable|string|max:2048',
            'description'    => 'nullable|string|max:255',
            'bank_name'      => 'nullable|string|max:100',
            'account_number' => 'nullable|string|max:50',
            'account_name'   => 'nullable|string|max:100',
            'status'         => 'nullable|boolean',
        ];
    }
}
