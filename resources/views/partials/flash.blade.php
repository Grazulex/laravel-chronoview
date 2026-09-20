@if (session()->has('chronoview.flash'))
    @php $flash = session('chronoview.flash'); @endphp
    <div class="cv-flash cv-flash-{{ $flash['type'] ?? 'success' }}" x-data="{ open: true }" x-show="open">
        <span>{{ $flash['message'] ?? '' }}</span>
        <button type="button" class="cv-flash-close" @click="open = false" aria-label="Dismiss">×</button>
    </div>
@endif
