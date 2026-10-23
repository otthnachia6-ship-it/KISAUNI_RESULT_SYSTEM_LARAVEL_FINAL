{{-- Classes grouped per standard. $groups, $idPrefix --}}
@foreach($groups as $std => $g)
<div class="class-pick-group" data-pick-group>
    <div class="class-pick-head">
        <span>{{ $g['title'] }}</span>
        <button type="button" class="btn btn-link btn-sm p-0" data-pick-all>Select all</button>
    </div>
    <div class="class-pick-items">
        @foreach($g['classes'] as $c)
        <label class="class-pick-item" for="{{ $idPrefix }}{{ $c['id'] }}" data-class-name="{{ strtolower($c['name']) }}">
            <input type="checkbox" class="form-check-input" name="class_ids[]" value="{{ $c['id'] }}" id="{{ $idPrefix }}{{ $c['id'] }}">
            <span>{{ str_replace('Standard ', 'Std ', $c['name']) }}</span>
        </label>
        @endforeach
    </div>
</div>
@endforeach
