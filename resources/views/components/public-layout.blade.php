<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head')
    </head>
    <body>
        <div class="mx-auto flex min-h-screen max-w-2xl flex-col px-4 sm:px-6">
            <header class="flex items-center justify-between gap-x-4 gap-y-3 border-b border-line py-5 max-[359px]:flex-wrap">
                <x-brand wraps />
                <x-theme-toggle class="ms-auto shrink-0" />
            </header>

            <main class="flex-1 py-10">
                {{ $slot }}
            </main>

            <footer class="border-t border-line py-5 text-sm text-ink-muted">{{ __('brand.footer') }}</footer>
        </div>

        @livewireScriptConfig(['nonce' => Vite::cspNonce()])
    </body>
</html>
