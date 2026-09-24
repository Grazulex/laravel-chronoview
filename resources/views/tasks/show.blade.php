@extends('chronoview::layouts.app')
@section('title', $task->name)

@php use Grazulex\ChronoView\Support\Format; @endphp

@section('content')
    <section class="cv-card cv-page-header">
        <div>
            <div class="cv-muted"><a href="{{ route('chronoview.tasks.index') }}">Tasks</a> / {{ $task->type->value }}</div>
            <h1>{{ $task->name }} @include('chronoview::partials.health-badge', ['health' => $task->health()])</h1>
            @if ($task->command && $task->command !== $task->name)<div><code>{{ $task->command }}</code></div>@endif
            @if (($task->description && $task->description !== $task->name) || $task->source)
                <div class="cv-meta">
                    @if ($task->description && $task->description !== $task->name)<span>Description: {{ $task->description }}</span>@endif
                    @if ($task->source)<span>Source: <code>{{ $task->source }}</code></span>@endif
                </div>
            @endif
            <div class="cv-meta">
                <span title="{{ $task->expression }}">{{ $task->cronDescription() }} · <code>{{ $task->expression }}</code></span>
                <span>Task timezone: {{ $task->timezone ?? config('app.timezone') }}@if (($task->timezone ?? config('app.timezone')) === config('app.timezone')) (application default)@endif</span>
                <span>Next: @include('chronoview::partials.time', ['date' => $next])</span>
                <span class="cv-flags">
                    @if ($task->run_in_background)<span>background</span>@endif
                    @if ($task->without_overlapping)<span>without overlapping</span>@endif
                    @if ($task->on_one_server)<span>one server</span>@endif
                </span>
            </div>
            @unless ($runsHere)
                <p class="cv-muted">Not scheduled in this environment ({{ app()->environment() }}) — the scheduler never runs it here.</p>
            @endunless
            @if ($task->isPaused())
                <p class="cv-muted">Paused {{ Format::ago($task->paused_at) }} — the scheduler skips this task until it is resumed.</p>
            @endif
        </div>
        <div class="cv-actions">
            @if (config('chronoview.actions.run_now') && $runsHere)
                <form method="post" action="{{ route('chronoview.tasks.run', $task) }}">@csrf
                    <button class="cv-btn cv-btn-primary" type="submit">▶ Run now</button>
                </form>
            @endif
            @if (config('chronoview.actions.pause'))
                @if ($task->isPaused())
                    <form method="post" action="{{ route('chronoview.tasks.resume', $task) }}">@csrf
                        <button class="cv-btn" type="submit">Resume</button>
                    </form>
                @else
                    <form method="post" action="{{ route('chronoview.tasks.pause', $task) }}">@csrf
                        <button class="cv-btn cv-btn-danger" type="submit">Pause</button>
                    </form>
                @endif
            @endif
        </div>
    </section>

    <section class="cv-kpis">
        <div class="cv-kpi"><span class="cv-kpi-label">Last 7 days</span><strong>{{ $summary['runs'] }} runs</strong></div>
        <div class="cv-kpi"><span class="cv-kpi-label">Success</span><strong>{{ $summary['success_rate'] === null ? '—' : number_format($summary['success_rate'], 1) . '%' }}</strong></div>
        <div class="cv-kpi {{ $summary['failed'] > 0 ? 'is-bad' : '' }}"><span class="cv-kpi-label">Failed</span><strong>{{ $summary['failed'] }}</strong></div>
        <div class="cv-kpi {{ $summary['missed'] > 0 ? 'is-bad' : '' }}"><span class="cv-kpi-label">Missed</span><strong>{{ $summary['missed'] }}</strong></div>
        <div class="cv-kpi"><span class="cv-kpi-label">Median duration</span><strong>{{ Format::duration($summary['median_ms']) }}</strong></div>
        <div class="cv-kpi {{ $task->consecutive_failures > 0 ? 'is-bad' : '' }}"><span class="cv-kpi-label">Consecutive failures</span><strong>{{ $task->consecutive_failures }}</strong></div>
    </section>

    @if ($sparkline->isNotEmpty())
        <section class="cv-card">
            <h2>Duration of the last {{ $sparkline->count() }} runs</h2>
            @include('chronoview::partials.sparkline', ['runs' => $sparkline])
        </section>
    @endif

    <section class="cv-card">
        <h2>History</h2>
        <table class="cv-table">
            <thead><tr><th>Status</th><th>Expected</th><th>Started</th><th class="cv-num">Duration</th><th class="cv-num">Exit</th><th>Host</th><th>Trigger</th><th></th></tr></thead>
            <tbody>
                @forelse ($history as $run)
                    <tr>
                        <td>@include('chronoview::partials.status-badge', ['status' => $run->status])</td>
                        <td>@include('chronoview::partials.time', ['date' => $run->expected_at])</td>
                        <td>@include('chronoview::partials.time', ['date' => $run->started_at])</td>
                        <td class="cv-num">{{ Format::duration($run->duration_ms) }}</td>
                        <td class="cv-num">{{ $run->exit_code ?? '—' }}</td>
                        <td><code>{{ $run->hostname ?? '—' }}</code></td>
                        <td class="cv-muted">{{ $run->trigger->value }}</td>
                        <td><a class="cv-btn cv-btn-ghost" href="{{ route('chronoview.runs.show', $run) }}">Details →</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="cv-muted">No run recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="cv-pagination">{{ $history->links('chronoview::partials.pagination') }}</div>
    </section>
@endsection
