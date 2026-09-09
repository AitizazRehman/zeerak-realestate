<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectBlockRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'project_id' => 'required|exists:projects,id',

            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',

            'description' => 'nullable|string',

            'total_area' => 'nullable|numeric|min:0',
            'area_unit' => 'nullable|string|max:50',

            'total_units' => 'nullable|integer|min:0',

            'is_active' => 'nullable|boolean',
        ];
    }
}