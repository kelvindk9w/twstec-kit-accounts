<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Accounts\Accounts;

/**
 * Middleware de teste de uma "área de operação" (como o /admin): declara o
 * modo sistema da requisição e segue, sem envolver o $next.
 */
final class DeclaresSystemModeForRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        Accounts::systemModeForRequest('área de operação');

        return $next($request);
    }
}
