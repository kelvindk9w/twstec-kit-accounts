<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;

/**
 * As chaves de API de SESSÃO (presas a uma sessão web —
 * ApiKeyService::createForSession) ficam FORA de toda consulta por padrão (escopo global do model ApiKey):
 * não aparecem na lista de chaves do painel nem da API v1, não entram em
 * contagem (limite de chaves, métricas, visão geral da conta), não recebem
 * aviso de inatividade nem contam como chave órfã.
 *
 * Quem precisa delas pede de propósito (escopos locais do model):
 * - `ApiKey::query()->withSessionKeys()` — todas;
 * - `ApiKey::query()->onlySessionKeys()` — só as de sessão.
 *
 * A autenticação da API (ResolveTenant) e as rotinas do pacote já pedem.
 * O escopo das contas (BelongsToAccount) continua valendo nos dois casos.
 *
 * @implements Scope<ApiKey>
 */
final class SessionKeyScope implements Scope
{
    /**
     * @param  Builder<covariant ApiKey>  $builder
     * @param  ApiKey  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->qualifyColumn('session_binding'));
    }
}
