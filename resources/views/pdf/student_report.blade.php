<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Student Report</title>
<style>
    @page {
        margin: 10mm;
        size: a4 portrait;
    }
    body {
        font-family: 'Helvetica', Arial, sans-serif;
        color: #1b2a4e;
        margin: 0;
        padding: 0;
        font-size: 10pt;
    }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    .header-table td {
        vertical-align: top;
    }
    .school-title {
        font-size: 18pt;
        font-weight: bold;
        color: #1b2a4e;
        text-align: center;
        margin: 0 0 2px 0;
        text-transform: uppercase;
    }
    .report-subtitle {
        font-size: 13pt;
        font-weight: bold;
        color: #1b2a4e;
        text-align: center;
        margin: 0 0 6px 0;
    }
    .badge-container {
        text-align: center;
        margin-bottom: 8px;
    }
    .badge {
        display: inline-block;
        background-color: #1b2a4e;
        color: #ffffff;
        font-weight: bold;
        font-size: 9.5pt;
        padding: 4px 16px;
        border-radius: 3px;
        text-align: center;
    }
    .info-table {
        width: 100%;
        margin-top: 6px;
        margin-bottom: 4px;
    }
    .info-table td {
        font-size: 9.5pt;
        padding: 1px 0;
        line-height: 1.3;
    }
    .divider {
        border-top: 1.4pt solid #1b2a4e;
        margin: 6px 0 10px 0;
    }
    .subject-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 12px;
    }
    .subject-table th {
        background-color: #1b2a4e;
        color: #ffffff;
        font-size: 9pt;
        font-weight: bold;
        padding: 6px 4px;
        text-align: center;
        border: 0.6pt solid #cccccc;
    }
    .subject-table td {
        padding: 5px 6px;
        font-size: 9.5pt;
        border: 0.6pt solid #cccccc;
    }
    .subject-table tr:nth-child(even) td {
        background-color: #f7f8fb;
    }
    .text-center { text-align: center; }
    .text-left { text-align: left; }
    .text-right { text-align: right; }
    .grade-A { color: #16a34a; font-weight: bold; }
    .grade-B { color: #2563eb; font-weight: bold; }
    .grade-C { color: #b45309; font-weight: bold; }
    .grade-D { color: #ea580c; font-weight: bold; }
    .grade-E { color: #dc2626; font-weight: bold; }
    .grade-- { color: #6c757d; font-weight: bold; }

    .summary-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 14px;
    }
    .summary-table th {
        font-size: 9pt;
        color: #1b2a4e;
        font-weight: bold;
        padding: 6px;
        text-align: center;
        border: 0.6pt solid #cccccc;
        background-color: #ffffff;
    }
    .summary-table td {
        padding: 7px;
        font-size: 11pt;
        font-weight: bold;
        text-align: center;
        border: 0.6pt solid #cccccc;
    }
    .comment-box-label {
        font-size: 9.5pt;
        font-weight: bold;
        color: #1b2a4e;
        margin-bottom: 4px;
    }
    .comment-box {
        width: 100%;
        height: 50px;
        border: 0.6pt solid #cccccc;
        margin-bottom: 24px;
    }
    .footer-table {
        width: 100%;
        margin-top: 15px;
    }
    .footer-table td {
        text-align: center;
        vertical-align: middle;
        font-size: 9pt;
    }
    .sign-dots {
        color: #1b2a4e;
        font-weight: bold;
    }
    .sign-label {
        color: #1b2a4e;
        font-weight: bold;
        font-size: 8.5pt;
        margin-top: 4px;
    }
    .stamp-label {
        color: #8891a8;
        font-size: 7.5pt;
        line-height: 1.2;
    }
    .date-label {
        color: #666666;
        font-size: 8pt;
        margin-top: 4px;
    }
</style>
</head>
<body>

<table class="header-table">
    <tr>
        <td style="width: 75px; text-align: left;">
            @if(!empty($logoBase64))
                <img src="{{ $logoBase64 }}" style="width: 70px; height: 70px; object-fit: contain;">
            @endif
        </td>
        <td style="text-align: center;">
            <div class="school-title">{{ strtoupper($data['school_name']) }}</div>
            <div class="report-subtitle">RESULT REPORT</div>
            <div class="badge-container">
                <span class="badge">ACADEMIC YEAR: {{ $data['academic_year'] }}</span>
            </div>
        </td>
    </tr>
</table>

<table class="info-table">
    <tr><td><b>Adm No:</b> {{ $data['reg_no'] }}</td></tr>
    <tr><td><b>Name:</b> {{ $data['full_name'] }}</td></tr>
    <tr><td><b>Class:</b> {{ $data['class_name'] }}</td></tr>
    <tr><td><b>Gender:</b> {{ $data['gender'] }}</td></tr>
    <tr><td><b>Exam:</b> {{ $data['exam_type'] }}</td></tr>
</table>

<div class="divider"></div>

<table class="subject-table">
    <thead>
        <tr>
            <th style="width: 12%;">S/N</th>
            <th style="width: 48%; text-align: left; padding-left: 10px;">Subject</th>
            <th style="width: 20%;">Mark (/100)</th>
            <th style="width: 20%;">Grade</th>
        </tr>
    </thead>
    <tbody>
        @foreach($data['subjects'] as $i => $sub)
        <tr>
            <td class="text-center">{{ $i + 1 }}</td>
            <td class="text-left" style="padding-left: 10px;">{{ strtoupper($sub['name']) }}</td>
            <td class="text-center">{{ $sub['mark'] !== null ? $sub['mark'] : '-' }}</td>
            <td class="text-center grade-{{ $sub['grade'] }}">{{ $sub['grade'] }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

<table class="summary-table">
    <thead>
        <tr>
            <th style="width: 20%;">Total</th>
            <th style="width: 20%;">Average</th>
            <th style="width: 20%;">Overall Grade</th>
            <th style="width: 20%;">Position</th>
            <th style="width: 20%;">Remark</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ $data['total'] }}</td>
            <td>{{ $data['average'] }}</td>
            <td class="grade-{{ $data['overall_grade'] }}">{{ $data['overall_grade'] }}</td>
            <td>{{ $data['position'] }} of {{ $data['total_students'] }}</td>
            <td>{{ $data['remark'] ?? '-' }}</td>
        </tr>
    </tbody>
</table>

<div class="comment-box-label">Teacher's Comment:</div>
<div class="comment-box"></div>

<table class="footer-table">
    <tr>
        <td style="width: 33%;" class="sign-dots">...........................................</td>
        <td style="width: 34%;"></td>
        <td style="width: 33%;" class="sign-dots">...........................................</td>
    </tr>
    <tr>
        <td class="sign-label">
            CLASS TEACHER SIGN.
            @if(!empty($data['teacher_name']))
                <br><span style="font-size: 7.5pt; font-weight: normal;">{{ $data['teacher_name'] }}</span>
            @endif
        </td>
        <td class="stamp-label">
            {{ strtoupper($data['school_name']) }}<br>OFFICIAL STAMP
        </td>
        <td class="sign-label">HEAD MASTER SIGN.</td>
    </tr>
    <tr>
        <td class="date-label">Date: {{ $data['report_date'] }}</td>
        <td></td>
        <td class="date-label">Date: {{ $data['report_date'] }}</td>
    </tr>
</table>

</body>
</html>
