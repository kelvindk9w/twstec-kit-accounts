<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

// As traduções do pacote: mesmas chaves nos três idiomas, e resolvidas pelos
// nomes de sempre (sem namespace), somadas às do aplicativo grupo a grupo.

const ACCOUNTS_LOCALES = ['pt_BR', 'en', 'es'];

function accountsLangPath(string $relative): string
{
    return dirname(__DIR__, 2).'/lang/'.$relative;
}

it('todos os idiomas têm os mesmos arquivos e chaves do pt-BR', function (): void {
    $reference = [];

    foreach (glob(accountsLangPath('pt_BR/*.php')) ?: [] as $file) {
        $reference[basename($file)] = collect(require $file)->dot()->keys()->sort()->values();
    }

    expect(array_keys($reference))->toBe(['accounts.php', 'api_keys.php', 'mail.php']);

    foreach (['en', 'es'] as $locale) {
        foreach ($reference as $file => $keys) {
            $path = accountsLangPath("{$locale}/{$file}");

            expect($path)->toBeFile();

            $actual = collect(require $path)->dot()->keys()->sort()->values();

            expect($actual->all())->toBe($keys->all(), "lang/{$locale}/{$file} diverge do pt-BR");
        }
    }
});

it('resolve cada chave do pacote pelo nome de sempre, nos três idiomas', function (string $locale): void {
    foreach (glob(accountsLangPath('pt_BR/*.php')) ?: [] as $file) {
        $group = basename($file, '.php');

        foreach (collect(require $file)->dot()->keys() as $key) {
            expect(app('translator')->hasForLocale("{$group}.{$key}", $locale))
                ->toBeTrue("Falta {$group}.{$key} em {$locale}");
        }
    }
})->with(ACCOUNTS_LOCALES);

it('toda chave que o código do pacote pede existe no pacote', function (): void {
    $faltando = [];
    $pedidas = 0;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src')) as $file) {
        if (! str_ends_with((string) $file, '.php')) {
            continue;
        }

        preg_match_all("/(?:__|trans_choice)\\('((?:accounts|api_keys|mail)\\.[a-z_.]+[a-z_])'/", (string) file_get_contents((string) $file), $matches);

        foreach ($matches[1] as $key) {
            $pedidas++;
            [$group, $item] = explode('.', $key, 2);

            foreach (ACCOUNTS_LOCALES as $locale) {
                // Direto no arquivo do PACOTE: a chave não pode depender do
                // lang/ do aplicativo.
                if (! Arr::has(require accountsLangPath("{$locale}/{$group}.php"), $item)) {
                    $faltando[] = "{$key} ({$locale})";
                }
            }
        }
    }

    expect($pedidas)->toBeGreaterThan(10)
        ->and($faltando)->toBe([]);
});
