<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class AssetController
{
    private const array ASSETS = [
        'alpine.min.js' => 'application/javascript',
        'chronoview.css' => 'text/css',
    ];

    public function __invoke(string $file): BinaryFileResponse
    {
        if (! isset(self::ASSETS[$file])) {
            abort(404);
        }

        $path = dirname(__DIR__, 3) . '/resources/dist/' . $file;

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => self::ASSETS[$file] . '; charset=UTF-8',
            'Cache-Control' => 'max-age=86400, public',
        ]);
    }
}
