@extends('base')
@section('page_title', 'Check Marks')
@section('content')
<x-page_header title="Check before saving" subtitle="{{ $cls->name }} &mdash; {{ $subject->name }} &mdash; {{ $exam->exam_type }} {{ $exam->academic_year }}">
    <x-slot:actions>
        <a href="{{ route('marks_upload', ['exam' => $exam->id, 'subject' => $subject->id]) }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Upload another file</a>
    </x-slot:actions>
</x-page_header>

@if($error_count > 0)
<div class="alert alert-danger"><i class="bi bi-exclamation-octagon me-1"></i>
    {{ $error_count }} {{ $error_count === 1 ? 'row has' : 'rows have' }} problems. Nothing has been saved. Correct the Excel file and upload it again.</div>
@else
<div class="alert alert-success"><i class="bi bi-check-circle me-1"></i>
    Ready to save: <strong>{{ $count_marks }}</strong> marks, <strong>{{ $count_absent }}</strong> absent (saved as 0)@if($count_blank), <strong>{{ $count_blank }}</strong> left empty (not changed)@endif.
    @if($not_in_file > 0)<br><span class="small">{{ $not_in_file }} pupil(s) of the class are not in the file and will not be changed.</span>@endif
</div>
@endif

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0 align-middle tbl-clean">
            <thead><tr><th>Row</th><th>Reg No</th><th>Full Name</th><th>Marks</th><th>Check</th></tr></thead>
            <tbody>
            @foreach($items as $i)
            <tr class="{{ $i['errors'] ? 'table-danger' : '' }}">
                <td>{{ $i['row'] }}</td>
                <td>{{ $i['reg_no'] }}</td>
                <td>{{ $i['full_name'] }}</td>
                <td>@if($i['kind'] === 'absent')ABS (0)@elseif($i['kind'] === 'marks'){{ rtrim(rtrim(number_format($i['score'], 2, '.', ''), '0'), '.') }}@else<span class="text-muted">{{ $i['raw'] !== '' ? $i['raw'] : '-' }}</span>@endif</td>
                <td>
                    @if($i['errors']){{ implode('; ', $i['errors']) }}
                    @elseif($i['warning'])<span class="text-warning">{{ $i['warning'] }}</span>
                    @else<span class="text-success">OK</span>@endif
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

@if($error_count === 0)
<form method="POST" action="{{ route('marks_upload_confirm') }}" class="mt-3" data-once>
    @csrf
    <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Save marks</button>
    <a href="{{ route('marks_upload', ['exam' => $exam->id, 'subject' => $subject->id]) }}" class="btn btn-outline-secondary ms-1">Cancel</a>
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
