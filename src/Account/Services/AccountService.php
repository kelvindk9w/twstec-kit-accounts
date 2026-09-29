<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Events\AccountCreated;
use Twstec\Kit\Accounts\Account\Events\AccountDeleting;
use Twstec\Kit\Accounts\Account\Events\MemberAdded;
use Twstec\Kit\Accounts\Account\Events\MemberRemoved;
use Twstec\Kit\Accounts\Account\Exceptions\AccountOwnershipException;
use Twstec\Kit\Accounts\Account\Exceptions\OwnerOfSharedAccountException;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Models\AccountInvitation;
use Twstec\Kit\Accounts\Account\Models\AccountMembership;
use Twstec\Kit\Accounts\Account\Support\AccountDatabaseGuards;
use Twstec\Kit\Accounts\Account\Support\OrphanedApiKeys;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * Regra única das CONTAS e dos MEMBROS (sem telas).
 *
 * - Toda pessoa tem uma conta pessoal, criada junto com ela, com o MESMO uuid
 *   (AccountsServiceProvider liga isso ao evento de criação do model de
 *   usuário do aplicativo).
 * - Membros: entrar, sair e mudar de papel passam por aqui — o dono nunca é
 *   rebaixado nem sai; a propriedade muda por transferência
 *   (transferOwnership). QUEM pode fazer cada coisa é das Actions
 *   (Account\Actions), que também gravam a trilha de auditoria.
 * - Exclusão de pessoa: recusada enquanto ela for DONA de conta com outros
 *   membros; senão, as contas dela (a pessoal e as que só ela usa) saem junto,
 *   com os dados — o mesmo efeito da 1.x, quando projetos e chaves eram da
 *   pessoa.
 *
 * Contas e vínculos não são dados "de conta" (não têm o escopo da conta
 * atual): são a própria estrutura do tenant.
 */
final class AccountService
{
    /**
     * A conta pessoal da pessoa — criada se ainda não existe.
     */
    public function createPersonalAccount(AuthUser $user): Account
    {
        return DB::transaction(function () use ($user): Account {
            $existente = $this->personalAccountOf($user);

            if ($existente !== null) {
                return $existente;
            }

            /** @var Account $account */
            $account = Account::createWithPublicCodeRetry([
                'uuid' => (string) $user->getAttribute('uuid'),
                'name' => null,
                'personal_user_id' => $user->getKey(),
            ]);

            AccountMembership::query()->create([
                'account_id' => $account->getKey(),
                'user_id' => $user->getKey(),
                'role' => AccountRole::Owner,
            ]);

            AccountDatabaseGuards::checkOwnershipNow();

            AccountCreated::dispatch($account);

            return $account;
        });
    }

    /**
     * Uma conta de EMPRESA nova, com a pessoa como dona.
     */
    public function createAccount(string $name, AuthUser $owner): Account
    {
        return DB::transaction(function () use ($name, $owner): Account {
            /** @var Account $account */
            $account = Account::createWithPublicCodeRetry(['name' => $name]);

            AccountMembership::query()->create([
                'account_id' => $account->getKey(),
                'user_id' => $owner->getKey(),
                'role' => AccountRole::Owner,
            ]);

            AccountDatabaseGuards::checkOwnershipNow();

            AccountCreated::dispatch($account);

            return $account;
        });
    }

    public function personalAccountOf(AuthUser $user): ?Account
    {
        return Account::query()->where('personal_user_id', $user->getKey())->first();
    }

