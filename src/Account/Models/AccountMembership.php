<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Exceptions\AccountOwnershipException;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Support\UserModel;

/**
 * Vínculo pessoa × conta, com o papel.
 *
 * A regra do dono vale AQUI no código (nos dois bancos) e, no PostgreSQL,
 * também nos gatilhos (Support\AccountDatabaseGuards):
 * - um vínculo nunca muda de conta;
 * - o dono não é rebaixado e ninguém vira dono por aqui (a propriedade muda
 *   por transferência, que troca a PESSOA do vínculo de dono);
 * - um segundo dono é recusado (e o índice único parcial também recusa);
 * - o vínculo do dono não é apagado enquanto a conta existe (a conta sai
 *   inteira — AccountService::deleteAccount — ou a propriedade é
 *   transferida antes).
 */
#[Fillable(['account_id', 'user_id', 'role'])]
class AccountMembership extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $membership): void {
            if ($membership->role === AccountRole::Owner
                && self::query()->where('account_id', $membership->account_id)->where('role', AccountRole::Owner->value)->exists()) {
                throw AccountOwnershipException::secondOwner();
            }
        });

        static::updating(function (self $membership): void {
            if ($membership->isDirty('account_id')) {
                throw AccountOwnershipException::membershipMovesAccount();
            }

            $antes = $membership->getOriginal('role');
            $antes = $antes instanceof AccountRole ? $antes : AccountRole::tryFrom((string) $antes);

            if ($membership->isDirty('role') && ($antes === AccountRole::Owner || $membership->role === AccountRole::Owner)) {
                throw AccountOwnershipException::ownerRoleChange();
            }
        });

        static::deleting(function (self $membership): void {
            if ($membership->role === AccountRole::Owner && Account::query()->whereKey($membership->account_id)->exists()) {
                throw AccountOwnershipException::ownerLeaves();
            }
        });

        // O papel guardado na requisição (CurrentAccount::roleFor) deixa de
        // valer na hora em que um vínculo muda.
        static::saved(fn () => app(CurrentAccount::class)->forgetRoles());
        static::deleted(fn () => app(CurrentAccount::class)->forgetRoles());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AccountRole::class,
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Model&AuthUser, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::name(), 'user_id');
    }
}
