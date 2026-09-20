<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Commands;

use Illuminate\Console\Command;

final class InstallCommand extends Command
{
    protected $signature = 'chronoview:install';

    protected $description = 'Publish the configuration, run the migrations and sync the schedule';

    public function handle(): int
    {
        $this->components->task('Publishing configuration', fn (): bool => $this->callSilently('vendor:publish', ['--tag' => 'chronoview-config']) === self::SUCCESS);
        $this->components->task('Running migrations', fn (): bool => $this->callSilently('migrate', ['--force' => true]) === self::SUCCESS);
        $this->components->task('Syncing the schedule', fn (): bool => $this->callSilently('chronoview:sync') === self::SUCCESS);

        $this->newLine();
        $this->components->info('ChronoView is ready: ' . url((string) config('chronoview.path', 'chronoview')));

        return self::SUCCESS;
    }
}
