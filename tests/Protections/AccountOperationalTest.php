<?php

declare(strict_types=1);

use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Auth\Enums\UserStatus;

// =============================================================================
// A REGRA ÚNICA "a conta opera" (Account::isOperational()): a conta responde
// pelo dono. Sem dono, dono inativo ou com e-mail pendente (quando a
// verificação é exigida), a conta não opera. A API (ResolveTenant) e os
// aplicativos usam esta mesma regra.
// =============================================================================

it('opera quando o dono está ativo e com o e-mail confirmado', function (): void {
    $conta = $this->accountOf($this->owner());

    expect($conta->isOperational())->toBeTrue();
});

it('conta de empresa responde pelo dono da conta, não por um membro', function (): void {
    $dono = $this->owner();
    $membro = $this->owner();
    $empresa = app(AccountService::class)->createAccount('Empresa', $dono);
    app(AccountService::class)->addMember($empresa, $membro, AccountRole::Member);

    // Membro bloqueado não tira a conta de operação.
    $membro->forceFill(['status' => UserStatus::Blocked])->save();
    expect($empresa->fresh()->isOperational())->toBeTrue();

    // Dono bloqueado tira.
    $dono->forceFill(['status' => UserStatus::Blocked])->save();
    expect($empresa->fresh()->isOperational())->toBeFalse();
});

it('não opera sem dono', function (): void {
    $conta = Accounts::asSystem('teste: conta sem dono', fn (): Account => Account::query()->create(['name' => 'Sem dono']));

    expect($conta->owner)->toBeNull()
        ->and($conta->isOperational())->toBeFalse();
});

it('não opera com o dono que não está ativo (bloqueado ou pendente)', function (UserStatus $status): void {
    $dono = $this->owner();
    $conta = $this->accountOf($dono);

    $dono->forceFill(['status' => $status])->save();

    expect($conta->fresh()->isOperational())->toBeFalse();
})->with([
    'bloqueado' => UserStatus::Blocked,
    'pendente' => UserStatus::Pending,
]);

it('não opera com o e-mail do dono pendente quando a verificação é exigida', function (): void {
    config()->set('auth.email_verification.required', true);
    $dono = $this->owner(['email_verified_at' => null]);

    expect($this->accountOf($dono)->isOperational())->toBeFalse();
});

it('opera com o e-mail do dono sem confirmação quando a verificação não é exigida', function (): void {
    config()->set('auth.email_verification.required', false);
    $dono = $this->owner(['email_verified_at' => null]);

    expect($this->accountOf($dono)->isOperational())->toBeTrue();
});

it('opera de novo assim que o dono confirma o e-mail', function (): void {
    config()->set('auth.email_verification.required', true);
    $dono = $this->owner(['email_verified_at' => null]);
    $conta = $this->accountOf($dono);
    expect($conta->isOperational())->toBeFalse();

    $dono->forceFill(['email_verified_at' => now()])->save();

    expect($conta->fresh()->isOperational())->toBeTrue();
});
