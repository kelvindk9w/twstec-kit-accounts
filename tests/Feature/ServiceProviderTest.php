<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Twstec\Kit\Accounts\AccountsServiceProvider;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Mail\ApiKeyInactivityWarningMail;
use Twstec\Kit\Accounts\Http\ApiRoutes;
use Twstec\Kit\Accounts\Tenancy\Support\TenantRateLimitSubject;
use Twstec\Kit\Accounts\Tenancy\TenantContext;
use Twstec\Kit\Accounts\Tests\TestCase;
use Twstec\Kit\Foundation\Security\Contracts\RateLimitSubjectResolver;

/**
 * As rotas /api/v1 registradas, como "MÉTODO uri nome => middleware da rota".
 *
 * @return list<string>
 */
function accountsApiRoutes(string $namePrefix = 'api.v1.'): array
{
    return collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with((string) $route->getName(), $namePrefix))
        ->map(fn (Route $route): string => implode('|', $route->methods()).' '.$route->uri().' '.$route->getName().' => '.implode(',', $route->middleware()))
        ->values()
        ->all();
}

it('os providers da suíte são os da descoberta automática', function (): void {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer['extra']['laravel']['providers'])->toBe(TestCase::PACKAGE_PROVIDERS);
});

it('traz a configuração padrão de config(api_keys), com as chaves novas da API', function (): void {
    expect(config('api_keys.environment'))->toBe('live')
        ->and(config('api_keys.last_used_throttle_seconds'))->toBe(60)
        ->and(config('api_keys.inactivity'))->toBe(['enabled' => true, 'months' => 3, 'warning_days' => 7])
        ->and(config('api_keys.rotation.max_grace_minutes'))->toBe(10080)
        ->and(config('api_keys.pagination.per_page'))->toBe(15)
        ->and(config('api_keys.default_scopes'))->toBe(['*:*'])
        ->and(config('api_keys.api.protections'))->toBeTrue()
        ->and(config('api_keys.api.routes'))->toBe(['enabled' => true, 'prefix' => 'api/v1', 'middleware' => ['api'], 'name' => 'api.v1.']);
});

it('a configuração da aplicação vence a do pacote, chave de primeiro nível a chave', function (): void {
    $this->bootWith(['api_keys.inactivity' => ['enabled' => true, 'months' => 1, 'warning_days' => 2]]);

    expect(config('api_keys.inactivity.months'))->toBe(1)
        // O que a aplicação não definiu vem do pacote.
        ->and(config('api_keys.pagination.per_page'))->toBe(15);
});

it('roda as migrations com os mesmos nomes de arquivo que tinham no aplicativo', function (): void {
    $arquivos = array_map('basename', glob(dirname(__DIR__, 2).'/database/migrations/*.php'));

    expect($arquivos)->toBe([
        '2026_08_20_200000_create_projects_table.php',
        '2026_08_20_200001_create_api_keys_table.php',
        '2026_09_23_000002_add_restricted_to_projects_to_api_keys_table.php',
        // Contas com membros (2.0) — arquivos novos, depois dos da 1.x.
        '2026_09_26_000001_create_accounts_tables.php',
        '2026_09_26_000002_move_projects_and_api_keys_to_accounts.php',
        // Convites (2.0, membros).
        '2026_09_27_000001_create_account_invitations_table.php',
    ])->and(app('migrator')->paths())->toContain(dirname(__DIR__, 2).'/database/migrations')
        ->and(Schema::hasTable('projects'))->toBeTrue()
        ->and(Schema::hasTable('api_key_project'))->toBeTrue()
        ->and(Schema::hasColumn('api_keys', 'restricted_to_projects'))->toBeTrue()
        ->and(Schema::hasTable('accounts'))->toBeTrue()
        ->and(Schema::hasTable('account_memberships'))->toBeTrue()
        ->and(Schema::hasColumn('projects', 'account_id'))->toBeTrue()
        ->and(Schema::hasColumn('api_keys', 'account_id'))->toBeTrue()
        ->and(Schema::hasTable('account_invitations'))->toBeTrue();
});

it('registra o contexto do tenant (um por aplicação) e quem o limite da API conta', function (): void {
    expect(app(TenantContext::class))->toBe(app(TenantContext::class))
        ->and(app(RateLimitSubjectResolver::class))->toBeInstanceOf(TenantRateLimitSubject::class);
});

it('um resolvedor do limite próprio da aplicação prevalece sobre o do pacote', function (): void {
    $proprio = new class implements RateLimitSubjectResolver
    {
        public function resolve(Request $request): ?string
        {
            return 'meu';
        }
    };

    app()->instance(RateLimitSubjectResolver::class, $proprio);
    (new AccountsServiceProvider(app()))->register();

    expect(app(RateLimitSubjectResolver::class))->toBe($proprio);
});

it('registra o comando de inatividade, que funciona sem nada do aplicativo', function (): void {
    Mail::fake();
    config(['api_keys.inactivity.months' => 3]);

    $owner = $this->owner();
    ['api_key' => $velha] = $this->keyFor($owner);
    $velha->forceFill(['created_at' => now()->subMonthsNoOverflow(3)->subDay()])->save();
    ['api_key' => $avisar] = $this->keyFor($owner);
    $avisar->forceFill(['created_at' => now()->subMonthsNoOverflow(3)->addDays(3)])->save();

    expect(Artisan::all())->toHaveKey('api-keys:process-inactivity');

    $this->artisan('api-keys:process-inactivity')->assertSuccessful();

    expect($velha->refresh()->status)->toBe(ApiKeyStatus::ExpiredInactivity)
        ->and($avisar->refresh()->inactivity_warning_sent_at)->not->toBeNull();

    Mail::assertQueued(ApiKeyInactivityWarningMail::class, fn (ApiKeyInactivityWarningMail $mail): bool => $mail->apiKey->is($avisar));
});

