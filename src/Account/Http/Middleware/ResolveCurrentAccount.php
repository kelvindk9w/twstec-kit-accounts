<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * A conta atual de cada requisição WEB — instalado pelo pacote no fim do
 * grupo `web` (AccountsServiceProvider).
 *
 * - Começa e termina a requisição sem modo sistema de requisição e sem o
 *   cache da sessão: nada de uma requisição sobra para a seguinte num
 *   processo longo (o fim de toda requisição HTTP também faz isso — evento
 *   RequestHandled, no provider).
 * - A conta em si é resolvida sob demanda por CurrentAccount: a selecionada
 *   na sessão, se a pessoa ainda é membro, senão a conta pessoal. Uma
 *   seleção que não vale mais (a pessoa saiu da conta) sai da sessão aqui.
 *
 * Opt-out (só explícito, com aviso no log): ACCOUNTS_WEB_MIDDLEWARE=false.
 * Sem ele a conta atual continua sendo resolvida, mas o estado não é zerado
 * por requisição.
 */
final class ResolveCurrentAccount
{
    public function __construct(
        private readonly CurrentAccount $context,
        private readonly AccountService $accounts,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->context->endRequest();

        try {
            $this->forgetStaleSelection($request);

            return $next($request);
        } finally {
            $this->context->endRequest();
        }
    }

    private function forgetStaleSelection(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $selecionada = $request->session()->get(CurrentAccount::sessionKey());

        if (! is_string($selecionada) || $selecionada === '') {
            return;
        }

        $user = $request->user(CurrentAccount::webGuard());

        $valida = $user instanceof AuthUser
            && $this->accounts->resolveForPerson($user, $selecionada)?->uuid === $selecionada;

        if (! $valida) {
            $request->session()->forget(CurrentAccount::sessionKey());
        }
    }
}
