<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Limits;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use LogicException;

/**
 * Os CONTADORES dos limites da API, no store do RateLimiter
 * (`cache.limiter` — Redis em produção). Toda operação é atômica no próprio
 * store, então vale com vários workers e servidores sem trava de aplicação:
 *
 * - COTA e RAJADA: o RateLimiter do framework (INCR com expiração). A
 *   janela começa na PRIMEIRA requisição e não se renova a cada uma — ao
 *   fim dela, a próxima requisição abre uma janela nova, do zero.
 * - CONCORRÊNCIA: N vagas por conta, cada uma uma trava do store com prazo
 *   (`SET NX EX` no Redis). Entrar = pegar uma vaga livre (a busca começa
 *   numa vaga sorteada); sair = devolver a trava. Uma vaga de processo que
 *   morreu no meio vence sozinha em `lease_seconds` — nada fica preso para
 *   sempre. Com todas ocupadas, a busca custa N consultas ao store.
 * - ROTA: o RateLimiter, numa chave por conta (ou IP) + rota.
 *
 * O store fora do ar faz a operação lançar; quem chama decide (o middleware
 * recusa a requisição — falha fechada, como os outros limites do kit).
 */
final class ApiLimitCounter
{
    public const PREFIX = 'api-limits:';

    public function __construct(private readonly RateLimiter $limiter) {}

    /**
     * Conta esta requisição na cota da conta.
     */
    public function hitQuota(string $accountUuid, int $limit): QuotaUsage
    {
        $key = self::PREFIX.'quota:'.$accountUuid;
        $used = $this->limiter->hit($key, ApiLimits::windowSeconds());

        return new QuotaUsage($limit, $used, max(0, $this->limiter->availableIn($key)));
    }

    /**
     * Conta esta requisição na rajada (por minuto). Devolve em quantos
     * segundos tentar de novo quando passou do teto; nulo quando cabe.
     */
    public function hitBurst(string $accountUuid, int $limit): ?int
    {
        return $this->hit(self::PREFIX.'burst:'.$accountUuid, $limit, 60);
    }

    /**
     * Conta esta requisição no limite da rota. Mesmo retorno de hitBurst().
     */
    public function hitRoute(string $subject, string $route, int $limit, int $decaySeconds): ?int
    {
        return $this->hit(self::PREFIX.'route:'.$subject.'|'.hash('xxh128', $route), $limit, $decaySeconds);
    }

    /**
     * Pega uma vaga de concorrência da conta; nulo quando todas estão
     * ocupadas. Quem recebe a vaga TEM de devolvê-la (release()) no fim.
     */
    public function acquireSlot(string $accountUuid, int $slots, int $leaseSeconds): ?Lock
    {
        $provider = $this->lockProvider();
        $start = random_int(0, $slots - 1);

        for ($i = 0; $i < $slots; $i++) {
            $lock = $provider->lock(self::slotKey($accountUuid, ($start + $i) % $slots), $leaseSeconds);

            if ($lock->get() === true) {
                return $lock;
            }
        }

        return null;
    }

    /**
     * Nome da vaga `$slot` da conta no store (para teste e operação).
     */
    public static function slotKey(string $accountUuid, int $slot): string
    {
        return self::PREFIX.'concurrency:'.$accountUuid.':'.$slot;
    }

    private function hit(string $key, int $limit, int $decaySeconds): ?int
    {
        if ($this->limiter->hit($key, $decaySeconds) <= $limit) {
            return null;
        }

        return max(1, $this->limiter->availableIn($key));
    }

    private function lockProvider(): LockProvider
    {
        $limiterStore = config('cache.limiter');
        $store = Cache::store(is_string($limiterStore) && $limiterStore !== '' ? $limiterStore : null)->getStore();

        if (! $store instanceof LockProvider) {
            throw new LogicException(sprintf('O store de cache do limitador (%s) não oferece travas; o limite de concorrência da API precisa de um que ofereça (redis, database, array...).', $store::class));
        }

        return $store;
    }
}
