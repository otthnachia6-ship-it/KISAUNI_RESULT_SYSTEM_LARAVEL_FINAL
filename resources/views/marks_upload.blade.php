@extends('base')
@section('page_title', 'Upload Exam Results')
@section('content')
<x-page_header title="Upload Exam Results" subtitle="Upload the Excel file of each subject exported from ZEMS" />

@if(!$cls)
<div class="alert alert-warning" style="max-width:600px;">
    You are not currently assigned to a class. Contact the Headmaster.
</div>
@elseif($exams->isEmpty())
<div class="alert alert-warning" style="max-width:600px;">
    <i class="bi bi-exclamation-triangle me-1"></i>
    No examinations have been added yet for Academic Year <strong>{{ $current_academic_year }}</strong>.
    Please ask the Headmaster to add one.
</div>
@else
@php $hasSelected = $exams->contains('id', $selected_exam); @endphp
<div class="card p-4 mb-3" style="max-width:640px;">
    <form method="POST" action="{{ route('marks.upload_ozems') }}" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label class="form-label">Class</label>
            <input type="text" class="form-control" value="{{ $cls->name }}" disabled>
        </div>
        <div class="mb-3">
            <label class="form-label" for="exam_id">Examination</label>
            <select id="exam_id" name="exam_id" class="form-select" required>
                <option value="">-- Select Examination --</option>
                @foreach($exams as $e)
                <option value="{{ $e->id }}" @if($hasSelected && (int)$e->id === (int)$selected_exam) selected @endif>{{ $e->exam_type }} - {{ $e->academic_year }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label" for="file">ZEMS result file (.xls)</label>
            <input type="file" id="file" name="file" class="form-control" accept=".xls,.csv,application/vnd.ms-excel,text/csv" required>
            <div class="form-text">
                Upload the Excel file exactly as exported from ZEMS (do not edit or re-save it). One subject per file.
                The subject and the pupils are recognised automatically, and only the pupils of your class are used.
                Uploading the same subject again replaces its marks.
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-success"><i class="bi bi-cloud-arrow-up me-1"></i>Upload</button>
            @if($hasSelected)
            <a href="{{ route('marks', ['exam_id' => $selected_exam, 'class_id' => $cls->id]) }}" class="btn btn-outline-secondary">
                <i class="bi bi-table me-1"></i>Open marks sheet (check &amp; submit)
            </a>
            @else
            <a href="{{ route('marks_select') }}" class="btn btn-outline-secondary">
                <i class="bi bi-table me-1"></i>Open marks sheet (check &amp; submit)
            </a>
            @endif
        </div>
    </form>
</div>
@endif

@if(!empty($report))
<div class="card p-4" style="max-width:640px;">
    <h2 class="h6 mb-3">{{ $report['subject_name'] }} <span class="text-muted">(code {{ $report['subject_code'] }})</span></h2>
    @if(!empty($report['file_std']) || !empty($report['file_term']) || !empty($report['file_year']))
    <div class="text-muted small mb-2">
        File: @if(!empty($report['file_std']))STD{{ $report['file_std'] }}@endif
        @if(!empty($report['file_term'])) &middot; {{ $report['file_term'] }}@endif
        @if(!empty($report['file_year'])) &middot; {{ $report['file_year'] }}@endif
    </div>
    @endif
    <ul class="list-unstyled small mb-3">
        <li><strong>{{ $report['saved'] }}</strong> mark(s) saved</li>
        <li>{{ $report['total_rows'] }} pupil row(s) in the file</li>
        @if(($report['other_class'] ?? 0) > 0)<li>{{ $report['other_class'] }} pupil(s) of other classes in the file were skipped (normal when the file covers all streams)</li>@endif
        @if(($report['removed'] ?? 0) > 0)<li>{{ $report['removed'] }} removed pupil(s) were skipped</li>@endif
        @if($report['blank'] > 0)<li>{{ $report['blank'] }} empty score(s) skipped</li>@endif
    </ul>

    @if(($report['linked_total'] ?? 0) > 0)
    <details class="mb-2" open>
        <summary class="text-primary">{{ $report['linked_total'] }} pupil(s) linked to their ZEMS number by name - please check</summary>
        <ul class="small mt-1 mb-0">@foreach($report['linked'] as $line)<li>{{ $line }}</li>@endforeach</ul>
        <div class="form-text">Wrong link? Correct the ZEMS Number on the Students page (Edit) and upload again.</div>
    </details>
    @endif

    @if($report['invalid_total'] > 0)
    <details class="mb-2" open>
        <summary class="text-danger">{{ $report['invalid_total'] }} score(s) could not be used</summary>
        <ul class="small mt-1 mb-0">@foreach($report['invalid'] as $line)<li>{{ $line }}</li>@endforeach</ul>
    </details>
    @endif
    @if($report['not_found_total'] > 0)
    <details class="mb-2" open>
        <summary class="text-danger">{{ $report['not_found_total'] }} pupil(s) in the file are not registered in your class</summary>
        <ul class="small mt-1 mb-0">@foreach($report['not_found'] as $z)<li>{{ $z }}</li>@endforeach</ul>
        <div class="form-text">Register the pupil in your class (Students page) or fill in the ZEMS Number there, then upload again.</div>
    </details>
    @endif
    @if($report['duplicates_total'] > 0)
    <details class="mb-2">
        <summary class="text-warning">{{ $report['duplicates_total'] }} repeated ZEMS number(s) ignored</summary>
        <ul class="small mt-1 mb-0">@foreach($report['duplicates'] as $z)<li>{{ $z }}</li>@endforeach</ul>
    </details>
    @endif
    @if($report['missing_students_total'] > 0)
    <details class="mb-0">
        <summary class="text-muted">{{ $report['missing_students_total'] }} pupil(s) of your class have no mark from this file</summary>
        <ul class="small mt-1 mb-0">@foreach($report['missing_students'] as $n)<li>{{ $n }}</li>@endforeach</ul>
    </details>
    @endif
</div>
@endif
@endsection
