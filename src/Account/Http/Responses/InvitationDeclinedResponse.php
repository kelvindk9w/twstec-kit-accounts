<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Http\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Accounts\Account\Contracts\Responses\InvitationDeclinedResponse as Contract;

/**
 * Padrão: volta à tela do link (que agora diz "recusado") com o aviso.
 * JSON: 204.
 */
final class InvitationDeclinedResponse implements Contract
{
    public function toResponse(Request $request): Response
    {
        if ($request->expectsJson()) {
            return response()->noContent();
        }

        return redirect()->back()->with('status', __('accounts.invitations.declined'));
    }
}
