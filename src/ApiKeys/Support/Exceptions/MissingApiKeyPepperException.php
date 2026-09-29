<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Support\Exceptions;

use RuntimeException;

/**
 * Não há pepper possível para o hash da chave secreta: API_KEYS_HASH_PEPPER
 * ausente ou vazio E a APP_KEY também vazia.
 *
 * Calcular o HMAC mesmo assim seria usar uma chave de 0 caracteres — um hash
 * que qualquer um com acesso ao banco verifica offline —, e fazê-lo em
 * silêncio. Recusar é a única saída honesta. Na prática a APP_KEY ausente já
 * é recusada antes, no boot de produção (CriticalSecrets, do foundation) e
 * pelo próprio encrypter do Laravel; esta exceção fecha o caminho que sobra.
 */
final class MissingApiKeyPepperException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'Sem pepper para o hash das chaves de API: API_KEYS_HASH_PEPPER está ausente ou vazio '
            .'e a APP_KEY também. Valor vazio nunca é usado como pepper. Defina um pepper '
            .'dedicado (API_KEYS_HASH_PEPPER, por exemplo `php artisan tinker` → Str::random(64)) '
            .'ou gere a APP_KEY. Detalhes em docs/api.md.'
        );
    }
}
