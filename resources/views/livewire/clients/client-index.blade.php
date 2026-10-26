<div class="space-y-4">
    <div class="flex flex-col gap-3 sm:flex-row">
        <x-text-input type="search" data-client-search wire:model.live.debounce.300ms="search" :placeholder="__('clients.index.search')"
                      :aria-label="__('clients.index.search')" class="w-full sm:flex-1" />
        <x-select-input data-client-type wire:model.live="type" :aria-label="__('clients.fields.type')">
            <option value="">{{ __('clients.index.all_types') }}</option>
            @foreach (App\Domain\Clients\Enums\ClientType::cases() as $clientType)
                <option value="{{ $clientType->value }}">{{ $clientType->label() }}</option>
            @endforeach
        </x-select-input>
    </div>

    <x-card padding="tight">
        <ul class="space-y-1">
            @forelse ($clients as $client)
                <li wire:key="client-{{ $client->id }}" data-client-row="{{ $client->type->value }}">
                    <x-disclosure :id="'client-'.$client->id.'-details'" :label="__('clients.index.details_of', ['name' => $client->name])">
                        <x-slot name="summary">
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2">
                                    <span class="truncate font-medium text-ink">{{ $client->name }}</span>
                                    @unless ($client->active)
                                        <span class="shrink-0 rounded-full bg-bark-soft px-2 py-0.5 text-xs text-bark">{{ __('clients.index.inactive') }}</span>
                                    @endunless
                                </span>
                                <span class="block truncate text-sm text-ink-muted">{{ collect([$client->type->label(), $client->phone, $client->email])->filter()->implode(' · ') }}</span>
                                <span class="mt-1 block text-xs text-ink-muted sm:hidden">{{ trans_choice('clients.index.appointments_count', $client->appointments_count) }}</span>
                            </span>
                            <span class="hidden shrink-0 pt-0.5 text-sm text-ink-muted sm:block">{{ trans_choice('clients.index.appointments_count', $client->appointments_count) }}</span>
                        </x-slot>

                        <div class="space-y-4 border-t border-line pt-4 text-sm">
                            <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                @if ($client->phone || $client->email)
                                    <div>
                                        <dt class="text-ink-muted">{{ __('clients.show.contact') }}</dt>
                                        <dd class="flex flex-wrap gap-x-3">
                                            @if ($client->phone)
                                                <a href="tel:{{ preg_replace('/\D/', '', $client->phone) }}" class="text-primary underline underline-offset-4">{{ $client->phone }}</a>
                                            @endif
                                            @if ($client->email)
                                                <a href="mailto:{{ $client->email }}" class="break-all text-primary underline underline-offset-4">{{ $client->email }}</a>
                                            @endif
                                        </dd>
                                    </div>
                                @endif
                                @if (($client->document && auth()->user()->canWrite()) || $client->trade_name)
                                    <div>
                                        <dt class="text-ink-muted">{{ $client->isOrganization() ? __('clients.fields.trade_name') : __('clients.fields.document') }}</dt>
                                        <dd class="text-ink">{{ collect([$client->trade_name, auth()->user()->canWrite() ? $client->document : null])->filter()->implode(' · ') }}</dd>
                                    </div>
                                @endif
                                <div>
                                    <dt class="text-ink-muted">{{ __('clients.index.next_appointment') }}</dt>
                                    <dd class="text-ink">
                                        @if ($client->nextAppointment)
                                            <a href="{{ route('appointments.show', $client->nextAppointment) }}" wire:navigate class="text-primary underline underline-offset-4 first-letter:uppercase">
                                                {{ App\Support\DisplayTimezone::toLocal($client->nextAppointment->starts_at)->translatedFormat('D, d/m · H:i') }}
                                            </a>
                                            · {{ $client->nextAppointment->service->name }}
                                        @else
                                            <span class="text-ink-muted">{{ __('clients.show.upcoming_empty') }}</span>
                                        @endif
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-ink-muted">{{ __('clients.index.recent_appointments') }}</dt>
                                    <dd>
                                        <ul class="space-y-0.5">
                                            @forelse ($client->recentAppointments as $appointment)
                                                <li class="text-ink">
                                                    <span class="text-ink-muted">{{ App\Support\DisplayTimezone::toLocal($appointment->starts_at)->format('d/m/Y') }}</span>
                                                    · {{ $appointment->service->name }}
                                                </li>
                                            @empty
                                                <li class="text-ink-muted">{{ __('clients.show.past_empty') }}</li>
                                            @endforelse
                                        </ul>
                                    </dd>
                                </div>
                            </dl>

                            <div class="flex flex-wrap gap-2">
                                <x-button-link size="sm" :href="route('clients.show', $client)">{{ __('clients.index.open') }}</x-button-link>
                                @if ($client->active)
                                    @can('create', App\Models\Appointment::class)
                                        <x-button-link size="sm" variant="secondary" :href="route('appointments.create', ['atendido' => $client->id])">{{ __('clients.show.schedule') }}</x-button-link>
                                    @endcan
                                @endif
                                @can('update', $client)
                                    <x-button-link size="sm" variant="secondary" :href="route('clients.edit', $client)">{{ __('clients.show.edit') }}</x-button-link>
                                @endcan
                            </div>
                        </div>
                    </x-disclosure>
                </li>
            @empty
                <li class="px-4 py-10 text-center text-ink-muted">{{ __('clients.index.empty') }}</li>
            @endforelse
        </ul>
    </x-card>

    @if ($clients->hasPages())
        <nav class="flex items-center justify-between">
            <x-secondary-button wire:click="previousPage" :disabled="$clients->onFirstPage()">{{ __('clients.index.previous') }}</x-secondary-button>
            <x-secondary-button wire:click="nextPage" :disabled="! $clients->hasMorePages()">{{ __('clients.index.next') }}</x-secondary-button>
        </nav>
    @endif
</div>
