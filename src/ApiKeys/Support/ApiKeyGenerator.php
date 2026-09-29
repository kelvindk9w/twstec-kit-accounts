<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Support;

use Illuminate\Support\Str;

/**
 * Geração do par de chaves de API (pública identifica, secreta autentica).
 *
 * - Pública: `pk_{env}_<32 chars>` — identificação/lookup (indexada, única).
 * - Secreta: `sk_{env}_<48 chars>` — credencial. Só o hash vai ao banco; o
 *   valor em claro é retornado UMA única vez (criação/rotação).
 *
 * Ambas usam Str::random(), que é criptograficamente seguro (random_bytes).
 * O prefixo de ambiente (live/test) vem de config/api_keys.php (nada hardcoded).
 */
final class ApiKeyGenerator
{
    /**
     * Entropia da parte aleatória (chars do alfabeto base62 do Str::random).
     * 32 chars ≈ 190 bits; 48 chars ≈ 285 bits — muito além do necessário.
     */
    private const PUBLIC_RANDOM_LENGTH = 32;

    private const SECRET_RANDOM_LENGTH = 48;

    /**
     * Gera o par completo: [public_key, secret_key (em claro)].
     *
     * @return array{public_key: string, secret_key: string}
     */
    public function generatePair(): array
    {
        return [
            'public_key' => $this->generatePublicKey(),
            'secret_key' => $this->generateSecretKey(),
        ];
    }

    public function generatePublicKey(): string
    {
        return 'pk_'.$this->environment().'_'.Str::random(self::PUBLIC_RANDOM_LENGTH);
    }

    public function generateSecretKey(): string
    {
        return 'sk_'.$this->environment().'_'.Str::random(self::SECRET_RANDOM_LENGTH);
    }

    /**
     * Ambiente das chaves (live/test) — nunca hardcoded.
     */
    private function environment(): string
    {
        return (string) config('api_keys.environment', 'live');
    }
}
