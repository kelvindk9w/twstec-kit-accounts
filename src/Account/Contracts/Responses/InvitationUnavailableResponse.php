<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Contracts\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Accounts\Account\Exceptions\InvitationUnavailableException;

/**
 * Resposta HTTP quando o convite do link não pode ser usado (expirado,
 * revogado, já usado, e-mail diferente, já é membro, o e-mail já tem conta)
 * — ponto de extensão (ver InvitationAcceptedResponse). A recusa já foi
 * gravada na trilha pela Action.
 */
interface InvitationUnavailableResponse
{
    public function toResponse(Request $request, InvitationUnavailableException $exception): Response;
}
