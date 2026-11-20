@extends('base')
@section('page_title', 'Login Attempts')
@section('content')
<x-page_header icon="bi-person-x" title="Login Attempts" subtitle="Wrong-password counters and lockouts" />

<div class="card mb-3">
    <div class="card-header fw-semibold">Accounts with wrong passwords since their last successful sign-in</div>
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Username</th><th>Name</th><th>Role</th><th>Status</th><th class="text-end">Wrong passwords in a row</th></tr></thead>
        <tbody>
        @forelse($users as $u)
        <tr>
            <td>{{ $u->username }}</td>
            <td>{{ $u->full_name }}</td>
            <td>{{ $u->isDeveloper() ? 'Developer' : ($u->isHeadmaster() ? ($u->title ?: 'Headmaster') : 'Class Teacher') }}</td>
            <td>{!! $u->active ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' !!}</td>
            <td class="text-end fw-semibold">{{ $u->failed_login_attempts }}</td>
        </tr>
        @empty
        <tr><td colspan="5" class="text-center text-muted">No account has recent wrong passwords.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <div class="card-header fw-semibold">Lockouts and reset requests <small class="text-muted fw-normal">(audit log, last {{ $retention_days }} days)</small></div>
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Time</th><th>Username</th><th>Event</th><th>Details</th></tr></thead>
        <tbody>
        @forelse($lockouts as $e)
        <tr><td class="text-nowrap text-muted">{{ $e->created_at }}</td><td>{{ $e->username }}</td>
            <td><span class="badge {{ $e->action === 'LOGIN_LOCKED' ? 'bg-danger' : 'bg-warning text-dark' }}">{{ $e->action === 'LOGIN_LOCKED' ? 'Locked out' : 'Reset requested' }}</span></td>
            <td class="small text-muted">{{ $e->details }}</td></tr>
        @empty
        <tr><td colspan="4" class="text-center text-muted">No lockouts or reset requests recorded.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
<p class="small text-muted mt-2">Single wrong passwords are not written to the audit log (only the counter above and lockouts are), so that the log is not flooded.</p>
@endsection
