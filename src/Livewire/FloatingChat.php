<?php

namespace Filament\ChatAi\Livewire;

use Filament\ChatAi\ChatAiPlugin;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Only a host: the chat itself is Alpine. Being a Livewire component gives the
 * charts the `$wire` context Filament's Chart.js component expects.
 */
class FloatingChat extends Component
{
    public function render(): View
    {
        $plugin = ChatAiPlugin::get();

        return view('filament-chatai::floating', [
            'label' => $plugin->getLabel(),
            'config' => $plugin->clientConfig(remember: true),
        ]);
    }
}
