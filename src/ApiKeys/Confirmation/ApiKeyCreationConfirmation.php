<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Confirmation;

use Illuminate\Validation\ValidationException;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Enums\VerificationResult;
use Twstec\Kit\Auth\Services\SensitiveActionService;
use Twstec\Kit\Auth\Services\VerificationCodes;

/**
 * A CONFIRMAÇÃO da criação de chave de API — a política do ambiente das
 * chaves (ApiKeyConfirmationPolicy, `api_keys.creation_confirmation`) num
 * lugar só, para as telas dos dois starters e para a chave de sessão do
 * aplicativo seguirem a mesma regra:
 *
 * 1. sendCode() — com TransactionPasswordAndCode, confere a senha de
 *    transação e manda o código (SensitiveActionService, como sempre); com
 *    Code, só manda o código, na família própria
 *    VerificationPurpose::CodeConfirmation;
 * 2. confirm() — confere o código e devolve a prova de uso único
 *    (ApiKeyCreationConfirmed). Na política forte, o código vira o token de
 *    ação sensível e o token é consumido NA HORA (nunca sai daqui); na
 *    política só de código, não existe token: o código não serve para
 *    nenhuma outra ação sensível.
 *
 * Erros saem como ValidationException com as chaves de sempre
 * (`transaction_password`, `code`), no idioma da requisição. Tentativas,
 * validade e intervalo de reenvio são os do motor de códigos do
 * twstec/kit-auth.
 */
final class ApiKeyCreationConfirmation
{
    public function __construct(
        private readonly SensitiveActionService $sensitive,
        private readonly VerificationCodes $codes,
    ) {}

    /**
     * A política do ambiente das chaves geradas.
     */
    public function policy(): ApiKeyConfirmationPolicy
    {
        return ApiKeyConfirmationPolicy::forEnvironment();
    }

    public function requiresTransactionPassword(): bool
    {
        return $this->policy()->requiresTransactionPassword();
    }

    /**
     * Passo 1: manda o código (conferida a senha de transação, se a política
     * pede). A senha é ignorada na política só de código.
     *
     * @throws ValidationException Senha incorreta/ausente, tentativas
     *                             esgotadas ou intervalo de reenvio.
     */
    public function sendCode(AuthUser $user, #[\SensitiveParameter] ?string $transactionPassword = null): void
    {
        if ($this->requiresTransactionPassword()) {
            $this->sensitive->sendCode($user, (string) $transactionPassword);

            return;
        }

        $remaining = $this->codes->cooldownRemaining($user, VerificationPurpose::CodeConfirmation);

        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'code' => __('auth.verification_code.resend_cooldown', ['seconds' => $remaining]),
            ]);
        }

        $this->codes->issue($user, VerificationPurpose::CodeConfirmation);
    }

    /**
     * Passo 2: confere o código e devolve a prova de uso único.
     *
     * @throws ValidationException Código inválido, expirado ou tentativas esgotadas.
     */
    public function confirm(AuthUser $user, #[\SensitiveParameter] string $code): ApiKeyCreationConfirmed
    {
        $policy = $this->policy();

        if ($policy->requiresTransactionPassword()) {
            $token = $this->sensitive->confirmCode($user, $code)['token'];

            // O token nasce e morre aqui: a criação que vem a seguir é a
            // única autorizada por ele (o mesmo que o `sensitive.token` faz).
            if (! $this->sensitive->validateToken($user, $token)) {
                throw ValidationException::withMessages(['code' => __('auth.verification_code.expired')]);
            }

            return ApiKeyCreationConfirmed::issue($user, $policy);
        }

        return match ($this->codes->verify($user, VerificationPurpose::CodeConfirmation, $code)) {
            VerificationResult::Valid => ApiKeyCreationConfirmed::issue($user, $policy),
            VerificationResult::Invalid => throw ValidationException::withMessages([
                'code' => __('auth.verification_code.invalid'),
            ]),
            VerificationResult::Expired => throw ValidationException::withMessages([
                'code' => __('auth.verification_code.expired'),
            ]),
        };
    }

    /**
     * Segundos até poder pedir outro código (0 = pode), na família da política.
     */
    public function resendCooldownRemaining(AuthUser $user): int
    {
        return $this->requiresTransactionPassword()
            ? $this->sensitive->resendCooldownRemaining($user)
            : $this->codes->cooldownRemaining($user, VerificationPurpose::CodeConfirmation);
    }
}
