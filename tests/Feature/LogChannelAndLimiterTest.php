<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Twstec\Kit\Foundation\Logging\RequestLogChannel;

// =============================================================================
// O CANAL DE LOG, A CONFIGURAÇÃO E O LIMITADOR QUE O PACOTE USA EXISTEM NUMA
// APLICAÇÃO LIMPA — e os da aplicação vencem.
//
//   - canal `request_log` (a trilha de arquivo, onde o ResolveTenant e o
//     comando de inatividade escrevem): vem do foundation, de que este pacote
//     depende, quando a aplicação não tem o dela;
//   - limitador `api` (o `throttle:api` que este pacote põe no grupo `api`):
//     vem do foundation; um RateLimiter::for('api') da aplicação o substitui;
//   - configuração `api_keys`: vem deste pacote (ServiceProviderTest).
// =============================================================================

afterEach(function (): void {
    foreach (glob(sys_get_temp_dir().'/accounts-log-*') ?: [] as $dir) {
        (new Filesystem)->deleteDirectory($dir);
    }
});

it('sem canal na aplicação, o request_log é o padrão do foundation e recebe o evento de falhas da API — nada vai para o log de emergência', function (): void {
    $dir = sys_get_temp_dir().'/accounts-log-'.uniqid();
    $padrao = RequestLogChannel::definition();

    expect(config('logging.channels.request_log'))->toBe($padrao);

    // O mesmo canal do foundation, só com o arquivo num lugar descartável.
    config([
        'logging.channels.request_log.path' => $dir.'/request.log',
        'logging.channels.emergency.path' => $dir.'/emergencia.log',
        'security.rate_limit.api_auth_failures' => 2,
    ]);

    foreach (range(1, 2) as $tentativa) {
        $this->getJson('/api/v1/projects', ['X-Api-Key' => 'pk_live_x', 'Authorization' => 'Bearer sk_live_errada'])->assertUnauthorized();
    }

    $arquivos = glob($dir.'/request-*.log') ?: [];

    expect($arquivos)->toHaveCount(1)
        ->and((string) file_get_contents($arquivos[0]))->toContain('api.auth_failures.throttled')
        ->and(is_file($dir.'/emergencia.log'))->toBeFalse();
});

it('com canal próprio na aplicação, o pacote escreve nele', function (): void {
    $dir = sys_get_temp_dir().'/accounts-log-'.uniqid();

    $this->bootWith([
        'logging.channels.request_log' => ['driver' => 'single', 'path' => $dir.'/meu-canal.log', 'level' => 'debug'],
    ]);

    config(['security.rate_limit.api_auth_failures' => 1]);

    $this->getJson('/api/v1/projects', ['X-Api-Key' => 'pk_live_x', 'Authorization' => 'Bearer sk_live_errada'])->assertUnauthorized();

    expect((string) file_get_contents($dir.'/meu-canal.log'))->toContain('api.auth_failures.throttled');
});

it('o limitador api existe numa aplicação limpa, e um limitador api da aplicação o substitui', function (): void {
    expect(RateLimiter::limiter('api'))->not->toBeNull();

    $owner = $this->owner();
    ['api_key' => $key, 'secret_key' => $secret] = $this->keyFor($owner);

    // A aplicação registra o dela (num provider que sobe depois dos pacotes).
    RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(1)->by('da-aplicacao'));

    $this->getJson('/api/v1/projects', $this->credentials($key, $secret))->assertOk();
    $this->getJson('/api/v1/projects', $this->credentials($key, $secret))->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');
});
