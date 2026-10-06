<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, minimum-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', $school_name) - Result System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="{{ asset('css/style.css') }}?v={{ $asset_version('css/style.css') }}" rel="stylesheet">
<script>
(function () {
    try {
        if (localStorage.getItem('kps-theme') === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        }
    } catch (e) {}
})();
</script>
</head>
<body>
@if($session_user)
<div class="d-flex" data-idle-timeout-minutes="{{ $IDLE_TIMEOUT_MINUTES }}" id="appShell">
    <div id="sidebarBackdrop" class="sidebar-backdrop"></div>
    <nav class="sidebar" style="width:250px;">
        <div class="brand d-flex align-items-center">
            <img src="{{ $logo_url($logo_path) }}" alt="logo">
            <div>
                <div style="font-weight:700; font-size:.95rem; line-height:1.1;">{{ $school_name }}</div>
                <small style="opacity:.7;">Result System</small>
            </div>
        </div>
        <div class="nav flex-column mt-2">
            <a class="nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}">
                <i class="bi bi-speedometer2 me-2"></i>Dashboard
            </a>

            <div class="nav-section">Students</div>
            <a class="nav-link {{ request()->routeIs('students', 'add_student', 'edit_student') ? 'active' : '' }}" href="{{ route('students') }}">
                <i class="bi bi-people me-2"></i>Students
            </a>
            @if($session_user->role == $ROLE_HEADMASTER)
            <a class="nav-link {{ request()->routeIs('classes') ? 'active' : '' }}" href="{{ route('classes') }}">
                <i class="bi bi-door-open me-2"></i>Classes
            </a>
            @endif
            <a class="nav-link {{ request()->routeIs('promote_students') ? 'active' : '' }}" href="{{ route('promote_students') }}">
                <i class="bi bi-arrow-up-circle me-2"></i>Promote Students
            </a>
            <a class="nav-link {{ request()->routeIs('subjects') ? 'active' : '' }}" href="{{ route('subjects') }}">
                <i class="bi bi-journal-bookmark me-2"></i>Subjects
            </a>
            <a class="nav-link {{ request()->routeIs('examinations') ? 'active' : '' }}" href="{{ route('examinations') }}">
                <i class="bi bi-pencil-square me-2"></i>Examinations
            </a>

            <div class="nav-section">Results</div>
            <a class="nav-link {{ request()->routeIs('marks_select', 'marks') ? 'active' : '' }}" href="{{ route('marks_select') }}">
                <i class="bi bi-input-cursor-text me-2"></i>Marks Entry
            </a>
            @if($session_user->role == $ROLE_HEADMASTER)
            <a class="nav-link {{ request()->routeIs('results', 'review_results') ? 'active' : '' }}" href="{{ route('results') }}">
                <i class="bi bi-check2-square me-2"></i>Review &amp; Approval
            </a>
            <a class="nav-link {{ request()->routeIs('headmaster_overview') ? 'active' : '' }}" href="{{ route('headmaster_overview') }}">
                <i class="bi bi-bar-chart-line me-2"></i>Headmaster Overview
            </a>
            @endif
            <a class="nav-link {{ request()->routeIs('performance_analytics') ? 'active' : '' }}" href="{{ route('performance_analytics') }}">
                <i class="bi bi-graph-up-arrow me-2"></i>Performance Analytics
            </a>
            <a class="nav-link {{ request()->routeIs('reports', 'student_report') ? 'active' : '' }}" href="{{ route('reports') }}">
                <i class="bi bi-file-earmark-text me-2"></i>Reports
            </a>
            <a class="nav-link {{ request()->routeIs('records') ? 'active' : '' }}" href="{{ route('records') }}">
                <i class="bi bi-archive me-2"></i>Exam Records / Search
            </a>

            @if($session_user->role == $ROLE_HEADMASTER)
            <div class="nav-section">Admin</div>
            <a class="nav-link {{ request()->routeIs('users', 'add_user', 'edit_user') ? 'active' : '' }}" href="{{ route('users') }}">
                <i class="bi bi-person-badge me-2"></i>Users / Accounts
            </a>
            <a class="nav-link {{ request()->routeIs('settings_page') ? 'active' : '' }}" href="{{ route('settings_page') }}">
                <i class="bi bi-gear me-2"></i>Settings
            </a>
            <a class="nav-link {{ request()->routeIs('audit_logs') ? 'active' : '' }}" href="{{ route('audit_logs') }}">
                <i class="bi bi-shield-check me-2"></i>Audit Log
            </a>
            @endif

            <div class="nav-section">Account</div>
            <a class="nav-link" href="{{ route('profile') }}"><i class="bi bi-person-circle me-2"></i>Profile</a>
            <a class="nav-link" href="{{ route('change_password') }}"><i class="bi bi-key me-2"></i>Change Password</a>
            <a class="nav-link nav-logout" href="{{ route('logout') }}"><i class="bi bi-box-arrow-right me-2"></i>Logout</a>
        </div>
    </nav>

    <main class="flex-grow-1" style="min-width:0;">
        <div class="topbar d-flex justify-content-between align-items-center no-print">
            <button id="sidebarToggle" class="btn btn-sm btn-outline-secondary d-md-none"><i class="bi bi-list"></i></button>
            <div class="fw-semibold">@yield('page_title')</div>
            <div class="d-flex align-items-center gap-2">
                <button id="themeToggle" class="btn btn-sm btn-outline-secondary" title="Badilisha Dark/Light Mode">
                    <i class="bi bi-moon-stars"></i>
                </button>
                <div class="text-end">
                    <div class="fw-semibold" style="font-size:.9rem;">{{ $session_user->full_name }}</div>
                    <small class="role-pill">
                        @if($session_user->role == $ROLE_HEADMASTER){{ $session_user->title ?: 'Headmaster' }}@else Class Teacher @endif
                    </small>
                </div>
                @php $photo = $user_photo_url($session_user->photo_path); @endphp
                @if($photo)
                <img src="{{ $photo }}" alt="{{ $session_user->full_name }}" class="user-avatar-img">
                @else
                @php $parts = explode(' ', trim($session_user->full_name)); @endphp
                <div class="user-avatar">{{ strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : '')) }}</div>
                @endif
            </div>
        </div>

        <div id="toastStack" class="toast-stack no-print">
            @foreach (['success', 'danger', 'warning', 'info'] as $msgType)
                @if(session()->has($msgType))
                <div class="app-toast app-toast-{{ $msgType }}">
                    <i class="bi @if($msgType=='success' || $msgType=='info') bi-check-circle-fill @elseif($msgType=='danger') bi-x-circle-fill @else bi-exclamation-triangle-fill @endif"></i>
                    <span>{!! session($msgType) !!}</span>
                    <button type="button" class="app-toast-close" onclick="this.parentElement.remove()">&times;</button>
                </div>
                @endif
            @endforeach
        </div>

        <div class="p-3 p-md-4 page-enter">
            @yield('content')
        </div>
    </main>
</div>

<!-- Reusable app-styled confirmation modal -->
<div class="modal fade" id="appConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content app-confirm-modal">
      <div class="modal-header">
        <h5 class="modal-title d-flex align-items-center gap-2" id="appConfirmTitle">
          <i class="bi bi-exclamation-triangle-fill"></i><span>Confirm</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="appConfirmBody"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger" id="appConfirmOkBtn">Yes, continue</button>
      </div>
    </div>
  </div>
</div>

<!-- Idle-timeout warning modal -->
<div class="modal fade" id="idleWarningModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content app-confirm-modal">
      <div class="modal-header">
        <h5 class="modal-title d-flex align-items-center gap-2">
          <i class="bi bi-hourglass-split text-warning"></i><span>Still there?</span>
        </h5>
      </div>
      <div class="modal-body">
        For your security, you'll be logged out from inactivity in
        <strong id="idleCountdownSeconds">20</strong> seconds.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="idleStayBtn">
            <i class="bi bi-check-lg me-1"></i>Stay logged in
        </button>
      </div>
    </div>
  </div>
</div>
@else
    @yield('guest_content')
@endif

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="{{ asset('js/script.js') }}?v={{ $asset_version('js/script.js') }}"></script>
@yield('scripts')
</body>
</html>
