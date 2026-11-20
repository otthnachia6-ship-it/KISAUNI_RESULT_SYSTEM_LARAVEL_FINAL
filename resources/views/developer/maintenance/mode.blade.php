@extends('base')
@section('page_title', 'Maintenance Mode')
@section('content')
<x-page_header icon="bi-cone-striped" title="Maintenance Mode" subtitle="Temporarily close the system to school users while you update or repair it" />

<div class="card p-4 mb-3" style="max-width:760px;">
    <div class="d-flex align-items-center gap-2 mb-2">
        <span class="text-muted">Current state:</span>
        @if($active)
            <span class="dev-status dev-status-warning"><i class="bi bi-cone-striped"></i>ON - school users see the maintenance page</span>
        @else
            <span class="dev-status dev-status-healthy"><i class="bi bi-check-circle-fill"></i>OFF - system open to everyone</span>
        @endif
    </div>

    @if($active)
        <p class="small text-muted mb-3">
            Headmaster, Administrator and Class Teachers cannot use the system now.
            @if($has_bypass) This browser still has full access (for up to 12 hours). @else This browser does <strong>not</strong> have full access yet. @endif
        </p>
        <div class="d-flex gap-2 flex-wrap">
            <form method="POST" action="{{ route('developer.maintenance.mode.off') }}"
                  onsubmit="return appConfirm(this, 'Switch maintenance mode OFF and open the system to everyone again?', {okClass: 'btn-success', okText: 'Switch OFF', icon: 'bi-unlock', title: 'Open the system'});">
                @csrf
                <button class="btn btn-success"><i class="bi bi-unlock me-1"></i>Switch OFF (open the system)</button>
            </form>
            @unless($has_bypass)
            <form method="POST" action="{{ route('developer.maintenance.mode.bypass') }}">
                @csrf
                <button class="btn btn-outline-secondary"><i class="bi bi-key me-1"></i>Give this browser full access</button>
            </form>
            @endunless
        </div>
    @else
        <form method="POST" action="{{ route('developer.maintenance.mode.on') }}"
              onsubmit="return appConfirm(this, 'Switch maintenance mode ON? Every school user will be stopped from using the system (unsaved work in their open pages may be lost) until you switch it off.', {okClass: 'btn-warning', okText: 'Switch ON', icon: 'bi-cone-striped', title: 'Close the system'});">
            @csrf
            <label class="form-label small mb-1">Page shown to school users</label>
            @foreach($pages as $key => [$view, $label])
            <div class="form-check">
                <input class="form-check-input" type="radio" name="page" id="page_{{ $key }}" value="{{ $key }}" @checked($key === 'updating')>
                <label class="form-check-label" for="page_{{ $key }}">{{ $label }}</label>
            </div>
            @endforeach
            <button class="btn btn-warning mt-3"><i class="bi bi-cone-striped me-1"></i>Switch ON (close the system)</button>
        </form>
    @endif
</div>

<div class="card p-4" style="max-width:760px;">
    <h6>How it works</h6>
    <ul class="small text-muted mb-0">
        <li>School users see the maintenance page (English and Kiswahili). It reloads itself every minute.</li>
        <li>You keep full access in this browser. The Developer Control Center (<code>/developer</code>) stays open, so you can always sign in at <code>/developer/login</code> and switch maintenance off, even from another browser.</li>
        <li>School data is not changed. Switching on and off is recorded in the audit log.</li>
        <li>From the server you can also use <code>php artisan up</code> to open the system.</li>
    </ul>
</div>
@endsection
