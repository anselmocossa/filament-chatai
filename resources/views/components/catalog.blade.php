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
