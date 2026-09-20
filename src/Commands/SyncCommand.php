<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Commands;

use Grazulex\ChronoView\Support\Recorder;
use Illuminate\Console\Command;

final class SyncCommand extends Command
{
    protected $signature = 'chronoview:sync';

    protected $description = 'Synchronise the scheduled tasks into the ChronoView tables';

    public function handle(Recorder $recorder): int
    {
        $count = $recorder->syncSchedule();

        $this->components->info(sprintf('%d %s synced.', $count, $count === 1 ? 'task' : 'tasks'));

        return self::SUCCESS;
    }
}
