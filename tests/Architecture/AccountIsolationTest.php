<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Twstec\Kit\Accounts\Account\Concerns\BelongsToAccount;
use Twstec\Kit\Accounts\Account\Models\AccountInvitation;
use Twstec\Kit\Accounts\Account\Scopes\AccountScope;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\Tenancy\Models\Project;

// =============================================================================
// TRAVA DO "ESQUECIMENTO" no código do pacote: nada contorna o escopo da
// conta atual fora do modo sistema declarado.
//
// Reprova:
// 1. `withoutGlobalScope(s)`, `newQueryWithoutScopes()`, `newModelQuery()` ou
//    `->getQuery()` (consultas que pulam os escopos globais);
// 2. query builder cru nas tabelas das contas (`DB::table('projects')`,
//    `->from('api_keys')`, `->join('api_key_project', …)`, SQL literal) —
//    fora da classe dos gatilhos do banco;
// 3. modo sistema (`Accounts::asSystem`) em lugar não revisado: cada chamada
//    tem um motivo e está na lista abaixo, com o porquê.
//
// A mesma trava, varrendo o starter e os cinco pacotes, está no starter
// (tests/Unit/Architecture/AccountIsolationTest.php).
// =============================================================================

const ACCOUNT_TABLES = ['accounts', 'account_memberships', 'account_invitations', 'projects', 'api_keys', 'api_key_project'];

/**
 * Onde o pacote entra em modo sistema, e por quê.
 */
const ACCOUNTS_SYSTEM_MODE_ALLOWED = [
    // A chave ainda não sabe de que conta é: a busca pela pública é a única
    // leitura em modo sistema na API.
    'src/Tenancy/Middleware/ResolveTenant.php' => 1,
    // O comando varre as chaves de todas as contas (avisos e desativação).
    'src/ApiKeys/Console/ProcessApiKeyInactivity.php' => 1,
    // Excluir conta, arrumar o que a pessoa excluída deixou nas contas e ler,
    // antes da exclusão, as chaves que ela deixa órfãs em contas alheias.
    'src/Account/Services/AccountService.php' => 3,
    // O link de convite não diz de que conta é o convite: achar pelo hash do
    // token e marcá-lo como aceito/recusado (um ponto só, InvitationTokens::system).
    'src/Account/Invitations/InvitationTokens.php' => 1,
];

/**
 * @return array<string, string> caminho relativo => conteúdo
 */
function accountsPackageSources(): array
{
    $root = dirname(__DIR__, 2);
    $arquivos = [];

    foreach ((new Finder)->files()->in($root.'/src')->name('*.php') as $file) {
        $arquivos[str_replace($root.'/', '', $file->getRealPath())] = $file->getContents();
    }

    ksort($arquivos);

    return $arquivos;
}

/**
 * Chamadas de `Accounts::asSystem(` no código (lidas dos tokens: comentário e
 * texto não contam): quantas têm motivo literal e quantas não têm.
 *
 * @return array{com_motivo: int, sem_motivo: int}
 */
function systemModeCalls(string $codigo): array
{
    $tokens = array_values(array_filter(
        PhpToken::tokenize($codigo),
        fn (PhpToken $t): bool => ! $t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
    ));

    $com = 0;
    $sem = 0;

    foreach ($tokens as $i => $token) {
        $nome = ltrim($token->text, '\\');

        if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) || ! ($nome === 'Accounts' || str_ends_with($nome, '\\Accounts'))) {
            continue;
        }

        if (($tokens[$i + 1]->text ?? '') !== '::' || ($tokens[$i + 2]->text ?? '') !== 'asSystem' || ($tokens[$i + 3]->text ?? '') !== '(') {
            continue;
        }

        $motivo = $tokens[$i + 4] ?? null;

        if ($motivo !== null && $motivo->is(T_CONSTANT_ENCAPSED_STRING) && trim($motivo->text, '\'" ') !== '') {
            $com++;
        } else {
            $sem++;
        }
    }

    return ['com_motivo' => $com, 'sem_motivo' => $sem];
}

/**
 * Violações de uma fonte PHP (sem comentários).
 *
 * @return list<string>
 */
