# twstec/kit-accounts

> **Parte do [TWS Laravel Starter Kit](https://github.com/kelvindk9w/tws-laravel-starter-kit).** O código, as issues e os
> pull requests ficam no monorepo
> [kelvindk9w/tws-laravel-starter-kit](https://github.com/kelvindk9w/tws-laravel-starter-kit) (pasta `packages/accounts`); este
> repositório é o espelho só-leitura publicado a cada versão.
> Documentação: [docs/](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/docs) · Segurança:
> [SECURITY.md](SECURITY.md) · Licença: MIT ([LICENSE](LICENSE)).

Contas e API do **TWS Laravel Starter Kit**, como pacote Laravel **sem telas**:
**contas com membros e papéis**, o **isolamento automático** entre contas,
projetos, chaves de API e a API v1. É a terceira camada do kit: depende só do
[`twstec/kit-auth`](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/packages/auth), do [`twstec/kit-foundation`](https://github.com/kelvindk9w/tws-laravel-starter-kit/tree/desenvolvimento/packages/foundation) e do
Laravel — não conhece uploads, o painel de administração nem a interface, e um
teste de arquitetura na suíte do pacote garante isso.

O **dono dos dados é a conta**: projetos e chaves pertencem a uma conta (e
guardam quem os criou); uma pessoa pode estar em várias contas, com um papel
fixo em cada (owner, admin, member), e toda pessoa tem a sua conta pessoal.
Toda consulta de dado de conta sai filtrada pela conta atual — e, sem conta,
**dá erro em vez de devolver tudo**. O guia completo (modelo, papéis, conta
atual, modo sistema, jobs, exclusão de pessoa, migração da 1.x) está em
[`docs/tenancy.md`](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/docs/tenancy.md). Membros, convites, transferência de
propriedade, exclusão de conta e a trilha de auditoria de cada evento de conta
são **Actions** do pacote (sem tela), com as respostas HTTP em contratos — o
starter Livewire tem as telas; outro front reaproveita as Actions.

- **Requisitos:** PHP 8.4+, Laravel 13, `twstec/kit-auth` e
  `twstec/kit-foundation` 2.x.
- **Licença:** MIT.

## O que o pacote traz

| Peça | O que faz |
| --- | --- |
| `Accounts` | A porta de entrada: `current()`, `asSystem('motivo', fn)`, `systemModeForRequest('motivo')` (middleware de área), `actingAs($conta, fn)`, `can()`/`authorize()` pelo papel, `switchTo($conta)` (seleção na sessão) |
| `Account\Models` | `Account` (conta; `owner`, `members`, `roleOf()`, conta pessoal) e `AccountMembership` (pessoa × conta, com a regra do dono no código) |
| `Account\Enums` | `AccountRole` (papéis fixos e a matriz `allows()`) e `AccountAbility` (as ações, também no Gate como `accounts.*`) |
| `Account\Concerns\BelongsToAccount` + `Account\Scopes\AccountScope` | O isolamento: escopo global da conta atual (exceção sem conta), gravação só na conta atual, `created_by`; registro sem conta só num model que declare a exceção (`allowsRecordWithoutAccount()` — hoje só a foto pessoal dos uploads) e só em modo sistema |
| `Account\CurrentAccount` | A conta atual: quadro explícito (API, `actingAs`, modo sistema), modo sistema da requisição, sessão web (seleção ou conta pessoal); e o papel de cada conta + pessoa guardado por requisição (`roleFor`), esquecido quando um vínculo muda, no fim da requisição e a cada job |
| `Account\Services\AccountService` | Conta pessoal, conta de empresa, membros, a regra de exclusão de pessoa, excluir conta |
| `Account\Events` | Pontos de extensão: `AccountCreated`, `MemberAdded`, `MemberRemoved` e, para quem guarda dado das contas fora do pacote (os uploads), `PersonDeleting` (só leitura — a exclusão ainda pode ser recusada), `PersonDeleted` e `AccountDeleting` (na transação da exclusão da conta) |
| `Account\Actions` | A regra de cada fluxo de conta, conferindo o papel e gravando a trilha (inclusive as recusas): `CreateAccount`, `RenameAccount`, `DeleteAccount` e `TransferOwnership` (os dois últimos com o token de ação sensível), `SwitchAccount`, `InviteMember`, `ResendInvitation`, `RevokeInvitation`, `AcceptInvitation`, `RegisterAndAcceptInvitation` (o aceite cria a conta, verificada), `DeclineInvitation`, `ChangeMemberRole`, `RemoveMember`, `LeaveAccount`. Pré-checagem para a tela conferir o papel antes de abrir a confirmação ou mandar o código: `authorize($pessoa)` em `TransferOwnership`, `DeleteAccount`, `RenameAccount`, `RemoveMember` e `RevokeInvitation` — o mesmo 403 de `handle()`, com a recusa na trilha (`denied`) |
| `Account\Support\MemberRules` | Quem mexe em quem (a mesma regra na Action e na tela) |
| `Account\Support\AccountResourceGuard` | A guarda das telas de chaves de API e de projetos: papel (`authorize()`, o 403 de sempre) e posse (`apiKey()`, `project()`: o 404 comum, idêntico para "de outra conta" e "não existe"; `foreignProjects()` no vínculo), gravando cada recusa (`denied`) com a ação tentada — `ApiKeyAttempt`, `ProjectAttempt`. Na API v1, os 403 de escopo e de chave vinculada também gravam `denied` (contexto `api`); os 401/404 ficam só no `request_logs` |
| `Account\Support\AccountAudit` | A trilha dos eventos de conta em `audit_events` (quem, conta, alvo, antes/depois redigido, IP, UA, correlation_id; `denied` nas recusas), na transação da mudança |
| `Account\Support\OrphanedApiKeys`, `Account\Mail` | O aviso de chave órfã e o convite (e-mails no template do kit, na galeria `/mail-preview`; o corpo é do front) |
| `Account\Models\AccountInvitation`, `Account\Invitations` | O convite (dado da conta; token só em hash), a busca pelo token (o único modo sistema dos convites) e o que a tela do link pode mostrar (`InvitationPreview`) |
| `Account\Queries\AccountDirectory` | As leituras das telas de conta (contas da pessoa, membros, convites em aberto) |
| `Account\Contracts\Responses`, `Account\Http` | Os contratos de resposta (aceite, recusa, convite indisponível, troca de conta — padrão `bindIf`) e os controllers dos envios do link de convite e da troca de conta (com `throttle:sensitive` no controller) |
| `Account\Http\Middleware\ResolveCurrentAccount` | No grupo `web`: limpa a seleção que deixou de valer e o estado por requisição |
| `Account\Queue\AccountJobContext` | O contexto de conta no payload dos jobs e a restauração no worker |
| `Account\Support\AccountDatabaseGuards` | Os gatilhos do PostgreSQL (regra do dono, chave ↔ projeto da mesma conta) |
| `ApiKeys\Support` | Par de chaves `pk_`/`sk_` (`ApiKeyGenerator`) e o hash da secreta com HMAC-SHA256 + pepper, verificado com `hash_equals` (`ApiKeyHasher`): pepper vazio nunca é usado, peppers anteriores e o legado do pepper vazio (só com flag) migram no primeiro uso, e os avisos de produção sobre o pepper (`PepperWarnings`) |
| `ApiKeys\Models\ApiKey` | Da conta (`account`, `creator`); escopos `recurso:acao` com curinga, vínculo com projetos e a marca de restrição (`restricted_to_projects`), validade, rotação com período de graça, inatividade, `last_used_at` com escrita limitada |
| `ApiKeys\Services\ApiKeyService` | Criar, rotacionar (herda nome, escopos, projetos e a restrição), revogar e vincular projetos |
| `ApiKeys\Console\ProcessApiKeyInactivity` | `api-keys:process-inactivity`: aviso prévio por e-mail e desativação por inatividade |
| `ApiKeys\Http`, `Tenancy\Http` | Controllers, Form Requests e Resources da API v1; middlewares `EnsureApiKeyScope` (`scope`) e `EnsureAccountWideApiKey` (`account.key`) |
| `Tenancy\Middleware\ResolveTenant` | `resolve.tenant`: autentica o par de chaves, recusa chave inutilizável e conta de dono inativo ou com e-mail não confirmado, limita as falhas por chave e por IP, define a CONTA da chave como conta atual e vincula o request log a ela |
| `Tenancy` | `TenantContext` e os helpers `tenant()` (a conta) / `tenantKey()`, `Project` (da conta) com o recorte `visibleToApiKey`, `ProjectService` (o CRUD único do painel e da API, na conta atual), `AccountOverviewQuery` (os números do painel do cliente, da conta atual) |
| `Http\ApiRoutes` | As rotas `/api/v1/api-keys…` e `/api/v1/projects…` |

## Instalação

Pelo Packagist:

```bash
composer require "twstec/kit-accounts:^2.0@beta"   # durante o beta; na 2.0.0 estável, ^2.0
```

Durante o beta, cada pacote do kit que você requerer leva o `@beta` (ou o
projeto declara `"minimum-stability": "beta"` com `"prefer-stable": true`) —
ver [docs/instalacao.md](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/docs/instalacao.md).

**No monorepo** (desenvolvimento do próprio kit), o starter instala o pacote por
*path repository* — como os
outros:

```json
"repositories": [
    {
        "type": "path",
        "url": "../../packages/accounts",
        "options": {
            "versions": { "twstec/kit-accounts": "2.x-dev" },
            "reference": "config"
        }
    }
],
"require": {
    "twstec/kit-accounts": "2.x-dev"
}
```

O `AccountsServiceProvider` é descoberto automaticamente. Depois,
`php artisan migrate` (numa base da 1.x, a migração cria a conta pessoal de
cada pessoa e passa os dados para ela — ver
[Migração da 1.x](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/docs/tenancy.md#migração-da-1x)). O model de usuário é
o do aplicativo, lido de `auth.providers.users.model` (ver o README do
`twstec/kit-auth`); o pacote o trata pelo contrato `AuthUser` e liga a conta
pessoal e a regra de exclusão aos eventos dele — o model não precisa de trait.

## O que ele instala sozinho

Nenhuma proteção depende de o aplicativo lembrar de chamar algo:

- **Isolamento entre contas:** o escopo da conta atual nos models da conta
  (não há chave para desligá-lo); o middleware de conta atual no grupo `web`
  (opt-out explícito, com aviso no log a cada boot:
  `ACCOUNTS_WEB_MIDDLEWARE=false`); a conta da chave na API; o contexto de
  conta nos jobs enfileirados; o fim de toda requisição HTTP desfazendo o modo
  sistema da requisição; a conta pessoal de cada pessoa criada; a recusa de
  excluir pessoa dona de conta com outros membros; os gatilhos do PostgreSQL;
  as habilidades por papel no Gate (`accounts.*`).

- **Autenticação por chave:** o alias `resolve.tenant`, que entra SEMPRE no
  grupo das rotas v1 (inclusive quando o aplicativo as registra ele mesmo).
- **Escopo e operação de conta:** os aliases `scope` e `account.key`,
  declarados em cada rota.
- **Limite por chave:** o `throttle:api` na frente do grupo `api` e o
  `ResolveTenant` logo antes do `ThrottleRequests` na lista de prioridade — o
  limite roda depois da autenticação e conta a chave (ou o dono, com
  `RATE_LIMIT_API_BY=tenant`), não o IP. O limitador `api` é do foundation.
- **Limite de falhas de autenticação** por chave pública + IP e um teto por
  IP, dentro do próprio `ResolveTenant` (`ApiRateLimit`, do foundation), antes
  de qualquer consulta.
- **Envelope de erro da API** (`{"error": {"code", "message",
  "correlation_id"}}`) para tudo em `api/*`, sem stack trace, classe ou
  caminho de servidor — nem com `APP_DEBUG=true` (o `ApiErrorRenderer` do
  foundation, registrado no tratador de exceções).
- **Regras que moram no domínio** e valem com qualquer rota: pepper
  obrigatório no hash (`API_KEYS_HASH_PEPPER`, e a `APP_KEY` quando ele não
  existe **ou está vazio** — vazio nunca é pepper), peppers anteriores com
  migração no primeiro uso (ver abaixo), recusa de chave revogada, expirada, rotacionada fora da graça ou
  inativa há mais que o limite (na autenticação — não depende do agendamento),
  recusa de dono inativo ou com e-mail não confirmado, recorte de projetos pelo
  vínculo da chave (404 uniforme fora dele).
- **Configuração padrão** em `config('api_keys')` e `config('accounts')`
  (guard e chave de sessão da conta selecionada, o middleware web, o lote da
  migração, validade, limite e intervalo dos convites, limite de contas de
  empresa por dono); as chaves de primeiro nível dos arquivos do aplicativo prevalecem
  (`vendor:publish --tag=accounts-config`).
- **Migrations** com os **mesmos nomes de arquivo** que tinham no aplicativo na
  1.x (`projects`, `api_keys`, `api_key_project` e a coluna
  `restricted_to_projects`): um banco que já as rodou não vê nada pendente; e
  as duas das contas (`2026_09_26_000001` e `…000002`), que migram os dados da
  1.x, e a dos convites (`2026_09_27_000001`).
- **Traduções** (pt-BR, en, es) das mensagens da API (`api_keys.*`), das
  contas (`accounts.*`: papéis, recusas, exceções) e do assunto do aviso de
  inatividade (`mail.api_key_inactivity.subject`), sem namespace. **O aplicativo vence** na mesma chave (a regra do foundation,
  `Localization\PackageTranslations`).
- **Comando** `api-keys:process-inactivity` e o aviso de inatividade na
  galeria `/mail-preview` do foundation.

Os aliases só entram se o aplicativo não declarou um de mesmo nome, e um
render de exceção declarado no `bootstrap/app.php` do aplicativo roda antes do
envelope do pacote.

**Opt-out** (só explícito): `API_KEYS_API_PROTECTIONS=false`
(`api_keys.api.protections`) não instala os aliases, o `throttle:api`, a
prioridade nem o envelope — e o pacote grava um aviso no log a cada boot, em
qualquer ambiente. Só é seguro se a aplicação instalar as mesmas proteções por
conta própria.

## Pepper do hash das chaves

| Variável | Config | O que faz |
| --- | --- | --- |
| `API_KEYS_HASH_PEPPER` | `api_keys.hash_pepper` | Pepper atual. Ausente, vazio ou só espaços → `APP_KEY` (vazio conta como ausente, também no `ApiKeyHasher`, que cobre uma cópia antiga do config no aplicativo). Sem pepper e sem `APP_KEY`, o hash é recusado (`MissingApiKeyPepperException`) |
| `API_KEYS_PREVIOUS_HASH_PEPPERS` | `api_keys.previous_peppers` | Peppers anteriores, separados por vírgula (itens vazios descartados). A secreta que confere com um deles autentica e tem o hash regravado com o atual |
| `API_KEYS_ACCEPT_EMPTY_PEPPER_LEGACY` | `api_keys.accept_empty_pepper_legacy` | Desligada por padrão. Ligada, aceita e migra chaves gravadas com pepper vazio (antes desta correção) |

Na autenticação, o `ApiKeyHasher::check()` calcula e compara com `hash_equals`
**todos** os peppers aceitos, sem saída antecipada: o custo depende só da
configuração, e a chave pública inexistente (conferida contra um hash
fictício com o mesmo pepper) leva o mesmo tempo. A migração acontece depois
de a autenticação passar inteira, com escrita condicional ao hash antigo, e
grava `api_keys.secret_hash.migrated` no `request_log` (chave, dono e origem —
sem segredo). A recusa continua 401 no envelope, contando para o limite de
falhas.

Em `APP_ENV=production`, o provider grava aviso no log a cada boot quando não
há pepper dedicado (ausente ou vazio) e quando a flag do legado está ligada —
aviso, não recusa. Roteiro de transição em [`docs/api.md`](https://github.com/kelvindk9w/tws-laravel-starter-kit/blob/desenvolvimento/docs/api.md#roteiro-de-transição).

## Rotas da API v1

O pacote registra as rotas com o prefixo `api/v1`, o grupo `api` e os nomes
`api.v1.*` (`api_keys.api.routes`). Para registrar você mesmo — outro
prefixo, outro grupo —, desligue o registro automático com
`API_KEYS_API_ROUTES=false` e chame o registro onde quiser:

```php
use Twstec\Kit\Accounts\Http\ApiRoutes;

// routes/api.php (o Laravel já põe o prefixo `api` e o grupo `api`)
ApiRoutes::register(prefix: 'v1', middleware: []);
```

A autenticação por chave (`resolve.tenant`) entra no grupo em qualquer caso.
O envelope de erro vale para `api/*`: um prefixo fora de `api/` fica sem ele.

## O que o aplicativo liga

| O quê | Como | Por que não no pacote |
| --- | --- | --- |
| Telas de chaves, projetos e painel do cliente | Componentes do front sobre `ApiKeyService`, `ProjectService` e `AccountOverviewQuery`, conferindo o papel e a posse com o `Account\Support\AccountResourceGuard` (o mesmo 403/404, com a recusa na trilha) | São a interface |
| Seletor de conta, página da conta, tela do convite | Telas sobre as Actions, o `AccountDirectory` e o `InvitationPreview`; rotas apontando para os controllers do pacote (`InvitationController`, `AccountSwitchController`) | São a interface |
| Corpo dos e-mails de convite e de chave órfã | Views `mail.messages.account-invitation` e `mail.messages.orphaned-api-keys` (a do convite usa a rota `invitations.show`; a do aviso, a rota assinada `accounts.open`) | É interface |
| Modo sistema dos próprios comandos, seeders e jobs que varrem contas | `Accounts::asSystem('motivo', fn () => …)` | Só o código sabe que precisa ver todas as contas |
| Agendamento do `api-keys:process-inactivity` | `Schedule::command('api-keys:process-inactivity')->daily()` em `routes/console.php` | A ordem do agendamento é do aplicativo; a recusa da chave inativa já vale na autenticação sem ele |
| Corpo do aviso de inatividade | View `mail.messages.api-key-inactivity-warning` | É interface (o layout `<x-email::…>` é do foundation) |
| Catálogo de escopos oferecido na tela | `api_keys.scopes_catalog` na cópia do aplicativo | O front decide o que oferece; a API aceita qualquer `recurso:acao` bem formado |
| Resources do painel `/admin` | No starter | São do painel de administração |

## Nomes antigos → nomes novos

| Na 1.x | Na 2.0 |
| --- | --- |
| `App\Core\Tenancy\…` | `Twstec\Kit\Accounts\Tenancy\…` (o resto do nome não muda) |
| `App\Core\ApiKeys\…` | `Twstec\Kit\Accounts\ApiKeys\…` (o resto do nome não muda) |
| `App\Core\Tenancy\Providers\TenancyServiceProvider` em `bootstrap/providers.php` | `Twstec\Kit\Accounts\AccountsServiceProvider`, descoberto automaticamente — tire-o de `bootstrap/providers.php` |
| Aliases `resolve.tenant`, `scope`, `account.key`, o `throttle:api` no grupo `api`, a prioridade do `ResolveTenant` e o render do `ApiErrorRenderer` em `bootstrap/app.php` | Instalados pelo pacote — tire-os de `bootstrap/app.php` |
| Rotas de chaves e projetos em `routes/api.php` | Registradas pelo pacote — tire-as de `routes/api.php` |
| `ProcessApiKeyInactivity` em `$this->commands()` do `AppServiceProvider` | Registrado pelo pacote |
| `app/Core/Tenancy/helpers.php` e `app/Core/ApiKeys/Mail/previews.php` no `autoload.files` | Carregados pelo pacote — tire-os do `composer.json` |
| `?User` em `tenant()`, `TenantContext::user()` e nos serviços | `?AuthUser` (o objeto continua sendo o model do aplicativo) |
| **2.0 — contas:** `tenant()` devolvia a pessoa dona da chave | Devolve a **conta** (`Account`); a pessoa por trás da chave é `app(TenantContext::class)->user()` / `$request->user()`. O uuid da conta pessoal é o da pessoa |
| `projects.user_id`, `api_keys.user_id`, `Project::owner()`, `ApiKey::owner()` | `account_id` + `created_by`; `account()`, `creator()` e, para o dono, `account->owner` |
| `ProjectService::listForUser($p)` / `findForUser($p, $uuid)` | `list()` / `find($uuid)`, na conta atual |
| `ApiKeyService::resolveProjectIds($p, $uuids)` | `resolveProjectIds($uuids)`, na conta atual |
| `AccountOverviewQuery::for($p)` | `forCurrentAccount()` |
| `TenantContext::resolve($pessoa, $chave)` | `resolve($conta, $chave, $pessoa)` |

**Compatibilidade por uma versão.** Os nomes antigos continuam resolvendo, como
apelidos das classes novas (`src/Compat/legacy-aliases.php`): é a mesma classe,
então `instanceof` e type hints aceitam os dois nomes. Isso protege o que está
gravado fora do código — o aviso de inatividade que estava na fila no deploy
(com o nome da classe e o do model da chave), o snapshot de um componente
Livewire aberto no navegador, uma rota em cache. **Os apelidos saem na 3.0**:
troque os `use` do seu código.

## Testes

A suíte do pacote é isolada do aplicativo (Pest + Orchestra Testbench) e sobe
uma aplicação Laravel **limpa** — o esqueleto do Testbench, o foundation, o
auth e este pacote, com um model de usuário mínimo, as rotas que o próprio
pacote registra e o **ambiente de uma aplicação nova**: o `tests/bootstrap.php`
apaga do processo toda variável que não está no `phpunit.xml` (o container de
desenvolvimento do kit injeta o `.env` do starter; o CI não injeta nada), então
a suíte dá o mesmo resultado nos dois lugares.

```bash
composer update
vendor/bin/pest
vendor/bin/pint --test
```

Ela prova os membros e os convites (cada célula de quem mexe em quem, com a
linha `denied` nas recusas; token só em hash, uso único inclusive na corrida
de dois aceites, expiração, reenvio, revogação, e-mail diferente sem dado da
conta, sem enumeração, intervalo e limites, o aceite que cria a conta
verificada), a transferência só com o token de ação sensível e sempre com um
dono, a exclusão de conta, o aviso de chave órfã sem segredo, a trilha de cada
evento (e a falha fechada), os contratos de resposta e o
isolamento entre contas numa aplicação limpa (consulta sem conta
lança exceção; modo sistema explícito e desfeito mesmo com exceção; pessoa em
duas contas pela web e pela API; gravação só na conta atual; papéis; a regra do
dono no código e no índice; exclusão de pessoa; chave que continua valendo
depois que quem a criou sai; o contexto restaurado nos jobs da fila
`database`), a migração da 1.x (dados sintéticos, idempotente, ida e volta), a
trava de arquitetura do "esquecimento", que as proteções vêm do pacote (401 no envelope sem detalhe, limite
de falhas por chave e por IP, 403 de escopo e de operação de conta, 404 fora do
vínculo, 429 do limite por chave com outra chave do mesmo IP passando, chave
revogada/expirada/rotacionada/inativa e dono não verificado recusados, pepper
(vazio vale como ausente, peppers anteriores e legado migrados no primeiro uso,
avisos de produção),
opt-out com aviso), a fiação do provider (rotas, aliases, prioridade, envelope
sem duplicar, registro manual das rotas), o canal de log e o limitador vindos
do foundation com os do aplicativo vencendo, as traduções (com o aplicativo
vencendo), os apelidos e a arquitetura. Os testes com as telas, o painel e o
banco do aplicativo ficam na suíte do starter.
