@extends('platform.layout')
@section('title', $tenant->name)

@section('content')
    <h1>{{ $tenant->name }}</h1>
    <p class="sub">
        <code>{{ $tenant->getKey() }}</code> ·
        <span class="pill {{ $tenant->status }}">{{ $tenant->status }}</span>
        @if ($tenant->suspended_at) · suspended {{ $tenant->suspended_at->toDayDateTimeString() }} @endif
        @if ($tenant->archived_at) · archived {{ $tenant->archived_at->toDayDateTimeString() }} @endif
    </p>

    <h2>Health</h2>

    @if (! ($health['reachable'] ?? false))
        <div class="errors">
            <strong>Not reachable.</strong> {{ $health['reason'] ?? 'Unknown.' }}
        </div>
    @else
        {{--
          Counts and sizes only. Reading an association's members, payments or
          ledger needs a break-glass grant (FR-SEC-6), which is not built - so
          this page can tell you an association has 315 members and cannot tell
          you who any of them are.
        --}}
        <div class="grid">
            <div class="stat"><div class="k">Members</div><div class="v">{{ number_format($health['members']) }}</div></div>
            <div class="stat"><div class="k">Staff accounts</div><div class="v">{{ number_format($health['staff_accounts']) }}</div></div>
            <div class="stat"><div class="k">Completed payments</div><div class="v">{{ number_format($health['completed_payments']) }}</div></div>
            <div class="stat"><div class="k">Pending payments</div><div class="v">{{ number_format($health['pending_payments']) }}</div></div>
            <div class="stat"><div class="k">Failed jobs</div><div class="v">{{ number_format($health['failed_jobs']) }}</div></div>
            <div class="stat"><div class="k">Database</div><div class="v">{{ $health['database_size_mb'] }} MB</div></div>
        </div>

        <p class="muted" style="margin-top: 10px;">
            {{-- The accrual is the job whose silent failure costs members money. --}}
            Last fine accrual:
            <strong>{{ $health['last_fine_accrual'] ?? 'never' }}</strong>.
            @if (empty($health['last_fine_accrual']))
                Nothing has accrued yet — if this association is live, that is worth checking.
            @endif
        </p>
    @endif

    @if ($snapshot)
        <p class="muted">
            Snapshot figures (collected {{ $snapshot->collected_at->diffForHumans() }}):
            outstanding instalments {{ number_format((float) $snapshot->outstanding_instalments, 2) }},
            outstanding fines {{ number_format((float) $snapshot->outstanding_fines, 2) }}.
        </p>
    @endif

    <h2>Lifecycle</h2>

    <div class="panel">
        {{--
          The one thing an operator most needs to know before pressing
          anything here, stated where the buttons are rather than in a manual.
        --}}
        <div class="note">
            <strong>Nothing here deletes data.</strong>
            Suspending locks the association out immediately and keeps every record.
            Archiving closes it and keeps every record too — an association's ledger is a
            financial record with a retention period measured in years. Dropping a database
            is a separate, deliberate act at the server, with a backup in hand.
        </div>

        <form method="POST" action="{{ route('platform.tenant.transition', $tenant->getKey()) }}">
            @csrf

            <label for="action">Action</label>
            <select id="action" name="action" required>
                @if ($tenant->status !== 'suspended')
                    <option value="suspend">Suspend — lock everybody out, keep everything</option>
                @endif
                @if ($tenant->status !== 'active')
                    <option value="reinstate">Reinstate — let them back in</option>
                @endif
                @if ($tenant->status !== 'archived')
                    <option value="archive">Archive — close the association, keep the records</option>
                @endif
            </select>

            <label for="reason">Reason</label>
            <input id="reason" name="reason" type="text" required
                   placeholder="Why — the association may ask, and this is the answer">

            <label for="confirm">Type <code>{{ $tenant->getKey() }}</code> to confirm</label>
            <input id="confirm" name="confirm" type="text" required autocomplete="off"
                   placeholder="{{ $tenant->getKey() }}">

            <div class="row" style="margin-top: 14px;">
                <button type="submit" class="danger">Apply</button>
                <span class="muted">Recorded against your account in the audit log.</span>
            </div>
        </form>
    </div>

    <h2>Migrations</h2>

    <div class="panel">
        <form method="POST" action="{{ route('platform.tenant.migrate', $tenant->getKey()) }}">
            @csrf
            <div class="row">
                <button type="submit" class="primary">Run migrations</button>
                <span class="muted">
                    Runs pending tenant migrations for this association only. The output is
                    kept below, because "did it run, and what did it say" gets asked days later.
                </span>
            </div>
        </form>

        @if ($runs->isNotEmpty())
            <table style="margin-top: 14px;">
                <thead>
                <tr><th>When</th><th>Command</th><th>Status</th><th>Output</th></tr>
                </thead>
                <tbody>
                @foreach ($runs as $run)
                    <tr>
                        <td>{{ $run->started_at?->toDayDateTimeString() }}</td>
                        <td><code>{{ $run->command }}</code></td>
                        <td>{{ $run->status }}</td>
                        <td style="max-width: 420px;">
                            @if ($run->error)
                                <span style="color: var(--danger);">{{ Str::limit($run->error, 200) }}</span>
                            @else
                                <span class="muted">{{ Str::limit(trim((string) $run->output) ?: '—', 200) }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <h2>What has been done to this association</h2>

    @if ($audit->isEmpty())
        <div class="panel muted">Nothing recorded yet.</div>
    @else
        <table>
            <thead>
            <tr><th>When</th><th>Action</th><th>By</th><th>From</th><th>Reason</th></tr>
            </thead>
            <tbody>
            @foreach ($audit as $entry)
                <tr>
                    <td>{{ $entry->created_at?->toDayDateTimeString() }}</td>
                    <td><code>{{ $entry->action }}</code></td>
                    <td>{{ $entry->operator_email ?? 'system' }}</td>
                    <td>{{ $entry->source }}</td>
                    <td>{{ $entry->reason ?? '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
@endsection
