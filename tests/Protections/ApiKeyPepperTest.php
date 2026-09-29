<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Testing\TestResponse;
use Twstec\Kit\Accounts\Accounts;
use Twstec\Kit\Accounts\ApiKeys\Models\ApiKey;
use Twstec\Kit\Accounts\ApiKeys\Support\ApiKeyHasher;
use Twstec\Kit\Accounts\ApiKeys\Support\Exceptions\MissingApiKeyPepperException;
use Twstec\Kit\Accounts\ApiKeys\Support\PepperMatch;
use Twstec\Kit\Accounts\ApiKeys\Support\PepperWarnings;
use Twstec\Kit\Accounts\Tenancy\Middleware\ResolveTenant;
use Twstec\Kit\Accounts\Tests\TestCase;

// =============================================================================
// PEPPER VAZIO NUNCA É PEPPER, E TROCAR O PEPPER NÃO INVALIDA AS CHAVES.
//
// `API_KEYS_HASH_PEPPER=` (linha presente, sem valor — era o que o
// .env.example trazia) não aciona o fallback do env(): o HMAC rodava com
// pepper de 0 caracteres, em silêncio. Aqui, numa aplicação limpa:
//
//   - vazio ou só espaços vale como ausente → APP_KEY, pelo config do pacote
//     e pelo ApiKeyHasher (que cobre uma cópia antiga do config no app);
//   - peppers anteriores (API_KEYS_PREVIOUS_HASH_PEPPERS) autenticam e o hash
//     é regravado com o atual no primeiro uso;
//   - o pepper vazio legado só é aceito com a flag explícita;
//   - a recusa continua 401 no envelope, contando para o limite de falhas;
//   - produção avisa sobre pepper ausente/vazio e sobre a flag ligada.
// =============================================================================

/**
 * Roda $callback com variáveis de ambiente definidas (como o .env faria) e
 * limpa tudo no fim — a suíte começa sem nenhuma variável do kit.
 *
 * @param  array<string, string>  $vars
 */
function withPepperEnv(array $vars, Closure $callback): mixed
{
    $previous = [];

    foreach ($vars as $name => $value) {
        $previous[$name] = $_SERVER[$name] ?? null;
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        return $callback();
    } finally {
        foreach ($vars as $name => $value) {
            if ($previous[$name] === null) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$previous[$name]);
                $_ENV[$name] = $_SERVER[$name] = $previous[$name];
            }
        }
    }
}

/**
 * Hash gravado da chave, direto do banco (o atributo é oculto no model).
 */
function storedHash(ApiKey $key): string
{
    return (string) Accounts::asSystem('teste: hash gravado', fn () => ApiKey::query()->whereKey($key->getKey())->value('secret_hash'));
}

/**
 * Recusa no envelope padrão de erro da API, sem detalhe interno.
 */
function assertPepperDenied(TestResponse $response, int $status, string $code): void
{
    $response->assertStatus($status)
        ->assertJsonPath('error.code', $code);

    expect(array_keys($response->json()))->toBe(['error'])
        ->and(array_keys($response->json('error')))->toBe(['code', 'message', 'correlation_id'])
        ->and($response->getContent())->not->toContain('Exception')
        ->and($response->getContent())->not->toContain('.php');
}

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/accounts-pepper-*') ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

// -----------------------------------------------------------------------------
// Vazio = ausente
// -----------------------------------------------------------------------------

it('pepper vazio ou só com espaços no .env vale como ausente: o hash é o da APP_KEY, nunca o de pepper vazio', function (string $valor): void {
    $secret = 'sk_live_'.str_repeat('z', 48);
    $appKey = (string) env('APP_KEY');

    withPepperEnv(['API_KEYS_HASH_PEPPER' => $valor], function () use ($secret, $appKey): void {
        // Um boot novo lê o ambiente de novo (a config é montada no boot).
        $this->refreshApplication();

        // A variável EXISTE (vazia) — é exatamente o caso em que o fallback
        // do env() não age.
        expect(env('API_KEYS_HASH_PEPPER'))->not->toBeNull()
            ->and(trim((string) env('API_KEYS_HASH_PEPPER')))->toBe('')
            ->and(config('api_keys.hash_pepper'))->toBe($appKey);

        $hasher = app(ApiKeyHasher::class);

        expect($hasher->currentPepper())->toBe($appKey)
            ->and($hasher->hash($secret))->toBe(hash_hmac('sha256', $secret, $appKey))
            ->and($hasher->hash($secret))->not->toBe(hash_hmac('sha256', $secret, ''));
    });
})->with([
    'vazio' => [''],
    'só espaços' => ['   '],
]);

