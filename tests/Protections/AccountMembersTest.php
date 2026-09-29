<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Twstec\Kit\Accounts\Account\Actions\ChangeMemberRole;
use Twstec\Kit\Accounts\Account\Actions\CreateAccount;
use Twstec\Kit\Accounts\Account\Actions\DeleteAccount;
use Twstec\Kit\Accounts\Account\Actions\LeaveAccount;
use Twstec\Kit\Accounts\Account\Actions\RemoveMember;
use Twstec\Kit\Accounts\Account\Actions\RenameAccount;
use Twstec\Kit\Accounts\Account\Actions\SwitchAccount;
use Twstec\Kit\Accounts\Account\Actions\TransferOwnership;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Exceptions\OwnerOfSharedAccountException;
use Twstec\Kit\Accounts\Account\Mail\OrphanedApiKeysMail;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Accounts\Tests\Fixtures\User;
use Twstec\Kit\Auth\Mail\VerificationCodeMail;
use Twstec\Kit\Auth\Services\SensitiveActionService;
use Twstec\Kit\Foundation\Audit\Enums\AuditOutcome;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// MEMBROS, TRANSFERÊNCIA e o CICLO DA CONTA pelas Actions do pacote, numa
// aplicação limpa — a regra que o painel Livewire e o front React usam.
//
// - Quem mexe em quem (MemberRules): cada célula da matriz, inclusive as
//   recusas, com a linha `denied` na trilha.
// - Transferência: só o dono, só com o token de ação sensível (senha de
//   transação + código), 1 dono sempre, o antigo vira admin.
// - Remover/sair: o dono não sai nem é removido; as chaves de quem sai
//   continuam valendo e o dono e os admins recebem o aviso (sem segredo).
// - Criar, renomear, excluir e trocar de conta; trilha de cada evento.
// =============================================================================

/**
 * Empresa com dono, dois admins e dois members.
 *
 * @return array{conta: Account, dono: User, admin: User, admin2: User, membro: User, membro2: User}
 */
function empresaCompleta(): array
{
    $dono = User::fixture(['email_verified_at' => now()]);
    $conta = app(AccountService::class)->createAccount('Empresa Completa', $dono);
    $pessoas = ['dono' => $dono];

    foreach (['admin' => AccountRole::Admin, 'admin2' => AccountRole::Admin, 'membro' => AccountRole::Member, 'membro2' => AccountRole::Member] as $nome => $papel) {
        $pessoas[$nome] = User::fixture(['email_verified_at' => now()]);
        app(AccountService::class)->addMember($conta, $pessoas[$nome], $papel);
    }

    return ['conta' => $conta, ...$pessoas];
}

/**
 * Roda a Action na conta, como a pessoa (o que o painel faz com a conta
 * selecionada na sessão).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function naEmpresa(Account $conta, User $quem, Closure $callback): mixed
{
    test()->actingAs($quem);

    return Accounts::actingAs($conta, $callback, $quem);
}

/**
 * Token de ação sensível pelo fluxo REAL: senha de transação → código por
 * e-mail (capturado com Mail::fake) → token.
 */
function tokenSensivelDe(User $pessoa): string
{
    // O reenvio do código tem intervalo mínimo: cada token do teste é pedido
    // depois dele (o relógio anda; o token vale 10 min a partir daqui).
    test()->travel(2)->minutes();
    Mail::fake();
    $pessoa->forceFill(['transaction_password' => 'Trans4cao!Segura'])->save();

    app(SensitiveActionService::class)->sendCode($pessoa, 'Trans4cao!Segura');

    $codigo = null;
    Mail::assertQueued(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$codigo): bool {
        $codigo = $mail->code;

        return true;
    });

    return app(SensitiveActionService::class)->confirmCode($pessoa, (string) $codigo)['token'];
}

/**
 * @return list<array{action: string, outcome: string}>
 */
function trilhaDaConta(Account|string $conta): array
{
    $uuid = $conta instanceof Account ? $conta->uuid : $conta;

    return AuditEvent::query()->where('tenant_uuid', $uuid)->orderBy('id')->get()
        ->map(fn (AuditEvent $e): array => ['action' => $e->action, 'outcome' => $e->outcome->value])
        ->all();
}

