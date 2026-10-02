<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Deletion\Exceptions;

use LogicException;

/**
 * `delete()` direto no model da CONTA, fora do caminho único de exclusão
 * (Deletion\AccountDeletion::deleteAccount) — recusado ANTES de qualquer
 * linha sair, com a recusa na trilha de auditoria.
 *
 * Por que recusar em vez de deixar sair: a exclusão da conta leva junto o que
 * é dela (projetos, chaves, vínculos, convites), avisa quem guarda dado dela
 * fora deste pacote (os uploads, pela LGPD) e pergunta antes aos
 * verificadores de impedimento. Um `delete()` solto pularia tudo isso.
 */
final class DeletionOutsideServiceException extends LogicException
{
    public function __construct()
    {
        parent::__construct(__('accounts.deletion.outside_service'));
    }
}
