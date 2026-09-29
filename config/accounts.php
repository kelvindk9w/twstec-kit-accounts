<?php

declare(strict_types=1);

// =============================================================================
// Contas com membros — configuração padrão do pacote twstec/kit-accounts
// (`vendor:publish --tag=accounts-config` publica também este arquivo; as
// chaves de primeiro nível do aplicativo prevalecem).
//
// O isolamento em si (o escopo da conta atual, que lança exceção sem conta)
// NÃO é configurável: não existe chave para desligá-lo.
// =============================================================================

return [

    'web' => [
        // Guard da sessão web de onde vem a pessoa (e a conta atual dela).
        'guard' => env('ACCOUNTS_WEB_GUARD', 'web'),

        // Onde a conta SELECIONADA fica na sessão (uuid). Sem seleção — ou
        // com uma seleção de que a pessoa não é mais membro —, vale a conta
        // pessoal.
        'session_key' => env('ACCOUNTS_SESSION_KEY', 'accounts.current'),

        // O middleware que zera o contexto por requisição e limpa a seleção
        // que não vale mais, instalado pelo pacote no fim do grupo `web`.
        // Desligar é opt-out explícito, com aviso no log a cada boot.
        'middleware' => env('ACCOUNTS_WEB_MIDDLEWARE', true),
    ],

    'invitations' => [
        // Validade do convite, em horas (padrão: 7 dias). Depois disso o link
        // não aceita mais — quem convidou pode reenviar (o reenvio gera um
        // link novo e renova a validade).
        'expires_hours' => (int) env('ACCOUNTS_INVITATION_EXPIRES_HOURS', 168),

        // Convites PENDENTES ao mesmo tempo, por conta. Chegando no limite,
        // um novo convite é recusado até algum ser aceito, revogado ou
        // expirar.
        'max_pending' => (int) env('ACCOUNTS_INVITATION_MAX_PENDING', 20),

        // Quantos convites (novos e reenvios) cabem numa janela de
        // `throttle_minutes`: por conta e por pessoa que convida. Contra
        // spam — o e-mail do convite sai em nome da plataforma.
        'throttle' => [
            'per_account' => (int) env('ACCOUNTS_INVITATION_THROTTLE_PER_ACCOUNT', 30),
            'per_person' => (int) env('ACCOUNTS_INVITATION_THROTTLE_PER_PERSON', 20),
            'minutes' => (int) env('ACCOUNTS_INVITATION_THROTTLE_MINUTES', 60),
        ],
    ],

    'limits' => [
        // Contas de EMPRESA de que uma pessoa pode ser dona (a conta pessoal
        // não conta). Contra abuso: cada conta é um tenant com dados, chaves
        // e trilha.
        'owned_accounts' => (int) env('ACCOUNTS_MAX_OWNED', 10),
    ],

    'migration' => [
        // Tamanho do lote da migração 1.x → contas (pessoas por lote e
        // faixa de ids por UPDATE de projetos/chaves).
        'chunk' => (int) env('ACCOUNTS_MIGRATION_CHUNK', 1000),
    ],

];
