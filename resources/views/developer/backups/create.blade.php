@extends('base')
@section('page_title', 'Create Backup')
@section('content')
<x-page_header icon="bi-database-add" title="Create Backup" subtitle="Make a fresh, verified copy of the whole school database" />

@if($dir_error)
<div class="alert alert-danger" style="max-width:760px;"><i class="bi bi-x-octagon me-1"></i>{{ $dir_error }}</div>
@endif

<div class="card p-4 mb-3" style="max-width:760px;">
    <h6 class="mb-2">What happens when you press the button</h6>
    <ol class="small text-muted mb-3">
        <li>The database is copied <strong>read-only</strong> (school data is not changed).</li>
        <li>The copy is compressed and <strong>verified</strong> (complete and readable). A copy that fails verification is thrown away and you are told it failed.</li>
        <li>The file is kept in the private backup folder, outside the public website folder.</li>
        <li>The action is written to the audit log. Older backups are <strong>not</strong> deleted by this button.</li>
    </ol>
    <form method="POST" action="{{ route('developer.backups.store') }}" onsubmit="return appConfirm(this, 'Create a new database backup now? This may take up to a minute on a large database.', {okClass: 'btn-primary', okText: 'Create backup', icon: 'bi-database-add', title: 'Create backup'});" data-once>
        @csrf
        <button class="btn btn-primary" @disabled($dir_error)><i class="bi bi-database-add me-1"></i>Create backup now</button>
    </form>
</div>

<div class="card p-4" style="max-width:760px;">
    <h6 class="mb-2">Latest backup</h6>
    @if($latest)
        <div><code>{{ $latest['name'] }}</code></div>
        <div class="small text-muted">{{ date('Y-m-d H:i:s', $latest['mtime']) }} &middot; {{ \App\Services\SystemHealthService::bytes($latest['size']) }}</div>
        <a href="{{ route('developer.backups.download', $latest['name']) }}" class="btn btn-sm btn-outline-secondary mt-2"><i class="bi bi-download me-1"></i>Download</a>
    @else
        <div class="text-muted">No backup file exists yet.</div>
    @endif
    <div class="small text-muted mt-2">Last recorded attempt: {{ $status['last_attempt_at'] ?? '-' }} ({{ $status['last_status'] ?? '-' }})</div>
</div>
@endsection

@section('scripts')
<script>
document.querySelectorAll('form[data-once]').forEach(function (f) {
    f.addEventListener('submit', function () {
        var b = f.querySelector('button:not([type=button])');
        if (b) { setTimeout(function () { b.disabled = true; b.innerHTML = 'Creating backup&hellip;'; }, 0); }
    });
});
</script>
@endsection
