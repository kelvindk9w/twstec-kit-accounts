<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Account;

use Closure;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Throwable;
use Twstec\Kit\Accounts\Account\Enums\AccountRole;
use Twstec\Kit\Accounts\Account\Exceptions\MissingAccountContextException;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Auth\Contracts\AuthUser;

/**
 * A CONTA ATUAL — o contexto que o escopo das contas (Scopes\AccountScope)
 * usa em toda consulta de dado de conta.
 *
 * De onde ela vem, em ordem:
 *
 * 1. De um QUADRO explícito, empilhado por quem sabe a conta:
 *    - a API: a conta da chave (Tenancy\Middleware\ResolveTenant);
 *    - `Accounts::actingAs($conta, fn () => …)`: um job restaurado, um teste;
 *    - `Accounts::asSystem('motivo', fn () => …)`: o MODO SISTEMA — sem filtro,
 *      todas as contas (o /admin, comandos, seeders, a autenticação da chave);
 *    - um quadro "sem conta" (o job que foi enfileirado sem conta): nada de
 *      cair na sessão de ninguém.
 * 2. Sem quadro, do MODO SISTEMA DA REQUISIÇÃO, quando declarado por um
 *    middleware (`Accounts::systemModeForRequest('motivo')` — o /admin): vale
 *    até o fim da requisição HTTP. Existe porque o Livewire reaplica os
 *    middlewares "persistentes" num pipeline à parte, ANTES de rodar o
 *    componente: um middleware que só envolvesse o `$next` com o modo sistema
 *    o desfaria antes de o componente rodar.
 * 3. Sem nada disso, da pessoa logada no guard web: a conta selecionada na
 *    sessão (se ela ainda é membro), senão a conta pessoal dela.
 * 4. Nada disso: não há conta — e o escopo LANÇA exceção em vez de devolver
 *    tudo (Exceptions\MissingAccountContextException).
 *
 * Singleton. O fim de CADA requisição HTTP (evento RequestHandled) desfaz o
 * modo sistema da requisição, o cache da sessão e o cache dos papéis; os
 * quadros são sempre desempilhados por quem os empilhou (seguro para
 * processos longos, como Octane e os testes).
 *
 * CACHE DO PAPEL (roleFor): a tela pergunta o papel várias vezes por
 * requisição (cada botão que ela esconde ou mostra); a resposta fica guardada
 * por conta + pessoa até o fim da requisição (ou do job, na fila), e é
 * esquecida na hora em que um vínculo de membro muda (criado, alterado,
 * apagado — inclusive em massa pelo AccountService). Papel velho nunca vale
 * de uma requisição para outra.
 */
final class CurrentAccount
{
    public const FRAME_ACCOUNT = 'account';

    public const FRAME_SYSTEM = 'system';

    public const FRAME_NONE = 'none';

    /**
     * @var list<array{type: string, account?: Account, actor?: AuthUser|null, reason?: string}>
     */
    private array $frames = [];

    /**
     * Cache da resolução pela sessão: [id da pessoa, conta selecionada, conta].
     *
     * @var array{0: mixed, 1: string|null, 2: Account|null}|null
     */
    private ?array $webCache = null;

    /**
     * Motivo do modo sistema declarado para a requisição HTTP corrente.
     */
    private ?string $requestSystemReason = null;

    /**
     * Papéis já consultados nesta requisição: "conta:pessoa" => papel (ou
     * null, quando não é membro).
     *
     * @var array<string, AccountRole|null>
     */
    private array $roles = [];

    /**
     * A conta atual, ou nulo (modo sistema, quadro sem conta ou ninguém logado).
     */
    public function account(): ?Account
    {
        $frame = $this->top();

        if ($frame !== null) {
            return $frame['type'] === self::FRAME_ACCOUNT ? $frame['account'] : null;
        }

        if ($this->requestSystemReason !== null) {
            return null;
        }

        return $this->resolveFromWeb();
    }

    /**
     * Id da conta atual para filtrar/gravar dado do model informado — ou a
     * exceção do "esquecimento".
     *
     * @param  class-string  $model
     *
     * @throws MissingAccountContextException
     */
    public function requireIdFor(string $model): int
    {
        $account = $this->account();

        if ($account === null) {
            throw MissingAccountContextException::forModel($model);
        }

        return (int) $account->getKey();
    }

    public function isSystem(): bool
    {
        $frame = $this->top();

        if ($frame !== null) {
            return $frame['type'] === self::FRAME_SYSTEM;
        }

        return $this->requestSystemReason !== null;
    }

    /**
     * O motivo declarado do modo sistema em vigor (nulo fora dele).
     */
    public function systemReason(): ?string
    {
        if (! $this->isSystem()) {
            return null;
        }

        return $this->top()['reason'] ?? $this->requestSystemReason;
    }

    /**
     * Modo sistema até o fim da requisição HTTP corrente (ver o item 2 do
     * docblock da classe). Quadros empilhados depois continuam valendo por
     * cima dele.
     */
    public function enterSystemForRequest(string $reason): void
    {
        $this->requestSystemReason = self::systemFrame($reason)['reason'];
    }

    /**
     * Fim da requisição HTTP: desfaz o modo sistema da requisição e o cache
     * da sessão (os quadros já foram desempilhados por quem os empilhou).
     */
    public function endRequest(): void
    {
        $this->requestSystemReason = null;
        $this->webCache = null;
        $this->forgetRoles();
    }

