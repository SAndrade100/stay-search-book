<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookRequest extends FormRequest
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
        $partial = $this->isMethod('PATCH');
        $presence = $partial ? 'sometimes' : 'present';

        return [
            'name' => [$presence, 'required', 'string', 'max:255'],
            'isbn' => [
                $presence,
                'nullable',
                'regex:/^(\d{10}|\d{13})$/',
                Rule::unique('books', 'isbn')
                    ->where('user_id', $this->user()->id)
                    ->ignore($this->route('book')),
            ],
            'value' => [$presence, 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'isbn.regex' => 'The isbn must contain digits only (10 or 13 digits).',
            'isbn.unique' => 'You already have a book with this isbn',
        ];
    }
}
