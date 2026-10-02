@extends('base')
@section('page_title', 'Dashboard')
@section('content')

<div class="dash-hero">
    <div>
        <h4 class="mb-1">Welcome back, {{ $session_user->full_name }} 👋</h4>
        <div class="dash-hero-date" id="heroDate">{{ $school_name }} &middot; Academic Year {{ $academic_year }}</div>
    </div>
    @if(($stats['pending'] ?? 0) > 0)
    <div class="dash-hero-pending">
        <div class="n">{{ $stats['pending'] }}</div>
        <div class="l">Pending Review</div>
    </div>
    @endif
</div>

@if($my_class)
<div class="alert alert-success">
    <i class="bi bi-door-open me-1"></i>
    You are assigned to <strong>{{ $my_class->name }}</strong> — {{ $my_class_students }} students.
    <a href="{{ route('marks_select') }}" class="alert-link">Enter marks &rarr;</a>
</div>
@endif

@foreach($returned_results as $rr)
<div class="alert alert-danger">
    <div class="d-flex align-items-start gap-2">
        <i class="bi bi-bell-fill mt-1"></i>
        <div class="flex-grow-1">
            <strong>Your {{ $my_class->name ?? '' }} results ({{ $rr->exam_type }} - {{ $rr->academic_year }}) were returned for correction by the Headmaster.</strong>
            @if($rr->remarks)
            <div class="mt-1"><em>Feedback:</em> "{{ $rr->remarks }}"</div>
            @endif
            <div class="mt-2">
                <a href="{{ route('marks', ['exam_id' => $rr->exam_id_val, 'class_id' => $my_class->id ?? 0]) }}" class="btn btn-sm btn-danger">
                    <i class="bi bi-arrow-repeat me-1"></i>Review &amp; Correct
                </a>
            </div>
        </div>
    </div>
</div>
@endforeach

@if($my_class_analytics && $my_class_latest_exam)
<div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0"><i class="bi bi-graph-up-arrow me-1"></i>{{ $my_class->name }} Performance - {{ $my_class_latest_exam->exam_type }} ({{ $my_class_latest_exam->academic_year }})</h6>
        <a href="{{ route('performance_analytics', ['exam_id' => $my_class_latest_exam->id]) }}" class="btn btn-sm btn-outline-primary">View Full Analytics</a>
    </div>
    <div class="row g-3">
        <div class="col-6 col-md-3">
            <span class="stat-label-chip stat-label-chip--blue">CLASS AVERAGE</span>
            <div style="font-size:1.4rem; font-weight:700;">{{ !is_null($my_class_analytics['class_average'] ?? null) ? $my_class_analytics['class_average'] : '-' }}</div>
        </div>
        <div class="col-6 col-md-3">
            <span class="stat-label-chip stat-label-chip--green">AVERAGE GRADE</span>
            <div><span class="grade-chip grade-chip-{{ $my_class_analytics['class_grade'] }}">{{ $my_class_analytics['class_grade'] }}</span></div>
        </div>
        <div class="col-12 col-md-6">
            <span class="stat-label-chip stat-label-chip--amber">TOP SUBJECT{{ count($my_class_analytics['top_subjects'] ?? []) > 1 ? 'S' : '' }}</span>
            <div>
            @forelse($my_class_analytics['top_subjects'] ?? [] as $s)
            <span class="badge bg-light text-dark border me-1">{{ $s['name'] }} ({{ $s['average'] }}%)</span>
            @empty
            <span class="text-muted">-</span>
            @endforelse
            </div>
        </div>
    </div>
</div>
@endif

