@extends('base')
@section('page_title', 'Create Developer Account')
@section('content')
<x-page_header icon="bi-shield-lock" title="Create Developer Account" subtitle="One-time setup: this page closes for good once the Developer account exists" />

<div class="alert alert-warning" style="max-width:620px;">
    <i class="bi bi-exclamation-triangle me-1"></i>
    The Developer account has full access to the system, backups and maintenance mode.
    Choose a strong password and keep it private. After this, the account can only be changed on the server.
</div>

<div class="card p-4" style="max-width:620px;">
    <form method="POST" action="{{ route('developer_setup') }}" autocomplete="off"
          onsubmit="return appConfirm(this, 'Create the Developer account now? This page will then close for good.', {okClass: 'btn-primary', okText: 'Create account', icon: 'bi-shield-lock', title: 'Create Developer account'});">
        @csrf
        <h6 class="mb-3">New Developer account</h6>
        <div class="mb-3">
            <label class="form-label" for="dev_username">Developer username</label>
            <input type="text" class="form-control" id="dev_username" name="username" value="{{ old('username', 'devuser') }}" required maxlength="191" autocomplete="off">
            <div class="form-text">Used only at the Developer sign-in page (/developer/login).</div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="dev_full_name">Full name</label>
            <input type="text" class="form-control" id="dev_full_name" name="full_name" value="{{ old('full_name', 'System Developer') }}" maxlength="191">
        </div>
        <div class="mb-3">
            <label class="form-label" for="dev_password">Developer password</label>
            <input type="password" class="form-control" id="dev_password" name="password" required autocomplete="new-password">
        </div>
        <div class="mb-4">
            <label class="form-label" for="dev_password_confirmation">Developer password again</label>
            <input type="password" class="form-control" id="dev_password_confirmation" name="password_confirmation" required autocomplete="new-password">
        </div>

        <h6 class="mb-2">Confirm it is you</h6>
        <div class="mb-3">
            <label class="form-label" for="headmaster_password">Your own (Headmaster) password</label>
            <input type="password" class="form-control" id="headmaster_password" name="headmaster_password" required autocomplete="current-password">
        </div>
        <button class="btn btn-primary"><i class="bi bi-shield-lock me-1"></i>Create Developer account</button>
    </form>
</div>
@endsection
