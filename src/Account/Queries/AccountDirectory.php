<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Queries;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Models\AccountInvitation;
use Twstec\Kit\Accounts\Account\Models\AccountMembership;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * As LEITURAS que as telas de conta fazem — num lugar só, para o painel
 * Livewire e o front React lerem a mesma coisa sem consultar model direto
 * (a trava de arquitetura do starter confere).
 *
 * - accountsOf(): as contas da pessoa, com o papel dela em cada (o seletor);
 * - members(): as pessoas da conta, com o papel no pivô — lido, com tipo,
 *   por roleOf() e memberSince();
 * - openInvitations(): os convites em aberto da conta ATUAL (pendentes e
 *   expirados — os que ainda podem ser reenviados ou revogados), pelo escopo;
 * - invitation(): um convite da conta ATUAL pelo uuid (outra conta = 404).
 */
final class AccountDirectory
{
    /**
     * @return Collection<int, AccountMembership>
     */
    public function accountsOf(AuthUser $user): Collection
    {
        return AccountMembership::query()
            ->with('account')
            ->where('user_id', $user->getKey())
            ->get()
            ->filter(fn (AccountMembership $m): bool => $m->account !== null)
            ->values();
    }

    /**
     * @return Collection<int, Model&AuthUser>
     */
    public function members(Account $account): Collection
    {
        /** @var Collection<int, Model&AuthUser> */
        return $account->members()->get();
    }

    /**
     * O papel de uma pessoa da lista de members() na conta (o vínculo vem no
     * pivô da consulta). Pessoa que não veio de members() não tem o vínculo:
     * é erro de quem chamou, e falha alto.
     */
    public function roleOf(AuthUser $member): AccountRole
    {
        return AccountRole::from((string) $this->membershipPivot($member)->getAttribute('role'));
    }

    /**
     * Desde quando a pessoa da lista de members() está na conta (null se o
     * vínculo não tem a data).
     */
    public function memberSince(AuthUser $member): ?Carbon
    {
        $joined = $this->membershipPivot($member)->getAttribute('created_at');

        return $joined === null ? null : Carbon::parse($joined);
    }

    /**
     * @return Collection<int, AccountInvitation>
     */
    public function openInvitations(): Collection
    {
        return AccountInvitation::query()
            ->with('creator')
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->whereNull('declined_at')
            ->latest('last_sent_at')
            ->get();
    }

    public function invitation(string $uuid): AccountInvitation
    {
        /** @var AccountInvitation */
        return AccountInvitation::query()->byUuid($uuid)->firstOrFail();
    }

    private function membershipPivot(AuthUser $member): Model
    {
        $pivot = $member->relationLoaded('pivot') ? $member->getRelation('pivot') : null;

        if (! $pivot instanceof Model || $pivot->getAttribute('role') === null) {
            throw new LogicException('A pessoa não veio de AccountDirectory::members(): sem o vínculo com a conta.');
        }

        return $pivot;
    }
}
