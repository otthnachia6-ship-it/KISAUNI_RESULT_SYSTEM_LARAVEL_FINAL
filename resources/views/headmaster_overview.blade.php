@extends('base')
@section('page_title', 'Headmaster Overview')
@section('content')
<h5 class="mb-3">Results Overview - All Classes</h5>

<form method="GET" action="{{ route('headmaster_overview') }}" class="mb-3">
    <select name="exam_id" class="form-select form-select-sm" style="max-width:320px;" onchange="this.form.submit()">
        @foreach($exams as $e)
        <option value="{{ $e->id }}" @if($e->id == $exam_id) selected @endif>{{ $e->exam_type }} - {{ $e->academic_year }}</option>
        @endforeach
    </select>
</form>

<div class="row g-3">
    @forelse($overview as $row)
    <div class="col-md-6 col-lg-4">
        <div class="card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0">{{ $row['cls']->name }}</h6>
                <span class="badge status-badge-{{ $row['status'] }}">{{ strtoupper($STATUS_LABELS[$row['status']] ?? $row['status']) }}</span>
            </div>
            <table class="table table-sm mb-0">
                <tr><td class="text-muted">Students</td><td class="text-end fw-semibold">{{ $row['student_count'] }}</td></tr>
                <tr><td class="text-muted">Class Average</td><td class="text-end fw-semibold">{{ !is_null($row['class_average']) ? $row['class_average'] : '-' }}</td></tr>
                <tr><td class="text-muted">Top Student</td><td class="text-end fw-semibold">{{ $row['top_student'] ?: '-' }}</td></tr>
            </table>
            <a href="{{ route('performance_analytics', ['exam_id' => $exam_id, 'class_id' => $row['cls']->id]) }}" class="btn btn-sm btn-outline-primary w-100 mt-2">
                <i class="bi bi-graph-up-arrow me-1"></i>Subject Analytics
            </a>
        </div>
    </div>
    @empty
    <p class="text-muted">No data to display. Add an examination first.</p>
    @endforelse
</div>
@endsection
