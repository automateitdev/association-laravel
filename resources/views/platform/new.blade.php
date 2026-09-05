@extends('platform.layout')
@section('title', 'New association')

@section('crumb')
    <a href="{{ route('platform.index') }}">Associations</a>
    <span class="muted"> / </span>New
@endsection

@section('content')
    <h1>New association</h1>
    <p class="sub">Creates a database, a scoped database user, the schema, and the first administrator.</p>

    {{--
      What is about to happen, before it happens. This is the one action in the
      console that creates infrastructure rather than changing a status, and an
      operator should know it is all-or-nothing before pressing anything.
    --}}
    <div class="note">
        <strong>All-or-nothing.</strong> If any step fails, the database, the database user, the
        domain and the registry row are all removed again — a half-made association would block
        the next attempt on the same id. The run and its outcome are recorded either way.
    </div>

    <form method="POST" action="{{ route('platform.tenant.store') }}" class="panel" style="max-width: 640px;">
        @csrf

        <label for="slug">Association id</label>
        <input id="slug" name="slug" type="text" value="{{ old('slug') }}" required autofocus
               placeholder="cocsol" autocomplete="off">
        {{--
          The one field that cannot be changed afterwards, said plainly. It
          becomes the database name and the code members type into the app.
        --}}
        <p class="muted" style="font-size: 12px; margin: 4px 0 0;">
            <strong>Permanent.</strong> Lowercase letters, digits and hyphens; 2–50 characters;
            must start with a letter. It becomes the database name and the code members type
            into the app, so it cannot be changed later.
        </p>

        <label for="name">Display name</label>
        <input id="name" name="name" type="text" value="{{ old('name') }}" required
               placeholder="Cooperative Society Ltd">

        <label for="legal_name">Registered legal name</label>
        <input id="legal_name" name="legal_name" type="text" value="{{ old('legal_name') }}"
               placeholder="Optional">

        <label for="domain">Domain</label>
        <input id="domain" name="domain" type="text" value="{{ old('domain') }}"
               placeholder="Leave blank for &lt;id&gt;.{{ config('tenancy.central_domains')[0] ?? 'localhost' }}">

        <h2 style="margin-top: 24px;">First administrator</h2>

        <label for="admin_email">Email</label>
        <input id="admin_email" name="admin_email" type="email" value="{{ old('admin_email') }}"
               placeholder="Optional — leave blank to add one later">
        <p class="muted" style="font-size: 12px; margin: 4px 0 0;">
            {{--
              The reason there is no password field, stated where somebody would
              look for one.
            --}}
            No password is set here. The account is created with an unusable random one and a
            <strong>single-use setup token</strong>, shown once on the next screen, which lets
            them choose a password nobody else has seen — including us.
        </p>

        <label for="admin_name">Name</label>
        <input id="admin_name" name="admin_name" type="text"
               value="{{ old('admin_name', 'Administrator') }}">

        <h2 style="margin-top: 24px;">Regional</h2>

        <div class="row">
            <div style="flex: 1;">
                <label for="locale">Locale</label>
                <input id="locale" name="locale" type="text" value="{{ old('locale', 'en') }}">
            </div>
            <div style="flex: 2;">
                <label for="timezone">Timezone</label>
                <input id="timezone" name="timezone" type="text"
                       value="{{ old('timezone', 'Asia/Dhaka') }}">
            </div>
            <div style="flex: 1;">
                <label for="currency">Currency</label>
                <input id="currency" name="currency" type="text"
                       value="{{ old('currency', 'BDT') }}">
            </div>
        </div>

        <div class="row" style="margin-top: 20px;">
            <button type="submit" class="primary">Create association</button>
            <a href="{{ route('platform.index') }}">Cancel</a>
        </div>

        <p class="muted" style="font-size: 12px; margin-top: 12px;">
            This can take a few seconds: it runs the tenant migrations and seeds the chart of
            accounts. Do not resubmit if it seems slow.
        </p>
    </form>

    <h2>Or at the server</h2>
    <div class="panel">
        <pre>php artisan tenant:provision &lt;id&gt; --name="Association name" --admin-email=someone@example.org</pre>
        <p class="muted" style="margin-bottom: 0;">
            The same code path as this form — the command and the console both call
            <code>TenantProvisioner</code>, so they cannot behave differently.
        </p>
    </div>
@endsection
