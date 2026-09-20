@extends('chronoview::layouts.app')
@section('title', 'Run #' . $run->id)

@php use Grazulex\ChronoView\Support\Format; @endphp

@section('content')
    <section class="cv-card cv-page-header">
        <div>
            <div class="cv-muted"><a href="{{ route('chronoview.runs.index') }}">Runs</a> / <a href="{{ route('chronoview.tasks.show', $run->task) }}">{{ $run->task->name }}</a></div>
            <h1>Run #{{ $run->id }} @include('chronoview::partials.status-badge', ['status' => $run->status])</h1>
            <div class="cv-meta">
                <span>Trigger: {{ $run->trigger->value }}</span>
                <span>Host: <code>{{ $run->hostname ?? '—' }}</code></span>
                <span>Expected: @include('chronoview::partials.time', ['date' => $run->expected_at])</span>
                <span>Started: @include('chronoview::partials.time', ['date' => $run->started_at])</span>
                <span>Finished: @include('chronoview::partials.time', ['date' => $run->finished_at])</span>
                <span>Duration: {{ Format::duration($run->duration_ms) }}</span>
                <span>Exit code: {{ $run->exit_code ?? '—' }}</span>
                <span>Memory peak: {{ Format::bytes($run->memory_peak) }}</span>
            </div>
        </div>
    </section>

    @if ($run->exception)
        <section class="cv-card">
            <h2>Exception</h2>
            <pre class="cv-pre">{{ $run->exception }}</pre>
        </section>
    @endif

    <section class="cv-card">
        <h2>Output</h2>
        @if ($run->output !== null && $run->output !== '')
            <pre class="cv-pre">{{ $run->output }}</pre>
        @else
            <p class="cv-muted">No output captured{{ $run->task->type->value === 'closure' || $run->task->type->value === 'job' ? ' (closures and jobs do not capture output)' : '' }}.</p>
        @endif
    </section>
@endsection
