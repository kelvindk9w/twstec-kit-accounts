<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Enums\InvitationStatus;
use Twstec\Kit\Accounts\Account\Exceptions\InvitationUnavailableException;
use Twstec\Kit\Accounts\Account\Invitations\InvitationPreview;
use Twstec\Kit\Accounts\Account\Invitations\InvitationTokens;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Models\AccountInvitation;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Support\UserModel;

/**
 * ACEITE do convite por quem ainda NÃO TEM CONTA na plataforma: o aceite CRIA
 * a conta (decisão do dono do kit) — com o e-mail do convite (a pessoa não o
 * escolhe), o nome e a senha que ela deu (a senha pela política do kit,
 * validada no Form Request) — e a pessoa entra na conta do convite.
 *
 * A conta nasce com o e-mail CONFIRMADO: o link só chega a quem lê aquela
 * caixa, que é exatamente o que a verificação de e-mail prova.
 *
 * - O e-mail do convite já tem conta → `has_account` (entrar e aceitar).
 * - Convite fora de validade → o motivo, como no aceite logado.
 * - Tudo numa transação: pessoa, conta pessoal, consumo do convite, vínculo
 *   e trilha (`account.invitation_accepted`, com a pessoa nova como quem
 *   agiu). Depois, a sessão é aberta (regenerada — fixation).
 */
final class RegisterAndAcceptInvitation
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly AccountAudit $audit,
    ) {}

    /**
     * @param  array{name: string, password: string}  $input  Já validado.
     * @return array{user: AuthUser, account: Account}
     *
     * @throws InvitationUnavailableException
     */
    public function handle(Request $request, #[\SensitiveParameter] string $token, #[\SensitiveParameter] array $input): array
    {
        $invitation = InvitationTokens::find($token);

        if ($invitation === null) {
            throw $this->refuse(new InvitationUnavailableException(InvitationUnavailableException::NOT_FOUND));
        }

        $status = $invitation->status();

        if ($status !== InvitationStatus::Pending) {
            throw $this->refuse(InvitationUnavailableException::forStatus($status, $invitation));
        }

        if (InvitationPreview::emailHasAccount($invitation)) {
            throw $this->refuse(new InvitationUnavailableException(InvitationUnavailableException::HAS_ACCOUNT, $invitation));
        }

        $account = $invitation->account;

        try {
            $user = $this->createAndAccept($invitation, $account, $input);
        } catch (InvitationUnavailableException $exception) {
            // Outro pedido usou o convite no meio do caminho: a transação
            // desfez a pessoa criada; a recusa é gravada fora dela.
            throw $this->refuse($exception);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return ['user' => $user, 'account' => $account];
    }

    /**
     * @param  array{name: string, password: string}  $input
     *
     * @throws InvitationUnavailableException
     */
    private function createAndAccept(AccountInvitation $invitation, Account $account, #[\SensitiveParameter] array $input): AuthUser
    {
        return DB::transaction(function () use ($invitation, $account, $input): AuthUser {
            /** @var AuthUser $user */
            $user = UserModel::name()::createWithPublicCodeRetry([
                'name' => $input['name'],
                'email' => $invitation->email,
                // Cast 'hashed' do model aplica o hash (Argon2id no kit).
                'password' => $input['password'],
                'locale' => app()->getLocale(),
            ]);

            $user->markEmailAsVerified();

            if (! InvitationTokens::consume($invitation, $user->getKey())) {
                throw InvitationUnavailableException::forStatus($invitation->refresh()->status(), $invitation);
            }

            $membership = $this->accounts->addMember($account, $user, $invitation->role);

            $this->audit->record(AccountAuditEvent::InvitationAccepted, $account, $user, $invitation, [
                'member' => ['before' => null, 'after' => (string) $user->getAttribute('uuid')],
                'role' => ['before' => null, 'after' => $membership->role->value],
                'registered' => ['before' => false, 'after' => true],
            ]);

            return $user;
        });
    }

    private function refuse(InvitationUnavailableException $exception): InvitationUnavailableException
    {
        return AcceptInvitation::recordRefusal($this->audit, AccountAuditEvent::InvitationAccepted, $exception, null);
    }
}