it('registra as rotas /api/v1 com os nomes, a ordem e o middleware de rota de sempre', function (): void {
    expect(accountsApiRoutes())->toBe([
        'GET|HEAD api/v1/api-keys api.v1.api-keys.index => api,resolve.tenant,account.key,scope:api-keys:read',
        'POST api/v1/api-keys api.v1.api-keys.store => api,resolve.tenant,account.key,scope:api-keys:create,sensitive.token',
        'PUT api/v1/api-keys/{uuid}/projects api.v1.api-keys.projects.sync => api,resolve.tenant,account.key,scope:api-keys:assign',
        'DELETE api/v1/api-keys/{uuid} api.v1.api-keys.destroy => api,resolve.tenant,account.key:self,scope:api-keys:revoke',
        'POST api/v1/api-keys/{uuid}/rotate api.v1.api-keys.rotate => api,resolve.tenant,account.key:self,scope:api-keys:rotate,sensitive.token',
        'GET|HEAD api/v1/projects api.v1.projects.index => api,resolve.tenant,scope:projects:read',
        'POST api/v1/projects api.v1.projects.store => api,resolve.tenant,account.key,scope:projects:create',
        'GET|HEAD api/v1/projects/{uuid} api.v1.projects.show => api,resolve.tenant,scope:projects:read',
        'PUT api/v1/projects/{uuid} api.v1.projects.update => api,resolve.tenant,scope:projects:update',
        'DELETE api/v1/projects/{uuid} api.v1.projects.destroy => api,resolve.tenant,scope:projects:delete',
    ]);
});

it('com o registro automático desligado, a aplicação registra as rotas onde quiser — e a autenticação por chave vem junto', function (): void {
    $this->bootWith(['api_keys.api.routes.enabled' => false]);

    expect(accountsApiRoutes())->toBe([]);

    ApiRoutes::register(prefix: 'api/integracoes/v1', middleware: ['api'], name: 'integracoes.');
    app('router')->getRoutes()->refreshNameLookups();

    $rotas = accountsApiRoutes('integracoes.');

    expect($rotas)->toHaveCount(10)
        ->and($rotas[5])->toBe('GET|HEAD api/integracoes/v1/projects integracoes.projects.index => api,resolve.tenant,scope:projects:read');

    foreach ($rotas as $rota) {
        expect($rota)->toContain('=> api,resolve.tenant,');
    }

    // E a rota responde com a proteção do pacote (o envelope de erro vale
    // para tudo em api/*).
    $this->getJson('/api/integracoes/v1/projects')->assertUnauthorized()->assertJsonPath('error.code', 'unauthorized');
});

it('um alias próprio da aplicação prevalece sobre o do pacote', function (): void {
    $kernel = app(Kernel::class);
    $kernel->setMiddlewareAliases([...$kernel->getMiddlewareAliases(), 'scope' => 'App\\Middleware\\Proprio']);

    (new AccountsServiceProvider(app()))->boot();

    expect($kernel->getMiddlewareAliases()['scope'])->toBe('App\\Middleware\\Proprio')
        ->and($kernel->getMiddlewareAliases()['resolve.tenant'])->toBe(AccountsServiceProvider::MIDDLEWARE_ALIASES['resolve.tenant']);
});

it('põe o envelope de erro da API uma vez só no tratador de exceções, depois do que a aplicação registrou', function (): void {
    $handler = app(ExceptionHandler::class);
    $interno = property_exists($handler, 'appExceptionHandler') ? (fn () => $this->appExceptionHandler)->call($handler) : $handler;
    $callbacks = fn (): array => (fn () => $this->renderCallbacks)->call($interno);

    $doPacote = array_filter($callbacks(), fn (Closure $callback): bool => (new ReflectionFunction($callback))->getClosureScopeClass()?->getName() === AccountsServiceProvider::class);

    expect($doPacote)->toHaveCount(1);

    // Um novo boot do provider (ou o tratador entregue duas vezes) não duplica.
    (new AccountsServiceProvider(app()))->boot();

    expect(array_filter($callbacks(), fn (Closure $callback): bool => (new ReflectionFunction($callback))->getClosureScopeClass()?->getName() === AccountsServiceProvider::class))->toHaveCount(1);
});

it('um render próprio da aplicação para api/* roda antes do envelope do pacote e prevalece', function (): void {
    $this->bootWith([]);

    // O que o bootstrap/app.php de uma aplicação registraria (antes dos
    // providers): um render que devolve a própria resposta.
    app(ExceptionHandler::class);
    $handler = app(ExceptionHandler::class);
    $interno = property_exists($handler, 'appExceptionHandler') ? (fn () => $this->appExceptionHandler)->call($handler) : $handler;
    (function (): void {
        array_unshift($this->renderCallbacks, fn (Throwable $e, Request $request) => $request->is('api/*') ? response()->json(['meu' => 'envelope'], 418) : null);
    })->call($interno);

    $this->getJson('/api/v1/projects')->assertStatus(418)->assertExactJson(['meu' => 'envelope']);
});
