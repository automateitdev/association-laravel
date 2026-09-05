{{--
  An association's rows, under a live grant (FR-SEC-6).

  THE BANNER IS NOT DECORATION. Somebody reading a member list should be unable
  to forget whose it is, why they said they were looking, that the association
  has been told, and when it stops. The alternative is a page that looks exactly
  like every other admin table in the world, which is how a break-glass session
  turns into an afternoon.

  Read-only, and there is no form on this page at all.
--}}
@extends('platform.layout')

@section('title', 'Break-glass · '.$tenant->getKey())

@section('content')
    <h1>{{ $tenant->name }} · {{ $area }}</h1>

    <div class="note">
        <strong>You are reading {{ $tenant->getKey() }}'s records under a break-glass grant.</strong>
        <div style="margin-top: 6px;">
            Approved by {{ $grant->decided_by_email }}. Ends
            <strong>{{ $grant->expires_at->toDayDateTimeString() }}</strong>
            ({{ $grant->expires_at->diffForHumans() }}).
            {{-- Named, so nobody has to take on trust that the notice went out. --}}
            The association's superadmins were told at {{ $grant->notified_at->toDayDateTimeString() }}:
            {{ implode(', ', $grant->notified_to ?? []) }}.
        </div>
        <div style="margin-top: 6px;">
            Your stated reason: <em>{{ $grant->reason }}</em>
        </div>
        <div style="margin-top: 6px;" class="muted">
            This is page {{ $page }}, and opening it was recorded. Pages opened under this grant so
            far: {{ $grant->reads }}.
        </div>
    </div>

    <form method="GET" class="row" style="margin-bottom: 16px;">
        <input type="text" name="q" value="{{ $search }}"
               placeholder="{{ $area === 'members' ? 'Name, mobile or membership number' : 'Invoice, gateway reference or member' }}"
               style="max-width: 340px;">
        <button type="submit">Search</button>
        <span class="muted">{{ number_format($total) }} row{{ $total === 1 ? '' : 's' }}</span>
    </form>

    @if ($rows->isEmpty())
        <div class="panel muted">Nothing matches.</div>
    @else
        <table>
            @if ($area === 'members')
                <thead>
                <tr>
                    <th>#</th>
                    <th>Membership no</th>
                    <th>Name</th>
                    <th>Mobile</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Joined</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row->id }}</td>
                        <td>{{ $row->membership_no ?? '—' }}</td>
                        <td>{{ $row->name }}</td>
                        <td>{{ $row->mobile ?? '—' }}</td>
                        <td>{{ $row->email ?? '—' }}</td>
                        <td><span class="pill {{ $row->status }}">{{ $row->status }}</span></td>
                        <td>{{ $row->joining_date ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            @else
                <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Member</th>
                    <th>Status</th>
                    {{--
                      Instalment and fine in separate columns, never added
                      together into one "amount" (ADR-0005). A support answer
                      given from a merged figure is the same wrong answer the
                      legacy reports gave.
                    --}}
                    <th class="num">Instalment</th>
                    <th class="num">Fine</th>
                    <th class="num">Total</th>
                    <th>Ledger</th>
                    <th>Reference</th>
                    <th>Created</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row->invoice_no }}</td>
                        <td>{{ $row->member }}</td>
                        <td><span class="pill {{ $row->status === 'completed' ? 'active' : '' }}">{{ $row->status }}</span></td>
                        <td class="num">{{ $row->payable_amount }}</td>
                        <td class="num">{{ $row->fine_amount }}</td>
                        <td class="num">{{ $row->total_amount }}</td>
                        <td>{{ $row->ledger ?? '—' }}</td>
                        <td>{{ $row->gateway_reference ?? '—' }}</td>
                        <td>{{ $row->created_at }}</td>
                    </tr>
                @endforeach
                </tbody>
            @endif
        </table>

        <div class="row" style="margin-top: 14px;">
            @if ($page > 1)
                <a href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}">Previous</a>
            @endif
            <span class="muted">
                {{ ($page - 1) * $perPage + 1 }}–{{ min($page * $perPage, $total) }} of {{ number_format($total) }}
            </span>
            @if ($page * $perPage < $total)
                <a href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}">Next</a>
            @endif
        </div>
    @endif

    <p class="muted" style="margin-top: 20px;">
        {{--
          Stated where somebody would otherwise go looking for it, and then ask
          for a wider grant to find it.
        --}}
        National ID numbers, uploaded documents and signatures are never shown here, under any
        grant. They are the most sensitive records the system holds and no support question has
        needed one. Nothing on this page can be changed.
    </p>
@endsection
