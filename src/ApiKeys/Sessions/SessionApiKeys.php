<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Sessions;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Events\SessionApiKeysRevoked;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Enums\SessionEndReason;
use Twstec\Kit\Auth\Events\SessionsEnded;
use Twstec\Kit\Auth\Sessions\SessionBinding;

/**
 * O ciclo de vida das chaves de API de SESSÃO (ApiKeyService::createForSession):
 *
 * - revokeEnded(): a sessão terminou (Twstec\Kit\Auth\Events\SessionsEnded —
 *   logout, outros aparelhos, troca de senha, bloqueio) → as chaves dela são
 *   revogadas NA HORA;
 * - touch(): a sessão foi vista (grupo `web`, middleware
 *   TouchSessionApiKeys) → `session_seen_at` renovado, no máximo uma escrita
 *   a cada `api_keys.session_keys.touch_throttle_seconds`;
 * - expireIdle(): a sessão venceu por inatividade (sem ser vista há mais que
 *   o SESSION_LIFETIME) ou quem criou já não existe → revogadas (a chave já
 *   era recusada na autenticação — ApiKey::isUsable);
 * - purge(): revogadas, vencidas, rotacionadas ou com a carência encerrada
 *   → APAGADAS (a trilha de requisições guarda o uuid da chave).
 *
 * Toda revogação vira a linha `api_keys.session_keys.revoked` no canal
 * `request_log` (uuids, motivo, pessoa — nunca segredo) e o evento
 * Events\SessionApiKeysRevoked. Tudo em modo sistema declarado: a sessão que
 * termina pode ser de outra conta que não a atual.
 */
final class SessionApiKeys
{
    public const SESSION_TOUCHED_AT = 'kit_accounts.session_api_keys_seen_at';

    public const REVOKED_EVENT = 'api_keys.session_keys.revoked';

    public function __construct(private readonly ApiKeyService $apiKeys) {}

    /**
     * Revoga as chaves das sessões que o evento encerrou. Devolve quantas.
     */
    public function revokeEnded(SessionsEnded $event): int
    {
        $userId = $event->user->getAuthIdentifier();

        if ($userId === null) {
            return 0;
        }

        $keys = Accounts::asSystem('api_keys:session-ended', fn (): array => array_values(ApiKey::query()
            ->onlySessionKeys()
            ->where('created_by', $userId)
            ->where('status', ApiKeyStatus::Active)
            ->get()
            ->filter(static fn (ApiKey $key): bool => $event->ends((string) $key->session_binding))
            ->all()));

        return $this->revokeAll($keys, $event->reason);
    }

    /**
     * A sessão desta requisição foi vista: renova as chaves dela (só as que
     * ainda valem — sessão vencida não ressuscita chave).
     */
    public function touch(Session $session, AuthUser $user): void
    {
        $binding = SessionBinding::current($session, $user);

        if ($binding === null) {
            return;
        }

        $last = $session->get(self::SESSION_TOUCHED_AT);
        $throttle = max(0, (int) config('api_keys.session_keys.touch_throttle_seconds', 60));

        if (is_int($last) && $last > now()->getTimestamp() - $throttle) {
            return;
        }

        Accounts::asSystem('api_keys:session-touch', fn (): int => ApiKey::query()
            ->onlySessionKeys()
            ->where('session_binding', $binding)
            ->where('status', ApiKeyStatus::Active)
            ->where(fn (Builder $query) => $this->seenSince($query, $this->idleCutoff()))
            ->update(['session_seen_at' => now()]));

        $session->put(self::SESSION_TOUCHED_AT, now()->getTimestamp());
    }

    /**
     * Revoga as chaves de sessão vencida por inatividade e as de quem já não
     * existe. Devolve quantas.
     */
    public function expireIdle(): int
    {
        $cutoff = $this->idleCutoff();

        $keys = Accounts::asSystem('console:api-keys:prune-session-keys', fn (): array => array_values(ApiKey::query()
            ->onlySessionKeys()
            ->where('status', ApiKeyStatus::Active)
            ->where(function (Builder $query) use ($cutoff): void {
                $query->whereNull('created_by')
                    ->orWhere(fn (Builder $idle) => $this->notSeenSince($idle, $cutoff));
            })
            ->orderBy('id')
            ->get()
            ->all()));

        return $this->revokeAll($keys, SessionEndReason::Expired);
    }

