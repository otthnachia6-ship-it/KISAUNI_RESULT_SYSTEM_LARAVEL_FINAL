@extends('base')
@section('page_title', 'Check Students')
@section('content')
<x-page_header icon="bi-file-earmark-excel" title="Check before saving" subtitle="{{ $file_name }} &mdash; class {{ $cls->name }}">
    <x-slot:actions>
        <a href="{{ route('students_upload') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Upload another file</a>
    </x-slot:actions>
</x-page_header>

@if($error_count > 0)
<div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-1"></i>
    {{ $error_count }} {{ $error_count === 1 ? 'row has' : 'rows have' }} problems. Nothing has been saved. Correct the Excel file and upload it again.</div>
@else
<div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>
    All {{ count($items) }} pupils are correct and ready to be registered in <strong>{{ $cls->name }}</strong>.</div>
@endif

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0 align-middle tbl-clean">
            <thead><tr><th>Row</th><th>Reg No</th><th>Full Name</th><th>Gender</th><th>Check</th></tr></thead>
            <tbody>
            @foreach($items as $i)
            <tr class="{{ $i['errors'] ? 'table-danger' : '' }}">
                <td>{{ $i['row'] }}</td>
                <td>{{ $i['reg_no'] }}</td>
                <td>{{ $i['full_name'] }}</td>
                <td>{{ $i['gender'] ?: '-' }}@if($i['gender'] && !$i['confirmed']) <span class="text-muted small">(guessed)</span>@endif</td>
                <td>@if($i['errors']){{ implode('; ', $i['errors']) }}@else<span class="text-success">OK</span>@endif</td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

@if($error_count === 0)
<form method="POST" action="{{ route('students_upload_confirm') }}" class="mt-3" data-once>
    @csrf
    <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save {{ count($items) }} students</button>
    <a href="{{ route('students_upload') }}" class="btn btn-outline-secondary ms-1">Cancel</a>
</form>
@endif
@endsection

@section('scripts')
<script>
document.querySelectorAll('form[data-once]').forEach(function (f) {
    f.addEventListener('submit', function () {
        var b = f.querySelector('button:not([type=button])');
        if (b) { setTimeout(function () { b.disabled = true; }, 0); }
    });
});
</script>
@endsection