it('mudar papel: o dono muda admin ↔ member; admin só promove member; ninguém mexe no dono nem em si mesmo', function (): void {
    $e = empresaCompleta();
    $mudar = fn (User $quem, User $alvo, AccountRole $papel) => naEmpresa($e['conta'], $quem, fn () => app(ChangeMemberRole::class)->handle($quem, $alvo->uuid, $papel));
    $recusa = function (User $quem, User $alvo, AccountRole $papel) use ($mudar): void {
        expect(fn () => $mudar($quem, $alvo, $papel))->toThrow(AuthorizationException::class);
    };

    // Dono: promove member e rebaixa admin.
    $mudar($e['dono'], $e['membro'], AccountRole::Admin);
    expect($e['conta']->roleOf($e['membro']))->toBe(AccountRole::Admin);
    $mudar($e['dono'], $e['membro'], AccountRole::Member);
    expect($e['conta']->roleOf($e['membro']))->toBe(AccountRole::Member);

    // Admin: promove member a admin...
    $mudar($e['admin'], $e['membro2'], AccountRole::Admin);
    expect($e['conta']->roleOf($e['membro2']))->toBe(AccountRole::Admin);

    // ...mas não rebaixa outro admin (nem o que acabou de promover), não
    // mexe no dono, não muda o próprio papel.
    $recusa($e['admin'], $e['admin2'], AccountRole::Member);
    $recusa($e['admin'], $e['membro2'], AccountRole::Member);
    $recusa($e['admin'], $e['dono'], AccountRole::Member);
    $recusa($e['admin'], $e['admin'], AccountRole::Member);

    // Ninguém vira dono por mudança de papel; o dono não se rebaixa.
    $recusa($e['dono'], $e['admin'], AccountRole::Owner);
    $recusa($e['dono'], $e['dono'], AccountRole::Admin);

    // Member não mexe em ninguém.
    $recusa($e['membro'], $e['admin'], AccountRole::Member);

    expect($e['conta']->roleOf($e['admin2']))->toBe(AccountRole::Admin)
        ->and($e['conta']->roleOf($e['dono']))->toBe(AccountRole::Owner)
        ->and(collect(trilhaDaConta($e['conta']))->where('action', 'account.member_role_changed')->countBy('outcome')->all())
        ->toBe(['success' => 3, 'denied' => 7]);
});

it('remover: o dono remove admin e member; admin só remove member; ninguém remove o dono nem a si mesmo', function (): void {
    $e = empresaCompleta();
    $remover = fn (User $quem, User $alvo) => naEmpresa($e['conta'], $quem, fn () => app(RemoveMember::class)->handle($quem, $alvo->uuid));

    expect(fn () => $remover($e['admin'], $e['dono']))->toThrow(AuthorizationException::class)
        ->and(fn () => $remover($e['admin'], $e['admin2']))->toThrow(AuthorizationException::class)
        ->and(fn () => $remover($e['admin'], $e['admin']))->toThrow(AuthorizationException::class)
        ->and(fn () => $remover($e['membro'], $e['membro2']))->toThrow(AuthorizationException::class)
        ->and(fn () => $remover($e['dono'], $e['dono']))->toThrow(AuthorizationException::class);

    expect($e['conta']->hasMember($e['dono']))->toBeTrue()
        ->and($e['conta']->hasMember($e['admin2']))->toBeTrue();

    $remover($e['admin'], $e['membro']);
    $remover($e['dono'], $e['admin2']);

    expect($e['conta']->hasMember($e['membro']))->toBeFalse()
        ->and($e['conta']->hasMember($e['admin2']))->toBeFalse()
        ->and($e['conta']->memberships()->where('role', 'owner')->count())->toBe(1);

    $removidos = collect(trilhaDaConta($e['conta']))->where('action', 'account.member_removed');
    expect($removidos->where('outcome', 'success')->count())->toBe(2)
        ->and($removidos->where('outcome', 'denied')->count())->toBe(5);
});

