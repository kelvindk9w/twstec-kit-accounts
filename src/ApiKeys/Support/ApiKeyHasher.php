<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Support;

use Twstec\Kit\Accounts\ApiKeys\Support\Exceptions\MissingApiKeyPepperException;

/**
 * Hash da chave SECRETA (sk_) — só o hash vai ao banco; a secreta é exibida uma vez.
 *
 * DECISÃO: HMAC-SHA256 com pepper (padrão Sanctum, que usa SHA-256 puro).
 * Justificativa:
 * - A sk_ tem ~285 bits de entropia aleatória criptográfica. KDFs lentas
 *   (Argon2id/bcrypt) existem para proteger segredos de BAIXA entropia
 *   (senhas humanas) contra força bruta; para um segredo aleatório desse
 *   tamanho, força bruta é inviável e a KDF lenta só adicionaria latência
 *   obrigatória a CADA request da API.
 * - O pepper (segredo fora do banco, via .env) garante que um vazamento
 *   SOMENTE do banco não permita computar/verificar hashes.
 * - A comparação é SEMPRE timing-safe: hash_equals() — timing attack em
 *   comparação de segredos é vetor real.
 *
 * -----------------------------------------------------------------------------
 * PEPPER VAZIO NUNCA É PEPPER
 * -----------------------------------------------------------------------------
 * `API_KEYS_HASH_PEPPER=` (a linha presente, sem valor) não aciona o fallback
 * do `env()`: o Laravel devolve a string vazia, e o HMAC rodava com uma chave
 * de 0 caracteres — um hash que qualquer um com o banco verifica offline —,
 * em silêncio. Aqui, vazio ou só espaços vale como AUSENTE: o pepper passa a
 * ser a APP_KEY, exatamente como se a variável não existisse. A regra vale
 * mesmo quando o aplicativo publicou uma cópia antiga do config (que ainda
 * repassa o vazio), porque ela mora nesta classe — o único lugar que entrega o
 * pepper ao HMAC. Sem pepper dedicado e sem APP_KEY não há pepper possível, e
 * a classe recusa em vez de calcular um hash sem segredo.
 *
 * -----------------------------------------------------------------------------
 * PEPPERS ANTERIORES (no estilo do APP_PREVIOUS_KEYS)
 * -----------------------------------------------------------------------------
 * Trocar o pepper invalidaria de uma vez toda chave já emitida. Por isso a
 * verificação (check()) tenta o pepper atual e, depois, cada pepper anterior
 * declarado em `api_keys.previous_peppers`, mais o pepper VAZIO quando — e só
 * quando — `api_keys.accept_empty_pepper_legacy` está ligado (instalações que
 * emitiram chaves antes desta correção). Quem casa com um anterior recebe
 * `PepperMatch::Previous`/`EmptyLegacy`, e quem autentica (ResolveTenant)
 * regrava o hash com o pepper atual: a migração acontece no primeiro uso.
 *
 * TEMPO CONSTANTE: todos os candidatos são SEMPRE calculados e comparados, sem
 * parar no primeiro que casa. O custo depende só da configuração — nunca da
 * chave, nem de qual pepper casou —, então a chave pública inexistente (que é
 * verificada contra um hash fictício) leva o mesmo tempo que a existente.
 */
final class ApiKeyHasher
{
    /**
     * Hash determinístico da secreta (é o que vai para secret_hash). Usa
     * sempre e só o pepper ATUAL.
     */
    public function hash(string $plainSecret): string
    {
        return hash_hmac('sha256', $plainSecret, $this->currentPepper());
    }

    /**
     * Verificação timing-safe contra o pepper ATUAL (hash_equals — NUNCA ===).
     * Para a autenticação, que também aceita peppers anteriores, ver check().
     */
    public function verify(string $plainSecret, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($plainSecret));
    }

    /**
     * Com qual pepper a secreta confere: o atual, um anterior, o vazio legado
     * (só com a flag ligada) ou nenhum.
     *
     * Todos os candidatos são calculados e comparados com hash_equals, sem
     * interrupção: o tempo gasto não diz se nem com qual pepper casou.
     */
    public function check(string $plainSecret, string $storedHash): PepperMatch
    {
        $result = PepperMatch::None;

        foreach ($this->candidates() as [$pepper, $kind]) {
            $matches = hash_equals($storedHash, hash_hmac('sha256', $plainSecret, $pepper));

            // Sem saída antecipada: o laço corre inteiro em qualquer caso. O
            // primeiro que casa vence (o atual vem antes dos anteriores).
            if ($matches && $result === PepperMatch::None) {
                $result = $kind;
            }
        }

        return $result;
    }

    /**
     * O pepper atual: API_KEYS_HASH_PEPPER quando tem conteúdo; a APP_KEY
     * quando ele está ausente, vazio ou só com espaços. Nunca vazio.
     *
     * @throws MissingApiKeyPepperException
     */
    public function currentPepper(): string
    {
        $configured = config('api_keys.hash_pepper');

        if (is_string($configured) && trim($configured) !== '') {
            return $configured;
        }

        $applicationKey = config('app.key');

        if (is_string($applicationKey) && trim($applicationKey) !== '') {
            return $applicationKey;
        }

        throw MissingApiKeyPepperException::make();
    }

    /**
     * Peppers anteriores declarados, sem vazios, sem repetição e sem o atual
     * (que já é o primeiro candidato). O vazio nunca entra por aqui — só pela
     * flag explícita do legado.
     *
     * @return list<string>
     */
    public function previousPeppers(): array
    {
        $current = $this->currentPepper();
        $previous = [];

        foreach (self::normalizeList(config('api_keys.previous_peppers', [])) as $pepper) {
            if ($pepper !== $current && ! in_array($pepper, $previous, true)) {
                $previous[] = $pepper;
            }
        }

        return $previous;
    }

    /**
     * A flag do pepper vazio legado está ligada?
     */
    public function acceptsEmptyPepperLegacy(): bool
    {
        return filter_var(config('api_keys.accept_empty_pepper_legacy', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Lista de peppers vinda da configuração: aceita array ou texto separado
     * por vírgula (o formato do .env); descarta itens vazios ou só com espaços.
     *
     * @return list<string>
     */
    public static function normalizeList(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(
            array_map(static fn (mixed $item): string => trim((string) $item), $items),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Candidatos na ordem de preferência: atual, anteriores, vazio legado.
     *
     * @return list<array{0: string, 1: PepperMatch}>
     */
    private function candidates(): array
    {
        $candidates = [[$this->currentPepper(), PepperMatch::Current]];

        foreach ($this->previousPeppers() as $pepper) {
            $candidates[] = [$pepper, PepperMatch::Previous];
        }

        if ($this->acceptsEmptyPepperLegacy()) {
            $candidates[] = ['', PepperMatch::EmptyLegacy];
        }

        return $candidates;
    }
}
