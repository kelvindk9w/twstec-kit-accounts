<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Contracts;

use Twstec\Kit\Accounts\Deletion\DeletionImpediment;
use Twstec\Kit\Accounts\Tenancy\Models\Project;

/**
 * Uma REGRA do aplicativo que impede a exclusão de um projeto: diz SE e POR
 * QUE o projeto não pode sair agora.
 *
 * Consultada pelo ProjectService::delete() — o caminho único da API v1 e das
 * telas — ANTES de o projeto sair (ver Deletion\ProjectDeletionImpediments).
 * Havendo um impedimento, a exclusão é recusada inteira: na API, 409
 * `project_deletion_refused` com o motivo; nas telas, o motivo no lugar do
 * aviso de sucesso.
 *
 * Exemplo (projeto com registros do aplicativo que não podem ficar órfãos):
 *
 *     final class PedidosImpedemExclusaoDoProjeto implements ProjectDeletionCheck
 *     {
 *         public function impediments(Project $project): iterable
 *         {
 *             if (Pedido::query()->where('project_id', $project->getKey())->exists()) {
 *                 yield new DeletionImpediment('project_has_orders', __('app.projetos.tem_pedidos'));
 *             }
 *         }
 *     }
 *
 * Regras do contrato:
 * - SÓ LÊ. Nada de apagar, avisar ou gravar aqui: outra regra ainda pode
 *   recusar a exclusão.
 * - Roda na conta do projeto (a conta atual da requisição): as consultas do
 *   aplicativo saem filtradas por ela, como em qualquer outro lugar.
 * - Exceção lançada aqui NÃO é engolida: a exclusão para (falha fechada).
 * - A mensagem é a que o usuário lê (na tela e no `message` da API):
 *   traduzida, sem dado pessoal de terceiros.
 */
interface ProjectDeletionCheck
{
    /**
     * Os impedimentos (vazio = pode excluir).
     *
     * @return iterable<DeletionImpediment>
     */
    public function impediments(Project $project): iterable;
}
