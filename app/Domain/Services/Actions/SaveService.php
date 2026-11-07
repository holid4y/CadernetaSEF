<?php

declare(strict_types=1);

namespace App\Domain\Services\Actions;

use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SaveService
{
    /**
     * @param  array{
     *     service_category_id: int,
     *     name: string,
     *     description: ?string,
     *     default_duration_minutes: int,
     *     requires_details: bool,
     *     active: bool,
     * }  $attributes
     * @param  list<string>  $documents  in the order they are asked for
     */
    public function handle(?Service $service, array $attributes, array $documents): Service
    {
        return DB::transaction(function () use ($service, $attributes, $documents): Service {
            $service ??= new Service(['slug' => $this->uniqueSlug($attributes['name'])]);
            $service->fill($attributes)->save();

            // Booked appointments keep their own copy, so the template is rebuilt freely.
            $service->documents()->delete();
            $service->documents()->createMany(
                $this->clean($documents)->map(fn (string $name, int $position): array => [
                    'name' => $name,
                    'sort_order' => $position,
                ]),
            );

            return $service;
        });
    }

    /**
     * @param  list<string>  $documents
     * @return Collection<int, string>
     */
    private function clean(array $documents): Collection
    {
        return collect($documents)
            ->map(fn (string $name): string => Str::squish($name))
            ->filter()
            ->unique(fn (string $name): string => Str::lower($name))
            ->values();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;

        for ($suffix = 2; Service::withTrashed()->where('slug', $slug)->exists(); $suffix++) {
            $slug = "{$base}-{$suffix}";
        }

        return $slug;
    }
}
