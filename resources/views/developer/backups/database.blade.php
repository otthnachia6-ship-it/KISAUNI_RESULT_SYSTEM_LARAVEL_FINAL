@extends('base')
@section('page_title', 'Database Overview')
@section('content')
<x-page_header icon="bi-table" title="Database Overview" subtitle="Connection, size and the number of records in each school table (read-only)">
    <x-slot:actions>
        <a href="{{ route('developer.backups.create') }}" class="btn btn-primary"><i class="bi bi-database-add me-1"></i>Create backup</a>
    </x-slot:actions>
</x-page_header>

@include('developer.partials.checks', ['checks' => $checks])

<div class="card">
    <div class="card-header fw-semibold">School tables</div>
    <div class="table-responsive">
    <table class="table table-sm mb-0">
        <thead><tr><th>Table</th><th>Contents</th><th class="text-end">Rows</th></tr></thead>
        <tbody>
        @foreach($tables as $t)
        <tr><td><code>{{ $t['table'] }}</code></td><td>{{ $t['label'] }}</td><td class="text-end">{{ $t['rows'] === null ? '-' : number_format($t['rows']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>
@endsection
