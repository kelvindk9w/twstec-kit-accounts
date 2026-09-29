<?php

declare(strict_types=1);

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Twstec\Kit\Accounts\Account\Actions\AcceptInvitation;
use Twstec\Kit\Accounts\Account\Actions\InviteMember;
use Twstec\Kit\Accounts\Account\Actions\ResendInvitation;
use Twstec\Kit\Accounts\Account\Actions\RevokeInvitation;
use Twstec\Kit\Accounts\Account\Contracts\Responses\InvitationAcceptedResponse;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Enums\InvitationStatus;
use Twstec\Kit\Accounts\Account\Exceptions\InvitationUnavailableException;
use Twstec\Kit\Accounts\Account\Exceptions\MissingAccountContextException;
use Twstec\Kit\Accounts\Account\Http\Controllers\AccountSwitchController;
use Twstec\Kit\Accounts\Account\Http\Controllers\InvitationController;
use Twstec\Kit\Accounts\Account\Invitations\InvitationPreview;
use Twstec\Kit\Accounts\Account\Invitations\InvitationTokens;
use Twstec\Kit\Accounts\Account\Mail\AccountInvitationMail;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Models\AccountInvitation;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\Tests\Fixtures\User;
use Twstec\Kit\Foundation\Audit\Models\AuditEvent;

// =============================================================================
// CONVITES pelas Actions e pelos controllers do pacote, numa aplicação limpa.
//
// - O token do link só existe em claro no e-mail: no banco, só o hash.
// - Uso único, expiração, revogação, reenvio (link novo, o antigo morre).
// - Aceite só pelo MESMO e-mail; outra pessoa logada recebe a recusa sem
//   dado da conta; e-mail sem conta → o aceite cria a conta, já verificada.
// - Sem enumeração: convidar um e-mail que tem conta e um que não tem dá a
//   mesma resposta.
// - Limites: convite pendente duplicado, máximo de pendentes, intervalo por
//   conta e por pessoa; papel owner nunca; quem já é membro, recusa.
// - Cada evento na trilha; cada recusa como `denied`.
// =============================================================================

beforeEach(function (): void {
    // As rotas que o FRONT declara (no starter, routes/web.php), apontando
    // para os controllers do pacote — o throttle vem com o controller.
    Route::middleware('web')->group(function (): void {
        Route::get('/dashboard', fn () => 'painel')->name('dashboard');
        Route::get('/convite/{token}', fn (string $token) => 'tela do convite')->name('invitations.show');
        Route::post('/convite/{token}/aceitar', [InvitationController::class, 'accept'])->middleware('auth');
        Route::post('/convite/{token}/cadastrar', [InvitationController::class, 'register'])->middleware('guest');
        Route::post('/convite/{token}/recusar', [InvitationController::class, 'decline']);
        Route::post('/contas/{account}/trocar', [AccountSwitchController::class, 'store'])->middleware('auth');
    });
});

/**
 * @return array{conta: Account, dono: User, admin: User, membro: User}
 */
function empresaParaConvites(): array
{
    $dono = User::fixture(['email_verified_at' => now(), 'name' => 'Dona da Empresa']);
    $conta = app(AccountService::class)->createAccount('Empresa dos Convites', $dono);
    $admin = User::fixture(['email_verified_at' => now()]);
    $membro = User::fixture(['email_verified_at' => now()]);
    app(AccountService::class)->addMember($conta, $admin, AccountRole::Admin);
    app(AccountService::class)->addMember($conta, $membro, AccountRole::Member);

    return ['conta' => $conta, 'dono' => $dono, 'admin' => $admin, 'membro' => $membro];
}

/**
 * Convida pela Action, na conta, e devolve o convite e o token EM CLARO
 * capturado do e-mail (Mail::fake).
 *
 * @return array{convite: AccountInvitation, token: string}
 */
function convidar(Account $conta, User $quem, string $email, AccountRole $papel = AccountRole::Member): array
{
    Mail::fake();
    test()->actingAs($quem);

    $convite = Accounts::actingAs($conta, fn () => app(InviteMember::class)->handle($quem, $email, $papel), $quem);

    $token = null;
    Mail::assertQueued(AccountInvitationMail::class, function (AccountInvitationMail $mail) use (&$token, $email): bool {
        $token = $mail->token;

        return $mail->hasTo(mb_strtolower(trim($email)));
    });

    // Quem convidou sai de cena: cada teste diz quem está logado depois.
    app('auth')->forgetGuards();

    return ['convite' => $convite, 'token' => (string) $token];
}

