<x-card>
    <form wire:submit="save" class="space-y-4">
        <div>
            <h2 class="text-xl">{{ __('reminders.settings.title') }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ __('reminders.settings.lead') }}</p>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <div>
                <x-input-label for="reminder-days" :value="__('reminders.settings.days')" />
                <x-text-input id="reminder-days" type="number" inputmode="numeric" min="1" :max="$max" wire:model="days" class="mt-1 block w-28" />
            </div>
            <x-primary-button wire:loading.attr="disabled" wire:target="save">{{ __('reminders.settings.save') }}</x-primary-button>
        </div>

        <p class="text-sm text-ink-muted">{{ __('reminders.settings.days_hint', ['max' => $max]) }}</p>
        <x-input-error :messages="$errors->get('days')" />

        @unless (config('mail.reminders_enabled'))
            <p class="text-sm text-ochre">{{ __('reminders.settings.disabled') }}</p>
        @endunless

        @if ($message)
            <p wire:key="saved-{{ md5($message) }}" class="rise-in text-sm text-moss" role="status">{{ $message }}</p>
        @endif
    </form>
</x-card>
