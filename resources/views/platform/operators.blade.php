{{--
  Who can reach this console.

  READ-ONLY BY DESIGN. No create, no disable, no reset-their-MFA. A console that
  could mint its own users would make one stolen session permanent, and one that
  could reset a colleague's second factor would make it reassignable — both are
  what somebody who got this far would reach for next. The commands that do
  those things are at the server, where the same access that creates operators
  enrols them.

  What this page is for is knowing, before an incident rather than during one,
  that break-glass needs two operators and this deployment has one.
--}}
@extends('platform.layout')

@section('title', 'Operators')

@section('content')
    <h1>Operators</h1>
    <p class="sub">
        {{ $operators->count() }} account{{ $operators->count() === 1 ? '' : 's' }},
        {{ $usable }} able to sign in.
    </p>

    @if ($usable < 2)
        <div class="note">
            <strong>Break-glass cannot be used with fewer than two working operators.</strong>
            A grant cannot be approved by the operator who asked for it — that is the only
            reason the approval step exists. Until there are two, nobody can be granted access
            to an association's records through this console, whatever the emergency.
        </div>
    @endif

    <table>
        <thead>
        <tr>
            <th>Operator</th>
            <th>Second factor</th>
            <th>Last signed in</th>
            <th>From</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($operators as $operator)
            <tr>
                <td>
                    {{ $operator->email }}
                    <div class="muted" style="font-size: 12px;">{{ $operator->name }}</div>
                </td>
                <td>
                    @if (! $operator->is_active)
                        {{-- Disabled beats un-enrolled: they cannot sign in either way. --}}
                        <span class="pill suspended">disabled</span>
                    @elseif ($operator->mfa_confirmed_at)
                        <span class="pill active">enrolled</span>
                        <div class="muted" style="font-size: 12px;">
                            {{ $operator->mfa_confirmed_at->toDateString() }} ·
                            {{ count($operator->mfa_recovery_codes ?? []) }} recovery codes left
                        </div>
                    @else
                        <span class="pill suspended">not enrolled</span>
                        <div class="muted" style="font-size: 12px;">
                            Cannot sign in. The console refuses an operator without one.
                        </div>
                    @endif
                </td>
                <td>{{ $operator->last_login_at?->toDayDateTimeString() ?? 'never' }}</td>
                <td class="muted">{{ $operator->last_login_ip ?? '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <h2>Adding one</h2>

    <div class="panel">
        <p style="margin-top: 0;">
            At the server, not here:
        </p>
        <pre>php artisan operator:create
php artisan operator:mfa &lt;email&gt;</pre>
        <p class="muted" style="margin-bottom: 0;">
            Enrolment draws a QR code in the terminal — scan it rather than typing the key.
            Every run mints a <strong>new</strong> secret, so delete any earlier entry from the
            authenticator first or you will be reading codes from a dead one.
        </p>
    </div>
@endsection
