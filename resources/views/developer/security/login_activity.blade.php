@extends('base')
@section('page_title', 'Login Activity')
@section('content')
<x-page_header icon="bi-clock-history" title="Login Activity" :subtitle="'Sign-ins, sign-outs, lockouts and password changes - kept for ' . $retention_days . ' days'" />
@php
    $labels = ['LOGIN' => 'Signed in', 'LOGOUT' => 'Signed out', 'IDLE_LOGOUT' => 'Signed out (inactive)', 'LOGIN_LOCKED' => 'Locked out',
        'PASSWORD_RESET_REQUESTED' => 'Asked for password reset', 'CHANGE_PASSWORD' => 'Changed password', 'PASSWORD_HASH_UPGRADED' => 'Password format upgraded'];
@endphp
<div class="card">
    <div class="table-responsive">
    <table class="table table-sm mb-0 dev-log">
        <thead><tr><th>Time</th><th>User</th><th>Event</th><th>Details</th></tr></thead>
        <tbody>
        @forelse($logs as $e)
        <tr><td class="text-muted dev-time" data-label="Time">{{ $e->created_at }}</td><td data-label="User">{{ $e->username }}</td>
            <td data-label="Event"><span class="badge {{ $e->action === 'LOGIN_LOCKED' ? 'bg-danger' : 'bg-light text-dark border' }}">{{ $labels[$e->action] ?? $e->action }}</span></td>
            <td class="small text-muted dev-msg" data-label="Details">{{ $e->details }}</td></tr>
        @empty
        <tr><td colspan="4" class="text-center text-muted dev-empty">No login activity recorded.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
<div class="mt-3">{{ $logs->links('pagination::bootstrap-5') }}</div>
@endsection
