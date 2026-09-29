<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * Uma pessoa está para ser excluída e a regra das contas NÃO a recusou (ela
 * não é dona de conta com outros membros). Disparado no `deleting` do model
 * de usuário, antes de a linha sair do banco.
 *
 * SÓ PARA LER: outra guarda pode ainda recusar a exclusão depois deste evento
 * (uma conta protegida, por exemplo) — então o ouvinte guarda o que vai
 * precisar e NÃO muda nada. O efeito vem no PersonDeleted, que só acontece se
 * a exclusão aconteceu.
 *
 * `vanishingAccountIds`: as contas que saem junto com a pessoa (as de que ela
 * é dona — nenhuma tem outros membros, senão a exclusão teria sido recusada).
 * No PostgreSQL elas somem na MESMA sentença que apaga a pessoa (gatilho +
 * cascata): o que for preciso ler delas tem de ser lido aqui.
 *
 * @param  list<int>  $vanishingAccountIds
 */
final class PersonDeleting
{
    use Dispatchable;

    public function __construct(
        public readonly AuthUser $user,
        public readonly array $vanishingAccountIds,
    ) {}
}