it('uma cópia antiga do config no app que ainda repassa o vazio não vira pepper vazio: o hasher cai na APP_KEY', function (?string $valor): void {
    $secret = 'sk_live_'.str_repeat('y', 48);

    // É o que o `config/api_keys.php` publicado antes desta correção entrega.
    config(['api_keys.hash_pepper' => $valor]);

    $hasher = app(ApiKeyHasher::class);

    expect($hasher->currentPepper())->toBe(config('app.key'))
        ->and($hasher->hash($secret))->toBe(hash_hmac('sha256', $secret, (string) config('app.key')))
        ->and($hasher->hash($secret))->not->toBe(hash_hmac('sha256', $secret, (string) $valor));

    // A chave nova, criada pelo serviço, nasce com o hash da APP_KEY.
    ['api_key' => $key, 'secret_key' => $criada] = $this->keyFor($this->owner());

    expect(storedHash($key))->toBe(hash_hmac('sha256', $criada, (string) config('app.key')))
        ->and(storedHash($key))->not->toBe(hash_hmac('sha256', $criada, (string) $valor));
})->with([
    'vazio' => [''],
    'só espaços' => ['  '],
    'nulo' => [null],
]);

it('sem pepper dedicado e sem APP_KEY não há pepper possível: recusa em vez de calcular hash sem segredo', function (): void {
    config(['api_keys.hash_pepper' => '', 'app.key' => '  ']);

    expect(fn () => app(ApiKeyHasher::class)->hash('sk_live_qualquer'))
        ->toThrow(MissingApiKeyPepperException::class);
});

it('o hash fictício da chave pública inexistente usa o pepper normalizado, nunca o vazio', function (): void {
    config(['api_keys.hash_pepper' => '']);

    // A chave pública inexistente continua 401 (e a verificação roda com o
    // pepper da APP_KEY — sem exceção, sem pepper vazio).
    assertPepperDenied(
        $this->getJson('/api/v1/projects', ['X-Api-Key' => 'pk_live_inexistente', 'Authorization' => 'Bearer sk_live_x', 'Accept' => 'application/json']),
        401,
        'unauthorized',
    );

    expect(app(ApiKeyHasher::class)->currentPepper())->toBe(config('app.key'));
});

// -----------------------------------------------------------------------------
// Peppers anteriores
// -----------------------------------------------------------------------------

