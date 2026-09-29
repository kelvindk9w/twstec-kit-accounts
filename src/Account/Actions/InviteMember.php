<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Twstec\Kit\Accounts\Account\Actions\Concerns\GuardsAccountAction;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Invitations\InvitationTokens;
use Twstec\Kit\Accounts\Account\Mail\AccountInvitationMail;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Models\AccountInvitation;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Accounts\Account\Support\InvitationThrottle;
use Twstec\Kit\Accounts\Account\Support\MemberRules;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * CONVIDA uma pessoa, por e-mail, para a conta ATUAL — como admin ou member
 * (nunca owner: a propriedade só muda por transferência).
 *
 * Quem pode: dono e admin (AccountAbility::ManageMembers; ver MemberRules).
 *
 * Recusas (cada uma com `denied` na trilha):
 * - e-mail inválido; papel owner;
 * - a pessoa JÁ É MEMBRO desta conta;
 * - já existe convite PENDENTE para o mesmo e-mail nesta conta (reenvie);
 * - a conta chegou ao limite de convites pendentes
 *   (`accounts.invitations.max_pending`);
 * - o INTERVALO: convites (novos e reenvios) por conta e por pessoa numa
 *   janela (`accounts.invitations.throttle.*`).
 *
 * SEM ENUMERAÇÃO: a resposta é a mesma para um e-mail que já tem conta na
 * plataforma e para um que não tem — o convite é criado e o e-mail sai nos
 * dois casos; é a tela do link que decide entre "entrar" e "criar a conta".
 *
 * O token do link é gerado aqui e só o hash é gravado; o e-mail (enfileirado,
 * com o payload criptografado) leva o token em claro.
 *
 * Trilha: `account.invitation_created` (e-mail mascarado e papel).
 */
final class InviteMember
{
    use GuardsAccountAction;

    public function __construct(private readonly AccountAudit $audit) {}

    public function handle(AuthUser $actor, string $email, AccountRole $role): AccountInvitation
    {
        $account = $this->currentAccount();
        $actorRole = $this->requireAbility(AccountAuditEvent::InvitationCreated, $account, $actor, AccountAbility::ManageMembers);

        if (! MemberRules::canInvite($actorRole, $role)) {
            $this->deny(AccountAuditEvent::InvitationCreated, $account, $actor, __('accounts.invitations.owner_role'), subjectType: 'account_invitation');
        }

        $email = AccountInvitation::normalizeEmail($email);

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->reject(AccountAuditEvent::InvitationCreated, $account, $actor, 'email', __('accounts.invitations.email_invalid'), subjectType: 'account_invitation');
        }

        if (($espera = InvitationThrottle::attempt($account, $actor)) !== null) {
            $this->reject(AccountAuditEvent::InvitationCreated, $account, $actor, 'email', InvitationThrottle::message($espera), subjectType: 'account_invitation');
        }

        if ($this->isMember($account, $email)) {
            $this->reject(AccountAuditEvent::InvitationCreated, $account, $actor, 'email', __('accounts.invitations.already_member'), subjectType: 'account_invitation');
        }

        if (AccountInvitation::query()->pending()->where('email', $email)->exists()) {
            $this->reject(AccountAuditEvent::InvitationCreated, $account, $actor, 'email', __('accounts.invitations.already_pending'), subjectType: 'account_invitation');
        }

        $limite = (int) config('accounts.invitations.max_pending', 20);

        if (AccountInvitation::query()->pending()->count() >= $limite) {
            $this->reject(AccountAuditEvent::InvitationCreated, $account, $actor, 'email', __('accounts.invitations.too_many_pending', ['max' => $limite]), subjectType: 'account_invitation');
        }

        $token = InvitationTokens::generate();

        $invitation = DB::transaction(function () use ($account, $actor, $email, $role, $token): AccountInvitation {
            /** @var AccountInvitation $invitation */
            $invitation = AccountInvitation::query()->create([
                'email' => $email,
                'role' => $role,
                'token_hash' => $token['hash'],
                'created_by' => $actor->getKey(),
                'expires_at' => self::expiresAt(),
                'last_sent_at' => now(),
                'send_count' => 1,
            ]);

            $this->audit->record(AccountAuditEvent::InvitationCreated, $account, $actor, $invitation, [
                'email' => ['before' => null, 'after' => $email],
                'role' => ['before' => null, 'after' => $role->value],
            ]);

            return $invitation;
        });

        self::send($account, $actor, $invitation, $token['plain']);

        return $invitation;
    }

    /**
     * A validade de um convite novo (ou reenviado), a partir de agora.
     */
    public static function expiresAt(): Carbon
    {
        return now()->addHours(max(1, (int) config('accounts.invitations.expires_hours', 168)));
    }

    /**
     * Enfileira o e-mail do convite (no idioma de quem convida: o convidado
     * ainda não tem — ou não sabemos se tem — preferência).
     */
    public static function send(Account $account, AuthUser $actor, AccountInvitation $invitation, #[\SensitiveParameter] string $plainToken): void
    {
        Mail::to($invitation->email)->locale(app()->getLocale())->queue(new AccountInvitationMail(
            accountName: $account->displayName(),
            inviterName: (string) $actor->getAttribute('name'),
            roleLabel: $invitation->role->label(),
            token: $plainToken,
            expiresAt: $invitation->expires_at,
        ));
    }

    private function isMember(Account $account, string $email): bool
    {
        return $account->members()->whereRaw('lower(email) = ?', [$email])->exists();
    }

    protected function audit(): AccountAudit
    {
        return $this->audit;
    }
}
