<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize() { return true; }

    public function rules()
    {
        return [
            'name' => ['required','string','max:150'],
            'cnic' => ['nullable','string','max:30','unique:customers,cnic'],
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
