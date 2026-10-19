@php
    use Filament\Support\Facades\FilamentAsset;
    use Illuminate\View\ComponentAttributeBag;
    use Filament\Widgets\View\Components\ChartWidgetComponent;
@endphp

{{-- The component catalog. `component` is in scope (from x-for). --}}

{{-- kpi --}}
<template x-if="component.type === 'kpi'">
    <div class="fi-chatai-kpi">
        <p class="fi-chatai-component-title" x-show="component.title" x-text="component.title"></p>
        <div class="fi-chatai-kpi-grid" :style="'--chatai-kpi-cols:' + component.items.length">
            <template x-for="(item, i) in component.items" :key="i">
                <div class="fi-chatai-kpi-item">
                    <span class="fi-chatai-kpi-label" x-text="item.label"></span>
                    <span class="fi-chatai-kpi-value">
                        <span x-text="formatKpi(item.value)"></span>
                        <small x-show="item.unit" x-text="item.unit"></small>
                    </span>
                    <template x-if="typeof item.change === 'number' || item.description">
                        <span class="fi-chatai-kpi-change" :class="'fi-chatai-trend-' + (item.trend || 'flat')">
                            <template x-if="typeof item.change === 'number'">
                                <span x-text="(item.trend === 'up' ? '▲ ' : item.trend === 'down' ? '▼ ' : '') + formatKpi(Math.abs(item.change)) + '%'"></span>
                            </template>
                            <span class="fi-chatai-kpi-description" x-show="item.description" x-text="item.description"></span>
                        </span>
                    </template>
                </div>
            </template>
        </div>
    </div>
</template>

{{-- table --}}
<template x-if="component.type === 'table'">
    <div class="fi-chatai-table-wrap" x-data="{ sortKey: null, sortDir: 1 }">
        <p class="fi-chatai-component-title" x-show="component.title" x-text="component.title"></p>
        <div class="fi-chatai-table-scroll">
            <table class="fi-chatai-table">
                <thead>
                    <tr>
                        <template x-for="column in component.columns" :key="column.key">
                            <th :class="{ 'fi-chatai-num': ['number', 'money'].includes(column.format) }">
                                <button type="button"
                                        x-on:click="sortDir = sortKey === column.key ? -sortDir : 1; sortKey = column.key"
                                        x-text="column.label + (sortKey === column.key ? (sortDir > 0 ? ' ↑' : ' ↓') : '')"></button>
                            </th>
                        </template>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="(row, r) in (sortKey ? [...component.rows].sort((a, b) => (a[sortKey] > b[sortKey] ? 1 : a[sortKey] < b[sortKey] ? -1 : 0) * sortDir) : component.rows)" :key="r">
                        <tr>
                            <template x-for="column in component.columns" :key="column.key">
                                <td :class="{ 'fi-chatai-num': ['number', 'money'].includes(column.format) }">
                                    <template x-if="column.format === 'badge'">
                                        <span class="fi-chatai-badge" x-text="formatCell(row[column.key], column.format)"></span>
                                    </template>
                                    <template x-if="column.format !== 'badge'">
                                        <span x-text="formatCell(row[column.key], column.format)"></span>
                                    </template>
                                </td>
                            </template>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div class="fi-chatai-table-footer" x-show="component.note || component.link">
            <span x-text="component.note"></span>
            <a x-show="component.link" :href="component.link" class="fi-chatai-link">{{ __('chatai::chatai.see_all') }} →</a>
        </div>
    </div>
</template>

