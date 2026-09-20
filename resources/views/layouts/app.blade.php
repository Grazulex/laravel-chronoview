<!DOCTYPE html>
<html lang="en" data-theme="auto">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Scheduler') · ChronoView</title>
    <link rel="stylesheet" href="{{ route('chronoview.asset', 'chronoview.css') }}">
    <script>
        (function () {
            try {
                var t = localStorage.getItem('chronoview-theme');
                if (t) document.documentElement.setAttribute('data-theme', t);
            } catch (e) {}
        })();
    </script>
    <script defer src="{{ route('chronoview.asset', 'alpine.min.js') }}"></script>
</head>
<body
    x-data="chronoview({{ \Grazulex\ChronoView\Facades\ChronoView::refresh() }})"
    data-refresh="{{ \Grazulex\ChronoView\Facades\ChronoView::refresh() }}"
    data-server-tz="{{ config('app.timezone') }}"
>
    <header class="cv-header">
        <a class="cv-brand" href="{{ route('chronoview.dashboard') }}">
            <span class="cv-brand-mark">◔</span> ChronoView
        </a>
        <nav class="cv-nav">
            <a href="{{ route('chronoview.dashboard') }}" @class(['is-active' => request()->routeIs('chronoview.dashboard')])>Overview</a>
            <a href="{{ route('chronoview.tasks.index') }}" @class(['is-active' => request()->routeIs('chronoview.tasks.*')])>Tasks</a>
            <a href="{{ route('chronoview.runs.index') }}" @class(['is-active' => request()->routeIs('chronoview.runs.*')])>Runs</a>
        </nav>
        <div class="cv-header-tools">
            <button type="button" class="cv-btn cv-btn-ghost" @click="toggleRefresh()" x-text="refreshLabel()" title="Auto-refresh" aria-label="Toggle auto-refresh"></button>
            <button type="button" class="cv-btn cv-btn-ghost" @click="toggleTz()" x-text="tzLabel()" title="Timezone" aria-label="Toggle timezone"></button>
            <button type="button" class="cv-btn cv-btn-ghost" @click="toggleTheme()" x-text="themeLabel()" title="Theme" aria-label="Toggle theme"></button>
        </div>
    </header>

    <p class="cv-tz-note" x-text="tzNote()">Times are shown in the application timezone ({{ config('app.timezone') }}). Switch to your local time with the clock button.</p>

    <main class="cv-main">
        @include('chronoview::partials.flash')
        @yield('content')
    </main>

    <footer class="cv-footer">
        ChronoView · {{ config('app.name') }} · @include('chronoview::partials.time', ['date' => now()]) <span class="cv-muted">(page rendered)</span>
    </footer>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('chronoview', (interval) => ({
                interval: interval,
                paused: false,
                timer: null,
                theme: document.documentElement.getAttribute('data-theme') || 'auto',
                tz: 'server',
                serverTz: document.body.dataset.serverTz || 'UTC',
                localTz: (Intl.DateTimeFormat().resolvedOptions().timeZone || 'local'),
                init() {
                    try { this.paused = localStorage.getItem('chronoview-refresh') === 'off'; } catch (e) {}
                    this.schedule();
                    try { this.tz = localStorage.getItem('chronoview-tz') === 'local' ? 'local' : 'server'; } catch (e) {}
                    this.applyTz();
                },
                schedule() {
                    clearTimeout(this.timer);
                    if (this.interval > 0 && !this.paused) {
                        this.timer = setTimeout(() => window.location.reload(), this.interval * 1000);
                    }
                },
                toggleRefresh() {
                    this.paused = !this.paused;
                    try { localStorage.setItem('chronoview-refresh', this.paused ? 'off' : 'on'); } catch (e) {}
                    this.schedule();
                },
                refreshLabel() {
                    if (this.interval <= 0) return 'Refresh off';
                    return this.paused ? '⏸ Refresh paused' : '↻ Every ' + this.interval + 's';
                },
                toggleTheme() {
                    this.theme = this.theme === 'dark' ? 'light' : (this.theme === 'light' ? 'auto' : 'dark');
                    document.documentElement.setAttribute('data-theme', this.theme);
                    try { localStorage.setItem('chronoview-theme', this.theme); } catch (e) {}
                },
                themeLabel() {
                    return this.theme === 'dark' ? '🌙 Dark' : (this.theme === 'light' ? '☀️ Light' : '◐ Auto');
                },
                applyTz() {
                    document.querySelectorAll('time.cv-time').forEach((el) => {
                        if (this.tz === 'server') { el.textContent = el.dataset.exact; return; }
                        const d = new Date(el.getAttribute('datetime'));
                        if (isNaN(d)) { el.textContent = el.dataset.exact; return; }
                        const p = (n) => String(n).padStart(2, '0');
                        el.textContent = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
                    });
                },
                toggleTz() {
                    this.tz = this.tz === 'server' ? 'local' : 'server';
                    try { localStorage.setItem('chronoview-tz', this.tz); } catch (e) {}
                    this.applyTz();
                },
                tzLabel() { return this.tz === 'server' ? '🕒 Server (' + this.serverTz + ')' : '🕒 Local (' + this.localTz + ')'; },
                tzNote() {
                    return this.tz === 'server'
                        ? 'Times are shown in the application timezone (' + this.serverTz + '). Switch to your local time with the clock button.'
                        : 'Times are shown in your local timezone (' + this.localTz + '). Server timezone: ' + this.serverTz + '.';
                },
            }));
        });
    </script>
</body>
</html>
