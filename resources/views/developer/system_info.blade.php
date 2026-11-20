@extends('base')
@section('page_title', 'System Information')
@section('content')
<x-page_header icon="bi-info-circle" title="System Information" subtitle="Versions, configuration and policies (no passwords or secret keys are shown)" />
<div class="row g-3">
    @foreach($info as $group => $rows)
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold">{{ $group }}</div>
            <table class="table table-sm mb-0">
                <tbody>
                @foreach($rows as $k => $v)
                <tr><td class="text-muted" style="width:45%;">{{ $k }}</td><td class="fw-semibold">{{ $v }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endforeach
</div>
@endsection
