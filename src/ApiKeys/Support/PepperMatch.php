<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\ApiKeys\Support;

/**
 * Resultado da verificação da secreta contra os peppers aceitos
 * (ApiKeyHasher::check()).
 */
enum PepperMatch: string
{
    /** Confere com o pepper atual: nada a fazer. */
    case Current = 'current';

    /** Confere com um pepper anterior (api_keys.previous_peppers). */
    case Previous = 'previous_pepper';

    /** Confere com o pepper VAZIO, aceito só com a flag do legado ligada. */
    case EmptyLegacy = 'empty_pepper_legacy';

    /** Não confere com nenhum. */
    case None = 'none';

    public function matched(): bool
    {
        return $this !== self::None;
    }

    /**
     * O hash gravado precisa ser regravado com o pepper atual.
     */
    public function needsRehash(): bool
    {
        return $this === self::Previous || $this === self::EmptyLegacy;
    }
}
