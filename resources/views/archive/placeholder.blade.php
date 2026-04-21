{{-- Stub page for archive routes built-in-progress. Replaced by real
     Livewire components as Phase 6 fills them in. --}}
@extends('layouts.app')

@section('content')
    <section class="mx-auto flex max-w-2xl flex-col items-center px-6 py-24 text-center">
        <div class="grid h-16 w-16 place-items-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-950 dark:text-brand-300">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="h-8 w-8">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
            </svg>
        </div>
        <h1 class="mt-6 text-2xl font-semibold text-surface-900 dark:text-surface-100">
            {{ $title ?? 'Coming soon' }}
        </h1>
        <p class="mt-2 max-w-md text-sm text-surface-600 dark:text-surface-400">
            {{ $message ?? 'This screen is under construction.' }}
        </p>
        @if (isset($site))
            <p class="mt-4 text-xs text-surface-500 dark:text-surface-400">
                {{ $site->base_url }}
                @if ($site->last_crawled_at) · last crawl {{ $site->last_crawled_at->diffForHumans() }}@endif
            </p>
        @endif
        <a href="{{ route('home') }}" class="mt-8 text-sm font-medium text-brand-600 hover:underline dark:text-brand-400">
            ← Back to home
        </a>
    </section>
@endsection
