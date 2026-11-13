<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Twstec\Kit\Auth\Enums\SessionEndReason;

/**
 * Chaves de API de SESSÃO foram revogadas porque a sessão delas terminou —
 * o gancho para o aplicativo reagir (limpar o que guardou junto, avisar,
 * registrar).
 *
 * Sai uma vez por pessoa e por motivo, DEPOIS de as chaves estarem
 * revogadas, de forma síncrona (dentro da transação de quem encerrou a
 * sessão, quando há uma):
 * - logout, "sair dos outros aparelhos", troca ou redefinição de senha,
 *   segundo fator ligado/desligado, conta bloqueada → na hora, pelo evento
 *   Twstec\Kit\Auth\Events\SessionsEnded;
 * - sessão vencida por inatividade (SESSION_LIFETIME) ou pessoa que já não
 *   existe → pela rotina `api-keys:prune-session-keys`, com o motivo
 *   `SessionEndReason::Expired`.
 *
 * Só dados simples (o evento pode ir para a fila): as chaves podem já ter
 * sido apagadas pela rotina quando o ouvinte rodar.
 *
 * @param  int|string|null  $userId  Quem criou as chaves (nulo: pessoa excluída).
 * @param  list<string>  $apiKeyUuids  As chaves revogadas.
 */
final class SessionApiKeysRevoked
{
    use Dispatchable;

    /**
     * @param  list<string>  $apiKeyUuids
     */
    public function __construct(
        public readonly int|string|null $userId,
        public readonly SessionEndReason $reason,
        public readonly array $apiKeyUuids,
    ) {}
}
