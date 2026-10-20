<?php

namespace Filament\ChatAi\Commands;

use Filament\ChatAi\Settings\ChatAiSettings;
use Filament\ChatAi\Support\AccessList;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;

/**
 * Sets the assistant up in one go: migrations, assets, the plugin on the
 * chosen panels and who manages it. Everything else is done on screen.
 * Never passes --force to migrate: in production Laravel asks first.
 */
class InstallCommand extends Command
{
    public const PLUGIN = '\\Filament\\ChatAi\\ChatAiPlugin::make()';

    protected $signature = 'chatai:install
        {--panel=* : Panel ids to add the assistant to (default: ask, or every panel without interaction)}
        {--manager=* : E-mails or role names that may manage the assistant}
        {--migrate : Run the migrations without asking}';

    protected $description = 'Install Filament ChatAI: migrations, assets, the plugin on your panels and who manages it.';

    public function handle(): int
    {
        $this->components->info('Installing Filament ChatAI');

        $this->call('vendor:publish', ['--tag' => 'chatai-migrations']);

        if ($this->option('migrate') || $this->confirm('Run the migrations now?', true)) {
            $this->call('migrate');
        }

        $this->call('filament:assets');

        $panels = $this->registerOnPanels();
        $this->saveManagers();

        $this->newLine();
        $this->components->info('Filament ChatAI is installed.');

        foreach ($panels as $id) {
            $this->line("  Open <comment>/{$id}/chatai/providers</comment> to add an AI provider key, then <comment>/{$id}/chatai</comment> to chat.");
        }

        $this->line('  Everything else — models, tools, voice, permissions, limits — is set on screen, under the ChatAI menu.');

        return self::SUCCESS;
    }

    /**
     * Adds the plugin to the chosen panel providers.
     *
     * @return array<int, string> the panel ids that have the plugin
     */
    protected function registerOnPanels(): array
    {
        $providers = $this->panelProviders();

        if ($providers === []) {
            $this->components->warn('No Filament panel provider found in app/Providers. Add '.self::PLUGIN.' to your panel\'s plugins.');

            return [];
        }

        $chosen = $this->option('panel');

        if ($chosen === []) {
            $chosen = $this->input->isInteractive() && count($providers) > 1
                ? (array) $this->choice('Which panels get the assistant? (comma-separated)', array_keys($providers), implode(',', array_keys($providers)), null, true)
                : array_keys($providers);
        }

        $done = [];

        foreach ($chosen as $id) {
            $path = $providers[$id] ?? null;

            if ($path === null) {
                $this->components->warn("No panel with id [{$id}].");

                continue;
            }

            $done[] = $id;
            $code = (string) file_get_contents($path);

            if (str_contains($code, 'ChatAiPlugin')) {
                $this->components->twoColumnDetail("Panel [{$id}]", 'already has the assistant');

                continue;
            }

            $updated = self::addPlugin($code);

            if ($updated === null) {
                array_pop($done);
                $this->components->warn("Could not edit {$path}. Add ->plugin(".self::PLUGIN.') to the panel yourself.');

                continue;
            }

            file_put_contents($path, $updated);
            $this->components->twoColumnDetail("Panel [{$id}]", 'assistant added');
        }

        return $done;
    }

    /**
     * Panel id → provider file, for every PanelProvider under app/Providers.
     *
     * @return array<string, string>
     */
    protected function panelProviders(): array
    {
        $dir = app_path('Providers');

        if (! is_dir($dir)) {
            return [];
        }

        $providers = [];

        foreach (Finder::create()->files()->in($dir)->name('*.php') as $file) {
            $code = $file->getContents();

            if (preg_match('/extends\s+(\\\\?Filament\\\\)?PanelProvider\b/', $code) && preg_match('/->id\(\s*[\'"]([^\'"]+)[\'"]\s*\)/', $code, $id)) {
                $providers[$id[1]] = $file->getRealPath();
            }
        }

        ksort($providers);

        return $providers;
    }

    /**
     * The provider's code with ->plugin(...) right after ->id('...'), or null when there is no ->id() line.
     */
    public static function addPlugin(string $code): ?string
    {
        if (! preg_match('/^([ \t]*)->id\(\s*[\'"][^\'"]+[\'"]\s*\)[^\n]*$/m', $code, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $end = $match[0][1] + strlen($match[0][0]);

        return substr($code, 0, $end)."\n".$match[1][0].'->plugin('.self::PLUGIN.')'.substr($code, $end);
    }

    protected function saveManagers(): void
    {
        $managers = AccessList::normalize($this->option('manager'));

        if ($managers === [] && $this->input->isInteractive()) {
            $managers = AccessList::normalize((string) $this->ask('Who manages the assistant (providers, keys, costs)? E-mails or role names, comma-separated', ''));
        }

        if ($managers === []) {
            $this->line('  Nobody manages the assistant yet (except Shield\'s super admin). Set CHATAI_MANAGERS=you@example.com in .env, or run this again with --manager.');

            return;
        }

        if (! Schema::hasTable('chatai_settings')) {
            $this->line('  Run the migrations, then add CHATAI_MANAGERS='.implode(',', $managers).' to .env.');

            return;
        }

        ChatAiSettings::save(['access.managers' => $managers]);
        $this->components->twoColumnDetail('Managers', implode(', ', $managers));
    }
}
