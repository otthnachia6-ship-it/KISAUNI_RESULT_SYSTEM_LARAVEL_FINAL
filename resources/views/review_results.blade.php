@extends('base')
@section('page_title', 'Review Results')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-0">{{ $cls->name }} — {{ $exam->exam_type }} ({{ $exam->academic_year }})</h5>
        <span class="badge status-badge-{{ $status_row ? $status_row->status : 'draft' }} mt-1">
            {{ strtoupper($STATUS_LABELS[$status_row ? $status_row->status : 'draft'] ?? 'Draft') }}
        </span>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('review_results_download', ['exam_id' => $exam->id, 'class_id' => $cls->id]) }}" class="btn btn-sm btn-outline-primary no-print">
            <i class="bi bi-file-earmark-pdf me-1"></i>Download PDF
        </a>
        <a href="{{ route('results') }}" class="btn btn-sm btn-outline-secondary no-print">&larr; Back</a>
    </div>
</div>

@if($status_row && $status_row->remarks && $status_row->status === 'returned')
<div class="alert alert-secondary no-print"><strong>Previous Remarks:</strong> {{ $status_row->remarks }}</div>
@endif

<div class="kps-sheet class-report-wrap">

    <div class="kps-header-top d-none d-print-flex">
        <div class="logo-col"><img src="{{ $logo_url($logo_path) }}" alt="logo"></div>
        <div class="center-col">
            <h1>{{ strtoupper($school_name) }}</h1>
            <div class="subtitle">CLASS RESULT SHEET</div>
            <span class="academic-badge">{{ strtoupper($exam->exam_type) }} - {{ $exam->academic_year }}</span>
        </div>
    </div>

    <div class="kps-info-wrap d-none d-print-flex">
        <div class="kps-info-box">
            <div class="kps-info-row"><span class="k">Class</span><span class="sep">:</span><span class="v">{{ $cls->name }}</span></div>
            <div class="kps-info-row"><span class="k">Students</span><span class="sep">:</span><span class="v">{{ count($results) }}</span></div>
            <div class="kps-info-row"><span class="k">Class Teacher</span><span class="sep">:</span><span class="v">{{ $teacher ? $teacher->full_name : '-' }}</span></div>
            <div class="kps-info-row"><span class="k">Class Average</span><span class="sep">:</span><span class="v">{{ !is_null($class_average) ? $class_average : '-' }} ({{ $class_grade }})</span></div>
            <div class="kps-info-row"><span class="k">Class Position</span><span class="sep">:</span><span class="v">{{ !is_null($class_position) ? $class_position : '-' }}</span></div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0 report-table">
                <thead>
                <tr>
                    <th class="d-none d-print-table-cell text-center" style="width:36px;">S/N</th>
                    <th>Reg No</th>
                    <th>Name</th>
                    @foreach($subjects as $sub)<th class="text-center">{{ $sub->name }}</th>@endforeach
                    <th class="text-center">Total</th>
                    <th class="text-center">Average</th>
                    <th class="text-center">Grade</th>
                    <th class="text-center">Position</th>
                    <th class="text-center">Remark</th>
                </tr>
                </thead>
                <tbody>
                @foreach($results as $r)
                <tr>
                    <td class="d-none d-print-table-cell text-center">{{ $loop->iteration }}</td>
                    <td>{{ $r['student']['reg_no'] }}</td>
                    <td>{{ $r['student']['full_name'] }}</td>
                    @foreach($subjects as $sub)
                    <td class="text-center">{{ $r['scores'][$sub->id] ?? '-' }}</td>
                    @endforeach
                    <td class="text-center fw-semibold">{{ $r['subjects_entered'] ? $r['total'] : '-' }}</td>
                    <td class="text-center fw-semibold">{{ !is_null($r['average']) ? $r['average'] : '-' }}</td>
                    <td class="text-center"><span class="grade-badge grade-{{ $r['grade'] }}">{{ $r['grade'] }}</span></td>
                    <td class="text-center">{{ $r['position'] }}</td>
                    <td class="text-center">{{ $r['remark'] }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="kps-footer d-none d-print-flex">
        <div class="sign-col">
            <div class="sign-line">CLASS TEACHER SIGN.@if($teacher)<br><small>{{ $teacher->full_name }}</small>@endif</div>
            <div class="sign-date">Date: {{ $report_date }}</div>
        </div>
        <div class="stamp-col">
            <div class="stamp-circle">{{ strtoupper($school_name) }}<br>OFFICIAL STAMP</div>
        </div>
        <div class="sign-col">
            <div class="sign-line">HEAD MASTER SIGN.</div>
            <div class="sign-date">Date: {{ $report_date }}</div>
        </div>
    </div>
</div>

@if($status_row && in_array($status_row->status, ['submitted', 'under_review']))
<div class="card p-3 no-print mb-3">
    <div class="d-flex gap-2 flex-wrap">
        <form method="POST" action="{{ route('review_results', ['exam_id' => $exam->id, 'class_id' => $cls->id]) }}" onsubmit="return appConfirm(this, 'Are you sure you want to approve these results? Once approved, they will be locked.', {okClass: 'btn-success', okText: 'Approve', icon: 'bi-check2-circle'});">
            @csrf
            <input type="hidden" name="action" value="approve">
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check2-circle me-1"></i>Approve Results
            </button>
        </form>
        <button type="button" class="btn btn-outline-danger" onclick="document.getElementById('returnPanel').classList.toggle('d-none')">
            <i class="bi bi-arrow-return-left me-1"></i>Return for Correction
        </button>
    </div>

    <div id="returnPanel" class="d-none mt-3 border-top pt-3">
        <form method="POST" action="{{ route('review_results', ['exam_id' => $exam->id, 'class_id' => $cls->id]) }}">
            @csrf
            <input type="hidden" name="action" value="return">
            <label class="form-label fw-semibold">Reason / Feedback</label>
            <textarea name="remarks" class="form-control mb-2" rows="3" required
                      placeholder="e.g. Please check the Mathematics marks for Asha, they look incorrect before resubmission."></textarea>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-danger"><i class="bi bi-send me-1"></i>Send Feedback</button>
                <button type="button" class="btn btn-outline-secondary" onclick="document.getElementById('returnPanel').classList.add('d-none')">Cancel</button>
            </div>
        </form>
    </div>
</div>
@endif

@if(!empty($history))
<div class="card p-3 no-print">
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
