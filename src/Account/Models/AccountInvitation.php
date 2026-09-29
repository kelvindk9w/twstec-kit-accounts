<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Twstec\Kit\Accounts\Account\Concerns\BelongsToAccount;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Enums\InvitationStatus;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Auth\Support\UserModel;
use Twstec\Kit\Foundation\Identifiers\RoutesByUuid;

/**
 * Convite para entrar numa conta, como admin ou member.
 *
 * É dado DA CONTA (BelongsToAccount): a lista de convites pendentes sai
 * filtrada pela conta atual, como projetos e chaves. `created_by` é quem
 * convidou.
 *
 * O token do link NUNCA é gravado — só o hash (`token_hash`, escondido na
 * serialização). A busca pelo token (o aceite, que chega por link, sem conta
 * atual) é a única leitura em modo sistema: Invitations\InvitationTokens.
 *
 * @property string $uuid
 * @property string $email
 * @property AccountRole $role
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $last_sent_at
 * @property int $send_count
 * @property CarbonInterface|null $accepted_at
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $declined_at
 */
#[Fillable(['account_id', 'email', 'role', 'token_hash', 'created_by', 'expires_at', 'last_sent_at', 'send_count'])]
class AccountInvitation extends Model
{
    use BelongsToAccount, HasUuids, RoutesByUuid;

    /**
     * @var list<string>
     */
    protected $hidden = ['token_hash'];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AccountRole::class,
            'expires_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'declined_at' => 'datetime',
            'send_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Model&AuthUser, $this>
     */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::name(), 'accepted_by');
    }

    public function status(): InvitationStatus
    {
        return match (true) {
            $this->accepted_at !== null => InvitationStatus::Accepted,
            $this->revoked_at !== null => InvitationStatus::Revoked,
            $this->declined_at !== null => InvitationStatus::Declined,
            $this->expires_at->isPast() => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    public function isPending(): bool
    {
        return $this->status() === InvitationStatus::Pending;
    }

    /**
     * Convites ainda válidos (sem aceite, revogação ou recusa, e no prazo).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->whereNull('declined_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Mesmo e-mail, sem diferença de caixa nem espaço nas pontas (a
     * comparação da tela de aceite e a de "já existe convite").
     */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function isFor(AuthUser $user): bool
    {
        return self::normalizeEmail((string) $user->getAttribute('email')) === self::normalizeEmail($this->email);
    }
}
