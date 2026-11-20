@extends('base')
@section('page_title', 'Backup Status')
@section('content')
<x-page_header icon="bi-check-circle" title="Backup Status" subtitle="Result of the most recent backup attempt (web or cron)" />

@if($dir_error)
<div class="alert alert-danger"><i class="bi bi-x-octagon me-1"></i>{{ $dir_error }}</div>
@endif

@php
    $rows = [
        ['Overall', $health['value'], $health['status'], $health['note']],
        ['Last attempt', $status['last_attempt_at'] ?? '-', ($status['last_status'] ?? '') === 'failed' ? 'critical' : (isset($status['last_attempt_at']) ? 'healthy' : 'not_configured')],
        ['Last attempt result', $status['last_status'] ?? 'never run', ($status['last_status'] ?? '') === 'success' ? 'healthy' : (($status['last_status'] ?? '') === 'failed' ? 'critical' : 'not_configured')],
        ['Last successful backup', $status['last_success_at'] ?? '-', isset($status['last_success_at']) ? 'healthy' : 'not_configured'],
        ['Last file', $status['last_file'] ?? '-', isset($status['last_file']) ? 'healthy' : 'not_configured'],
        ['Method', $status['last_method'] ?? '-', isset($status['last_method']) ? 'healthy' : 'not_configured'],
        ['Files kept', $count . ' (' . \App\Services\SystemHealthService::bytes($total_size) . ')', $count > 0 ? 'healthy' : 'not_configured'],
    ];
    if (!empty($status['last_error'])) {
        $rows[] = ['Last error', $status['last_error'], 'critical'];
    }
@endphp
@include('developer.partials.checks', ['checks' => $rows])

<p class="small text-muted">Automatic backups run only if the hosting cron job <code>php artisan db:backup</code> is set up (see README). This page reads the same status file the cron job writes.</p>
@endsection
