@extends('platform.layout')
@section('title', 'Second factor')

@section('content')
    <div style="margin: 72px 0;">
        {{-- Same wordless brand as the sign-in step: one flow, two pages. --}}
        <div class="row" style="gap: 10px; margin-bottom: 18px;">
            <span style="width: 30px; height: 30px; border-radius: 8px; background: var(--accent);
                         color: #fff; display: grid; place-items: center; font-weight: 700;">B</span>
            <strong style="font-size: 15px;">BCS Platform</strong>
        </div>

        <h1>Second factor</h1>
        <p class="sub">Your password was accepted. One more step.</p>

        <form method="POST" action="{{ route('platform.challenge.verify') }}" class="panel">
            @csrf

            <label for="code">Code from your authenticator</label>
            {{--
              inputmode numeric but type text: a recovery code goes in the same
              box, and type=number would refuse the hyphen and letters.
            --}}
            <input id="code" name="code" type="text" inputmode="numeric"
                   autocomplete="one-time-code" required autofocus
                   placeholder="123456" autocapitalize="characters">

            <p class="muted" style="font-size: 12px; margin: 6px 0 0;">
                Lost your phone? Enter one of your recovery codes here instead — each works once.
            </p>

            <div class="row" style="margin-top: 16px;">
                <button type="submit" class="primary">Continue</button>
                <a href="{{ route('platform.login') }}">Start again</a>
            </div>
        </form>

        <p class="muted" style="font-size: 12px; margin-top: 16px;">
            This sign-in expires in five minutes. A half-finished one is a password already
            proved, so it is not left lying around.
        </p>
    </div>
@endsection
