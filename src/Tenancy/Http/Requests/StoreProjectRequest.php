<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Criação de projeto: nasce SÓ COM NOME.
 */
final class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Autorização é dos middlewares (tenant + scope).
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
