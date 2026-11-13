<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Middleware;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Twstec\Kit\Accounts\Tenancy\Exceptions\ApiLimitExceededException;
use Twstec\Kit\Accounts\Tenancy\Exceptions\ApiLimitsUnavailableException;
use Twstec\Kit\Accounts\Tenancy\Limits\ApiLimitCounter;
use Twstec\Kit\Accounts\Tenancy\Limits\ApiLimits;
use Twstec\Kit\Accounts\Tenancy\Limits\QuotaUsage;
use Twstec\Kit\Accounts\Tenancy\TenantContext;

/**
 * LIMITES DA API POR CONTA (`api.limits`, no grupo `api`; ver ApiLimits).
 *
 * Roda logo DEPOIS do `resolve.tenant` (lista de prioridade — quem é a conta
 * só se sabe ali) e ANTES da idempotência: o replay de uma Idempotency-Key
 * conta como qualquer requisição. Sem conta resolvida (rota pública, chave
 * recusada) ou com API_LIMITS_ENABLED=false, não faz nada.
 *
 * Ordem, numa requisição autenticada:
 *
 *   1. COTA da janela — passou: 429 `api_quota_exceeded`, Retry-After até o
 *      fim da janela;
 *   2. RAJADA por minuto — 429 `api_burst_exceeded`;
 *   3. CONCORRÊNCIA — sem vaga: 429 `api_concurrency_exceeded`. A vaga é
 *      devolvida no fim, inclusive quando a requisição falha.
 *
 * Toda resposta da requisição autenticada leva RateLimit-Limit,
 * RateLimit-Remaining e RateLimit-Reset (segundos até a janela recomeçar) —
 * sempre da COTA, também nas recusas por outro limite.
 *
 * Store dos contadores fora do ar: a requisição não roda (503
 * `api_limits_unavailable`, log `api.limits.unavailable`) — a mesma falha
 * fechada dos outros limites do kit.
 */
final class EnforceAccountApiLimits
{
    public const ALIAS = 'api.limits';

    public function __construct(
        private readonly TenantContext $context,
        private readonly ApiLimits $limits,
        private readonly ApiLimitCounter $counter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $account = $this->context->resolved() ? $this->context->account() : null;

        if ($account === null || ! ApiLimits::enabled()) {
            return $next($request);
        }

        $uuid = (string) $account->uuid;
        $limits = $this->limits->effective($account);

        [$usage, $slot] = $this->guarded(function () use ($uuid, $limits): array {
            $usage = $this->counter->hitQuota($uuid, $limits->quota);

            if ($usage->exceeded()) {
                throw ApiLimitExceededException::quota($usage->resetInSeconds, $usage->headers());
            }

            $retryAfter = $this->counter->hitBurst($uuid, $limits->burstPerMinute);

            if ($retryAfter !== null) {
                throw ApiLimitExceededException::burst($retryAfter, $usage->headers());
            }

            $slot = $this->counter->acquireSlot($uuid, $limits->concurrency, self::leaseSeconds());

            if ($slot === null) {
                throw ApiLimitExceededException::concurrency(self::concurrencyRetryAfter(), $usage->headers());
            }

            return [$usage, $slot];
        });

        try {
            $response = $next($request);
        } finally {
            $this->release($slot, $uuid);
        }

        return $this->withHeaders($response, $usage);
    }

    /**
     * Roda a contagem; falha do store (não a recusa de limite) vira 503.
     *
     * @param  Closure(): array{QuotaUsage, Lock}  $count
     * @return array{QuotaUsage, Lock}
     *
     * @throws ApiLimitExceededException|ApiLimitsUnavailableException
     */
    private function guarded(Closure $count): array
    {
        try {
            return $count();
        } catch (ApiLimitExceededException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::critical('api.limits.unavailable', ['exception' => $e::class, 'message' => $e->getMessage()]);

            throw new ApiLimitsUnavailableException($e);
        }
    }

    /**
     * Devolve a vaga. Se o store falhar agora, a vaga vence sozinha no fim do
     * prazo (`lease_seconds`) — a resposta já pronta não é perdida por isso.
     */
    private function release(Lock $slot, string $accountUuid): void
    {
        try {
            $slot->release();
        } catch (Throwable $e) {
            Log::warning('api.limits.release_failed', ['account' => $accountUuid, 'exception' => $e::class]);
        }
    }

    private function withHeaders(Response $response, QuotaUsage $usage): Response
    {
        foreach ($usage->headers() as $name => $value) {
            $response->headers->set($name, (string) $value);
        }

        return $response;
    }

    private static function leaseSeconds(): int
    {
        $value = config('api_keys.limits.lease_seconds', 300);

        return max(1, is_numeric($value) ? (int) $value : 300);
    }

    private static function concurrencyRetryAfter(): int
    {
        $value = config('api_keys.limits.concurrency_retry_after_seconds', 1);

        return max(1, is_numeric($value) ? (int) $value : 1);
    }
}
