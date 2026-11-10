<x-guest-layout>
    <h2 class="text-3xl">{{ __('brand.sign_in') }}</h2>

    <x-auth-session-status class="mt-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}" data-login-form x-data="{ sending: false }" x-on:submit="sending = true" class="mt-8 space-y-5">
        @csrf

        <div x-data="passwordHint(@js(route('api.account-status')), @js(route('password.request')))">
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" x-ref="email" x-on:input="changed($event.target.value)" x-on:change="changed($event.target.value)" class="mt-1 block w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
            <div role="status">
                <p x-show="awaiting" x-cloak class="mt-2 text-sm text-ink-muted">
                    {{ __('auth.awaiting_password') }}
                    <a x-bind:href="createLink" class="text-primary underline decoration-1 underline-offset-4 hover:text-primary-hover">{{ __('auth.create_password') }}</a>
                </p>
            </div>
        </div>

        <div>
            <x-input-label for="password" :value="__('Password')" />
            <x-text-input id="password" class="mt-1 block w-full" type="password" name="password" required autocomplete="current-password" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <label for="remember_me" class="inline-flex min-h-11 items-center gap-2 text-sm text-ink-muted">
            <x-checkbox-input id="remember_me" name="remember" />
            {{ __('Remember me') }}
        </label>

        <x-primary-button class="w-full" x-bind:disabled="sending" x-bind:aria-busy="sending.toString()">
            <span x-show="! sending">{{ __('Log in') }}</span>
            <span x-show="sending" x-cloak class="inline-flex items-center gap-2">
                <x-application-logo class="size-4 animate-spin [animation-duration:1.4s]" />
                {{ __('session.signing_in') }}
            </span>
        </x-primary-button>

        @if (Route::has('password.request'))
            <a class="inline-block text-sm text-primary underline decoration-1 underline-offset-4 hover:text-primary-hover" href="{{ route('password.request') }}">
                {{ __('Forgot your password?') }}
            </a>
        @endif
    </form>

    <p class="mt-10 border-t border-line pt-5 text-sm text-ink-muted">{{ __('brand.accounts_note') }}</p>
</x-guest-layout>