it('quem não é membro da conta (ou nem existe) é 404 — não confirma nada', function (): void {
    $e = empresaCompleta();
    $estranho = User::fixture();

    expect(fn () => naEmpresa($e['conta'], $e['dono'], fn () => app(RemoveMember::class)->handle($e['dono'], $estranho->uuid)))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => naEmpresa($e['conta'], $e['dono'], fn () => app(ChangeMemberRole::class)->handle($e['dono'], '00000000-0000-7000-8000-000000000000', AccountRole::Admin)))
        ->toThrow(ModelNotFoundException::class);
});

it('sair: admin e member saem; o dono não sai (transfere antes)', function (): void {
    $e = empresaCompleta();

    expect(fn () => naEmpresa($e['conta'], $e['dono'], fn () => app(LeaveAccount::class)->handle($e['dono'])))
        ->toThrow(AuthorizationException::class);

    naEmpresa($e['conta'], $e['membro'], fn () => app(LeaveAccount::class)->handle($e['membro']));
    naEmpresa($e['conta'], $e['admin'], fn () => app(LeaveAccount::class)->handle($e['admin']));

    expect($e['conta']->hasMember($e['membro']))->toBeFalse()
        ->and($e['conta']->hasMember($e['admin']))->toBeFalse()
        ->and($e['conta']->hasMember($e['dono']))->toBeTrue()
        ->and(collect(trilhaDaConta($e['conta']))->where('action', 'account.member_left')->pluck('outcome')->all())
        ->toBe(['denied', 'success', 'success']);
});

it('chave órfã: quem sai deixa as chaves valendo e o dono e os admins recebem o aviso, sem segredo', function (): void {
    $e = empresaCompleta();
    $chave = $this->keyFor($e['membro'], ['name' => 'Chave do membro'], $e['conta']);
    $revogada = $this->keyFor($e['membro'], ['name' => 'Revogada'], $e['conta']);
    Accounts::actingAs($e['conta'], fn () => app(ApiKeyService::class)->revoke($revogada['api_key']), $e['dono']);

    Mail::fake();
    naEmpresa($e['conta'], $e['admin'], fn () => app(RemoveMember::class)->handle($e['admin'], $e['membro']->uuid));

    // A chave continua autenticando depois da saída de quem a criou.
    $this->getJson('/api/v1/projects', $this->credentials($chave['api_key'], $chave['secret_key']))->assertOk();

    // Dono e os DOIS admins (inclusive quem removeu) — nunca quem saiu nem os members.
    $destinos = [];
    Mail::assertQueued(OrphanedApiKeysMail::class, function (OrphanedApiKeysMail $mail) use (&$destinos, $chave): bool {
        $destinos[] = $mail->to[0]['address'];

        $serializado = serialize($mail);
        expect($mail->keys)->toBe([[
            'name' => 'Chave do membro',
            'code' => $chave['api_key']->codigo_publico,
            'public_key' => $chave['api_key']->public_key,
        ]])
            ->and($mail->removed)->toBeTrue()
            ->and($serializado)->not->toContain($chave['secret_key'])
            ->and($serializado)->not->toContain((string) Accounts::asSystem('teste', fn () => ApiKey::query()->whereKey($chave['api_key']->getKey())->value('secret_hash')));

        return true;
    });

    sort($destinos);
    $esperado = [$e['dono']->email, $e['admin']->email, $e['admin2']->email];
    sort($esperado);
    expect($destinos)->toBe($esperado);

    // Quem saiu sem chave nenhuma não gera aviso.
    Mail::fake();
    naEmpresa($e['conta'], $e['membro2'], fn () => app(LeaveAccount::class)->handle($e['membro2']));
    Mail::assertNothingQueued();

    $evento = AuditEvent::query()->where('action', 'account.member_removed')->where('outcome', 'success')->sole();
    expect($evento->changes['orphaned_api_keys']['after'])->toBe(1);
});

