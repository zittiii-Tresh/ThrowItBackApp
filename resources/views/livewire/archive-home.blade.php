<div class="blob-stage relative overflow-hidden">
    {{-- Background gradient + drifting blobs from the landing design --}}
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
        <div class="blob blob--a left-[3%]   top-[8%]    h-[30rem] w-[30rem]"></div>
        <div class="blob blob--b right-[-2%] top-[22%]   h-[36rem] w-[36rem]"></div>
        <div class="blob blob--c left-[28%]  bottom-[4%] h-[28rem] w-[28rem]"></div>
    </div>

    <section class="relative mx-auto flex max-w-3xl flex-col items-center px-6 pt-16 pb-24 text-center">
        <span class="mb-6 inline-flex items-center gap-2 rounded-full border border-brand-200 bg-brand-50 px-3 py-1 text-xs font-medium text-brand-700 dark:border-brand-900 dark:bg-brand-950 dark:text-brand-300">
            Internal Tool · Sites at Scale
        </span>

        <h1 class="text-4xl font-semibold tracking-tight text-surface-900 dark:text-surface-100 sm:text-5xl">
            Browse past versions of
            <span class="bg-gradient-to-r from-brand-500 to-brand-300 bg-clip-text text-transparent">your sites</span>
        </h1>

        <p class="mt-4 max-w-xl text-base text-surface-600 dark:text-surface-400">
            Search any archived URL to view snapshots and recover assets.
        </p>

        {{-- Search bar with live autocomplete --}}
        <form wire:submit="submit" class="mt-10 w-full max-w-2xl">
            <div class="relative">
                <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-surface-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>

                <input
                    type="text"
                    wire:model.live.debounce.300ms="query"
                    placeholder="Enter a URL — e.g. acme.com or acme.com/about"
                    autofocus
                    class="w-full rounded-xl border border-surface-200 bg-white/80 py-3.5 pl-12 pr-28 text-sm text-surface-900 shadow-sm backdrop-blur-sm transition
                           placeholder:text-surface-400
                           focus:border-brand-400 focus:outline-none focus:ring-2 focus:ring-brand-400/30
                           dark:border-surface-800 dark:bg-surface-900/80 dark:text-surface-100 dark:placeholder:text-surface-500"
                />

                <button type="submit" class="btn-primary absolute right-1.5 top-1/2 -translate-y-1/2 !py-2">
                    Browse
                </button>
            </div>

            {{-- Live-search dropdown --}}
            @if ($this->matches->isNotEmpty() && strlen($query) >= 2)
                <ul class="mt-2 divide-y divide-surface-100 overflow-hidden rounded-xl border border-surface-200 bg-white shadow-lg dark:divide-surface-800 dark:border-surface-800 dark:bg-surface-900">
                    @foreach ($this->matches as $site)
                        <li>
                            <a
                                href="{{ route('archive.browse', $site) }}"
                                class="flex items-center justify-between gap-3 px-4 py-3 text-left hover:bg-surface-50 dark:hover:bg-surface-800/50"
                            >
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-medium text-surface-900 dark:text-surface-100">
                                        {{ $site->name }}
                                    </div>
                                    <div class="truncate text-xs text-surface-500 dark:text-surface-400">
                                        {{ $site->base_url }}
                                    </div>
                                </div>
                                @if ($site->last_crawled_at)
                                    <span class="shrink-0 text-xs text-surface-500 dark:text-surface-400">
                                        {{ $site->last_crawled_at->diffForHumans() }}
                                    </span>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            @elseif (strlen($query) >= 2)
                <p class="mt-3 text-sm text-surface-500 dark:text-surface-400">
                    No archived sites match "{{ $query }}".
                </p>
            @endif
        </form>

        {{-- Recently archived chips --}}
        @if ($this->recentSites->isNotEmpty())
            <div class="mt-10 flex flex-col items-center gap-3">
                <p class="text-xs uppercase tracking-wider text-surface-500 dark:text-surface-400">
                    Recently archived
                </p>
                <div class="flex flex-wrap justify-center gap-2">
                    @foreach ($this->recentSites as $site)
                        <a href="{{ route('archive.browse', $site) }}" class="site-chip">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            {{ $site->name }}
                        </a>
                    @endforeach
                </div>
            </div>
        @else
            <div class="mt-10 rounded-xl border border-dashed border-surface-200 bg-white/50 px-6 py-8 text-center text-sm text-surface-500 backdrop-blur-sm dark:border-surface-800 dark:bg-surface-900/50 dark:text-surface-400">
                No sites have been crawled yet.<br>
                <a href="{{ url('/admin/sites/create') }}" class="mt-1 inline-block font-medium text-brand-600 hover:underline dark:text-brand-400">
                    Add the first site in the admin panel →
                </a>
            </div>
        @endif
    </section>
</div>
