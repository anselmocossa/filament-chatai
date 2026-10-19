<?php

namespace Filament\ChatAi;

use Filament\ChatAi\Events\TurnCompleted;
use Filament\ChatAi\Listeners\RecordChatUsage;
use Filament\ChatAi\Livewire\FloatingChat;
use Filament\ChatAi\Llm\LlmClient;
use Filament\ChatAi\Llm\ProviderLlmClient;
use Filament\ChatAi\Models\Conversation;
use Filament\ChatAi\Policies\ConversationPolicy;
use Filament\Support\Assets\Css;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class ChatAiServiceProvider extends PackageServiceProvider
{
    public static string $name = 'chatai';

    public static string $viewNamespace = 'filament-chatai';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews(static::$viewNamespace)
            ->hasMigrations(['create_chatai_tables', 'create_chatai_settings_tables', 'create_chatai_tools_tables', 'create_chatai_models_table', 'create_chatai_provider_connections_table', 'update_chatai_budgets_table', 'update_chatai_models_effort_table']);
    }

    public function packageRegistered(): void
    {
        $this->app->bindIf(LlmClient::class, ProviderLlmClient::class);
    }

    public function packageBooted(): void
    {
        Gate::policy(Conversation::class, ConversationPolicy::class);

        Livewire::component('chatai-floating', FloatingChat::class);

        Event::listen(TurnCompleted::class, RecordChatUsage::class);

        // Loaded on request: only panels that use the plugin pull them in.
        FilamentAsset::register([
            Css::make('chatai', __DIR__.'/../resources/dist/chatai.css')->loadedOnRequest(),
            Js::make('chatai', __DIR__.'/../resources/dist/chatai.js')->loadedOnRequest(),
        ], 'anselmokossa/filament-chatai');
    }
}
