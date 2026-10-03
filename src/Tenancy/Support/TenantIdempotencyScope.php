<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Tenancy\TenantContext;
use Twstec\Kit\Foundation\Idempotency\Contracts\IdempotencyScopeResolver;

/**
 * DE QUEM é a `Idempotency-Key` (ver o contrato no foundation):
 *
 * - requisição da API autenticada pelo `resolve.tenant`: a CONTA da chave +
 *   a CHAVE DE API que fez a chamada (`account:<uuid>|key:<uuid>`). Duas
 *   integrações da mesma conta, cada uma com a sua chave, não colidem; a
 *   mesma integração depois de rotacionar a chave começa um escopo novo;
 * - requisição de SESSÃO (painel): a conta atual + a pessoa
 *   (`account:<uuid>|user:<id>`);
 * - nenhum dos dois (sem conta identificada, modo sistema, ninguém
 *   autenticado): null — o middleware RECUSA a requisição com chave (falha
 *   fechada). Nunca cai para "só a pessoa" ou para o IP.
 *
 * Registrado no container pelo AccountsServiceProvider (`bindIf`: um
 * resolvedor do aplicativo vence).
 */
final class TenantIdempotencyScope implements IdempotencyScopeResolver
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly CurrentAccount $accounts,
    ) {}

    public function resolve(Request $request): ?string
    {
        $apiKey = $this->tenant->apiKey();
        $account = $this->tenant->account();

        if ($this->tenant->resolved() && $apiKey !== null && $account !== null) {
            return 'account:'.$account->uuid.'|key:'.$apiKey->uuid;
        }

        $user = $request->user();
        $current = $this->accounts->account();

        if ($user instanceof Authenticatable && $current !== null && ! $this->accounts->isSystem()) {
            return 'account:'.$current->uuid.'|user:'.$user->getAuthIdentifier();
        }

        return null;
    }
}
