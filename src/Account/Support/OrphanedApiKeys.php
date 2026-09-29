<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Mail\OrphanedApiKeysMail;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * CHAVES ÓRFÃS: as chaves de API que uma pessoa criou numa conta continuam
 * valendo depois que ela sai (são da conta — decisão do dono do kit), e quem
 * pode revisá-las é AVISADO por e-mail.
 *
 * - keysCreatedBy(): as chaves que a pessoa criou na conta ATUAL e que ainda
 *   autenticam (revogada, expirada ou rotacionada fora da graça não
 *   preocupa ninguém). Lidas ANTES de ela sair, pelo escopo da conta.
 * - forPersonExit() (AccountService::orphanedKeysOnPersonExit): na EXCLUSÃO
 *   da pessoa, por qualquer caminho, as chaves dela em cada conta de que era
 *   admin/member — lidas antes de ela sair do banco.
 * - notify(): um e-mail para o DONO e para cada ADMIN (quem gere chaves),
 *   menos quem saiu, no idioma de cada um. Só identificadores públicos da
 *   chave — nunca a secreta nem o hash.
 */
final class OrphanedApiKeys
{
    /**
     * @return list<array{name: string, code: string, public_key: string}>
     */
    public function keysCreatedBy(AuthUser $user): array
    {
        return self::summarize(ApiKey::query()->where('created_by', $user->getKey())->orderBy('id')->get());
    }

    /**
     * Só as chaves que ainda autenticam, e só os identificadores públicos.
     *
     * @param  iterable<ApiKey>  $keys
     * @return list<array{name: string, code: string, public_key: string}>
     */
    public static function summarize(iterable $keys): array
    {
        return collect($keys)
            ->filter(fn (ApiKey $key): bool => $key->isUsable())
            ->map(fn (ApiKey $key): array => [
                'name' => (string) $key->name,
                'code' => (string) $key->codigo_publico,
                'public_key' => (string) $key->public_key,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{name: string, code: string, public_key: string}>  $keys
     */
    public function notify(Account $account, AuthUser $departed, array $keys, bool $removed, bool $deleted = false): void
    {
        if ($keys === []) {
            return;
        }

        $destinatarios = $account->members()
            ->wherePivotIn('role', [AccountRole::Owner->value, AccountRole::Admin->value])
            ->where($account->members()->getRelated()->getQualifiedKeyName(), '!=', $departed->getKey())
            ->get();

        /** @var Model&AuthUser $pessoa */
        foreach ($destinatarios as $pessoa) {
            $locale = method_exists($pessoa, 'preferredLocale') ? $pessoa->preferredLocale() : app()->getLocale();

            // Só depois do commit: uma exclusão desfeita (a transação do
            // /admin, por exemplo) não avisa ninguém.
            Mail::to($pessoa)->locale($locale)->queue((new OrphanedApiKeysMail(
                accountName: $account->displayName(),
                accountUuid: (string) $account->uuid,
                departedName: (string) $departed->getAttribute('name'),
                removed: $removed,
                keys: $keys,
                deleted: $deleted,
            ))->afterCommit());
        }
    }
}
