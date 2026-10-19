{{-- The assistant's image if one was uploaded, else its icon. Expects $avatarUrl, $icon, $class. --}}
@if ($avatarUrl)
    <img src="{{ $avatarUrl }}" alt="" class="{{ $class }} fi-chatai-avatar-img">
@else
    <x-filament::icon :icon="$icon" class="{{ $class }}" />
@endif
