<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Enums;

/**
 * Os eventos de CONTA gravados na trilha de auditoria (`audit_events.action`).
 *
 * Toda ação de conta feita pelas Actions do pacote grava uma linha, na mesma
 * transação da mudança (falha fechada: sem a linha, a mudança é desfeita) —
 * e toda RECUSA grava a mesma ação com `outcome = denied` e o motivo.
 */
enum AccountAuditEvent: string
{
    case Created = 'account.created';
    case Renamed = 'account.renamed';
    case Deleted = 'account.deleted';
    case Switched = 'account.switched';

    case InvitationCreated = 'account.invitation_created';
    case InvitationResent = 'account.invitation_resent';
    case InvitationRevoked = 'account.invitation_revoked';
    case InvitationAccepted = 'account.invitation_accepted';
    case InvitationDeclined = 'account.invitation_declined';

    case MemberRoleChanged = 'account.member_role_changed';
    case MemberRemoved = 'account.member_removed';
    case MemberLeft = 'account.member_left';

    case OwnershipTransferred = 'account.ownership_transferred';
}
