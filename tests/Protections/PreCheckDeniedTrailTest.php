<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Twstec\Kit\Accounts\Account\Actions\DeleteAccount;
use Twstec\Kit\Accounts\Account\Actions\RemoveMember;
use Twstec\Kit\Accounts\Account\Actions\RenameAccount;
use Twstec\Kit\Accounts\Account\Actions\RevokeInvitation;
use Twstec\Kit\Accounts\Account\Actions\TransferOwnership;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Tests\Fixtures\User;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// A PRÉ-CHECAGEM DE PAPEL das Actions de conta/membros/convites também fica
// na trilha.
//
// As telas conferem o papel ANTES de abrir a confirmação ou de gastar o
// código de uma ação sensível (transferir, excluir, renomear, remover,
// revogar). Essa conferência é a da própria Action (`authorize()`): quem não
// pode recebe o MESMO 403, com a MESMA mensagem de handle(), e a tentativa
// grava `denied` na trilha — tudo que é tentado no painel fica no banco,
// inclusive as recusas. Quem pode passa sem linha nenhuma (a linha de
// sucesso é da operação, não da pré-checagem).
// =============================================================================

/**
 * @return array{conta: Account, dono: User, admin: User, membro: User, fora: User}
 */
function equipeDaPrecheck(): array
{
    $dono = User::fixture(['email_verified_at' => now()]);
    $admin = User::fixture(['email_verified_at' => now()]);
    $membro = User::fixture(['email_verified_at' => now()]);
    $fora = User::fixture(['email_verified_at' => now()]);
    $conta = app(AccountService::class)->createAccount('Empresa Precheck', $dono);
    app(AccountService::class)->addMember($conta, $admin, AccountRole::Admin);
    app(AccountService::class)->addMember($conta, $membro, AccountRole::Member);

    return ['conta' => $conta, 'dono' => $dono, 'admin' => $admin, 'membro' => $membro, 'fora' => $fora];
}

function precheckNa(Account $conta, User $quem, string $action): void
{
    test()->actingAs($quem);

    Accounts::actingAs($conta, fn () => app($action)->authorize($quem), $quem);
}

/**
 * @return list<array{action: string, outcome: string}>
 */
function recusasDaPrecheck(): array
{
    return AuditEvent::query()->orderBy('id')->get()
        ->map(fn (AuditEvent $e): array => ['action' => $e->action, 'outcome' => $e->outcome->value])
        ->all();
}

dataset('pré-checagens de dono', [
    'transferir a propriedade' => [TransferOwnership::class, 'account.ownership_transferred'],
    'excluir a conta' => [DeleteAccount::class, 'account.deleted'],
]);

dataset('pré-checagens de gestão', [
    'renomear a conta' => [RenameAccount::class, 'account.renamed'],
    'remover membro' => [RemoveMember::class, 'account.member_removed'],
    'revogar convite' => [RevokeInvitation::class, 'account.invitation_revoked'],
]);

it('ação de dono: admin e member recebem 403 com a mesma mensagem, e cada tentativa grava denied', function (string $action, string $evento): void {
    $e = equipeDaPrecheck();

    foreach (['admin', 'membro'] as $papel) {
        expect(fn () => precheckNa($e['conta'], $e[$papel], $action))
            ->toThrow(AuthorizationException::class, __('accounts.authorization.denied'));
    }

    $linhas = AuditEvent::query()->where('action', $evento)->get();

    expect($linhas)->toHaveCount(2)
        ->and($linhas->every(fn (AuditEvent $l): bool => $l->outcome->value === 'denied'))->toBeTrue()
        ->and($linhas->every(fn (AuditEvent $l): bool => $l->tenant_uuid === $e['conta']->uuid))->toBeTrue()
        ->and($linhas->pluck('actor_uuid')->sort()->values()->all())
        ->toBe(collect([$e['admin']->uuid, $e['membro']->uuid])->sort()->values()->all());
})->with('pré-checagens de dono');

it('ação de dono: o dono passa, sem linha na trilha', function (string $action): void {
    $e = equipeDaPrecheck();

    precheckNa($e['conta'], $e['dono'], $action);

    expect(recusasDaPrecheck())->toBe([]);
})->with('pré-checagens de dono');

it('ação de gestão: member recebe 403 com denied; dono e admin passam sem linha', function (string $action, string $evento): void {
    $e = equipeDaPrecheck();

    expect(fn () => precheckNa($e['conta'], $e['membro'], $action))
        ->toThrow(AuthorizationException::class, __('accounts.authorization.denied'));

    precheckNa($e['conta'], $e['dono'], $action);
    precheckNa($e['conta'], $e['admin'], $action);

    expect(recusasDaPrecheck())->toBe([['action' => $evento, 'outcome' => 'denied']]);
})->with('pré-checagens de gestão');

it('quem não é membro da conta recebe 403 "não é membro", também com denied', function (string $action, string $evento): void {
    $e = equipeDaPrecheck();

    expect(fn () => precheckNa($e['conta'], $e['fora'], $action))
        ->toThrow(AuthorizationException::class, __('accounts.authorization.not_member'));

    expect(recusasDaPrecheck())->toBe([['action' => $evento, 'outcome' => 'denied']])
        ->and(AuditEvent::query()->sole()->actor_uuid)->toBe($e['fora']->uuid);
})->with([
    ...['transferir a propriedade' => [TransferOwnership::class, 'account.ownership_transferred']],
    ...['excluir a conta' => [DeleteAccount::class, 'account.deleted']],
    ...['renomear a conta' => [RenameAccount::class, 'account.renamed']],
]);
