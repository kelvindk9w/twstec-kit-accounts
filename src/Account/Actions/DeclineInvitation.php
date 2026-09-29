<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Enums\InvitationStatus;
use Twstec\Kit\Accounts\Account\Exceptions\InvitationUnavailableException;
use Twstec\Kit\Accounts\Account\Invitations\InvitationTokens;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * A pessoa convidada RECUSA o convite (quem tem o link; logada, só com o
 * mesmo e-mail do convite). O link deixa de valer.
 *
 * Trilha: `account.invitation_declined` (recusas do próprio pedido:
 * `denied`, com o motivo).
 */
final class DeclineInvitation
{
    public function __construct(private readonly AccountAudit $audit) {}

    /**
     * @throws InvitationUnavailableException
     */
    public function handle(?AuthUser $user, #[\SensitiveParameter] string $token): void
    {
        $invitation = InvitationTokens::find($token);

        if ($invitation === null) {
            throw $this->refuse(new InvitationUnavailableException(InvitationUnavailableException::NOT_FOUND), $user);
        }

        if ($user !== null && ! $invitation->isFor($user)) {
            throw $this->refuse(new InvitationUnavailableException(InvitationUnavailableException::WRONG_EMAIL, $invitation), $user);
        }

        $status = $invitation->status();

        if ($status !== InvitationStatus::Pending) {
            throw $this->refuse(InvitationUnavailableException::forStatus($status, $invitation), $user);
        }

        $recusado = DB::transaction(function () use ($invitation, $user): bool {
            if (! InvitationTokens::decline($invitation)) {
                return false;
            }

            $this->audit->record(AccountAuditEvent::InvitationDeclined, $invitation->account, $user, $invitation, [
                'status' => ['before' => InvitationStatus::Pending->value, 'after' => InvitationStatus::Declined->value],
            ]);

            return true;
        });

        if (! $recusado) {
            throw $this->refuse(InvitationUnavailableException::forStatus($invitation->refresh()->status(), $invitation), $user);
        }
    }

    private function refuse(InvitationUnavailableException $exception, ?AuthUser $user): InvitationUnavailableException
    {
        return AcceptInvitation::recordRefusal($this->audit, AccountAuditEvent::InvitationDeclined, $exception, $user);
    }
}
