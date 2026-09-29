<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Http;

use Illuminate\Support\Facades\Route;
use Twstec\Kit\Accounts\ApiKeys\Http\Controllers\ApiKeyController;
use Twstec\Kit\Accounts\Tenancy\Http\Controllers\ProjectController;

/**
 * As rotas da API v1 do pacote: chaves de API e projetos.
 *
 * O pacote as registra sozinho (routes/api.php, com prefixo, middleware e
 * nome de `api_keys.api.routes`). Uma aplicação que prefira registrar ela
 * mesma desliga o registro automático (`api_keys.api.routes.enabled = false`,
 * API_KEYS_API_ROUTES=false) e chama `ApiRoutes::register()` onde quiser —
 * por exemplo, dentro do próprio routes/api.php, que o Laravel já monta com o
 * prefixo `api` e o grupo `api`:
 *
 *     ApiRoutes::register(prefix: 'v1', middleware: []);
 *
 * Em qualquer caso a AUTENTICAÇÃO POR CHAVE (`resolve.tenant`) entra no grupo
 * — ela não é opção de quem registra —, e cada rota traz o próprio escopo
 * (`scope:recurso:acao`), a recusa da chave vinculada em operação de conta
 * (`account.key`) e, na criação e na rotação de chave, o token de ação
 * sensível (`sensitive.token`, do twstec/kit-auth).
 *
 * Autenticação: par de credenciais no header —
 *   X-Api-Key: pk_live_...            (chave pública)
 *   Authorization: Bearer sk_live_... (chave secreta — só hash no banco)
 * O tenant (dono da chave) é resolvido e vinculado ao request log; cada rota
 * exige ainda o escopo granular da chave. Criação e rotação de chave são AÇÕES
 * SENSÍVEIS: exigem o token de curta duração emitido pela confirmação sensível
 * (senha de transação + código por e-mail), no header X-Sensitive-Action-Token.
 */
final class ApiRoutes
{
    /**
     * Middleware de autenticação que o grupo sempre recebe.
     */
    public const AUTHENTICATION = 'resolve.tenant';

    /**
     * Registra o grupo da API v1.
     *
     * @param  string|null  $prefix  Prefixo do grupo; null = `api_keys.api.routes.prefix` (`api/v1`).
     * @param  list<string>|null  $middleware  Middleware antes da autenticação; null = `api_keys.api.routes.middleware` (`['api']`).
     * @param  string|null  $name  Prefixo dos nomes; null = `api_keys.api.routes.name` (`api.v1.`).
     */
    public static function register(?string $prefix = null, ?array $middleware = null, ?string $name = null): void
    {
        $prefix ??= (string) config('api_keys.api.routes.prefix', 'api/v1');
        $middleware ??= (array) config('api_keys.api.routes.middleware', ['api']);
        $name ??= (string) config('api_keys.api.routes.name', 'api.v1.');

        Route::prefix($prefix)
            ->middleware([...$middleware, self::AUTHENTICATION])
            ->name($name)
            ->group(static function (): void {
                self::define();
            });
    }

    /**
     * As rotas, sem grupo. Quem chama direto é responsável pelo grupo — use
     * register(), que já põe a autenticação por chave.
     */
    private static function define(): void
    {
        // --- Chaves de API -------------------------------------------------
        // Gerenciar chaves é operação de CONTA: exige chave sem vínculo a
        // projetos (account.key). Uma chave vinculada que pudesse gerenciar
        // chaves se desvincularia sozinha — o vínculo limita o scope, nunca
        // o contrário. Exceção: a chave vinculada pode rotacionar ou revogar
        // a SI MESMA (account.key:self) — nenhuma das duas amplia acesso.
        Route::middleware('account.key')->group(function (): void {
            Route::get('api-keys', [ApiKeyController::class, 'index'])
                ->middleware('scope:api-keys:read')
                ->name('api-keys.index');

            Route::post('api-keys', [ApiKeyController::class, 'store'])
                ->middleware(['scope:api-keys:create', 'sensitive.token'])
                ->name('api-keys.store');

            // Vínculo N:N chave ↔ projetos (lista vazia = chave enxerga a conta toda).
            Route::put('api-keys/{uuid}/projects', [ApiKeyController::class, 'syncProjects'])
                ->middleware('scope:api-keys:assign')
                ->name('api-keys.projects.sync');
        });

        Route::middleware('account.key:self')->group(function (): void {
            Route::delete('api-keys/{uuid}', [ApiKeyController::class, 'destroy'])
                ->middleware('scope:api-keys:revoke')
                ->name('api-keys.destroy');

            Route::post('api-keys/{uuid}/rotate', [ApiKeyController::class, 'rotate'])
                ->middleware(['scope:api-keys:rotate', 'sensitive.token'])
                ->name('api-keys.rotate');
        });

        // --- Projetos (camada organizacional) ------------------------------
        // Chave vinculada a projetos só enxerga os vinculados (404 nos demais)
        // e não cria projeto (operação de conta — account.key).
        Route::get('projects', [ProjectController::class, 'index'])
            ->middleware('scope:projects:read')
            ->name('projects.index');

        Route::post('projects', [ProjectController::class, 'store'])
            ->middleware(['account.key', 'scope:projects:create'])
            ->name('projects.store');

        Route::get('projects/{uuid}', [ProjectController::class, 'show'])
            ->middleware('scope:projects:read')
            ->name('projects.show');

        Route::put('projects/{uuid}', [ProjectController::class, 'update'])
            ->middleware('scope:projects:update')
            ->name('projects.update');

        Route::delete('projects/{uuid}', [ProjectController::class, 'destroy'])
            ->middleware('scope:projects:delete')
            ->name('projects.destroy');
    }
}
