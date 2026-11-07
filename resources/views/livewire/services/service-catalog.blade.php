<div class="space-y-6">
    @if ($message)
        <div wire:key="message-{{ md5($message) }}" class="rise-in rounded-soft bg-moss-soft px-4 py-3 text-sm text-moss" role="status">{{ $message }}</div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_1.1fr] lg:items-start">
        <x-card padding="tight">
            <div class="flex items-center justify-between gap-4 px-3 pt-3 pb-2">
                <h2 class="text-xl">{{ __('services.title') }}</h2>
                <x-secondary-button wire:click="create">+ {{ __('services.new') }}</x-secondary-button>
            </div>

            @foreach ($this->categories as $category)
                <section wire:key="category-{{ $category->id }}" class="px-1 py-2">
                    <h3 class="px-2 py-1 text-sm font-semibold text-ink-muted">{{ $category->name }}</h3>
                    <ul>
                        @foreach ($category->services as $service)
                            <li wire:key="service-{{ $service->id }}">
                                <button type="button" wire:click="edit({{ $service->id }})"
                                        @class([
                                            'press flex w-full items-center justify-between gap-3 rounded-soft px-3 py-2.5 text-start',
                                            'bg-primary-soft' => $service_id === $service->id,
                                            'hover:bg-sunken' => $service_id !== $service->id,
                                        ])>
                                    <span class="min-w-0">
                                        <span @class(['block truncate', 'text-ink' => $service->active, 'text-ink-muted line-through' => ! $service->active])>{{ $service->name }}</span>
                                        <span class="text-xs text-ink-muted">{{ trans_choice('services.documents_count', $service->documents_count) }}</span>
                                    </span>
                                    @unless ($service->active)
                                        <span class="shrink-0 rounded-full bg-bark-soft px-2 py-0.5 text-xs text-bark">{{ __('services.inactive') }}</span>
                                    @endunless
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </x-card>

        <x-card class="lg:sticky lg:top-24">
            @if ($editing)
                <form wire:submit="save" class="space-y-5">
                    <h2 class="text-2xl">{{ $service_id ? __('services.edit_title') : __('services.new_title') }}</h2>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="service_category_id" :value="__('services.fields.category')" />
                            <x-select-input id="service_category_id" wire:model="service_category_id" class="mt-1 block w-full">
                                @foreach ($this->categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </x-select-input>
                            <x-input-error :messages="$errors->get('service_category_id')" class="mt-2" />
                        </div>
                        <div>
                            <x-input-label for="default_duration_minutes" :value="__('services.fields.duration')" />
                            <x-text-input id="default_duration_minutes" type="number" min="15" max="480" step="15" wire:model="default_duration_minutes" class="mt-1 block w-full" />
                            <x-input-error :messages="$errors->get('default_duration_minutes')" class="mt-2" />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="name" :value="__('services.fields.name')" />
                        <x-text-input id="name" wire:model="name" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('name')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="description" :value="__('services.fields.description')" />
                        <x-textarea-input id="description" wire:model="description" rows="2" class="mt-1 block w-full" />
                        <x-input-error :messages="$errors->get('description')" class="mt-2" />
                    </div>

                    <div class="space-y-1">
                        <label class="flex min-h-11 items-center gap-3 text-sm">
                            <x-checkbox-input wire:model="requires_details" />
                            {{ __('services.fields.requires_details') }}
                        </label>
                        <label class="flex min-h-11 items-center gap-3 text-sm">
                            <x-checkbox-input wire:model="active" />
                            {{ __('services.fields.active') }}
                        </label>
                    </div>

                    <fieldset class="border-t border-line pt-5">
                        <legend class="float-left w-full text-sm font-medium text-ink">{{ __('services.fields.documents') }}</legend>
                        <p class="clear-left pt-1 text-xs text-ink-muted">{{ __('services.documents_hint') }}</p>

                        <ol class="mt-3 space-y-1">
                            @foreach ($documents as $index => $document)
                                <li wire:key="document-{{ $index }}-{{ md5($document) }}" class="flex items-center gap-2 rounded-soft bg-sunken/60 py-1 ps-3 pe-1">
                                    <span class="w-5 shrink-0 text-xs text-ink-muted">{{ $index + 1 }}.</span>
                                    <x-text-input wire:model="documents.{{ $index }}" :aria-label="__('services.fields.documents').' '.($index + 1)" class="min-h-9! flex-1 border-transparent! bg-transparent! py-1 text-sm focus:bg-surface!" />
                                    <button type="button" wire:click="moveDocument({{ $index }}, -1)" @disabled($loop->first)
                                            class="press inline-flex size-10 items-center justify-center rounded-full text-ink-muted hover:bg-surface hover:text-ink disabled:opacity-30"
                                            aria-label="{{ __('services.move_up', ['name' => $document]) }}"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5m-6 6 6-6 6 6" /></svg></button>
                                    <button type="button" wire:click="moveDocument({{ $index }}, 1)" @disabled($loop->last)
                                            class="press inline-flex size-10 items-center justify-center rounded-full text-ink-muted hover:bg-surface hover:text-ink disabled:opacity-30"
                                            aria-label="{{ __('services.move_down', ['name' => $document]) }}"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14m6-6-6 6-6-6" /></svg></button>
                                    <button type="button" wire:click="removeDocument({{ $index }})"
                                            class="press inline-flex size-10 items-center justify-center rounded-full text-danger hover:bg-danger-soft"
                                            aria-label="{{ __('services.remove', ['name' => $document]) }}"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" /></svg></button>
                                </li>
                            @endforeach
                        </ol>

                        <div class="mt-3 flex gap-2">
                            <x-text-input wire:model="new_document" wire:keydown.enter.prevent="addDocument" :placeholder="__('services.fields.new_document')"
                                          :aria-label="__('services.fields.new_document')" class="flex-1" />
                            <x-secondary-button wire:click="addDocument">{{ __('services.add_document') }}</x-secondary-button>
                        </div>
                        <x-input-error :messages="array_merge($errors->get('new_document'), $errors->get('documents'), Arr::flatten($errors->get('documents.*')))" class="mt-2" />
                    </fieldset>

                    <x-input-error :messages="$errors->get('service')" />

                    <div class="flex flex-col-reverse gap-3 border-t border-line pt-5 sm:flex-row sm:justify-end">
                        @if ($service_id)
                            <button type="button" wire:click="delete" wire:confirm="{{ __('services.confirm_delete') }}" wire:loading.attr="disabled" wire:target="delete"
                                    class="press inline-flex min-h-11 items-center justify-center rounded-full px-5 text-sm font-semibold text-danger hover:bg-danger-soft sm:me-auto">
                                {{ __('services.delete') }}
                            </button>
                        @endif
                        <x-secondary-button wire:click="cancel">{{ __('services.cancel') }}</x-secondary-button>
                        <x-primary-button wire:loading.attr="disabled" wire:target="save">{{ __('services.save') }}</x-primary-button>
                    </div>
                </form>
            @else
                <p class="py-10 text-center text-ink-muted">{{ __('services.empty_hint') }}</p>
            @endif
        </x-card>
    </div>
</div>
