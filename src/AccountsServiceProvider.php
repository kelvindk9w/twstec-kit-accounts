<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Throwable;
use Twstec\Kit\Accounts\Account\Contracts\Responses\AccountSwitchedResponse as AccountSwitchedResponseContract;
use Twstec\Kit\Accounts\Account\Contracts\Responses\InvitationAcceptedResponse as InvitationAcceptedResponseContract;
use Twstec\Kit\Accounts\Account\Contracts\Responses\InvitationDeclinedResponse as InvitationDeclinedResponseContract;
use Twstec\Kit\Accounts\Account\Contracts\Responses\InvitationUnavailableResponse as InvitationUnavailableResponseContract;
use Twstec\Kit\Accounts\Account\CurrentAccount;
use Twstec\Kit\Accounts\Account\Enums\AccountAbility;
use Twstec\Kit\Accounts\Account\Http\Middleware\ResolveCurrentAccount;
use Twstec\Kit\Accounts\Account\Http\Responses\AccountSwitchedResponse;
use Twstec\Kit\Accounts\Account\Http\Responses\InvitationAcceptedResponse;
use Twstec\Kit\Accounts\Account\Http\Responses\InvitationDeclinedResponse;
use Twstec\Kit\Accounts\Account\Http\Responses\InvitationUnavailableResponse;
use Twstec\Kit\Accounts\Account\Queue\AccountJobContext;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Account\Support\PersonLifecycle;
use Twstec\Kit\Accounts\ApiKeys\Console\ProcessApiKeyInactivity;
use Twstec\Kit\Accounts\ApiKeys\Http\Middleware\EnsureAccountWideApiKey;
use Twstec\Kit\Accounts\ApiKeys\Http\Middleware\EnsureApiKeyScope;
use Twstec\Kit\Accounts\ApiKeys\Support\PepperWarnings;
use Twstec\Kit\Accounts\Tenancy\Middleware\ResolveTenant;
use Twstec\Kit\Accounts\Tenancy\Support\TenantRateLimitSubject;
use Twstec\Kit\Accounts\Tenancy\TenantContext;
use Twstec\Kit\Auth\Contracts\AuthUser;
use Twstec\Kit\Foundation\Http\Exceptions\ApiErrorRenderer;
use Twstec\Kit\Foundation\Localization\PackageTranslations;
use Twstec\Kit\Foundation\Security\Contracts\RateLimitSubjectResolver;
use WeakMap;

/**
 * O que o pacote de contas e API instala numa aplicação Laravel — sozinho,
 * sem a aplicação precisar lembrar de chamar nada:
 *
 * - CONTAS COM MEMBROS e o ISOLAMENTO AUTOMÁTICO (ver registerAccounts): a
 *   conta atual por requisição (web: a selecionada na sessão, padrão a
 *   pessoal; API: a da chave), o middleware da web no fim do grupo `web`,
 *   a conta pessoal de cada pessoa criada, a regra de exclusão de pessoa, o
 *   contexto de conta nos jobs enfileirados e as habilidades por papel no
 *   Gate (`accounts.*`). Membros, convites, transferência e a trilha de
 *   auditoria de cada evento de conta moram nas Actions (Account\Actions),
 *   com as respostas HTTP em contratos (Account\Contracts\Responses); O escopo que filtra projetos e chaves pela conta
 *   atual vem dos próprios models (Concerns\BelongsToAccount) — não há como
 *   desligá-lo;
 * - a configuração padrão (`config('api_keys')`), as migrations (projetos,
 *   chaves de API e o vínculo entre eles, com os MESMOS nomes de arquivo que
 *   tinham no aplicativo) e as traduções do domínio (`api_keys.*` e o
 *   assunto do aviso de inatividade), com o aplicativo vencendo na mesma
 *   chave;
 * - o contexto do tenant da requisição (singleton) e quem o limite da API
 *   conta numa requisição autenticada (a chave ou o tenant — ver
 *   Tenancy\Support\TenantRateLimitSubject);
 * - o comando `api-keys:process-inactivity` (aviso prévio e desativação por
 *   inatividade — o AGENDAMENTO é do aplicativo, em routes/console.php);
 * - as rotas da API v1 (`/api/v1/…`), desligáveis para a aplicação
 *   registrar ela mesma (ver Http\ApiRoutes);
 * - as PROTEÇÕES da API (ver registerApiProtections): a autenticação por
 *   chave (`resolve.tenant`), a autorização por escopo (`scope`), a recusa da
 *   chave vinculada a projetos em operação de conta (`account.key`), o limite
 *   por chave (`throttle:api` no grupo `api`, contado DEPOIS da autenticação
 *   pela lista de prioridade) e o envelope de erro sem vazamento de detalhe
 *   em `api/*`.
 *
 * As regras que moram no domínio continuam lá e não dependem de provider:
 * o hash com pepper (nunca vazio; peppers anteriores migrados no primeiro
 * uso) e a verificação timing-safe (ApiKeyHasher), a recusa de
 * chave expirada, rotacionada, inativa ou de dono inativo/não verificado e o
 * limite de falhas de autenticação por chave e por IP (ResolveTenant +
 * ApiRateLimit do foundation), o recorte de projetos pelo vínculo da chave
 * (Project::visibleToApiKey).
 *
 * Opt-out das proteções: `api_keys.api.protections = false`
 * (API_KEYS_API_PROTECTIONS=false) — com aviso no log a cada boot, em
 * qualquer ambiente.
 *
 * NOME ANTIGO: até a 1.x o provider do módulo era
 * App\Core\Tenancy\Providers\TenancyServiceProvider; o nome antigo continua
 * resolvendo para este (Compat/legacy-aliases.php).
 */
