@extends('platform.layout')
@section('title', 'Sign in')

@section('content')
    <div style="max-width: 380px; margin: 60px auto;">
        <h1>Platform console</h1>
        <p class="sub">Operator access. Not an association account.</p>

        {{--
          The console refuses an operator who has not enrolled, so there is no
          "set it up later" state to explain here - only where to go if you are
          the one being refused.
        --}}
        <p class="muted" style="font-size: 12px;">
            A second factor is required. If you have not enrolled, run
            <code>php artisan operator:mfa &lt;your email&gt;</code> at the server.
        </p>

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