it('chave emitida com um pepper anterior autentica, é regravada com o atual e o segundo uso já confere direto', function (): void {
    $dir = sys_get_temp_dir().'/accounts-pepper-'.uniqid();
    config(['logging.channels.request_log.path' => $dir.'/request.log']);

    config(['api_keys.hash_pepper' => 'pepper-antigo-7f3a']);
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($this->owner());
    expect(storedHash($key))->toBe(hash_hmac('sha256', $secret, 'pepper-antigo-7f3a'));

    // A instalação troca o pepper e declara o antigo como anterior.
    config([
        'api_keys.hash_pepper' => 'pepper-novo-91cd',
        'api_keys.previous_peppers' => ['pepper-antigo-7f3a'],
    ]);

    $hasher = app(ApiKeyHasher::class);
    expect($hasher->check($secret, storedHash($key)))->toBe(PepperMatch::Previous);

    // Primeiro uso: autentica e migra.
    $this->getJson('/api/v1/projects', $this->credentials($key, $secret))->assertOk();

    expect(storedHash($key))->toBe(hash_hmac('sha256', $secret, 'pepper-novo-91cd'))
        ->and($hasher->check($secret, storedHash($key)))->toBe(PepperMatch::Current);

    // O evento na trilha de arquivo, sem segredo nem hash.
    $log = implode('', array_map('file_get_contents', glob($dir.'/request-*.log') ?: []));
    expect($log)->toContain(ResolveTenant::HASH_MIGRATED_EVENT)
        ->and($log)->toContain('previous_pepper')
        ->and($log)->toContain((string) $key->uuid)
        ->and($log)->not->toContain($secret)
        ->and($log)->not->toContain('pepper-antigo-7f3a')
        ->and($log)->not->toContain('pepper-novo-91cd')
        ->and($log)->not->toContain(hash_hmac('sha256', $secret, 'pepper-antigo-7f3a'))
        ->and($log)->not->toContain(hash_hmac('sha256', $secret, 'pepper-novo-91cd'));

    // Segundo uso SEM o pepper anterior declarado: confere direto com o atual.
    config(['api_keys.previous_peppers' => []]);
    $this->getJson('/api/v1/projects', $this->credentials($key, $secret))->assertOk();
});

it('a lista de peppers anteriores vem do .env separada por vírgula, sem itens vazios — vazio nunca entra por ela', function (): void {
    withPepperEnv(['API_KEYS_PREVIOUS_HASH_PEPPERS' => ' pepper-a , ,pepper-b,,  '], function (): void {
        $this->refreshApplication();

        expect(config('api_keys.previous_peppers'))->toBe(['pepper-a', 'pepper-b'])
            ->and(app(ApiKeyHasher::class)->previousPeppers())->toBe(['pepper-a', 'pepper-b'])
            ->and(config('api_keys.accept_empty_pepper_legacy'))->toBeFalse();
    });

    // E uma lista vinda de config com vazios também não aceita hash de pepper vazio.
    config(['api_keys.previous_peppers' => ['', '   ']]);
    $secret = 'sk_live_'.str_repeat('v', 48);

    expect(app(ApiKeyHasher::class)->check($secret, hash_hmac('sha256', $secret, '')))->toBe(PepperMatch::None);
});

it('trocar o pepper sem declarar o anterior continua recusando a chave antiga com 401', function (): void {
    config(['api_keys.hash_pepper' => 'pepper-antigo-7f3a']);
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($this->owner());

    config(['api_keys.hash_pepper' => 'pepper-novo-91cd', 'api_keys.previous_peppers' => []]);

    assertPepperDenied($this->getJson('/api/v1/projects', $this->credentials($key, $secret)), 401, 'unauthorized');
    expect(storedHash($key))->toBe(hash_hmac('sha256', $secret, 'pepper-antigo-7f3a'));
});

// -----------------------------------------------------------------------------
// Pepper vazio legado: só com a flag
// -----------------------------------------------------------------------------

/**
 * Chave como ela ficava gravada antes desta correção, com o pepper vazio.
 *
 * @return array{api_key: ApiKey, secret_key: string}
 */
function legacyEmptyPepperKey(ApiKey $key, string $secret): array
{
    Accounts::asSystem('teste: hash legado', fn () => ApiKey::query()->whereKey($key->getKey())->update(['secret_hash' => hash_hmac('sha256', $secret, '')]));

    return ['api_key' => $key->refresh(), 'secret_key' => $secret];
}

it('chave emitida com pepper vazio é recusada sem a flag do legado: 401 no envelope e o hash não muda', function (): void {
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($this->owner());
    ['api_key' => $key] = legacyEmptyPepperKey($key, $secret);

    expect(config('api_keys.accept_empty_pepper_legacy'))->toBeFalse()
        ->and(app(ApiKeyHasher::class)->check($secret, storedHash($key)))->toBe(PepperMatch::None);

    assertPepperDenied($this->getJson('/api/v1/projects', $this->credentials($key, $secret)), 401, 'unauthorized');

    expect(storedHash($key))->toBe(hash_hmac('sha256', $secret, ''));
});

