<div
    class="fi-chatai-floating"
    {{-- Not "open"/"toggle": the chat inside has an open() method and Alpine merges both scopes. --}}
    x-data="{
        panelOpen: (() => { try { return sessionStorage.getItem('chatai-open') === '1' } catch { return false } })(),
        togglePanel(value) {
            this.panelOpen = value
            if (value) this.agent = null
            try { sessionStorage.setItem('chatai-open', value ? '1' : '0') } catch {}
            if (value) this.$nextTick(() => this.$root.querySelector('.fi-chatai-input')?.focus())
        },
        // While the assistant works on the page (filling a form) the chat shrinks
        // to a small bar on the chat button, so the page stays in view.
        agent: null,
        onAgent(detail) {
            if (detail.state === 'start') {
                this.agent = { state: 'working', done: 0, total: detail.total, detail: '' }
                this.panelOpen = false
            } else if (this.agent && detail.state === 'progress') {
                Object.assign(this.agent, { done: detail.done, detail: detail.label })
            } else if (this.agent && detail.state === 'done') {
                Object.assign(this.agent, { state: detail.stopped ? 'stopped' : 'done', done: detail.done, detail: '' })
            }
        },
        stopAgent() {
            window.dispatchEvent(new CustomEvent('chatai-agent-stop'))
        },
    }"
    x-on:keydown.escape.window="panelOpen && togglePanel(false)"
    @if ($shortcut = $config['shortcut'] ?? null)
        {{-- ⌘/Ctrl + key opens and closes the chat --}}
        x-on:keydown.window="if (($event.metaKey || $event.ctrlKey) && ! $event.altKey && $event.key.toLowerCase() === @js($shortcut)) { $event.preventDefault(); togglePanel(! panelOpen) }"
    @endif
    x-on:chatai-agent.window="onAgent($event.detail)"
