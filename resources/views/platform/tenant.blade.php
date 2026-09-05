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

    @if (session('setup_token'))
        {{--
          Shown ONCE, immediately after provisioning, and never again: only its
          hash reaches the database, and it is deliberately absent from the
          audit log. It is not a password - it lets the association's first
          administrator choose one that nobody else, including us, has seen.
        --}}
        <div class="note">
            <strong>Setup token for {{ session('setup_email') }} — copy it now.</strong>
            <p style="margin: 8px 0;">
                <code style="font-size: 13px; word-break: break-all;">{{ session('setup_token') }}</code>
            </p>
            <p class="muted" style="margin: 0; font-size: 12px;">
                This is the only time it is shown. It is not a password: it lets them set one.
                If it is lost, no one can recover it — issue a new administrator instead.
            </p>
        </div>
    @endif

    <h2>Health</h2>

    @if (! ($health['reachable'] ?? false))
        <div class="errors">
            <strong>Not reachable.</strong> {{ $health['reason'] ?? 'Unknown.' }}
        </div>
    @else
        {{--
          Counts and sizes only. This page can tell you an association has 315
          members and cannot tell you who any of them are — reading a row needs
          a break-glass grant (FR-SEC-6), which is the section below.
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

    {{--
      Break-glass (FR-SEC-6).

      Placed under the health figures on purpose: this is where somebody arrives
      wanting a row and finds only counts, and it is the moment to say what the
      route to a row actually is rather than leaving them to go looking for one.
    --}}
    <h2>Reading this association's records</h2>

    @php($liveGrants = \App\Models\BreakGlassGrant::where('tenant_id', $tenant->getKey())
        ->where('requested_by', auth('operator')->id())
        ->live()->get()->keyBy('scope'))

    <div class="panel">
        @if ($liveGrants->isNotEmpty())
            <p style="margin-top: 0;">
                <strong>You have live access.</strong> Every page you open is recorded, and
                {{ $tenant->getKey() }}'s superadmins have already been told.
            </p>
            <div class="row">
                @foreach ($liveGrants as $scope => $grant)
                    <a href="{{ route('platform.tenant.data.'.$scope, $tenant->getKey()) }}">
                        Read {{ $scope }}
                    </a>
                    <span class="muted">(ends {{ $grant->expires_at->diffForHumans() }})</span>
                @endforeach
            </div>
            <hr style="border: 0; border-top: 1px solid var(--line); margin: 16px 0;">
        @endif

        <p style="margin-top: 0;" class="muted">
            Read-only, time-boxed, and approved by a second operator before it opens anything.
            The association's superadmins are emailed and the grant is written into their own
            audit log — they will know, whether or not anybody tells them.
        </p>

        <form method="POST" action="{{ route('platform.break-glass.request', $tenant->getKey()) }}">
            @csrf

            <div class="row" style="align-items: flex-end;">
                <div style="flex: 0 0 180px;">
                    <label for="scope">Area</label>
                    <select id="scope" name="scope">
                        <option value="members">Members</option>
                        <option value="payments">Payments</option>
                    </select>
                </div>

                <div style="flex: 0 0 180px;">
                    <label for="minutes">For how long</label>
                    <select id="minutes" name="minutes">
                        @foreach (\App\Models\BreakGlassGrant::DURATIONS as $minutes)
                            <option value="{{ $minutes }}" @selected($minutes === 30)>{{ $minutes }} minutes</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <label for="reason">Why — the association reads this, word for word</label>
            <input type="text" id="reason" name="reason" minlength="20" maxlength="500" required
                   value="{{ old('reason') }}"
                   placeholder="e.g. Invoice INV-2026-0912 shows completed but the member says no receipt arrived">

            <div style="margin-top: 12px;">
                <button type="submit" class="primary">Request access</button>
            </div>
        </form>
    </div>

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
