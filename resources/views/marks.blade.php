@extends('base')
@section('page_title', 'Marks Entry')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-0">{{ $cls->name }} — {{ $exam->exam_type }} ({{ $exam->academic_year }})</h5>
        <span class="badge status-badge-{{ $status }} mt-1">Status: {{ strtoupper($STATUS_LABELS[$status] ?? $status) }}</span>
    </div>
    <a href="{{ route('marks_select') }}" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
</div>

@if($status === 'returned')
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-1"></i>The Headmaster has returned these results for correction. Make your corrections and resubmit.</div>
@endif

@if($locked)
<div class="alert alert-info"><i class="bi bi-lock me-1"></i>Marks for this class are locked (read-only) because they have already been submitted or approved.</div>
@endif

<form method="POST" action="{{ route('marks', ['exam_id' => $exam->id, 'class_id' => $cls->id]) }}">
@csrf
<div class="card">
    <div class="table-responsive">
        <table class="table table-bordered table-sm align-middle mb-0 marks-table">
            <colgroup>
                <col style="width:190px;">
                @foreach($subjects as $sub)<col style="width:115px;">@endforeach
                <col style="width:80px;">
                <col style="width:90px;">
                <col style="width:80px;">
                <col style="width:80px;">
            </colgroup>
            <thead>
            <tr>
                <th>Student Name</th>
                @foreach($subjects as $sub)<th class="text-center">{{ $sub->name }}</th>@endforeach
                <th class="text-center">Total</th>
                <th class="text-center">Average</th>
                <th class="text-center">Grade</th>
                <th class="text-center">Position</th>
            </tr>
            </thead>
            <tbody>
            @forelse($results as $r)
            <tr>
                <td>{{ $r['student']['full_name'] }} <div class="text-muted small">{{ $r['student']['reg_no'] }}</div></td>
                @foreach($subjects as $sub)
                <td>
                    <input type="number" min="0" max="100" step="0.5"
                           class="form-control form-control-sm text-center score-input"
                           data-student="{{ $r['student']['id'] }}"
                           name="score_{{ $r['student']['id'] }}_{{ $sub->id }}"
                           value="{{ $r['scores'][$sub->id] ?? '' }}"
                           @if($locked) disabled @endif>
                </td>
                @endforeach
                <td class="text-center fw-semibold" id="total-{{ $r['student']['id'] }}">{{ $r['subjects_entered'] ? $r['total'] : '-' }}</td>
                <td class="text-center fw-semibold" id="avg-{{ $r['student']['id'] }}">{{ !is_null($r['average']) ? $r['average'] : '-' }}</td>
                <td class="text-center" id="grade-{{ $r['student']['id'] }}">
                    <span class="grade-badge grade-{{ $r['grade'] }}">{{ $r['grade'] }}</span>
                </td>
                <td class="text-center fw-semibold">{{ !is_null($r['average']) ? $r['position'] : '-' }}</td>
            </tr>
            @empty
            <tr><td colspan="{{ count($subjects) + 5 }}" class="text-center text-muted">No students in this class.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@if(!$locked)
<div class="mt-3 d-flex gap-2 no-print">
    <button type="submit" class="btn btn-success"><i class="bi bi-save me-1"></i>Save Marks</button>
</div>
@endif
</form>

@if(!$locked && count($results) > 0)
@php
    $missingTotal = 0;
    foreach ($results as $r) {
        $missingTotal += (count($subjects) - $r['subjects_entered']);
    }
@endphp
<div class="mt-2 no-print">
    @if($missingTotal == 0)
    <div class="alert alert-success py-2 mb-2"><i class="bi bi-check-circle me-1"></i>All marks completed - ready to submit.</div>
    @else
    <div class="alert alert-warning py-2 mb-2">
        <i class="bi bi-exclamation-triangle me-1"></i>
        {{ $missingTotal }} mark(s) still missing. You can submit only once every student has every subject filled in.
    </div>
    @endif
</div>
<form method="POST" action="{{ route('submit_marks', ['exam_id' => $exam->id, 'class_id' => $cls->id]) }}" class="mt-2 no-print"
      onsubmit="return appConfirm(this, 'Are you sure you want to submit these results to the Headmaster? Once submitted, you will not be able to edit them until they are returned.', {okClass: 'btn-success', okText: 'Submit', icon: 'bi-send-check'});">
    @csrf
    <button class="btn btn-outline-primary" @if($missingTotal > 0) disabled title="Fill in every mark first" @endif>
        <i class="bi bi-send me-1"></i>Submit for Headmaster Review
    </button>
</form>
@endif

@if(!empty($history))
<div class="card p-3 mt-3 no-print">
    <h6 class="mb-3"><i class="bi bi-clock-history me-1"></i>Submission &amp; Feedback History</h6>
    <ul class="list-unstyled mb-0">
        @foreach($history as $h)
        <li class="mb-2 pb-2 border-bottom">
            <div class="d-flex justify-content-between">
                <span class="fw-semibold">
                    @if($h->action === 'SUBMIT_RESULTS')<i class="bi bi-send text-primary me-1"></i>Submitted by {{ $h->username }}
                    @elseif($h->action === 'START_REVIEW')<i class="bi bi-eye text-info me-1"></i>Review started by {{ $h->username }}
                    @elseif($h->action === 'APPROVE_RESULTS')<i class="bi bi-check2-circle text-success me-1"></i>Approved by {{ $h->username }}
                    @elseif($h->action === 'RETURN_RESULTS')<i class="bi bi-arrow-return-left text-danger me-1"></i>Returned by {{ $h->username }}
                    @endif
                </span>
                <span class="text-muted small">{{ $h->created_at }}</span>
            </div>
            @if($h->action === 'RETURN_RESULTS' && str_contains($h->details, ':'))
            <div class="text-muted small mt-1">Feedback: "{{ explode(': ', $h->details, 2)[1] ?? '' }}"</div>
            @endif
        </li>
        @endforeach
    </ul>
</div>
@endif

@endsection
