@extends('reports.layout')

@section('content')
    @if ($from || $to)
        <p class="muted small">{{ __('Years :from to :to.', ['from' => $from ?? '…', 'to' => $to ?? '…']) }}</p>
    @endif

    @if ($truncated)
        <p class="note">{{ __('The timeline is very long. Only the first 500 moments are listed; choose a range of years for the rest.') }}</p>
    @endif

    @forelse ($decades as $decade => $items)
        <h2>{{ $decade }}</h2>
        <table>
            <tbody>
                @foreach ($items as $item)
                    <tr>
                        <td style="width: 110pt;" class="muted">{{ $item['date']['label'] ?? '' }}</td>
                        <td>
                            <strong>{{ $item['title'] }}</strong>
                            @if ($item['place'])<span class="muted"> · {{ $item['place'] }}</span>@endif
                            @if ($item['description'])<div class="pre small">{{ $item['description'] }}</div>@endif
                            @if ($item['kind'] === 'entry' && $item['people'] !== [])
                                <div class="muted small">{{ implode(', ', array_column($item['people'], 'name')) }}</div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @empty
        <p class="note">{{ __('Nothing to show for these years.') }}</p>
    @endforelse
@endsection
