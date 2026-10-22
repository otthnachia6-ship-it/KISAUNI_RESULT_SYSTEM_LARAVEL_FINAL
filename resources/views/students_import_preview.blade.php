@extends('base')
@section('page_title', 'Import Students from ZEMS')
@section('content')
<x-page_header :title="'Pupils of ' . $cls->name" subtitle="Tick the pupils who belong to this class, then register them">
    <x-slot:actions>
        <a href="{{ route('students_import') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Choose another file</a>
    </x-slot:actions>
</x-page_header>

<div class="card p-3 mb-3" style="max-width:900px;">
    <ul class="list-unstyled small mb-0">
        <li>
            File: @if($file_std)<strong>STD{{ $file_std }}</strong>@endif
            @if($file_year) &middot; {{ $file_year }}@endif
            &middot; {{ $analysis['total'] }} pupil(s)
        </li>
        @if($analysis['already_here'] > 0)<li>{{ $analysis['already_here'] }} already registered in {{ $cls->name }}</li>@endif
        @if($analysis['already_elsewhere'] > 0)<li>{{ $analysis['already_elsewhere'] }} already registered in other classes (not shown)</li>@endif
        @if(count($analysis['to_link']) > 0)
        <li class="text-primary">{{ count($analysis['to_link']) }} pupil(s) of {{ $cls->name }} will be linked to their ZEMS number by name:
            {{ collect($analysis['to_link'])->pluck('name')->take(8)->implode(', ') }}@if(count($analysis['to_link']) > 8) ...@endif
        </li>
        @endif
        <li><strong>{{ count($analysis['candidates']) }}</strong> pupil(s) can be registered</li>
    </ul>
</div>

@if(count($analysis['candidates']) === 0)
<div class="alert alert-info" style="max-width:900px;">
    Everybody in this file is already registered.
    @if(count($analysis['to_link']) > 0)Press the button below to link the pupils named above.@endif
</div>
<form method="POST" action="{{ route('students_import_confirm') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    @if(count($analysis['to_link']) > 0)<button class="btn btn-success">Link pupils</button>@endif
    <a href="{{ route('students') }}" class="btn btn-outline-secondary">Back to Students</a>
</form>
@else
<form method="POST" action="{{ route('students_import_confirm') }}" id="importForm">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">

    <div class="card p-3 mb-3" style="max-width:900px;">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <input type="search" id="pupilSearch" class="form-control form-control-sm" style="max-width:260px;" placeholder="Search name or number" autocomplete="off">
            <button type="button" class="btn btn-outline-primary btn-sm" id="selectShown">Select all shown</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="clearAll">Clear all</button>
            <span class="small text-muted ms-auto"><strong id="pickedCount">0</strong> selected</span>
        </div>
        <div class="form-text mt-2">Tip: tick one pupil, then hold <strong>Shift</strong> and tick another to select everyone in between. Pupils you register here disappear from this list next time.</div>
    </div>

    <div class="card mb-3" style="max-width:900px;">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr><th style="width:44px;"></th><th>Pupil</th><th class="text-end" style="width:90px;">Gender</th></tr>
                </thead>
                <tbody id="pupilRows">
                    @foreach($analysis['candidates'] as $c)
                    <tr data-search="{{ mb_strtolower($c['name'] . ' ' . $c['zems']) }}">
                        <td><input type="checkbox" class="form-check-input pupil-box" name="selected[]" value="{{ $c['zems'] }}" aria-label="Select {{ $c['name'] }}"></td>
                        <td><div class="fw-semibold">{{ $c['name'] }}</div><div class="small text-muted">{{ $c['zems'] }}</div></td>
                        <td class="text-end small">{{ $c['gender'] === 'M' ? 'Male' : ($c['gender'] === 'F' ? 'Female' : '-') }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-success" id="registerBtn" disabled><i class="bi bi-person-plus me-1"></i>Register selected pupils</button>
        <a href="{{ route('students') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endif
@endsection

@section('scripts')
@if(count($analysis['candidates']) > 0)
<script>
(function () {
    var rows = Array.prototype.slice.call(document.querySelectorAll('#pupilRows tr'));
    var boxes = rows.map(function (r) { return r.querySelector('.pupil-box'); });
    var counter = document.getElementById('pickedCount');
    var registerBtn = document.getElementById('registerBtn');
    var search = document.getElementById('pupilSearch');
    var lastIndex = null;

    function visible(i) { return rows[i].style.display !== 'none'; }
    function refresh() {
        var n = boxes.filter(function (b) { return b.checked; }).length;
        counter.textContent = n;
        registerBtn.disabled = n === 0;
    }

    boxes.forEach(function (box, i) {
        box.addEventListener('click', function (ev) {
            if (ev.shiftKey && lastIndex !== null) {
                var from = Math.min(lastIndex, i), to = Math.max(lastIndex, i);
                for (var k = from; k <= to; k++) { if (visible(k)) { boxes[k].checked = box.checked; } }
            }
            lastIndex = i;
            refresh();
        });
    });

    search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        rows.forEach(function (r) { r.style.display = (q === '' || r.getAttribute('data-search').indexOf(q) !== -1) ? '' : 'none'; });
    });
    document.getElementById('selectShown').addEventListener('click', function () {
        rows.forEach(function (r, i) { if (visible(i)) { boxes[i].checked = true; } });
        refresh();
    });
    document.getElementById('clearAll').addEventListener('click', function () {
        boxes.forEach(function (b) { b.checked = false; });
        refresh();
    });
    search.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); } });
    refresh();
})();
</script>
@endif
@endsection
