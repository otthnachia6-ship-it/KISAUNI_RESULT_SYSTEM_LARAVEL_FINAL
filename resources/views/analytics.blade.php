@extends('base')
@section('page_title', 'Performance Analytics')
@section('content')

@if($view_scope === 'school')
    <x-page_header icon="bi-graph-up-arrow" title="Performance Analytics" subtitle="School-wide subject and grade performance across all classes" />
@elseif($cls)
    <x-page_header icon="bi-graph-up-arrow" title="Performance Analytics" :subtitle="$cls->name . ' - subject and grade performance'" />
@else
    <x-page_header icon="bi-graph-up-arrow" title="Performance Analytics" subtitle="Track class and subject performance across exams" />
@endif

<form method="GET" action="{{ route('performance_analytics') }}" class="mb-3 d-flex flex-wrap gap-2">
    <select name="exam_id" class="form-select form-select-sm" style="max-width:260px;" onchange="this.form.submit()">
        @if($exams->isNotEmpty() && !$exams->contains('id', $exam_id))
        <option value="" selected disabled>Select examination...</option>
        @endif
        @foreach($exams as $e)
        <option value="{{ $e->id }}" @if($e->id == $exam_id) selected @endif>{{ $e->exam_type }} - {{ $e->academic_year }}</option>
        @endforeach
    </select>

    @if($session_user->role === $ROLE_HEADMASTER)
    <select name="class_id" class="form-select form-select-sm" style="max-width:220px;" onchange="this.form.submit()">
        <option value="all" @if(!$class_id) selected @endif>All Classes (School-wide)</option>
        @foreach($classes as $c)
        <option value="{{ $c->id }}" @if($c->id == $class_id) selected @endif>{{ $c->name }}</option>
        @endforeach
    </select>
    @endif
</form>

@if(!empty($empty_state))
<div class="alert alert-info d-flex align-items-start gap-2" role="status">
    <i class="bi bi-info-circle fs-5"></i>
    <div>
        <strong>{{ $empty_state['title'] }}</strong>
        <div>{{ $empty_state['message'] }}</div>
    </div>
</div>

@elseif(empty($analytics) || empty($analytics['student_count']))
<div class="alert alert-info d-flex align-items-start gap-2" role="status">
    <i class="bi bi-info-circle fs-5"></i>
    <div>There is not enough data to generate analytics for this selection.</div>
</div>

@else
@foreach(($notices ?? []) as $notice)
<div class="alert alert-warning py-2 mb-2" role="status"><i class="bi bi-exclamation-triangle me-1"></i>{{ $notice }}</div>
@endforeach
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card p-3 h-100 text-center">
            <span class="stat-label-chip stat-label-chip--blue">
                @if($view_scope === 'school') SCHOOL AVERAGE @else CLASS AVERAGE @endif
            </span>
            <div style="font-size:2rem; font-weight:700;">{{ !is_null($analytics['class_average'] ?? null) ? $analytics['class_average'] : '-' }}</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3 h-100 text-center">
            <span class="stat-label-chip stat-label-chip--green">AVERAGE GRADE</span>
            <div><span class="grade-chip grade-chip-{{ $analytics['class_grade'] }}" style="font-size:1rem;">{{ $analytics['class_grade'] }}</span></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3 h-100 text-center">
            <span class="stat-label-chip stat-label-chip--amber">STUDENTS WITH MARKS</span>
            <div style="font-size:2rem; font-weight:700;">{{ $analytics['student_count'] }}</div>
        </div>
    </div>
    @if($view_scope === 'school')
    <div class="col-md-6">
        <div class="card p-3 h-100 text-center">
            <span class="stat-label-chip" style="background:#dcfce7; color:#16a34a;">HIGHEST AVERAGE</span>
            <div style="font-size:1.6rem; font-weight:700;">{{ !is_null($analytics['highest_average'] ?? null) ? $analytics['highest_average'] : '-' }}</div>
            @if(!empty($analytics['highest_average_class']))<div class="text-muted" style="font-size:.85rem;">{{ $analytics['highest_average_class'] }}</div>@endif
        </div>
    </div>
    <div class="col-md-6">
        <div class="card p-3 h-100 text-center">
            <span class="stat-label-chip" style="background:#fee2e2; color:#dc2626;">LOWEST AVERAGE</span>
            <div style="font-size:1.6rem; font-weight:700;">{{ !is_null($analytics['lowest_average'] ?? null) ? $analytics['lowest_average'] : '-' }}</div>
            @if(!empty($analytics['lowest_average_class']))<div class="text-muted" style="font-size:.85rem;">{{ $analytics['lowest_average_class'] }}</div>@endif
        </div>
    </div>
    @endif
</div>

@if(!empty($trend) && count($trend) > 1)
<div class="card p-3 mb-3">
    <span class="stat-label-chip stat-label-chip--blue"><i class="bi bi-graph-up me-1"></i>TREND - LAST {{ count($trend) }} EXAMS</span>
    <div class="position-relative mt-2" style="height:220px;">
        <canvas id="trendChart"></canvas>
    </div>
