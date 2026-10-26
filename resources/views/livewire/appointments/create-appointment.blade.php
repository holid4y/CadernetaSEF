<form wire:submit="save" class="space-y-6">
    <x-card class="space-y-4">
        <h2 class="text-2xl">{{ __('appointments.create.step_client') }}</h2>

        @include(match (true) {
            $this->client !== null => 'livewire.appointments.client-step.selected',
            $creating_client => 'livewire.appointments.client-step.new-client',
            default => 'livewire.appointments.client-step.search',
        })
    </x-card>

    <x-card class="space-y-4">
        <h2 class="text-2xl">{{ __('appointments.create.step_service') }}</h2>

        <div>
            <x-input-label for="service_id" :value="__('appointments.fields.service')" class="sr-only" />
            <x-select-input id="service_id" wire:model.live="service_id" class="block w-full">
                <option value="">{{ __('appointments.create.choose_service') }}</option>
                @foreach ($this->categories as $category)
                    <optgroup label="{{ $category->name }}">
                        @foreach ($category->services as $service)
                            <option value="{{ $service->id }}">{{ $service->name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </x-select-input>
            <x-input-error :messages="$errors->get('service_id')" class="mt-2" />
        </div>

        @if ($this->service?->requires_details)
            <div>
                <x-input-label for="service_details" :value="__('appointments.fields.service_details')" />
                <x-textarea-input id="service_details" wire:model="service_details" class="mt-1 block w-full"
                                  :placeholder="__('appointments.create.details_placeholder')" />
                <x-input-error :messages="$errors->get('service_details')" class="mt-2" />
            </div>
        @endif

        @if ($this->service)
            <div class="rounded-soft bg-sunken px-4 py-3 text-sm">
                @if ($this->service->documents->isEmpty())
                    <p class="text-ink-muted">{{ __('appointments.create.checklist_empty') }}</p>
                @else
                    <p class="font-medium text-ink">{{ __('appointments.create.checklist_preview') }}</p>
                    <ul class="mt-2 list-disc space-y-1 ps-5 text-ink-muted">
                        @foreach ($this->service->documents as $document)
                            <li>{{ $document->name }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </x-card>

    <x-card class="space-y-4">
        <h2 class="text-2xl">{{ __('appointments.create.step_when') }}</h2>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <x-input-label for="date" :value="__('appointments.fields.date')" />
                <x-text-input id="date" type="date" wire:model="date" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('date')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="start_time" :value="__('appointments.fields.start_time')" />
                <x-text-input id="start_time" type="time" step="900" wire:model.live.blur="start_time" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('start_time')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="end_time" :value="__('appointments.fields.end_time')" />
                <x-text-input id="end_time" type="time" step="900" wire:model="end_time" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('end_time')" class="mt-2" />
            </div>
        </div>

        <x-input-error :messages="array_merge($errors->get('starts_at'), $errors->get('ends_at'))" />

        <div>
            <x-input-label for="responsible_user_id" :value="__('appointments.fields.responsible')" />
            <x-select-input id="responsible_user_id" wire:model="responsible_user_id" class="mt-1 block w-full">
                <option value="">—</option>
                @foreach ($this->responsibles as $responsible)
                    <option value="{{ $responsible->id }}">{{ $responsible->name }}</option>
                @endforeach
            </x-select-input>
            <x-input-error :messages="$errors->get('responsible_user_id')" class="mt-2" />
        </div>
    </x-card>

    <x-card class="space-y-4">
        <h2 class="text-2xl">{{ __('appointments.create.step_details') }}</h2>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-[1fr_2fr]">
            <div>
                <x-input-label for="location_type" :value="__('appointments.fields.location_type')" />
                <x-select-input id="location_type" wire:model="location_type" class="mt-1 block w-full">
                    @foreach ($locationTypes as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </x-select-input>
            </div>
            <div>
                <x-input-label for="location" :value="__('appointments.fields.location')" />
                <x-text-input id="location" wire:model="location" class="mt-1 block w-full" :placeholder="__('appointments.create.location_placeholder')" />
                <x-input-error :messages="$errors->get('location')" class="mt-2" />
            </div>
        </div>

        <div>
            <x-input-label for="notes" :value="__('appointments.fields.notes')" />
            <x-textarea-input id="notes" wire:model="notes" class="mt-1 block w-full" :placeholder="__('appointments.create.notes_placeholder')" />
            <x-input-error :messages="$errors->get('notes')" class="mt-2" />
        </div>
    </x-card>

    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <x-button-link variant="secondary" :href="route('agenda')">{{ __('appointments.create.cancel') }}</x-button-link>
        <x-primary-button wire:loading.attr="disabled" wire:target="save">{{ __('appointments.create.submit') }}</x-primary-button>
    </div>
</form>
