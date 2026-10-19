@php
    use Filament\Support\Facades\FilamentAsset;
    use Filament\Widgets\View\Components\ChartWidgetComponent;
    use Illuminate\View\ComponentAttributeBag;

    $t = fn (string $key, array $replace = []) => __('chatai::chatai.usage.' . $key, $replace);
    $totals = $report['totals'];
    $currency = $totals['currency'];
    $money = fn (float $value) => number_format($value, $value > 0 && $value < 1 ? 4 : 2) . ' ' . $currency;
    $int = fn (int|float $value) => number_format($value, 0, ',', ' ');
    $minutes = fn (float $seconds) => number_format($seconds / 60, 1);
    // Every input token the model read, cached or not, plus output.
    $tokens = fn (array $row) => $row['tokens_in'] + $row['cache_read'] + $row['cache_write'] + $row['tokens_out'];
    $kinds = ['chat' => $t('kinds.chat'), 'speech' => $t('kinds.speech'), 'transcription' => $t('kinds.transcription')];
@endphp

<x-filament-panels::page>
    <div class="fi-chatai-usage">
        <div class="fi-chatai-usage-toolbar">
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="period" aria-label="{{ $t('period') }}">
                    @foreach ($periods as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        <div class="fi-chatai-kpi-grid fi-chatai-usage-kpis" style="--chatai-kpi-cols: 4">
            <div class="fi-chatai-kpi-item">
                <span class="fi-chatai-kpi-label">{{ $t('cost') }}</span>
                <span class="fi-chatai-kpi-value">{{ $money($totals['cost']) }}</span>
                @if ($totals['unpriced'] > 0)
                    <span class="fi-chatai-kpi-description">{{ $t('unpriced', ['count' => $totals['unpriced']]) }}</span>
                @endif
            </div>
            <div class="fi-chatai-kpi-item">
                <span class="fi-chatai-kpi-label">{{ $t('questions') }}</span>
                <span class="fi-chatai-kpi-value">{{ $int($totals['questions']) }}</span>
                @if ($totals['questions'] > 0)
                    <span class="fi-chatai-kpi-description">{{ $t('per_question', ['cost' => $money($totals['cost'] / max(1, $totals['questions']))]) }}</span>
                @endif
            </div>
            <div class="fi-chatai-kpi-item">
                <span class="fi-chatai-kpi-label">{{ $t('tokens') }}</span>
                @php($input = $totals['tokens_in'] + $totals['cache_read'] + $totals['cache_write'])
                <span class="fi-chatai-kpi-value">{{ $int($input + $totals['tokens_out']) }}</span>
                <span class="fi-chatai-kpi-description">{{ $t('tokens_split', ['in' => $int($input), 'out' => $int($totals['tokens_out'])]) }}</span>
                @if ($totals['cache_read'] > 0)
                    <span class="fi-chatai-kpi-description">{{ $t('cache', ['percent' => round(100 * $totals['cache_read'] / max(1, $input))]) }}</span>
                @endif
            </div>
            <div class="fi-chatai-kpi-item">
                <span class="fi-chatai-kpi-label">{{ $t('voice') }}</span>
                <span class="fi-chatai-kpi-value">{{ $int($totals['characters']) }}</span>
                <span class="fi-chatai-kpi-description">{{ $t('voice_split', ['minutes' => $minutes($totals['seconds'])]) }}</span>
            </div>
        </div>

        <section class="fi-chatai-component fi-chatai-usage-card">
            <p class="fi-chatai-component-title">{{ $t('by_day') }}</p>
            @if (count($report['by_day']) > 0)
                <div class="fi-chatai-chart fi-wi-chart" wire:key="usage-chart-{{ $period }}">
                    <div class="fi-wi-chart-image">
                        <div
                            x-load
                            x-load-src="{{ FilamentAsset::getAlpineComponentSrc('chart', 'filament/widgets') }}"
                            wire:ignore
                            x-data="chart({
                                cachedData: @js(['labels' => array_column($report['by_day'], 'day'), 'datasets' => [['label' => $t('cost') . ' (' . $currency . ')', 'data' => array_column($report['by_day'], 'cost')], ['label' => $t('questions'), 'data' => array_column($report['by_day'], 'questions'), 'type' => 'line', 'yAxisID' => 'y1']]]),
                                options: @js(['plugins' => ['legend' => ['display' => true]], 'scales' => ['y' => ['beginAtZero' => true], 'y1' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['drawOnChartArea' => false]]]]),
                                type: 'bar',
                            })"
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
            @else
                <p class="fi-chatai-usage-empty">{{ $t('empty') }}</p>
            @endif
        </section>

        <div class="fi-chatai-usage-columns">
            <section class="fi-chatai-component fi-chatai-table-wrap">
                <p class="fi-chatai-component-title">{{ $t('by_user') }}</p>
                <div class="fi-chatai-table-scroll">
                    <table class="fi-chatai-table">
                        <thead><tr>
                            <th>{{ $t('user') }}</th>
                            <th class="fi-chatai-num">{{ $t('questions') }}</th>
                            <th class="fi-chatai-num">{{ $t('tokens') }}</th>
                            <th class="fi-chatai-num">{{ $t('cost') }}</th>
                        </tr></thead>
                        <tbody>
                            @forelse ($report['by_user'] as $row)
                                <tr>
                                    <td>{{ $row['name'] }}</td>
                                    <td class="fi-chatai-num">{{ $int($row['questions']) }}</td>
                                    <td class="fi-chatai-num">{{ $int($tokens($row)) }}</td>
                                    <td class="fi-chatai-num">{{ $money($row['cost']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="fi-chatai-usage-empty">{{ $t('empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="fi-chatai-component fi-chatai-table-wrap">
                <p class="fi-chatai-component-title">{{ $t('by_model') }}</p>
                <div class="fi-chatai-table-scroll">
                    <table class="fi-chatai-table">
                        <thead><tr>
                            <th>{{ $t('model') }}</th>
                            <th>{{ $t('kind') }}</th>
                            <th class="fi-chatai-num">{{ $t('volume') }}</th>
                            <th class="fi-chatai-num">{{ $t('cost') }}</th>
                        </tr></thead>
                        <tbody>
                            @forelse ($report['by_model'] as $row)
                                <tr>
                                    <td>{{ $row['model'] ?? '—' }}</td>
                                    <td>{{ $kinds[$row['kind']] ?? $row['kind'] }}</td>
                                    <td class="fi-chatai-num">
                                        @switch($row['kind'])
                                            @case('speech') {{ $t('characters', ['count' => $int($row['characters'])]) }} @break
                                            @case('transcription') {{ $t('minutes', ['count' => $minutes($row['seconds'])]) }} @break
                                            @default {{ $t('tokens_count', ['count' => $int($tokens($row))]) }}
                                        @endswitch
                                    </td>
                                    <td class="fi-chatai-num">{{ $row['unpriced'] === $row['calls'] ? $t('no_price') : $money($row['cost']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="fi-chatai-usage-empty">{{ $t('empty') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        <p class="fi-chatai-usage-note">{{ $t('note') }}</p>
    </div>
</x-filament-panels::page>
