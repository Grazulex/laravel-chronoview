<?php

declare(strict_types=1);

use Grazulex\ChronoView\ChronoView;
use Grazulex\ChronoView\Facades\ChronoView as ChronoViewFacade;

it('merges the package configuration', function (): void {
    expect(config('chronoview.path'))->toBe('chronoview')
        ->and(config('chronoview.check.grace'))->toBe(90)
        ->and(config('chronoview.table_prefix'))->toBe('chronoview_');
});

it('binds the manager as a singleton behind the facade', function (): void {
    expect(app(ChronoView::class))->toBe(app('chronoview'))
        ->and(ChronoViewFacade::enabled())->toBeTrue();
});
