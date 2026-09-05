{{--
  The platform console's shell.

  Plain CSS, no build step, no framework. This surface is used by a handful of
  operators on a desktop; adding a frontend toolchain to serve it would be a
  standing maintenance cost for something that renders a table and four buttons.
  The mobile app is where the design system lives.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Platform') · BCS</title>
    <style>
        :root {
            --ink: #1c1917;
            --muted: #78716c;
            --line: #e7e5e4;
            --bg: #fafaf9;
            --panel: #ffffff;
            --accent: #7c5cff;
            --danger: #b91c1c;
            --danger-bg: #fef2f2;
            --ok: #15803d;
            --warn: #b45309;
            --warn-bg: #fffbeb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font: 14px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            color: var(--ink);
            background: var(--bg);
        }
        a { color: var(--accent); }
        header.bar {
            display: flex; align-items: center; gap: 20px;
            padding: 12px 24px; background: var(--panel);
            border-bottom: 1px solid var(--line);
        }
        header.bar .brand { font-weight: 650; letter-spacing: -0.01em; }
        header.bar nav { display: flex; gap: 16px; margin-left: auto; align-items: center; }
        header.bar .who { color: var(--muted); font-size: 13px; }
        main { max-width: 1100px; margin: 0 auto; padding: 24px; }
        h1 { font-size: 20px; margin: 0 0 4px; letter-spacing: -0.01em; }
        h2 { font-size: 14px; text-transform: uppercase; letter-spacing: 0.06em;
             color: var(--muted); margin: 28px 0 10px; font-weight: 600; }
        .sub { color: var(--muted); margin: 0 0 20px; }
        .panel { background: var(--panel); border: 1px solid var(--line);
                 border-radius: 10px; padding: 16px; }
        table { width: 100%; border-collapse: collapse; background: var(--panel);
                border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--line); }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); }
        tr:last-child td { border-bottom: 0; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
        .stat { background: var(--panel); border: 1px solid var(--line);
                border-radius: 10px; padding: 14px; }
        .stat .k { color: var(--muted); font-size: 12px; text-transform: uppercase;
                   letter-spacing: 0.05em; }
        .stat .v { font-size: 22px; font-variant-numeric: tabular-nums; margin-top: 4px; }
        .pill { display: inline-block; padding: 2px 8px; border-radius: 999px;
                font-size: 12px; border: 1px solid var(--line); }
        .pill.active { color: var(--ok); border-color: currentColor; }
        .pill.suspended { color: var(--danger); border-color: currentColor; }
        .pill.archived, .pill.provisioning { color: var(--muted); }
        .pill.failed { color: var(--danger); border-color: currentColor; }
        .note { background: var(--warn-bg); border: 1px solid #fde68a;
                border-radius: 10px; padding: 12px 14px; margin-bottom: 18px; }
        .flash { background: #f0fdf4; border: 1px solid #bbf7d0; color: var(--ok);
                 border-radius: 10px; padding: 12px 14px; margin-bottom: 18px; }
        .errors { background: var(--danger-bg); border: 1px solid #fecaca; color: var(--danger);
                  border-radius: 10px; padding: 12px 14px; margin-bottom: 18px; }
        label { display: block; font-size: 13px; margin: 10px 0 4px; }
        input[type=text], input[type=email], input[type=password], select {
            width: 100%; padding: 8px 10px; border: 1px solid var(--line);
            border-radius: 8px; font: inherit; background: #fff;
        }
        button {
            font: inherit; padding: 8px 14px; border-radius: 8px; cursor: pointer;
            border: 1px solid var(--line); background: #fff;
        }
        button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        button.danger { background: var(--danger); border-color: var(--danger); color: #fff; }
        pre { background: #0c0a09; color: #e7e5e4; padding: 12px; border-radius: 8px;
              overflow-x: auto; font-size: 12px; }
        .muted { color: var(--muted); }
        .row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
    </style>
</head>
<body>
@auth('operator')
    <header class="bar">
        <span class="brand">BCS Platform</span>
        <nav>
            <a href="{{ route('platform.index') }}">Associations</a>
            {{--
              Counted in the nav, because an unapproved grant is a colleague
              waiting mid-incident, and an approval queue nobody looks at is how
              two-operator approval quietly becomes one-operator approval.
            --}}
            @php($pendingGrants = \App\Models\BreakGlassGrant::where('status', 'pending')->count())
            <a href="{{ route('platform.break-glass') }}">
                Break-glass
                @if ($pendingGrants)
                    <strong>({{ $pendingGrants }})</strong>
                @endif
            </a>
            <a href="{{ route('platform.audit') }}">Audit</a>
            <span class="who">{{ auth('operator')->user()->email }}</span>
            <form method="POST" action="{{ route('platform.logout') }}">
                @csrf
                <button type="submit">Sign out</button>
            </form>
        </nav>
    </header>
@endauth

<main>
    @if (session('status'))
        <div class="flash">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="errors">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    @yield('content')
</main>
</body>
</html>
