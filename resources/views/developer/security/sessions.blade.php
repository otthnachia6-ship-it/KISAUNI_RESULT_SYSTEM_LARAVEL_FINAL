@extends('base')
@section('page_title', 'Session Monitoring')
@section('content')
<x-page_header icon="bi-window-stack" title="Session Monitoring" subtitle="How sign-in sessions are kept, and recent sign-ins and sign-outs" />

@php
    $rows = [
        ['Session driver', $driver, 'healthy'],
        ['Session lifetime', $lifetime . ' minutes', 'healthy'],
        ['Idle sign-out', $idle . ' minutes', 'healthy'],
        ['Active sessions', $active === null ? '-' : (string) $active, $active === null ? 'unavailable' : 'healthy', $note],
    ];
@endphp
@include('developer.partials.checks', ['checks' => $rows])

<div class="card">
    <div class="card-header fw-semibold">Recent sign-ins and sign-outs</div>
    <div class="table-responsive">
    <table class="table table-sm mb-0 dev-log">
        <thead><tr><th>Time</th><th>User</th><th>Event</th></tr></thead>
        <tbody>
        @forelse($recent as $e)
        <tr><td class="text-muted dev-time" data-label="Time">{{ $e->created_at }}</td><td data-label="User">{{ $e->username }}</td>
            <td data-label="Event">{{ ['LOGIN' => 'Signed in', 'LOGOUT' => 'Signed out', 'IDLE_LOGOUT' => 'Signed out (inactive)'][$e->action] ?? $e->action }}</td></tr>
        @empty
        <tr><td colspan="3" class="text-center text-muted dev-empty">No sign-ins recorded.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