it('transferência: só o dono, só com o token de ação sensível — sem ele nada muda e a recusa fica na trilha', function (): void {
    $e = empresaCompleta();
    $transferir = fn (User $quem, User $para, string $token) => naEmpresa($e['conta'], $quem, fn () => app(TransferOwnership::class)->handle($quem, $para->uuid, $token));

    // Sem token, token inventado, token de OUTRA pessoa: recusado.
    $tokenDoAdmin = tokenSensivelDe($e['admin']);
    expect(fn () => $transferir($e['dono'], $e['admin'], ''))->toThrow(AuthorizationException::class)
        ->and(fn () => $transferir($e['dono'], $e['admin'], str_repeat('a', 64)))->toThrow(AuthorizationException::class)
        ->and(fn () => $transferir($e['dono'], $e['admin'], $tokenDoAdmin))->toThrow(AuthorizationException::class);

    // Admin, mesmo com token válido dele, não transfere.
    $tokenDoAdmin = tokenSensivelDe($e['admin']);
    expect(fn () => $transferir($e['admin'], $e['membro'], $tokenDoAdmin))->toThrow(AuthorizationException::class);

    expect($e['conta']->fresh()->owner->is($e['dono']))->toBeTrue()
        ->and(collect(trilhaDaConta($e['conta']))->where('action', 'account.ownership_transferred')->pluck('outcome')->unique()->values()->all())
        ->toBe(['denied']);
});

it('transferência com o token: o membro vira dono, o antigo vira admin e há sempre exatamente um dono', function (): void {
    $e = empresaCompleta();
    $token = tokenSensivelDe($e['dono']);

    naEmpresa($e['conta'], $e['dono'], fn () => app(TransferOwnership::class)->handle($e['dono'], $e['membro']->uuid, $token));

    $conta = $e['conta']->fresh();
    expect($conta->owner->is($e['membro']))->toBeTrue()
        ->and($conta->roleOf($e['dono']))->toBe(AccountRole::Admin)
        ->and($conta->memberships()->where('role', 'owner')->count())->toBe(1)
        ->and($conta->memberships()->count())->toBe(5);

    // O token é de uso único.
    expect(fn () => naEmpresa($conta, $e['membro'], fn () => app(TransferOwnership::class)->handle($e['membro'], $e['dono']->uuid, $token)))
        ->toThrow(AuthorizationException::class);

    $evento = AuditEvent::query()->where('action', 'account.ownership_transferred')->where('outcome', 'success')->sole();
    expect($evento->tenant_uuid)->toBe($conta->uuid)
        ->and($evento->actor_uuid)->toBe($e['dono']->uuid)
        ->and($evento->changes['owner'])->toBe(['before' => $e['dono']->uuid, 'after' => $e['membro']->uuid]);

    // O antigo dono (agora admin) pode sair; o novo, não.
    naEmpresa($conta, $e['dono'], fn () => app(LeaveAccount::class)->handle($e['dono']));
    expect(fn () => naEmpresa($conta, $e['membro'], fn () => app(LeaveAccount::class)->handle($e['membro'])))
        ->toThrow(AuthorizationException::class);
});

it('a conta pessoal não se transfere nem se exclui por aqui', function (): void {
    $dona = $this->owner();
    $pessoal = $this->accountOf($dona);
    $membro = $this->owner();
    app(AccountService::class)->addMember($pessoal, $membro, AccountRole::Member);

    expect(fn () => naEmpresa($pessoal, $dona, fn () => app(TransferOwnership::class)->handle($dona, $membro->uuid, tokenSensivelDe($dona))))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => naEmpresa($pessoal, $dona, fn () => app(DeleteAccount::class)->handle($dona, tokenSensivelDe($dona))))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => naEmpresa($pessoal, $dona, fn () => app(RenameAccount::class)->handle($dona, 'Outro nome')))
        ->toThrow(AuthorizationException::class);

    expect(Account::query()->whereKey($pessoal->getKey())->exists())->toBeTrue()
        ->and($pessoal->fresh()->owner->is($dona))->toBeTrue();
});