function accountIsolationViolations(string $codigo): array
{
    $semComentarios = '';

    foreach (PhpToken::tokenize($codigo) as $token) {
        if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
            $semComentarios .= $token->text;
        }
    }

    $violacoes = [];

    foreach (['withoutGlobalScope', 'newQueryWithoutScopes', 'newModelQuery', '->getQuery()'] as $atalho) {
        if (str_contains($semComentarios, $atalho)) {
            $violacoes[] = "usa {$atalho}";
        }
    }

    $tabelas = implode('|', ACCOUNT_TABLES);

    if (preg_match("/(?:table|from|join|leftJoin|rightJoin|crossJoin)\\(\\s*['\"]({$tabelas})['\"]/", $semComentarios, $m) === 1) {
        $violacoes[] = "consulta crua em {$m[1]}";
    }

    if (preg_match("/(?:select|statement|update|delete|insert|unprepared)\\(\\s*['\"][^'\"]*\\b(?:from|into|update|join)\\s+({$tabelas})\\b/i", $semComentarios, $m) === 1) {
        $violacoes[] = "SQL literal em {$m[1]}";
    }

    return $violacoes;
}

it('nenhum código do pacote pula o escopo da conta nem consulta as tabelas das contas por fora', function (): void {
    $violacoes = [];

    foreach (accountsPackageSources() as $caminho => $codigo) {
        // Os gatilhos do banco são SQL de propósito (Support\AccountDatabaseGuards).
        if ($caminho === 'src/Account/Support/AccountDatabaseGuards.php') {
            continue;
        }

        foreach (accountIsolationViolations($codigo) as $problema) {
            $violacoes[] = "{$caminho}: {$problema}";
        }
    }

    expect($violacoes)->toBe([]);
});

it('modo sistema só onde foi revisado, sempre com motivo', function (): void {
    $encontrado = [];

    // A porta de baixo nível (o quadro de sistema) só é usada pela própria
    // porta de entrada e pela restauração dos jobs.
    $portaDeBaixo = [];

    foreach (accountsPackageSources() as $caminho => $codigo) {
        if (in_array($caminho, ['src/Accounts.php', 'src/Account/CurrentAccount.php', 'src/Account/Queue/AccountJobContext.php'], true)) {
            continue;
        }

        if (preg_match('/runAsSystem\(|systemFrame\(|FRAME_SYSTEM/', $codigo) === 1) {
            $portaDeBaixo[] = $caminho;
        }
    }

    expect($portaDeBaixo)->toBe([]);

    foreach (accountsPackageSources() as $caminho => $codigo) {
        ['com_motivo' => $vezes, 'sem_motivo' => $semMotivo] = systemModeCalls($codigo);

        expect($semMotivo)->toBe(0, "{$caminho}: modo sistema sem motivo literal");

        if ($vezes > 0) {
            $encontrado[$caminho] = $vezes;
        }
    }

    ksort($encontrado);
    $permitido = ACCOUNTS_SYSTEM_MODE_ALLOWED;
    ksort($permitido);

    expect($encontrado)->toBe($permitido);
});

it('os models da conta carregam o escopo (e o trait) — o esquecimento de um model novo reprova', function (): void {
    foreach ([Project::class, ApiKey::class, AccountInvitation::class] as $model) {
        expect(in_array(BelongsToAccount::class, class_uses_recursive($model), true))->toBeTrue($model)
            ->and((new $model)->hasGlobalScope(AccountScope::class))->toBeTrue($model);
    }
});

it('a trava não é cega: pega cada atalho e ignora comentário', function (): void {
    expect(accountIsolationViolations('<?php Project::query()->withoutGlobalScopes()->get();'))->toBe(['usa withoutGlobalScope'])
        ->and(accountIsolationViolations('<?php $m->newQueryWithoutScopes();'))->toBe(['usa newQueryWithoutScopes'])
        ->and(accountIsolationViolations('<?php Project::query()->getQuery()->get();'))->toBe(['usa ->getQuery()'])
        ->and(accountIsolationViolations("<?php DB::table('projects')->get();"))->toBe(['consulta crua em projects'])
        ->and(accountIsolationViolations("<?php DB::select('select * from api_keys');"))->toBe(['SQL literal em api_keys'])
        ->and(accountIsolationViolations("<?php \$q->join('api_key_project', 'a', '=', 'b');"))->toBe(['consulta crua em api_key_project'])
        ->and(accountIsolationViolations("<?php // DB::table('projects') withoutGlobalScopes\n\$x = 1;"))->toBe([])
        ->and(accountIsolationViolations("<?php DB::table('users')->get();"))->toBe([])
        ->and(systemModeCalls("<?php Accounts::asSystem('motivo', fn () => 1); \\Twstec\\Kit\\Accounts\\Accounts::asSystem(\$x, fn () => 1); \$m = 'Accounts::asSystem(\\'texto\\')';"))
        ->toBe(['com_motivo' => 1, 'sem_motivo' => 1]);
});
