<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Twstec\Kit\Accounts\ApiKeys\Sessions\SessionApiKeys;

/**
 * Rotina das chaves de API de SESSÃO, agendada pelo pacote
 * (`api_keys.session_keys.prune_schedule`, padrão a cada 10 minutos):
 *
 * 1. revoga as de sessão vencida por inatividade (sem ser vista há mais que o
 *    SESSION_LIFETIME) e as de quem já não existe — com o evento
 *    SessionApiKeysRevoked (motivo `expired`);
 * 2. APAGA as que já não valem: revogadas, vencidas, rotacionadas ou com a
 *    carência encerrada.
 *
 * Chaves comuns nunca são tocadas.
 */
final class PruneSessionApiKeys extends Command
{
    protected $signature = 'api-keys:prune-session-keys';

    protected $description = 'Revoga as chaves de API de sessão vencida e apaga as de sessão revogadas ou vencidas.';

    public function handle(SessionApiKeys $sessionKeys): int
    {
        $revoked = $sessionKeys->expireIdle();
        $purged = $sessionKeys->purge();

        $this->info("Chaves de sessão revogadas: {$revoked}. Chaves de sessão apagadas: {$purged}.");

        Log::channel('request_log')->info('api_keys.session_keys.pruned', [
            'revoked' => $revoked,
            'purged' => $purged,
        ]);

        return self::SUCCESS;
    }
}
