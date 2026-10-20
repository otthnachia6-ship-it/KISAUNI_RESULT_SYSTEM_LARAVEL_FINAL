@props(['title', 'subtitle' => null, 'icon' => null, 'actions' => null])
<div class="page-head">
    <div class="page-head-main">
        <div class="page-eyebrow"><span class="page-eyebrow-dot"></span><span data-today>{{ now()->format('l, j F Y') }}</span></div>
        <h1 class="page-title">{{ $title }}</h1>
        @if(!empty($subtitle))<p class="page-sub">{{ $subtitle }}</p>@endif
    </div>
    @if(!empty($actions) && !$actions->isEmpty())
    <div class="page-actions">{{ $actions }}</div>
    @endif
</div>
