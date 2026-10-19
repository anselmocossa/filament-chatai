{{-- Read-only code with tabs and a copy button. Expects $blocks: [['id', 'label', 'code'], ...]. --}}
<div class="fi-chatai-code" x-data="{ tab: @js($blocks[0]['id'] ?? null), copied: false }">
    @if (empty($blocks))
        <p class="fi-chatai-code-empty">{{ $empty ?? '' }}</p>
    @else
        <div class="fi-chatai-code-bar">
            @if (count($blocks) > 1)
                <x-filament::input.wrapper class="fi-chatai-code-select">
                    <x-filament::input.select x-model="tab" aria-label="{{ __('chatai::chatai.editor.format') }}">
                        @foreach ($blocks as $block)
                            <option value="{{ $block['id'] }}">{{ $block['label'] }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            @else
                <span></span>
            @endif
            <button type="button" class="fi-chatai-action" x-on:click="navigator.clipboard?.writeText($refs['code_' + tab].innerText); copied = true; setTimeout(() => copied = false, 1500)">
                <x-filament::icon icon="heroicon-m-clipboard-document" class="fi-chatai-action-icon" />
                <span x-text="copied ? @js(__('chatai::chatai.editor.copied')) : @js(__('chatai::chatai.editor.copy'))"></span>
            </button>
        </div>
        @foreach ($blocks as $block)
            <pre class="fi-chatai-code-pre" x-show="tab === @js($block['id'])" x-ref="code_{{ $block['id'] }}"><code>{{ $block['code'] }}</code></pre>
        @endforeach
    @endif
</div>
