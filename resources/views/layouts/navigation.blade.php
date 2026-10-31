<nav x-data="{ open: false }" class="sticky top-0 z-40 border-b border-line bg-surface/95">
    <div class="mx-auto flex h-18 max-w-6xl items-center justify-between gap-6 px-4 sm:px-6 lg:px-8">
        <div class="flex items-center gap-8">
            <a href="{{ route('dashboard') }}" wire:navigate><x-brand /></a>

            <div class="relative hidden gap-1 lg:flex">
                <span data-nav-indicator class="absolute inset-y-0 start-0 rounded-full bg-primary-soft" aria-hidden="true"></span>
                <x-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                    {{ __('nav.dashboard') }}
                </x-nav-link>
                <x-nav-link :href="route('agenda')" :active="request()->routeIs('agenda', 'appointments.*')">
                    {{ __('nav.agenda') }}
                </x-nav-link>
                <x-nav-link :href="route('clients.index')" :active="request()->routeIs('clients.*')">
                    {{ __('nav.clients') }}
                </x-nav-link>
                <x-nav-link :href="route('impact')" :active="request()->routeIs('impact')">
                    {{ __('nav.impact') }}
                </x-nav-link>
                @can('create', App\Models\Service::class)
                    <x-nav-link :href="route('services.index')" :active="request()->routeIs('services.*')">
                        {{ __('nav.services') }}
                    </x-nav-link>
                @endcan
            </div>
        </div>

        <div class="hidden items-center gap-3 lg:flex">
            <x-theme-toggle />

            <x-dropdown align="right" width="48">
                <x-slot name="trigger">
                    <button class="press inline-flex min-h-11 items-center gap-2 rounded-full px-4 text-sm font-medium text-ink-muted hover:bg-sunken hover:text-ink">
                        {{ Auth::user()->name }}
                        <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                        </svg>
                    </button>
                </x-slot>

                <x-slot name="content">
                    <x-dropdown-link :href="route('profile.edit')" wire:navigate>
                        {{ __('Profile') }}
                    </x-dropdown-link>

                    <form method="POST" action="{{ route('logout') }}" data-farewell>
                        @csrf

                        <button type="submit" class="press block w-full rounded-lg px-3 py-2 text-start text-sm text-ink hover:bg-sunken">
                            {{ __('Log Out') }}
                        </button>
                    </form>
                </x-slot>
            </x-dropdown>
        </div>

        <button x-on:click="open = ! open" x-bind:aria-expanded="open" aria-controls="mobile-menu"
                class="press inline-flex size-11 items-center justify-center rounded-full text-ink-muted hover:bg-sunken hover:text-ink lg:hidden">
            <span class="sr-only">{{ __('Menu') }}</span>
            <svg class="size-6" stroke="currentColor" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                <path x-show="! open" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                <path x-show="open" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <div id="mobile-menu" x-show="open" x-cloak
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="-translate-y-2 opacity-0"
         x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="-translate-y-2 opacity-0"
         class="space-y-4 border-t border-line px-4 py-4 lg:hidden">
        <div class="space-y-1">
            <x-responsive-nav-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                {{ __('nav.dashboard') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('agenda')" :active="request()->routeIs('agenda', 'appointments.*')">
                {{ __('nav.agenda') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('clients.index')" :active="request()->routeIs('clients.*')">
                {{ __('nav.clients') }}
            </x-responsive-nav-link>
            <x-responsive-nav-link :href="route('impact')" :active="request()->routeIs('impact')">
                {{ __('nav.impact') }}
            </x-responsive-nav-link>
            @can('create', App\Models\Service::class)
                <x-responsive-nav-link :href="route('services.index')" :active="request()->routeIs('services.*')">
                    {{ __('nav.services') }}
                </x-responsive-nav-link>
            @endcan
        </div>

        <div class="border-t border-line pt-4">
            <p class="px-4 font-medium text-ink">{{ Auth::user()->name }}</p>
            <p class="px-4 text-sm text-ink-muted">{{ Auth::user()->email }}</p>

            <div class="mt-3 space-y-1">
                <x-responsive-nav-link :href="route('profile.edit')">
                    {{ __('Profile') }}
                </x-responsive-nav-link>

                <form method="POST" action="{{ route('logout') }}" data-farewell>
                    @csrf

                    <button type="submit" class="press block w-full rounded-soft px-4 py-3 text-start text-base font-medium text-ink-muted hover:bg-sunken hover:text-ink">
                        {{ __('Log Out') }}
                    </button>
                </form>
            </div>
        </div>

        <x-theme-toggle />
    </div>
</nav>