it('criar conta: a pessoa vira dona; nome obrigatório; limite de contas de empresa', function (): void {
    config()->set('accounts.limits.owned_accounts', 2);
    $pessoa = $this->owner();
    $this->actingAs($pessoa);

    $conta = app(CreateAccount::class)->handle($pessoa, '  Nova Empresa  ');
    expect($conta->name)->toBe('Nova Empresa')
        ->and($conta->owner->is($pessoa))->toBeTrue()
        ->and($conta->isPersonal())->toBeFalse();

    expect(fn () => app(CreateAccount::class)->handle($pessoa, '   '))->toThrow(ValidationException::class);

    app(CreateAccount::class)->handle($pessoa, 'Segunda');
    expect(fn () => app(CreateAccount::class)->handle($pessoa, 'Terceira'))->toThrow(ValidationException::class);

    expect(trilhaDaConta($conta))->toBe([['action' => 'account.created', 'outcome' => 'success']])
        ->and(AuditEvent::query()->where('action', 'account.created')->where('outcome', 'denied')->count())->toBe(2);
});

it('renomear: dono e admin; member não', function (): void {
    $e = empresaCompleta();

    naEmpresa($e['conta'], $e['admin'], fn () => app(RenameAccount::class)->handle($e['admin'], 'Nome do admin'));
    expect($e['conta']->fresh()->name)->toBe('Nome do admin');

    expect(fn () => naEmpresa($e['conta'], $e['membro'], fn () => app(RenameAccount::class)->handle($e['membro'], 'Nome do membro')))
        ->toThrow(AuthorizationException::class);

    expect($e['conta']->fresh()->name)->toBe('Nome do admin');

    $evento = AuditEvent::query()->where('action', 'account.renamed')->where('outcome', 'success')->sole();
    expect($evento->changes['name'])->toBe(['before' => 'Empresa Completa', 'after' => 'Nome do admin']);
});

it('excluir conta: só o dono, com o token de ação sensível — a conta sai com os dados', function (): void {
    $e = empresaCompleta();
    Accounts::actingAs($e['conta'], fn () => Project::createWithPublicCodeRetry(['name' => 'Projeto da empresa']), $e['dono']);
    $this->keyFor($e['admin'], ['name' => 'Chave da empresa'], $e['conta']);

    expect(fn () => naEmpresa($e['conta'], $e['admin'], fn () => app(DeleteAccount::class)->handle($e['admin'], tokenSensivelDe($e['admin']))))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => naEmpresa($e['conta'], $e['dono'], fn () => app(DeleteAccount::class)->handle($e['dono'], 'token-errado')))
        ->toThrow(AuthorizationException::class);

    expect(Account::query()->whereKey($e['conta']->getKey())->exists())->toBeTrue();

    naEmpresa($e['conta'], $e['dono'], fn () => app(DeleteAccount::class)->handle($e['dono'], tokenSensivelDe($e['dono'])));

    expect(Account::query()->whereKey($e['conta']->getKey())->exists())->toBeFalse()
        ->and(Accounts::asSystem('teste', fn () => Project::query()->where('account_id', $e['conta']->getKey())->count()))->toBe(0)
        ->and(Accounts::asSystem('teste', fn () => ApiKey::query()->where('account_id', $e['conta']->getKey())->count()))->toBe(0)
        ->and(User::query()->whereKey($e['admin']->getKey())->exists())->toBeTrue()
        ->and(collect(trilhaDaConta($e['conta']))->where('action', 'account.deleted')->pluck('outcome')->all())
        ->toBe(['denied', 'denied', 'success']);
});

it('trocar de conta: só conta de que a pessoa é membro; a recusa é 403 com a linha denied', function (): void {
    $e = empresaCompleta();
    $estranho = $this->owner();
    $this->actingAs($estranho);

    expect(fn () => app(SwitchAccount::class)->handle($estranho, $e['conta']->uuid))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SwitchAccount::class)->handle($estranho, '00000000-0000-7000-8000-000000000000'))->toThrow(AuthorizationException::class)
        ->and(session('accounts.current'))->toBeNull();

    $this->actingAs($e['membro']);
    app(SwitchAccount::class)->handle($e['membro'], $e['conta']->uuid);
    expect(session('accounts.current'))->toBe($e['conta']->uuid)
        ->and(Accounts::current()?->is($e['conta']))->toBeTrue()
        ->and(AuditEvent::query()->where('action', 'account.switched')->where('outcome', AuditOutcome::Denied->value)->count())->toBe(2);
});

