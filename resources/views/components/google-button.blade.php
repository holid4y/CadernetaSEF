@props(['href' => null])

{{-- Google's button rules: the colour "G" on white, 12px before it, 10px after, 12px after the text. --}}
@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => 'google-button press']) }}>
        <span class="google-button__mark"><x-google-logo /></span>
        <span>{{ $slot }}</span>
    </a>
@else
    <button {{ $attributes->merge(['type' => 'submit', 'class' => 'google-button press']) }}>
        <span class="google-button__mark"><x-google-logo /></span>
        <span>{{ $slot }}</span>
    </button>
@endif
