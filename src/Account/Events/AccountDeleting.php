<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Twstec\Kit\Accounts\Account\Models\Account;

/**
 * A conta vai ser excluída AGORA, com os dados dela — disparado por
 * AccountService::deleteAccount() DENTRO da transação da exclusão, antes de
 * qualquer linha sair. Quem guarda dado da conta fora deste pacote (os
 * uploads, por exemplo) apaga o que é dela aqui, na mesma transação: se a
 * exclusão for desfeita, o que o ouvinte apagou volta junto.
 *
 * Efeito que não se desfaz (apagar arquivo do disco, mandar e-mail) vai para
 * DEPOIS do commit (`DB::afterCommit`, job com `afterCommit()`).
 *
 * Quando a conta sai junto com a PESSOA dona dela (exclusão de pessoa), os
 * eventos são PersonDeleting / PersonDeleted — no PostgreSQL a conta sai na
 * mesma sentença que apaga a pessoa (gatilho), antes de qualquer ouvinte PHP.
 */
final class AccountDeleting
{
    use Dispatchable;

    public function __construct(public readonly Account $account) {}
}
