<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twstec\Kit\Accounts\Tenancy\Exceptions\ApiLimitExceededException;
use Twstec\Kit\Accounts\Tenancy\Exceptions\ApiLimitsUnavailableException;
use Twstec\Kit\Accounts\Tenancy\Limits\ApiLimitCounter;
use Twstec\Kit\Accounts\Tenancy\TenantContext;
use Twstec\Kit\Foundation\Security\ClientBucket;

/**
 * LIMITE PRÓPRIO DE UMA ROTA, por conta: `api.route-limit:<máximo>,<segundos>`
 * (ex.: `api.route-limit:30,60` — 30 por minuto por conta nesta rota).
 *
 * Opcional e declarado na rota: vale sempre que declarado, com ou sem
 * API_LIMITS_ENABLED, e SOMA-SE aos limites da conta (a requisição conta
 * na cota também). Conta pela conta da chave; numa rota sem conta
 * resolvida, pelo IP. Passou: 429 `api_route_limit_exceeded` com
 * Retry-After. Store fora do ar: 503 `api_limits_unavailable`.
 */
final class LimitApiRoute
{
    public const ALIAS = 'api.route-limit';

    public function __construct(
        private readonly TenantContext $context,
        private readonly ApiLimitCounter $counter,
    ) {}

    public function handle(Request $request, Closure $next, string $max, string $seconds = '60'): Response
    {
        if (preg_match('/^[1-9]\d*$/', $max) !== 1 || preg_match('/^[1-9]\d*$/', $seconds) !== 1) {
            throw new InvalidArgumentException(sprintf('`%s:%s,%s`: o máximo e a janela (segundos) precisam ser inteiros positivos.', self::ALIAS, $max, $seconds));
        }

        $account = $this->context->resolved() ? $this->context->account() : null;
        $subject = $account !== null ? 'account:'.(string) $account->uuid : 'ip:'.ClientBucket::for($request);

        try {
            $retryAfter = $this->counter->hitRoute($subject, $this->routeId($request), (int) $max, (int) $seconds);
        } catch (Throwable $e) {
            Log::critical('api.limits.unavailable', ['exception' => $e::class, 'message' => $e->getMessage()]);

            throw new ApiLimitsUnavailableException($e);
        }

        if ($retryAfter !== null) {
            throw ApiLimitExceededException::route($retryAfter);
        }

        return $next($request);
    }

    /**
     * A rota pelo nome; sem nome, pelo método + URI declarada (nunca a URL
     * com os parâmetros: `/orders/{order}` é uma rota só).
     */
    private function routeId(Request $request): string
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return $request->method().' '.$request->path();
        }

        return $route->getName() ?? implode('|', $route->methods()).' '.$route->uri();
    }
}
