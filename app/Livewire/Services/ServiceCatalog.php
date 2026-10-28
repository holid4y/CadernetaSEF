<?php

declare(strict_types=1);

namespace App\Livewire\Services;

use App\Domain\Services\Actions\SaveService;
use App\Models\Service;
use App\Models\ServiceCategory;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ServiceCatalog extends Component
{
    #[Locked]
    public ?int $service_id = null;

    public bool $editing = false;

    public ?int $service_category_id = null;

    public string $name = '';

    public string $description = '';

    public int $default_duration_minutes = 60;

    public bool $requires_details = false;

    public bool $active = true;

    /** @var list<string> */
    public array $documents = [];

    public string $new_document = '';

    public ?string $message = null;

    public function mount(): void
    {
        $this->authorize('create', Service::class);
    }

    /**
     * @return Collection<int, ServiceCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return ServiceCategory::query()
            ->with(['services' => fn ($query) => $query->withCount('documents')])
            ->orderBy('sort_order')
            ->get();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->service_category_id = $this->categories->first()?->id;
        $this->editing = true;
    }

    public function edit(int $serviceId): void
    {
        $service = Service::query()->with('documents')->findOrFail($serviceId);

        $this->resetForm();
        $this->service_id = $service->id;
        $this->service_category_id = $service->service_category_id;
        $this->name = $service->name;
        $this->description = (string) $service->description;
        $this->default_duration_minutes = $service->default_duration_minutes;
        $this->requires_details = $service->requires_details;
        $this->active = $service->active;
        $this->documents = $service->documents->pluck('name')->all();
        $this->editing = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function addDocument(): void
    {
        $this->validateOnly('new_document', ['new_document' => ['required', 'string', 'max:200']]);

        $this->documents[] = $this->new_document;
        $this->new_document = '';
    }

    public function removeDocument(int $index): void
    {
        unset($this->documents[$index]);
        $this->documents = array_values($this->documents);
    }

    public function moveDocument(int $index, int $offset): void
    {
        $target = $index + $offset;

        if (! isset($this->documents[$index], $this->documents[$target])) {
            return;
        }

        [$this->documents[$index], $this->documents[$target]] = [$this->documents[$target], $this->documents[$index]];
    }

    public function save(SaveService $save): void
    {
        $service = $this->service_id ? Service::query()->findOrFail($this->service_id) : null;

        $this->authorize($service ? 'update' : 'create', $service ?? Service::class);

        $this->validate([
            'service_category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'name' => ['required', 'string', 'max:120', $this->nameIsFree(...)],
            'description' => ['nullable', 'string', 'max:1000'],
            'default_duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'requires_details' => ['boolean'],
            'active' => ['boolean'],
            'documents' => ['array', 'max:30'],
            'documents.*' => ['string', 'max:200'],
        ]);

        $saved = $save->handle($service, [
            'service_category_id' => $this->service_category_id,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'default_duration_minutes' => $this->default_duration_minutes,
            'requires_details' => $this->requires_details,
            'active' => $this->active,
        ], $this->documents);

        $this->message = __($service ? 'services.flash.updated' : 'services.flash.created', ['name' => $saved->name]);
        $this->resetForm();
        unset($this->categories);
    }

    public function render(): View
    {
        return view('livewire.services.service-catalog');
    }

    // The booking form lists services by name under their category, where two
    // equal names could not be told apart.
    private function nameIsFree(string $attribute, mixed $value, Closure $fail): void
    {
        $taken = Service::query()
            ->where('service_category_id', $this->service_category_id)
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
            ->when($this->service_id, fn ($query, int $id) => $query->whereKeyNot($id))
            ->exists();

        if ($taken) {
            $fail(__('services.errors.name_taken'));
        }
    }

    private function resetForm(): void
    {
        $this->reset([
            'service_id', 'editing', 'service_category_id', 'name', 'description',
            'default_duration_minutes', 'requires_details', 'active', 'documents', 'new_document',
        ]);
        $this->resetValidation();
    }
}
