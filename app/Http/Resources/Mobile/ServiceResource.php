<?php

declare(strict_types=1);

namespace App\Http\Resources\Mobile;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'slug' => $this->slug,
            'categorySlug' => $this->category->slug,
            'name' => $this->name,
            'minutes' => $this->default_duration_minutes,
            'requiresDetails' => $this->requires_details,
            'active' => $this->active,
            'documents' => $this->documents->pluck('name')->values()->all(),
        ];
    }
}
