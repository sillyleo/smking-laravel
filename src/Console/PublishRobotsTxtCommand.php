<?php

declare(strict_types=1);

namespace Smking\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Smking\Laravel\Support\RobotsTxtBuilder;

/**
 * `php artisan smking:publish-robots` — write AI bot rules + Content-Signal
 * directive into `public/robots.txt`.
 *
 * Why a publish command instead of dynamic /robots.txt route handling:
 *
 *   nginx / Apache serve `public/robots.txt` directly when it exists —
 *   the Laravel kernel never runs, middleware never fires. The only
 *   reliable way to influence that response is to write the actual file.
 *
 * Why explicit command instead of writing on package install:
 *
 *   `composer require smking/laravel` should never silently mutate the
 *   customer's `public/` directory. The command is opt-in; running it is
 *   the customer's affirmative action that their robots.txt is OK to
 *   manage. Re-runs replace only the smking-managed block (fenced by
 *   {smking-aeo-block-start} / {smking-aeo-block-end}); customer rules
 *   above the block survive untouched.
 */
class PublishRobotsTxtCommand extends Command
{
    protected $signature = 'smking:publish-robots
        {--force : Re-write even when no changes are detected}';

    protected $description = 'Write AI bot rules + Content-Signal directive into public/robots.txt';

    public function handle(Filesystem $files, ConfigRepository $config): int
    {
        $bots = (array) $config->get('smking.robots.bots', []);
        $signal = $config->get('smking.robots.content_signal');
        $signal = is_string($signal) && $signal !== '' ? $signal : null;

        if ($bots === [] && $signal === null) {
            $this->error('Both smking.robots.bots and smking.robots.content_signal are empty — nothing to publish.');

            return self::INVALID;
        }

        $path = public_path('robots.txt');
        $existing = $files->exists($path) ? $files->get($path) : '';
        $merged = RobotsTxtBuilder::merge($existing, $bots, $signal);

        if ($existing === $merged && ! $this->option('force')) {
            $this->info('robots.txt already up-to-date — pass --force to rewrite anyway.');

            return self::SUCCESS;
        }

        $files->put($path, $merged);

        if ($existing === '') {
            $this->info("Created {$path} with smking AI bot block.");
        } elseif (RobotsTxtBuilder::hasManagedBlock($existing)) {
            $this->info("Updated smking AI bot block in {$path}.");
        } else {
            $this->info("Appended smking AI bot block to existing {$path}.");
        }

        $this->line('  Bots: '.(count($bots) > 0 ? implode(', ', array_keys($bots)) : '(none)'));
        $this->line('  Content-Signal: '.($signal ?? '(none)'));

        return self::SUCCESS;
    }
}
