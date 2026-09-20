@php /** @var \Carbon\CarbonInterface|null $date */ @endphp
@if ($date === null)
—
@else
<time class="cv-time" datetime="{{ $date->toIso8601String() }}" data-exact="{{ \Grazulex\ChronoView\Support\Format::exact($date) }}" title="{{ \Grazulex\ChronoView\Support\Format::exact($date) }} {{ config('app.timezone') }}">{{ \Grazulex\ChronoView\Support\Format::exact($date) }}</time>
@endif
