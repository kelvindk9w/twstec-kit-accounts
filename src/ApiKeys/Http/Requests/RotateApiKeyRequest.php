<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rotação de chave de API. Sempre combinado com resolve.tenant +
 * scope:api-keys:rotate + sensitive.token (rota).
 *
 * grace_period_minutes: escolha do usuário sobre a morte da chave antiga —
 * ausente/0 = morte imediata; positivo = janela de coexistência (sem downtime).
 */
final class RotateApiKeyRequest extends FormRequest
{
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
            'grace_period_minutes' => [
                'nullable',
                'integer',
                'min:0',
                'max:'.(int) config('api_keys.rotation.max_grace_minutes', 10080),
            ],
        ];
    }
}
