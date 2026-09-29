<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Accounts\Account\Actions\SwitchAccount;
use Twstec\Kit\Accounts\Account\Contracts\Responses\AccountSwitchedResponse;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * Troca de conta (o seletor do painel): POST com o uuid da conta. Só HTTP —
 * a regra é da Action SwitchAccount (só conta de que a pessoa é membro;
 * senão 403 e `denied` na trilha).
 */
final class AccountSwitchController
{
    public function store(Request $request, string $account, SwitchAccount $switch): Response
    {
        /** @var AuthUser $user */
        $user = $request->user();

        return app(AccountSwitchedResponse::class)->toResponse($request, $switch->handle($user, $account));
    }
}