it('chave emitida com pepper vazio é aceita com a flag do legado e migrada para o pepper atual no primeiro uso', function (): void {
    $dir = sys_get_temp_dir().'/accounts-pepper-'.uniqid();

    withPepperEnv(['API_KEYS_ACCEPT_EMPTY_PEPPER_LEGACY' => 'true'], function () use ($dir): void {
        // A flag vem do .env, como numa instalação de verdade.
        $this->bootWith(['logging.channels.request_log.path' => $dir.'/request.log']);

        expect(config('api_keys.accept_empty_pepper_legacy'))->toBeTrue();

        ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($this->owner());
        ['api_key' => $key] = legacyEmptyPepperKey($key, $secret);

        expect(app(ApiKeyHasher::class)->check($secret, storedHash($key)))->toBe(PepperMatch::EmptyLegacy);

        $this->getJson('/api/v1/projects', $this->credentials($key, $secret))->assertOk();

        expect(storedHash($key))->toBe(hash_hmac('sha256', $secret, (string) config('app.key')))
            ->and(storedHash($key))->not->toBe(hash_hmac('sha256', $secret, ''));

        $log = implode('', array_map('file_get_contents', glob($dir.'/request-*.log') ?: []));
        expect($log)->toContain(ResolveTenant::HASH_MIGRATED_EVENT)
            ->and($log)->toContain('empty_pepper_legacy')
            ->and($log)->not->toContain($secret);

        // Migrada: com a flag desligada, o segundo uso confere direto.
        config(['api_keys.accept_empty_pepper_legacy' => false]);
        $this->getJson('/api/v1/projects', $this->credentials($key, $secret))->assertOk();
    });
});

it('a flag do legado não abre porta para secreta errada: 401 no envelope e o limite de falhas continua valendo', function (): void {
    config([
        'api_keys.accept_empty_pepper_legacy' => true,
        'api_keys.previous_peppers' => ['pepper-antigo-7f3a'],
        'security.rate_limit.api_auth_failures' => 3,
    ]);

    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($this->owner());

    foreach (range(1, 3) as $tentativa) {
        assertPepperDenied(
            $this->getJson('/api/v1/projects', $this->credentials($key, 'sk_live_errada'.$tentativa)),
            401,
            'unauthorized',
        );
    }

    // Passou do limite: 429 no envelope, e nem a secreta certa passa.
    $response = $this->getJson('/api/v1/projects', $this->credentials($key, $secret));
    assertPepperDenied($response, 429, 'too_many_requests');
    expect($response->headers->get('Retry-After'))->not->toBeNull();

    // O hash da chave boa não foi tocado por nenhuma tentativa.
    expect(storedHash($key))->toBe(hash_hmac('sha256', $secret, (string) config('app.key')));
});

it('a verificação calcula todos os peppers aceitos em qualquer caso — mesma quantidade para chave existente e inexistente', function (): void {
    config([
        'api_keys.hash_pepper' => 'pepper-atual',
        'api_keys.previous_peppers' => ['pepper-a', 'pepper-b', 'pepper-atual', 'pepper-a'],
        'api_keys.accept_empty_pepper_legacy' => true,
    ]);

    $hasher = app(ApiKeyHasher::class);
    $secret = 'sk_live_'.str_repeat('t', 48);

    // Sem repetição e sem o atual entre os anteriores.
    expect($hasher->previousPeppers())->toBe(['pepper-a', 'pepper-b']);

    // Cada candidato é reconhecido; o atual vence quando mais de um confere.
    expect($hasher->check($secret, hash_hmac('sha256', $secret, 'pepper-atual')))->toBe(PepperMatch::Current)
        ->and($hasher->check($secret, hash_hmac('sha256', $secret, 'pepper-b')))->toBe(PepperMatch::Previous)
        ->and($hasher->check($secret, hash_hmac('sha256', $secret, '')))->toBe(PepperMatch::EmptyLegacy)
        ->and($hasher->check($secret, hash_hmac('sha256', 'chave-publica-inexistente', 'pepper-atual')))->toBe(PepperMatch::None);

    // Estrutural: o laço dos candidatos não tem saída antecipada, e a
    // comparação é hash_equals.
    $source = (string) file_get_contents((new ReflectionClass(ApiKeyHasher::class))->getFileName());
    preg_match('/function check\(.*?\n    \}\n/s', $source, $check);

    expect($check[0] ?? '')->toContain('hash_equals')
        ->not->toContain('break')
        ->not->toMatch('/foreach.*return \$kind/s');
});