    /**
     * O papel da pessoa na conta — consultado no banco uma vez por
     * requisição (ver o docblock da classe).
     */
    public function roleFor(Account $account, AuthUser $user): ?AccountRole
    {
        $chave = $account->getKey().':'.$user->getKey();

        if (! array_key_exists($chave, $this->roles)) {
            $this->roles[$chave] = $account->roleOf($user);
        }

        return $this->roles[$chave];
    }

    /**
     * Esquece os papéis guardados (um vínculo mudou, a requisição ou o job
     * acabou).
     */
    public function forgetRoles(): void
    {
        $this->roles = [];
    }

    /**
     * Quem está agindo (para `created_by`): a pessoa declarada no quadro (na
     * API, a pessoa por trás da chave) ou a pessoa logada no guard web.
     */
    public function actor(): ?AuthUser
    {
        $frame = $this->top();

        if ($frame !== null && ($frame['actor'] ?? null) instanceof AuthUser) {
            return $frame['actor'];
        }

        if ($frame !== null && $frame['type'] === self::FRAME_NONE) {
            return null;
        }

        return $this->webUser();
    }

    /**
     * Roda o callback com a conta dada como atual.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAs(Account $account, Closure $callback, ?AuthUser $actor = null): mixed
    {
        return $this->runWith(['type' => self::FRAME_ACCOUNT, 'account' => $account, 'actor' => $actor], $callback);
    }

    /**
     * Roda o callback em MODO SISTEMA (sem filtro de conta). O motivo é
     * obrigatório: é o que aparece em Accounts::systemReason() e o que a trava
     * de arquitetura confere a cada chamada.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runAsSystem(string $reason, Closure $callback): mixed
    {
        return $this->runWith(self::systemFrame($reason), $callback);
    }

    /**
     * @template T
     *
     * @param  array{type: string, account?: Account, actor?: AuthUser|null, reason?: string}  $frame
     * @param  Closure(): T  $callback
     * @return T
     */
    public function runWith(array $frame, Closure $callback): mixed
    {
        $depth = $this->push($frame);

        try {
            $result = $callback();

            // `fn () => dispatch(new Job)` devolve o PendingDispatch, que só
            // enfileira quando é destruído — fora do contexto, se saísse
            // daqui. Destruído AQUI, o job leva a conta certa.
            if ($result instanceof PendingDispatch) {
                $result = null;
            }

            return $result;
        } finally {
            $this->popTo($depth);
        }
    }

    /**
     * Empilha um quadro e devolve a profundidade anterior (para popTo). Para
     * quem não pode usar callback (um job entre dois eventos da fila).
     *
     * @param  array{type: string, account?: Account, actor?: AuthUser|null, reason?: string}  $frame
     */
    public function push(array $frame): int
    {
        $depth = count($this->frames);

        $this->frames[] = $frame;

        return $depth;
    }

    public function popTo(int $depth): void
    {
        $this->frames = array_slice($this->frames, 0, max(0, $depth));
    }

    /**
     * Zera tudo (fim de requisição, processo longo).
     */
    public function reset(): void
    {
        $this->frames = [];
        $this->endRequest();
    }

    /**
     * O contexto em forma serializável — vai no payload dos jobs.
     *
     * @return array{account: int|null, system: string|null, actor: mixed}
     */
    public function snapshot(): array
    {
        try {
            $system = $this->systemReason();
            $account = $system === null ? $this->account() : null;
            $actor = $this->actor();
        } catch (Throwable) {
            // Enfileirar nunca falha por causa do contexto: sem conta, o job
            // roda sem conta (e falha visível se tocar em dado de conta).
            return ['account' => null, 'system' => null, 'actor' => null];
        }

        return [
            'account' => $account !== null ? (int) $account->getKey() : null,
            'system' => $system,
            'actor' => $actor?->getKey(),
        ];
    }

    /**
     * @return array{type: string, reason: string}
     */
    public static function systemFrame(string $reason): array
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('O modo sistema exige um motivo (Accounts::asSystem(\'motivo\', …)).');
        }

        return ['type' => self::FRAME_SYSTEM, 'reason' => $reason];
    }

    /**
     * @return array{type: string, account?: Account, actor?: AuthUser|null, reason?: string}|null
     */
    private function top(): ?array
    {
        return $this->frames === [] ? null : $this->frames[count($this->frames) - 1];
    }

    private function resolveFromWeb(): ?Account
    {
        $user = $this->webUser();

        if ($user === null) {
            return null;
        }

        $selected = $this->selectedAccountUuid();

        if ($this->webCache !== null && $this->webCache[0] === $user->getKey() && $this->webCache[1] === $selected) {
            return $this->webCache[2];
        }

        $account = app(AccountService::class)->resolveForPerson($user, $selected);

        $this->webCache = [$user->getKey(), $selected, $account];

        return $account;
    }

    private function webUser(): ?AuthUser
    {
        $user = Auth::guard(self::webGuard())->user();

        return $user instanceof AuthUser ? $user : null;
    }

    private function selectedAccountUuid(): ?string
    {
        if (! app()->bound('session')) {
            return null;
        }

        $value = session()->get(self::sessionKey());

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function webGuard(): string
    {
        return (string) config('accounts.web.guard', 'web');
    }

    public static function sessionKey(): string
    {
        return (string) config('accounts.web.session_key', 'accounts.current');
    }
}
