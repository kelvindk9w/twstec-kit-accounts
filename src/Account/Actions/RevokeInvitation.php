<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Account\Actions\Concerns\GuardsAccountAction;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Enums\InvitationStatus;
use Twstec\Kit\Accounts\Account\Models\AccountInvitation;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * REVOGA um convite da conta ATUAL (pendente ou expirado): o link deixa de
 * valer na hora — a tela de aceite passa a dizer "revogado".
 *
 * Quem pode: dono e admin. Convite de outra conta = 404.
 *
 * A revogação é um UPDATE condicional (só se o convite continua sem aceite,
 * recusa ou revogação — como o aceite, InvitationTokens::consume): se a
 * pessoa aceitou (ou recusou) no meio, nada muda e a recusa diz o estado
 * novo, em vez de anunciar uma revogação que não valeu.
 *
 * Trilha: `account.invitation_revoked`.
 */
final class RevokeInvitation
{
    use GuardsAccountAction;

    public function __construct(private readonly AccountAudit $audit) {}

    /**
     * PRÉ-CHECAGEM do papel, para a tela conferir ANTES de abrir a
     * confirmação ou gastar o código (quem não pode nem começa). Recusa →
     * 403 com a mesma mensagem de handle() e a linha `denied` na trilha,
     * como qualquer recusa desta Action. handle() confere de novo.
     *
     * @throws AuthorizationException
     */
    public function authorize(AuthUser $actor): void
    {
        $account = $this->currentAccount();
        $this->requireAbility(AccountAuditEvent::InvitationRevoked, $account, $actor, AccountAbility::ManageMembers);
    }

    public function handle(AuthUser $actor, string $invitationUuid): AccountInvitation
    {
        $account = $this->currentAccount();

        /** @var AccountInvitation $invitation */
        $invitation = AccountInvitation::query()->byUuid($invitationUuid)->firstOrFail();

        $this->requireAbility(AccountAuditEvent::InvitationRevoked, $account, $actor, AccountAbility::ManageMembers, $invitation);

        if (! in_array($invitation->status(), [InvitationStatus::Pending, InvitationStatus::Expired], true)) {
            $this->reject(AccountAuditEvent::InvitationRevoked, $account, $actor, 'invitation', __('accounts.invitations.not_revocable'), $invitation);
        }

        $antes = $invitation->status();

        $revogou = DB::transaction(function () use ($account, $actor, $invitation, $antes): bool {
            $revogou = AccountInvitation::query()
                ->whereKey($invitation->getKey())
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->whereNull('declined_at')
                ->update(['revoked_at' => now(), 'updated_at' => now()]) === 1;

            if (! $revogou) {
                return false;
            }

            $invitation->refresh();

            $this->audit->record(AccountAuditEvent::InvitationRevoked, $account, $actor, $invitation, [
                'status' => ['before' => $antes->value, 'after' => InvitationStatus::Revoked->value],
            ]);

            return true;
        });

        if (! $revogou) {
            $invitation->refresh();
            $this->reject(AccountAuditEvent::InvitationRevoked, $account, $actor, 'invitation', __('accounts.invitations.not_revocable_now', ['status' => $invitation->status()->label()]), $invitation);
        }

        return $invitation;
    }

    protected function audit(): AccountAudit
    {
        return $this->audit;
    }
}
