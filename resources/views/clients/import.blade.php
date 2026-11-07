<x-app-layout>
    <div class="mx-auto max-w-5xl space-y-8 px-4 py-10 sm:px-6 lg:px-8">
        <x-page-header :title="__('clients.import.title')" :subtitle="__('clients.import.subtitle')" />

        <livewire:clients.client-import />
    </div>
</x-app-layout>
