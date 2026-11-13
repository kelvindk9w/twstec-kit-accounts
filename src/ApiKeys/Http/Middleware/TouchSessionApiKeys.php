<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twstec\Kit\Accounts\ApiKeys\Sessions\SessionApiKeys;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * "A sessão foi vista": no grupo `web` (instalado pelo pacote), renova as
 * chaves de API de sessão desta sessão — é o que deixa a chave morrer quando
 * a sessão vence por inatividade (SESSION_LIFETIME), com qualquer driver de
 * sessão. Sessão sem chave de sessão não paga nada (nem uma consulta).
 *
 * Falha ao marcar não derruba a página: a chave só morre mais cedo (lado
 * seguro), e o motivo vai para o log.
 */
final class TouchSessionApiKeys
{
    public function __construct(private readonly SessionApiKeys $sessionKeys) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $user = $request->user();

            if ($user instanceof AuthUser) {
                try {
                    $this->sessionKeys->touch($request->session(), $user);
                } catch (Throwable $exception) {
                    Log::channel('request_log')->warning('api_keys.session_keys.touch_failed', [
                        'exception' => $exception::class,
                    ]);
                }
            }
        }

        return $next($request);
    }
}
