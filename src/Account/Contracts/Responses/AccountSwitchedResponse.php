<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Contracts\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Accounts\Account\Models\Account;

/**
 * Resposta HTTP da troca de conta — ponto de extensão (ver
 * InvitationAcceptedResponse).
 */
interface AccountSwitchedResponse
{
    public function toResponse(Request $request, Account $account): Response;
}
