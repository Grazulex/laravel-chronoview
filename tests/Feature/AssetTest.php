<?php

declare(strict_types=1);

it('serves the embedded assets without authentication', function (): void {
    $this->get('/chronoview/assets/alpine.min.js')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
        ->assertHeader('Cache-Control', 'max-age=86400, public');

    $this->get('/chronoview/assets/chronoview.css')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/css; charset=UTF-8');
});

it('refuses unknown assets', function (): void {
    $this->get('/chronoview/assets/../../composer.json')->assertNotFound();
    $this->get('/chronoview/assets/nope.js')->assertNotFound();
});
