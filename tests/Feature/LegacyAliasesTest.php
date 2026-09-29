<?php

declare(strict_types=1);

use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;
use Symfony\Component\Finder\Finder;
use Twstec\Kit\Accounts\AccountsServiceProvider;
use Twstec\Kit\Accounts\ApiKeys\Enums\ApiKeyStatus;
use Twstec\Kit\Accounts\ApiKeys\Mail\ApiKeyInactivityWarningMail;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Accounts\Tenancy\TenantContext;

// Nomes antigos (App\Core\Tenancy\… e App\Core\ApiKeys\…, da 1.x) continuam
// resolvendo para as classes do pacote — é o que protege payload de fila
// serializado antes da atualização (o aviso de inatividade e a referência da
// chave dentro dele), snapshot de componente Livewire aberto no navegador e
// bootstrap/providers.php antigo.

it('resolve o nome antigo de classe, model e enum para a classe nova', function (): void {
    expect((new ReflectionClass('App\\Core\\Tenancy\\TenantContext'))->getName())->toBe(TenantContext::class)
        ->and((new ReflectionClass('App\\Core\\Tenancy\\Models\\Project'))->getName())->toBe(Project::class)
        ->and((new ReflectionClass('App\\Core\\ApiKeys\\Models\\ApiKey'))->getName())->toBe(ApiKey::class)
        ->and(enum_exists('App\\Core\\ApiKeys\\Enums\\ApiKeyStatus'))->toBeTrue()
        ->and(constant('App\\Core\\ApiKeys\\Enums\\ApiKeyStatus::Rotated'))->toBe(ApiKeyStatus::Rotated);
});

it('o provider antigo do módulo resolve para o provider do pacote, sem registro duplicado', function (): void {
    expect((new ReflectionClass('App\\Core\\Tenancy\\Providers\\TenancyServiceProvider'))->getName())->toBe(AccountsServiceProvider::class);

    // Um bootstrap/providers.php da 1.x que ainda o liste: o Laravel reconhece
    // a mesma classe já registrada pela descoberta de pacotes.
    $antes = count(app()->getProviders(AccountsServiceProvider::class));
    app()->register('App\\Core\\Tenancy\\Providers\\TenancyServiceProvider');

    expect(app()->getProviders(AccountsServiceProvider::class))->toHaveCount($antes)->and($antes)->toBe(1);
});

it('o aviso de inatividade enfileirado com os nomes antigos volta com a chave certa', function (): void {
    $owner = $this->owner();
    ['api_key' => $key] = $this->keyFor($owner);

    $serializado = serialize(new ApiKeyInactivityWarningMail($key, 7));

    // Como estaria na fila antes do deploy: a classe do e-mail e a do model
    // gravadas pelo nome da 1.x.
    // O nome aparece como objeto (O:) ou como texto (s:), conforme a forma
    // em que a fila guardou o model.
    $antigo = $serializado;

    foreach ([
        ApiKeyInactivityWarningMail::class => 'App\\Core\\ApiKeys\\Mail\\ApiKeyInactivityWarningMail',
        ApiKey::class => 'App\\Core\\ApiKeys\\Models\\ApiKey',
    ] as $novo => $velho) {
        $antigo = str_replace(
            [sprintf('O:%d:"%s"', strlen($novo), $novo), sprintf('s:%d:"%s"', strlen($novo), $novo)],
            [sprintf('O:%d:"%s"', strlen($velho), $velho), sprintf('s:%d:"%s"', strlen($velho), $velho)],
            $antigo,
        );
    }

    expect($antigo)->not->toBe($serializado)
        ->and($antigo)->toContain('App\\Core\\ApiKeys\\Models\\ApiKey');

    $mail = unserialize($antigo);

    expect($mail)->toBeInstanceOf(ApiKeyInactivityWarningMail::class)
        ->and($mail->apiKey)->toBeInstanceOf(ApiKey::class)
        ->and($mail->apiKey->is($key))->toBeTrue()
        ->and($mail->expiresInDays)->toBe(7);
});

it('a referência de model com o nome antigo (snapshot, fila) acha o registro', function (): void {
    $owner = $this->owner();
    $projeto = $this->inAccountOf($owner, fn () => Project::createWithPublicCodeRetry(['name' => 'Antigo']));

    $restaurador = new class
    {
        use SerializesAndRestoresModelIdentifiers {
            getRestoredPropertyValue as public;
        }
    };

    $restaurado = $restaurador->getRestoredPropertyValue(new ModelIdentifier('App\\Core\\Tenancy\\Models\\Project', $projeto->getKey(), [], null));

    expect($restaurado)->toBeInstanceOf(Project::class)
        ->and($restaurado->is($projeto))->toBeTrue();
});

it('não inventa apelido fora dos dois módulos nem para o que não existe', function (): void {
    expect(class_exists('App\\Core\\Tenancy\\NaoExiste'))->toBeFalse()
        ->and(class_exists('App\\Core\\ApiKeys\\Models\\NaoExiste'))->toBeFalse()
        // Uploads é de outra camada (o pacote twstec/kit-uploads, que este não
        // conhece: os apelidos App\Core\Uploads\… são dele).
        ->and(class_exists('App\\Core\\Uploads\\Models\\Upload'))->toBeFalse();
});

it('todo arquivo de src/Tenancy e src/ApiKeys tem o nome antigo equivalente resolvível', function (): void {
    $sem = [];

    foreach (['Tenancy', 'ApiKeys'] as $module) {
        foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/src/'.$module)->name('*.php')->notName(['previews.php', 'helpers.php']) as $file) {
            $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
            $novo = "Twstec\\Kit\\Accounts\\{$module}\\{$relative}";

            if (! class_exists($novo) && ! interface_exists($novo) && ! trait_exists($novo) && ! enum_exists($novo)) {
                $sem[] = "{$novo} (não existe)";

                continue;
            }

            $antigo = "App\\Core\\{$module}\\{$relative}";

            if (! class_exists($antigo) && ! interface_exists($antigo) && ! trait_exists($antigo) && ! enum_exists($antigo)) {
                $sem[] = $antigo;
            }
        }
    }

    expect($sem)->toBe([]);
});
