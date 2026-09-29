<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Twstec\Kit\Auth\PasswordPolicy;

/**
 * Criar a conta pelo convite: nome e senha (pela política de senha do kit —
 * a mesma do cadastro). O e-mail NÃO vem do formulário: é o do convite.
 */
final class RegisterFromInvitationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('accounts.invitations.fields.name'),
            'password' => __('accounts.invitations.fields.password'),
        ];
    }
}
