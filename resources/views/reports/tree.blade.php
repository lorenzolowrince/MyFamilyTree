@extends('reports.layout')

@section('styles')
    .chart svg { width: 100%; height: auto; max-height: 150mm; display: block; }
    .chart rect { fill: #ffffff; stroke: #78716c; stroke-width: 1; }
    .chart .partner rect { fill: #f5f5f4; }
    .chart .repeat rect, .chart .hidden rect { stroke-dasharray: 4 3; fill: #fafaf9; }
    .chart text { text-anchor: middle; font-family: Helvetica, Arial, sans-serif; fill: #1c1917; }
    .chart .name { font-size: 11px; font-weight: 600; }
    .chart .detail { font-size: 9.5px; fill: #57534e; }
    .chart .hidden .name { font-style: italic; font-weight: 400; fill: #78716c; }
    .chart .link { fill: none; stroke: #78716c; stroke-width: 1; }
    .chart .marriage { stroke: #166534; stroke-width: 2; }
@endsection

@section('content')
    <p class="muted small">
        {{ trans_choice('{1} One generation|[2,*] :count generations', $generations, ['count' => $generations]) }}
        {{ __('from :name. Partners are shown beside each person (shaded, joined by a green line); children below.', ['name' => $root['name'] ?? '']) }}
    </p>

    <div class="chart">{!! $chart['svg'] !!}</div>
@endsection
