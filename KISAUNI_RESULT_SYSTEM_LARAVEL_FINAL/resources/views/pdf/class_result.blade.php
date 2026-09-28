<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Class Result Sheet</title>
<style>
    @page {
        margin: 10mm;
        size: a4 landscape;
    }
    body {
        font-family: 'Helvetica', Arial, sans-serif;
        color: #1b2a4e;
        margin: 0;
        padding: 0;
        font-size: 8.5pt;
    }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    .school-title {
        font-size: 16pt;
        font-weight: bold;
        color: #1b2a4e;
        text-align: center;
        margin: 0 0 2px 0;
        text-transform: uppercase;
    }
    .sheet-subtitle {
        font-size: 11.5pt;
        font-weight: bold;
        color: #1b2a4e;
        text-align: center;
        margin: 0 0 4px 0;
    }
    .badge-container {
        text-align: center;
        margin-bottom: 6px;
    }
    .badge {
        display: inline-block;
        background-color: #1b2a4e;
        color: #ffffff;
        font-weight: bold;
        font-size: 9pt;
        padding: 3px 14px;
        border-radius: 3px;
    }
    .info-strip {
        width: 100%;
        margin-top: 4px;
        margin-bottom: 4px;
    }
    .info-strip td {
        font-size: 9pt;
        padding: 2px 0;
    }
    .divider {
        border-top: 1.2pt solid #1b2a4e;
        margin: 4px 0 8px 0;
    }
    .result-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 15px;
    }
    .result-table th {
        background-color: #1b2a4e;
        color: #ffffff;
        font-size: 7.2pt;
        font-weight: bold;
        padding: 4px 2px;
        text-align: center;
        border: 0.6pt solid #cccccc;
        text-transform: uppercase;
    }
    .result-table td {
        padding: 3px 2px;
        font-size: 8pt;
        border: 0.6pt solid #cccccc;
        text-align: center;
    }
    .result-table tr:nth-child(even) td {
        background-color: #f7f8fb;
    }
    .text-left { text-align: left !important; padding-left: 4px !important; }
    .grade-A { color: #16a34a; font-weight: bold; }
    .grade-B { color: #2563eb; font-weight: bold; }
    .grade-C { color: #b45309; font-weight: bold; }
    .grade-D { color: #ea580c; font-weight: bold; }
    .grade-E { color: #dc2626; font-weight: bold; }
    .grade-- { color: #6c757d; font-weight: bold; }

    .footer-table {
        width: 100%;
        margin-top: 15px;
    }
    .footer-table td {
        text-align: center;
        vertical-align: middle;
        font-size: 8.5pt;
    }
    .sign-dots {
        color: #1b2a4e;
        font-weight: bold;
    }
    .sign-label {
        color: #1b2a4e;
        font-weight: bold;
        font-size: 8.5pt;
        margin-top: 3px;
    }
    .stamp-label {
        color: #8891a8;
        font-size: 7.5pt;
        line-height: 1.2;
    }
    .date-label {
        color: #666666;
        font-size: 8pt;
        margin-top: 3px;
    }
</style>
</head>
<body>

<table>
    <tr>
        <td style="width: 65px; text-align: left; vertical-align: top;">
            @if(!empty($logoBase64))
                <img src="{{ $logoBase64 }}" style="width: 60px; height: 60px; object-fit: contain;">
            @endif
        </td>
        <td style="text-align: center; vertical-align: top;">
            <div class="school-title">{{ strtoupper($data['school_name']) }}</div>
            <div class="sheet-subtitle">CLASS RESULT SHEET - {{ strtoupper($data['class_name']) }}</div>
            <div class="badge-container">
                <span class="badge">{{ strtoupper($data['exam_type']) }} - {{ $data['academic_year'] }}</span>
            </div>
        </td>
    </tr>
</table>

<table class="info-strip">
    <tr>
        <td style="width: 22%;"><b>Class Teacher:</b> {{ $data['teacher_name'] ?? '-' }}</td>
        <td style="width: 18%;"><b>Students:</b> {{ $data['total_students'] }}</td>
        <td style="width: 20%;"><b>Class Average:</b> {{ $data['class_average'] }}</td>
        <td style="width: 20%;"><b>Class Grade:</b> <span class="grade-{{ $data['class_grade'] }}">{{ $data['class_grade'] }}</span></td>
        <td style="width: 20%;"><b>Class Position:</b> <span class="grade-{{ $data['class_grade'] }}">{{ $data['class_position'] ?? '-' }}</span></td>
    </tr>
</table>

<div class="divider"></div>

<table class="result-table">
    <thead>
        <tr>
            <th style="width: 3%;">S/N</th>
            <th style="width: 10%;">REG NO</th>
            <th style="width: 16%; text-align: left; padding-left: 4px;">NAME</th>
            @foreach($data['subjects'] as $sub)
                <th>{{ \App\Services\PdfReportService::shortSubjectLabel($sub) }}</th>
            @endforeach
            <th style="width: 6%;">TOTAL</th>
            <th style="width: 6%;">AVERAGE</th>
            <th style="width: 5%;">GRADE</th>
            <th style="width: 6%;">POSITION</th>
            <th style="width: 11%;">REMARK</th>
        </tr>
    </thead>
    <tbody>
        @foreach($data['rows'] as $i => $row)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $row['reg_no'] ?? '-' }}</td>
            <td class="text-left">{{ strtoupper($row['name']) }}</td>
            @foreach($row['scores'] as $score)
                <td>{{ $score !== null ? $score : '-' }}</td>
            @endforeach
            <td>{{ $row['total'] !== null ? $row['total'] : '-' }}</td>
            <td>{{ $row['average'] !== null ? $row['average'] : '-' }}</td>
            <td class="grade-{{ $row['grade'] }}">{{ $row['grade'] }}</td>
            <td>{{ $row['position'] !== null ? $row['position'] : '-' }}</td>
            <td>{{ $row['remark'] ?? '-' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>

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
