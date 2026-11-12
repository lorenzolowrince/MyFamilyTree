@extends('reports.layout')

@section('content')
    <p class="muted small">{{ trans_choice('{1} One person|[2,*] :count people', count($rows), ['count' => number_format(count($rows))]) }}, {{ __('in alphabetical order.') }}</p>

    <table>
        <thead>
            <tr><th style="width: 38%;">{{ __('Name') }}</th><th>{{ __('Born') }}</th><th>{{ __('Died') }}</th><th style="width: 30pt;">{{ __('Gen.') }}</th></tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td><strong>{{ $row['name'] }}</strong></td>
                    <td>{{ $row['born'] }}@if ($row['born_place'])<div class="muted small">{{ $row['born_place'] }}</div>@endif</td>
                    <td>
                        @if ($row['died'] || $row['died_place'])
                            {{ $row['died'] }}@if ($row['died_place'])<div class="muted small">{{ $row['died_place'] }}</div>@endif
                        @elseif ($row['living'])
                            <span class="muted">{{ __('Living') }}</span>
                        @endif
                    </td>
                    <td class="num">{{ $row['generation'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endsection