>
    {{-- The minimised assistant at work, in place of the chat button --}}
    <div class="fi-chatai-agent" x-show="agent && ! panelOpen" x-cloak role="status" aria-live="polite"
         :class="{ 'fi-done': agent?.state !== 'working' }"
         x-transition:enter="fi-chatai-enter" x-transition:enter-start="fi-chatai-enter-start" x-transition:enter-end="fi-chatai-enter-end">
        <button type="button" class="fi-chatai-agent-main" x-on:click="togglePanel(true)" aria-label="{{ __('chatai::chatai.screen.expand') }}">
            <span class="fi-chatai-agent-avatar">
                <span class="fi-chatai-agent-ring" x-show="agent?.state === 'working'"></span>
                <template x-if="agent?.state === 'working'">
                    @include('filament-chatai::components.avatar', ['class' => 'fi-chatai-agent-icon'])
                </template>
                <template x-if="agent?.state !== 'working'">
                    <x-filament::icon icon="heroicon-m-check" class="fi-chatai-agent-icon" />
                </template>
            </span>
            <span class="fi-chatai-agent-text">
                <span class="fi-chatai-agent-title"
                      x-text="agent?.state === 'working' ? @js(__('chatai::chatai.screen.filling')) : agent?.state === 'stopped' ? @js(__('chatai::chatai.screen.stopped')) : @js(__('chatai::chatai.screen.filled_short'))"></span>
                <span class="fi-chatai-agent-detail"
                      x-text="agent?.state === 'working' ? (agent.detail ? agent.detail + ' · ' : '') + agent.done + '/' + agent.total : @js(__('chatai::chatai.screen.review')).replace(':count', agent?.done ?? 0)"></span>
            </span>
            <span class="fi-chatai-agent-progress" x-show="agent?.state === 'working'"><span :style="'width:' + (agent ? Math.round(agent.done / Math.max(1, agent.total) * 100) : 0) + '%'"></span></span>
        </button>
        <button type="button" class="fi-chatai-agent-btn" x-show="agent?.state === 'working'" x-on:click="stopAgent()" title="{{ __('chatai::chatai.screen.stop') }}" aria-label="{{ __('chatai::chatai.screen.stop') }}">
            <x-filament::icon icon="heroicon-s-stop" class="fi-chatai-agent-btn-icon" />
        </button>
        <button type="button" class="fi-chatai-agent-btn" x-on:click="togglePanel(true)" title="{{ __('chatai::chatai.screen.expand') }}" aria-label="{{ __('chatai::chatai.screen.expand') }}">
            <x-filament::icon icon="heroicon-m-arrows-pointing-out" class="fi-chatai-agent-btn-icon" />
        </button>
        <button type="button" class="fi-chatai-agent-btn" x-show="agent?.state !== 'working'" x-on:click="agent = null" title="{{ __('chatai::chatai.close') }}" aria-label="{{ __('chatai::chatai.close') }}">
            <x-filament::icon icon="heroicon-m-x-mark" class="fi-chatai-agent-btn-icon" />
        </button>
    </div>

    <button
        type="button"
        class="fi-chatai-launcher"
        x-show="! panelOpen && ! agent"
        x-on:click="togglePanel(true)"
        aria-label="{{ __('chatai::chatai.open', ['name' => $label]) }}"
        title="{{ __('chatai::chatai.open', ['name' => $label]) }}{{ ($config['shortcut'] ?? null) ? ' (⌘/Ctrl+'.strtoupper($config['shortcut']).')' : '' }}"
    >
        @if ($avatarUrl)
            @include('filament-chatai::components.avatar', ['class' => 'fi-chatai-launcher-icon fi-chatai-launcher-avatar'])
        @else
            <x-filament::icon icon="heroicon-o-chat-bubble-left-right" class="fi-chatai-launcher-icon" />
        @endif
    </button>

    <section
        class="fi-chatai-panel"
        x-show="panelOpen"
        x-cloak
        x-transition:enter="fi-chatai-enter"
        x-transition:enter-start="fi-chatai-enter-start"
        x-transition:enter-end="fi-chatai-enter-end"
        role="dialog"
        aria-label="{{ $label }}"
    >
        <div class="fi-chatai-panel-inner" x-data="chataiChat(@js($config))">
            <header class="fi-chatai-header">
                <span class="fi-chatai-header-avatar" aria-hidden="true">
                    @include('filament-chatai::components.avatar', ['class' => 'fi-chatai-header-avatar-icon'])
                    <span class="fi-chatai-header-dot"></span>
                </span>
                <span class="fi-chatai-header-title" x-text="title || @js($label)"></span>

                <button type="button" class="fi-chatai-header-btn" x-on:click="newConversation()" aria-label="{{ __('chatai::chatai.new_conversation') }}" title="{{ __('chatai::chatai.new_conversation') }}">
                    <x-filament::icon icon="heroicon-m-pencil-square" class="fi-chatai-header-btn-icon" />
                </button>

                <x-filament::dropdown placement="bottom-end" width="xs">
                    <x-slot name="trigger">
                        <button type="button" class="fi-chatai-header-btn" aria-label="{{ __('chatai::chatai.menu') }}">
                            <x-filament::icon icon="heroicon-m-ellipsis-vertical" class="fi-chatai-header-btn-icon" />
                        </button>
                    </x-slot>

                    <x-filament::dropdown.list>
                        <x-filament::dropdown.list.item icon="heroicon-m-light-bulb" x-show="config.memory" x-on:click="openMemory(); close()">
                            {{ __('chatai::chatai.memory.title') }}
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
                <button type="button" class="fi-chatai-header-btn" x-on:click="togglePanel(false)" aria-label="{{ __('chatai::chatai.close') }}">
                    <x-filament::icon icon="heroicon-m-x-mark" class="fi-chatai-header-btn-icon" />
                </button>
            </header>

            @include('filament-chatai::components.conversation', ['label' => $label])
        </div>
    </section>
</div>
