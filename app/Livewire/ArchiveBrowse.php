<?php

namespace App\Livewire;

use App\Enums\CrawlStatus;
use App\Models\CrawlRun;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * User Archive Screen 2 — Browse (calendar).
 *
 * Full-page Livewire component mounted at /browse/{site}.
 *
 * Two panes:
 *   LEFT  — month calendar; days with snapshots are highlighted purple.
 *           Previous/next arrows scrub through months. Clicking a day
 *           shows its runs on the right.
 *   RIGHT — list of crawl runs for the selected day. Each item shows
 *           time, page/asset counts, status badge, and a View link into
 *           the Phase 6c snapshot viewer.
 */
#[Layout('layouts.app')]
class ArchiveBrowse extends Component
{
    public Site $site;

    /** Currently-displayed month (YYYY-MM). Persisted in URL so back works. */
    #[Url(as: 'm')]
    public ?string $month = null;

    /** Currently-selected day (YYYY-MM-DD). null = no day selected. */
    #[Url(as: 'd')]
    public ?string $selectedDay = null;

    public function mount(Site $site): void
    {
        $this->site = $site;

        // Default to the month of the most recent run (or now if no runs).
        if (! $this->month) {
            $latest = $site->crawlRuns()->latest()->first();
            $this->month = ($latest?->created_at ?? now())->format('Y-m');
        }

        // Default selected day = today if it has runs this month, otherwise
        // the newest day with runs in the visible month.
        if (! $this->selectedDay) {
            $this->selectedDay = $this->daysWithRuns()->keys()->first();
        }
    }

    /**
     * Map of "YYYY-MM-DD" → run count for the currently-shown month.
     * Used by the calendar to decide which days to highlight.
     */
    public function daysWithRuns(): Collection
    {
        $start = CarbonImmutable::parse($this->month . '-01')->startOfMonth();
        $end   = $start->endOfMonth();

        return CrawlRun::query()
            ->where('site_id', $this->site->id)
            ->whereBetween('created_at', [$start, $end])
            ->get(['id', 'created_at'])
            ->groupBy(fn (CrawlRun $r) => $r->created_at->format('Y-m-d'))
            ->map->count();
    }

    /** Runs on the currently-selected day (for the right-hand list). */
    public function runsForSelectedDay(): Collection
    {
        if (! $this->selectedDay) {
            return collect();
        }
        $start = CarbonImmutable::parse($this->selectedDay)->startOfDay();
        $end   = $start->endOfDay();

        return CrawlRun::query()
            ->with('site')
            ->where('site_id', $this->site->id)
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('created_at')
            ->get();
    }

    public function previousMonth(): void
    {
        $this->month = CarbonImmutable::parse($this->month . '-01')->subMonth()->format('Y-m');
        $this->selectedDay = null;
    }

    public function nextMonth(): void
    {
        $this->month = CarbonImmutable::parse($this->month . '-01')->addMonth()->format('Y-m');
        $this->selectedDay = null;
    }

    public function selectDay(string $day): void
    {
        // Only highlight a day if it has runs. Guards against clicking a
        // blank cell in the calendar grid.
        if (isset($this->daysWithRuns()[$day])) {
            $this->selectedDay = $day;
        }
    }

    public function getCrawlStatusColorProperty(): callable
    {
        return fn (CrawlStatus $s) => $s->color();
    }

    /**
     * Title shown in browser tab.
     */
    #[Title('Browse archives')]
    public function render()
    {
        return view('livewire.archive-browse', [
            'monthDate'    => CarbonImmutable::parse($this->month . '-01'),
            'daysWithRuns' => $this->daysWithRuns(),
            'runs'         => $this->runsForSelectedDay(),
        ]);
    }
}
