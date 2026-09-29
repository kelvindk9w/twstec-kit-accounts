<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

// =============================================================================
// ARQUITETURA DO PACOTE accounts — conhece a base (foundation), a
// autenticação (auth) e o Laravel, e só.
//
// accounts é a terceira camada do kit: depende de twstec/kit-foundation e
// twstec/kit-auth; uploads e o painel de administração dependem dela, nunca o
// contrário. E ela não tem telas (a API é backend). Este arquivo reprova o
// build quando:
//
// 1. qualquer arquivo do pacote (código, config, migrations, traduções, rotas)
//    nomeia classe do APLICATIVO (App\…, inclusive o model de usuário — o
//    pacote trabalha com o model configurado, via
//    Twstec\Kit\Auth\Support\UserModel), das camadas de cima (uploads,
//    admin) ou de tela (Filament, Livewire);
// 2. um `use` ou nome totalmente qualificado sai do que o pacote pode usar:
//    o próprio pacote, o foundation, o auth, o Laravel (Illuminate), o
//    HttpFoundation do Symfony (que o Laravel usa nas respostas) e o Carbon
//    (a biblioteca de datas que o próprio Laravel usa e expõe);
// 3. o pacote passa a ter views (telas são do front).
//
// A leitura do PHP é por tokens: comentários e strings não contam, só nomes
// de classe de verdade (`use`, `new`, `::class`, tipos, `instanceof`…).
// =============================================================================

const ACCOUNTS_FORBIDDEN_PREFIXES = [
    'App\\',
    'Database\\',
    'Twstec\\Kit\\Uploads\\',
    'Twstec\\Kit\\Admin\\',
    'Filament\\',
    'Livewire\\',
];

const ACCOUNTS_ALLOWED_ROOTS = [
    'Twstec\\Kit\\Accounts\\',
    'Twstec\\Kit\\Auth\\',
    'Twstec\\Kit\\Foundation\\',
    'Illuminate\\',
    'Symfony\\Component\\HttpFoundation\\',
    'Carbon\\',
];

const ACCOUNTS_SHIPPED_DIRECTORIES = ['src', 'config', 'database', 'lang', 'routes'];

function accountsRoot(): string
{
    return dirname(__DIR__, 2);
}

/**
 * Nomes de classe de um arquivo PHP, lidos dos tokens: todos os qualificados
 * (`$all`) e só os que são certamente absolutos — os dos `use` do topo e os
 * escritos com `\` na frente (`$absolute`).
 *
 * @return array{all: list<string>, absolute: list<string>}
 */
function accountsClassNamesIn(string $contents): array
{
    $all = [];
    $absolute = [];
    $inUse = false;
    $depth = 0;

    foreach (PhpToken::tokenize($contents) as $token) {
        if ($token->text === '{') {
            $depth++;
        } elseif ($token->text === '}') {
            $depth--;
        }

        if ($token->is(T_USE) && $depth === 0) {
            $inUse = true;

            continue;
        }

        if ($inUse && $token->text === ';') {
            $inUse = false;
        }

        if (! $token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }

        $name = ltrim($token->text, '\\');
        $all[$name] = true;

        if ($inUse || $token->is(T_NAME_FULLY_QUALIFIED)) {
            $absolute[$name] = true;
        }
    }

    return ['all' => array_keys($all), 'absolute' => array_keys($absolute)];
}

/**
 * @return array<string, list<string>> caminho relativo => violações
 */
function accountsDependencyViolations(): array
{
    $violations = [];

    foreach (ACCOUNTS_SHIPPED_DIRECTORIES as $directory) {
        foreach ((new Finder)->files()->in(accountsRoot().'/'.$directory)->name('*.php') as $file) {
            $path = str_replace(accountsRoot().'/', '', $file->getRealPath());
            $names = accountsClassNamesIn($file->getContents());

            foreach ($names['all'] as $name) {
                foreach (ACCOUNTS_FORBIDDEN_PREFIXES as $prefix) {
                    if (str_starts_with($name, $prefix)) {
                        $violations[$path][] = "usa {$name}";
                    }
                }
            }

            foreach ($names['absolute'] as $name) {
                $allowed = false;

                foreach (ACCOUNTS_ALLOWED_ROOTS as $root) {
                    $allowed = $allowed || str_starts_with($name.'\\', $root);
                }

                // Funções e constantes globais importadas não têm `\`.
                if (! $allowed && str_contains($name, '\\')) {
                    $violations[$path][] = "usa {$name} (fora de foundation + auth + Laravel)";
                }
            }
        }
    }

    ksort($violations);

    return $violations;
}

it('não nomeia nada do aplicativo, das camadas de cima nem de tela', function (): void {
    $violations = [];

    foreach (accountsDependencyViolations() as $path => $problems) {
        foreach (array_unique($problems) as $problem) {
            $violations[] = "{$path} {$problem}";
        }
    }

    expect($violations)->toBe([]);
});

it('não tem telas: nenhuma view no pacote', function (): void {
    expect(is_dir(accountsRoot().'/resources/views'))->toBeFalse();

    $blade = iterator_to_array((new Finder)->files()->in(accountsRoot().'/src')->name('*.blade.php'), false);

    expect($blade)->toBe([]);
});

it('a leitura por tokens pega o que deve pegar (a trava não é cega)', function (): void {
    $sample = <<<'PHP'
        <?php
        namespace Twstec\Kit\Accounts\Algo;
        use App\Models\User;
        use Twstec\Kit\Auth\Contracts\Responses;
        use Livewire\Component;
        // App\Demo\Coisa em comentário não conta
        $a = new \Filament\Panel;
        $b = Responses\LoginResponse::class;
        $c = 'App\\Em\\String';
        PHP;

    $names = accountsClassNamesIn($sample);

    expect($names['absolute'])->toBe(['App\Models\User', 'Twstec\Kit\Auth\Contracts\Responses', 'Livewire\Component', 'Filament\Panel'])
        ->and($names['all'])->toContain('Responses\LoginResponse')
        ->and($names['all'])->not->toContain('App\Demo\Coisa');
});
