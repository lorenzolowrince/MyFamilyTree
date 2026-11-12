@extends('reports.layout')

@php
    $headings = [1 => __('Parents'), 2 => __('Grandparents'), 3 => __('Great-grandparents')];
@endphp

@section('content')
    <p>
        <span class="num">1</span> @include('reports.person', ['person' => $subject])
    </p>
    <p class="muted small">
        {{ __('Each person has a number: someone\'s father has twice their number, their mother twice their number plus one.') }}
    </p>

    @forelse ($levels as $generation => $people)
        <h2>{{ $headings[$generation] ?? __(':n× great-grandparents', ['n' => $generation - 2]) }}</h2>
        <table>
            <thead>
                <tr><th style="width: 34pt;">{{ __('No.') }}</th><th style="width: 36%;">{{ __('Name') }}</th><th>{{ __('Born') }}</th><th>{{ __('Died') }}</th><th style="width: 60pt;">{{ __('Parent of') }}</th></tr>
            </thead>
            <tbody>
                @foreach ($people as $row)
                    <tr>
                        <td class="num">{{ $row['number'] }}</td>
                        <td>
                            @if ($row['person']['hidden'])<span class="private">{{ __('Private relative') }}</span>@else<strong>{{ $row['person']['name'] }}</strong>@endif
                            @if ($row['note']) <span class="muted small">({{ $row['note'] }})</span>@endif
                            @if ($row['same_as']) <div class="muted small">{{ __('Same person as no. :n: the family lines meet here.', ['n' => $row['same_as']]) }}</div>@endif
                        </td>
                        <td>{{ $row['person']['born'] }}@if ($row['person']['born_place'])<div class="muted small">{{ $row['person']['born_place'] }}</div>@endif</td>
                        <td>{{ $row['person']['died'] }}@if ($row['person']['died_place'])<div class="muted small">{{ $row['person']['died_place'] }}</div>@endif</td>
                        <td class="num">{{ $row['child'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p class="note">{{ __('No parents have been recorded for this person yet.') }}</p>
    @endforelse
@endsection
