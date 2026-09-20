@php
    /** @var \Illuminate\Support\Collection<int, \Grazulex\ChronoView\Models\TaskRun> $runs */
    $max = max(1, (int) $runs->max('duration_ms'));
    $count = max(1, $runs->count());
    $width = 400; $height = 48; $gap = 2;
    $barWidth = ($width - $gap * ($count - 1)) / $count;
@endphp
<svg class="cv-sparkline" viewBox="0 0 {{ $width }} {{ $height }}" preserveAspectRatio="none" role="img" aria-label="Duration of the last {{ $runs->count() }} runs">
    @foreach ($runs as $i => $run)
        @php $h = max(2, round(($run->duration_ms ?? 0) / $max * ($height - 2))); @endphp
        <rect class="bar {{ $run->status === \Grazulex\ChronoView\Enums\RunStatus::Failed ? 'is-failed' : '' }}"
              x="{{ round($i * ($barWidth + $gap), 2) }}" y="{{ round($height - $h, 2) }}" width="{{ round($barWidth, 2) }}" height="{{ round($h, 2) }}">
            <title>{{ \Grazulex\ChronoView\Support\Format::duration($run->duration_ms) }} · {{ \Grazulex\ChronoView\Support\Format::exact($run->started_at) }}</title>
        </rect>
    @endforeach
</svg>
