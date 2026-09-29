<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Contracts\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Accounts\Account\Models\Account;

/**
 * Resposta HTTP do aceite de convite (logado, ou criando a conta) — ponto de
 * extensão.
 *
 * A regra já rodou (Actions\AcceptInvitation / RegisterAndAcceptInvitation);
 * a resposta só decide o que devolver. A padrão (Http\Responses) seleciona a
 * conta do convite e leva ao painel; outro front (o starter React, uma API
 * JSON) registra a sua no container — o padrão é `bindIf`, o do aplicativo
 * vence.
 */
interface InvitationAcceptedResponse
{
    public function toResponse(Request $request, Account $account): Response;
}
