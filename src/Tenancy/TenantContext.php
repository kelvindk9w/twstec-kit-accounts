<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy;

use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * Contexto da requisição da API autenticada por chave.
 *
 * Preenchido pelo middleware ResolveTenant depois de autenticar a secreta:
 * - o TENANT é a CONTA da chave (helper tenant());
 * - a chave que autenticou (helper tenantKey());
 * - a PESSOA por trás da chave, para o que é por pessoa (o token de ação
 *   sensível, o `created_by`): quem criou a chave, enquanto for membro ativo
 *   da conta; senão, o dono da conta. É também o `$request->user()` da rota.
 *
 * Zerado no fim de cada requisição pelo próprio ResolveTenant (nada sobra
 * para a próxima num processo longo — Octane, testes).
 */
final class TenantContext
{
    private ?Account $account = null;

    private ?ApiKey $apiKey = null;

    private ?AuthUser $user = null;

    /**
     * Resolve o tenant da requisição (chamado somente pelo ResolveTenant).
     */
    public function resolve(Account $account, ApiKey $apiKey, AuthUser $user): void
    {
        $this->account = $account;
        $this->apiKey = $apiKey;
        $this->user = $user;
    }

    public function forget(): void
    {
        $this->account = null;
        $this->apiKey = null;
        $this->user = null;
    }

    /**
     * A conta da chave autenticada (o tenant). Null fora de rota tenant.
     */
    public function account(): ?Account
    {
        return $this->account;
    }

    /**
     * A pessoa por trás da chave (ver o docblock da classe). Null fora de
     * rota tenant.
     */
    public function user(): ?AuthUser
    {
        return $this->user;
    }

    /**
     * Chave de API que autenticou a requisição. Null fora de rota tenant.
     */
    public function apiKey(): ?ApiKey
    {
        return $this->apiKey;
    }

    public function resolved(): bool
    {
        return $this->account !== null;
    }
}
