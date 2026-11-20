@extends('base')
@section('page_title', 'Backup History')
@section('content')
<x-page_header icon="bi-list-ul" title="Backup History" subtitle="Backup files kept on the server, and recent backup actions" />

<div class="card mb-3">
    <div class="card-header fw-semibold">Backup files ({{ count($files) }})</div>
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>File</th><th>Created</th><th>Type</th><th class="text-end">Size</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($files as $f)
        <tr>
            <td><code>{{ $f['name'] }}</code></td>
            <td class="text-nowrap">{{ date('Y-m-d H:i:s', $f['mtime']) }}</td>
            <td>{{ \App\Http\Controllers\Developer\BackupController::labelOf($f['name']) }}</td>
            <td class="text-end text-nowrap">{{ \App\Services\SystemHealthService::bytes($f['size']) }}</td>
            <td><span class="dev-status dev-status-healthy" title="Only files that passed verification are kept"><i class="bi bi-check-circle-fill"></i>Verified when created</span></td>
        </tr>
        @empty
        <tr><td colspan="5" class="text-center text-muted">No backup files yet.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>

<div class="card">
    <div class="card-header fw-semibold">Backup actions <small class="text-muted fw-normal">(from the audit log, kept for {{ $retention_days }} days)</small></div>
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
        <tbody>
        @forelse($events as $e)
        <tr>
            <td class="text-nowrap text-muted">{{ $e->created_at }}</td>
            <td>{{ $e->username }}</td>
            <td><span class="badge {{ $e->action === 'CREATE_BACKUP_FAILED' ? 'bg-danger' : 'bg-light text-dark border' }}">{{ $e->action }}</span></td>
            <td class="small text-muted">{{ $e->details }}</td>
        </tr>
        @empty
        <tr><td colspan="4" class="text-center text-muted">No backup actions in the last {{ $retention_days }} days. (Backups made by cron are listed above.)</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
