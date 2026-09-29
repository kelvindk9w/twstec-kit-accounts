<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Http\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Accounts\Account\Contracts\Responses\AccountSwitchedResponse as Contract;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Foundation\Http\SafeRedirect;

/**
 * Padrão: volta à MESMA tela (que passa a mostrar a conta nova — toda tela
 * do painel lê a conta atual), pelo SafeRedirect: só destino de dentro da
 * aplicação; fora disso, o painel. JSON: 200 com a conta.
 */
final class AccountSwitchedResponse implements Contract
{
    public function toResponse(Request $request, Account $account): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['data' => ['account' => ['uuid' => $account->uuid, 'name' => $account->displayName()]]]);
        }

        return redirect()
            ->to(SafeRedirect::url(url()->previous(), route('dashboard')))
            ->with('status', __('accounts.switch.switched', ['account' => $account->displayName()]));
    }
}