function invitationRows(): array
{
    return DB::table('account_invitations')->get()->map(fn ($r) => (array) $r)->all();
}

it('o token só existe em claro no e-mail: no banco, só o hash SHA-256', function (): void {
    $e = empresaParaConvites();
    ['convite' => $convite, 'token' => $token] = convidar($e['conta'], $e['dono'], 'nova@example.com');

    expect($token)->toMatch('/\A[0-9a-f]{64}\z/');

    $linha = DB::table('account_invitations')->where('id', $convite->getKey())->first();
    expect($linha->token_hash)->toBe(hash('sha256', $token))
        ->and(json_encode(invitationRows()))->not->toContain($token)
        ->and($convite->toArray())->not->toHaveKey('token_hash');

    // A trilha também não tem o token.
    expect(AuditEvent::query()->get()->toJson())->not->toContain($token);
});

it('aceite pelo MESMO e-mail (sem diferença de caixa) vira membro com o papel do convite; uso único', function (): void {
    $e = empresaParaConvites();
    $pessoa = User::fixture(['email' => 'pessoa.convidada@example.com', 'email_verified_at' => now()]);
    ['token' => $token] = convidar($e['conta'], $e['admin'], 'Pessoa.Convidada@Example.com', AccountRole::Admin);

    $this->actingAs($pessoa);
    $conta = app(AcceptInvitation::class)->handle($pessoa, $token);

    expect($conta->is($e['conta']))->toBeTrue()
        ->and($e['conta']->roleOf($pessoa))->toBe(AccountRole::Admin);

    // Segundo uso do mesmo link: recusado como "já usado".
    try {
        app(AcceptInvitation::class)->handle($pessoa, $token);
        $this->fail('o segundo aceite deveria ser recusado');
    } catch (InvitationUnavailableException $ex) {
        expect($ex->reason)->toBe(InvitationUnavailableException::ACCEPTED);
    }

    expect(collect(AuditEvent::query()->where('action', 'account.invitation_accepted')->orderBy('id')->get())->map(fn ($r) => $r->outcome->value)->all())
        ->toBe(['success', 'denied']);
});

it('e-mail de OUTRA pessoa logada: recusa clara, sem dado da conta, e nada muda', function (): void {
    $e = empresaParaConvites();
    ['token' => $token, 'convite' => $convite] = convidar($e['conta'], $e['dono'], 'certa@example.com');
    $outra = User::fixture(['email' => 'outra@example.com', 'email_verified_at' => now()]);

    $preview = InvitationPreview::for($token, $outra);
    expect($preview->state)->toBe(InvitationUnavailableException::WRONG_EMAIL)
        ->and($preview->accountName)->toBeNull()
        ->and($preview->inviterName)->toBeNull()
        ->and($preview->email)->toBeNull();

    $this->actingAs($outra);
    $resposta = $this->post("/convite/{$token}/aceitar");
    $resposta->assertRedirect()->assertSessionHas('invitation_error', __('accounts.invitations.unavailable.wrong_email'));

    $json = $this->postJson("/convite/{$token}/aceitar")->assertStatus(403);
    expect($json->getContent())->not->toContain('Empresa dos Convites')
        ->and($json->getContent())->not->toContain('certa@example.com');

    expect($e['conta']->hasMember($outra))->toBeFalse()
        ->and($convite->fresh()->status())->toBe(InvitationStatus::Pending)
        ->and(AuditEvent::query()->where('action', 'account.invitation_accepted')->where('outcome', 'denied')->count())->toBe(2);
});

