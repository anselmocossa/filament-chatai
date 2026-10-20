<?php

namespace Filament\ChatAi\Launchpad;

use Filament\ChatAi\Http\Controllers\ConversationController;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Models\ToolAction;
use Filament\Launchpad\Launchpad\KpiResult;

/**
 * Launchpad tile: changes the assistant proposed that still wait for the
 * user's confirmation.
 */
class PendingKpi extends ChatAiKpi
{
    public static function key(): string
    {
        return 'chatai_pending';
    }

    public function label(): string
    {
        return __('chatai::chatai.launchpad.pending');
    }

    public function resolve(): KpiResult
    {
        $pending = ToolAction::query()
            ->where('status', ToolAction::PENDING)
            ->whereIn('conversation_id', Conversation::forUser(auth()->user(), ConversationController::tenantId())
                ->where('panel_id', $this->panelId())->select('id'))
            ->count();

        $result = KpiResult::make($pending)->unit(__('chatai::chatai.launchpad.pending_unit'));

        return $pending > 0
            ? $result->badge(__('chatai::chatai.launchpad.pending_badge'), '#fef3c7', '#92400e')->trend(__('chatai::chatai.launchpad.pending_trend'), 'danger')
            : $result->trend(__('chatai::chatai.launchpad.pending_none'), 'gray');
    }
}
