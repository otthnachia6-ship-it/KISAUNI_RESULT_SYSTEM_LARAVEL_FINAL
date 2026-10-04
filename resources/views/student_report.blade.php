@extends('base')
@section('page_title', 'Student Report')
@section('content')
<div class="mb-3 no-print d-flex gap-2">
    <a href="{{ route('reports') }}" class="btn btn-sm btn-outline-secondary">&larr; Back</a>
    <a href="{{ route('student_report_download', ['student_id' => $student->id, 'exam_id' => $exam->id]) }}" class="btn btn-sm btn-success">
        <i class="bi bi-download me-1"></i>Download PDF
    </a>
</div>

<div class="kps-sheet">

    <div class="kps-header-top">
        <div class="logo-col">
            <img src="{{ $logo_url($logo_path) }}" alt="logo">
        </div>
        <div class="center-col">
            <h1>{{ strtoupper($school_name) }}</h1>
            <div class="subtitle">RESULT REPORT</div>
            <span class="academic-badge">ACADEMIC YEAR: {{ $exam->academic_year }}</span>
        </div>
    </div>

    <div class="kps-info-wrap">
        <div class="kps-info-box">
            <div class="kps-info-row"><span class="k">Adm No</span><span class="sep">:</span><span class="v">{{ $student->reg_no }}</span></div>
            <div class="kps-info-row"><span class="k">Name</span><span class="sep">:</span><span class="v">{{ $student->full_name }}</span></div>
            <div class="kps-info-row"><span class="k">Class</span><span class="sep">:</span><span class="v">{{ $cls->name }}</span></div>
            <div class="kps-info-row"><span class="k">Gender</span><span class="sep">:</span><span class="v">{{ $student->gender }}</span></div>
            <div class="kps-info-row"><span class="k">Exam</span><span class="sep">:</span><span class="v">{{ ucwords(strtolower($exam->exam_type)) }}</span></div>
        </div>
    </div>

    <div class="table-responsive">
    <table class="kps-table">
        <thead>
        <tr>
            <th style="width:40px;" class="center">S/N</th>
            <th>Subject</th>
            <th class="center" style="width:110px;">Mark (/100)</th>
            <th class="center" style="width:90px;">Grade</th>
        </tr>
        </thead>
        <tbody>
        @foreach($subjects as $sub)
        @php
            $score = $result ? ($result['scores'][$sub->id] ?? null) : null;
            $letter = '-';
            if (!is_null($score)) {
                if ($score >= 81) $letter = 'A';
                elseif ($score >= 61) $letter = 'B';
                elseif ($score >= 41) $letter = 'C';
                elseif ($score >= 21) $letter = 'D';
                else $letter = 'E';
            }
        @endphp
        <tr>
            <td class="center">{{ $loop->iteration }}</td>
            <td>{{ strtoupper($sub->name) }}</td>
            <td class="center">{{ !is_null($score) ? $score : '-' }}</td>
            <td class="center fw-bold">
                @if(!is_null($score))
                    <span class="grade-text-{{ $letter }}">{{ $letter }}</span>
                @else
                    -
                @endif
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
    </div>

    <div class="table-responsive">
    <table class="kps-table" style="margin-bottom:14px;">
        <thead>
        <tr>
            <th class="center">Total</th>
            <th class="center">Average</th>
            <th class="center">Overall Grade</th>
            <th class="center">Position</th>
            <th class="center">Remark</th>
        </tr>
        </thead>
        <tbody>
        <tr style="font-weight:700; font-size:1rem;">
            <td class="center">{{ ($result && $result['subjects_entered']) ? $result['total'] : '-' }}</td>
            <td class="center">{{ ($result && !is_null($result['average'])) ? $result['average'] : '-' }}</td>
            <td class="center"><span class="grade-text-{{ $result['grade'] ?? '-' }}">{{ $result['grade'] ?? '-' }}</span></td>
            <td class="center">{{ $result['position'] ?? '-' }} of {{ $total_students }}</td>
            <td class="center">{{ $result['remark'] ?? '-' }}</td>
        </tr>
        </tbody>
    </table>
    </div>

    <div class="kps-remarks">
        <div class="k">Teacher's Comment:</div>
        <div class="box">&nbsp;</div>
    </div>

    <div class="kps-footer">
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
@endsection
