<?php

declare(strict_types=1);

use Grazulex\ChronoView\Facades\ChronoView;
use Illuminate\Foundation\Auth\User;
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

it('allows listed emails and denies the others', function (): void {
    config()->set('chronoview.auth.allowed_emails', 'JMS@grazulex.be, ops@example.com');
    $admin = (new User)->forceFill(['email' => 'jms@grazulex.be']);
    $other = (new User)->forceFill(['email' => 'someone@example.com']);

    $this->actingAs($admin)->get('/chronoview')->assertOk();
    $this->actingAs($other)->get('/chronoview')->assertForbidden();
});

it('requires authentication when an allow-list is configured, even in local', function (): void {
    app()->detectEnvironment(fn (): string => 'local');
    config()->set('chronoview.auth.allowed_emails', 'jms@grazulex.be');

    $this->get('/chronoview')->assertForbidden();
});

it('accepts an array of emails from a published config', function (): void {
    config()->set('chronoview.auth.allowed_emails', ['jms@grazulex.be']);

    expect(ChronoView::allowedEmails())->toBe(['jms@grazulex.be']);
    $this->actingAs((new User)->forceFill(['email' => 'jms@grazulex.be']))->get('/chronoview')->assertOk();
});

it('lets the callback and the gate take precedence over the allow-list', function (): void {
    config()->set('chronoview.auth.allowed_emails', 'jms@grazulex.be');
    ChronoView::auth(fn (): bool => true);

    $this->get('/chronoview')->assertOk();
});

it('uses the configured path', function (): void {
    config()->set('chronoview.path', 'ops/scheduler');
    $this->refreshApplication();
    ChronoView::auth(fn (): bool => true);

    $this->get('/ops/scheduler')->assertOk();
})->skip('path is read at boot; covered by the Testbench workbench');
