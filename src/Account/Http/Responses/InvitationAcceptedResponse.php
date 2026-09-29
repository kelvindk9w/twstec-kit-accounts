<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Http\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Accounts\Account\Contracts\Responses\InvitationAcceptedResponse as Contract;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Accounts;

/**
 * Padrão: seleciona a conta do convite (a pessoa acabou de entrar nela) e
 * leva ao painel com o aviso. JSON: 200 com a conta.
 */
final class InvitationAcceptedResponse implements Contract
{
    public function toResponse(Request $request, Account $account): Response
    {
        Accounts::switchTo($account);

        if ($request->expectsJson()) {
            return response()->json(['data' => ['account' => ['uuid' => $account->uuid, 'name' => $account->displayName()]]]);
        }

        return redirect()
            ->route('dashboard')
            ->with('status', __('accounts.invitations.accepted', ['account' => $account->displayName()]));
    }
}
