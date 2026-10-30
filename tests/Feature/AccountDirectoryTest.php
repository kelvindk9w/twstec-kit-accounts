<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Queries\AccountDirectory;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Tests\Fixtures\User;

// =============================================================================
// AS LEITURAS DAS TELAS DE CONTA com tipo: o papel e a data de entrada de cada
// membro saem do vínculo da consulta de members() por roleOf() e
// memberSince() — as telas não leem o pivô na mão. Pessoa que não veio de
// members() não tem o vínculo, e a leitura falha alto (nunca um papel
// inventado).
// =============================================================================

it('dá o papel e a data de entrada de cada membro da lista de members()', function (): void {
    Carbon::setTestNow('2026-10-01 12:00:00');
    $dono = User::fixture(['email_verified_at' => now()]);
    $conta = app(AccountService::class)->createAccount('Empresa', $dono);

    Carbon::setTestNow('2026-10-02 08:30:00');
    $admin = User::fixture(['email_verified_at' => now()]);
    app(AccountService::class)->addMember($conta, $admin, AccountRole::Admin);
    $membro = User::fixture(['email_verified_at' => now()]);
    app(AccountService::class)->addMember($conta, $membro, AccountRole::Member);

    $directory = app(AccountDirectory::class);
    $papeis = [];
    $entradas = [];

    foreach ($directory->members($conta) as $pessoa) {
        $papeis[$pessoa->email] = $directory->roleOf($pessoa);
        $entradas[$pessoa->email] = $directory->memberSince($pessoa)?->toDateTimeString();
    }

    expect($papeis)->toBe([
        $dono->email => AccountRole::Owner,
        $admin->email => AccountRole::Admin,
        $membro->email => AccountRole::Member,
    ])->and($entradas)->toBe([
        $dono->email => '2026-10-01 12:00:00',
        $admin->email => '2026-10-02 08:30:00',
        $membro->email => '2026-10-02 08:30:00',
    ]);
});

it('recusa ler o papel de quem não veio de members()', function (): void {
    $pessoa = User::fixture(['email_verified_at' => now()]);
    app(AccountService::class)->createAccount('Empresa', $pessoa);

    expect(fn () => app(AccountDirectory::class)->roleOf($pessoa->fresh()))
        ->toThrow(LogicException::class, 'members()')
        ->and(fn () => app(AccountDirectory::class)->memberSince($pessoa->fresh()))
        ->toThrow(LogicException::class, 'members()');
});
