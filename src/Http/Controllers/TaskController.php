<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Http\Controllers;

use Illuminate\Http\Response;

final class TaskController
{
    public function index(): Response
    {
        return response('ChronoView');
    }

    public function show(): Response
    {
        return response('ChronoView');
    }
}
