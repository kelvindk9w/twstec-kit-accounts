<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Exceptions;

use LogicException;

/**
 * Tentativa de gravar um dado numa conta que não é a atual (ou de mudar a
 * conta de um dado já gravado). Só o modo sistema grava em conta explícita.
 */
final class CrossAccountWriteException extends LogicException
{
    public static function forModel(string $model): self
    {
        return new self(__('accounts.context.cross_account_write', ['model' => class_basename($model)]));
    }

    public static function accountChange(string $model): self
    {
        return new self(__('accounts.context.account_change', ['model' => class_basename($model)]));
    }
}
