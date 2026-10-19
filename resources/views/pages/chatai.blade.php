<x-filament-panels::page>
    <div class="fi-chatai-page" x-data="chataiChat(@js($config))" :class="{ 'fi-chatai-sidebar-open': sidebarOpen }">
        <aside class="fi-chatai-sidebar">
            <x-filament::button icon="heroicon-m-plus" class="fi-chatai-sidebar-new" x-on:click="newConversation(); closeSidebarIfNarrow()">
                {{ __('chatai::chatai.new_conversation') }}
            </x-filament::button>

            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass" class="fi-chatai-sidebar-search">
                <x-filament::input
                    type="search"
                    x-model="search"
                    x-on:input.debounce.300ms="loadConversations()"
                    placeholder="{{ __('chatai::chatai.search') }}"
                />
            </x-filament::input.wrapper>

            <nav class="fi-chatai-sidebar-list" aria-label="{{ __('chatai::chatai.conversations') }}">
                <template x-for="item in conversations" :key="item.id">
                    <div class="fi-chatai-sidebar-item" :class="{ 'fi-active': item.id === conversationId }">
                        <button type="button" class="fi-chatai-sidebar-item-btn" x-on:click="open(item.id); closeSidebarIfNarrow()">
                            <span class="fi-chatai-sidebar-item-title" x-text="item.title || @js(__('chatai::chatai.untitled'))"></span>
                            <span class="fi-chatai-sidebar-item-date" x-text="formatWhen(item.last_message_at)"></span>
                        </button>
                        <button type="button" class="fi-chatai-sidebar-item-delete" x-on:click="remove(item.id)" aria-label="{{ __('chatai::chatai.delete') }}">
                            <x-filament::icon icon="heroicon-m-trash" class="fi-chatai-header-btn-icon" />
                        </button>
                    </div>
                </template>

                <p class="fi-chatai-sidebar-empty" x-show="conversations.length === 0">{{ __('chatai::chatai.no_conversations') }}</p>
            </nav>
        </aside>

        <section class="fi-chatai-main">
            <header class="fi-chatai-main-header">
                <button type="button" class="fi-chatai-header-btn fi-chatai-sidebar-toggle" x-on:click="toggleSidebar()" :aria-expanded="sidebarOpen" aria-label="{{ __('chatai::chatai.conversations') }}">
                    <x-filament::icon icon="heroicon-m-bars-3" class="fi-chatai-header-btn-icon" />
                </button>
                <span class="fi-chatai-header-avatar" aria-hidden="true">
                    @include('filament-chatai::components.avatar', ['class' => 'fi-chatai-header-avatar-icon'])
                </span>
                <h1 class="fi-chatai-main-title" x-text="title || @js($label)"></h1>
                <div class="fi-chatai-header-actions">
                    <button type="button" class="fi-chatai-header-btn" x-show="config.voiceChoice" x-cloak x-on:click="openVoices()" aria-label="{{ __('chatai::chatai.voice_menu') }}" title="{{ __('chatai::chatai.voice_menu') }}">
                        <x-filament::icon icon="heroicon-m-speaker-wave" class="fi-chatai-header-btn-icon" />
                    </button>
                    <button type="button" class="fi-chatai-header-btn fi-chatai-header-export" x-on:click="exportConversation()" x-show="messages.length" x-cloak aria-label="{{ __('chatai::chatai.export') }}" title="{{ __('chatai::chatai.export') }}">
                        <x-filament::icon icon="heroicon-m-arrow-down-tray" class="fi-chatai-header-btn-icon" />
                    </button>
                    <button type="button" class="fi-chatai-header-btn fi-chatai-header-new" x-on:click="newConversation()" aria-label="{{ __('chatai::chatai.new_conversation') }}" title="{{ __('chatai::chatai.new_conversation') }}">
                        <x-filament::icon icon="heroicon-m-pencil-square" class="fi-chatai-header-btn-icon" />
                    </button>
                </div>
            </header>

            @include('filament-chatai::components.conversation', ['label' => $label])
        </section>
    </div>
</x-filament-panels::page>
