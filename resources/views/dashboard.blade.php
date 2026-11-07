<x-app-layout>
    <div class="mx-auto max-w-6xl space-y-8 px-4 py-10 sm:px-6 lg:px-8">
        <x-page-header
            :title="__('dashboard.greeting', ['name' => Str::before(Auth::user()->name, ' ')])"
            :subtitle="Str::ucfirst(__('dashboard.today', ['date' => App\Support\DisplayTimezone::toLocal(now())->translatedFormat('l, j \d\e F')]))">
            <x-slot name="actions">
                <x-button-link variant="secondary" :href="route('agenda')">{{ __('dashboard.open_agenda') }}</x-button-link>
                @can('create', App\Models\Appointment::class)
                    <x-button-link :href="route('appointments.create')">{{ __('dashboard.new_appointment') }}</x-button-link>
                @endcan
            </x-slot>
        </x-page-header>

        <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
            <x-stat-tile tone="primary" :value="$stats['today']" :label="__('dashboard.stats.today')" />
            <x-stat-tile tone="moss" :value="$stats['week']" :label="__('dashboard.stats.week')" style="animation-delay: 60ms" />
            <x-stat-tile tone="ochre" :value="$stats['confirmed']" :label="__('dashboard.stats.confirmed')" style="animation-delay: 120ms" />
            <x-stat-tile tone="clay" :value="$stats['completed']" :label="__('dashboard.stats.completed')" style="animation-delay: 180ms" />
        </div>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-card class="rise-in">
                <h2 class="text-2xl">{{ __('dashboard.today_title') }}</h2>
                <div class="-mx-3 mt-4 space-y-1">
                    @forelse ($today as $appointment)
                        <x-appointment-row :appointment="$appointment" />
                    @empty
                        <p class="px-3 text-ink-muted">{{ __('dashboard.today_empty') }}</p>
                    @endforelse
                </div>
            </x-card>

            <x-card class="rise-in">
                <h2 class="text-2xl">{{ __('dashboard.upcoming_title') }}</h2>
                <div class="-mx-3 mt-4 space-y-1">
                    @forelse ($upcoming as $appointment)
                        <x-appointment-row :appointment="$appointment" show-date />
                    @empty
                        <p class="px-3 text-ink-muted">{{ __('dashboard.upcoming_empty') }}</p>
                    @endforelse
                </div>
            </x-card>
        </div>
    </div>
</x-app-layout>
