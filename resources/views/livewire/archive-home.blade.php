<div class="blob-stage relative flex min-h-[calc(100vh-8rem)] items-center justify-center overflow-hidden">
    {{-- Stage fills viewport minus header/footer, and the section is
         vertically centered inside it (items-center justify-center)
         so content sits in the middle instead of hugging the top. --}}
    <div aria-hidden="true" class="pointer-events-none absolute inset-0 -z-10">
        <div class="blob blob--a left-[3%]   top-[8%]    h-[30rem] w-[30rem]"></div>
        <div class="blob blob--b right-[-2%] top-[22%]   h-[36rem] w-[36rem]"></div>
        <div class="blob blob--c left-[28%]  bottom-[4%] h-[28rem] w-[28rem]"></div>
    </div>

    <section class="relative mx-auto flex w-full max-w-3xl flex-col items-center px-6 py-10 text-center">
        <img src="{{ asset('sas-logo.svg') }}"
             alt="Sites at Scale"
             class="mb-6 h-11 w-auto">

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
                <input
                    type="text"
                    wire:model.live.debounce.300ms="query"
                    placeholder="Enter a URL"
                    autofocus
                    class="w-full rounded-xl border border-surface-200 bg-white/80 py-3.5 pl-5 pr-28 text-sm text-surface-900 shadow-sm backdrop-blur-sm transition
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
