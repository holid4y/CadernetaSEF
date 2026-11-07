<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-6 px-4 py-10 sm:px-6 lg:px-8">
        <x-page-header :title="__('clients.index.title')" :subtitle="__('clients.index.subtitle')">
            @can('create', App\Models\Client::class)
                <x-slot name="actions">
                    <x-button-link :href="route('clients.import')" variant="secondary">{{ __('clients.index.import') }}</x-button-link>
                    <x-button-link :href="route('clients.create')">{{ __('clients.index.new') }}</x-button-link>
                </x-slot>
            @endcan
        </x-page-header>

        <livewire:clients.client-index />
    </div>
</x-app-layout>
