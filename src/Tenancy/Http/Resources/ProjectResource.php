<?php

declare(strict_types=1);

namespace Twstec\Kit\Accounts\Tenancy\Http\Resources;

use Illuminate\Http\Request;
use Twstec\Kit\Accounts\Tenancy\Models\Project;
use Twstec\Kit\Foundation\Http\Resources\BaseResource;

/**
 * Serialização do projeto (nunca expor o `id` interno).
 *
 * @mixin Project
 */
final class ProjectResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...$this->publicIdentifiers($this->resource),
            'name' => $this->name,
            'status' => $this->status->value,
            'created_at' => $this->isoTimestamp($this->created_at),
        ];
    }
}
