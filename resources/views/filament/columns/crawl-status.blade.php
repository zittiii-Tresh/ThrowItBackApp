@php
    /** @var \App\Models\Site $site */
    $site = $getRecord();
    // Eager-loaded by SiteResource::table()->modifyQueryUsing() — no per-row query.
    $latest = $site->latestCrawlRun;
    $isRunning = $latest && $latest->status->value === 'running';

    // Estimated total pages for the progress calculation:
    //   - use the most recent *successful* run's page count, IF that count
    //     is plausibly larger than what we've already crawled (otherwise we'd
    //     show 100% way too early on sites whose page count grew)
    //   - fall back to the site's max_pages cap
    if ($isRunning) {
        $priorCount = \App\Models\CrawlRun::query()
            ->where('site_id', $site->id)
            ->whereIn('status', ['complete', 'partial'])
            ->where('id', '<>', $latest->id)
            ->latest('id')
            ->value('pages_crawled');

        // If the prior run tiny (wrong URL, failed most pages), it makes a
        // bad baseline — bump to max_pages so the bar doesn't hit 100% on
        // page 2.
        $expected = max(
            $latest->pages_crawled + 1,           // always at least ahead of current
            $priorCount > 5 ? $priorCount : 0,    // ignore tiny priors
            $site->max_pages > 5 ? (int) ($site->max_pages / 10) : 10, // sane default ~10%
        );
        $percent = (int) min(100, round(($latest->pages_crawled / $expected) * 100));
    }
@endphp

@if ($isRunning)
    <div class="flex min-w-[14rem] flex-col gap-1.5">
        <div class="flex items-center justify-between text-[11px] font-medium">
            <span class="inline-flex items-center gap-1 text-primary-600 dark:text-primary-400">
                <svg class="h-3 w-3 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"/>
                </svg>
                <span>Crawling</span>
            </span>
            <span class="text-gray-600 dark:text-gray-400">{{ $percent }}%</span>
        </div>
        <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
            <div
                class="h-full rounded-full bg-gradient-to-r from-primary-500 to-primary-400 transition-all duration-700"
                style="width: {{ $percent }}%"
            ></div>
        </div>
    </div>
@elseif ($latest)
    <div class="flex items-center gap-2 text-xs">
        @php $statusColor = $latest->status->color(); @endphp
        <span @class([
            'h-1.5 w-1.5 shrink-0 rounded-full',
            'bg-emerald-500' => $statusColor === 'success',
            'bg-amber-500'   => $statusColor === 'warning',
            'bg-rose-500'    => $statusColor === 'danger',
            'bg-sky-500'     => $statusColor === 'info',
            'bg-gray-400'    => $statusColor === 'gray',
        ])></span>
        <span class="font-medium text-gray-700 dark:text-gray-300">
            {{ $latest->status->label() }}
        </span>
        <span class="text-gray-500 dark:text-gray-400">
            · {{ $site->last_crawled_at?->diffForHumans() ?? '—' }}
        </span>
    </div>
@else
    <span class="text-xs italic text-gray-400 dark:text-gray-500">— never —</span>
@endif
