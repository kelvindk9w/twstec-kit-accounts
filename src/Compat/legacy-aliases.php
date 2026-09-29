<?php

declare(strict_types=1);

// =============================================================================
// Nomes antigos (1.x) → nomes novos, por UMA versão (2.x).
//
// Na 1.x os módulos de projetos (Tenancy) e de chaves de API (ApiKeys) moravam
// no aplicativo, em App\Core\Tenancy\… e App\Core\ApiKeys\…; na 2.0 eles são
// deste pacote, em Twstec\Kit\Accounts\Tenancy\… e Twstec\Kit\Accounts\ApiKeys\….
// O nome antigo continua resolvendo porque ele pode estar gravado fora do
// código:
//
//   - payload de fila serializado ANTES da atualização (o aviso de
//     inatividade de chave, ApiKeyInactivityWarningMail, que estava na fila no
//     deploy carrega o nome antigo da classe e a referência da chave — o
//     model ApiKey — pelo nome antigo);
//   - snapshot de componente Livewire aberto no navegador durante o deploy
//     (uma propriedade com um Project ou uma ApiKey guarda a classe);
//   - rota em cache da 1.x, bootstrap/providers.php, config publicada ou
//     código do projeto que ainda não trocou o `use`.
//
// O provider do módulo mudou de nome e de lugar: App\Core\Tenancy\Providers\
// TenancyServiceProvider virou Twstec\Kit\Accounts\AccountsServiceProvider (um
// provider só para o pacote). Um bootstrap/providers.php da 1.x que ainda o
// liste continua subindo, e o Laravel não o registra duas vezes (é a mesma
// classe que a descoberta de pacotes já registrou).
//
// O alias é PREGUIÇOSO: nada é carregado até alguém pedir um nome antigo. O
// autoloader do Composer tenta primeiro; só quando ele não acha é que este
// entra, carrega a classe nova e registra o nome antigo como apelido dela — a
// MESMA classe, então `instanceof` e type hints aceitam os dois nomes.
//
// O código do kit usa só os nomes novos (um teste de arquitetura do starter
// garante). Estes apelidos saem na 3.0.
// =============================================================================

spl_autoload_register(static function (string $class): void {
    static $renamed = [
        'App\\Core\\Tenancy\\Providers\\TenancyServiceProvider' => 'Twstec\\Kit\\Accounts\\AccountsServiceProvider',
    ];

    static $modules = [
        'App\\Core\\Tenancy\\' => 'Twstec\\Kit\\Accounts\\Tenancy\\',
        'App\\Core\\ApiKeys\\' => 'Twstec\\Kit\\Accounts\\ApiKeys\\',
    ];

    $target = $renamed[$class] ?? null;

    if ($target === null) {
        foreach ($modules as $old => $new) {
            if (str_starts_with($class, $old)) {
                $target = $new.substr($class, strlen($old));

                break;
            }
        }
    }

    if ($target !== null && (class_exists($target) || interface_exists($target) || trait_exists($target) || enum_exists($target))) {
        class_alias($target, $class);
    }
});
