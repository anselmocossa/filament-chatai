<div
    class="fi-chatai-floating"
    x-data="{
        open: (() => { try { return sessionStorage.getItem('chatai-open') === '1' } catch { return false } })(),
        toggle(value) {
            this.open = value
            try { sessionStorage.setItem('chatai-open', value ? '1' : '0') } catch {}
            if (value) this.$nextTick(() => this.$root.querySelector('.fi-chatai-input')?.focus())
        },
    }"
    x-on:keydown.escape.window="open && toggle(false)"
>
    <button
        type="button"
        class="fi-chatai-launcher"
        x-show="! open"
        x-on:click="toggle(true)"
        aria-label="{{ __('chatai::chatai.open', ['name' => $label]) }}"
    >
        <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="fi-chatai-launcher-icon" />
    </button>

    <section
        class="fi-chatai-panel"
        x-show="open"
        x-cloak
        x-transition:enter="fi-chatai-enter"
        x-transition:enter-start="fi-chatai-enter-start"
        x-transition:enter-end="fi-chatai-enter-end"
        role="dialog"
        aria-label="{{ $label }}"
    >
        <div class="fi-chatai-panel-inner" x-data="chataiChat(@js($config))">
            <header class="fi-chatai-header">
                <span class="fi-chatai-header-dot" aria-hidden="true"></span>
                <span class="fi-chatai-header-avatar" aria-hidden="true">
                    <x-filament::icon icon="heroicon-m-sparkles" class="fi-chatai-header-avatar-icon" />
                </span>
                <span class="fi-chatai-header-title" x-text="title || @js($label)"></span>

                <x-filament::dropdown placement="bottom-end" width="xs">
                    <x-slot name="trigger">
                        <button type="button" class="fi-chatai-header-btn" aria-label="{{ __('chatai::chatai.menu') }}">
                            <x-filament::icon icon="heroicon-m-ellipsis-vertical" class="fi-chatai-header-btn-icon" />
                        </button>
                    </x-slot>

                    <x-filament::dropdown.list>
                        <x-filament::dropdown.list.item icon="heroicon-m-plus" x-on:click="newConversation(); close()">
                            {{ __('chatai::chatai.new_conversation') }}
                        </x-filament::dropdown.list.item>
                        <x-filament::dropdown.list.item icon="heroicon-m-speaker-wave" x-show="config.voiceChoice" x-on:click="openVoices(); close()">
                            {{ __('chatai::chatai.voice_menu') }}
                        </x-filament::dropdown.list.item>
                        <x-filament::dropdown.list.item icon="heroicon-m-arrow-down-tray" x-on:click="exportConversation(); close()" x-bind:disabled="messages.length === 0">
                            {{ __('chatai::chatai.export') }}
                        </x-filament::dropdown.list.item>
                        <x-filament::dropdown.list.item icon="heroicon-m-arrows-pointing-out" tag="a" x-bind:href="expandUrl()">
                            {{ __('chatai::chatai.open_full_page') }}
                        </x-filament::dropdown.list.item>
                    </x-filament::dropdown.list>
                </x-filament::dropdown>

                <a class="fi-chatai-header-btn" :href="expandUrl()" aria-label="{{ __('chatai::chatai.open_full_page') }}">
                    <x-filament::icon icon="heroicon-m-arrows-pointing-out" class="fi-chatai-header-btn-icon" />
                </a>
                <button type="button" class="fi-chatai-header-btn" x-on:click="toggle(false)" aria-label="{{ __('chatai::chatai.close') }}">
                    <x-filament::icon icon="heroicon-m-x-mark" class="fi-chatai-header-btn-icon" />
                </button>
            </header>

            @include('filament-chatai::components.conversation', ['label' => $label])
        </div>
    </section>
</div>
