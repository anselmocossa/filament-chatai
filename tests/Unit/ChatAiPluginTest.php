<?php

use Filament\ChatAi\ChatAiPlugin;
use Filament\ChatAi\Speech\AzureSpeech;
use Filament\Facades\Filament;

it('has the chatai id and keeps fluent settings', function () {
    $access = fn (): bool => false;

    $plugin = ChatAiPlugin::make()
        ->label('Assistente')
        ->canAccess($access)
        ->floatingWidget(false)
        ->page(false)
        ->systemPrompt('Be brief.');

    expect($plugin->getId())->toBe('chatai')
        ->and($plugin->getLabel())->toBe('Assistente')
        ->and($plugin->hasFloatingWidget())->toBeFalse()
        ->and($plugin->hasPage())->toBeFalse()
        ->and($plugin->getSystemPrompt())->toBe('Be brief.');
});

it('defaults the label and enables both surfaces', function () {
    $plugin = ChatAiPlugin::make();

    expect($plugin->getLabel())->toBe('ChatAI')
        ->and($plugin->hasFloatingWidget())->toBeTrue()
        ->and($plugin->hasPage())->toBeTrue();
});

it('publishes the config with sane defaults', function () {
    expect(config('chatai.max_iterations'))->toBe(6)
        ->and(config('chatai.timeout'))->toBe(60)
        ->and(config('chatai.rate_limit.per_minute'))->toBe(20);
});

it('is registered on the panel', function () {
    expect(Filament::getPanel('admin')->getPlugin('chatai'))->toBeInstanceOf(ChatAiPlugin::class);
});

it('turns voice on by default and passes the speech language to the browser', function () {
    expect(ChatAiPlugin::make()->hasVoice())->toBeTrue()
        ->and(ChatAiPlugin::make()->voice(false)->hasVoice())->toBeFalse()
        ->and(ChatAiPlugin::make()->speechLanguage('pt-PT')->getSpeechLanguage())->toBe('pt-PT')
        ->and(ChatAiPlugin::make()->getSpeechLanguage())->toBeNull();
});

it('uses the server voice from config and drops it when voice is off', function () {
    config(['chatai.speech.provider' => 'azure', 'chatai.speech.azure.key' => 'k', 'chatai.speech.azure.region' => 'westeurope']);

    expect(ChatAiPlugin::make()->resolveSpeech())->toBeInstanceOf(AzureSpeech::class)
        ->and(ChatAiPlugin::make()->voice(false)->resolveSpeech())->toBeNull()
        ->and(ChatAiPlugin::make()->getSpeechLanguage())->toBe('pt-PT');

    config(['chatai.speech.azure.key' => null]);

    expect(ChatAiPlugin::make()->resolveSpeech())->toBeNull();
});