final class AccountsServiceProvider extends ServiceProvider
{
    /**
     * Aliases do pacote. Cada um só entra se a aplicação não declarou um de
     * mesmo nome (o da aplicação prevalece).
     *
     * @var array<string, class-string>
     */
    public const MIDDLEWARE_ALIASES = [
        // Autenticação da API: resolve o tenant pela pk_/sk_ no header,
        // vincula o request log e atualiza o last_used_at.
        'resolve.tenant' => ResolveTenant::class,
        // Autorização por escopo da chave: 'scope:recurso:acao'.
        'scope' => EnsureApiKeyScope::class,
        // Operação de conta (gerenciar chaves, criar projeto): recusa a
        // chave vinculada a projetos.
        'account.key' => EnsureAccountWideApiKey::class,
    ];

    /**
     * Contrato de resposta => implementação padrão (Account\Http\Responses).
     *
     * @var array<class-string, class-string>
     */
    public const RESPONSES = [
        InvitationAcceptedResponseContract::class => InvitationAcceptedResponse::class,
        InvitationDeclinedResponseContract::class => InvitationDeclinedResponse::class,
        InvitationUnavailableResponseContract::class => InvitationUnavailableResponse::class,
        AccountSwitchedResponseContract::class => AccountSwitchedResponse::class,
    ];

    /**
     * Limite por chave de API, na FRENTE do grupo `api` (o limitador `api` é
     * do foundation — Security\ApiRateLimit).
     */
    public const API_GROUP_THROTTLE = 'throttle:api';

    /**
     * Tratadores de exceção que já receberam o envelope de erro da API.
     *
     * @var WeakMap<ExceptionHandler, true>|null
     */
    private static ?WeakMap $envelopeInstalled = null;

