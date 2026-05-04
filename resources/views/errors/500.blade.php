@extends('errors.layout')

@section('title', 'Something went wrong')

@section('trace_label', 'If the problem persists, share this with support — Trace ID:')

@section('content')
    <p class="eyebrow">Error 500</p>
    <h1>Something went wrong.</h1>
    <p class="description">
        Something went wrong on our end. Our team has been notified.
        Please try again in a moment.
    </p>
    <div class="actions">
        <span class="btn-shell">
            <span class="btn-shadow"></span>
            <a href="{{ url()->current() }}" class="btn">↻ Try again</a>
        </span>
        <a href="{{ url('/') }}" class="btn-secondary">Go home</a>
    </div>
@endsection
