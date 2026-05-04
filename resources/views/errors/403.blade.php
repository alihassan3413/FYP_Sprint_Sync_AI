<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Error') · {{ config('app.name') }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 0;
            min-height: 100vh;
            background-color: #f4f4ef;
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #111111;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 48px 16px;
        }
        .wrapper {
            width: 100%;
            max-width: 560px;
        }
        .brand {
            margin: 0 0 28px;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 3px;
            text-transform: uppercase;
            color: #111111;
        }
        .card-shell {
            position: relative;
        }
        .card-shadow {
            position: absolute;
            inset: 0;
            transform: translate(6px, 6px);
            background-color: #111111;
        }
        .card {
            position: relative;
            background-color: #ffffff;
            border: 2px solid #111111;
            padding: 44px;
        }
        .eyebrow {
            margin: 0 0 14px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: #6b6b6b;
        }
        h1 {
            margin: 0 0 16px;
            color: #111111;
            font-size: 36px;
            font-weight: 800;
            line-height: 1.15;
            letter-spacing: -0.5px;
        }
        .description {
            margin: 0 0 32px;
            font-size: 15px;
            line-height: 1.65;
            color: #1f1f1f;
        }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin: 0 0 32px;
        }
        .btn-shell {
            position: relative;
            display: inline-block;
        }
        .btn-shadow {
            position: absolute;
            inset: 0;
            transform: translate(4px, 4px);
            background-color: #d4ff4f;
            z-index: 0;
        }
        .btn {
            position: relative;
            z-index: 1;
            display: inline-block;
            background-color: #111111;
            color: #ffffff;
            text-decoration: none;
            padding: 12px 28px;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 0.5px;
            border: 2px solid #111111;
            cursor: pointer;
            font-family: inherit;
        }
        .btn:hover {
            opacity: 0.9;
        }
        .btn-secondary {
            display: inline-block;
            background-color: #ffffff;
            color: #111111;
            text-decoration: none;
            padding: 12px 28px;
            font-size: 14px;
            font-weight: 800;
            letter-spacing: 0.5px;
            border: 2px solid #111111;
            cursor: pointer;
            font-family: inherit;
        }
        .btn-secondary:hover {
            background-color: #f4f4ef;
        }
        .trace {
            margin: 0;
            padding-top: 20px;
            border-top: 1px solid #e5e5e0;
            font-size: 12px;
            line-height: 1.6;
            color: #9a9a9a;
        }
        .trace-id {
            font-family: 'SF Mono', Monaco, Consolas, monospace;
            color: #6b6b6b;
        }
        @media (max-width: 480px) {
            .card { padding: 28px; }
            h1 { font-size: 28px; }
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <p class="brand">{{ config('app.name') }}</p>

        <div class="card-shell">
            <div class="card-shadow"></div>
            <div class="card">
                @yield('content')

                @php
                    $traceId = request()->header('X-Trace-Id')
                        ?? (app()->bound(\App\Support\Errors\TraceId::class)
                            ? app(\App\Support\Errors\TraceId::class)->get()
                            : null);
                @endphp

                @if($traceId)
                    <p class="trace">
                        @yield('trace_label', 'Trace ID:')
                        <span class="trace-id">{{ $traceId }}</span>
                    </p>
                @endif
            </div>
        </div>
    </div>
</body>
</html>
