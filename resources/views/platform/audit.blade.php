@extends('platform.layout')
@section('title', 'Audit')

@section('content')
    <h1>Operator audit</h1>
    <p class="sub">Every action taken by an operator, from the console or the server (FR-PLT-4).</p>

    {{--
      The limit of this log, said where it is read. An append-only intent is
      not the same as a tamper-proof record, and an audit log people trust
      further than it deserves is worse than one whose limits are known.
    --}}
    <div class="note">
        <strong>Append-only by intent, not by enforcement.</strong>
        There is no code path that edits an entry and no screen that offers it — but anyone
        with database access could. A log that resists tampering needs signing or shipping
        off-box, and neither is built.
    </div>

    <form method="GET" class="panel" style="margin-bottom: 16px;">
        <div class="row">
            <div style="flex: 1; min-width: 200px;">
                <label for="tenant">Association</label>
                <input id="tenant" name="tenant" type="text" value="{{ request('tenant') }}"
                       placeholder="Any">
            </div>
            <div style="flex: 1; min-width: 200px;">
                <label for="action">Action</label>
                <select id="action" name="action">
                    <option value="">Any</option>
                    @foreach ($actions as $action)
                        <option value="{{ $action }}" @selected(request('action') === $action)>{{ $action }}</option>
                    @endforeach
                </select>
            </div>
            <div style="align-self: flex-end;">
                <button type="submit">Filter</button>
            </div>
        </div>
    </form>

    <table>
        <thead>
        <tr>
            <th>When</th>
            <th>Action</th>
            <th>Association</th>
            <th>By</th>
            <th>From</th>
            <th>Reason</th>
        </tr>
        </thead>
        <tbody>
        @forelse ($entries as $entry)
            <tr>
                <td>{{ $entry->created_at?->toDayDateTimeString() }}</td>
                <td><code>{{ $entry->action }}</code></td>
                <td>
                    @if ($entry->tenant_id)
                        <a href="{{ route('platform.tenant', $entry->tenant_id) }}">{{ $entry->tenant_id }}</a>
                    @else
                        <span class="muted">—</span>
                    @endif
                </td>
                {{-- The email is stored on the row, so it survives the account being removed. --}}
                <td>{{ $entry->operator_email ?? 'system' }}</td>
                <td>{{ $entry->source }}</td>
                <td>{{ $entry->reason ?? '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">Nothing recorded.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div style="margin-top: 16px;">
        {{ $entries->links() }}
    </div>
@endsection
