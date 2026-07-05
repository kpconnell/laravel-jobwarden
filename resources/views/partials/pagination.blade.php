{{-- Dense paginator for the console tables. $paginator is a LengthAwarePaginator
     rendered by Livewire, so prev/next are wire actions (no page reload). --}}
@if ($paginator->total() > 0)
    <div class="pager">
        <span class="info">
            {{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }}
            of {{ number_format($paginator->total()) }}
        </span>
        <div class="right">
            @if ($perPageControl ?? false)
                <select wire:model.live="perPage" title="Rows per page">
                    <option value="25">25 / page</option>
                    <option value="50">50 / page</option>
                </select>
            @endif
            <button type="button" class="btn sm" wire:click="previousPage" @if ($paginator->onFirstPage()) disabled @endif>← Prev</button>
            <span class="info">page {{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
            <button type="button" class="btn sm" wire:click="nextPage" @if (! $paginator->hasMorePages()) disabled @endif>Next →</button>
        </div>
    </div>
@endif