it('e-mail SEM conta: o aceite cria a conta (nome + senha pela política), já verificada, e entra na conta', function (): void {
    $e = empresaParaConvites();
    ['token' => $token] = convidar($e['conta'], $e['dono'], 'sem.conta@example.com');

    expect(InvitationPreview::for($token, null)->mode)->toBe(InvitationPreview::MODE_REGISTER)
        ->and(InvitationPreview::for($token, null)->accountName)->toBe('Empresa dos Convites');

    // A política de senha vale (a mesma do cadastro).
    $this->post("/convite/{$token}/cadastrar", ['name' => 'Nova Pessoa', 'password' => '123', 'password_confirmation' => '123'])
        ->assertSessionHasErrors('password');
    expect(User::query()->where('email', 'sem.conta@example.com')->exists())->toBeFalse();

    $this->post("/convite/{$token}/cadastrar", ['name' => 'Nova Pessoa', 'password' => 'SenhaForte123', 'password_confirmation' => 'SenhaForte123', 'email' => 'outro@example.com'])
        ->assertRedirect(route('dashboard'));

    $nova = User::query()->where('email', 'sem.conta@example.com')->sole();
    expect($nova->hasVerifiedEmail())->toBeTrue()
        ->and(User::query()->where('email', 'outro@example.com')->exists())->toBeFalse()
        ->and($e['conta']->roleOf($nova))->toBe(AccountRole::Member)
        ->and(app(AccountService::class)->personalAccountOf($nova))->not->toBeNull()
        ->and(auth()->id())->toBe($nova->getKey())
        ->and(session('accounts.current'))->toBe($e['conta']->uuid);

    $evento = AuditEvent::query()->where('action', 'account.invitation_accepted')->where('outcome', 'success')->sole();
    expect($evento->actor_uuid)->toBe($nova->uuid)
        ->and($evento->tenant_uuid)->toBe($e['conta']->uuid);
});

it('e-mail que JÁ tem conta: a tela pede para entrar, e o cadastro pelo link é recusado', function (): void {
    $e = empresaParaConvites();
    User::fixture(['email' => 'ja.tem@example.com']);
    ['token' => $token] = convidar($e['conta'], $e['dono'], 'ja.tem@example.com');

    expect(InvitationPreview::for($token, null)->mode)->toBe(InvitationPreview::MODE_LOGIN);

    $this->post("/convite/{$token}/cadastrar", ['name' => 'X', 'password' => 'SenhaForte123', 'password_confirmation' => 'SenhaForte123'])
        ->assertSessionHas('invitation_error', __('accounts.invitations.unavailable.has_account'));

    expect(User::query()->where('email', 'ja.tem@example.com')->count())->toBe(1);
});

it('a conta logada sem e-mail confirmado passa a ter o e-mail confirmado ao aceitar (o link chegou nele)', function (): void {
    $e = empresaParaConvites();
    $pessoa = User::fixture(['email' => 'nao.verificada@example.com']);
    ['token' => $token] = convidar($e['conta'], $e['dono'], 'nao.verificada@example.com');

    expect($pessoa->hasVerifiedEmail())->toBeFalse();

    $this->actingAs($pessoa)->post("/convite/{$token}/aceitar")->assertRedirect(route('dashboard'));

    expect($pessoa->fresh()->hasVerifiedEmail())->toBeTrue()
        ->and($e['conta']->hasMember($pessoa))->toBeTrue();
});

it('expirado, revogado e recusado: o link não aceita, cada um com o motivo', function (): void {
    $e = empresaParaConvites();
    $pessoa = User::fixture(['email' => 'estados@example.com', 'email_verified_at' => now()]);

    ['token' => $expirado] = convidar($e['conta'], $e['dono'], 'estados@example.com');
    $this->travel(8)->days();
    expect(InvitationPreview::for($expirado, $pessoa)->state)->toBe(InvitationUnavailableException::EXPIRED);

    ['token' => $revogado, 'convite' => $c2] = convidar($e['conta'], $e['dono'], 'estados@example.com');
    Accounts::actingAs($e['conta'], fn () => app(RevokeInvitation::class)->handle($e['dono'], $c2->uuid), $e['dono']);

    $motivos = [];
    foreach ([$expirado, $revogado, str_repeat('f', 64), 'curto'] as $token) {
        try {
            app(AcceptInvitation::class)->handle($pessoa, $token);
        } catch (InvitationUnavailableException $ex) {
            $motivos[] = $ex->reason;
        }
    }

    expect($motivos)->toBe(['expired', 'revoked', 'not_found', 'not_found'])
        ->and($e['conta']->hasMember($pessoa))->toBeFalse();

    // Recusa pela pessoa convidada: o link morre.
    ['token' => $recusado] = convidar($e['conta'], $e['dono'], 'estados@example.com');
    $this->actingAs($pessoa)->post("/convite/{$recusado}/recusar")->assertRedirect();
    expect(InvitationPreview::for($recusado, $pessoa)->state)->toBe(InvitationUnavailableException::DECLINED)
        ->and(AuditEvent::query()->where('action', 'account.invitation_declined')->where('outcome', 'success')->count())->toBe(1);
});

