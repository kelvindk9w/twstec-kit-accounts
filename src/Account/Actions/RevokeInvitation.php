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

        DB::transaction(function () use ($account, $actor, $invitation): void {
            $invitation->forceFill(['revoked_at' => now()])->save();

            $this->audit->record(AccountAuditEvent::InvitationRevoked, $account, $actor, $invitation, [
                'status' => ['before' => InvitationStatus::Pending->value, 'after' => InvitationStatus::Revoked->value],
            ]);
        });

        return $invitation;
    }

    protected function audit(): AccountAudit
    {
        return $this->audit;
    }
}
