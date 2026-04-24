{{--
    Notifications feed — Admin Screen 6.
    Backed by App\Filament\Pages\Notifications (markRead / markAllRead
    Livewire actions).
--}}
<x-filament-panels::page>
    @php
        $events = $this->getEvents();
        $unreadCount = $events->whereNull('read_at')->count();
    @endphp

    @if ($unreadCount > 0)
        <div class="flex justify-end -mt-2 mb-2">
            <button
                type="button"
                wire:click="markAllRead"
                class="text-xs font-medium text-primary-600 hover:text-primary-700 dark:text-primary-400"
            >
                Mark all as read ({{ $unreadCount }})
            </button>
        </div>
    @endif

    @if ($events->isEmpty())
        {{-- Empty state styled to match Filament's TableWidget empty state
             (e.g. "Upcoming today" on the Dashboard): circular icon halo,
             generous padding, soft secondary description text. --}}
        <div class="sa-notify-card rounded-xl text-center" style="padding: 4rem 2.5rem;">
            <div class="sa-empty-icon-halo">
                <x-filament::icon icon="heroicon-o-check-circle" class="text-emerald-300"/>
            </div>
            <h3 style="margin-top: 1.25rem;" class="text-base font-semibold text-emerald-300">All healthy</h3>
            <p style="margin-top: 0.5rem; padding-left: 2rem; padding-right: 2rem;" class="text-sm text-gray-500 dark:text-gray-400">
                No failed crawls. Only crawls that fail will appear here — successes are silent.
            </p>
        </div>
    @else
        {{--
            Each notification is its own rounded card instead of a flush list —
            hover lights up the border + adds a ring, no background fill,
            so text contrast stays the same in both themes.
        --}}
        <ul class="space-y-2">
            @foreach ($events as $event)
                @php
                    // Dot color by severity level.
                    $dotColor = match ($event->level->value) {
                        'error'   => 'bg-rose-500',
                        'warning' => 'bg-amber-500',
                        default   => 'bg-emerald-500',
                    };
                    // Matching glow color on hover — ring-*-300 picks up the level.
                    $ringColor = match ($event->level->value) {
                        'error'   => 'hover:ring-rose-300 dark:hover:ring-rose-700 hover:border-rose-300 dark:hover:border-rose-700',
                        'warning' => 'hover:ring-amber-300 dark:hover:ring-amber-700 hover:border-amber-300 dark:hover:border-amber-700',
                        default   => 'hover:ring-emerald-300 dark:hover:ring-emerald-700 hover:border-emerald-300 dark:hover:border-emerald-700',
                    };
                    $isRead = $event->read_at !== null;
                @endphp

                <li
                    wire:click="markRead({{ $event->id }})"
                    class="sa-notify-item group flex cursor-pointer items-start gap-3 rounded-xl border shadow-sm transition
                           hover:ring-2 hover:ring-offset-0 hover:shadow-md
                           {{ $ringColor }}"
                >
                    <span class="mt-1.5 inline-block h-2 w-2 shrink-0 rounded-full {{ $dotColor }} {{ $isRead ? 'opacity-50' : '' }}"></span>

                    <div class="min-w-0 flex-1">
                        <p @class([
                            'text-sm',
                            'font-medium text-gray-900 dark:text-gray-100' => ! $isRead,
                            'text-gray-500 dark:text-gray-400'             => $isRead,
                        ])>
                            {{ $event->message }}
                        </p>
                        <p @class([
                            'mt-0.5 text-xs',
                            'text-gray-500 dark:text-gray-400' => ! $isRead,
                            'text-gray-400 dark:text-gray-500' => $isRead,
                        ])>
                            {{ $event->created_at->format('M j, Y · g:i A') }}
                            @if ($event->site)
                                · <a
                                    href="{{ \App\Filament\Resources\SiteResource::getUrl('edit', ['record' => $event->site]) }}"
                                    class="text-primary-600 hover:underline dark:text-primary-400"
                                    wire:click.stop
                                >{{ $event->site->name }}</a>
                            @endif
                        </p>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament-panels::page>
