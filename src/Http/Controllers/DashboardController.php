<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Http\Controllers;

use Illuminate\Http\Response;

final class DashboardController
{
    public function __invoke(): Response
    {
        return response('ChronoView');
    }
}