@if(!empty($class_ranking))
<div class="card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <span class="stat-label-chip stat-label-chip--purple"><i class="bi bi-bar-chart-steps me-1"></i>CLASS RANKING - {{ $my_class_latest_exam->exam_type }} ({{ $my_class_latest_exam->academic_year }})</span>
        <a href="{{ route('performance_analytics', ['exam_id' => $my_class_latest_exam->id]) }}" class="btn btn-sm btn-outline-primary">View My Class Analytics</a>
    </div>

    @if($leading_class && $my_class_rank && ($leading_class['cls']->id ?? null) != ($my_class->id ?? null))
    <p class="text-muted small mb-2">
        <i class="bi bi-trophy me-1"></i><strong>{{ $leading_class['cls']->name }}</strong> is currently leading (average {{ $leading_class['class_average'] }}).
        Your class, <strong>{{ $my_class->name }}</strong>, is position <strong>#{{ $my_class_rank['position'] }}</strong> of {{ $class_ranking_total }}.
    </p>
    @elseif($leading_class && ($leading_class['cls']->id ?? null) == ($my_class->id ?? null))
    <p class="text-muted small mb-2">
        <i class="bi bi-trophy me-1"></i><strong>{{ $my_class->name }}</strong> is currently leading the school (position #1 of {{ $class_ranking_total }}) 🎉
    </p>
    @endif

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Class</th>
                    <th class="text-end">Average</th>
                    <th class="text-center">Grade</th>
                    <th>Top Subject</th>
                </tr>
            </thead>
            <tbody>
                @foreach($class_ranking as $e)
                <tr @if(($e['cls']->id ?? null) == ($my_class->id ?? null)) class="table-active fw-semibold" @endif>
                    <td><span class="rank-badge @if($e['position'] == 1) rank-badge--gold @endif">#{{ $e['position'] }}</span></td>
                    <td>{{ $e['cls']->name }}@if(($e['cls']->id ?? null) == ($my_class->id ?? null)) <span class="badge bg-primary ms-1">Your Class</span>@endif</td>
                    <td class="text-end">{{ $e['class_average'] }}</td>
                    <td class="text-center"><span class="grade-chip grade-chip-{{ $e['class_grade'] }}">{{ $e['class_grade'] }}</span></td>
                    <td>{{ !empty($e['top_subjects']) ? $e['top_subjects'][0]['name'] : '-' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<!-- ---------- Top stats: the 6 headline numbers, all live counts ---------- -->
<div class="row g-3 mb-4 dashboard-stats">
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card stat-card stat-card--blue p-3">
            <div class="stat-icon stat-icon-blue mb-2"><i class="bi bi-people"></i></div>
            <div class="stat-value">{{ $stats['students'] }}</div>
            <div class="text-muted small">Students</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card stat-card stat-card--green p-3">
            <div class="stat-icon stat-icon-green mb-2"><i class="bi bi-mortarboard"></i></div>
            <div class="stat-value">{{ $stats['teachers'] }}</div>
            <div class="text-muted small">Teachers</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card stat-card stat-card--navy p-3">
            <div class="stat-icon stat-icon-navy mb-2"><i class="bi bi-door-open"></i></div>
            <div class="stat-value">{{ $stats['classes'] }}</div>
            <div class="text-muted small">Classes</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card stat-card stat-card--cyan p-3">
            <div class="stat-icon stat-icon-cyan mb-2"><i class="bi bi-pencil-square"></i></div>
            <div class="stat-value">{{ $stats['exams'] }}</div>
            <div class="text-muted small">Examinations</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card stat-card stat-card--orange p-3">
            <div class="stat-icon stat-icon-orange mb-2"><i class="bi bi-hourglass-split"></i></div>
            <div class="stat-value">{{ $stats['pending'] }}</div>
            <div class="text-muted small">Pending Review</div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card stat-card stat-card--green p-3">
            <div class="stat-icon stat-icon-green mb-2"><i class="bi bi-check2-circle"></i></div>
            <div class="stat-value">{{ $stats['approved'] }}</div>
            <div class="text-muted small">Approved</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- ---------- Recent Activities: real audit log, restyled ---------- -->
    @if($session_user->role === $ROLE_HEADMASTER)
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="mb-0"><i class="bi bi-clock-history me-1"></i>Recent Activities</h6>
                <a href="{{ route('audit_logs') }}" class="small">View all &rarr;</a>
            </div>
            <div class="activity-list">
            @forelse($recent_logs as $log)
                @php
                    $a = is_array($log) ? ($log['action'] ?? '') : $log->action;
                    $lUser = is_array($log) ? ($log['username'] ?? '') : $log->username;
                    $lDetails = is_array($log) ? ($log['details'] ?? '') : $log->details;
                    $lTime = is_array($log) ? ($log['created_at'] ?? '') : $log->created_at;
                    if (str_contains($a, 'DELETE') || str_contains($a, 'PURGE')) {
                        $icon = 'bi-trash3'; $iconcls = 'icon-red';
                    } elseif (str_contains($a, 'RESTORE') || str_contains($a, 'APPROVE') || str_contains($a, 'PROMOTE')) {
                        $icon = 'bi-check2-circle'; $iconcls = 'icon-green';
                    } elseif (str_contains($a, 'RETURN') || str_contains($a, 'SUBMIT') || str_contains($a, 'REVIEW')) {
                        $icon = 'bi-send-check'; $iconcls = 'icon-orange';
                    } elseif (str_contains($a, 'MARKS')) {
                        $icon = 'bi-input-cursor-text'; $iconcls = 'icon-blue';
                    } elseif (str_contains($a, 'STUDENT')) {
                        $icon = 'bi-people'; $iconcls = 'icon-blue';
                    } elseif (str_contains($a, 'EXAM')) {
                        $icon = 'bi-pencil-square'; $iconcls = 'icon-cyan';
                    } elseif (str_contains($a, 'USER')) {
                        $icon = 'bi-person-badge'; $iconcls = 'icon-purple';
                    } elseif (str_contains($a, 'CLASS')) {
                        $icon = 'bi-door-open'; $iconcls = 'icon-navy';
                    } elseif (str_contains($a, 'SUBJECT')) {
                        $icon = 'bi-journal-bookmark'; $iconcls = 'icon-purple';
                    } elseif (str_contains($a, 'BACKUP')) {
                        $icon = 'bi-cloud-arrow-down'; $iconcls = 'icon-navy';
                    } elseif (str_contains($a, 'SETTINGS')) {
                        $icon = 'bi-gear'; $iconcls = 'icon-navy';
                    } else {
                        $icon = 'bi-info-circle'; $iconcls = 'icon-blue';
                    }
                @endphp
                <div class="activity-row">
                    <div class="activity-icon {{ $iconcls }}"><i class="bi {{ $icon }}"></i></div>
                    <div class="flex-grow-1" style="min-width:0;">
                        <div class="fw-semibold" style="font-size:.86rem;">{{ ucwords(str_replace('_', ' ', strtolower($a))) }}</div>
                        <div class="text-muted small text-truncate">{{ $lUser }}@if($lDetails) &middot; {{ $lDetails }}@endif</div>
                    </div>
                    <div class="text-muted small text-nowrap ms-2">{{ $lTime }}</div>
                </div>
            @empty
                <div class="empty-state py-4">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:40px;height:40px;"><path d="M4 13v6a1 1 0 001 1h14a1 1 0 001-1v-6M4 13l2.5-7A1 1 0 017.4 5h9.2a1 1 0 01.9 1l2.5 7M4 13h5a1 1 0 011 1 2 2 0 002 2 2 2 0 002-2 1 1 0 011-1h5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <div class="msg">No activity yet.</div>
                </div>
            @endforelse
            </div>
        </div>
    </div>
    @endif
</div>

<div class="row g-3">
    <!-- ---------- Students by Class: real per-class counts ---------- -->
    <div class="col-lg-5">
        <div class="card p-3 h-100">
            <h6 class="mb-3"><i class="bi bi-pie-chart me-1"></i>Students by Class</h6>
            @if($stats['students'] == 0)
            <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 13v6a1 1 0 001 1h14a1 1 0 001-1v-6M4 13l2.5-7A1 1 0 017.4 5h9.2a1 1 0 01.9 1l2.5 7M4 13h5a1 1 0 011 1 2 2 0 002 2 2 2 0 002-2 1 1 0 011-1h5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <div class="msg">No active students yet.</div>
            </div>
            @else
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div style="width:180px;height:180px;flex-shrink:0;"><canvas id="classChart"></canvas></div>
                <div class="flex-grow-1">
                @foreach($class_breakdown as $idx => $row)
                    @php
                        $rName = is_array($row) ? ($row['name'] ?? '') : $row->name;
                        $rCount = is_array($row) ? ($row['c'] ?? 0) : $row->c;
                    @endphp
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <span><span class="legend-dot" style="background:{{ $chart_colors[$idx % count($chart_colors)] }};"></span>{{ $rName }}</span>
                        <span class="text-muted small">{{ $rCount }}</span>
                    </div>
                @endforeach
                </div>
            </div>
            @endif
        </div>
    </div>

    <!-- ---------- Quick Actions: real navigation shortcuts only ---------- -->
    <div class="col-lg-7">
        <div class="card p-3 h-100">
            <h6 class="mb-3"><i class="bi bi-lightning-charge me-1"></i>Quick Actions</h6>
            <div class="quick-action-grid">
                <a class="quick-action-tile" href="{{ route('add_student') }}">
                    <div class="qa-icon stat-icon-blue"><i class="bi bi-person-plus"></i></div>Add Student
                </a>
                <a class="quick-action-tile" href="{{ route('marks_select') }}">
                    <div class="qa-icon stat-icon-cyan"><i class="bi bi-input-cursor-text"></i></div>Marks Entry
                </a>
                <a class="quick-action-tile" href="{{ route('reports') }}">
                    <div class="qa-icon stat-icon-purple"><i class="bi bi-file-earmark-text"></i></div>Reports
                </a>
                @if($session_user->role === $ROLE_HEADMASTER)
                <a class="quick-action-tile" href="{{ route('classes') }}">
                    <div class="qa-icon stat-icon-navy"><i class="bi bi-door-open"></i></div>Add Class
                </a>
                <a class="quick-action-tile" href="{{ route('add_user') }}">
                    <div class="qa-icon stat-icon-green"><i class="bi bi-person-badge"></i></div>Add User
                </a>
                <a class="quick-action-tile" href="{{ route('examinations') }}">
                    <div class="qa-icon stat-icon-orange"><i class="bi bi-pencil-square"></i></div>Add Exam
                </a>
                <a class="quick-action-tile" href="{{ route('results') }}">
                    <div class="qa-icon stat-icon-blue"><i class="bi bi-check2-square"></i></div>Review &amp; Approval
                </a>
                @endif
            </div>
        </div>
    </div>
</div>

@endsection

@section('scripts')
<script>
document.addEventListener("DOMContentLoaded", function () {
    const dateEl = document.getElementById("heroDate");
    if (dateEl) {
        const today = new Date().toLocaleDateString("en-GB", { weekday: "long", day: "numeric", month: "long", year: "numeric" });
        dateEl.textContent = today;
    }
});
</script>
@if(!empty($class_breakdown) && $stats['students'] > 0)
@php
    $cbList = is_array($class_breakdown) ? $class_breakdown : $class_breakdown->all();
    $cbNames = array_map(fn($r) => is_array($r) ? ($r['name'] ?? '') : $r->name, $cbList);
    $cbCounts = array_map(fn($r) => is_array($r) ? ($r['c'] ?? 0) : $r->c, $cbList);
@endphp
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const palette = {!! json_encode($chart_colors) !!};
    const classCtx = document.getElementById("classChart");
    if (classCtx) {
        new Chart(classCtx, {
            type: "doughnut",
            data: {
                labels: {!! json_encode($cbNames) !!},
                datasets: [{
                    data: {!! json_encode($cbCounts) !!},
                    backgroundColor: palette,
                    borderWidth: 2,
                    borderColor: "#fff"
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: "68%",
                plugins: { legend: { display: false } }
            }
        });
    }
});
</script>
@endif
@endsection
