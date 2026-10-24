<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBookRequest extends FormRequest
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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'isbn' => [
                'nullable',
                'regex:/^(\d{10}|\d{13})$/',
                Rule::unique('books', 'isbn')->where('user_id', $this->user()->id),
            ],
            'value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'isbn.regex' => 'The isbn must contain digits only (10 or 13 digits).',
            'isbn.unique' => 'You already have a book with this isbn.',
        ];
    }
}
