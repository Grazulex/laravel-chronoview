<?php

declare(strict_types=1);

use Grazulex\ChronoView\Facades\ChronoView;
use Illuminate\Support\Facades\Gate;

it('denies access outside local without any rule', function (): void {
    $this->get('/chronoview')->assertForbidden();
});

it('allows access in the local environment by default', function (): void {
    app()->detectEnvironment(fn (): string => 'local');

    $this->get('/chronoview')->assertOk();
});

it('honours the auth callback', function (): void {
    ChronoView::auth(fn ($request): bool => $request->header('X-Admin') === 'yes');

    $this->get('/chronoview')->assertForbidden();
    $this->withHeader('X-Admin', 'yes')->get('/chronoview')->assertOk();
});

it('honours the viewChronoView gate', function (): void {
    Gate::define('viewChronoView', fn ($user = null): bool => true);

    $this->get('/chronoview')->assertOk();
});

it('uses the configured path', function (): void {
    config()->set('chronoview.path', 'ops/scheduler');
    $this->refreshApplication();
    ChronoView::auth(fn (): bool => true);

    $this->get('/ops/scheduler')->assertOk();
})->skip('path is read at boot; covered by the Testbench workbench');
