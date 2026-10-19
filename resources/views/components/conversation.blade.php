{{-- Messages + composer. Expects to sit inside an element with x-data="chataiChat(...)". --}}
<div class="fi-chatai-conversation">
    <div class="fi-chatai-messages" x-ref="scroller" aria-live="polite">
        <template x-if="messages.length === 0 && ! loading">
            <div class="fi-chatai-empty">
                <x-filament::icon icon="heroicon-o-sparkles" class="fi-chatai-empty-icon" />
                <p class="fi-chatai-empty-title">{{ __('chatai::chatai.empty_title', ['name' => $label]) }}</p>
                <p class="fi-chatai-empty-text">{{ $emptyText ?? __('chatai::chatai.empty_text') }}</p>
            </div>
        </template>

        <template x-for="message in messages" :key="message.id">
            <div class="fi-chatai-message" :class="'fi-chatai-message-' + message.role">
                <template x-if="message.role === 'user'">
                    <div class="fi-chatai-bubble fi-chatai-bubble-user" x-text="message.content"></div>
                </template>

                <template x-if="message.role === 'assistant'">
                    <div class="fi-chatai-answer">
                        <template x-if="message.content">
                            <div class="fi-chatai-bubble fi-chatai-bubble-assistant fi-chatai-prose" x-html="markdown(message.content)"></div>
                        </template>

                        <template x-if="message.tool">
                            <div class="fi-chatai-status">
                                <x-filament::loading-indicator class="fi-chatai-status-spinner" />
                                <span x-text="message.tool + '…'"></span>
                            </div>
                        </template>

                        <template x-if="message.status === 'streaming' && ! message.content && ! message.tool && message.components.length === 0">
                            <div class="fi-chatai-typing" aria-label="{{ __('chatai::chatai.thinking') }}"><span></span><span></span><span></span></div>
                        </template>

                        <template x-for="(component, index) in message.components" :key="message.id + '-' + index">
                            <div class="fi-chatai-component">
                                @include('filament-chatai::components.catalog')
                            </div>
                        </template>

                        <template x-if="canSpeak && message.content && message.status !== 'streaming'">
                            <div class="fi-chatai-actions">
                                <button type="button" class="fi-chatai-action" x-on:click="speak(message)"
                                        :class="{ 'fi-active': speakingId === message.id }"
                                        :aria-pressed="speakingId === message.id"
                                        :title="speakingId === message.id ? @js(__('chatai::chatai.stop_listening')) : @js(__('chatai::chatai.listen'))">
                                    <x-filament::icon icon="heroicon-m-speaker-wave" class="fi-chatai-action-icon" x-show="speakingId !== message.id" />
                                    <x-filament::icon icon="heroicon-m-stop" class="fi-chatai-action-icon" x-show="speakingId === message.id" x-cloak />
                                    <span x-text="speakingId === message.id ? @js(__('chatai::chatai.stop_listening')) : @js(__('chatai::chatai.listen'))"></span>
                                </button>
                            </div>
                        </template>

                        <template x-if="message.status === 'interrupted'">
                            <button type="button" class="fi-chatai-retry" x-on:click="retry()">
                                {{ __('chatai::chatai.interrupted') }}
                            </button>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </div>

    <template x-if="voicePanel">
        <div class="fi-chatai-voices" role="dialog" aria-label="{{ __('chatai::chatai.voice_menu') }}" x-on:keydown.escape.window="voicePanel = false">
            <div class="fi-chatai-voices-header">
                <span>{{ __('chatai::chatai.voice_menu') }}</span>
                <button type="button" class="fi-chatai-header-btn" x-on:click="voicePanel = false" aria-label="{{ __('chatai::chatai.close') }}">
                    <x-filament::icon icon="heroicon-m-x-mark" class="fi-chatai-header-btn-icon" />
                </button>
            </div>
            <p class="fi-chatai-voices-hint" x-show="! config.serverVoice">{{ __('chatai::chatai.voice_device') }}</p>
            <div class="fi-chatai-voices-list">
                <label class="fi-chatai-voice">
                    <input type="radio" name="chatai-voice" :checked="! currentVoice()" x-on:change="chooseVoice(null)">
                    <span>{{ __('chatai::chatai.voice_default') }}</span>
                </label>
                <template x-for="voice in voiceOptions()" :key="voice.id">
                    <div class="fi-chatai-voice">
                        <label>
                            <input type="radio" name="chatai-voice" :checked="currentVoice() === voice.id" x-on:change="chooseVoice(voice.id)">
                            <span x-text="voice.label"></span>
                        </label>
                        <button type="button" class="fi-chatai-action" x-on:click="playSample(voice.id)" :title="@js(__('chatai::chatai.voice_play_sample'))">
                            <x-filament::icon icon="heroicon-m-play" class="fi-chatai-action-icon" />
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </template>

    <template x-if="notice">
        <div class="fi-chatai-notice" role="alert">
            <span x-text="notice"></span>
            <button type="button" x-on:click="retry()" x-show="lastQuestion">{{ __('chatai::chatai.retry') }}</button>
        </div>
    </template>

    {{-- Composer: one card with the text on top and the actions below, like the big chat apps. --}}
    <form class="fi-chatai-composer" x-on:submit.prevent="send()">
        <div class="fi-chatai-composer-box" :class="{ 'fi-recording': listening }" x-on:click.self="$refs.input.focus()">
            <textarea
                x-ref="input"
                x-model="input"
                x-on:keydown="onKeydown($event)"
                rows="1"
                maxlength="4000"
                placeholder="{{ __('chatai::chatai.placeholder') }}"
                :placeholder="transcribing ? @js(__('chatai::chatai.transcribing')) : (listening ? @js(__('chatai::chatai.listening')) : @js(__('chatai::chatai.placeholder')))"
                :disabled="transcribing"
                aria-label="{{ __('chatai::chatai.placeholder') }}"
                class="fi-chatai-input"
                x-init="$watch('input', () => { $el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 220) + 'px' })"
            ></textarea>

            <div class="fi-chatai-composer-bar">
                <div class="fi-chatai-composer-tools">
                    <button type="button" class="fi-chatai-tool" x-on:click="newConversation()" title="{{ __('chatai::chatai.new_conversation') }}" aria-label="{{ __('chatai::chatai.new_conversation') }}">
                        <x-filament::icon icon="heroicon-m-plus" class="fi-chatai-tool-icon" />
                    </button>
                    <button type="button" class="fi-chatai-tool" x-show="config.voiceChoice" x-cloak x-on:click="openVoices()" title="{{ __('chatai::chatai.voice_menu') }}" aria-label="{{ __('chatai::chatai.voice_menu') }}">
                        <x-filament::icon icon="heroicon-m-speaker-wave" class="fi-chatai-tool-icon" />
                    </button>
                    <span class="fi-chatai-recording" x-show="listening" x-cloak aria-live="polite">
                        <span class="fi-chatai-recording-dot"></span>
                        <span x-text="@js(__('chatai::chatai.listening')) + ' ' + recordingTime()"></span>
                    </span>
                    <span class="fi-chatai-counter" x-show="! listening && input.length > 3500" x-cloak x-text="input.length + '/4000'"></span>
                </div>

                <div class="fi-chatai-composer-actions">
                    <button type="button" class="fi-chatai-mic" x-show="canDictate" x-cloak x-on:click="dictate()"
                            :class="{ 'fi-active': listening }" :aria-pressed="listening"
                            :aria-label="listening ? @js(__('chatai::chatai.stop_dictation')) : @js(__('chatai::chatai.dictate'))"
                            :title="listening ? @js(__('chatai::chatai.stop_dictation')) : @js(__('chatai::chatai.dictate'))">
                        <x-filament::icon icon="heroicon-m-microphone" class="fi-chatai-send-icon" x-show="! listening && ! transcribing" />
                        <x-filament::icon icon="heroicon-m-stop" class="fi-chatai-send-icon" x-show="listening" x-cloak />
                        <x-filament::loading-indicator class="fi-chatai-send-icon" x-show="transcribing" x-cloak />
                    </button>

                    <button type="submit" class="fi-chatai-send" :disabled="busy || ! input.trim()" aria-label="{{ __('chatai::chatai.send') }}">
                        <x-filament::icon icon="heroicon-m-arrow-up" class="fi-chatai-send-icon" x-show="! busy" />
                        <x-filament::loading-indicator class="fi-chatai-send-icon" x-show="busy" x-cloak />
                    </button>
                </div>
            </div>
        </div>
        <p class="fi-chatai-composer-hint">{{ __('chatai::chatai.composer_hint') }}</p>
    </form>
</div>
