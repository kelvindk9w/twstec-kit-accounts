<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Http\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Accounts\Account\Contracts\Responses\InvitationUnavailableResponse as Contract;
use Twstec\Kit\Accounts\Account\Exceptions\InvitationUnavailableException;

/**
 * Padrão: volta à tela do link com o motivo (a tela recalcula o estado).
 * JSON: 409 para "o e-mail já tem conta" (é para entrar, não cadastrar),
 * 403 para e-mail diferente e 410 para o resto — sempre só o motivo, nunca
 * dado da conta.
 */
final class InvitationUnavailableResponse implements Contract
{
    public function toResponse(Request $request, InvitationUnavailableException $exception): Response
    {
        if ($request->expectsJson()) {
            $status = match ($exception->reason) {
                InvitationUnavailableException::HAS_ACCOUNT, InvitationUnavailableException::ALREADY_MEMBER => 409,
                InvitationUnavailableException::WRONG_EMAIL => 403,
                InvitationUnavailableException::NOT_FOUND => 404,
                default => 410,
            };

            return response()->json(['error' => ['code' => 'invitation_'.$exception->reason, 'message' => $exception->getMessage()]], $status);
        }

        return redirect()->back()->with('invitation_error', $exception->getMessage());
    }
}