{{-- chart: Filament's own Chart.js component, so it matches every other chart in the panel --}}
<template x-if="component.type === 'chart'">
    <div class="fi-chatai-chart fi-wi-chart">
        <p class="fi-chatai-component-title" x-show="component.title" x-text="component.title"></p>
        <div class="fi-wi-chart-image">
            <div
                x-load
                x-load-src="{{ FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                wire:ignore
                :data-chart-type="component.chart_type"
                x-data="chart({ cachedData: chartData(component), options: chartOptions(component), type: component.chart_type })"
                {{ (new ComponentAttributeBag)->color(ChartWidgetComponent::class, 'primary')->class(['fi-wi-chart-frame', 'fi-wi-chart-canvas-ctn', 'fi-wi-chart-frame-no-aspect-ratio']) }}
            >
                <canvas x-ref="canvas" aria-hidden="true"></canvas>
                <span x-ref="backgroundColorElement" class="fi-wi-chart-bg-color"></span>
                <span x-ref="borderColorElement" class="fi-wi-chart-border-color"></span>
                <span x-ref="gridColorElement" class="fi-wi-chart-grid-color"></span>
                <span x-ref="textColorElement" class="fi-wi-chart-text-color"></span>
                <span x-ref="tooltipBackgroundColorElement" class="fi-wi-chart-tooltip-bg-color"></span>
                <span x-ref="tooltipTextColorElement" class="fi-wi-chart-tooltip-text-color"></span>
                <span x-ref="tooltipBorderColorElement" class="fi-wi-chart-tooltip-border-color"></span>
            </div>
        </div>
    </div>
</template>

{{-- suggestions --}}
<template x-if="component.type === 'suggestions'">
    <div class="fi-chatai-suggestions">
        <template x-for="(item, i) in component.items" :key="i">
            <button type="button" class="fi-chatai-suggestion" :disabled="busy" x-on:click="ask(item.prompt)" x-text="item.label"></button>
        </template>
    </div>
</template>

{{-- file (only built by server tools, e.g. create_document) --}}
<template x-if="component.type === 'file'">
    <a class="fi-chatai-file" :href="component.url" download>
        <span class="fi-chatai-file-icon"><x-filament::icon icon="heroicon-o-document-arrow-down" class="fi-chatai-file-svg" /></span>
        <span class="fi-chatai-file-text">
            <span class="fi-chatai-file-title" x-text="component.title || component.name"></span>
            <span class="fi-chatai-file-meta" x-text="component.name + ' · ' + formatSize(component.size)"></span>
        </span>
        <span class="fi-chatai-file-action">{{ __('chatai::chatai.download') }}</span>
    </a>
</template>

{{-- confirm: a change the assistant proposed; it runs only after Confirm --}}
<template x-if="component.type === 'confirm'">
    <div class="fi-chatai-confirm" :class="'fi-chatai-confirm-' + component.status">
        <div class="fi-chatai-confirm-head">
            <span class="fi-chatai-confirm-icon"><x-filament::icon icon="heroicon-o-pencil-square" class="fi-chatai-file-svg" /></span>
            <span class="fi-chatai-confirm-title" x-text="component.title"></span>
            <span class="fi-chatai-confirm-badge" x-show="component.status !== 'pending'"
                  x-text="{ done: @js(__('chatai::chatai.actions.done_badge')), cancelled: @js(__('chatai::chatai.actions.cancelled_badge')), failed: @js(__('chatai::chatai.actions.failed_badge')) }[component.status] || ''"></span>
        </div>
        {{-- While waiting: an editable form built from the tool's schema --}}
        <template x-if="component.status === 'pending'">
            <form class="fi-chatai-form" x-on:submit.prevent="answerAction(component, true)">
                <template x-for="(field, i) in component.fields" :key="field.key || i">
                    <label class="fi-chatai-form-field" :class="{ 'fi-wide': field.type === 'textarea', 'fi-check': field.type === 'checkbox', 'fi-error': component.errors?.[field.key] }">
                        <span class="fi-chatai-form-label" x-show="field.type !== 'checkbox'">
                            <span x-text="field.label"></span><span class="fi-chatai-form-required" x-show="field.required">*</span>
                        </span>
                        <template x-if="field.type === 'select'">
                            <select class="fi-chatai-form-input" x-model="field.value" :required="field.required">
                                <option value=""></option>
                                <template x-for="option in field.options" :key="option.value">
                                    <option :value="option.value" x-text="option.label" :selected="option.value === field.value"></option>
                                </template>
                            </select>
                        </template>
                        <template x-if="field.type === 'textarea'">
                            <textarea class="fi-chatai-form-input" rows="3" x-model="field.value" :required="field.required"></textarea>
                        </template>
                        <template x-if="field.type === 'checkbox'">
                            <span class="fi-chatai-form-checkbox"><input type="checkbox" x-model="field.value"> <span x-text="field.label"></span></span>
                        </template>
                        <template x-if="! ['select', 'textarea', 'checkbox'].includes(field.type)">
                            <input class="fi-chatai-form-input" :type="field.type" x-model="field.value" :required="field.required" :placeholder="field.help || ''">
                        </template>
                        <span class="fi-chatai-form-error" x-show="component.errors?.[field.key]" x-text="component.errors?.[field.key]"></span>
                    </label>
                </template>
                <button type="submit" hidden></button>
            </form>
        </template>

        {{-- Answered: what it ran with --}}
        <template x-if="component.status !== 'pending'">
            <dl class="fi-chatai-confirm-fields">
                <template x-for="(field, i) in component.fields.filter((f) => f.value !== '' && f.value !== null)" :key="field.key || i">
                    <div class="fi-chatai-confirm-field">
                        <dt x-text="field.label"></dt>
                        <dd x-text="field.type === 'checkbox' ? (field.value ? '✓' : '—') : (field.options?.find((o) => o.value === field.value)?.label || field.value)"></dd>
                    </div>
                </template>
            </dl>
        </template>

        <template x-if="component.result?.length">
            <dl class="fi-chatai-confirm-fields fi-chatai-confirm-result">
                <template x-for="(field, i) in component.result" :key="i">
                    <div class="fi-chatai-confirm-field">
                        <dt x-text="field.label"></dt>
                        <dd x-text="field.value"></dd>
                    </div>
                </template>
            </dl>
        </template>
        <div class="fi-chatai-confirm-actions" x-show="component.status === 'pending'">
            <span class="fi-chatai-confirm-hint">{{ __('chatai::chatai.actions.waiting') }}</span>
            <button type="button" class="fi-chatai-confirm-btn" x-on:click="answerAction(component, false)" :disabled="!! component.busy">{{ __('chatai::chatai.actions.cancel') }}</button>
            <button type="button" class="fi-chatai-confirm-btn fi-primary" x-on:click="answerAction(component, true)" :disabled="!! component.busy">
                <x-filament::icon icon="heroicon-m-check" class="fi-chatai-chip-icon" />
                {{ __('chatai::chatai.actions.confirm') }}
            </button>
        </div>
    </div>
</template>

{{-- confirm_batch: several changes (e.g. rows of a file), reviewed in a paginated list and approved once --}}
<template x-if="component.type === 'confirm_batch'">
    <div class="fi-chatai-confirm fi-chatai-batch" :class="'fi-chatai-confirm-' + component.status">
        <div class="fi-chatai-confirm-head">
            <span class="fi-chatai-confirm-icon"><x-filament::icon icon="heroicon-o-queue-list" class="fi-chatai-file-svg" /></span>
            <span class="fi-chatai-confirm-title">
                <span x-text="component.title"></span>
                <span class="fi-chatai-batch-count" x-text="@js(__('chatai::chatai.actions.items')).replace(':count', component.rows.length)"></span>
            </span>
            <span class="fi-chatai-confirm-badge" x-show="component.status !== 'pending'"
                  x-text="{ done: @js(__('chatai::chatai.actions.done_badge')), cancelled: @js(__('chatai::chatai.actions.cancelled_badge')), failed: @js(__('chatai::chatai.actions.failed_badge')), partial: @js(__('chatai::chatai.actions.partial_badge')) }[component.status] || ''"></span>
        </div>

        <div class="fi-chatai-table-scroll">
            <table class="fi-chatai-table fi-chatai-batch-table">
                <thead>
                    <tr>
                        <th class="fi-chatai-batch-check">
                            <input type="checkbox" x-show="component.status === 'pending'" :checked="component.rows.every((r) => r.status !== 'pending' || ! r.excluded)" x-on:change="toggleAllRows(component, $event.target.checked)">
                        </th>
                        <template x-for="column in component.columns" :key="column.key">
                            <th x-text="column.label"></th>
                        </template>
                        <th x-show="component.status !== 'pending'"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in batchPage(component).rows" :key="row.action_id">
                        <tr :class="{ 'fi-excluded': row.excluded && row.status === 'pending' }">
                            <td class="fi-chatai-batch-check">
                                <input type="checkbox" x-show="row.status === 'pending'" :checked="! row.excluded" x-on:change="row.excluded = ! $event.target.checked">
                            </td>
                            <template x-for="column in component.columns" :key="column.key">
                                <td x-text="row.values[column.key] || '—'"></td>
                            </template>
                            <td x-show="component.status !== 'pending'" class="fi-chatai-batch-state">
                                <span :class="'fi-chatai-batch-' + row.status" :title="row.error || ''"
                                      x-text="{ done: '✓', failed: '✕ ' + (row.error || ''), skipped: @js(__('chatai::chatai.actions.skipped_badge')), cancelled: @js(__('chatai::chatai.actions.cancelled_badge')), pending: '…' }[row.status] || ''"></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="fi-chatai-batch-pager" x-show="component.rows.length > 10">
            <span x-text="@js(__('chatai::chatai.actions.page')).replace(':from', batchPage(component).from).replace(':to', batchPage(component).to).replace(':total', batchPage(component).total)"></span>
            <button type="button" class="fi-chatai-confirm-btn" x-on:click="component.page = Math.max(0, (component.page || 0) - 1)" :disabled="! component.page">‹</button>
            <button type="button" class="fi-chatai-confirm-btn" x-on:click="component.page = (component.page || 0) + 1" :disabled="batchPage(component).last">›</button>
        </div>

        <div class="fi-chatai-confirm-actions" x-show="component.status === 'pending'">
            <span class="fi-chatai-confirm-hint" x-text="@js(__('chatai::chatai.actions.selected')).replace(':count', batchSelected(component).length)"></span>
            <button type="button" class="fi-chatai-confirm-btn" x-on:click="answerBatch(component, false)" :disabled="!! component.busy">{{ __('chatai::chatai.actions.cancel_all') }}</button>
            <button type="button" class="fi-chatai-confirm-btn fi-primary" x-on:click="answerBatch(component, true)" :disabled="!! component.busy || batchSelected(component).length === 0">
                <x-filament::icon icon="heroicon-m-check" class="fi-chatai-chip-icon" />
                <span x-text="@js(__('chatai::chatai.actions.confirm_all')).replace(':count', batchSelected(component).length)"></span>
            </button>
        </div>
    </div>
</template>

{{-- filled: what the assistant wrote into the form on screen; the user saves --}}
<template x-if="component.type === 'filled'">
    <div class="fi-chatai-confirm fi-chatai-filled-card">
        <div class="fi-chatai-confirm-head">
            <span class="fi-chatai-confirm-icon"><x-filament::icon icon="heroicon-o-pencil" class="fi-chatai-file-svg" /></span>
            <span class="fi-chatai-confirm-title">{{ __('chatai::chatai.screen.filled') }}</span>
        </div>
        <dl class="fi-chatai-confirm-fields">
            <template x-for="field in component.fields" :key="field.path">
                <div class="fi-chatai-confirm-field">
                    <dt x-text="field.label"></dt>
                    <dd x-text="field.value"></dd>
                </div>
            </template>
        </dl>
    </div>
</template>

{{-- navigate: the place the browser opens when the answer ends --}}
<template x-if="component.type === 'navigate'">
    <a class="fi-chatai-file fi-chatai-navigate" :href="component.url" wire:navigate>
        <span class="fi-chatai-file-icon"><x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="fi-chatai-file-svg" /></span>
        <span class="fi-chatai-file-text">
            <span class="fi-chatai-file-meta">{{ __('chatai::chatai.screen.opening') }}</span>
            <span class="fi-chatai-file-title" x-text="component.label"></span>
        </span>
        <span class="fi-chatai-file-action">›</span>
    </a>
</template>
