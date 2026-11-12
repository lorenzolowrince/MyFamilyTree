@extends('reports.layout')

@section('content')
    <p class="muted small">
        {{ trans_choice('{1} One generation.|[2,*] :count generations.', $generations, ['count' => $generations]) }}
        {{ __('Each number shows the line of descent: 1.2.3 is the third child of the second child of person 1.') }}
    </p>

    @if ($truncated)
        <p class="note">{{ __('This branch is very large. Only the first :count people are listed; start from someone further down for the rest.', ['count' => number_format(count($rows))]) }}</p>
    @endif

    <div class="outline">
        @foreach ($rows as $row)
            <div style="padding-left: {{ $row['depth'] * 14 }}pt;">
                <span class="num">{{ $row['number'] }}</span>
                @include('reports.person', ['person' => $row['person']])
                @if ($row['repeat'])
                    <span class="muted small">{{ __('(listed above)') }}</span>
                @elseif ($row['partners'] !== [])
                    <span class="muted">
                        · {{ __('with') }}
                        @foreach ($row['partners'] as $partner)
                            @include('reports.person', ['person' => $partner])@if (! $loop->last), @endif
                        @endforeach
                    </span>
                @endif
            </div>
        @endforeach
    </div>
@endsection
