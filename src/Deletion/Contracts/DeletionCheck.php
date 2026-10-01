<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Deletion\Contracts;

use Twstec\Kit\Accounts\Deletion\DeletionImpediment;
use Twstec\Kit\Accounts\Deletion\DeletionRequest;

/**
 * Um VERIFICADOR de impedimento de exclusão, declarado pelo aplicativo (ou
 * por outro pacote do kit): diz SE e POR QUE uma pessoa ou uma conta não pode
 * ser excluída agora.
 *
 * Consultado ANTES de qualquer linha sair (ver Deletion\DeletionImpediments):
 * havendo um impedimento, a exclusão é recusada inteira, com a mensagem ao
 * usuário e a recusa na trilha de auditoria — nada é apagado pela metade.
 *
 * Exemplo (um aplicativo cujos lançamentos contábeis apontam para a conta
 * com chave estrangeira RESTRICT e precisam ser guardados por lei):
 *
 *     final class LancamentosImpedemExclusao implements DeletionCheck
 *     {
 *         public function impediments(DeletionRequest $request): iterable
 *         {
 *             $total = Lancamento::query()
 *                 ->whereIn('account_id', $request->accountIds())
 *                 ->count();
 *
 *             if ($total > 0) {
 *                 yield new DeletionImpediment(
 *                     'ledger_entries',
 *                     __('app.exclusao.lancamentos', ['total' => $total]),
 *                 );
 *             }
 *         }
 *     }
 *
 * Regras do contrato:
 * - SÓ LÊ. Nada de apagar, avisar ou gravar aqui: a exclusão ainda pode ser
 *   recusada por outro verificador (e a mesma pergunta é feita pelas telas
 *   antes de pedir a confirmação).
 * - Exceção lançada aqui NÃO é engolida: a exclusão para (falha fechada).
 * - A mensagem é a que o usuário lê e a que vai para a trilha (redigida):
 *   traduzida, sem dado pessoal de terceiros.
 */
interface DeletionCheck
{
    /**
     * Os impedimentos (vazio = pode excluir).
     *
     * @return iterable<DeletionImpediment>
     */
    public function impediments(DeletionRequest $request): iterable;
}
