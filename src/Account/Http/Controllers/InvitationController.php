<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Accounts\Account\Actions\AcceptInvitation;
use Twstec\Kit\Accounts\Account\Actions\DeclineInvitation;
use Twstec\Kit\Accounts\Account\Actions\RegisterAndAcceptInvitation;
use Twstec\Kit\Accounts\Account\Contracts\Responses\InvitationAcceptedResponse;
use Twstec\Kit\Accounts\Account\Contracts\Responses\InvitationDeclinedResponse;
use Twstec\Kit\Accounts\Account\Contracts\Responses\InvitationUnavailableResponse;
use Twstec\Kit\Accounts\Account\Exceptions\InvitationUnavailableException;
use Twstec\Kit\Accounts\Account\Http\Requests\RegisterFromInvitationRequest;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * Os ENVIOS da tela do link de convite — só HTTP. A regra mora nas Actions;
 * a resposta vem dos contratos (Contracts\Responses). A TELA (GET) é do
 * front. O `throttle:sensitive` vem com o controller (HasMiddleware): uma
 * rota que aponte para ele já nasce limitada.
 *
 * - accept (logado): AcceptInvitation;
 * - register (deslogado, e-mail sem conta): RegisterAndAcceptInvitation;
 * - decline (logado com o mesmo e-mail, ou quem tem o link): DeclineInvitation.
 */
final class InvitationController implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return [new Middleware('throttle:sensitive')];
    }

    public function accept(Request $request, string $token, AcceptInvitation $accept): Response
    {
        /** @var AuthUser $user */
        $user = $request->user();

        try {
            $account = $accept->handle($user, $token);
        } catch (InvitationUnavailableException $exception) {
            return app(InvitationUnavailableResponse::class)->toResponse($request, $exception);
        }

        return app(InvitationAcceptedResponse::class)->toResponse($request, $account);
    }

    public function register(RegisterFromInvitationRequest $request, string $token, RegisterAndAcceptInvitation $register): Response
    {
        /** @var array{name: string, password: string} $input */
        $input = $request->safe()->only(['name', 'password']);

        try {
            $result = $register->handle($request, $token, $input);
        } catch (InvitationUnavailableException $exception) {
            return app(InvitationUnavailableResponse::class)->toResponse($request, $exception);
        }

        return app(InvitationAcceptedResponse::class)->toResponse($request, $result['account']);
    }

    public function decline(Request $request, string $token, DeclineInvitation $decline): Response
    {
        $user = $request->user();

        try {
            $decline->handle($user instanceof AuthUser ? $user : null, $token);
        } catch (InvitationUnavailableException $exception) {
            return app(InvitationUnavailableResponse::class)->toResponse($request, $exception);
        }

        return app(InvitationDeclinedResponse::class)->toResponse($request);
    }
}
