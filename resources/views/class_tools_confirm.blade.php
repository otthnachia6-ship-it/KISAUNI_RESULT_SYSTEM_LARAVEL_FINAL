@extends('base')
@section('page_title', 'Confirm')

@section('content')
<x-page_header icon="bi-eraser" title="{{ $class->name }}" subtitle="{{ $plan['title'] }}">
</x-page_header>

<form method="POST" action="{{ route('class_tools_run', $class->id) }}" class="card p-3 p-md-4">
    @csrf
    @foreach($plan['params'] as $key => $value)
        @if(is_array($value))
            @foreach($value as $v)<input type="hidden" name="{{ $key }}[]" value="{{ $v }}">@endforeach
        @else
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endif
    @endforeach

    <ul class="plan-list mb-3">
        @foreach($plan['lines'] as $line)
            <li><span>{{ $line[0] }}</span><strong>{{ $line[1] }}</strong></li>
        @endforeach
    </ul>

    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-danger" type="submit" data-loading-text="Working...">Confirm</button>
        <a href="{{ route('class_tools', $class->id) }}" class="btn btn-outline-secondary">Back</a>
    </div>
</form>
@endsection
