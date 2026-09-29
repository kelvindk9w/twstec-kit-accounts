<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Exceptions\MissingAccountContextException;

/**
 * ISOLAMENTO AUTOMÁTICO: toda consulta de um model da conta (Concerns\
 * BelongsToAccount) sai filtrada pela conta atual — leitura, contagem,
 * UPDATE/DELETE em massa, subconsultas de relação (`withCount`, `whereHas`).
 *
 * - Com conta atual: `account_id = conta atual`.
 * - Em modo sistema (Accounts::asSystem): sem filtro, todas as contas.
 * - Sem conta e fora do modo sistema: EXCEÇÃO. Nunca devolve tudo.
 *
 * O filtro é aplicado quando a consulta é EXECUTADA (é assim que o Eloquent
 * aplica escopos globais): o contexto que vale é o do momento da execução.
 */
final class AccountScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     *
     * @throws MissingAccountContextException
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(CurrentAccount::class);

        if ($context->isSystem()) {
            return;
        }

        $builder->where($model->qualifyColumn('account_id'), $context->requireIdFor($model::class));
    }
}
