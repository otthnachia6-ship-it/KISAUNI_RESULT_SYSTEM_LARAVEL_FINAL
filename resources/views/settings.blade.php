@extends('base')
@section('page_title', 'Settings')
@section('content')
<x-page_header icon="bi-gear" title="School Settings" subtitle="School name, logo and academic year" />
<div class="card p-4" style="max-width:550px;">
    <form method="POST" action="{{ route('settings_page') }}" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label class="form-label">School Name</label>
            <input type="text" class="form-control" name="school_name" value="{{ $current['school_name'] ?? $school_name }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Academic Year</label>
            <input type="text" class="form-control" name="academic_year" value="{{ $current['academic_year'] ?? $academic_year }}" required>
            <div class="form-text">
                Changing this to a new year is also what unlocks Class Teachers to promote their
                students to the next class (they cannot promote while it stays the same as before).
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label">School Logo</label><br>
            <img src="{{ $logo_url($current['logo_path'] ?? $logo_path) }}" style="height:60px;" class="mb-2"><br>
            <input type="file" class="form-control" name="logo" accept="image/*">
        </div>
        <div class="mb-3">
            <label class="form-label">Grading System (fixed)</label>
            <table class="table table-sm">
                <tr><td><span class="grade-badge grade-A">A</span></td><td>81 - 100</td></tr>
                <tr><td><span class="grade-badge grade-B">B</span></td><td>61 - 80</td></tr>
                <tr><td><span class="grade-badge grade-C">C</span></td><td>41 - 60</td></tr>
                <tr><td><span class="grade-badge grade-D">D</span></td><td>21 - 40</td></tr>
                <tr><td><span class="grade-badge grade-E">E</span></td><td>0 - 20.9</td></tr>
            </table>
            <small class="text-muted">To change the grading bands, contact your system administrator (config/school.php).</small>
        </div>
        <button class="btn btn-success">Save Settings</button>
    </form>
</div>

<div class="card p-4 mt-4" id="backups" style="max-width:750px;">
    <h5 class="mb-1">Database Backups</h5>
    <p class="text-muted small mb-3">
        Live database: <strong>{{ $db_size_kb }} KB</strong>
        @if($db_modified)(last changed {{ $db_modified }})@endif.
        The system deletes automatic/manual backups older than {{ $backup_retention_days }} days on its own,
        but always keeps at least the {{ $backup_min_keep }} most recent ones regardless of age.
        There are currently {{ count($backups) }} on the server.
    </p>

    @if(is_null($offsite_days))
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        You have never downloaded a backup off this server. If something ever happens to this
        server account, the live data and every backup here would be affected together -
        download one below and keep it somewhere else (your computer, email, Google Drive).
    </div>
    @elseif($offsite_days >= 30)
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        It has been {{ $offsite_days }} days since you last downloaded a backup off this server.
        Consider downloading a fresh one below.
    </div>
    @else
    <div class="alert alert-success small">
        <i class="bi bi-check-circle me-1"></i>
        A backup was downloaded off this server {{ $offsite_days }} day(s) ago.
    </div>
    @endif

    <form method="POST" action="{{ route('create_backup_route') }}" class="mb-3">
        @csrf
        <button class="btn btn-outline-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Create Backup Now</button>
    </form>

    <div class="alert alert-info small">
        <i class="bi bi-life-preserver me-1"></i>
        <strong>Disaster recovery:</strong> if this server itself was ever lost/rebuilt and you only
        have a backup file saved on your own computer or phone, upload it here to bring the system
        back exactly as it was.
        <form method="POST" action="{{ route('upload_restore_backup_route') }}" enctype="multipart/form-data"
              class="row g-2 align-items-end mt-2"
              onsubmit="return appConfirm(this, 'This will REPLACE the current live data with the contents of the uploaded backup file. A safety copy of what is here now will be taken first. Continue?', {okText: 'Replace data', icon: 'bi-upload'});">
            @csrf
            <div class="col-sm-8">
                <input type="file" name="backup_file" accept=".sql,.db" class="form-control form-control-sm" required>
            </div>
            <div class="col-sm-4">
                <button class="btn btn-warning btn-sm w-100"><i class="bi bi-upload me-1"></i>Upload &amp; Restore</button>
            </div>
        </form>
    </div>

    @if(empty($backups))
    <div class="alert alert-info small">No backups yet - one will be created automatically, or click "Create Backup Now" above.</div>
    @else
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead><tr><th>Created</th><th>Size</th><th style="width:280px;"></th></tr></thead>
            <tbody>
            @foreach($backups as $b)
            <tr>
                <td>{{ $b['created_at'] }}@if($loop->first) <span class="badge bg-secondary-subtle text-secondary">Most recent</span>@endif</td>
                <td>{{ $b['size_kb'] }} KB</td>
                <td>
                    <a href="{{ route('download_backup', ['filename' => $b['filename']]) }}" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-download me-1"></i>Download
                    </a>
                    <form method="POST" action="{{ route('restore_backup_route', ['filename' => $b['filename']]) }}" class="d-inline"
                          onsubmit="return appConfirm(this, 'Restore the database from this backup ({{ $b['created_at'] }})? The CURRENT data will be replaced (a safety copy of it is taken first). Continue?', {okText: 'Restore', icon: 'bi-arrow-counterclockwise'});">
                        @csrf
                        <button class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-counterclockwise me-1"></i>Restore</button>
                    </form>
                    <form method="POST" action="{{ route('delete_backup_route', ['filename' => $b['filename']]) }}" class="d-inline"
                          onsubmit="return appConfirm(this, 'Delete this backup file permanently? Make sure you have downloaded it first if you need to keep it.', {okText: 'Delete', icon: 'bi-trash'});">
                        @csrf
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete</button>
                    </form>
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>
@endsection
