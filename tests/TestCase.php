<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\Attributes\WithMigration;
use Orchestra\Testbench\TestCase as Testbench;
use Spatie\Backup\BackupServiceProvider;
use Twstec\Kit\Accounts\Account\Models\Account;
use Twstec\Kit\Accounts\Account\Services\AccountService;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\AccountsServiceProvider;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Services\ApiKeyService;
use Twstec\Kit\Accounts\Tests\Fixtures\User;
use Twstec\Kit\Auth\Providers\AuthServiceProvider;
use Twstec\Kit\Foundation\Audit\Providers\AuditServiceProvider;
use Twstec\Kit\Foundation\FoundationServiceProvider;
use Twstec\Kit\Foundation\Mail\Providers\MailServiceProvider;
use Twstec\Kit\Foundation\Settings\Providers\SettingsServiceProvider;

/**
 * Aplicação Laravel LIMPA — o esqueleto do Testbench, os pacotes foundation e
 * auth (dos quais este depende) e este pacote. Nada do starter: nenhuma view,
 * nenhum provider do aplicativo, nenhuma rota, nenhuma linha de
 * bootstrap/app.php além da padrão. As rotas /api/v1 são as que o PRÓPRIO
 * pacote registra; se uma proteção só funcionasse porque o starter lembrou de
 * ligá-la, a suíte reprovaria.
 */
#[WithMigration] // a tabela `users` do esqueleto Laravel; o resto vem das migrations dos pacotes
abstract class TestCase extends Testbench
{
    use RefreshDatabase;

    /**
     * Providers do pacote — os mesmos que a descoberta automática instala
     * (composer.json → extra.laravel; um teste confere).
     *
     * @var list<class-string>
     */
    public const PACKAGE_PROVIDERS = [
        AccountsServiceProvider::class,
    ];

    /**
     * Configuração aplicada ANTES de os providers subirem (como num processo
     * de verdade) — ver bootWith().
     *
     * @var array<string, mixed>
     */
    public static array $scenario = [];

    protected function getPackageProviders($app): array
    {
        // Numa aplicação, a descoberta segue a ordem do vendor: spatie,
        // twstec/kit-accounts, twstec/kit-auth, twstec/kit-foundation (e os
        // providers dos módulos de auditoria, e-mail e configurações da base).
        return [
            BackupServiceProvider::class,
            ...self::PACKAGE_PROVIDERS,
            AuthServiceProvider::class,
            FoundationServiceProvider::class,
            AuditServiceProvider::class,
            MailServiceProvider::class,
            SettingsServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', User::class);

        foreach (static::$scenario as $key => $value) {
            $app['config']->set($key, $value);
        }

        // O Testbench fixa o ambiente em `testing`; um cenário que declara
        // `app.env` (ex.: avisos de produção no boot) sobe nele de verdade.
        if (isset(static::$scenario['app.env'])) {
            $environment = (string) static::$scenario['app.env'];
            $app->detectEnvironment(static fn (): string => $environment);
        }
    }

    /**
     * Sobe uma aplicação nova com a configuração dada já valendo no boot.
     *
     * @param  array<string, mixed>  $config
     */
    protected function bootWith(array $config): void
    {
        static::$scenario = $config;

        try {
            $this->refreshApplication();
            // A aplicação nova vem com um banco em memória novo e vazio: migra
            // de novo (o esqueleto Laravel e as migrations dos pacotes).
            $this->loadLaravelMigrations();
            $this->artisan('migrate');
        } finally {
            static::$scenario = [];
        }
    }

    /**
     * Conta ativa, com e-mail confirmado (a API recusa dono não verificado).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function owner(array $attributes = []): User
    {
        return User::fixture(['email_verified_at' => now(), ...$attributes]);
    }

    /**
     * Chave criada pelo serviço do pacote (como o painel e a API criam), na
     * conta pessoal da pessoa — ou na conta dada.
     *
     * @param  array<string, mixed>  $data
     * @return array{api_key: ApiKey, secret_key: string}
     */
    protected function keyFor(User $owner, array $data = [], ?Account $account = null): array
    {
        return Accounts::actingAs(
            $account ?? $this->accountOf($owner),
            fn (): array => app(ApiKeyService::class)->create($owner, ['name' => 'Integração', ...$data]),
            $owner,
        );
    }

    /**
     * A conta pessoal da pessoa (criada junto com ela pelo pacote).
     */
    protected function accountOf(User $user): Account
    {
        return app(AccountService::class)->personalAccountOf($user)
            ?? throw new \LogicException('Pessoa sem conta pessoal.');
    }

    /**
     * Roda o arranjo/consulta do teste dentro da conta pessoal da pessoa.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    protected function inAccountOf(User $user, \Closure $callback): mixed
    {
        return Accounts::actingAs($this->accountOf($user), $callback, $user);
    }

    /**
     * Cabeçalhos do par de credenciais.
     *
     * @return array<string, string>
     */
    protected function credentials(ApiKey $key, string $secret): array
    {
        return [
            'X-Api-Key' => (string) $key->public_key,
            'Authorization' => 'Bearer '.$secret,
            'Accept' => 'application/json',
        ];
    }
}
