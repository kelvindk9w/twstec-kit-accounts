<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Support\UserModel;

/**
 * Acha um MEMBRO da conta pelo uuid da pessoa. Quem não é membro desta conta
 * (ou nem existe) é a mesma ModelNotFoundException — 404 uniforme, como
 * projeto ou chave de outra conta.
 */
trait FindsAccountMember
{
    /**
     * @return Model&AuthUser
     */
    protected function memberOf(Account $account, string $userUuid): AuthUser
    {
        /** @var (Model&AuthUser)|null $user */
        $user = UserModel::name()::query()->where('uuid', $userUuid)->first();

        if ($user === null || ! $account->hasMember($user)) {
            throw (new ModelNotFoundException)->setModel(UserModel::name());
        }

        return $user;
    }
}
