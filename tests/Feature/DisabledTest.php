<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Tests\Feature;

use Grazulex\ChronoView\Tests\TestCase;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Test;

final class DisabledTest extends TestCase
{
    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('chronoview.enabled', false);
    }

    #[Test]
    public function it_registers_no_route_when_disabled(): void
    {
        $this->get('/chronoview')->assertNotFound();
    }
}
