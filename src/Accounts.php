<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Exceptions\MissingAccountContextException;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * A porta de entrada das contas: a conta atual, o modo sistema e a
 * autorização por papel.
 *
 *     Accounts::current();                               // a conta atual (ou null)
 *     Accounts::asSystem('motivo', fn () => …);          // todas as contas, com motivo
 *     Accounts::actingAs($conta, fn () => …);            // uma conta, explícita
 *     Accounts::authorize(AccountAbility::ManageApiKeys); // 403 se o papel não permite
 *     Accounts::switchTo($conta);                        // seleciona na sessão (web)
 *
 * O MODO SISTEMA é a única forma de ler dado de mais de uma conta. Ele é
 * explícito (sempre com motivo) e auditável: uma trava de arquitetura no
 * starter lista cada chamada fora deste pacote e reprova a que não foi
 * revisada. Quem usa hoje: o /admin (todas as requisições do painel), os
 * comandos e seeders que varrem contas e a autenticação da chave de API
 * (que ainda não sabe de que conta é a chave).
 */
final class Accounts
{
    public static function current(): ?Account
    {
        return self::context()->account();
    }

    /**
     * @throws MissingAccountContextException
     */
    public static function currentOrFail(): Account
    {
        return self::current() ?? throw MissingAccountContextException::forModel(Account::class);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function asSystem(string $reason, Closure $callback): mixed
    {
        return self::context()->runAsSystem($reason, $callback);
    }

    /**
     * Roda o callback com a conta dada como atual (e, opcionalmente, quem está
     * agindo — o `created_by` do que for criado).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function actingAs(Account $account, Closure $callback, ?AuthUser $actor = null): mixed
    {
        return self::context()->runAs($account, $callback, $actor);
    }

    /**
     * Modo sistema até o FIM DA REQUISIÇÃO HTTP corrente — para middleware de
     * uma área inteira que opera em modo sistema (o /admin), inclusive nas
     * ações do Livewire (que reaplica os middlewares persistentes antes do
     * componente, num pipeline à parte). Desfeito sozinho no fim de cada
     * requisição. Fora de um middleware, use asSystem() com callback.
     */
    public static function systemModeForRequest(string $reason): void
    {
        self::context()->enterSystemForRequest($reason);
    }

    public static function inSystemMode(): bool
    {
        return self::context()->isSystem();
    }

    public static function systemReason(): ?string
    {
        return self::context()->systemReason();
    }

    /**
     * Papel da pessoa na conta (padrão: a pessoa logada, na conta atual).
     */
    public static function roleOf(?AuthUser $user = null, ?Account $account = null): ?AccountRole
    {
        $user ??= self::context()->actor();
        $account ??= self::current();

        if ($user === null || $account === null) {
            return null;
        }

        // Uma consulta por conta + pessoa por requisição (CurrentAccount::roleFor).
        return self::context()->roleFor($account, $user);
    }

    /**
     * O papel da pessoa (padrão: a logada) na conta atual permite a ação?
     */
    public static function can(AccountAbility $ability, ?AuthUser $user = null): bool
    {
        return self::roleOf($user)?->allows($ability) ?? false;
    }

    /**
     * Conferência de papel SEM trilha — para código que decide sozinho o que
     * fazer com a recusa. Nas TELAS, a recusa fica na trilha: nas de chaves de
     * API e de projetos, use o Account\Support\AccountResourceGuard (o mesmo
     * 403 e a mesma mensagem, com a linha `denied`); na pré-checagem de uma
     * ação de conta, membros ou convites (transferir, excluir, renomear,
     * remover, revogar), o `authorize()` da própria Action.
     *
     * @throws AuthorizationException 403 quando o papel não permite.
     */
    public static function authorize(AccountAbility $ability, ?AuthUser $user = null): void
    {
        if (! self::can($ability, $user)) {
            throw new AuthorizationException(__('accounts.authorization.denied'));
        }
    }

    /**
     * Seleciona, na sessão web, a conta atual da pessoa logada. Só conta de
     * que ela é membro; a seleção é conferida de novo a cada requisição.
     */
    public static function switchTo(Account $account): void
    {
        $user = Auth::guard(CurrentAccount::webGuard())->user();

        if (! $user instanceof AuthUser || ! $account->hasMember($user)) {
            throw new AuthorizationException(__('accounts.authorization.not_member'));
        }

        session()->put(CurrentAccount::sessionKey(), (string) $account->uuid);
    }

    /**
     * Desfaz a seleção de conta na sessão web (a pessoa volta para a conta
     * pessoal) — depois de sair de uma conta ou de excluí-la.
     */
    public static function clearSelection(): void
    {
        if (app()->bound('session')) {
            session()->forget(CurrentAccount::sessionKey());
        }
    }

    private static function context(): CurrentAccount
    {
        return app(CurrentAccount::class);
    }
}
