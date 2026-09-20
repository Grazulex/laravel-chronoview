@if ($paginator->hasPages())
    <nav class="cv-actions" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="cv-btn" aria-disabled="true">← Previous</span>
        @else
            <a class="cv-btn" href="{{ $paginator->previousPageUrl() }}">← Previous</a>
        @endif
        <span class="cv-muted">Page {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())
            <a class="cv-btn" href="{{ $paginator->nextPageUrl() }}">Next →</a>
        @else
            <span class="cv-btn" aria-disabled="true">Next →</span>
        @endif
    </nav>
@endif
