@extends('platform.layout')
@section('title', 'Associations')

@section('content')
    <h1>Associations</h1>
    <p class="sub">
        {{ $byStatus->sum() }} on this platform ·
        @foreach ($byStatus as $state => $count)
            {{ $count }} {{ $state }}@if (! $loop->last), @endif
        @endforeach
    </p>

    {{--
      The one line an operator should not have to go looking for. An association
      can be "active" and still unable to take a payment, so status alone does
      not answer "is anything wrong" - and a console that makes you open forty
      pages to find out is one nobody opens at all.
    --}}
    @if ($needAttention > 0)
        <div class="note">
            <strong>{{ $needAttention }} association{{ $needAttention === 1 ? '' : 's' }}
            need{{ $needAttention === 1 ? 's' : '' }} attention</strong> — unreachable, or not
            finished being set up.
            <a href="{{ route('platform.index', ['status' => 'attention']) }}">Show them</a>.
        </div>
    @endif

    <h2>Platform totals</h2>

    {{--
      Every figure here is a sum of `tenant_snapshots`, never a query across
      tenant databases (FR-PLT-5). So it is as fresh as the last collection,
      which is why the date is printed next to it rather than left implied.
    --}}
    <div class="grid">
        <div class="stat"><div class="k">Members</div><div class="v">{{ number_format($totals['members']) }}</div></div>
        <div class="stat"><div class="k">Active members</div><div class="v">{{ number_format($totals['active_members']) }}</div></div>
        <div class="stat"><div class="k">Completed payments</div><div class="v">{{ number_format($totals['completed_payments']) }}</div></div>

        {{-- Instalments and fines never combined into one "collected". ADR-0005. --}}
        <div class="stat"><div class="k">Collected · instalments</div><div class="v">{{ number_format((float) $totals['collected_instalments'], 2) }}</div></div>
        <div class="stat"><div class="k">Collected · fines</div><div class="v">{{ number_format((float) $totals['collected_fines'], 2) }}</div></div>
        <div class="stat"><div class="k">Databases</div><div class="v">{{ $totals['database_size_mb'] }} MB</div></div>
    </div>

    <p class="muted" style="margin-top: 10px;">
        @if ($collectedAt)
            Collected {{ $collectedAt->diffForHumans() }} ({{ $collectedAt->toDayDateTimeString() }}).
            These are snapshot figures, not live — run <code>php artisan platform:snapshot</code> to refresh.
        @else
            No figures collected yet. Run <code>php artisan platform:snapshot</code>.
        @endif
    </p>

    <h2>Every association</h2>

    {{--
      GET, so a filtered view is a URL somebody can send to a colleague mid
      incident. Everything here is a plain form: no JavaScript, because this is
      a table and four controls.
    --}}
    <form method="GET" class="row" style="margin-bottom: 14px;">
        <input type="text" name="q" value="{{ $search }}" placeholder="Name or id"
               style="max-width: 260px;">

        <select name="status" style="width: auto;">
            <option value="">Any status</option>
            <option value="attention" @selected($status === 'attention')>Needs attention</option>
            @foreach (['active', 'suspended', 'archived', 'provisioning'] as $option)
                <option value="{{ $option }}" @selected($status === $option)>{{ ucfirst($option) }}</option>
            @endforeach
        </select>

        <select name="sort" style="width: auto;">
            <option value="id" @selected($sort === 'id')>Sort by id</option>
            <option value="name" @selected($sort === 'name')>Sort by name</option>
            <option value="members" @selected($sort === 'members')>Most members</option>
            <option value="size" @selected($sort === 'size')>Largest database</option>
        </select>

        <button type="submit">Apply</button>

        @if ($search !== '' || $status !== '' || $sort !== 'id')
            <a href="{{ route('platform.index') }}">Clear</a>
            <span class="muted">{{ $tenants->count() }} shown</span>
        @endif
    </form>

    <table>
        <thead>
        <tr>
            <th>Association</th>
            <th>Status</th>
            <th class="num">Members</th>
            <th class="num">Completed</th>
            <th class="num">Size</th>
            <th>Setup</th>
            <th>Onboarded</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($tenants as $tenant)
            @php($snapshot = $snapshots->get($tenant->getKey()))
            <tr>
                <td>
                    <a href="{{ route('platform.tenant', $tenant->getKey()) }}">{{ $tenant->name }}</a>
                    <div class="muted" style="font-size: 12px;">{{ $tenant->getKey() }}</div>
                </td>
                <td><span class="pill {{ $tenant->status }}">{{ $tenant->status }}</span></td>
                <td class="num">{{ $snapshot ? number_format($snapshot->members) : '—' }}</td>
                <td class="num">{{ $snapshot ? number_format($snapshot->completed_payments) : '—' }}</td>
                <td class="num">{{ $snapshot ? $snapshot->database_size_mb.' MB' : '—' }}</td>
                <td>
                    {{--
                      From the snapshot, not asked live: one connection swap per
                      row would make this list cost forty database connections
                      to render. Named rather than shown as a bare count, since
                      "no administrator" and "no fee heads" are not the same
                      news.
                    --}}
                    @if (! $snapshot)
                        <span class="muted">not collected</span>
                    @elseif ($snapshot->error)
                        <span class="pill suspended">unreachable</span>
                    @elseif ($snapshot->blocking_issues > 0)
                        <span class="pill suspended">{{ implode(', ', array_slice($snapshot->unmetChecks(), 0, 2)) }}</span>
                    @elseif ($unmet = $snapshot->unmetChecks())
                        <span class="muted">{{ implode(', ', array_slice($unmet, 0, 2)) }}</span>
                    @else
                        <span class="pill active">ready</span>
                    @endif
                </td>
                <td>{{ $tenant->onboarded_at?->toDateString() ?? '—' }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="7" class="muted">
                    @if ($search !== '' || $status !== '')
                        Nothing matches that.
                        <a href="{{ route('platform.index') }}">Clear the filters</a>.
                    @else
                        No associations yet. Add one below.
                    @endif
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>

    <h2>Adding one</h2>
    <div class="panel">
        <div class="row">
            <a href="{{ route('platform.tenant.create') }}">
                <button type="button" class="primary">New association</button>
            </a>
            <span class="muted">
                Creates a database, a scoped database user, the schema and the first
                administrator — all-or-nothing, rolled back entirely if any step fails.
            </span>
        </div>

        <p class="muted" style="margin-bottom: 0; margin-top: 12px;">
            The same thing at the server:
            <code>php artisan tenant:provision &lt;id&gt;</code>. Both call the same
            provisioner, so they cannot behave differently.
        </p>
    </div>
@endsection
