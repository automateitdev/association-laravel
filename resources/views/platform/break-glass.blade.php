{{--
  The grant queue and the history (FR-SEC-6).

  THE HISTORY IS THE POINT, not the queue. A pending request is a colleague
  waiting five minutes; the finished grants below are the standing record that
  associations' records were read, by whom, why, and how many pages they
  actually opened. Nothing on this page prunes, filters by date or defaults to
  hiding anything.

  The read count is shown next to every grant for the same reason. "Approved for
  an hour" and "approved for an hour and used four hundred times" are different
  events, and only one of them looks like somebody answering a support ticket.
--}}
@extends('platform.layout')

@section('title', 'Break-glass')

@section('content')
    <h1>Break-glass</h1>
    <p class="sub">
        Time-boxed, read-only access into one association's records. A second operator has to
        approve it, the association's superadmins are told before it opens anything, and it
        expires on the clock.
    </p>

    @if ($operators < 2)
        <div class="note">
            <strong>There is only one active operator, so no grant can ever be approved.</strong>
            A grant cannot be approved by the person who asked for it — that is the only reason
            the approval step exists. Create a second operator at the server with
            <code>php artisan operator:create</code> before this feature can be used at all.
        </div>
    @endif

    <h2>Awaiting approval</h2>

    @if ($pending->isEmpty())
        <div class="panel muted">Nothing is waiting.</div>
    @else
        @foreach ($pending as $grant)
            <div class="panel" style="margin-bottom: 12px;">
                <div class="row" style="justify-content: space-between;">
                    <div>
                        <strong>{{ $grant->tenant?->name ?? $grant->tenant_id }}</strong>
                        <span class="muted">· {{ $grant->scope }} · {{ $grant->duration_minutes }} minutes</span>
                    </div>
                    <span class="muted">asked by {{ $grant->requested_by_email }}, {{ $grant->created_at?->diffForHumans() }}</span>
                </div>

                <p style="margin: 10px 0;">{{ $grant->reason }}</p>

                @if ($grant->requested_by === auth('operator')->id())
                    {{--
                      Said here rather than only on the refusal. Somebody looking
                      at their own request should not have to click to find out
                      why there is no button.
                    --}}
                    <p class="muted" style="margin: 0;">
                        You asked for this one, so you cannot approve it. Another operator has to.
                    </p>
                @else
                    <form method="POST" action="{{ route('platform.break-glass.decide', $grant) }}">
                        @csrf
                        <label for="note-{{ $grant->id }}">Note (required to refuse)</label>
                        <input type="text" id="note-{{ $grant->id }}" name="note" maxlength="500">

                        <div class="row" style="margin-top: 10px;">
                            <button type="submit" name="decision" value="approve" class="primary">
                                Approve, notify and open
                            </button>
                            <button type="submit" name="decision" value="deny">Refuse</button>
                        </div>
                    </form>
                @endif
            </div>
        @endforeach
    @endif

    <h2>Every grant</h2>

    @if ($grants->isEmpty())
        <div class="panel muted">No association's records have been read through the console.</div>
    @else
        <table>
            <thead>
            <tr>
                <th>Association</th>
                <th>Area</th>
                <th>Asked by</th>
                <th>Decided by</th>
                <th>State</th>
                <th class="num">Reads</th>
                <th>Ends</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            @foreach ($grants as $grant)
                <tr>
                    <td>
                        <a href="{{ route('platform.tenant', $grant->tenant_id) }}">{{ $grant->tenant_id }}</a>
                        <div class="muted" style="font-size: 12px;">{{ $grant->reason }}</div>
                    </td>
                    <td>{{ $grant->scope }}</td>
                    <td>{{ $grant->requested_by_email }}</td>
                    <td>
                        {{ $grant->decided_by_email ?? '—' }}
                        @if ($grant->decision_note)
                            <div class="muted" style="font-size: 12px;">{{ $grant->decision_note }}</div>
                        @endif
                    </td>
                    <td>
                        <span class="pill {{ $grant->isLive() ? 'active' : ($grant->status === 'denied' || $grant->status === 'revoked' ? 'suspended' : '') }}">
                            {{ $grant->state() }}
                        </span>
                        @if ($grant->awaitingNotification())
                            <div class="muted" style="font-size: 12px;">{{ $grant->notify_error }}</div>
                        @endif
                    </td>
                    <td class="num">{{ $grant->reads }}</td>
                    <td>
                        {{ $grant->expires_at?->toDayDateTimeString() ?? '—' }}
                        @if ($grant->last_read_at)
                            <div class="muted" style="font-size: 12px;">last read {{ $grant->last_read_at->diffForHumans() }}</div>
                        @endif
                    </td>
                    <td>
                        @if ($grant->awaitingNotification())
                            <form method="POST" action="{{ route('platform.break-glass.renotify', $grant) }}">
                                @csrf
                                <button type="submit">Notify again</button>
                            </form>
                        @elseif ($grant->isLive())
                            <form method="POST" action="{{ route('platform.break-glass.revoke', $grant) }}" class="row">
                                @csrf
                                <input type="text" name="note" placeholder="Why it is ending" required minlength="5" style="width: 160px;">
                                <button type="submit">End now</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div style="margin-top: 14px;">{{ $grants->links() }}</div>
    @endif
@endsection
