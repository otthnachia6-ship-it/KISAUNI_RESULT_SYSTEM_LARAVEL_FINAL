@extends('base')
@section('page_title', 'Cache Management')
@section('content')
<x-page_header icon="bi-arrow-repeat" title="Cache Management" subtitle="Clear caches after a deployment or when a page shows old content. School data is never touched." />

<div class="card mb-3" style="max-width:760px;">
    <table class="table table-sm mb-0">
        <tbody>
        @foreach($state as $k => $v)
        <tr><td class="text-muted">{{ $k }}</td><td class="fw-semibold">{{ $v }}</td></tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="row g-3" style="max-width:780px;">
    @foreach($actions as $key => [$command, $label, $explain])
    <div class="col-md-6">
        <div class="card p-3 h-100">
            <h6 class="mb-1">{{ $label }}</h6>
            <div class="small text-muted mb-2"><code>{{ $command }}</code> &middot; {{ $explain }}</div>
            <form method="POST" action="{{ route('developer.maintenance.cache.clear') }}" class="mt-auto"
                  onsubmit="return appConfirm(this, @js('Clear the ' . strtolower($label) . '? ' . $explain), {okClass: 'btn-warning', okText: 'Clear', icon: 'bi-arrow-repeat', title: 'Clear cache'});">
                @csrf
                <input type="hidden" name="action" value="{{ $key }}">
                <button class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-repeat me-1"></i>Clear</button>
            </form>
        </div>
    </div>
    @endforeach
</div>
@endsection
