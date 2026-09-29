<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;

// =============================================================================
// O APLICATIVO VENCE O PACOTE NAS TRADUÇÕES — a mesma regra do foundation e do
// auth (Twstec\Kit\Foundation\Localization\PackageTranslations), valendo para
// as traduções deste pacote.
//
// Quem usa o kit troca qualquer mensagem da API editando o lang/ do próprio
// aplicativo: numa mesma chave vale o texto do aplicativo; o pacote só
// preenche o que o aplicativo não definiu — em qualquer grupo, nos três
// idiomas e no idioma de reserva.
// =============================================================================

/**
 * Troca a pasta lang/ do aplicativo por uma temporária com estes arquivos e
 * refaz o carregador de traduções (como num boot novo).
 *
 * @param  array<string, array<string, mixed>>  $files  'pt_BR/api_keys.php' => conteúdo
 */
function accountsAppLang(array $files): string
{
    $dir = sys_get_temp_dir().'/accounts-app-lang-'.uniqid();

    foreach ($files as $relative => $contents) {
        @mkdir(dirname($dir.'/'.$relative), 0755, true);
        file_put_contents($dir.'/'.$relative, '<?php return '.var_export($contents, true).';');
    }

    app()->useLangPath($dir);
    app()->forgetInstance('translation.loader');
    app()->forgetInstance('translator');

    return $dir;
}

function accountsPackageLang(string $relative): array
{
    return require dirname(__DIR__, 2).'/lang/'.$relative;
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/accounts-app-lang-*') ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('(a) chave só no pacote: texto do pacote', function (string $locale): void {
    accountsAppLang(["{$locale}/outro.php" => ['x' => 'y']]);

    expect(__('api_keys.auth.invalid', [], $locale))->toBe(accountsPackageLang("{$locale}/api_keys.php")['auth']['invalid'])
        ->and(__('api_keys.scopes.denied', ['scope' => 'a:b'], $locale))
        ->toBe(str_replace(':scope', 'a:b', accountsPackageLang("{$locale}/api_keys.php")['scopes']['denied']))
        ->and(__('mail.api_key_inactivity.subject', ['platform' => 'ACME'], $locale))
        ->toBe(str_replace(':platform', 'ACME', accountsPackageLang("{$locale}/mail.php")['api_key_inactivity']['subject']));
})->with(['pt_BR', 'en', 'es']);

it('(b) mesma chave no aplicativo: texto do aplicativo', function (string $locale): void {
    accountsAppLang([
        "{$locale}/api_keys.php" => [
            'auth' => ['invalid' => "Chave recusada pelo app ({$locale})"],
            'projects' => ['created' => "Criado no app ({$locale})"],
        ],
        "{$locale}/mail.php" => ['api_key_inactivity' => ['subject' => "Assunto do app ({$locale})"]],
    ]);

    expect(__('api_keys.auth.invalid', [], $locale))->toBe("Chave recusada pelo app ({$locale})")
        ->and(__('api_keys.projects.created', [], $locale))->toBe("Criado no app ({$locale})")
        ->and(__('mail.api_key_inactivity.subject', [], $locale))->toBe("Assunto do app ({$locale})");
})->with(['pt_BR', 'en', 'es']);

it('(c) grupo repartido: cada chave resolve do lado certo — app, accounts, auth e foundation no mesmo grupo', function (string $locale): void {
    accountsAppLang([
        "{$locale}/api_keys.php" => [
            'keys' => ['revoked' => "Revogada no app ({$locale})"],
        ],
        "{$locale}/mail.php" => [
            // O corpo do aviso é do app; o assunto, do pacote.
            'api_key_inactivity' => ['heading' => "Título do e-mail do app ({$locale})"],
            'footer' => ['cnpj' => "CNPJ do app ({$locale})"],
        ],
    ]);

    $apiKeys = accountsPackageLang("{$locale}/api_keys.php");
    $mail = accountsPackageLang("{$locale}/mail.php");
    $authMail = require dirname(__DIR__, 2).'/vendor/twstec/kit-auth/lang/'.$locale.'/mail.php';
    $foundationMail = require dirname(__DIR__, 2).'/vendor/twstec/kit-foundation/lang/'.$locale.'/mail.php';

    expect(__('api_keys.keys.revoked', [], $locale))->toBe("Revogada no app ({$locale})")
        ->and(__('api_keys.keys.rotated', [], $locale))->toBe($apiKeys['keys']['rotated'])
        ->and(__('mail.api_key_inactivity.heading', [], $locale))->toBe("Título do e-mail do app ({$locale})")
        ->and(__('mail.api_key_inactivity.subject', [], $locale))->toBe($mail['api_key_inactivity']['subject'])
        ->and(__('mail.password_reset.subject', [], $locale))->toBe($authMail['password_reset']['subject'])
        ->and(__('mail.footer.cnpj', [], $locale))->toBe("CNPJ do app ({$locale})")
        ->and(__('mail.footer.transactional', [], $locale))->toBe($foundationMail['footer']['transactional']);
})->with(['pt_BR', 'en', 'es']);

it('no idioma de reserva o aplicativo também vence, e o pacote preenche o idioma pedido', function (): void {
    accountsAppLang(['pt_BR/api_keys.php' => ['auth' => ['invalid' => 'Recusada pelo app (reserva)']]]);

    app('translator')->setFallback('pt_BR');

    expect(__('api_keys.auth.invalid', [], 'fr'))->toBe('Recusada pelo app (reserva)')
        ->and(__('api_keys.auth.invalid', [], 'en'))->toBe(accountsPackageLang('en/api_keys.php')['auth']['invalid']);
});

it('a mensagem que a API devolve é a do aplicativo quando ele a define', function (): void {
    accountsAppLang(['en/api_keys.php' => ['auth' => ['invalid' => 'Chave recusada pelo app']]]);

    $this->getJson('/api/v1/projects')
        ->assertUnauthorized()
        ->assertJsonPath('error.message', 'Chave recusada pelo app');
});

it('põe a pasta do pacote antes da do aplicativo no carregador, junto com as do auth e do foundation', function (): void {
    $dir = accountsAppLang(['pt_BR/outro.php' => ['x' => 'y']]);

    app('translator');

    $paths = app('translation.loader')->paths();
    $accounts = realpath(dirname(__DIR__, 2).'/lang');
    $auth = realpath(dirname(__DIR__, 2).'/vendor/twstec/kit-auth/lang');
    $foundation = realpath(dirname(__DIR__, 2).'/vendor/twstec/kit-foundation/lang');
    $posicao = fn (string|false $path): int|false => array_search($path, array_map(fn (string $p): string => realpath($p) ?: $p, $paths), true);

    expect($posicao($dir))->toBe(count($paths) - 1)
        ->and($posicao($accounts))->toBeInt()
        ->and($posicao($auth))->toBeInt()
        ->and($posicao($foundation))->toBeInt()
        ->and($posicao($accounts))->toBeLessThan($posicao($dir))
        ->and($posicao($auth))->toBeLessThan($posicao($dir))
        ->and($posicao($foundation))->toBeLessThan($posicao($dir));
});
