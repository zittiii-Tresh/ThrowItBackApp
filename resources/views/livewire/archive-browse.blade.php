<div class="mx-auto max-w-6xl px-6 py-10">

    {{-- Breadcrumb + site header --}}
    <div class="mb-6 flex items-start justify-between gap-4">
        <div class="min-w-0">
            <a href="{{ route('home') }}" class="text-xs text-surface-500 hover:text-brand-600 dark:text-surface-400 dark:hover:text-brand-400">← All archives</a>
            <h1 class="mt-1 truncate text-2xl font-semibold text-surface-900 dark:text-surface-100">
                {{ $site->name }}
            </h1>
            <a href="{{ $site->base_url }}" target="_blank" rel="noopener"
               class="mt-1 block truncate text-sm text-surface-500 hover:text-brand-600 dark:text-surface-400 dark:hover:text-brand-400">
                {{ $site->base_url }}
            </a>
        </div>
        <div class="text-right text-xs text-surface-500 dark:text-surface-400">
            <div>{{ $site->crawlRuns()->count() }} total runs</div>
            @if ($site->last_crawled_at)
                <div>last crawl {{ $site->last_crawled_at->diffForHumans() }}</div>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">

        {{-- Calendar pane (spans 3 cols on desktop) --}}
        <div class="lg:col-span-3 rounded-xl border border-surface-200 bg-white p-5 shadow-sm dark:border-surface-800 dark:bg-surface-900">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-surface-900 dark:text-surface-100">
                    {{ $monthDate->format('F Y') }}
                </h2>
                <div class="flex items-center gap-1">
                    <button wire:click="previousMonth"
                            class="grid h-8 w-8 place-items-center rounded-md text-surface-500 hover:bg-surface-100 hover:text-surface-900 dark:text-surface-400 dark:hover:bg-surface-800 dark:hover:text-surface-100">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </button>
                    <button wire:click="nextMonth"
                            class="grid h-8 w-8 place-items-center rounded-md text-surface-500 hover:bg-surface-100 hover:text-surface-900 dark:text-surface-400 dark:hover:bg-surface-800 dark:hover:text-surface-100">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Weekday header row (Mon–Sun) --}}
            <div class="mt-5 grid grid-cols-7 gap-1 text-center text-[11px] font-medium uppercase tracking-wider text-surface-400 dark:text-surface-500">
                <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span>
            </div>

            {{-- Day cells --}}
            <div class="mt-2 grid grid-cols-7 gap-1">
                @php
                    // First Monday on or before the month start — gives us the
                    // leading blanks so the 1st lines up under the right weekday.
                    $cursor = $monthDate->startOfMonth();
                    $leadingBlank = ($cursor->dayOfWeek + 6) % 7; // Mon=0..Sun=6
                    $today = now()->format('Y-m-d');
                @endphp

                {{-- Leading empty cells --}}
                @for ($i = 0; $i < $leadingBlank; $i++)
                    <span class="h-10"></span>
                @endfor

                {{-- Actual days in month --}}
                @for ($day = 1; $day <= $monthDate->daysInMonth; $day++)
                    @php
                        $key = $monthDate->format('Y-m-') . str_pad($day, 2, '0', STR_PAD_LEFT);
                        $hasRuns = isset($daysWithRuns[$key]);
                        $isSelected = $selectedDay === $key;
                        $isToday = $key === $today;
                    @endphp
                    <button
                        type="button"
                        @if ($hasRuns) wire:click="selectDay('{{ $key }}')" @endif
                        @disabled(! $hasRuns)
                        class="relative h-10 rounded-md text-sm transition
                               {{ $isSelected ? 'bg-brand-600 text-white font-semibold shadow' : '' }}
                               {{ ! $isSelected && $hasRuns ? 'text-brand-700 dark:text-brand-300 hover:bg-brand-50 dark:hover:bg-brand-950 font-medium' : '' }}
                               {{ ! $hasRuns ? 'text-surface-300 dark:text-surface-700 cursor-default' : '' }}
                               {{ $isToday && ! $isSelected ? 'ring-1 ring-brand-300 dark:ring-brand-700' : '' }}"
                    >
                        {{ $day }}
                        @if ($hasRuns && ! $isSelected)
                            <span class="absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-brand-500"></span>
                        @endif
                    </button>
                @endfor
            </div>
        </div>

        {{-- Runs list for the selected day --}}
        <div class="lg:col-span-2 rounded-xl border border-surface-200 bg-white p-5 shadow-sm dark:border-surface-800 dark:bg-surface-900">
            <h2 class="text-sm font-semibold text-surface-900 dark:text-surface-100">
                @if ($selectedDay)
                    Snapshots — {{ \Illuminate\Support\Carbon::parse($selectedDay)->format('M j, Y') }}
                @else
                    Select a date
                @endif
            </h2>

            @if ($runs->isEmpty())
                <div class="mt-6 rounded-lg border border-dashed border-surface-200 p-8 text-center text-sm text-surface-500 dark:border-surface-800 dark:text-surface-400">
                    @if ($selectedDay)
                        No snapshots on this day.
                    @else
                        Pick a highlighted day from the calendar to see its snapshots.
                    @endif
                </div>
            @else
                <ul class="mt-4 space-y-3">
                    @foreach ($runs as $run)
                        @php
                            $firstOkSnapshotId = optional($run->snapshots()->where('status_code',200)->first())->id;
                        @endphp
                        <li class="rounded-lg border border-surface-200 p-3 dark:border-surface-800">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-surface-900 dark:text-surface-100">
                                        {{ $run->started_at?->format('h:i A') ?? '—' }}
                                    </div>
                                    <div class="mt-0.5 text-xs text-surface-500 dark:text-surface-400">
                                        {{ $run->pages_crawled }} {{ \Illuminate\Support\Str::plural('page', $run->pages_crawled) }} ·
                                        {{ $run->assets_downloaded }} {{ \Illuminate\Support\Str::plural('asset', $run->assets_downloaded) }}
                                        @if ($run->triggered_by->value === 'manual') · Manual @endif
                                    </div>
                                </div>
                                <span @class([
                                    'shrink-0 rounded-full px-2 py-1 text-[10px] font-semibold uppercase tracking-wider',
                                    'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' => $run->status->value === 'complete',
                                    'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300' => $run->status->value === 'partial',
                                    'bg-rose-50 text-rose-700 dark:bg-rose-950 dark:text-rose-300' => $run->status->value === 'failed',
                                    'bg-surface-100 text-surface-700 dark:bg-surface-800 dark:text-surface-300' => in_array($run->status->value, ['queued', 'running']),
                                ])>
                                    {{ $run->status->label() }}
                                </span>
                            </div>

                            @if ($firstOkSnapshotId)
                                <a href="{{ url("/archive/snapshot/{$firstOkSnapshotId}") }}"
                                   class="mt-3 inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:underline dark:text-brand-400">
                                    View snapshot →
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
