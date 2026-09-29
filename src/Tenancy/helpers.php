<?php

declare(strict_types=1);

use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\TenantContext;

// =============================================================================
// Helpers globais do módulo de Tenancy.
// Carregado via composer.json → autoload.files.
// =============================================================================

if (! function_exists('tenant')) {
    /**
     * Tenant da requisição corrente da API: a CONTA da chave de API.
     * Null fora de rotas protegidas pelo middleware resolve.tenant.
     *
     * Na 1.x devolvia a pessoa dona da chave; a pessoa por trás da chave agora
     * é `app(TenantContext::class)->user()` (e o `$request->user()` da rota).
     * O uuid da conta pessoal é o da pessoa.
     */
    function tenant(): ?Account
    {
        return app(TenantContext::class)->account();
    }
}

if (! function_exists('tenantKey')) {
    /**
     * Chave de API que autenticou a requisição corrente (null fora de rotas
     * com resolve.tenant). Útil para scopes e vínculos chave↔projeto.
     */
    function tenantKey(): ?ApiKey
    {
        return app(TenantContext::class)->apiKey();
    }
}
