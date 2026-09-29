<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account\Invitations;

use Closure;
use Twstec\Kit\Accounts\Account\Models\AccountInvitation;
use Twstec\Kit\Accounts\Accounts;

/**
 * O TOKEN do link de convite: gerar, guardar só o hash, achar e consumir.
 *
 * - Gerado com 32 bytes do gerador criptográfico (64 caracteres hex) — só
 *   existe em claro no e-mail; no banco, só o SHA-256 (`token_hash`).
 * - O aceite chega por LINK, sem conta atual (e a pessoa ainda não é membro
 *   da conta do convite): achar o convite pelo hash e marcá-lo como usado
 *   (ou recusado) são as únicas operações de convite em MODO SISTEMA — um
 *   ponto só (system()), revisado na trava de arquitetura. Listar, reenviar
 *   e revogar acontecem na conta atual, com o escopo.
 * - USO ÚNICO: o consumo é um UPDATE condicional (só se o convite ainda está
 *   pendente) — dois aceites simultâneos não viram dois membros.
 */
final class InvitationTokens
{
    public const BYTES = 32;

    /**
     * @return array{plain: string, hash: string}
     */
    public static function generate(): array
    {
        $plain = bin2hex(random_bytes(self::BYTES));

        return ['plain' => $plain, 'hash' => self::hash($plain)];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Token com a forma certa (64 hex)? Outro formato nem vai ao banco.
     */
    public static function wellFormed(string $plain): bool
    {
        return preg_match('/\A[0-9a-f]{'.(self::BYTES * 2).'}\z/', $plain) === 1;
    }

    /**
     * O convite deste token (em qualquer estado), com a conta — ou nulo.
     */
    public static function find(string $plain): ?AccountInvitation
    {
        if (! self::wellFormed($plain)) {
            return null;
        }

        $hash = self::hash($plain);

        /** @var AccountInvitation|null */
        return self::system(fn (): ?AccountInvitation => AccountInvitation::query()
            ->with('account')
            ->where('token_hash', $hash)
            ->first());
    }

    /**
     * Marca o convite como ACEITO — só se ele ainda está pendente (UPDATE
     * condicional). Devolve se foi esta chamada que o consumiu.
     */
    public static function consume(AccountInvitation $invitation, mixed $acceptedBy): bool
    {
        return self::closeIfPending($invitation, ['accepted_at' => now(), 'accepted_by' => $acceptedBy]);
    }

    /**
     * Marca o convite como RECUSADO pela pessoa convidada — só se ainda está
     * pendente.
     */
    public static function decline(AccountInvitation $invitation): bool
    {
        return self::closeIfPending($invitation, ['declined_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function closeIfPending(AccountInvitation $invitation, array $values): bool
    {
        return self::system(fn (): bool => AccountInvitation::query()
            ->whereKey($invitation->getKey())
            ->pending()
            ->update([...$values, 'updated_at' => now()]) === 1);
    }

    /**
     * O único ponto de modo sistema dos convites: o link não diz de que conta
     * é o convite até ele ser achado — o mesmo motivo da busca da chave de API
     * na autenticação.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private static function system(Closure $callback): mixed
    {
        return Accounts::asSystem('accounts:invitation-token', $callback);
    }
}
