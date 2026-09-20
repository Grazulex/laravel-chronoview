<section class="cv-heartbeat {{ $alive ? 'is-alive' : 'is-down' }}">
    <div class="cv-heartbeat-status">
        <span class="cv-dot"></span>
        <strong>{{ $alive ? 'Scheduler alive' : 'Scheduler down' }}</strong>
        @unless ($alive)
            <span class="cv-muted">— no heartbeat for {{ config('chronoview.check.heartbeat_timeout') }}s. Is <code>* * * * * php artisan schedule:run</code> running?</span>
        @endunless
    </div>
    <ul class="cv-hosts">
        @forelse ($hosts as $host)
            <li title="{{ \Grazulex\ChronoView\Support\Format::exact($host->beat_at) }}">
                <code>{{ $host->hostname }}</code> <span class="cv-muted">{{ \Grazulex\ChronoView\Support\Format::ago($host->beat_at) }}</span>
            </li>
        @empty
            <li class="cv-muted">No heartbeat recorded yet</li>
        @endforelse
    </ul>
</section>
