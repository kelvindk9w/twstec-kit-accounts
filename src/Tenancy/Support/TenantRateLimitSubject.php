<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Support;

use Illuminate\Http\Request;
use Twstec\Kit\Accounts\Tenancy\TenantContext;
use Twstec\Kit\Foundation\Security\ApiRateLimit;
use Twstec\Kit\Foundation\Security\Contracts\RateLimitSubjectResolver;

/**
 * O sujeito do limite da API numa requisição autenticada pelo
 * `resolve.tenant`: a CHAVE de API (padrão) ou a CONTA dona dela
 * (RATE_LIMIT_API_BY=tenant — soma as chaves da conta; na conta pessoal o
 * uuid é o da pessoa, então o balde tem o mesmo nome da 1.x). Fora de rota autenticada, null — e o
 * ApiRateLimit conta pelo IP.
 *
 * Registrado no container pelo AccountsServiceProvider (a aplicação pode
 * registrar o dela).
 */
final class TenantRateLimitSubject implements RateLimitSubjectResolver
{
    public function __construct(private readonly TenantContext $context) {}

    public function resolve(Request $request): ?string
    {
        $apiKey = $this->context->apiKey();

        if (! $this->context->resolved() || $apiKey === null) {
            return null;
        }

        if (ApiRateLimit::countsByTenant()) {
            return 'tenant:'.(string) $this->context->account()?->uuid;
        }

        return 'key:'.(string) $apiKey->uuid;
    }
}
