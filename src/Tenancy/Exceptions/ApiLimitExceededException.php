<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Exceptions;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Twstec\Kit\Foundation\Http\Exceptions\Contracts\ProvidesApiErrorCode;

/**
 * Um LIMITE DA API DA CONTA foi atingido — 429 no envelope de erro, com
 * `Retry-After` e os cabeçalhos da cota (RateLimit-*).
 *
 * | code                        | quando                                              |
 * |-----------------------------|-----------------------------------------------------|
 * | `api_quota_exceeded`        | a cota da janela acabou (Retry-After = fim da janela) |
 * | `api_burst_exceeded`        | passou do teto por minuto                           |
 * | `api_concurrency_exceeded`  | requisições demais da conta em andamento            |
 * | `api_route_limit_exceeded`  | passou do limite próprio da rota (`api.route-limit`) |
 */
final class ApiLimitExceededException extends TooManyRequestsHttpException implements ProvidesApiErrorCode
{
    public const QUOTA = 'api_quota_exceeded';

    public const BURST = 'api_burst_exceeded';

    public const CONCURRENCY = 'api_concurrency_exceeded';

    public const ROUTE = 'api_route_limit_exceeded';

    /**
     * @param  array<string, int|string>  $headers
     */
    private function __construct(private readonly string $errorCode, int $retryAfter, array $headers = [])
    {
        parent::__construct(max(1, $retryAfter), (string) __('api.errors.'.$errorCode), null, 0, $headers);
    }

    /**
     * @param  array<string, int|string>  $headers
     */
    public static function quota(int $retryAfter, array $headers = []): self
    {
        return new self(self::QUOTA, $retryAfter, $headers);
    }

    /**
     * @param  array<string, int|string>  $headers
     */
    public static function burst(int $retryAfter, array $headers = []): self
    {
        return new self(self::BURST, $retryAfter, $headers);
    }

    /**
     * @param  array<string, int|string>  $headers
     */
    public static function concurrency(int $retryAfter, array $headers = []): self
    {
        return new self(self::CONCURRENCY, $retryAfter, $headers);
    }

    public static function route(int $retryAfter): self
    {
        return new self(self::ROUTE, $retryAfter);
    }

    public function apiErrorCode(): string
    {
        return $this->errorCode;
    }
}
