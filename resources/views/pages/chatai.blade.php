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
                <button type="button" class="fi-chatai-history-add" x-on:click="createGroup()">
                    <x-filament::icon icon="heroicon-m-folder-plus" class="fi-chatai-history-icon" />
                    <span>{{ __('chatai::chatai.history.new_group') }}</span>
                </button>

                <template x-for="section in historySections()" :key="section.key">
                    <section class="fi-chatai-history-section" :class="{ 'fi-group': section.group }">
                        <div class="fi-chatai-history-heading" x-show="section.group || section.items.length">
                            <template x-if="section.group && editingGroupId === section.group.id">
                                <input type="text" class="fi-chatai-history-input" x-model="editingText" maxlength="80"
                                       x-init="$nextTick(() => { $el.focus(); $el.select() })"
                                       x-on:keydown.enter.prevent="saveGroup(section.group)" x-on:keydown.escape="editingGroupId = null" x-on:blur="saveGroup(section.group)">
                            </template>
                            <template x-if="! (section.group && editingGroupId === section.group.id)">
                                <button type="button" class="fi-chatai-history-title" x-on:click="section.group && toggleGroup(section.group.id)">
                                    <template x-if="section.group">
                                        <x-filament::icon icon="heroicon-m-folder" class="fi-chatai-history-icon" />
                                    </template>
                                    <template x-if="section.key === 'pinned'">
                                        <x-filament::icon icon="heroicon-m-bookmark" class="fi-chatai-history-icon" />
                                    </template>
                                    <span x-text="section.label"></span>
                                    <template x-if="section.group">
                                        <x-filament::icon icon="heroicon-m-chevron-down" class="fi-chatai-history-chevron" x-bind:class="{ 'fi-collapsed': isCollapsed(section.group.id) }" />
                                    </template>
                                </button>
                            </template>
                            <template x-if="section.group">
                                <x-filament::dropdown placement="bottom-end" teleport>
                                    <x-slot name="trigger">
                                        <button type="button" class="fi-chatai-history-more" aria-label="{{ __('chatai::chatai.history.options') }}">
                                            <x-filament::icon icon="heroicon-m-ellipsis-horizontal" class="fi-chatai-history-icon" />
                                        </button>
                                    </x-slot>
                                    <x-filament::dropdown.list>
                                        <x-filament::dropdown.list.item icon="heroicon-m-pencil" x-on:click="close(); startGroupRename(section.group)">{{ __('chatai::chatai.history.rename_group') }}</x-filament::dropdown.list.item>
                                        <x-filament::dropdown.list.item icon="heroicon-m-trash" color="danger" x-on:click="close(); deleteGroup(section.group)">{{ __('chatai::chatai.history.delete_group') }}</x-filament::dropdown.list.item>
                                    </x-filament::dropdown.list>
                                </x-filament::dropdown>
                            </template>
                        </div>

                        <template x-for="item in (section.group && isCollapsed(section.group.id) ? [] : section.items)" :key="item.id">
                            <div class="fi-chatai-sidebar-item" :class="{ 'fi-active': item.id === conversationId }">
                                <template x-if="editingId === item.id">
                                    <input type="text" class="fi-chatai-history-input" x-model="editingText" maxlength="120"
                                           x-init="$nextTick(() => { $el.focus(); $el.select() })"
                                           x-on:keydown.enter.prevent="saveRename(item)" x-on:keydown.escape="editingId = null" x-on:blur="saveRename(item)">
                                </template>
                                <template x-if="editingId !== item.id">
                                    <button type="button" class="fi-chatai-sidebar-item-btn" x-on:click="open(item.id); closeSidebarIfNarrow()">
                                        <span class="fi-chatai-sidebar-item-title" x-text="item.title || @js(__('chatai::chatai.untitled'))"></span>
                                        <span class="fi-chatai-sidebar-item-date" x-text="formatWhen(item.last_message_at)"></span>
                                    </button>
                                </template>
                                <x-filament::dropdown placement="bottom-end" teleport>
                                    <x-slot name="trigger">
                                        <button type="button" class="fi-chatai-sidebar-item-delete" aria-label="{{ __('chatai::chatai.history.options') }}">
                                            <x-filament::icon icon="heroicon-m-ellipsis-horizontal" class="fi-chatai-header-btn-icon" />
                                        </button>
                                    </x-slot>
                                    <x-filament::dropdown.list>
                                        <x-filament::dropdown.list.item icon="heroicon-m-bookmark" x-on:click="close(); togglePin(item)">
                                            <span x-text="item.pinned ? @js(__('chatai::chatai.history.unpin')) : @js(__('chatai::chatai.history.pin'))"></span>
                                        </x-filament::dropdown.list.item>
                                        <x-filament::dropdown.list.item icon="heroicon-m-pencil" x-on:click="close(); startRename(item)">{{ __('chatai::chatai.history.rename') }}</x-filament::dropdown.list.item>
                                    </x-filament::dropdown.list>
                                    <x-filament::dropdown.header icon="heroicon-m-folder-arrow-down" color="gray">{{ __('chatai::chatai.history.move') }}</x-filament::dropdown.header>
                                    <x-filament::dropdown.list>
                                        <template x-for="group in groups.filter((g) => g.id !== item.group_id)" :key="group.id">
                                            <x-filament::dropdown.list.item icon="heroicon-m-folder" x-on:click="close(); moveTo(item, group.id)">
                                                <span x-text="group.name"></span>
                                            </x-filament::dropdown.list.item>
                                        </template>
                                        <x-filament::dropdown.list.item icon="heroicon-m-folder-plus" x-on:click="close(); createGroup(item)">{{ __('chatai::chatai.history.new_group') }}</x-filament::dropdown.list.item>
                                        <template x-if="item.group_id">
                                            <x-filament::dropdown.list.item icon="heroicon-m-folder-minus" x-on:click="close(); moveTo(item, null)">{{ __('chatai::chatai.history.remove_from_group') }}</x-filament::dropdown.list.item>
                                        </template>
                                    </x-filament::dropdown.list>
                                    <x-filament::dropdown.list>
                                        <x-filament::dropdown.list.item icon="heroicon-m-trash" color="danger" x-on:click="close(); remove(item.id)">{{ __('chatai::chatai.delete') }}</x-filament::dropdown.list.item>
                                    </x-filament::dropdown.list>
                                </x-filament::dropdown>
                            </div>
                        </template>
                    </section>
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
