<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use Twstec\Kit\Foundation\Http\Exceptions\Contracts\ProvidesServerErrorCode;

/**
 * O store dos contadores dos limites da API (Redis) não respondeu: a
 * requisição NÃO roda — falha fechada, como os demais limites do kit (sem
 * contador, ninguém sabe se a conta ainda pode). 503 `api_limits_unavailable`
 * com Retry-After; o detalhe do erro fica só no log, nunca na resposta.
 */
final class ApiLimitsUnavailableException extends HttpException implements ProvidesServerErrorCode
{
    public const CODE = 'api_limits_unavailable';

    public function __construct(?Throwable $previous = null)
    {
        parent::__construct(503, 'API limits store unavailable.', $previous, ['Retry-After' => 5]);
    }

    public function apiErrorCode(): string
    {
        return self::CODE;
    }
}
