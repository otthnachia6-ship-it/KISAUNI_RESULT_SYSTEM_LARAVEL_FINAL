@extends('base')
@section('page_title', 'Change Password')
@section('content')
<x-page_header title="Change Password" subtitle="Set your own username and a new password" />
<div class="card p-4" style="max-width:500px;">
    <p class="text-muted small">You can set your own username here (leave it as-is if you don't want to change it), and you must set a new password below.</p>
    <form method="POST" action="{{ route('change_password') }}">
        @csrf
        <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" class="form-control" name="new_username" required
                   value="{{ $user['username'] ?? ($user->username ?? '') }}">
            <div class="form-text">Choose any username you'll remember - it must be unique.</div>
        </div>
        <div class="mb-3">
            <label class="form-label">Current Password</label>
            <input type="password" class="form-control" name="current_password" required>
        </div>
        <div class="mb-3">
            <label class="form-label">New Password</label>
            <input type="password" class="form-control" name="new_password" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Confirm New Password</label>
            <input type="password" class="form-control" name="confirm_password" required>
        </div>
        <button class="btn btn-success">Save</button>
    </form>
</div>
@endsection
