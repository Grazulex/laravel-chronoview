@extends('chronoview::layouts.app')
@section('title', 'Overview')

@php use Grazulex\ChronoView\Support\Format; @endphp

@section('content')
    @include('chronoview::partials.heartbeat', ['alive' => $alive, 'hosts' => $hosts])

    <section class="cv-kpis">
        <div class="cv-kpi"><span class="cv-kpi-label">Runs · 24 h</span><strong>{{ $stats['runs'] }}</strong></div>
        <div class="cv-kpi"><span class="cv-kpi-label">Success</span><strong>{{ $stats['success_rate'] === null ? '—' : number_format($stats['success_rate'], 1) . '%' }}</strong></div>
        <div class="cv-kpi {{ $stats['failed'] > 0 ? 'is-bad' : '' }}"><span class="cv-kpi-label">Failed</span><strong>{{ $stats['failed'] }}</strong></div>
        <div class="cv-kpi {{ $stats['missed'] > 0 ? 'is-bad' : '' }}"><span class="cv-kpi-label">Missed</span><strong>{{ $stats['missed'] }}</strong></div>
        <div class="cv-kpi"><span class="cv-kpi-label">Avg duration</span><strong>{{ Format::duration($stats['avg_duration_ms']) }}</strong></div>
        <div class="cv-kpi"><span class="cv-kpi-label">Running now</span><strong>{{ $stats['running'] }}</strong></div>
    </section>

    @if ($taskCount === 0)
        <section class="cv-card cv-empty">
            <h2>No task recorded yet</h2>
            <p>Run <code>php artisan chronoview:sync</code> or wait for the next <code>schedule:run</code>.</p>
        </section>
    @endif

    <div class="cv-grid-2">
        <section class="cv-card">
            <h2>Needs attention</h2>
            @forelse ($attention as $task)
                <a class="cv-row" href="{{ route('chronoview.tasks.show', $task) }}">
                    @include('chronoview::partials.health-badge', ['health' => $task->health()])
                    <span class="cv-row-title">{{ $task->name }}</span>
                    <span class="cv-muted">{{ $task->consecutive_failures }} consecutive {{ $task->consecutive_failures === 1 ? 'failure' : 'failures' }} · last {{ $task->last_status?->value }} {{ Format::ago($task->last_finished_at) }}</span>
                </a>
            @empty
                <p class="cv-muted">Everything is healthy.</p>
            @endforelse
        </section>

        <section class="cv-card">
            <h2>Up next</h2>
            @forelse ($upNext as $row)
                <a class="cv-row" href="{{ route('chronoview.tasks.show', $row['task']) }}">
                    @include('chronoview::partials.time', ['date' => $row['at']])
                    <span class="cv-row-title">{{ $row['task']->name }}</span>
                    <span class="cv-muted">{{ $row['task']->cronDescription() }}</span>
                </a>
            @empty
                <p class="cv-muted">Nothing scheduled.</p>
            @endforelse
        </section>
    </div>

    <div class="cv-grid-2">
        <section class="cv-card">
            <h2>Recent problems</h2>
            @forelse ($problems as $run)
                <a class="cv-row" href="{{ route('chronoview.runs.show', $run) }}">
                    @include('chronoview::partials.status-badge', ['status' => $run->status])
                    <span class="cv-row-title">{{ $run->task->name }}</span>
                    <span class="cv-muted">{{ Format::ago($run->started_at ?? $run->expected_at) }}</span>
                </a>
            @empty
                <p class="cv-muted">No failure or missed run.</p>
            @endforelse
        </section>

        <section class="cv-card">
            <h2>Recent runs</h2>
            @forelse ($recent as $run)
                <a class="cv-row" href="{{ route('chronoview.runs.show', $run) }}">
                    @include('chronoview::partials.status-badge', ['status' => $run->status])
                    <span class="cv-row-title">{{ $run->task->name }}</span>
                    <span class="cv-muted">{{ Format::duration($run->duration_ms) }} · {{ Format::ago($run->started_at ?? $run->expected_at) }}</span>
                </a>
            @empty
                <p class="cv-muted">No run yet.</p>
            @endforelse
        </section>
    </div>
@endsection
