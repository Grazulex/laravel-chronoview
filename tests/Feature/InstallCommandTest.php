<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('publishes the config, migrates and syncs', function (): void {
    $target = config_path('chronoview.php');
    File::delete($target);

    $this->artisan('chronoview:install')
        ->expectsOutputToContain('ChronoView is ready')
        ->assertSuccessful();

    expect(File::exists($target))->toBeTrue();

    File::delete($target);
});
