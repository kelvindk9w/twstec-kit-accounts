<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Geração do par de chaves de API (pública identifica, secreta autentica).
 *
 * - Pública: `[<produto>_]pk_{env}_<32 chars>` — identificação/lookup
 *   (indexada, única).
 * - Secreta: `[<produto>_]sk_{env}_<48 chars>` — credencial. Só o hash vai ao
 *   banco; o valor em claro é retornado UMA única vez (criação/rotação).
 *
 * O prefixo do produto (`api_keys.prefix`, API_KEY_PREFIX) é opcional e só
 * vale para as chaves NOVAS: a autenticação procura a chave pública inteira,
 * então as emitidas antes da troca continuam valendo.
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
     * Tamanho máximo do prefixo do produto.
     */
    public const PREFIX_MAX_LENGTH = 16;

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
        return self::productPrefix().'pk_'.$this->environment().'_'.Str::random(self::PUBLIC_RANDOM_LENGTH);
    }

    public function generateSecretKey(): string
    {
        return self::productPrefix().'sk_'.$this->environment().'_'.Str::random(self::SECRET_RANDOM_LENGTH);
    }

    /**
     * O prefixo do produto configurado (vazio = sem prefixo), conferido: só
     * [a-z0-9], até PREFIX_MAX_LENGTH caracteres. Fora disso é exceção — o
     * provider chama no boot, e o aplicativo não sobe com um prefixo que
     * geraria chaves fora do formato.
     */
    public static function prefix(): string
    {
        $prefix = config('api_keys.prefix', '');
        $prefix = is_string($prefix) ? $prefix : '';

        if ($prefix !== '' && preg_match('/^[a-z0-9]{1,'.self::PREFIX_MAX_LENGTH.'}$/', $prefix) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'API_KEY_PREFIX inválido ("%s"): use só letras minúsculas e números, até %d caracteres (ex.: acme) — ou deixe vazio.',
                $prefix,
                self::PREFIX_MAX_LENGTH,
            ));
        }

        return $prefix;
    }

    private static function productPrefix(): string
    {
        $prefix = self::prefix();

        return $prefix === '' ? '' : $prefix.'_';
    }

    /**
     * Ambiente das chaves (live/test) — nunca hardcoded.
     */
    private function environment(): string
    {
        return (string) config('api_keys.environment', 'live');
    }
}
