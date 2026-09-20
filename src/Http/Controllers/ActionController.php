<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Http\Controllers;

use Illuminate\Http\Response;

final class ActionController
{
    public function run(): Response
    {
        return response('ChronoView');
    }

    public function pause(): Response
    {
        return response('ChronoView');
    }

    public function resume(): Response
    {
        return response('ChronoView');
    }
}