// -----------------------------------------------------------------------------
// Avisos de produção
// -----------------------------------------------------------------------------

/**
 * Sobe a aplicação em APP_ENV=production com o log padrão num arquivo
 * descartável e devolve o que o boot gravou.
 *
 * @param  array<string, string>  $env
 */
function bootProductionAndReadLog(object $test, array $env): string
{
    $dir = sys_get_temp_dir().'/accounts-pepper-'.uniqid();

    withPepperEnv($env, function () use ($test, $dir): void {
        // Só o boot interessa (os avisos saem do provider): sobe a aplicação
        // com o cenário já valendo, sem migrar — em produção o migrate pede
        // confirmação.
        TestCase::$scenario = [
            'app.env' => 'production',
            'logging.default' => 'pepper_boot',
            'logging.channels.pepper_boot' => ['driver' => 'single', 'path' => $dir.'/boot.log', 'level' => 'debug'],
        ];

        try {
            (fn () => $this->refreshApplication())->call($test);

            expect(app()->environment('production'))->toBeTrue();
        } finally {
            TestCase::$scenario = [];
        }
    });

    // Volta ao ambiente da suíte (com o banco migrado, para o encerramento).
    (fn () => $this->bootWith([]))->call($test);

    return is_file($dir.'/boot.log') ? (string) file_get_contents($dir.'/boot.log') : '';
}

it('produção com API_KEYS_HASH_PEPPER vazio: aviso explícito no boot, sem recusar', function (): void {
    $log = bootProductionAndReadLog($this, ['API_KEYS_HASH_PEPPER' => '']);

    expect($log)->toContain('API_KEYS_HASH_PEPPER está ausente ou VAZIO em APP_ENV=production')
        ->and($log)->toContain('Valor vazio nunca é usado como pepper')
        ->and($log)->not->toContain('API_KEYS_ACCEPT_EMPTY_PEPPER_LEGACY=true');
});

it('produção com a flag do pepper vazio legado ligada: aviso a cada boot, mesmo com pepper dedicado', function (): void {
    $env = ['API_KEYS_HASH_PEPPER' => 'pepper-dedicado-c4e8', 'API_KEYS_ACCEPT_EMPTY_PEPPER_LEGACY' => 'true'];

    $primeiro = bootProductionAndReadLog($this, $env);
    $segundo = bootProductionAndReadLog($this, $env);

    foreach ([$primeiro, $segundo] as $log) {
        expect($log)->toContain('API_KEYS_ACCEPT_EMPTY_PEPPER_LEGACY=true em APP_ENV=production')
            ->and($log)->not->toContain('API_KEYS_HASH_PEPPER está ausente ou VAZIO');
    }
});

it('produção com pepper dedicado e sem a flag: nenhum aviso de pepper; fora de produção, nenhum aviso', function (): void {
    $log = bootProductionAndReadLog($this, ['API_KEYS_HASH_PEPPER' => 'pepper-dedicado-c4e8']);

    expect($log)->not->toContain('API_KEYS_HASH_PEPPER')
        ->and($log)->not->toContain('API_KEYS_ACCEPT_EMPTY_PEPPER_LEGACY');

    // A suíte roda em `testing`, com pepper ausente: o boot não avisa, mas a
    // regra (chamada direto) enxerga as duas condições.
    config(['api_keys.accept_empty_pepper_legacy' => true]);

    expect(app()->environment('production'))->toBeFalse()
        ->and(PepperWarnings::forCurrentConfiguration())->toHaveCount(2);
});