</div>
@endif

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card p-3 h-100">
            <span class="stat-label-chip stat-label-chip--amber"><i class="bi bi-trophy me-1"></i>TOP 3 SUBJECTS @if($view_scope === 'school') - SCHOOL-WIDE @endif</span>
            @if(!empty($analytics['top_subjects']))
            <div class="d-flex flex-column gap-2 mt-2">
                @foreach($analytics['top_subjects'] as $s)
                <div class="border rounded p-2 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small">#{{ $loop->iteration }}</span>
                        <span class="fw-semibold ms-1">{{ $s['name'] }}</span>
                        <div class="text-muted small">{{ $s['average'] }}% average</div>
                    </div>
                    <span class="grade-chip grade-chip-{{ $s['grade'] }}">{{ $s['grade'] }}</span>
                </div>
                @endforeach
            </div>
            @else
            <p class="text-muted mb-0 mt-2">Not enough marks recorded yet.</p>
            @endif
        </div>
    </div>
    <div class="col-md-6">
        <div class="card p-3 h-100">
            <span class="stat-label-chip" style="background:#fee2e2; color:#dc2626;"><i class="bi bi-flag me-1"></i>SUBJECTS NEEDING ATTENTION @if($view_scope === 'school') - SCHOOL-WIDE @endif</span>
            @if(!empty($analytics['bottom_subjects']))
            <div class="d-flex flex-column gap-2 mt-2">
                @foreach($analytics['bottom_subjects'] as $s)
                <div class="border rounded p-2 d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted small">#{{ $loop->iteration }}</span>
                        <span class="fw-semibold ms-1">{{ $s['name'] }}</span>
                        <div class="text-muted small">{{ $s['average'] }}% average</div>
                    </div>
                    <span class="grade-chip grade-chip-{{ $s['grade'] }}">{{ $s['grade'] }}</span>
                </div>
                @endforeach
            </div>
            @else
            <p class="text-muted mb-0 mt-2">Not enough subjects to compare yet.</p>
            @endif
        </div>
    </div>
</div>

@if($view_scope === 'class' && !empty($analytics['subject_stats']))
<div class="card p-3 mb-3">
    <span class="stat-label-chip stat-label-chip--purple"><i class="bi bi-table me-1"></i>GRADE DISTRIBUTION BY SUBJECT</span>
    <div class="table-responsive mt-2">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Subject</th>
                    @foreach(['A', 'B', 'C', 'D', 'E'] as $g)
                    <th class="text-center"><span class="grade-chip grade-chip-{{ $g }}">{{ $g }}</span></th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($analytics['subject_stats'] as $s)
                <tr>
                    <td class="fw-semibold">{{ $s['name'] }}</td>
                    @foreach(['A', 'B', 'C', 'D', 'E'] as $g)
                    @php $cnt = (int) ($s['grade_counts'][$g] ?? 0); @endphp
                    <td class="text-center">
                        <span class="grade-count-circle grade-dot-{{ $g }}{{ $cnt === 0 ? ' is-zero' : '' }}"
                              title="Grade {{ $g }}: {{ $cnt }} student(s)">{{ $cnt }}</span>
                    </td>
                    @endforeach
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

@if($view_scope === 'school' && !empty($analytics['per_class']))
<div class="card p-3 mb-3">
    <span class="stat-label-chip stat-label-chip--purple"><i class="bi bi-grid-3x3-gap me-1"></i>PER CLASS BREAKDOWN - RANKED</span>
    <div class="row g-3 mt-1">
        @foreach($analytics['per_class'] as $pc)
        <div class="col-md-6 col-lg-4">
            <div class="border rounded p-3 h-100">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="d-flex align-items-center gap-2">
                        <span class="rank-badge @if($pc['position'] == 1) rank-badge--gold @endif">#{{ $pc['position'] }}</span>
                        <span class="fw-semibold">{{ $pc['cls']->name }}</span>
                    </span>
                    <span class="grade-chip grade-chip-{{ $pc['class_grade'] }}">{{ $pc['class_grade'] }}</span>
                </div>
                <table class="table table-sm mb-2">
                    <tr><td class="text-muted">Average</td><td class="text-end fw-semibold">{{ $pc['class_average'] }}</td></tr>
                    <tr><td class="text-muted">Students</td><td class="text-end fw-semibold">{{ $pc['student_count'] }}</td></tr>
                    <tr><td class="text-muted">Top Subject</td>
                        <td class="text-end fw-semibold">{{ !empty($pc['top_subjects']) ? $pc['top_subjects'][0]['name'] : '-' }}</td></tr>
                </table>
                <a href="{{ route('performance_analytics', ['exam_id' => $exam_id, 'class_id' => $pc['cls']->id]) }}" class="btn btn-sm btn-outline-primary w-100">
                    View Full Analytics
                </a>
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif

@endif
@endsection

@section('scripts')
@if(!empty($trend) && count($trend) > 1)
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const trendCtx = document.getElementById("trendChart");
    if (trendCtx) {
        new Chart(trendCtx, {
            type: "line",
            data: {
                labels: {!! json_encode(array_column($trend, 'label')) !!},
                datasets: [{
                    label: "Average",
                    data: {!! json_encode(array_column($trend, 'average')) !!},
                    borderColor: "#2563eb",
                    backgroundColor: "rgba(37,99,235,0.1)",
                    borderWidth: 2,
                    tension: 0.3,
                    fill: true
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { min: 0, max: 100 } }
            }
        });
    }
});
</script>
@endif
@endsection
