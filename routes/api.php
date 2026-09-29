<?php

declare(strict_types=1);

use Twstec\Kit\Accounts\Http\ApiRoutes;

// =============================================================================
// Rotas da API v1 do pacote (chaves de API e projetos), carregadas pelo
// AccountsServiceProvider quando `api_keys.api.routes.enabled` é true (o
// padrão). Prefixo, middleware e nome vêm de `api_keys.api.routes`; a
// autenticação por chave (`resolve.tenant`) entra sempre. Ver Http\ApiRoutes.
// =============================================================================

ApiRoutes::register();
