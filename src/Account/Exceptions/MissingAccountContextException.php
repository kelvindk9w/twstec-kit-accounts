<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Exceptions;

use LogicException;

/**
 * Uma consulta (ou gravação) de dado de conta rodou SEM conta atual e fora do
 * modo sistema.
 *
 * É o "esquecimento" virando falha visível em vez de vazamento: o escopo da
 * conta nunca devolve os dados de todas as contas por falta de contexto. Numa
 * requisição do painel a conta vem da sessão; na API, da chave; num comando,
 * job ou seeder, declare o que o código faz:
 *
 *     Accounts::asSystem('motivo', fn () => …);        // todas as contas
 *     Accounts::actingAs($conta, fn () => …);          // uma conta
 */
final class MissingAccountContextException extends LogicException
{
    public static function forModel(string $model): self
    {
        return new self(__('accounts.context.missing', ['model' => class_basename($model)]));
    }

    public static function inSystemModeWithoutAccount(string $model): self
    {
        return new self(__('accounts.context.system_write_without_account', ['model' => class_basename($model)]));
    }
}
