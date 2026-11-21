@extends('base')
@section('page_title', 'Download Backup')
@section('content')
<x-page_header icon="bi-download" title="Download Backup" subtitle="Each file is verified again before it is sent to your computer">
    <x-slot:actions>
        <a href="{{ route('developer.backups.create') }}" class="btn btn-primary"><i class="bi bi-database-add me-1"></i>Create backup</a>
    </x-slot:actions>
</x-page_header>

@if($dir_error)
<div class="alert alert-danger"><i class="bi bi-x-octagon me-1"></i>{{ $dir_error }}</div>
@endif

<div class="alert alert-warning small"><i class="bi bi-shield-exclamation me-1"></i>
    A backup contains all school records and the password <em>hashes</em> of staff accounts. Keep downloaded files in a safe, private place.</div>

<div class="card">
    <div class="table-responsive">
    <table class="table table-sm mb-0 dev-log">
        <thead><tr><th>File</th><th>Created</th><th>Type</th><th class="text-end">Size</th><th></th></tr></thead>
        <tbody>
        @forelse($files as $f)
        <tr>
            <td data-label="File" style="overflow-wrap:anywhere;"><code>{{ $f['name'] }}</code></td>
            <td class="dev-time" data-label="Created">{{ date('Y-m-d H:i:s', $f['mtime']) }}</td>
            <td data-label="Type">{{ \App\Http\Controllers\Developer\BackupController::labelOf($f['name']) }}</td>
            <td class="text-end text-nowrap" data-label="Size">{{ \App\Services\SystemHealthService::bytes($f['size']) }}</td>
            <td class="text-end dev-empty pt-2"><a href="{{ route('developer.backups.download', $f['name']) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-download me-1"></i>Download</a></td>
        </tr>
        @empty
        <tr><td colspan="5" class="text-center text-muted dev-empty">No backup files yet. <a href="{{ route('developer.backups.create') }}">Create one</a>.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
