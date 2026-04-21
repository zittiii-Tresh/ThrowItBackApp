@extends('layouts.app')

@section('content')
<section class="mx-auto flex max-w-lg flex-col items-center px-6 py-20 text-center">
    <div class="grid h-16 w-16 place-items-center rounded-2xl bg-brand-50 text-brand-600 dark:bg-brand-950 dark:text-brand-300">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="h-8 w-8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/>
        </svg>
    </div>

    <h1 class="mt-6 text-2xl font-semibold text-surface-900 dark:text-surface-100">Verify your email</h1>
    <p class="mt-2 text-sm text-surface-600 dark:text-surface-400">
        Before you can access the SiteArchive admin panel, please click the link in the
        verification email we just sent to <strong>{{ auth()->user()?->email }}</strong>.
    </p>

    @if (session('message'))
        <p class="mt-4 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
            {{ session('message') }}
        </p>
    @endif

    <form method="POST" action="{{ route('verification.send') }}" class="mt-8">
        @csrf
        <button type="submit" class="btn-primary">Resend verification email</button>
    </form>

    <form method="POST" action="{{ route('logout') ?? url('/logout') }}" class="mt-3">
        @csrf
        <button type="submit" class="text-xs text-surface-500 hover:text-surface-700 dark:text-surface-400 dark:hover:text-surface-200">
            Log out
        </button>
    </form>
</section>
@endsection
