@extends('platform.layout')
@section('title', 'Associations')

@section('content')
    <h1>Associations</h1>
    <p class="sub">
        {{ $tenants->count() }} on this platform ·
        @foreach ($byStatus as $status => $count)
            {{ $count }} {{ $status }}@if (! $loop->last), @endif
        @endforeach
    </p>

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

    <table>
        <thead>
        <tr>
            <th>Association</th>
            <th>Status</th>
            <th class="num">Members</th>
            <th class="num">Completed</th>
            <th class="num">Size</th>
            <th>Onboarded</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($tenants as $tenant)
            @php($snapshot = $snapshots[$tenant->getKey()] ?? null)
            <tr>
                <td>
                    <a href="{{ route('platform.tenant', $tenant->getKey()) }}">{{ $tenant->name }}</a>
                    <div class="muted" style="font-size: 12px;">{{ $tenant->getKey() }}</div>
                </td>
                <td><span class="pill {{ $tenant->status }}">{{ $tenant->status }}</span></td>
                <td class="num">{{ $snapshot ? number_format($snapshot->members) : '—' }}</td>
                <td class="num">{{ $snapshot ? number_format($snapshot->completed_payments) : '—' }}</td>
                <td class="num">{{ $snapshot ? $snapshot->database_size_mb.' MB' : '—' }}</td>
                <td>{{ $tenant->onboarded_at?->toDateString() ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No associations yet. Create one with <code>php artisan tenant:provision &lt;slug&gt;</code>.</td></tr>
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
