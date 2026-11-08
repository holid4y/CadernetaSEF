<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('layouts.partials.head')
    </head>
    <body>
        <div class="mx-auto flex min-h-screen max-w-6xl flex-col px-4 sm:px-6 lg:px-8">
            <header class="flex items-center justify-between gap-x-4 gap-y-3 border-b border-line py-5 max-[359px]:flex-wrap">
                <a href="{{ route('login') }}"><x-brand wraps /></a>
                <x-theme-toggle class="ms-auto shrink-0" />
            </header>

            <main class="grid flex-1 content-start gap-12 py-10 lg:grid-cols-[1fr_24rem] lg:content-center lg:gap-0 lg:py-16">
                <section class="order-last lg:order-none lg:pe-16">
                    <h1 class="text-4xl leading-[1.05] tracking-tight sm:text-6xl">{{ __('brand.title') }}</h1>
                    <p class="mt-5 max-w-lg text-lg leading-relaxed text-ink-muted">{{ __('brand.lead') }}</p>

                    <dl class="mt-12 max-w-lg divide-y divide-line border-y border-line text-sm">
                        <div class="grid gap-1 py-4 sm:grid-cols-[7rem_1fr] sm:gap-6">
                            <dt class="text-ink-muted">{{ __('brand.place_label') }}</dt>
                            <dd class="text-ink">{{ __('brand.place') }}</dd>
                        </div>
                        <div class="grid gap-1 py-4 sm:grid-cols-[7rem_1fr] sm:gap-6">
                            <dt class="text-ink-muted">{{ __('brand.contact_label') }}</dt>
                            <dd class="text-ink">
                                {{ __('brand.coordinator') }}
                                <a href="mailto:{{ __('brand.contact') }}" class="block text-primary underline decoration-1 underline-offset-4 hover:text-primary-hover">{{ __('brand.contact') }}</a>
                            </dd>
                        </div>
                        <div class="grid gap-1 py-4 sm:grid-cols-[7rem_1fr] sm:gap-6">
                            <dt class="text-ink-muted">{{ __('brand.audience_label') }}</dt>
                            <dd class="text-ink">{{ __('brand.audience') }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="lg:border-s lg:border-line lg:ps-16">
                    {{ $slot }}
                </section>
            </main>

            <footer class="border-t border-line py-5 text-sm text-ink-muted">{{ __('brand.footer') }}</footer>
        </div>

        @if (session('farewell'))
            <x-curtain class="curtain--quick" :title="__('session.signed_out_title')" :subtitle="__('session.signed_out_subtitle')" />
        @endif

        @livewireScriptConfig(['nonce' => Vite::cspNonce()])
    </body>
</html>
