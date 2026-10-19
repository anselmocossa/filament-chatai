<?php

namespace Filament\ChatAi\Pages;

use BackedEnum;
use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Models\Conversation;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;

class ChatAiPage extends Page
{
    protected static ?string $slug = 'chatai';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 1000;

    protected string $view = 'filament-chatai::pages.chatai';

    public ?string $conversation = null;

    protected $queryString = ['conversation'];

    public function mount(): void
    {
        // Only hand the browser a conversation it may open.
        if ($this->conversation !== null) {
            $owned = Conversation::query()->whereKey($this->conversation)->first()?->isOwnedBy(auth()->user());

            if (! $owned) {
                $this->conversation = null;
            }
        }
    }

    public static function canAccess(): bool
    {
        $plugin = ChatAiPlugin::get();

        return $plugin->hasPage() && $plugin->isAccessible();
    }

    public static function getNavigationLabel(): string
    {
        return ChatAiPlugin::get()->getLabel();
    }

    public function getTitle(): string|Htmlable
    {
        return ChatAiPlugin::get()->getLabel();
    }

    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return Width::Full;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $plugin = ChatAiPlugin::get();

        return [
            'label' => $plugin->getLabel(),
            'config' => $plugin->clientConfig(remember: false, withSidebar: true, conversationId: $this->conversation),
        ];
    }
}
