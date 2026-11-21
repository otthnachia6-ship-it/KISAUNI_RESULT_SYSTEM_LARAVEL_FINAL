@extends('base')
@section('page_title', 'Login Attempts')
@section('content')
<x-page_header icon="bi-person-x" title="Login Attempts" subtitle="Wrong-password counters and lockouts" />

<div class="card mb-3">
    <div class="card-header fw-semibold">Accounts with wrong passwords since their last successful sign-in</div>
    <div class="table-responsive">
    <table class="table table-sm mb-0 dev-log">
        <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Status</th><th class="text-end">Wrong passwords in a row</th></tr></thead>
        <tbody>
        @forelse($users as $u)
        <tr>
            <td data-label="Username">{{ $u->username }}</td>
            <td data-label="Name">{{ $u->full_name }}</td>
            <td data-label="Role">{{ $u->isDeveloper() ? 'Developer' : ($u->isHeadmaster() ? ($u->title ?: 'Headmaster') : 'Class Teacher') }}</td>
            <td data-label="Status">{!! $u->active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' !!}</td>
            <td class="text-end fw-semibold" data-label="Wrong">{{ $u->failed_login_attempts }}</td>
        </tr>
        @empty
        <tr><td colspan="5" class="text-center text-muted dev-empty">No account has recent wrong passwords.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <div class="card-header fw-semibold">Lockouts and reset requests <small class="text-muted fw-normal">(audit log, last {{ $retention_days }} days)</small></div>
    <div class="table-responsive">
    <table class="table table-sm mb-0 dev-log">
        <thead><tr><th>Time</th><th>Username</th><th>Event</th><th>Details</th></tr></thead>
        <tbody>
        @forelse($lockouts as $e)
        <tr><td class="text-muted dev-time" data-label="Time">{{ $e->created_at }}</td><td data-label="User">{{ $e->username }}</td>
            <td data-label="Event"><span class="badge {{ $e->action === 'LOGIN_LOCKED' ? 'bg-danger' : 'bg-warning text-dark' }}">{{ $e->action === 'LOGIN_LOCKED' ? 'Locked out' : 'Reset requested' }}</span></td>
            <td class="small text-muted dev-msg" data-label="Details">{{ $e->details }}</td></tr>
        @empty
        <tr><td colspan="4" class="text-center text-muted dev-empty">No lockouts or reset requests recorded.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
<p class="small text-muted mt-2">Single wrong passwords are not written to the audit log (only the counter above and lockouts are), so that the log is not flooded.</p>
@endsection
