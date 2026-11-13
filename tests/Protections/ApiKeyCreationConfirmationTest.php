<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Twstec\Kit\Accounts\ApiKeys\Confirmation\ApiKeyConfirmationPolicy;
use Twstec\Kit\Accounts\ApiKeys\Confirmation\ApiKeyCreationConfirmation;
use Twstec\Kit\Accounts\ApiKeys\Confirmation\ApiKeyCreationConfirmed;
use Twstec\Kit\Accounts\Tests\Fixtures\User;
use Twstec\Kit\Auth\Enums\VerificationPurpose;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Models\SensitiveActionToken;
use Twstec\Kit\Auth\Services\SensitiveActionService;

// =============================================================================
// CONFIRMAÇÃO DA CRIAÇÃO DE CHAVE POR AMBIENTE (`api_keys.creation_confirmation`):
// em `live`, senha de transação + código — sempre, sem como afrouxar; em
// `test`, por padrão só o código, numa família própria que nunca vira token
// de ação sensível.
// =============================================================================

beforeEach(function (): void {
    config(['auth.verification.resend_cooldown_seconds' => 0]);
});

function confirmationUser(mixed $test): User
{
    return (fn () => $this->owner(['transaction_password' => 'transacao-123', 'transaction_password_set_at' => now()]))->call($test);
}

/**
 * @return array{code: string, purpose: VerificationPurpose}
 */
function sentCode(): array
{
    $sent = null;

    Mail::assertQueued(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$sent): bool {
        $sent = ['code' => $mail->code, 'purpose' => $mail->purpose];

        return true;
    });

    /** @var array{code: string, purpose: VerificationPurpose} */
    return $sent;
}

it('os padrões: live pede senha de transação + código; test, só o código', function (): void {
    expect(ApiKeyConfirmationPolicy::forEnvironment('live'))->toBe(ApiKeyConfirmationPolicy::TransactionPasswordAndCode)
        ->and(ApiKeyConfirmationPolicy::forEnvironment('test'))->toBe(ApiKeyConfirmationPolicy::Code)
        // Ambiente sem política configurada: a mais forte.
        ->and(ApiKeyConfirmationPolicy::forEnvironment('homologacao'))->toBe(ApiKeyConfirmationPolicy::TransactionPasswordAndCode);

    config(['api_keys.environment' => 'live']);
    expect(app(ApiKeyCreationConfirmation::class)->requiresTransactionPassword())->toBeTrue();

    config(['api_keys.environment' => 'test']);
    expect(app(ApiKeyCreationConfirmation::class)->requiresTransactionPassword())->toBeFalse();
});

it('live: sem a senha de transação certa, nenhum código sai', function (): void {
    Mail::fake();
    config(['api_keys.environment' => 'live']);
    $user = confirmationUser($this);
    $confirmation = app(ApiKeyCreationConfirmation::class);

    foreach ([null, '', 'senha-errada'] as $password) {
        try {
            $confirmation->sendCode($user, $password);
            $this->fail('O código saiu sem a senha de transação.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('transaction_password');
        }
    }

    Mail::assertNothingQueued();

    $confirmation->sendCode($user, 'transacao-123');
    ['code' => $code, 'purpose' => $purpose] = sentCode();

    expect($purpose)->toBe(VerificationPurpose::SensitiveAction);

    $proof = $confirmation->confirm($user, $code);

    expect($proof)->toBeInstanceOf(ApiKeyCreationConfirmed::class)
        ->and($proof->policy)->toBe(ApiKeyConfirmationPolicy::TransactionPasswordAndCode)
        // O token de ação sensível nasceu e morreu aqui: nada sobra para outra operação.
        ->and(SensitiveActionToken::query()->whereNull('consumed_at')->count())->toBe(0)
        ->and(SensitiveActionToken::query()->whereNotNull('consumed_at')->count())->toBe(1);
});

it('test: só o código, na família própria — e o código não serve para outra ação sensível', function (): void {
    Mail::fake();
    config(['api_keys.environment' => 'test']);
    $user = confirmationUser($this);
    $confirmation = app(ApiKeyCreationConfirmation::class);

    $confirmation->sendCode($user);
    ['code' => $code, 'purpose' => $purpose] = sentCode();

    expect($purpose)->toBe(VerificationPurpose::CodeConfirmation);

    // O código desta família não vira token de ação sensível.
    expect(fn () => app(SensitiveActionService::class)->confirmCode($user, $code))
        ->toThrow(ValidationException::class);

    // Código errado: recusado, no campo `code`.
    try {
        $confirmation->confirm($user, $code === '000000' ? '111111' : '000000');
        $this->fail('Código errado aceito.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('code');
    }

    $proof = $confirmation->confirm($user, $code);

    expect($proof->policy)->toBe(ApiKeyConfirmationPolicy::Code)
        ->and(SensitiveActionToken::query()->count())->toBe(0);

    // Uso único: o mesmo código não confirma de novo.
    expect(fn () => $confirmation->confirm($user, $code))->toThrow(ValidationException::class);
});

it('test com a política forte configurada: volta a pedir a senha de transação', function (): void {
    Mail::fake();
    config(['api_keys.environment' => 'test', 'api_keys.creation_confirmation.test' => 'transaction_password_and_code']);
    $user = confirmationUser($this);

    expect(fn () => app(ApiKeyCreationConfirmation::class)->sendCode($user))->toThrow(ValidationException::class);
    Mail::assertNothingQueued();
});

it('live nunca afrouxa: só código, ou valor inválido, impedem a inicialização', function (array $config, string $message): void {
    expect(fn () => $this->bootWith($config))->toThrow(LogicException::class, $message);
})->with([
    'live só com código' => [['api_keys.creation_confirmation.live' => 'code'], 'creation_confirmation.live'],
    'valor desconhecido' => [['api_keys.creation_confirmation.test' => 'nenhuma'], 'não é uma política válida'],
]);

it('o intervalo de reenvio da família só-de-código vale', function (): void {
    Mail::fake();
    config(['api_keys.environment' => 'test', 'auth.verification.resend_cooldown_seconds' => 60]);
    $user = confirmationUser($this);
    $confirmation = app(ApiKeyCreationConfirmation::class);

    $confirmation->sendCode($user);

    expect($confirmation->resendCooldownRemaining($user))->toBeGreaterThan(0);

    try {
        $confirmation->sendCode($user);
        $this->fail('Reenvio dentro do intervalo.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('code');
    }

    Mail::assertQueuedCount(1);
});
