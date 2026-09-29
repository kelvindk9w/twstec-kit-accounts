<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisos de produção sobre o pepper do hash das chaves de API, gravados no
 * log A CADA BOOT enquanto a condição durar.
 *
 * AVISO, NÃO RECUSA: uma instalação que já emitiu chaves sem pepper dedicado
 * continua funcionando — derrubá-la não troca o pepper, só troca um problema
 * de segurança por uma indisponibilidade (o critério do CriticalSecrets, do
 * foundation). O remédio é a transição documentada: pepper dedicado + peppers
 * anteriores (e, para chaves emitidas com pepper vazio, a flag do legado).
 *
 * Duas condições:
 *
 *   SEM PEPPER DEDICADO — API_KEYS_HASH_PEPPER ausente, vazio ou só com
 *   espaços. Vazio nunca é usado como pepper: vale como ausente, e o hash usa
 *   a APP_KEY. Rotacionar a APP_KEY sem pepper dedicado invalidaria toda chave
 *   (a menos que a APP_KEY antiga seja declarada como pepper anterior).
 *
 *   FLAG DO LEGADO LIGADA — API_KEYS_ACCEPT_EMPTY_PEPPER_LEGACY=true: hashes
 *   sem segredo ainda são aceitos. É estado de transição; o aviso recorrente é
 *   o que impede que fique ligado para sempre.
 */
final class PepperWarnings
{
    /**
     * Mensagens para o estado atual (lista vazia = nada a avisar).
     *
     * @return list<string>
     */
    public static function forCurrentConfiguration(): array
    {
        $warnings = [];

        $configured = config('api_keys.hash_pepper');
        $applicationKey = config('app.key');

        $dedicated = is_string($configured) && trim($configured) !== ''
            && ! (is_string($applicationKey) && $configured === $applicationKey);

        if (! $dedicated) {
            $warnings[] = 'API_KEYS_HASH_PEPPER está ausente ou VAZIO em APP_ENV=production. Valor vazio '
                .'nunca é usado como pepper: conta como ausente, e o hash das chaves de API usa a APP_KEY. '
                .'Assim, rotacionar a APP_KEY invalida todas as chaves de API. Defina um pepper dedicado '
                .'(`php artisan tinker` → Str::random(64)) e declare o valor que estava em uso em '
                .'API_KEYS_PREVIOUS_HASH_PEPPERS (a APP_KEY atual) para que as chaves emitidas migrem no '
                .'primeiro uso. Este aviso volta a cada boot. Detalhes em docs/api.md.';
        }

        if (app(ApiKeyHasher::class)->acceptsEmptyPepperLegacy()) {
            $warnings[] = 'API_KEYS_ACCEPT_EMPTY_PEPPER_LEGACY=true em APP_ENV=production: chaves de API cujo '
                .'hash foi gravado com pepper VAZIO (sem segredo — verificável por quem tiver só o banco) '
                .'ainda são aceitas e migradas para o pepper atual no primeiro uso. É estado de transição: '
                .'desligue a flag quando todas as chaves tiverem sido usadas ou rotacionadas (evento '
                .'api_keys.secret_hash.migrated no request_log). Este aviso volta a cada boot enquanto a '
                .'flag estiver ligada. Detalhes em docs/api.md.';
        }

        return $warnings;
    }

    /**
     * Grava os avisos no log. Best-effort: durante um build a pasta de logs
     * pode não ser gravável, e um aviso nunca pode ser o motivo de o
     * `composer install` falhar.
     */
    public static function announce(): void
    {
        try {
            foreach (self::forCurrentConfiguration() as $message) {
                Log::warning($message);
            }
        } catch (Throwable) {
            // Sem log disponível não há o que fazer: o aviso volta no próximo boot.
        }
    }
}
