<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * A pessoa saiu do banco (evento `deleted` do model de usuário), junto com as
 * contas de que era dona. Disparado ANTES de o pacote arrumar o resto
 * (AccountService::cleanUpAfterPersonDeleted): o ouvinte que apaga dado dessas
 * contas o faz aqui, uma vez só — a exclusão das contas que vem depois (nos
 * bancos sem os gatilhos) já não encontra o que ele apagou.
 *
 * Roda dentro da transação da exclusão, quando há uma: efeito que não se
 * desfaz (disco, e-mail) vai para depois do commit.
 *
 * @param  list<int>  $vanishingAccountIds  As mesmas do PersonDeleting.
 */
final class PersonDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly AuthUser $user,
        public readonly array $vanishingAccountIds,
    ) {}
}
