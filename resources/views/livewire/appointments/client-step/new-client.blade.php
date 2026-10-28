<div class="space-y-4 rounded-soft border border-line p-4">
    <div class="flex flex-wrap gap-4">
        @foreach (App\Domain\Clients\Enums\ClientType::cases() as $type)
            <label class="inline-flex min-h-11 items-center gap-2 text-sm">
                <x-radio-input wire:model.live="new_client.type" value="{{ $type->value }}" />
                {{ $type->label() }}
            </label>
        @endforeach
    </div>

    <div>
        <x-input-label for="new_client_name" :value="$new_client->isOrganization() ? __('clients.fields.name_organization') : __('clients.fields.name_individual')" />
        <x-text-input id="new_client_name" wire:model="new_client.name" class="mt-1 block w-full" />
        <x-input-error :messages="$errors->get('new_client.name')" class="mt-2" />
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <x-input-label for="new_client_phone" :value="__('clients.fields.phone')" />
            <x-text-input id="new_client_phone" wire:model="new_client.phone" type="tel" inputmode="tel" class="mt-1 block w-full" />
            <x-input-error :messages="$errors->get('new_client.phone')" class="mt-2" />
        </div>
        <div>
            <x-input-label for="new_client_email" :value="__('clients.fields.email')" />
            <x-text-input id="new_client_email" wire:model="new_client.email" type="email" class="mt-1 block w-full" />
            <x-input-error :messages="$errors->get('new_client.email')" class="mt-2" />
        </div>
    </div>

    <label class="flex min-h-11 items-start gap-3 text-sm">
        <x-checkbox-input wire:model="new_client.accepts_reminders" class="mt-0.5" />
        <span>
            {{ __('clients.fields.accepts_reminders') }}
            <span class="block text-ink-muted">{{ __('clients.fields.accepts_reminders_hint') }}</span>
        </span>
    </label>

    <div class="flex flex-wrap gap-3">
        <x-primary-button type="button" wire:click="saveNewClient">{{ __('clients.save') }}</x-primary-button>
        <x-secondary-button wire:click="$set('creating_client', false)">{{ __('clients.cancel') }}</x-secondary-button>
    </div>
</div>