it('reenviar gera link novo (o antigo morre) e renova a validade; revogar mata o link', function (): void {
    $e = empresaParaConvites();
    ['token' => $antigo, 'convite' => $convite] = convidar($e['conta'], $e['dono'], 'reenvio@example.com');
    $this->travel(6)->days();

    Mail::fake();
    Accounts::actingAs($e['conta'], fn () => app(ResendInvitation::class)->handle($e['admin'], $convite->uuid), $e['admin']);

    $novo = null;
    Mail::assertQueued(AccountInvitationMail::class, function (AccountInvitationMail $mail) use (&$novo): bool {
        $novo = $mail->token;

        return true;
    });

    expect($novo)->not->toBe($antigo)
        ->and(InvitationTokens::find($antigo))->toBeNull()
        ->and(InvitationTokens::find($novo)?->is($convite))->toBeTrue()
        ->and($convite->fresh()->send_count)->toBe(2)
        ->and($convite->fresh()->expires_at->isAfter(now()->addDays(6)))->toBeTrue();

    Accounts::actingAs($e['conta'], fn () => app(RevokeInvitation::class)->handle($e['dono'], $convite->uuid), $e['dono']);
    expect(InvitationPreview::for($novo, null)->state)->toBe(InvitationUnavailableException::REVOKED);

    // Revogado não volta por reenvio.
    expect(fn () => Accounts::actingAs($e['conta'], fn () => app(ResendInvitation::class)->handle($e['dono'], $convite->uuid), $e['dono']))
        ->toThrow(ValidationException::class);
});

it('SEM ENUMERAÇÃO: convidar e-mail com conta e sem conta dá a mesma resposta e o mesmo e-mail', function (): void {
    $e = empresaParaConvites();
    User::fixture(['email' => 'existe@example.com']);

    $com = convidar($e['conta'], $e['dono'], 'existe@example.com');
    $sem = convidar($e['conta'], $e['dono'], 'nao.existe@example.com');

    expect($com['convite']->status())->toBe($sem['convite']->status())
        ->and(array_keys($com['convite']->toArray()))->toBe(array_keys($sem['convite']->toArray()))
        ->and(AuditEvent::query()->where('action', 'account.invitation_created')->where('outcome', 'success')->count())->toBe(2);

    // A linha da trilha mascara o e-mail.
    $linha = AuditEvent::query()->where('action', 'account.invitation_created')->first();
    expect(json_encode($linha->changes))->not->toContain('existe@example.com');
});

it('recusas do convite: papel owner, member convidando, quem já é membro, pendente duplicado, e-mail inválido', function (): void {
    $e = empresaParaConvites();
    $recusa = fn (User $quem, string $email, AccountRole $papel, string $excecao) => expect(fn () => convidar($e['conta'], $quem, $email, $papel))->toThrow($excecao);

    $recusa($e['dono'], 'novo@example.com', AccountRole::Owner, AuthorizationException::class);
    $recusa($e['membro'], 'novo@example.com', AccountRole::Member, AuthorizationException::class);
    $recusa($e['dono'], strtoupper($e['admin']->email), AccountRole::Member, ValidationException::class);
    $recusa($e['dono'], 'nao-e-email', AccountRole::Member, ValidationException::class);

    convidar($e['conta'], $e['admin'], 'duplicado@example.com', AccountRole::Admin);
    $recusa($e['dono'], 'duplicado@example.com', AccountRole::Member, ValidationException::class);

    expect(DB::table('account_invitations')->count())->toBe(1)
        ->and(AuditEvent::query()->where('action', 'account.invitation_created')->where('outcome', 'denied')->count())->toBe(5);
});

