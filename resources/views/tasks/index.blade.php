@extends('chronoview::layouts.app')
@section('title', 'Tasks')

@php use Grazulex\ChronoView\Enums\Health; use Grazulex\ChronoView\Support\Format; @endphp

@section('content')
    <section class="cv-card" x-data="{ q: '' }">
        <div class="cv-toolbar">
            <h1>Tasks <span class="cv-muted">({{ $rows->count() }})</span></h1>
            <input class="cv-input" type="search" placeholder="Search a task…" x-model="q" autofocus>
            <nav class="cv-filters">
                <a href="{{ route('chronoview.tasks.index') }}" @class(['is-active' => $filter === null])>All</a>
                @foreach (Health::cases() as $case)
                    <a href="{{ route('chronoview.tasks.index', ['health' => $case->value]) }}" @class(['is-active' => $filter === $case])>
                        {{ $case->label() }} <span class="cv-muted">{{ $counts->get($case->value, 0) }}</span>
                    </a>
                @endforeach
            </nav>
        </div>

        <table class="cv-table">
            <thead>
                <tr><th>Health</th><th>Task</th><th>Type</th><th>Schedule</th><th>Last run</th><th>Next run</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr x-show="q === '' || $el.dataset.name.includes(q.toLowerCase())" data-name="{{ strtolower($row['task']->name . ' ' . $row['task']->command) }}">
                        <td>@include('chronoview::partials.health-badge', ['health' => $row['health']])</td>
                        <td><a href="{{ route('chronoview.tasks.show', $row['task']) }}"><strong>{{ $row['task']->name }}</strong></a></td>
                        <td><code>{{ $row['task']->type->value }}</code></td>
                        <td title="{{ $row['task']->expression }}">{{ $row['task']->cronDescription() }}</td>
                        <td title="{{ Format::exact($row['task']->last_started_at) }}">
                            @if ($row['task']->last_status)
                                @include('chronoview::partials.status-badge', ['status' => $row['task']->last_status])
                            @endif
                            <span class="cv-muted">{{ Format::ago($row['task']->last_started_at) }}</span>
                        </td>
                        <td class="cv-mono">{{ $row['next'] ? Format::exact($row['next']) : '—' }}</td>
                        <td><a class="cv-btn cv-btn-ghost" href="{{ route('chronoview.tasks.show', $row['task']) }}">Details →</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="cv-muted">No task matches this filter.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
@endsection