    /**
     * Apaga as chaves de sessão que já não valem (revogadas, vencidas,
     * rotacionadas, carência encerrada). Devolve quantas.
     */
    public function purge(): int
    {
        return Accounts::asSystem('console:api-keys:prune-session-keys', function (): int {
            $now = now();
            $purged = 0;

            ApiKey::query()
                ->onlySessionKeys()
                ->where(function (Builder $query) use ($now): void {
                    $query->where('status', '!=', ApiKeyStatus::Active)
                        ->orWhere(fn (Builder $q) => $q->whereNotNull('expires_at')->where('expires_at', '<=', $now))
                        ->orWhere(fn (Builder $q) => $q->whereNotNull('grace_ends_at')->where('grace_ends_at', '<=', $now));
                })
                ->chunkById(100, function ($keys) use (&$purged): void {
                    foreach ($keys as $key) {
                        /** @var ApiKey $key */
                        DB::transaction(function () use ($key): void {
                            // Sem depender das chaves estrangeiras do banco:
                            // o vínculo com projetos e o encadeamento da
                            // rotação saem antes da linha.
                            $key->projects()->detach();

                            ApiKey::query()->withSessionKeys()->where('rotated_from_id', $key->id)->update(['rotated_from_id' => null]);
                            ApiKey::query()->withSessionKeys()->where('rotated_to_id', $key->id)->update(['rotated_to_id' => null]);

                            $key->delete();
                        });

                        $purged++;
                    }
                });

            return $purged;
        });
    }

    /**
     * @param  list<ApiKey>  $keys
     */
    private function revokeAll(array $keys, SessionEndReason $reason): int
    {
        if ($keys === []) {
            return 0;
        }

        /** @var array<string, array{user: int|string|null, uuids: list<string>}> $byUser */
        $byUser = [];

        Accounts::asSystem('api_keys:session-ended', function () use ($keys, &$byUser): void {
            foreach ($keys as $key) {
                $this->apiKeys->revoke($key);

                $user = $key->created_by;
                $group = $user === null ? '' : (string) $user;
                $byUser[$group] ??= ['user' => $user, 'uuids' => []];
                $byUser[$group]['uuids'][] = (string) $key->uuid;
            }
        });

        foreach ($byUser as $group) {
            Log::channel('request_log')->info(self::REVOKED_EVENT, [
                'reason' => $reason->value,
                'api_key_uuids' => $group['uuids'],
                'user_id' => $group['user'],
            ]);

            SessionApiKeysRevoked::dispatch($group['user'], $reason, $group['uuids']);
        }

        return count($keys);
    }

    private function idleCutoff(): CarbonInterface
    {
        return now()->subMinutes(max(1, (int) config('session.lifetime', 120)));
    }

    /**
     * Vista (ou, sem marcação, criada) depois do corte.
     *
     * @param  Builder<ApiKey>  $query
     */
    private function seenSince(Builder $query, CarbonInterface $cutoff): void
    {
        $query->where(fn (Builder $q) => $q->whereNotNull('session_seen_at')->where('session_seen_at', '>=', $cutoff))
            ->orWhere(fn (Builder $q) => $q->whereNull('session_seen_at')->where('created_at', '>=', $cutoff));
    }

    /**
     * @param  Builder<ApiKey>  $query
     */
    private function notSeenSince(Builder $query, CarbonInterface $cutoff): void
    {
        $query->where(fn (Builder $q) => $q->whereNotNull('session_seen_at')->where('session_seen_at', '<', $cutoff))
            ->orWhere(fn (Builder $q) => $q->whereNull('session_seen_at')->where('created_at', '<', $cutoff));
    }
}