    public function register(): void
    {
        $this->mergeConfigFrom($this->path('config/api_keys.php'), 'api_keys');
        $this->mergeConfigFrom($this->path('config/accounts.php'), 'accounts');

        // Conta atual da requisição (ver Account\CurrentAccount) e o contexto
        // de conta dos jobs. Zerados por requisição pelos middlewares do
        // pacote (web e API).
        $this->app->singleton(CurrentAccount::class);
        $this->app->singleton(AccountJobContext::class);
        $this->app->singleton(PersonLifecycle::class);
        $this->app->singleton(AccountService::class);

        // Contexto do tenant da requisição, preenchido pelo ResolveTenant.
        // PHP-FPM garante o ciclo por requisição; se Octane entrar um dia,
        // resetar entre requisições (senão o tenant vaza de uma requisição
        // para a outra).
        $this->app->singleton(TenantContext::class);

        // Quem o limite da API conta numa requisição autenticada: Segurança
        // (foundation) define o contrato, este pacote diz quem é o cliente (a
        // chave ou o tenant). Um resolvedor próprio da aplicação prevalece.
        $this->app->bindIf(RateLimitSubjectResolver::class, TenantRateLimitSubject::class);

        // Respostas HTTP dos fluxos de conta (aceite de convite, troca de
        // conta): o padrão é `bindIf` — a implementação do aplicativo (outro
        // front, uma API JSON) vence em qualquer ordem de providers.
        foreach (self::RESPONSES as $contract => $default) {
            $this->app->bindIf($contract, $default);
        }

        PackageTranslations::register($this->app, $this->path('lang'));
    }

    public function boot(): void
    {
        $this->registerAccounts();
        $this->registerApiProtections();

        // Pepper do hash das chaves de API em produção: sem pepper dedicado
        // (ausente ou VAZIO, que vale como ausente) ou com a flag do pepper
        // vazio legado ligada → aviso no log a cada boot. Aviso, não recusa:
        // ver ApiKeys\Support\PepperWarnings.
        if ($this->app->environment('production')) {
            PepperWarnings::announce();
        }

        // As migrations rodam direto daqui, com os MESMOS nomes de arquivo
        // que tinham quando moravam no aplicativo: um banco que já as rodou
        // não vê nada pendente, e um banco novo as roda na mesma ordem.
        $this->loadMigrationsFrom($this->path('database/migrations'));

        if (config('api_keys.api.routes.enabled', true) !== false) {
            $this->loadRoutesFrom($this->path('routes/api.php'));
        }

        if ($this->app->runningInConsole()) {
            $this->commands([ProcessApiKeyInactivity::class]);

            $this->publishes([
                $this->path('config/api_keys.php') => config_path('api_keys.php'),
                $this->path('config/accounts.php') => config_path('accounts.php'),
            ], 'accounts-config');
        }
    }

    /**
     * Contas com membros: tudo o que o isolamento precisa, ligado pelo pacote.
     */
    private function registerAccounts(): void
    {
        $events = $this->app['events'];

        // Pessoa criada → conta pessoal; pessoa excluída → regra do dono.
        PersonLifecycle::register($events);

        // Jobs levam a conta de quem enfileirou e a restauram no worker.
        AccountJobContext::register($events);

        // Fim de TODA requisição HTTP (web, API, /admin): o modo sistema da
        // requisição e o cache da sessão não sobram para a próxima.
        $events->listen(RequestHandled::class, static function (): void {
            app(CurrentAccount::class)->endRequest();
        });

        // Papel na conta atual como habilidade do Gate: `accounts.<ação>`.
        foreach (AccountAbility::cases() as $ability) {
            Gate::define($ability->gateName(), static fn (AuthUser $user): bool => Accounts::can($ability, $user));
        }

        if (config('accounts.web.middleware', true) === false) {
            Log::warning('ACCOUNTS_WEB_MIDDLEWARE=false: o middleware de conta atual do twstec/kit-accounts NÃO está no grupo `web` — o contexto de conta não é zerado a cada requisição e a seleção de conta que deixou de valer não é limpa da sessão. O isolamento continua (o escopo lança exceção sem conta). Só é seguro se a aplicação instalar o Twstec\Kit\Accounts\Account\Http\Middleware\ResolveCurrentAccount por conta própria.');

            return;
        }

        $this->callAfterResolving(HttpKernelContract::class, function (HttpKernelContract $kernel): void {
            if ($kernel instanceof HttpKernel && array_key_exists('web', $kernel->getMiddlewareGroups())) {
                $kernel->appendMiddlewareToGroup('web', ResolveCurrentAccount::class);
            }
        });
    }

