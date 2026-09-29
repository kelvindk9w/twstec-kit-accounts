<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Actions;

use Illuminate\Support\Facades\DB;
use Twstec\Kit\Accounts\Account\Enums\AccountAuditEvent;
use Twstec\Kit\Accounts\Account\Enums\InvitationStatus;
use Twstec\Kit\Accounts\Account\Exceptions\InvitationUnavailableException;
use Twstec\Kit\Accounts\Account\Invitations\InvitationTokens;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Models\AccountInvitation;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Account\Support\AccountAudit;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * ACEITE do convite por quem está LOGADO.
 *
 * - Só a pessoa com o MESMO e-mail do convite (sem diferença de caixa):
 *   outra pessoa logada recebe `wrong_email` — sem nenhum dado da conta.
 * - Só convite PENDENTE: expirado, revogado, recusado ou já usado recebem o
 *   motivo. Uso ÚNICO: o consumo é condicional (dois aceites ao mesmo tempo
 *   não viram dois membros).
 * - Quem já é membro da conta recebe `already_member` (o convite continua
 *   como está).
 * - O link chegou naquele e-mail: se a conta da pessoa ainda não tinha o
 *   e-mail confirmado, passa a ter.
 *
 * Cada recusa grava `account.invitation_accepted` com `denied` e o motivo;
 * o aceite grava a mesma ação com sucesso, na transação que cria o vínculo.
 * Selecionar a conta na sessão é do front (a resposta padrão seleciona).
 */
final class AcceptInvitation
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly AccountAudit $audit,
    ) {}

    /**
     * @throws InvitationUnavailableException
     */
    public function handle(AuthUser $user, #[\SensitiveParameter] string $token): Account
    {
        $invitation = $this->pendingFor($token, $user);
        $account = $invitation->account;

        $aceito = DB::transaction(function () use ($invitation, $account, $user): bool {
            if (! InvitationTokens::consume($invitation, $user->getKey())) {
                return false;
            }

            $membership = $this->accounts->addMember($account, $user, $invitation->role);

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            $this->audit->record(AccountAuditEvent::InvitationAccepted, $account, $user, $invitation, [
                'member' => ['before' => null, 'after' => (string) $user->getAttribute('uuid')],
                'role' => ['before' => null, 'after' => $membership->role->value],
            ]);

            return true;
        });

        // Outro pedido consumiu o convite entre a leitura e o UPDATE: a
        // recusa é gravada FORA da transação (que não mudou nada).
        if (! $aceito) {
            throw $this->refuse(InvitationUnavailableException::forStatus($invitation->refresh()->status(), $invitation), $user);
        }

        return $account;
    }

    /**
     * O convite do token, pendente e para esta pessoa — ou a recusa (já
     * registrada).
     *
     * @throws InvitationUnavailableException
     */
    private function pendingFor(string $token, AuthUser $user): AccountInvitation
    {
        $invitation = InvitationTokens::find($token);

        if ($invitation === null) {
            throw $this->refuse(new InvitationUnavailableException(InvitationUnavailableException::NOT_FOUND), $user);
        }

        if (! $invitation->isFor($user)) {
            throw $this->refuse(new InvitationUnavailableException(InvitationUnavailableException::WRONG_EMAIL, $invitation), $user);
        }

        $status = $invitation->status();

        if ($status !== InvitationStatus::Pending) {
            throw $this->refuse(InvitationUnavailableException::forStatus($status, $invitation), $user);
        }

        if ($invitation->account->hasMember($user)) {
            throw $this->refuse(new InvitationUnavailableException(InvitationUnavailableException::ALREADY_MEMBER, $invitation), $user);
        }

        return $invitation;
    }

    private function refuse(InvitationUnavailableException $exception, ?AuthUser $user): InvitationUnavailableException
    {
        return self::recordRefusal($this->audit, AccountAuditEvent::InvitationAccepted, $exception, $user);
    }

    /**
     * Grava a recusa (`denied`, com o motivo) e devolve a exceção para lançar.
     */
    public static function recordRefusal(AccountAudit $audit, AccountAuditEvent $event, InvitationUnavailableException $exception, ?AuthUser $user): InvitationUnavailableException
    {
        $invitation = $exception->invitation;

        $audit->denied(
            $event,
            $invitation?->account,
            $user,
            $exception->reason.': '.$exception->getMessage(),
            $invitation,
            subjectType: 'account_invitation',
        );

        return $exception;
    }
}
