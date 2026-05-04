@extends('errors.layout')

@section('title', 'Page not found')

@section('content')
    <p class="eyebrow">Error 404</p>
    <h1>Page not found.</h1>
    <p class="description">
        The page you're looking for doesn't exist or has been moved.
    </p>
    <div class="actions">
        <span class="btn-shell">
            <span class="btn-shadow"></span>
            <a href="{{ url('/') }}" class="btn">← Go home</a>
        </span>
    </div>
@endsection