    /**
     * Contas de que a pessoa participa (a pessoal primeiro).
     *
     * @return Collection<int, Account>
     */
    public function accountsOf(AuthUser $user): Collection
    {
        return Account::query()
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $user->getKey()))
            ->orderByRaw('case when personal_user_id = ? then 0 else 1 end', [$user->getKey()])
            ->orderBy('id')
            ->get();
    }

    /**
     * A conta atual de uma pessoa na web: a selecionada na sessão, se ela
     * ainda é membro; senão a conta pessoal (criada se faltar — uma pessoa
     * gravada por fora do Eloquent, por exemplo).
     */
    public function resolveForPerson(AuthUser $user, ?string $selectedUuid): ?Account
    {
        if ($selectedUuid !== null) {
            $selecionada = Account::query()
                ->byUuid($selectedUuid)
                ->whereHas('memberships', fn ($query) => $query->where('user_id', $user->getKey()))
                ->first();

            if ($selecionada !== null) {
                return $selecionada;
            }
        }

        $pessoal = $this->personalAccountOf($user);

        if ($pessoal !== null) {
            return $pessoal;
        }

        if ($user->getAttribute('uuid') === null) {
            return null;
        }

        Log::warning('accounts.personal_account.recreated', ['user_uuid' => $user->getAttribute('uuid')]);

        return $this->createPersonalAccount($user);
    }

    /**
     * Põe a pessoa na conta como admin ou member (dono só por criação ou
     * transferência).
     */
    public function addMember(Account $account, AuthUser $user, AccountRole $role): AccountMembership
    {
        if ($role === AccountRole::Owner) {
            throw AccountOwnershipException::secondOwner();
        }

        if ($account->hasMember($user)) {
            throw new InvalidArgumentException(__('accounts.members.already_member'));
        }

        /** @var AccountMembership $membership */
        $membership = AccountMembership::query()->create([
            'account_id' => $account->getKey(),
            'user_id' => $user->getKey(),
            'role' => $role,
        ]);

        MemberAdded::dispatch($membership);

        return $membership;
    }

    /**
     * Tira a pessoa da conta. O dono não sai (transfira antes). As chaves que
     * ela criou continuam valendo: são da conta.
     */
    public function removeMember(Account $account, AuthUser $user): void
    {
        $membership = $account->memberships()->where('user_id', $user->getKey())->first();

        if ($membership === null) {
            return;
        }

        $membership->delete();

        MemberRemoved::dispatch($account, $user->getKey());
    }

    public function changeRole(Account $account, AuthUser $user, AccountRole $role): AccountMembership
    {
        /** @var AccountMembership $membership */
        $membership = $account->memberships()->where('user_id', $user->getKey())->firstOrFail();

        $membership->role = $role;
        $membership->save();

        return $membership;
    }

    /**
     * TRANSFERE A PROPRIEDADE: o vínculo de dono passa a ser da pessoa
     * `$to` (que já é membro) e quem era dono fica como admin — tudo numa
     * transação, sem nunca haver zero nem dois donos:
     *
     * 1. sai o vínculo atual de `$to` (admin ou member);
     * 2. o vínculo de DONO troca de pessoa (o papel não muda — é o que a
     *    regra do dono permite no código e nos gatilhos do PostgreSQL);
     * 3. quem era dono entra de novo, como admin.
     *
     * Quem pode transferir (o dono, com senha de transação e ação sensível)
     * é decidido antes, pela Action TransferOwnership.
     *
     * @throws AccountOwnershipException se `$from` não é o dono ou `$to` não é membro.
     */
    public function transferOwnership(Account $account, AuthUser $from, AuthUser $to): AccountMembership
    {
        return DB::transaction(function () use ($account, $from, $to): AccountMembership {
            /** @var AccountMembership|null $dono */
            $dono = $account->memberships()->where('role', AccountRole::Owner->value)->lockForUpdate()->first();

            /** @var AccountMembership|null $alvo */
            $alvo = $account->memberships()->where('user_id', $to->getKey())->lockForUpdate()->first();

            if ($dono === null || (string) $dono->user_id !== (string) $from->getKey() || $alvo === null || $alvo->getKey() === $dono->getKey()) {
                throw AccountOwnershipException::invalidTransfer();
            }

            $alvo->delete();

            $dono->user_id = $to->getKey();
            $dono->save();

            /** @var AccountMembership $antigo */
            $antigo = AccountMembership::query()->create([
                'account_id' => $account->getKey(),
                'user_id' => $from->getKey(),
                'role' => AccountRole::Admin,
            ]);

            return $antigo;
        });
    }

    /**
     * Contas de que a pessoa é DONA e que têm outros membros — as que
     * impedem a exclusão dela.
     *
     * @return Collection<int, Account>
     */
    public function sharedAccountsOwnedBy(AuthUser $user): Collection
    {
        return Account::query()
            ->whereHas('memberships', fn ($query) => $query
                ->where('user_id', $user->getKey())
                ->where('role', AccountRole::Owner->value))
            ->whereHas('memberships', fn ($query) => $query->where('user_id', '!=', $user->getKey()))
            ->orderBy('id')
            ->get();
    }

    /**
     * Motivo traduzido pelo qual a pessoa não pode ser excluída (nulo quando
     * pode) — para as telas avisarem antes.
     */
    public function deletionDenial(AuthUser $user): ?string
    {
        $compartilhadas = $this->sharedAccountsOwnedBy($user);

        if ($compartilhadas->isEmpty()) {
            return null;
        }

        return OwnerOfSharedAccountException::messageFor($compartilhadas->pluck('codigo_publico')->all());
    }

    /**
     * Recusa a exclusão da pessoa que é dona de conta com outros membros.
     *
     * @throws OwnerOfSharedAccountException
     */
    public function ensurePersonCanBeDeleted(AuthUser $user): void
    {
        $compartilhadas = $this->sharedAccountsOwnedBy($user);

        if ($compartilhadas->isNotEmpty()) {
            throw new OwnerOfSharedAccountException($compartilhadas->pluck('codigo_publico')->all());
        }
    }

    /**
     * Ids das contas de que a pessoa é dona (antes de ela sair).
     *
     * @return list<int>
     */
    public function ownedAccountIds(AuthUser $user): array
    {
        return AccountMembership::query()
            ->where('user_id', $user->getKey())
            ->where('role', AccountRole::Owner->value)
            ->pluck('account_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * As chaves ÓRFÃS que a exclusão desta pessoa vai deixar: em cada conta
     * de que ela é admin ou member (nas que é dona e que saem junto, não há
     * órfã), as chaves que ela criou e que ainda autenticam. Lido ANTES de a
     * pessoa sair do banco (depois, o `created_by` já é nulo).
     *
     * Modo sistema: as contas não são a atual de ninguém nesta hora.
     *
     * @return list<array{account: Account, keys: list<array{name: string, code: string, public_key: string}>}>
     */
    public function orphanedKeysOnPersonExit(AuthUser $user): array
    {
        return Accounts::asSystem('accounts:person-leaving', function () use ($user): array {
            $contas = AccountMembership::query()
                ->where('user_id', $user->getKey())
                ->where('role', '!=', AccountRole::Owner->value)
                ->pluck('account_id');

            $orfas = [];

            foreach (Account::query()->whereKey($contas)->orderBy('id')->get() as $account) {
                $keys = OrphanedApiKeys::summarize(ApiKey::query()
                    ->where('account_id', $account->getKey())
                    ->where('created_by', $user->getKey())
                    ->orderBy('id')
                    ->get());

                if ($keys !== []) {
                    $orfas[] = ['account' => $account, 'keys' => $keys];
                }
            }

            return $orfas;
        });
    }

    /**
     * Depois que a pessoa saiu do banco: o que ela deixou nas contas é
     * arrumado aqui, sem depender de o banco ter as chaves estrangeiras
     * ligadas (no PostgreSQL os gatilhos e as cascatas já fizeram o mesmo na
     * própria sentença; aqui nada sobra em nenhum banco):
     *
     * - as contas de que era dona e que ficaram sem dono saem com os dados;
     * - os vínculos que sobraram (admin/member) saem;
     * - `created_by` dos dados que criou fica vazio — os dados são da conta.
     *
     * @param  list<int>  $ownedAccountIds  Contas de que a pessoa era dona.
     */
    public function cleanUpAfterPersonDeleted(mixed $userId, array $ownedAccountIds): void
    {
        Accounts::asSystem('accounts:person-deleted', function () use ($userId, $ownedAccountIds): void {
            DB::transaction(function () use ($userId, $ownedAccountIds): void {
                AccountMembership::query()->where('user_id', $userId)->where('role', '!=', AccountRole::Owner->value)->delete();

                Account::query()
                    ->whereKey($ownedAccountIds)
                    ->whereDoesntHave('memberships', fn ($query) => $query
                        ->where('role', AccountRole::Owner->value)
                        ->where('user_id', '!=', $userId))
                    ->get()
                    ->each(fn (Account $account) => $this->deleteAccount($account));

                Project::query()->where('created_by', $userId)->update(['created_by' => null]);
                ApiKey::query()->where('created_by', $userId)->update(['created_by' => null]);
            });
        });

        // Vínculos saíram em massa (sem evento de model): papel guardado não vale mais.
        app(CurrentAccount::class)->forgetRoles();
    }

    /**
     * Exclui a conta com os dados dela (projetos, chaves, vínculos). Em modo
     * sistema declarado; quem pode excluir (o dono) é decidido antes.
     *
     * Antes de qualquer linha sair, dentro da mesma transação, avisa quem
     * guarda dado da conta fora deste pacote (Events\AccountDeleting — os
     * uploads, por exemplo): o que o ouvinte apagar volta junto se a
     * exclusão for desfeita.
     */
    public function deleteAccount(Account $account): void
    {
        Accounts::asSystem('accounts:delete-account', function () use ($account): void {
            DB::transaction(function () use ($account): void {
                AccountDeleting::dispatch($account);

                ApiKey::query()->where('account_id', $account->getKey())->get()
                    ->each(fn (ApiKey $key) => $key->projects()->detach());

                ApiKey::query()->where('account_id', $account->getKey())->update(['rotated_from_id' => null, 'rotated_to_id' => null]);
                ApiKey::query()->where('account_id', $account->getKey())->delete();
                Project::query()->where('account_id', $account->getKey())->delete();
                AccountInvitation::query()->where('account_id', $account->getKey())->delete();

                // A conta sai antes dos vínculos: no PostgreSQL, a regra do
                // dono só deixa o vínculo do dono sair quando a conta já saiu.
                $account->delete();

                AccountMembership::query()->where('account_id', $account->getKey())->delete();
            });
        });

        app(CurrentAccount::class)->forgetRoles();
    }
}
