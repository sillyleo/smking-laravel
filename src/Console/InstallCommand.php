<?php

declare(strict_types=1);

namespace Smking\Laravel\Console;

use Illuminate\Console\Command;

/**
 * `php artisan smking:install` — install entry point.
 *
 * Two install paths:
 *
 *   1. Full interactive wizard (preferred): `npx @soloworks/smking-wizard`.
 *      The Node CLI scans customer routes, walks OAuth, writes env keys,
 *      publishes config, registers controllers, fixes the route:cache
 *      webhook hole (audit handoff #7), and runs smking:doctor to verify.
 *      Handles failure with an AI agent retry loop.
 *
 *   2. Manual fallback (for air-gapped / scripted installs): this command
 *      prints the steps for a customer to do by hand. Each step is a
 *      separate artisan command or file edit; the customer reads, executes,
 *      moves on. v0.13.0 deliberately keeps the manual path documented in
 *      one place rather than scattering it across README + multiple
 *      vendor:publish tags.
 *
 * This command does NOT actively modify files itself — the wizard's
 * value is the interactive OAuth + route scanning, which is much cleaner
 * to implement in Node than in a PHP command. Keeping this Laravel
 * command lightweight avoids duplicate logic drift.
 */
class InstallCommand extends Command
{
    protected $signature = 'smking:install';

    protected $description = 'Show smking install instructions (preferred: npx @soloworks/smking-wizard)';

    public function handle(): int
    {
        $this->newLine();
        $this->line('<info>smking — install</info>');
        $this->newLine();
        $this->line('For the full interactive wizard (OAuth, route scan, doctor verification):');
        $this->newLine();
        $this->line('  <comment>npx @soloworks/smking-wizard</comment>');
        $this->newLine();
        $this->line('Manual install (if the wizard cannot run in your environment):');
        $this->newLine();
        $this->line('  1. Set <comment>SMKING_API_KEY</comment> and <comment>SMKING_BASE_URL</comment> in .env');
        $this->line('  2. <comment>php artisan vendor:publish --tag=smking-config</comment>');
        $this->line('  3. Edit <comment>config/smking.php</comment> — fill <comment>only</comment> with the paths you want smking on (e.g. [\'products/*\', \'blog/*\']).');
        $this->line('     Empty <comment>only</comment> = whole site. Leave <comment>except</comment> at the framework defaults.');
        $this->line('  4. <comment>php artisan smking:publish-robots</comment>');
        $this->line('  5. Add to <comment>routes/web.php</comment>:');
        $this->newLine();
        $this->line('       Route::get(\'/sitemap.xml\', \\Smking\\Laravel\\Http\\Controllers\\SitemapController::class);');
        $this->line('       Route::get(\'/llms.txt\', \\Smking\\Laravel\\Http\\Controllers\\LlmsTxtController::class);');
        $this->newLine();
        $this->line('  6. Add to <comment>routes/api.php</comment> (required for production with <comment>php artisan route:cache</comment>):');
        $this->newLine();
        $this->line('       require base_path(\'vendor/smking/laravel/routes/webhook.php\');');
        $this->newLine();
        $this->line('  7. <comment>php artisan smking:doctor</comment> — verify the install');
        $this->newLine();
        $this->line('<comment>⚠ CMS surface</comment>: the <x-smking-cms /> Blade component is available but the');
        $this->line('  SaaS-side capability is OFF by default. Production use waits for audit #6');
        $this->line('  hardening (v0.13.x patch). Until then, enable CMS only in staging via the dashboard.');
        $this->newLine();

        return self::SUCCESS;
    }
}
