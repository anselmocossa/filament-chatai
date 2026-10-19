<x-filament-panels::page>
    <div class="fi-chatai-editor">
        <section class="fi-chatai-editor-form">
            <form wire:submit="save">
                {{ $this->form }}
            </form>
        </section>

        {{-- Test chat: the real model with only this tool, as it is in the form (saved or not). --}}
        <aside class="fi-chatai-editor-test" wire:ignore>
            <div class="fi-chatai-page fi-chatai-editor-chat" x-data="chataiChat(@js($config))">
                <section class="fi-chatai-main">
                    <header class="fi-chatai-main-header">
                        <span class="fi-chatai-header-avatar" aria-hidden="true">
                            <x-filament::icon icon="heroicon-m-beaker" class="fi-chatai-header-avatar-icon" />
                        </span>
                        <div class="fi-chatai-editor-test-title">
                            <h2 class="fi-chatai-main-title">{{ __('chatai::chatai.editor.test_title') }}</h2>
                            <p class="fi-chatai-editor-test-hint">{{ __('chatai::chatai.editor.test_hint') }}</p>
                        </div>
                        <div class="fi-chatai-header-actions">
                            <button type="button" class="fi-chatai-header-btn" x-on:click="newConversation()" aria-label="{{ __('chatai::chatai.new_conversation') }}" title="{{ __('chatai::chatai.new_conversation') }}">
                                <x-filament::icon icon="heroicon-m-arrow-path" class="fi-chatai-header-btn-icon" />
                            </button>
                        </div>
                    </header>

                    <div class="fi-chatai-editor-options">
                        <x-filament::input.wrapper class="fi-chatai-editor-model">
                            <x-filament::input.select x-model="playgroundOptions.model_id" x-on:change="pickPlaygroundModel()" aria-label="{{ __('chatai::chatai.editor.model') }}">
                                <option value="">{{ __('chatai::chatai.editor.model_chain') }}</option>
                                <template x-for="model in config.playground.models" :key="model.id">
                                    <option :value="model.id" x-text="model.label"></option>
                                </template>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <button type="button" class="fi-chatai-header-btn" x-on:click="playgroundSettings = ! playgroundSettings"
                                :class="{ 'fi-active': playgroundSettings }" :aria-expanded="playgroundSettings" title="{{ __('chatai::chatai.editor.options') }}">
                            <x-filament::icon icon="heroicon-m-adjustments-horizontal" class="fi-chatai-header-btn-icon" />
                        </button>
                    </div>

                    <div class="fi-chatai-editor-settings" x-show="playgroundSettings" x-cloak>
                        {{-- Only for a chosen model that uses effort, with the levels its API accepts. --}}
                        <label x-show="playgroundModel()?.efforts.length">
                            <span>{{ __('chatai::chatai.models.effort') }}</span>
                            <x-filament::input.wrapper>
                                <x-filament::input.select x-model="playgroundOptions.effort">
                                    <template x-for="level in playgroundModel()?.efforts ?? []" :key="level">
                                        <option :value="level" x-text="level + (level === playgroundModel()?.effort ? ' · ' + @js(__('chatai::chatai.editor.model_setting')) : '')" :selected="level === playgroundOptions.effort"></option>
                                    </template>
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </label>
                        <label>
                            <span>{{ __('chatai::chatai.editor.max_tokens') }}</span>
                            <x-filament::input.wrapper>
                                <x-filament::input type="number" min="256" max="128000" step="256" x-model="playgroundOptions.max_tokens" placeholder="{{ __('chatai::chatai.models.effort_default') }}" />
                            </x-filament::input.wrapper>
                        </label>
                        {{-- Claude models no longer accept temperature: shown for OpenAI-compatible models only. --}}
                        <label class="fi-chatai-editor-settings-wide" x-show="config.playground.models.find((m) => String(m.id) === String(playgroundOptions.model_id))?.driver === 'openai'">
                            <span>{{ __('chatai::chatai.editor.temperature') }} <b x-text="playgroundOptions.temperature === '' ? @js(__('chatai::chatai.models.effort_default')) : playgroundOptions.temperature"></b></span>
                            <input type="range" min="0" max="1" step="0.1" x-model="playgroundOptions.temperature" class="fi-chatai-range">
                        </label>
                        <button type="button" class="fi-chatai-action" x-on:click="playgroundOptions = { model_id: playgroundOptions.model_id, effort: playgroundModel()?.effort ?? '', max_tokens: '', temperature: '' }">
                            <x-filament::icon icon="heroicon-m-arrow-uturn-left" class="fi-chatai-action-icon" />
                            <span>{{ __('chatai::chatai.editor.reset_options') }}</span>
                        </button>
                    </div>

                    @include('filament-chatai::components.conversation', ['label' => $label, 'emptyText' => __('chatai::chatai.editor.test_empty')])
                </section>
            </div>
        </aside>
    </div>
</x-filament-panels::page>
