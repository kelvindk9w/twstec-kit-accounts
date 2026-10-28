<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Account\Actions\Concerns\GuardsAccountAction;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Enums\InvitationStatus;
use Twstec\Kit\Accounts\Account\Invitations\InvitationTokens;
use Twstec\Kit\Accounts\Account\Models\AccountInvitation;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Accounts\Account\Support\InvitationThrottle;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * REENVIA um convite da conta ATUAL — pendente ou expirado (aceito,
 * revogado ou recusado não voltam). Gera um token NOVO (o link antigo morre:
 * o hash é trocado) e renova a validade. Conta no mesmo intervalo dos
 * convites novos.
 *
 * Reenviar um EXPIRADO é criar um convite de novo: valem as mesmas recusas
 * da criação (InviteMember::refusalFor) — a pessoa já é membro, já existe
 * outro convite pendente para o e-mail, a conta chegou ao limite de
 * pendentes. O pendente reenviado já conta no limite.
 *
 * A troca do token é um UPDATE condicional (só se o convite continua sem
 * aceite, recusa ou revogação): se ele foi aceito ou recusado no meio, nada
 * muda e a recusa diz o estado novo.
 *
 * Quem pode: dono e admin. Convite de outra conta = 404 (o escopo da conta).
 *
 * Trilha: `account.invitation_resent`.
 */
final class ResendInvitation
{
    use GuardsAccountAction;

    public function __construct(private readonly AccountAudit $audit) {}

    public function handle(AuthUser $actor, string $invitationUuid): AccountInvitation
    {
        $account = $this->currentAccount();

        /** @var AccountInvitation $invitation */
        $invitation = AccountInvitation::query()->byUuid($invitationUuid)->firstOrFail();

        $this->requireAbility(AccountAuditEvent::InvitationResent, $account, $actor, AccountAbility::ManageMembers, $invitation);

        if (! in_array($invitation->status(), [InvitationStatus::Pending, InvitationStatus::Expired], true)) {
            $this->reject(AccountAuditEvent::InvitationResent, $account, $actor, 'invitation', __('accounts.invitations.not_resendable'), $invitation);
        }

        if (($espera = InvitationThrottle::attempt($account, $actor)) !== null) {
            $this->reject(AccountAuditEvent::InvitationResent, $account, $actor, 'invitation', InvitationThrottle::message($espera), $invitation);
        }

        $revive = $invitation->status() === InvitationStatus::Expired;

        if (($motivo = InviteMember::refusalFor($account, $invitation->email, $invitation, becomesPending: $revive)) !== null) {
            $this->reject(AccountAuditEvent::InvitationResent, $account, $actor, 'invitation', $motivo, $invitation);
        }

        $token = InvitationTokens::generate();
        $antes = $invitation->expires_at->toIso8601String();
        $envios = $invitation->send_count;

        $trocou = DB::transaction(function () use ($account, $actor, $invitation, $token, $antes, $envios): bool {
            $trocou = AccountInvitation::query()
                ->whereKey($invitation->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->whereNull('declined_at')
                ->update([
                    'token_hash' => $token['hash'],
                    'expires_at' => InviteMember::expiresAt(),
                    'last_sent_at' => now(),
                    'send_count' => $envios + 1,
                    'updated_at' => now(),
                ]) === 1;

            if (! $trocou) {
                return false;
            }

            $invitation->refresh();

            $this->audit->record(AccountAuditEvent::InvitationResent, $account, $actor, $invitation, [
                'expires_at' => ['before' => $antes, 'after' => $invitation->expires_at->toIso8601String()],
                'send_count' => ['before' => $envios, 'after' => $invitation->send_count],
            ]);

            return true;
        });

        if (! $trocou) {
            $invitation->refresh();
            $this->reject(AccountAuditEvent::InvitationResent, $account, $actor, 'invitation', __('accounts.invitations.not_resendable_now', ['status' => $invitation->status()->label()]), $invitation);
        }

        InviteMember::send($account, $actor, $invitation, $token['plain']);

        return $invitation;
    }

    protected function audit(): AccountAudit
    {
        return $this->audit;
    }
}
