<form wire:submit="save" class="space-y-6">
    <x-card class="space-y-5">
        <fieldset>
            <legend class="text-sm font-medium text-ink">{{ __('clients.fields.type') }}</legend>
            <div class="mt-2 flex flex-wrap gap-4">
                @foreach (App\Domain\Clients\Enums\ClientType::cases() as $type)
                    <label class="inline-flex min-h-11 items-center gap-2">
                        <input type="radio" wire:model.live="form.type" value="{{ $type->value }}" class="size-5 border-line bg-surface text-primary focus:ring-primary">
                        {{ $type->label() }}
                    </label>
                @endforeach
            </div>
        </fieldset>

        <div>
            <x-input-label for="name" :value="$form->isOrganization() ? __('clients.fields.name_organization') : __('clients.fields.name_individual')" />
            <x-text-input id="name" wire:model="form.name" class="mt-1 block w-full" required />
            <x-input-error :messages="$errors->get('form.name')" class="mt-2" />
        </div>

        @if ($form->isOrganization())
            <div>
                <x-input-label for="trade_name" :value="__('clients.fields.trade_name')" />
                <x-text-input id="trade_name" wire:model="form.trade_name" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('form.trade_name')" class="mt-2" />
            </div>
        @endif

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="phone" :value="__('clients.fields.phone')" />
                <x-text-input id="phone" type="tel" inputmode="tel" wire:model="form.phone" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('form.phone')" class="mt-2" />
            </div>
            <div>
                <x-input-label for="email" :value="__('clients.fields.email')" />
                <x-text-input id="email" type="email" wire:model="form.email" class="mt-1 block w-full" />
                <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
            </div>
        </div>

        <div>
            <x-input-label for="document" :value="__('clients.fields.document')" />
            <x-text-input id="document" wire:model="form.document" :inputmode="$form->isOrganization() ? 'text' : 'numeric'" autocapitalize="characters" class="mt-1 block w-full sm:w-1/2" />
            <x-input-error :messages="$errors->get('form.document')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="notes" :value="__('clients.fields.notes')" />
            <x-textarea-input id="notes" wire:model="form.notes" class="mt-1 block w-full" />
            <x-input-error :messages="$errors->get('form.notes')" class="mt-2" />
        </div>

        <label class="flex min-h-11 items-start gap-3">
            <x-checkbox-input wire:model="form.accepts_reminders" class="mt-0.5" />
            <span>
                {{ __('clients.fields.accepts_reminders') }}
                <span class="block text-sm text-ink-muted">{{ __('clients.fields.accepts_reminders_hint') }}</span>
            </span>
        </label>

        @if ($form->client)
            <label class="flex min-h-11 items-center gap-3">
                <x-checkbox-input wire:model="form.active" />
                {{ __('clients.fields.active') }}
            </label>
        @endif
    </x-card>

    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <x-button-link variant="secondary" :href="$form->client ? route('clients.show', $form->client) : route('clients.index')">{{ __('clients.cancel') }}</x-button-link>
        <x-primary-button wire:loading.attr="disabled">{{ __('clients.save') }}</x-primary-button>
    </div>
</form>
