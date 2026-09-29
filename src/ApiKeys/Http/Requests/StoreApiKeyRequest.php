<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Criação de chave de API. Sempre combinado com os middlewares
 * resolve.tenant + scope:api-keys:create + sensitive.token (rota).
 *
 * - scopes omitido = padrão da config (tudo habilitado, ['*:*']).
 * - expires_at omitido = sem validade (o sistema NUNCA impõe prazo).
 */
final class StoreApiKeyRequest extends FormRequest
{
    /**
     * Formato de scope: recurso:acao, com wildcard permitido nos dois lados.
     */
    public const SCOPE_REGEX = '/^(\*|[a-z0-9][a-z0-9-]*):(\*|[a-z0-9][a-z0-9-]*)$/';

    public function authorize(): bool
    {
        return true; // Autorização é dos middlewares (tenant + scope + token).
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['nullable', 'array', 'min:1'],
            'scopes.*' => ['string', 'regex:'.self::SCOPE_REGEX],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'project_uuids' => ['nullable', 'array'],
            'project_uuids.*' => ['uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scopes.*.regex' => __('api_keys.scopes.invalid_format'),
        ];
    }
}
