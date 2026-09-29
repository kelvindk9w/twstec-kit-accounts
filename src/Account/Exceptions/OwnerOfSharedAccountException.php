<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Exceptions;

use RuntimeException;

/**
 * Exclusão de pessoa recusada: ela é DONA de conta(s) que têm outros
 * membros. A propriedade precisa ser transferida antes (a transferência
 * chega com as telas de membros); excluir levaria os dados de uma empresa
 * que tem outras pessoas.
 */
final class OwnerOfSharedAccountException extends RuntimeException
{
    /**
     * @param  list<string>  $accounts  Códigos públicos das contas.
     */
    public function __construct(public readonly array $accounts)
    {
        parent::__construct(self::messageFor($accounts));
    }

    /**
     * @param  list<string>  $accounts
     */
    public static function messageFor(array $accounts): string
    {
        return trans_choice('accounts.deletion.owner_has_members', count($accounts), [
            'accounts' => implode(', ', $accounts),
        ]);
    }
}
