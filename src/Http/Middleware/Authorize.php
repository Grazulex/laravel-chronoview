<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Http\Middleware;

use Closure;
use Grazulex\ChronoView\ChronoView;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class Authorize
{
    public function __construct(private readonly ChronoView $chronoView) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->chronoView->check($request), 403);

        return $next($request);
    }
}
