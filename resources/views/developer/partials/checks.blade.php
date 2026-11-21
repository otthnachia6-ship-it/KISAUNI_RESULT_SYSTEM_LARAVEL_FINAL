{{-- $checks: list of ['label','value','status','note'] or [label, value, status] --}}
{{-- On phones the Status column is hidden and the status is shown under the check name instead. --}}
<div class="card mb-3">
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0 dev-checks">
        <thead><tr><th>Check</th><th>Value</th><th class="dev-col-status">Status</th></tr></thead>
        <tbody>
        @forelse($checks as $c)
            @php
                $c = isset($c['label']) ? $c : ['label' => $c[0], 'value' => $c[1], 'status' => $c[2], 'note' => $c[3] ?? null];
            @endphp
            <tr>
                <td class="fw-semibold">{{ $c['label'] }}
                    <div class="dev-status-inline mt-1 fw-normal">@include('developer.partials.status', ['status' => $c['status']])</div>
                </td>
                <td style="overflow-wrap:anywhere;">{{ $c['value'] }}@if(!empty($c['note']))<div class="small text-muted">{{ $c['note'] }}</div>@endif</td>
                <td class="dev-col-status">@include('developer.partials.status', ['status' => $c['status']])</td>
            </tr>
        @empty
            <tr><td colspan="3" class="text-center text-muted">No information available.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
