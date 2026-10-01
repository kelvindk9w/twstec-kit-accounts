<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Deletion;

use Illuminate\Database\Eloquent\Collection;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * O que está para ser EXCLUÍDO — a pergunta que cada verificador
 * (Contracts\DeletionCheck) responde.
 *
 * - Exclusão de PESSOA: `person` é ela e `accounts` são as contas que saem
 *   junto (a pessoal e as de que ela é a única dona).
 * - Exclusão de CONTA: `person` é nulo e `accounts` é a conta.
 *
 * Um verificador que só se importa com "o registro que aponta para a conta"
 * olha `accountIds()`; um que se importa com a pessoa (um registro que aponta
 * para `users`) olha `person` — e vale também quando a pessoa sai.
 */
final class DeletionRequest
{
    /**
     * @param  Collection<int, Account>  $accounts
     */
    private function __construct(
        public readonly ?AuthUser $person,
        public readonly Collection $accounts,
    ) {}

    /**
     * @param  list<int>  $vanishingAccountIds  As contas que saem junto com a pessoa.
     */
    public static function forPerson(AuthUser $person, array $vanishingAccountIds): self
    {
        return new self($person, Account::query()->whereKey($vanishingAccountIds)->orderBy('id')->get());
    }

    public static function forAccount(Account $account): self
    {
        return new self(null, new Collection([$account]));
    }

    public function isPersonDeletion(): bool
    {
        return $this->person !== null;
    }

    /**
     * @return list<int>
     */
    public function accountIds(): array
    {
        return array_values(array_map('intval', $this->accounts->modelKeys()));
    }

    /**
     * @return list<string>
     */
    public function accountUuids(): array
    {
        return array_values($this->accounts->map(fn (Account $account): string => (string) $account->uuid)->all());
    }
}
