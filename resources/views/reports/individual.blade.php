@extends('reports.layout')

@section('styles')
    .top { display: flex; gap: 14pt; align-items: flex-start; }
    .top img { width: 96pt; height: 96pt; object-fit: cover; border-radius: 6pt; border: 0.5pt solid #a8a29e; }
    .top .facts { flex: 1; }
@endsection

@php
    $date = fn (?array $value) => $value['label'] ?? null;
    $facts = array_filter([
        __('Also known as') => $person['nickname'],
        __('Given names') => $person['given_names'],
        __('Surname') => $person['surname'],
        __('Maiden or birth name') => $person['maiden_name'],
        __('Born') => implode(', ', array_filter([$date($person['birth']), $person['birth_place']])),
        __('Died') => implode(', ', array_filter([$date($person['death']), $person['death_place']])),
        __('Hometown') => $person['hometown'],
        __('Occupation') => $person['occupation'],
        __('Education') => $person['education'],
        __('Nationality') => $person['nationality'],
    ]);
    $groups = [__('Parents') => $relatives['parents'], __('Partners') => $relatives['partners'], __('Children') => $relatives['children'], __('Siblings') => $relatives['siblings']];
@endphp

@section('content')
    <div class="top">
        @if ($photo)
            <img src="{{ $photo }}" alt="">
        @endif
        <div class="facts">
            @if ($person['sensitive_hidden'])
                <p class="note">{{ __('This person is living. Their dates, places and other personal details are not shown to your role.') }}</p>
            @endif
            @if ($facts !== [])
                <dl class="facts">
                    @foreach ($facts as $label => $value)
                        <dt>{{ $label }}</dt><dd>{{ $value }}</dd>
                    @endforeach
                </dl>
            @endif
        </div>
    </div>

    @if ($names !== [])
        <h2>{{ __('Other names') }}</h2>
        <ul class="plain">
            @foreach ($names as $name)
                <li>{{ $name['name'] }} <span class="muted small">({{ $name['type'] }})</span></li>
            @endforeach
        </ul>
    @endif

    @if ($person['biography'])
        <h2>{{ __('Life story') }}</h2>
        <p class="pre">{{ $person['biography'] }}</p>
    @endif

    <h2>{{ __('Family') }}</h2>
    @foreach ($groups as $heading => $entries)
        @if ($entries !== [])
            <h3>{{ $heading }}</h3>
            <ul class="plain">
                @foreach ($entries as $entry)
                    <li>
                        @include('reports.person', ['person' => $entry['person']])
                        @php
                            $relationship = $entry['relationship'] ?? [];
                            $details = array_filter([
                                isset($relationship['subtype']) && ! in_array($relationship['subtype'], ['biological', 'marriage'], true) ? str_replace('_', ' ', $relationship['subtype']) : null,
                                isset($relationship['status']) && $relationship['status'] !== 'current' ? $relationship['status'] : null,
                                $relationship['start']['label'] ?? null,
                                $relationship['place'] ?? null,
                                isset($entry['kind']) && $entry['kind'] === 'half' ? __('half-sibling') : null,
                            ]);
                        @endphp
                        @if ($details !== [])
                            <span class="muted small">· {{ implode(' · ', $details) }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    @endforeach

    @if ($events !== [])
        <h2>{{ __('Life events') }}</h2>
        <table>
            <tbody>
                @foreach ($events as $event)
                    <tr>
                        <td style="width: 110pt;" class="muted">{{ $event['date']['label'] ?? __('Date not known') }}</td>
                        <td>
                            <strong>{{ $event['title'] }}</strong>
                            @if ($event['place'])<span class="muted"> · {{ $event['place'] }}</span>@endif
                            @if ($event['description'])<div class="pre small">{{ $event['description'] }}</div>@endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($person['notes'])
        <h2>{{ __('Research notes') }}</h2>
        <p class="pre">{{ $person['notes'] }}</p>
    @endif
@endsection
