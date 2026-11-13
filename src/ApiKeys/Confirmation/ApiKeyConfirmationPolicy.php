<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Confirmation;

use LogicException;

/**
 * Como se confirma a CRIAÇÃO de uma chave de API, por ambiente das chaves
 * (`api_keys.creation_confirmation`, chave = `api_keys.environment`).
 *
 * - TransactionPasswordAndCode: senha de transação + código por e-mail — a
 *   ação sensível de sempre. A ÚNICA aceita em `live`.
 * - Code: só o código por e-mail (padrão em `test`).
 */
enum ApiKeyConfirmationPolicy: string
{
    case TransactionPasswordAndCode = 'transaction_password_and_code';
    case Code = 'code';

    /**
     * Ambiente em que só a política mais forte é aceita.
     */
    public const LIVE_ENVIRONMENT = 'live';

    /**
     * A política do ambiente informado (padrão: o das chaves geradas).
     * Ambiente sem política configurada = a mais forte.
     *
     * @throws LogicException Política inválida, ou afrouxada em `live`.
     */
    public static function forEnvironment(?string $environment = null): self
    {
        $environment ??= (string) config('api_keys.environment', self::LIVE_ENVIRONMENT);

        $configured = config('api_keys.creation_confirmation.'.$environment);

        if ($configured === null || $configured === '') {
            return self::TransactionPasswordAndCode;
        }

        $policy = is_string($configured) ? self::tryFrom(trim($configured)) : null;

        if ($policy === null) {
            throw new LogicException(sprintf(
                'api_keys.creation_confirmation.%s = "%s" não é uma política válida. Use "%s" ou "%s".',
                $environment,
                is_scalar($configured) ? (string) $configured : get_debug_type($configured),
                self::TransactionPasswordAndCode->value,
                self::Code->value,
            ));
        }

        if ($environment === self::LIVE_ENVIRONMENT && $policy !== self::TransactionPasswordAndCode) {
            throw new LogicException(sprintf(
                'api_keys.creation_confirmation.live só aceita "%s": as chaves de produção não podem nascer sem a senha de transação.',
                self::TransactionPasswordAndCode->value,
            ));
        }

        return $policy;
    }

    /**
     * Confere todas as políticas configuradas (chamado no boot do pacote:
     * configuração inválida ou afrouxada em `live` impede a inicialização).
     *
     * @throws LogicException
     */
    public static function assertConfigured(): void
    {
        foreach (array_keys((array) config('api_keys.creation_confirmation', [])) as $environment) {
            self::forEnvironment((string) $environment);
        }

        self::forEnvironment();
    }

    public function requiresTransactionPassword(): bool
    {
        return $this === self::TransactionPasswordAndCode;
    }
}
