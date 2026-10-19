<?php

namespace Filament\ChatAi\Support;

use Carbon\CarbonInterface;
use Filament\ChatAi\Http\Controllers\PlaygroundController;
use Filament\ChatAi\Models\Usage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Totals for the usage page: cost and volume per day, user and model.
 */
final class UsageReport
{
    /**
     * @return array{totals: array<string, mixed>, by_day: list<array<string, mixed>>, by_user: list<array<string, mixed>>, by_model: list<array<string, mixed>>}
     */
    public static function for(CarbonInterface $from, CarbonInterface $to, ?string $panelId = null, ?string $tenantId = null): array
    {
        $base = fn (): Builder => Usage::query()
            ->whereBetween('created_at', [$from, $to])
            // Test chats of the tool editor cost money too.
            ->when($panelId, fn (Builder $q) => $q->whereIn('panel_id', [$panelId, $panelId.PlaygroundController::PANEL_SUFFIX]))
            ->when($tenantId !== null, fn (Builder $q) => $q->where('tenant_id', $tenantId));

        $sums = 'COUNT(*) as calls, SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END) as questions, SUM(tokens_in) as tokens_in, SUM(tokens_out) as tokens_out, SUM(cache_read_tokens) as cache_read, SUM(cache_write_tokens) as cache_write,'
            .' SUM(characters) as characters, SUM(seconds) as seconds, SUM(cost) as cost, SUM(CASE WHEN cost IS NULL THEN 1 ELSE 0 END) as unpriced';

        $totals = $base()->selectRaw($sums, [Usage::KIND_CHAT])->first();

        $byDay = $base()->selectRaw('DATE(created_at) as day, SUM(cost) as cost, SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END) as questions', [Usage::KIND_CHAT])
            ->groupByRaw('DATE(created_at)')->orderBy('day')->get()
            ->map(fn ($row): array => ['day' => (string) $row->day, 'cost' => round((float) $row->cost, 6), 'questions' => (int) $row->questions])
            ->all();

        $byUser = $base()->selectRaw('user_type, user_id, '.$sums, [Usage::KIND_CHAT])
            ->groupBy('user_type', 'user_id')->get()
            ->map(fn ($row): array => [...self::numbers($row), 'name' => self::userName($row->user_type, $row->user_id)])
            ->sortByDesc(fn (array $row): array => [$row['cost'], $row['calls']])->values()->all();

        $byModel = $base()->selectRaw('kind, model, '.$sums, [Usage::KIND_CHAT])
            ->groupBy('kind', 'model')->get()
            ->map(fn ($row): array => [...self::numbers($row), 'kind' => $row->kind, 'model' => $row->model])
            ->sortByDesc(fn (array $row): array => [$row['cost'], $row['calls']])->values()->all();

        return [
            'totals' => [...self::numbers($totals), 'currency' => Pricing::currency()],
            'by_day' => $byDay,
            'by_user' => $byUser,
            'by_model' => $byModel,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    protected static function numbers(?object $row): array
    {
        return [
            'calls' => (int) ($row->calls ?? 0),
            'questions' => (int) ($row->questions ?? 0),
            'tokens_in' => (int) ($row->tokens_in ?? 0),
            'tokens_out' => (int) ($row->tokens_out ?? 0),
            'cache_read' => (int) ($row->cache_read ?? 0),
            'cache_write' => (int) ($row->cache_write ?? 0),
            'characters' => (int) ($row->characters ?? 0),
            'seconds' => round((float) ($row->seconds ?? 0), 2),
            'cost' => round((float) ($row->cost ?? 0), 6),
            'unpriced' => (int) ($row->unpriced ?? 0),
        ];
    }

    protected static function userName(?string $type, mixed $id): string
    {
        $class = $type ? (Relation::getMorphedModel($type) ?? $type) : null;
        $user = $class && class_exists($class) ? $class::query()->find($id) : null;

        return (string) ($user?->name ?? $user?->email ?? ($id ? "#{$id}" : '—'));
    }
}
