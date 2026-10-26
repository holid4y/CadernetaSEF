<x-app-layout>
    <div class="mx-auto max-w-3xl space-y-8 px-4 py-10 sm:px-6 lg:px-8">
        <x-page-header :title="__('appointments.create.title')" :subtitle="__('appointments.create.subtitle')" />

        <livewire:appointments.create-appointment :start="$start" :end="$end" :client-id="$clientId" />
    </div>
</x-app-layout>
