<?php

namespace App\Livewire;

use App\Models\Site;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * User Archive Screen 1 — Home / search.
 *
 * Full-page Livewire component rendered at "/". Behavior:
 *   - Live-search any site by name or base_url as the user types
 *   - Shows recently-crawled sites as quick-access chips
 *   - Enter or clicking a chip routes to /browse/{site}
 */
#[Layout('layouts.app')]
#[Title('SiteArchive — Browse past versions')]
class ArchiveHome extends Component
{
    /** Search input — updates live with a small debounce so we don't query every keystroke. */
    public string $query = '';

    /** Recently-crawled sites for the chip row. Capped to 6. */
    public function getRecentSitesProperty(): Collection
    {
        return Site::query()
            ->whereNotNull('last_crawled_at')
            ->orderByDesc('last_crawled_at')
            ->limit(6)
            ->get();
    }

    /** Live-search results — populated when the user types 2+ chars. */
    public function getMatchesProperty(): Collection
    {
        $q = trim($this->query);
        if (strlen($q) < 2) {
            return collect();
        }

        return Site::query()
            ->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                        ->orWhere('base_url', 'like', "%{$q}%");
            })
            ->orderByDesc('last_crawled_at')
            ->limit(8)
            ->get();
    }

    /**
     * Enter → jump straight to the top matching site's browse page.
     * If nothing matches, stay on the home page (chip view handles feedback).
     */
    public function submit(): mixed
    {
        $top = $this->matches->first();
        if ($top) {
            return redirect()->route('archive.browse', $top);
        }
        return null;
    }

    public function render()
    {
        return view('livewire.archive-home');
    }
}
