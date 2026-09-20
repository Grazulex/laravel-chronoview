<?php

declare(strict_types=1);

namespace Grazulex\ChronoView\Http\Controllers;

use Grazulex\ChronoView\Enums\RunStatus;
use Grazulex\ChronoView\Models\TaskRun;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class RunController
{
    public function index(Request $request): View
    {
        $filter = RunStatus::tryFrom((string) $request->query('status', ''));

        $runs = TaskRun::query()->with('task')
            ->when($filter !== null, fn ($q) => $q->where('status', $filter->value))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('chronoview::runs.index', ['runs' => $runs, 'filter' => $filter]);
    }

    public function show(TaskRun $run): View
    {
        return view('chronoview::runs.show', ['run' => $run->load('task')]);
    }
}