it('limite de convites pendentes por conta e intervalo por conta e por pessoa', function (): void {
    $e = empresaParaConvites();

    config()->set('accounts.invitations.max_pending', 2);
    convidar($e['conta'], $e['dono'], 'a@example.com');
    convidar($e['conta'], $e['dono'], 'b@example.com');
    expect(fn () => convidar($e['conta'], $e['dono'], 'c@example.com'))->toThrow(ValidationException::class);

    config()->set('accounts.invitations.max_pending', 100);
    config()->set('accounts.invitations.throttle.per_person', 4);
    // O dono já fez 3 tentativas (a recusada pelo limite também conta).
    convidar($e['conta'], $e['dono'], 'd@example.com');
    expect(fn () => convidar($e['conta'], $e['dono'], 'e@example.com'))->toThrow(ValidationException::class);

    // Outra pessoa da mesma conta ainda convida — até o balde da CONTA.
    config()->set('accounts.invitations.throttle.per_account', 5);
    convidar($e['conta'], $e['admin'], 'f@example.com');
    expect(fn () => convidar($e['conta'], $e['admin'], 'g@example.com'))->toThrow(ValidationException::class);

    // Passada a janela, volta a caber.
    $this->travel((int) config('accounts.invitations.throttle.minutes') + 1)->minutes();
    convidar($e['conta'], $e['admin'], 'g@example.com');

    expect(AuditEvent::query()->where('action', 'account.invitation_created')->where('outcome', 'denied')->count())->toBe(3);
});

it('convites são dado da conta: a lista e o reenvio de outra conta não enxergam (404)', function (): void {
    $e = empresaParaConvites();
    $outraDona = $this->owner();
    $outra = app(AccountService::class)->createAccount('Outra', $outraDona);
    ['convite' => $convite] = convidar($e['conta'], $e['dono'], 'isolado@example.com');

    expect(Accounts::actingAs($outra, fn () => AccountInvitation::query()->count(), $outraDona))->toBe(0)
        ->and(fn () => Accounts::actingAs($outra, fn () => app(RevokeInvitation::class)->handle($outraDona, $convite->uuid), $outraDona))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => AccountInvitation::query()->count())->toThrow(MissingAccountContextException::class);
});

it('a troca de conta pela rota do pacote só vale para conta de que a pessoa é membro', function (): void {
    $e = empresaParaConvites();
    $estranho = $this->owner();

    $this->actingAs($estranho)->post("/contas/{$e['conta']->uuid}/trocar")->assertForbidden();
    $this->actingAs($e['membro'])->from('/dashboard')->post("/contas/{$e['conta']->uuid}/trocar")
        ->assertRedirect('/dashboard')
        ->assertSessionHas('accounts.current', $e['conta']->uuid);
});

it('as respostas são contratos: o aplicativo troca a do aceite sem tocar na regra', function (): void {
    $e = empresaParaConvites();
    $pessoa = User::fixture(['email' => 'contrato@example.com', 'email_verified_at' => now()]);
    ['token' => $token] = convidar($e['conta'], $e['dono'], 'contrato@example.com');

    app()->bind(
        InvitationAcceptedResponse::class,
        fn () => new class implements InvitationAcceptedResponse
        {
            public function toResponse(Request $request, Account $account): Response
            {
                return response('aceito: '.$account->uuid, 201);
            }
        },
    );

    $this->actingAs($pessoa)->post("/convite/{$token}/aceitar")->assertStatus(201)->assertSee('aceito: '.$e['conta']->uuid);
    expect($e['conta']->hasMember($pessoa))->toBeTrue();
});

it('o aceite e a recusa pelo link vêm com o throttle:sensitive do controller', function (): void {
    $rota = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => str_contains($r->uri(), 'aceitar'));

    expect(Route::getRoutes()->getByAction(InvitationController::class.'@accept'))->not->toBeNull()
        ->and($rota->controllerMiddleware())->toContain('throttle:sensitive');
});

it('uso único no banco: dois consumos do mesmo convite (a corrida de dois aceites) — só o primeiro vale', function (): void {
    $e = empresaParaConvites();
    ['convite' => $convite, 'token' => $token] = convidar($e['conta'], $e['dono'], 'corrida@example.com');
    $a = User::fixture(['email' => 'corrida@example.com']);

    // Os dois pedidos leram o convite PENDENTE antes de qualquer um gravar.
    $leitura1 = InvitationTokens::find($token);
    $leitura2 = InvitationTokens::find($token);

    expect(InvitationTokens::consume($leitura1, $a->getKey()))->toBeTrue()
        ->and(InvitationTokens::consume($leitura2, $a->getKey()))->toBeFalse()
        ->and($convite->fresh()->status())->toBe(InvitationStatus::Accepted)
        ->and(InvitationTokens::decline($leitura2))->toBeFalse();
});
