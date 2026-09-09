<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        $customer = $this->route('customer');
        $id = is_object($customer) ? $customer->id : $customer;
        return [
            'name' => ['required','string','max:150'],
            'cnic' => ['nullable','string','max:30',Rule::unique('customers','cnic')->ignore($id)],
            'phone' => ['required','string','max:30'],
            'alternate_phone' => ['nullable','string','max:30'],
            'email' => ['nullable','email','max:150'],
            'address' => ['nullable','string'],
            'city' => ['nullable','string','max:100'],
            'source' => ['nullable','string','max:100'],
            'notes' => ['nullable','string'],
            'is_active' => ['boolean'],
        ];
    }
}
