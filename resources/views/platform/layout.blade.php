{{--
  The platform console's shell.

  PLAIN CSS, NO BUILD STEP, NO FRAMEWORK. This surface is used by a handful of
  operators on a desktop; adding a frontend toolchain to serve it would be a
  standing maintenance cost for something that renders tables and forms. The
  mobile app is where the design system lives.

  A SIDEBAR, NOT A ROW OF LINKS. The console grew from two pages to six, and a
  horizontal nav had already started hiding things behind their own length. A
  vertical nav has room to say what each destination is, to show a count next to
  the one that needs attention, and to mark where you are — which a row of
  identical links cannot.

  NO JAVASCRIPT ANYWHERE. The navigation, the responsive collapse and the
  disclosure sections are all CSS and native HTML elements. A console that an
  operator reaches during an incident should not depend on a script loading.
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
            --ink-soft: #44403c;
            --muted: #78716c;
            --line: #e7e5e4;
            --line-soft: #f0efee;
            --bg: #fafaf9;
            --panel: #ffffff;
            --accent: #6d4aff;
            --accent-soft: #f2eeff;
            --danger: #b91c1c;
            --danger-bg: #fef2f2;
            --ok: #15803d;
            --ok-bg: #f0fdf4;
            --warn: #b45309;
            --warn-bg: #fffbeb;
            --sidebar: 236px;
            --radius: 10px;
        }

        /*
          Dark only when the operator's system asks for it. Not a toggle: a
          preference stored per browser is one more thing to be wrong on the
          machine somebody is borrowing at 2am.
        */
        @media (prefers-color-scheme: dark) {
            :root {
                --ink: #f5f5f4;
                --ink-soft: #d6d3d1;
                --muted: #a8a29e;
                --line: #292524;
                --line-soft: #211d1b;
                --bg: #171412;
                --panel: #1f1c1a;
                --accent: #a78bfa;
                --accent-soft: #2a2440;
                --danger: #f87171;
                --danger-bg: #2a1717;
                --ok: #4ade80;
                --ok-bg: #14241a;
                --warn: #fbbf24;
                --warn-bg: #2a2313;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font: 14px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            color: var(--ink);
            background: var(--bg);
            -webkit-font-smoothing: antialiased;
        }

        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }

        /* Visible focus everywhere. Keyboard use is not an edge case here. */
        :focus-visible {
            outline: 2px solid var(--accent);
            outline-offset: 2px;
            border-radius: 4px;
        }

        /* ---------------------------------------------------------- shell */

        .shell { display: flex; min-height: 100vh; }

        aside.nav {
            width: var(--sidebar);
            flex: 0 0 var(--sidebar);
            background: var(--panel);
            border-right: 1px solid var(--line);
            display: flex;
            flex-direction: column;
            position: sticky;
            top: 0;
            height: 100vh;
        }

        .brand {
            display: flex; align-items: center; gap: 10px;
            padding: 18px 18px 14px;
            font-weight: 650; letter-spacing: -0.01em;
            color: var(--ink);
        }
        .brand:hover { text-decoration: none; }
        .brand .mark {
            width: 26px; height: 26px; border-radius: 7px;
            background: var(--accent); color: #fff;
            display: grid; place-items: center;
            font-size: 13px; font-weight: 700;
        }
        .brand small { display: block; font-size: 11px; font-weight: 500; color: var(--muted); }

        .nav-group { padding: 6px 10px; }
        .nav-group h6 {
            margin: 12px 8px 6px;
            font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.08em;
            color: var(--muted); font-weight: 600;
        }

        .nav a.item {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 10px; margin-bottom: 2px;
            border-radius: 8px;
            color: var(--ink-soft); font-weight: 500;
        }
        .nav a.item:hover { background: var(--line-soft); text-decoration: none; }
        .nav a.item.on { background: var(--accent-soft); color: var(--accent); font-weight: 600; }
        .nav a.item svg { flex: 0 0 16px; opacity: 0.85; }
        .nav a.item .count {
            margin-left: auto;
            background: var(--danger); color: #fff;
            font-size: 11px; font-weight: 600;
            padding: 1px 7px; border-radius: 999px;
        }

        .nav .who {
            margin-top: auto;
            border-top: 1px solid var(--line);
            padding: 12px 14px;
            font-size: 12px; color: var(--muted);
        }
        .nav .who strong { display: block; color: var(--ink); font-weight: 600; font-size: 13px; }
        .nav .who form { margin-top: 8px; }
        .nav .who button { width: 100%; }

        /* ----------------------------------------------------------- main */

        .main { flex: 1; min-width: 0; }

        .topbar {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 28px;
            border-bottom: 1px solid var(--line);
            background: var(--panel);
            position: sticky; top: 0; z-index: 5;
        }
        .topbar .crumbs { font-size: 13px; color: var(--muted); }
        .topbar .crumbs a { color: var(--muted); }
        .topbar .crumbs a:hover { color: var(--accent); }
        .topbar .spacer { margin-left: auto; }

        main { max-width: 1120px; padding: 24px 28px 64px; }

        h1 { font-size: 22px; margin: 0 0 4px; letter-spacing: -0.015em; }
        h2 {
            font-size: 12px; text-transform: uppercase; letter-spacing: 0.07em;
            color: var(--muted); margin: 32px 0 10px; font-weight: 650;
        }
        h6 { margin: 0; }
        .sub { color: var(--muted); margin: 0 0 20px; }

        /* --------------------------------------------------------- pieces */

        .panel {
            background: var(--panel); border: 1px solid var(--line);
            border-radius: var(--radius); padding: 16px;
        }

        table {
            width: 100%; border-collapse: collapse; background: var(--panel);
            border: 1px solid var(--line); border-radius: var(--radius); overflow: hidden;
        }
        th, td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--line); }
        th {
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em;
            color: var(--muted); font-weight: 600; background: var(--line-soft);
        }
        tbody tr:hover { background: var(--line-soft); }
        tr:last-child td { border-bottom: 0; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }

        /* Wide tables scroll inside themselves rather than pushing the page. */
        .scroll-x { overflow-x: auto; border-radius: var(--radius); }

        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(165px, 1fr)); gap: 12px; }
        .stat {
            background: var(--panel); border: 1px solid var(--line);
            border-radius: var(--radius); padding: 14px;
        }
        .stat .k {
            color: var(--muted); font-size: 11px; text-transform: uppercase;
            letter-spacing: 0.05em; font-weight: 600;
        }
        .stat .v { font-size: 22px; font-variant-numeric: tabular-nums; margin-top: 4px; }

        .pill {
            display: inline-block; padding: 2px 9px; border-radius: 999px;
            font-size: 11.5px; font-weight: 550; border: 1px solid var(--line);
            color: var(--muted);
        }
        .pill.active { color: var(--ok); border-color: currentColor; background: var(--ok-bg); }
        .pill.suspended, .pill.failed { color: var(--danger); border-color: currentColor; background: var(--danger-bg); }
        .pill.archived, .pill.provisioning { color: var(--muted); }

        .note, .flash, .errors {
            border-radius: var(--radius); padding: 12px 14px; margin-bottom: 18px;
            border: 1px solid;
        }
        .note { background: var(--warn-bg); border-color: var(--warn); color: var(--ink); }
        .flash { background: var(--ok-bg); border-color: var(--ok); color: var(--ink); }
        .errors { background: var(--danger-bg); border-color: var(--danger); color: var(--danger); }

        label { display: block; font-size: 12.5px; font-weight: 550; margin: 12px 0 4px; }
        input[type=text], input[type=email], input[type=password], select, textarea {
            width: 100%; padding: 8px 10px;
            border: 1px solid var(--line); border-radius: 8px;
            font: inherit; background: var(--panel); color: var(--ink);
        }
        input:focus, select:focus { border-color: var(--accent); }

        button {
            font: inherit; font-weight: 550;
            padding: 8px 14px; border-radius: 8px; cursor: pointer;
            border: 1px solid var(--line); background: var(--panel); color: var(--ink);
        }
        button:hover { border-color: var(--muted); }
        button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        button.primary:hover { filter: brightness(1.08); }
        button.danger { background: var(--danger); border-color: var(--danger); color: #fff; }
        button:disabled { opacity: 0.45; cursor: not-allowed; }

        pre {
            background: #0c0a09; color: #e7e5e4; padding: 12px;
            border-radius: 8px; overflow-x: auto; font-size: 12px;
        }
        code { font-size: 12.5px; }

        .muted { color: var(--muted); }
        .row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

        details > summary { cursor: pointer; }
        details > summary::marker { color: var(--muted); }

        /* ---------------------------------------------------- narrow screens */

        @media (max-width: 900px) {
            .shell { display: block; }
            aside.nav {
                width: auto; height: auto; position: static;
                border-right: 0; border-bottom: 1px solid var(--line);
                flex-direction: row; flex-wrap: wrap; align-items: center;
            }
            .brand { padding: 12px 16px; }
            .nav-group { display: flex; padding: 0 8px; overflow-x: auto; }
            .nav-group h6 { display: none; }
            .nav a.item { margin-bottom: 0; white-space: nowrap; }
            .nav .who {
                margin-top: 0; margin-left: auto;
                border-top: 0; display: flex; align-items: center; gap: 10px;
            }
            .nav .who strong { display: inline; }
            .nav .who form { margin: 0; }
            .topbar { padding: 12px 16px; }
            main { padding: 18px 16px 48px; }
        }
    </style>
</head>
<body>

@auth('operator')
    @php($pendingGrants = \App\Models\BreakGlassGrant::where('status', 'pending')->count())

    <div class="shell">
        <aside class="nav">
            <a href="{{ route('platform.index') }}" class="brand">
                <span class="mark">B</span>
                <span>
                    BCS Platform
                    <small>Operator console</small>
                </span>
            </a>

            <div class="nav-group">
                <h6>Manage</h6>

                {{--
                  `on` marks where you are. A row of identical links leaves that
                  to the page title, which is the one thing somebody scanning a
                  sidebar is not reading.
                --}}
                <a class="item {{ request()->routeIs('platform.index', 'platform.tenant*') ? 'on' : '' }}"
                   href="{{ route('platform.index') }}">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M2 14h12M3 14V6l5-3.5L13 6v8M6.5 14v-3.5h3V14"/>
                    </svg>
                    Associations
                </a>

                <a class="item {{ request()->routeIs('platform.break-glass') ? 'on' : '' }}"
                   href="{{ route('platform.break-glass') }}">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
                        <rect x="3.5" y="7" width="9" height="6.5" rx="1.5"/>
                        <path d="M5.5 7V4.75a2.5 2.5 0 015 0V7"/>
                    </svg>
                    Break-glass
                    {{--
                      Counted here because an unapproved grant is a colleague
                      waiting mid-incident, and an approval queue nobody looks at
                      is how two-operator approval quietly becomes one-operator.
                    --}}
                    @if ($pendingGrants)
                        <span class="count">{{ $pendingGrants }}</span>
                    @endif
                </a>
            </div>

            <div class="nav-group">
                <h6>Records</h6>

                <a class="item {{ request()->routeIs('platform.operators') ? 'on' : '' }}"
                   href="{{ route('platform.operators') }}">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
                        <circle cx="8" cy="5.5" r="2.5"/>
                        <path d="M3 13.5c0-2.5 2.2-4 5-4s5 1.5 5 4"/>
                    </svg>
                    Operators
                </a>

                <a class="item {{ request()->routeIs('platform.audit') ? 'on' : '' }}"
                   href="{{ route('platform.audit') }}">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M4 2.5h8v11H4zM6 5.5h4M6 8h4M6 10.5h2.5"/>
                    </svg>
                    Audit
                </a>
            </div>

            <div class="who">
                <strong>{{ auth('operator')->user()->name }}</strong>
                {{ auth('operator')->user()->email }}
                <form method="POST" action="{{ route('platform.logout') }}">
                    @csrf
                    <button type="submit">Sign out</button>
                </form>
            </div>
        </aside>

        <div class="main">
            <div class="topbar">
                {{--
                  A trail, not just a title. Most pages here are one association,
                  and the way back to the list was previously the browser button.
                --}}
                <div class="crumbs">
                    <a href="{{ route('platform.index') }}">Platform</a>
                    @hasSection('crumb')
                        <span class="muted"> / </span>@yield('crumb')
                    @endif
                </div>
                <div class="spacer"></div>
                @yield('topbar')
            </div>

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
        </div>
    </div>
@else
    {{-- Signed out: no chrome. There is nowhere to navigate to. --}}
    <main style="max-width: 420px; margin: 0 auto; padding: 0 20px;">
        @if (session('status'))
            <div class="flash" style="margin-top: 60px;">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="errors" style="margin-top: 60px;">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        @yield('content')
    </main>
@endauth

</body>
</html>
