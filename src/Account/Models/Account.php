<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Support\UserModel;
use Twstec\Kit\Foundation\Identifiers\HasPublicCode;
use Twstec\Kit\Foundation\Identifiers\RoutesByUuid;

/**
 * Conta — a dona dos dados (projetos, chaves de API) e o TENANT que a chave
 * de API autentica. Uma empresa é uma conta; toda pessoa tem também a sua
 * CONTA PESSOAL (criada junto com ela, com o MESMO uuid).
 *
 * Pessoas entram na conta por AccountMembership, com um papel fixo (owner,
 * admin, member) — exatamente um owner por conta (o banco garante; ver
 * Support\AccountDatabaseGuards e o índice único parcial da migration).
 *
 * Este model NÃO tem o escopo da conta atual: ele É o tenant. Quem lista as
 * contas de uma pessoa passa pelo AccountService.
 *
 * Identificadores (3 camadas — anti-enumeração): `id` nunca exposto; `uuid`
 * externo; `codigo_publico` legível ACC-xxxxxx.
 */
#[Fillable(['uuid', 'name', 'personal_user_id'])]
class Account extends Model
{
    use HasPublicCode, HasUuids, RoutesByUuid;

    protected const PUBLIC_CODE_PREFIX = 'ACC';

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return HasMany<AccountMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(AccountMembership::class);
    }

    /**
     * Pessoas da conta, com o papel no pivô (só LEITURA: vínculos mudam pelo
     * AccountService, que aplica a regra do dono).
     *
     * @return BelongsToMany<Model&AuthUser, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(UserModel::name(), 'account_memberships')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * O dono (exatamente um).
     *
     * @return HasOneThrough<Model&AuthUser, AccountMembership, $this>
     */
    public function owner(): HasOneThrough
    {
        return $this->hasOneThrough(UserModel::name(), AccountMembership::class, 'account_id', 'id', 'id', 'user_id')
            ->where('account_memberships.role', AccountRole::Owner->value);
    }

    /**
     * A pessoa desta conta pessoal (nulo numa conta de empresa).
     *
     * @return BelongsTo<Model&AuthUser, $this>
     */
    public function personalUser(): BelongsTo
    {
        return $this->belongsTo(UserModel::name(), 'personal_user_id');
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function isPersonal(): bool
    {
        return $this->personal_user_id !== null;
    }

    /**
     * Papel da pessoa nesta conta (nulo se ela não é membro).
     */
    public function roleOf(AuthUser $user): ?AccountRole
    {
        $role = $this->memberships()->where('user_id', $user->getKey())->value('role');

        return match (true) {
            $role instanceof AccountRole => $role,
            is_string($role) => AccountRole::from($role),
            default => null,
        };
    }

    public function hasMember(AuthUser $user): bool
    {
        return $this->roleOf($user) !== null;
    }

    /**
     * Nome para exibição: o da conta ou, na conta pessoal, o da pessoa (que é
     * dado pessoal cifrado e não é copiado para a conta).
     */
    public function displayName(): string
    {
        if (is_string($this->name) && $this->name !== '') {
            return $this->name;
        }

        $pessoa = $this->personalUser ?? $this->owner;

        return (string) ($pessoa?->getAttribute('name') ?? $this->codigo_publico);
    }
}
