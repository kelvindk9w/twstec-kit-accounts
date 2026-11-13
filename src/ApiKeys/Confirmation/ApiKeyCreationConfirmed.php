<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Confirmation;

use LogicException;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * Prova de que UMA criação de chave foi confirmada pela política do ambiente
 * (ApiKeyCreationConfirmation::confirm), por esta pessoa. Uso único: a
 * criação que a recebe a consome (ApiKeyService::createForSession).
 *
 * @internal Só o ApiKeyCreationConfirmation cria; quem cria chave recebe e repassa.
 */
final class ApiKeyCreationConfirmed
{
    private bool $consumed = false;

    private function __construct(
        private readonly string $userId,
        public readonly ApiKeyConfirmationPolicy $policy,
    ) {}

    /**
     * @internal
     */
    public static function issue(AuthUser $user, ApiKeyConfirmationPolicy $policy): self
    {
        return new self((string) $user->getAuthIdentifier(), $policy);
    }

    /**
     * Consome a prova para a criação feita por `$creator`.
     *
     * @throws LogicException Já usada, ou confirmada por outra pessoa.
     */
    public function consumeFor(AuthUser $creator): void
    {
        if ($this->consumed) {
            throw new LogicException('A confirmação da criação de chave já foi usada: cada chave pede a sua.');
        }

        if ($this->userId !== (string) $creator->getAuthIdentifier()) {
            throw new LogicException('A confirmação da criação de chave é de outra pessoa.');
        }

        $this->consumed = true;
    }
}
