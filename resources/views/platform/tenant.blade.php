@extends('platform.layout')
@section('title', $tenant->name)

@section('crumb')
    <a href="{{ route('platform.index') }}">Associations</a>
    <span class="muted"> / </span>{{ $tenant->getKey() }}
@endsection

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

    {{--
      What is still missing, before anything else on the page.

      Provisioning succeeding and the association WORKING are different things,
      and the gap between them used to be discovered by an officer three weeks
      later. Blocking items are separated from advisory ones on purpose: an
      association with no fee heads yet is fine, one nobody can administer is
      not, and a single undifferentiated list of warnings is how the second ends
      up sitting underneath the first.
    --}}
    <h2>Setup</h2>

    @if ($readiness['ready'])
        <div class="flash">
            <strong>Ready.</strong> Nothing is blocking this association from being used.
        </div>
    @else
        <div class="errors">
            <strong>{{ $readiness['blocking'] }} thing{{ $readiness['blocking'] === 1 ? '' : 's' }}
            stop{{ $readiness['blocking'] === 1 ? 's' : '' }} this association working.</strong>
        </div>
    @endif

    <table>
        <tbody>
        @foreach ($readiness['checks'] as $check)
            <tr>
                <td style="width: 30px;">
                    @if ($check['ok'])
                        <span style="color: var(--ok);">&check;</span>
                    @else
                        <span style="color: {{ $check['blocking'] ? 'var(--danger)' : 'var(--warn)' }};">&times;</span>
                    @endif
                </td>
                <td>
                    {{ $check['label'] }}
                    @if (! $check['ok'] && ! $check['blocking'])
                        <span class="muted">(advisory)</span>
                    @endif
                    <div class="muted" style="font-size: 12px;">{{ $check['detail'] }}</div>

                    {{-- The fix, on the row, so "what now" is not a second question. --}}
                    @if (! $check['ok'])
                        <div style="font-size: 12px; margin-top: 2px;">{{ $check['fix'] }}</div>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

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
      The payment gateway (FR-PAY-11).

      An operator surface, not the association's. `ar_account` is where their
      money lands, and it used to be editable by anybody in the association
      holding `settings.edit` - a permission given to treasurers for editing
      fine rates.

      NOTHING IS PRE-FILLED, and there is no "edit" that keeps the fields you
      are not changing. The credentials are write-only: no part of this
      application reads a merchant password back out, and a form that rendered
      one into an HTML input would have undone the reason this moved. Retyping
      eight fields is the correct cost for an action this rare.
    --}}
    <h2>Payment gateway</h2>

    <div class="panel">
        @if ($gateway['unreachable'] ?? false)
            {{-- Not "no gateway": saying that about a database nobody can
                 reach invites somebody to configure a second one. --}}
            <div class="errors">
                <strong>Cannot tell.</strong> This association's database did not answer, so
                whether a gateway is configured is unknown. Fix the database first.
            </div>
        @elseif ($gateway)
            <div class="row" style="justify-content: space-between; margin-bottom: 12px;">
                <div>
                    <strong>{{ $gateway['label'] ?? $gateway['provider'] }}</strong>
                    <span class="pill {{ $gateway['is_active'] ? 'active' : 'suspended' }}">
                        {{ $gateway['is_active'] ? 'active' : 'switched off' }}
                    </span>
                    <div class="muted" style="font-size: 12px;">
                        Money lands in an account ending <strong>{{ $gateway['ar_account_last4'] }}</strong> ·
                        set {{ $gateway['updated_at'] }}
                    </div>
                </div>

                <form method="POST" action="{{ route('platform.tenant.gateway.toggle', $tenant->getKey()) }}">
                    @csrf
                    <input type="hidden" name="action" value="{{ $gateway['is_active'] ? 'disable' : 'enable' }}">
                    <button type="submit">
                        {{ $gateway['is_active'] ? 'Stop taking online payment' : 'Resume online payment' }}
                    </button>
                </form>
            </div>

            <div class="note">
                Only the last four digits are ever shown, here or anywhere else. Replacing the
                configuration below <strong>overwrites every field</strong> — there is nothing to
                read back, so a partial edit is not possible.
            </div>
        @else
            <div class="note" style="margin-bottom: 12px;">
                <strong>No gateway configured.</strong> Online payment cannot be taken. Counter
                collection and bank transfer still work, so this is not urgent unless the
                association expects to take card payments.
            </div>
        @endif

        {{--
          ONE FORM PER PROVIDER, rather than one form and a JavaScript switch.

          The two routes to Sonali take different fields, so a single form would
          have to mark everything optional and sort it out on the server - which
          means the browser stops catching a missed field, and the first thing
          that notices is a validation error after eight values were typed. Each
          block carries its own `required` attributes and its own hidden
          provider, and the page still has no JavaScript in it.
        --}}
        @foreach (\App\Services\GatewayConfigurator::PROVIDERS as $key => $meta)
            <details style="margin-top: 12px;" @if (($gateway['provider'] ?? 'spg') === $key) open @endif>
                <summary style="cursor: pointer; font-weight: 600;">
                    {{ $meta['label'] }}
                    @if (($gateway['provider'] ?? null) === $key)
                        <span class="pill active">in use</span>
                    @endif
                </summary>

                <p class="muted" style="margin: 8px 0 4px;">{{ $meta['blurb'] }}</p>

                <form method="POST" action="{{ route('platform.tenant.gateway', $tenant->getKey()) }}"
                      autocomplete="off">
                    @csrf
                    <input type="hidden" name="provider" value="{{ $key }}">

                    @foreach ($meta['fields'] as $field => $spec)
                        <label for="{{ $key }}-{{ $field }}">
                            {{ $spec['label'] }}
                            @if ($spec['optional'] ?? false)
                                <span class="muted">(optional)</span>
                            @endif
                        </label>
                        @isset($spec['help'])
                            <div class="muted" style="font-size: 12px; margin-bottom: 4px;">{{ $spec['help'] }}</div>
                        @endisset
                        {{--
                          `type=password` on the secrets so a shoulder cannot read
                          them, and autocomplete off throughout: a browser offering
                          to save a merchant password is a copy of it nobody
                          decided to make.
                        --}}
                        <input type="{{ $spec['secret'] ? 'password' : 'text' }}"
                               id="{{ $key }}-{{ $field }}" name="{{ $field }}"
                               @required(! ($spec['optional'] ?? false))
                               autocomplete="off" spellcheck="false">
                    @endforeach

                    {{--
                      Re-typed, not confirmed with a checkbox. This is the one
                      field where a typo does not fail loudly: it succeeds, and
                      the money goes somewhere else.
                    --}}
                    <label for="{{ $key }}-confirm">Type the AR account again</label>
                    <input type="text" id="{{ $key }}-confirm" name="ar_account_confirm" required
                           autocomplete="off" spellcheck="false">

                    <div class="row" style="margin-top: 14px;">
                        <button type="submit" class="primary">
                            @if (($gateway['provider'] ?? null) === $key)
                                Replace this configuration
                            @elseif ($gateway)
                                Switch to {{ $meta['label'] }}
                            @else
                                Configure {{ $meta['label'] }}
                            @endif
                        </button>
                        <span class="muted">
                            Recorded in the audit log — field names and the last four digits, never
                            values — and in the association's own log.
                        </span>
                    </div>

                    @if ($gateway && ($gateway['provider'] ?? null) !== $key)
                        <p class="muted" style="font-size: 12px; margin-bottom: 0;">
                            {{--
                              An association has exactly one active gateway. Two
                              would make "which one is this association using"
                              depend on row order, which is how money ends up
                              collected through the provider somebody thought
                              they had left.
                            --}}
                            Switching here turns the current gateway off. Its credentials are kept,
                            so switching back is a click rather than eight fields again.
                        </p>
                    @endif
                </form>
            </details>
        @endforeach

        <p class="muted" style="margin-bottom: 0; margin-top: 12px;">
            The same thing at the server: <code>php artisan tenant:gateway {{ $tenant->getKey() }}</code>.
            Both go through the same configurator, so they cannot behave differently.
        </p>
    </div>

    {{--
      Break-glass (FR-SEC-6).

      Placed after the health figures on purpose: this is where somebody arrives
      wanting a row and finds only counts, and it is the moment to say what the
      route to a row actually is rather than leaving them to go looking.
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
                {{--
                  Disabled when the database did not answer. Migrating an
                  unreachable tenant fails in a way whose message is about
                  connections, not about migrations, and sends the reader off
                  chasing the wrong thing.
                --}}
                <button type="submit" class="primary" @disabled(! ($health['reachable'] ?? false))>
                    Run migrations
                </button>
                <span class="muted">
                    @if ($health['reachable'] ?? false)
                        Runs pending tenant migrations for this association only. The output is
                        kept below, because "did it run, and what did it say" gets asked days later.
                    @else
                        The database did not answer, so there is nothing to migrate yet. Fix the
                        connection first — see Health above.
                    @endif
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
                        <td><span class="pill {{ $run->status === 'failed' ? 'suspended' : ($run->status === 'succeeded' ? 'active' : '') }}">{{ $run->status }}</span></td>
                        <td style="max-width: 520px;">
                            {{--
                              NOT truncated. This used to cut at 200 characters,
                              which is reliably before the part of a migration
                              error that says what actually went wrong - the SQL
                              state and the offending column are at the end of
                              the message, after the stack of framework context.
                              Scrolls instead.
                            --}}
                            @if ($run->error)
                                <pre style="max-height: 180px; white-space: pre-wrap;">{{ $run->error }}</pre>
                            @elseif (trim((string) $run->output) !== '')
                                <pre style="max-height: 180px; white-space: pre-wrap;">{{ trim($run->output) }}</pre>
                            @else
                                <span class="muted">no output</span>
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
