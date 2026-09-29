<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Contracts\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resposta HTTP da recusa do convite pela pessoa convidada — ponto de
 * extensão (ver InvitationAcceptedResponse).
 */
interface InvitationDeclinedResponse
{
    public function toResponse(Request $request): Response;
}