    /**
     * Instala as proteções da API: no kernel HTTP quando ele é resolvido —
     * depois de a aplicação montar a pilha dela (bootstrap/app.php) — ou na
     * hora, se ele já foi; e no tratador de exceções, do mesmo jeito.
     */
    private function registerApiProtections(): void
    {
        if (config('api_keys.api.protections', true) === false) {
            Log::warning('API_KEYS_API_PROTECTIONS=false: as proteções da API do twstec/kit-accounts estão DESLIGADAS — os aliases `resolve.tenant` (autenticação por chave), `scope` e `account.key` não são instalados, o grupo `api` não ganha o `throttle:api` (limite por chave), a autenticação não é posta antes do limite na lista de prioridade e os erros de `api/*` não passam pelo envelope padrão (sem vazamento de detalhe). Só é seguro se a aplicação instalar as mesmas proteções por conta própria. Ver Twstec\Kit\Accounts\AccountsServiceProvider.');

            return;
        }

        $this->callAfterResolving(HttpKernelContract::class, function (HttpKernelContract $kernel): void {
            if (! $kernel instanceof HttpKernel) {
                throw new LogicException(sprintf(
                    'twstec/kit-accounts precisa de um kernel HTTP que estenda %s para instalar as proteções da API; recebeu %s.',
                    HttpKernel::class,
                    $kernel::class,
                ));
            }

            if (! array_key_exists('api', $kernel->getMiddlewareGroups())) {
                throw new LogicException('twstec/kit-accounts: o grupo de middleware `api` não existe; o limite por chave de API não teria onde ser aplicado.');
            }

            // Limite por chave em toda rota do grupo `api` (idempotente: se a
            // aplicação já o declarou, nada muda).
            $kernel->prependMiddlewareToGroup('api', self::API_GROUP_THROTTLE);

            // O `throttle:api` conta pela CHAVE de API, então precisa rodar
            // DEPOIS do `resolve.tenant`, que é quem descobre a chave. Pela
            // posição ele rodaria antes (middleware de grupo vem antes do de
            // rota) e contaria sempre por IP. A lista de PRIORIDADE do
            // framework é o que reordena middleware entre grupo e rota, e o
            // ThrottleRequests já está nela: pôr o ResolveTenant logo antes
            // dele garante a ordem em toda rota que usar os dois, sem depender
            // de como a rota foi declarada. Chave inválida não escapa por
            // ficar antes: o próprio ResolveTenant limita as falhas por chave
            // e por IP (Twstec\Kit\Foundation\Security\ApiRateLimit).
            $kernel->addToMiddlewarePriorityBefore(ThrottleRequests::class, ResolveTenant::class);

            $kernel->setMiddlewareAliases([...self::MIDDLEWARE_ALIASES, ...$kernel->getMiddlewareAliases()]);
        });

        // Envelope padronizado de erro da API (`api/*`), contrapartida do
        // envelope de sucesso {"data": …} — ver ApiErrorRenderer (foundation)
        // e docs/api.md. Nunca stack trace, classe ou caminho de servidor, nem
        // com APP_DEBUG=true. Um render próprio da aplicação (bootstrap/app.php)
        // é registrado antes deste e prevalece.
        self::$envelopeInstalled ??= new WeakMap;

        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler): void {
            // Uma vez por tratador: o callAfterResolving pode entregar o mesmo
            // objeto duas vezes (quando o tratador é resolvido pela própria
            // chamada), e o envelope não pode entrar duplicado na lista.
            if (isset(self::$envelopeInstalled[$handler])) {
                return;
            }

            self::$envelopeInstalled[$handler] = true;

            if (! method_exists($handler, 'renderable')) {
                throw new LogicException(sprintf(
                    'twstec/kit-accounts precisa de um tratador de exceções com renderable() para instalar o envelope de erro da API; recebeu %s.',
                    $handler::class,
                ));
            }

            $handler->renderable(self::apiErrorEnvelope());
        });
    }

    /**
     * O render do envelope de erro — devolve null fora de `api/*` e o
     * Laravel segue com o tratamento padrão.
     */
    public static function apiErrorEnvelope(): Closure
    {
        return static fn (Throwable $e, Request $request) => app(ApiErrorRenderer::class)($e, $request);
    }

    private function path(string $relative): string
    {
        return dirname(__DIR__).'/'.$relative;
    }
}
