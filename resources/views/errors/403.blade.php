@extends('errors.layout')

@section('title', 'Access denied')

@php
    $user = auth()->user();
    $homeUrl = $user !== null ? \App\Support\Routing\LandingRoute::for($user) : url('/');
    $backUrl = url()->previous();
    $canGoBack = $backUrl !== url()->current() && $backUrl !== url('/');
@endphp

@section('content')
    <p class="eyebrow">Error 403</p>
    <h1>You don't have access to this page.</h1>
    <p class="description">
        Your account doesn't have permission to view this. If you think it should,
        ask the workspace owner to check your role.
    </p>
    <div class="actions">
        <span class="btn-shell">
            <span class="btn-shadow"></span>
            <a href="{{ $homeUrl }}" class="btn">{{ $user !== null ? 'Go to your dashboard' : 'Go home' }}</a>
        </span>
        @if ($canGoBack)
            <a href="{{ $backUrl }}" class="btn-secondary">← Go back</a>
        @endif
    </div>
@endsection
