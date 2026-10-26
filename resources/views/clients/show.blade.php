<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6 px-4 py-10 sm:px-6 lg:px-8">
        <x-flash-status />

        <x-page-header :title="$client->name" :subtitle="collect([$client->type->label(), $client->trade_name, auth()->user()->canWrite() ? $client->document : null])->filter()->implode(' · ')">
            <x-slot name="actions">
                @can('create', App\Models\Appointment::class)
                    @if ($client->active)
                        <x-button-link :href="route('appointments.create', ['atendido' => $client->id])">{{ __('clients.show.schedule') }}</x-button-link>
                    @endif
                @endcan
                @can('update', $client)
                    <x-button-link variant="secondary" :href="route('clients.edit', $client)">{{ __('clients.show.edit') }}</x-button-link>
                @endcan
            </x-slot>
        </x-page-header>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_2fr]">
            <x-card class="space-y-4 self-start">
                <h2 class="text-xl">{{ __('clients.show.contact') }}</h2>
                <dl class="space-y-3 text-sm">
                    @if ($client->phone)
                        <div><dt class="text-ink-muted">{{ __('clients.fields.phone') }}</dt><dd class="text-ink">{{ $client->phone }}</dd></div>
                    @endif
                    @if ($client->email)
                        <div><dt class="text-ink-muted">{{ __('clients.fields.email') }}</dt><dd class="break-all text-ink">{{ $client->email }}</dd></div>
                    @endif
                    <div><dd class="text-ink-muted">{{ $client->accepts_reminders ? __('clients.show.reminders_on') : __('clients.show.reminders_off') }}</dd></div>
                    @if ($client->notes && auth()->user()->canWrite())
                        <div><dt class="text-ink-muted">{{ __('clients.fields.notes') }}</dt><dd class="whitespace-pre-line text-ink">{{ $client->notes }}</dd></div>
                    @endif
                </dl>
                <x-contact-links :client="$client" />
                @unless ($client->active)
                    <p class="rounded-soft bg-sunken px-3 py-2 text-sm text-ink-muted">{{ __('clients.index.inactive') }}</p>
                @endunless
            </x-card>

            <div class="space-y-6">
                <x-card>
                    <h2 class="text-xl">{{ __('clients.show.upcoming') }}</h2>
                    <div class="mt-4 -mx-3 space-y-1">
                        @forelse ($upcoming as $appointment)
                            <x-appointment-row :appointment="$appointment" :show-client="false" show-date />
                        @empty
                            <p class="px-3 text-sm text-ink-muted">{{ __('clients.show.upcoming_empty') }}</p>
                        @endforelse
                    </div>
                </x-card>

                <x-card>
                    <h2 class="text-xl">{{ __('clients.show.past') }}</h2>
                    <div class="mt-4 -mx-3 space-y-1">
                        @forelse ($past as $appointment)
                            <x-appointment-row :appointment="$appointment" :show-client="false" show-date />
                        @empty
                            <p class="px-3 text-sm text-ink-muted">{{ __('clients.show.past_empty') }}</p>
                        @endforelse
                    </div>
                </x-card>
            </div>
        </div>
    </div>
</x-app-layout>
