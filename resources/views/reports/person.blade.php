{{-- One person's name as a report shows it. $person is a line from ReportBuilder or a MemberPresenter summary. --}}
@if ($person['hidden'])
    <span class="private">{{ __('Private relative') }}</span>
@else
    <strong>{{ $person['name'] }}</strong>@if (! empty($person['lifespan'])) <span class="muted">({{ $person['lifespan'] }})</span>@endif
@endif
