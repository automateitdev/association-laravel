@extends('platform.layout')
@section('title', 'Sign in')

@section('content')
    <div style="max-width: 380px; margin: 60px auto;">
        <h1>Platform console</h1>
        <p class="sub">Operator access. Not an association account.</p>

        {{--
          Stated on the login page, not only in the docs. An operator can
          suspend an association and reach the controls that decide where its
          payments land; NFR-SEC-5 requires MFA for exactly that reason and it
          is not built. Whoever signs in should know what is protecting this.
        --}}
        <div class="note">
            <strong>No second factor yet.</strong> This is a password login.
            NFR-SEC-5 requires MFA on operator accounts and it is not built —
            treat these credentials accordingly.
        </div>

        <form method="POST" action="{{ route('platform.login.store') }}" class="panel">
            @csrf

            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" required>

            <div class="row" style="margin-top: 16px;">
                <button type="submit" class="primary">Sign in</button>
                <label style="margin: 0; display: flex; gap: 6px; align-items: center;">
                    <input type="checkbox" name="remember" value="1"> Remember
                </label>
            </div>
        </form>
    </div>
@endsection
