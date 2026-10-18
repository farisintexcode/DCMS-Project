@props(['paginator', 'elements' => [], 'livewire' => false])
@if($paginator->hasPages())
<nav aria-label="Pagination Navigation" class="flex flex-wrap items-center justify-between gap-4">
    <p class="text-sm text-ink-600">Page {{ $paginator->currentPage() }}@if(method_exists($paginator, 'lastPage')) of {{ $paginator->lastPage() }}@endif</p>
    <div class="flex flex-wrap items-center gap-2">
        @if($paginator->onFirstPage())
            <span class="page-control" aria-disabled="true">Previous</span>
        @elseif($livewire)
            <button type="button" class="page-control" wire:click="previousPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled">Previous</button>
        @else
            <a class="page-control" href="{{ $paginator->previousPageUrl() }}" rel="prev">Previous</a>
        @endif
        <div class="hidden flex-wrap gap-2 sm:flex">
        @foreach($elements as $element)
            @if(is_string($element))<span class="px-2 py-3 text-ink-600">{{ $element }}</span>
            @else
                @foreach($element as $number => $url)
                    @if($number == $paginator->currentPage())
                        <span class="page-control" aria-current="page" aria-label="Page {{ $number }}">{{ $number }}</span>
                    @elseif($livewire)
                        <button type="button" class="page-control" wire:click="gotoPage({{ $number }}, '{{ $paginator->getPageName() }}')" wire:loading.attr="disabled" aria-label="Go to page {{ $number }}">{{ $number }}</button>
                    @else
                        <a class="page-control" href="{{ $url }}" aria-label="Go to page {{ $number }}">{{ $number }}</a>
                    @endif
                @endforeach
            @endif
        @endforeach
        </div>
        @if(!$paginator->hasMorePages())
            <span class="page-control" aria-disabled="true">Next</span>
        @elseif($livewire)
            <button type="button" class="page-control" wire:click="nextPage('{{ $paginator->getPageName() }}')" wire:loading.attr="disabled">Next</button>
        @else
            <a class="page-control" href="{{ $paginator->nextPageUrl() }}" rel="next">Next</a>
        @endif
    </div>
</nav>
@endif
