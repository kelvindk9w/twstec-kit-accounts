<?php

declare(strict_types=1);

// =============================================================================
// Motor de API Keys + Tenancy — configuração padrão do pacote
// twstec/kit-accounts. A aplicação pode publicar a própria cópia
// (`vendor:publish --tag=accounts-config`); as chaves de primeiro nível dela
// prevalecem.
//
// Todos os valores são ajustáveis por .env — NUNCA hardcodar.
// =============================================================================

return [

    // Ambiente das chaves geradas: define o prefixo pk_live_/sk_live_ ou
    // pk_test_/sk_test_. Produção usa 'live'; sandbox/dev usam 'test'.
    'environment' => env('API_KEYS_ENVIRONMENT', 'live'),

    // --- Hash da chave secreta --------------------------------------------------
    // DECISÃO (documentada em docs/api.md): HMAC-SHA256 com pepper, no padrão Sanctum
    // (SHA-256). A sk_ tem 256 bits de entropia aleatória criptográfica — KDF
    // lenta (Argon2id) protege segredos de BAIXA entropia (senhas humanas); aqui
    // só adicionaria latência por requisição sem ganho. O pepper (segredo fora
    // do banco) garante que um vazamento SÓ do banco não permita computar nem
    // verificar hashes. A comparação é SEMPRE timing-safe via hash_equals().
    // Pepper próprio recomendado (API_KEYS_HASH_PEPPER); fallback: APP_KEY.
    // VAZIO (ou só espaços) conta como AUSENTE e também cai na APP_KEY: a
    // linha `API_KEYS_HASH_PEPPER=` sem valor não aciona o fallback do env(),
    // e o HMAC rodaria com pepper de 0 caracteres. O ApiKeyHasher repete a
    // regra (vale mesmo com uma cópia antiga deste arquivo).
    'hash_pepper' => filled(env('API_KEYS_HASH_PEPPER')) ? env('API_KEYS_HASH_PEPPER') : env('APP_KEY'),

    // Peppers ANTERIORES, no estilo do APP_PREVIOUS_KEYS (lista separada por
    // vírgula): a secreta que não confere com o pepper atual é tentada contra
    // cada um e, se conferir, tem o hash regravado com o atual no primeiro uso
    // (evento api_keys.secret_hash.migrated no request_log). É o que permite
    // trocar o pepper — ou sair do fallback da APP_KEY para um pepper
    // dedicado, declarando a APP_KEY aqui — sem invalidar as chaves emitidas.
    'previous_peppers' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('API_KEYS_PREVIOUS_HASH_PEPPERS', ''))),
        static fn (string $pepper): bool => $pepper !== '',
    )),

    // LEGADO: aceita (e migra no primeiro uso) chaves emitidas quando o
    // pepper era VAZIO — antes desta correção, `API_KEYS_HASH_PEPPER=` sem
    // valor gerava hash sem segredo. Desligado por padrão; vazio nunca é
    // aceito de forma implícita. Ligue só durante a transição e desligue
    // quando todas as chaves tiverem sido usadas ou rotacionadas: com ela
    // ligada, produção grava aviso no log a cada boot.
    'accept_empty_pepper_legacy' => (bool) env('API_KEYS_ACCEPT_EMPTY_PEPPER_LEGACY', false),

    // Atualização do last_used_at é throttled: no máximo 1 escrita a cada N
    // segundos por chave (a request nunca paga um UPDATE a cada chamada).
    'last_used_throttle_seconds' => (int) env('API_KEYS_LAST_USED_THROTTLE_SECONDS', 60),

    // --- Expiração por inatividade ---------------------------------------------
    // Job diário (api-keys:process-inactivity): chave sem uso por `months`
    // meses é desativada (status expired_inactivity); `warning_days` antes,
    // envia e-mail de AVISO PRÉVIO (uma única vez por ciclo de inatividade).
    'inactivity' => [
        'enabled' => env('API_KEYS_INACTIVITY_ENABLED', true),
        'months' => (int) env('API_KEYS_INACTIVITY_MONTHS', 3),
        'warning_days' => (int) env('API_KEYS_INACTIVITY_WARNING_DAYS', 7),
    ],

    // --- Rotação ---------------------------------------------------------------
    // No ato da rotação o usuário escolhe a morte da antiga: grace_period_minutes
    // nulo/0 = morte imediata; positivo = janela de coexistência (sem downtime).
    'rotation' => [
        'max_grace_minutes' => (int) env('API_KEYS_MAX_GRACE_MINUTES', 10080),
    ],

    // Paginação das listagens da API v1.
    'pagination' => [
        'per_page' => (int) env('API_KEYS_PER_PAGE', 15),
    ],

    // Scopes padrão na criação: TUDO habilitado. O usuário pode
    // restringir por recurso:ação (menor privilégio) informando `scopes`.
    'default_scopes' => ['*:*'],

    // Catálogo de scopes oferecidos na UI do painel (seleção granular).
    // Recursos novos do domínio entram aqui para aparecer na tela de chaves.
    // A API aceita qualquer "recurso:acao" bem formado (StoreApiKeyRequest);
    // este catálogo governa apenas o que a UI oferece como toggle.
    'scopes_catalog' => [
        'api-keys' => ['read', 'create', 'rotate', 'revoke', 'assign'],
        'projects' => ['read', 'create', 'update', 'delete'],
        // Os módulos instalados acrescentam os deles na cópia da aplicação
        // (no starter, 'uploads' => ['create']).
    ],

    // --- API v1 (pacote twstec/kit-accounts) ------------------------------------
    'api' => [
        // PROTEÇÕES da API que o pacote instala sozinho: os aliases
        // `resolve.tenant` (autenticação por chave), `scope` e `account.key`;
        // o `throttle:api` (limite por chave) na frente do grupo `api`; a
        // autenticação antes do limite na lista de prioridade; e o envelope de
        // erro de `api/*` sem vazamento de detalhe. Desligar só é seguro se a
        // aplicação instalar as mesmas proteções — e fica registrado no log a
        // cada boot.
        'protections' => env('API_KEYS_API_PROTECTIONS', true),

        // Rotas /api/v1 (chaves de API e projetos). Com `enabled` = false o
        // pacote não as registra e a aplicação chama
        // Twstec\Kit\Accounts\Http\ApiRoutes::register() onde quiser. A
        // autenticação por chave (`resolve.tenant`) entra no grupo sempre.
        'routes' => [
            'enabled' => env('API_KEYS_API_ROUTES', true),
            'prefix' => 'api/v1',
            'middleware' => ['api'],
            'name' => 'api.v1.',
        ],
    ],

];
