{{--
    Developer Control Center navigation: expandable main menus with nested submenus.
    Shown by base.blade.php for the Developer account only. Every link points to a real page;
    access is enforced on the server (middleware 'developer' / 'headmaster'), not by this menu.
--}}
@php
    $mon = fn (string $s) => request()->routeIs('developer.monitoring') && request()->route('section') === $s;

    $devMenu = [
        ['id' => 'main', 'label' => 'Main', 'icon' => 'bi-house', 'items' => [
            ['Dashboard', 'bi-speedometer2', route('developer.dashboard'), request()->routeIs('developer.dashboard')],
        ]],
        ['id' => 'school', 'label' => 'School Management', 'icon' => 'bi-mortarboard', 'items' => [
            ['Students', 'bi-people', route('students'), request()->routeIs('students', 'add_student', 'add_students_bulk', 'edit_student', 'student_history', 'students_upload*', 'promote_students')],
            ['Classes', 'bi-door-open', route('classes'), request()->routeIs('classes', 'class_tools*')],
            ['Subjects', 'bi-journal-bookmark', route('subjects'), request()->routeIs('subjects')],
            ['Examinations', 'bi-pencil-square', route('examinations'), request()->routeIs('examinations')],
            ['Marks Entry & Upload', 'bi-cloud-arrow-up', route('marks_select'), request()->routeIs('marks_select', 'marks', 'marks_upload*')],
            ['Results Review & Approval', 'bi-check2-square', route('results'), request()->routeIs('results', 'review_results', 'headmaster_overview')],
            ['Reports', 'bi-file-earmark-text', route('reports'), request()->routeIs('reports', 'student_report')],
            ['Analytics', 'bi-graph-up-arrow', route('performance_analytics'), request()->routeIs('performance_analytics')],
            ['Student Records', 'bi-archive', route('records'), request()->routeIs('records')],
        ]],
        ['id' => 'users', 'label' => 'User Management', 'icon' => 'bi-person-badge', 'items' => [
            ['All Users', 'bi-people-fill', route('users'), request()->routeIs('users', 'edit_user')],
            ['Account Management', 'bi-person-plus', route('add_user'), request()->routeIs('add_user')],
            ['Login Activity', 'bi-clock-history', route('developer.security.login_activity'), request()->routeIs('developer.security.login_activity')],
        ]],
        ['id' => 'monitoring', 'label' => 'System Monitoring', 'icon' => 'bi-activity', 'items' => [
            ['Application Health', 'bi-heart-pulse', route('developer.monitoring', 'health'), $mon('health')],
            ['Database Monitoring', 'bi-database-check', route('developer.monitoring', 'database'), $mon('database')],
            ['Server Status', 'bi-hdd-rack', route('developer.monitoring', 'server'), $mon('server')],
            ['Storage Monitoring', 'bi-device-hdd', route('developer.monitoring', 'storage'), $mon('storage')],
            ['Error Monitoring', 'bi-bug', route('developer.monitoring', 'errors'), $mon('errors')],
            ['Performance', 'bi-speedometer', route('developer.monitoring', 'performance'), $mon('performance')],
        ]],
        ['id' => 'backup', 'label' => 'Database & Backup', 'icon' => 'bi-database', 'items' => [
            ['Database Overview', 'bi-table', route('developer.backups.database'), request()->routeIs('developer.backups.database')],
            ['Create Backup', 'bi-plus-circle', route('developer.backups.create'), request()->routeIs('developer.backups.create')],
            ['Download Backup', 'bi-download', route('developer.backups.downloads'), request()->routeIs('developer.backups.downloads')],
            ['Backup History', 'bi-list-ul', route('developer.backups.history'), request()->routeIs('developer.backups.history')],
            ['Backup Status', 'bi-check-circle', route('developer.backups.status'), request()->routeIs('developer.backups.status')],
        ]],
        ['id' => 'security', 'label' => 'Security', 'icon' => 'bi-shield-lock', 'items' => [
            ['Security Overview', 'bi-shield-check', route('developer.security.overview'), request()->routeIs('developer.security.overview')],
            ['Login Attempts', 'bi-person-x', route('developer.security.attempts'), request()->routeIs('developer.security.attempts')],
            ['Session Monitoring', 'bi-window-stack', route('developer.security.sessions'), request()->routeIs('developer.security.sessions')],
        ]],
        ['id' => 'activities', 'label' => 'System Activities', 'icon' => 'bi-journal-text', 'items' => [
            ['Audit Logs', 'bi-shield-check', route('audit_logs'), request()->routeIs('audit_logs')],
        ]],
        ['id' => 'maintenance', 'label' => 'Maintenance', 'icon' => 'bi-tools', 'items' => [
            ['Cache Management', 'bi-arrow-repeat', route('developer.maintenance.cache'), request()->routeIs('developer.maintenance.cache')],
            ['System Diagnostics', 'bi-clipboard2-pulse', route('developer.maintenance.diagnostics'), request()->routeIs('developer.maintenance.diagnostics')],
            ['Maintenance Mode', 'bi-cone-striped', route('developer.maintenance.mode'), request()->routeIs('developer.maintenance.mode')],
        ]],
        ['id' => 'settings', 'label' => 'Developer Settings', 'icon' => 'bi-sliders', 'items' => [
            ['My Profile', 'bi-person-circle', route('profile'), request()->routeIs('profile')],
            ['Security Settings', 'bi-key', route('change_password'), request()->routeIs('change_password')],
            ['System Information', 'bi-info-circle', route('developer.system_info'), request()->routeIs('developer.system_info')],
        ]],
    ];
@endphp

<div class="nav flex-column mt-2 dev-nav">
    <div class="nav-section">Developer Dashboard</div>
    @foreach($devMenu as $i => $group)
        @php $open = collect($group['items'])->contains(fn ($it) => $it[3]); @endphp
        <button type="button" class="nav-link dev-group-toggle w-100 text-start border-0 {{ $open ? '' : 'collapsed' }}"
                data-bs-toggle="collapse" data-bs-target="#devGroup-{{ $group['id'] }}"
                aria-expanded="{{ $open ? 'true' : 'false' }}" aria-controls="devGroup-{{ $group['id'] }}">
            <span class="dev-num">{{ $i + 1 }}</span><i class="bi {{ $group['icon'] }} me-2"></i>{{ $group['label'] }}
            <i class="bi bi-chevron-down dev-chevron"></i>
        </button>
        <div class="collapse dev-submenu {{ $open ? 'show' : '' }}" id="devGroup-{{ $group['id'] }}">
            @foreach($group['items'] as [$label, $icon, $url, $active])
            <a class="nav-link {{ $active ? 'active' : '' }}" href="{{ $url }}" @if($active) aria-current="page" @endif>
                <i class="bi {{ $icon }} me-2"></i>{{ $label }}
            </a>
            @endforeach
        </div>
    @endforeach

    <form method="POST" action="{{ route('logout') }}" id="logoutForm" class="m-0">
        @csrf
        <button type="submit" class="nav-link nav-logout w-100 text-start border-0"><span class="dev-num">10</span><i class="bi bi-box-arrow-right me-2"></i>Logout</button>
    </form>
</div>
