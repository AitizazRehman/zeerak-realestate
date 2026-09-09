<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePropertyRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'project_id' => [
                'required',
                'exists:projects,id',
            ],

            'block_id' => [
                'required',
                'exists:project_blocks,id',
            ],

            'property_number' => [
                'required',
                'string',
                'max:100',
            ],

            'property_type' => [
                'required',
                'string',
                'max:100',
            ],

            'size' => [
                'required',
                'numeric',
                'min:0',
            ],

            'size_unit' => [
                'required',
                'string',
                'max:50',
            ],

            'price' => [
                'required',
                'numeric',
                'min:0',
            ],

            'discount' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'status' => [
                'required',
                'in:available,reserved,booked,sold,under_construction,rented,unavailable,cancelled',
            ],

            'bedrooms' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],

            'bathrooms' => [
                'nullable',
                'integer',
                'min:0',
                'max:50',
            ],

            'covered_area' => [
                'nullable',
                'numeric',
                'min:0',
            ],

            'covered_area_unit' => [
                'nullable',
                'string',
                'max:50',
            ],

            'address' => [
                'nullable',
                'string',
                'max:500',
            ],

            'latitude' => [
                'nullable',
                'numeric',
                'between:-90,90',
            ],

            'longitude' => [
                'nullable',
                'numeric',
                'between:-180,180',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'assigned_agent_id' => [
                'nullable',
                'exists:users,id',
            ],

            'is_featured' => [
                'boolean',
            ],

            'is_published' => [
                'boolean',
            ],
        ];
    }
}