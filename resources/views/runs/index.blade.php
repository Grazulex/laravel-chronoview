@extends('chronoview::layouts.app')
@section('title', 'Runs')

@php use Grazulex\ChronoView\Enums\RunStatus; use Grazulex\ChronoView\Support\Format; @endphp

@section('content')
    <section class="cv-card">
        <div class="cv-toolbar">
            <h1>Runs</h1>
            <nav class="cv-filters">
                <a href="{{ route('chronoview.runs.index') }}" @class(['is-active' => $filter === null])>All</a>
                @foreach (RunStatus::cases() as $case)
                    <a href="{{ route('chronoview.runs.index', ['status' => $case->value]) }}" @class(['is-active' => $filter === $case])>{{ ucfirst($case->value) }}</a>
                @endforeach
            </nav>
        </div>
        <table class="cv-table">
            <thead><tr><th>Status</th><th>Task</th><th>Expected</th><th>Started</th><th class="cv-num">Duration</th><th class="cv-num">Exit</th><th>Host</th><th></th></tr></thead>
            <tbody>
                @forelse ($runs as $run)
                    <tr>
                        <td>@include('chronoview::partials.status-badge', ['status' => $run->status])</td>
                        <td><a href="{{ route('chronoview.tasks.show', $run->task) }}"><strong>{{ $run->task->name }}</strong></a></td>
                        <td class="cv-mono">{{ Format::exact($run->expected_at) }}</td>
                        <td class="cv-mono">{{ Format::exact($run->started_at) }}</td>
                        <td class="cv-num">{{ Format::duration($run->duration_ms) }}</td>
                        <td class="cv-num">{{ $run->exit_code ?? '—' }}</td>
                        <td><code>{{ $run->hostname ?? '—' }}</code></td>
                        <td><a class="cv-btn cv-btn-ghost" href="{{ route('chronoview.runs.show', $run) }}">Details →</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="cv-muted">No run matches this filter.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="cv-pagination">{{ $runs->links('chronoview::partials.pagination') }}</div>
    </section>
@endsection
