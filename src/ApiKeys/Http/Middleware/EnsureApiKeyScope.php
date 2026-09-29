<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyAttempt;
use Twstec\Kit\Accounts\Tenancy\TenantContext;
use Twstec\Kit\Foundation\Audit\Enums\AuditContext;

/**
 * Autorização por SCOPE da chave de API (menor privilégio).
 *
 * Uso em rotas (SEMPRE depois de resolve.tenant):
 *
 *   Route::post('/pix', ...)->middleware('scope:pix:create');
 *
 * O parâmetro é "recurso:acao" (ex.: customers:read, withdrawals:*).
 * A verificação é ApiKey::allows() — casamento exato ou wildcard.
 * Sem permissão: 403 com mensagem traduzida indicando o scope exigido — e a
 * tentativa na trilha (`api_key.scope_denied`, contexto `api`, `denied`, a
 * chave como alvo e a mesma mensagem como motivo): a chave autenticada tentou
 * além do que pode. (Os 401 e 404 da API ficam fora da trilha — ver
 * ApiKeyAttempt.)
 */
final class EnsureApiKeyScope
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AccountAudit $audit,
    ) {}

    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $apiKey = $this->tenantContext->apiKey();

        if ($apiKey === null || ! $apiKey->allows($scope)) {
            $reason = __('api_keys.scopes.denied', ['scope' => $scope]);

            // Sem chave resolvida (rota sem resolve.tenant) não há quem registrar.
            if ($apiKey !== null) {
                $this->audit->denied(
                    ApiKeyAttempt::ScopeDenied,
                    $this->tenantContext->account(),
                    $this->tenantContext->user(),
                    $reason,
                    $apiKey,
                    context: AuditContext::Api,
                );
            }

            abort(403, $reason);
        }

        return $next($request);
    }
}
