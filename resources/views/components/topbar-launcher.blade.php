<x-filament::icon-button
    :icon="$avatarUrl ?: 'heroicon-o-chat-bubble-left-right'"
    :label="__('chatai::chatai.open', ['name' => $label])"
    @class(['fi-chatai-topbar-launcher', 'fi-chatai-topbar-avatar' => $avatarUrl])
    color="gray"
    aria-controls="chatai-panel"
    x-data
    x-on:click="$dispatch('chatai-toggle-panel')"
/>
