<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Exceptions\CrossAccountWriteException;
use Twstec\Kit\Accounts\Account\Exceptions\MissingAccountContextException;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Scopes\AccountScope;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Support\UserModel;

/**
 * Model que PERTENCE A UMA CONTA (colunas `account_id` e `created_by`).
 *
 * - Leitura: o escopo global AccountScope filtra pela conta atual (ou lança
 *   exceção sem conta; o modo sistema vê todas).
 * - Criação: `account_id` vem da conta atual; informar outra conta é
 *   recusado (CrossAccountWriteException). Em modo sistema a conta precisa
 *   ser informada. `created_by` vem de quem está agindo, se não foi dado.
 * - Atualização: a conta de um registro não muda.
 * - Registro SEM conta: só quando o model declara que aquele registro pode
 *   não ter conta (allowsRecordWithoutAccount() — hoje só a foto pessoal do
 *   pacote de uploads) E em modo sistema declarado. Fora do modo sistema a
 *   conta atual é sempre gravada; uma trava de arquitetura lista os models
 *   que declaram a exceção.
 *
 * Todo model com `account_id` usa esta trait — uma trava de arquitetura no
 * starter confere.
 */
trait BelongsToAccount
{
    public static function bootBelongsToAccount(): void
    {
        static::addGlobalScope(new AccountScope);

        static::creating(function (Model $model): void {
            $context = app(CurrentAccount::class);
            $informada = $model->getAttribute('account_id');

            if ($context->isSystem()) {
                if ($informada === null && ! $model->allowsRecordWithoutAccount()) {
                    throw MissingAccountContextException::inSystemModeWithoutAccount($model::class);
                }
            } else {
                $atual = $context->requireIdFor($model::class);

                if ($informada === null) {
                    $model->setAttribute('account_id', $atual);
                } elseif ((int) $informada !== $atual) {
                    throw CrossAccountWriteException::forModel($model::class);
                }
            }

            if ($model->getAttribute('created_by') === null) {
                $model->setAttribute('created_by', $context->actor()?->getKey());
            }
        });

        static::updating(function (Model $model): void {
            if ($model->isDirty('account_id')) {
                throw CrossAccountWriteException::accountChange($model::class);
            }
        });
    }

    /**
     * Este registro pode existir SEM conta? Padrão: não. Um model que declara
     * a exceção só grava o registro sem conta em modo sistema declarado (e a
     * lista desses models é revisada numa trava de arquitetura).
     */
    public function allowsRecordWithoutAccount(): bool
    {
        return false;
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Quem criou (pode ter saído da conta ou ter sido excluído — o registro
     * continua da conta).
     *
     * @return BelongsTo<Model&AuthUser, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(UserModel::name(), 'created_by');
    }
}
