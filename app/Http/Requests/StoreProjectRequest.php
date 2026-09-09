<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'branch_id' => 'required|exists:branches,id',

            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50',

            'project_type' => 'nullable|string|max:100',
            'description' => 'nullable|string',

            'location' => 'nullable|string|max:255',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',

            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',

            'total_area' => 'nullable|numeric|min:0',
            'area_unit' => 'nullable|string|max:50',

            'start_date' => 'nullable|date',
            'expected_completion_date' => 'nullable|date',
            'actual_completion_date' => 'nullable|date',

            'status' => 'nullable|in:planning,approved,active,under_construction,completed,on_hold,cancelled',

            'construction_progress' => 'nullable|integer|min:0|max:100',

            'budget' => 'nullable|numeric|min:0',
            'actual_cost' => 'nullable|numeric|min:0',

            'cover_image' => 'nullable|string|max:500',

            'is_featured' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ];
    }
}