it('cada linha da trilha diz quem agiu, a conta, o alvo, o IP e o correlation_id', function (): void {
    $e = empresaCompleta();

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Teste/1.0']);
    naEmpresa($e['conta'], $e['dono'], fn () => app(ChangeMemberRole::class)->handle($e['dono'], $e['membro']->uuid, AccountRole::Admin));

    $evento = AuditEvent::query()->where('action', 'account.member_role_changed')->sole();

    expect($evento->actor_uuid)->toBe($e['dono']->uuid)
        ->and($evento->tenant_uuid)->toBe($e['conta']->uuid)
        ->and($evento->subject_type)->toBe('user')
        ->and($evento->subject_uuid)->toBe($e['membro']->uuid)
        ->and($evento->changes['role'])->toBe(['before' => 'member', 'after' => 'admin'])
        ->and($evento->correlation_id)->not->toBeNull()
        ->and($evento->outcome)->toBe(AuditOutcome::Success);
});

it('falha fechada: se a trilha não grava, a mudança é desfeita', function (): void {
    $e = empresaCompleta();

    AuditEvent::creating(function (): void {
        throw new RuntimeException('trilha fora do ar');
    });

    expect(fn () => naEmpresa($e['conta'], $e['dono'], fn () => app(ChangeMemberRole::class)->handle($e['dono'], $e['membro']->uuid, AccountRole::Admin)))
        ->toThrow(RuntimeException::class, 'trilha fora do ar');

    expect($e['conta']->roleOf($e['membro']))->toBe(AccountRole::Member);
});

it('chave órfã na EXCLUSÃO da pessoa (qualquer caminho Eloquent): aviso ao dono e aos admins de cada conta alheia, uma vez por conta', function (): void {
    $e = empresaCompleta();
    $outraDona = User::fixture(['email_verified_at' => now()]);
    $outra = app(AccountService::class)->createAccount('Outra Empresa', $outraDona);
    app(AccountService::class)->addMember($outra, $e['membro'], AccountRole::Admin);

    $chave = $this->keyFor($e['membro'], ['name' => 'Na empresa'], $e['conta']);
    $this->keyFor($e['membro'], ['name' => 'Na outra 1'], $outra);
    $this->keyFor($e['membro'], ['name' => 'Na outra 2'], $outra);
    $this->keyFor($e['membro'], ['name' => 'Pessoal']);

    Mail::fake();
    $e['membro']->delete();

    // Empresa: dono + 2 admins; Outra: a dona. Um e-mail por destinatário e conta.
    Mail::assertQueued(OrphanedApiKeysMail::class, 4);
    Mail::assertQueued(OrphanedApiKeysMail::class, fn (OrphanedApiKeysMail $m): bool => $m->hasTo($outraDona->email)
        && $m->deleted
        && array_column($m->keys, 'name') === ['Na outra 1', 'Na outra 2']);
    Mail::assertQueued(OrphanedApiKeysMail::class, fn (OrphanedApiKeysMail $m): bool => $m->hasTo($e['dono']->email)
        && array_column($m->keys, 'name') === ['Na empresa']
        && ! str_contains(serialize($m), $chave['secret_key']));

    // A chave continua autenticando.
    $this->getJson('/api/v1/projects', $this->credentials($chave['api_key'], $chave['secret_key']))->assertOk();
});

it('exclusão de pessoa sem chave em conta alheia não avisa; exclusão recusada também não', function (): void {
    $e = empresaCompleta();
    Mail::fake();

    $e['membro2']->delete();
    expect(fn () => $e['dono']->delete())->toThrow(OwnerOfSharedAccountException::class);

    Mail::assertNotQueued(OrphanedApiKeysMail::class);
});
