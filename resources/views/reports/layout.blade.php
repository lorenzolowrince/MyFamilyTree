<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    {{-- Everything is inline: the PDF engine loads nothing from outside and runs no scripts. --}}
    <style>
        * { box-sizing: border-box; }
        html { font-family: "Helvetica Neue", Helvetica, Arial, "Noto Sans", "DejaVu Sans", sans-serif; font-size: 10.5pt; color: #1c1917; line-height: 1.4; }
        body { margin: 0; }
        h1 { font-size: 20pt; margin: 0 0 2pt; letter-spacing: -0.01em; }
        h2 { font-size: 12.5pt; margin: 16pt 0 6pt; padding-bottom: 3pt; border-bottom: 1pt solid #d6d3d1; break-after: avoid; }
        h3 { font-size: 10.5pt; margin: 10pt 0 4pt; break-after: avoid; }
        p { margin: 0 0 6pt; }
        .cover { margin-bottom: 14pt; padding-bottom: 8pt; border-bottom: 2pt solid #166534; }
        .cover .family { color: #166534; font-weight: 600; font-size: 10pt; text-transform: uppercase; letter-spacing: 0.06em; }
        .muted { color: #57534e; }
        .small { font-size: 8.5pt; }
        .note { margin: 0 0 10pt; padding: 6pt 8pt; background: #f5f5f4; border-left: 3pt solid #a8a29e; font-size: 9pt; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th { text-align: left; font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.04em; color: #57534e; border-bottom: 1pt solid #a8a29e; padding: 3pt 6pt 3pt 0; }
        td { padding: 3.5pt 6pt 3.5pt 0; border-bottom: 0.5pt solid #e7e5e4; vertical-align: top; }
        tr { break-inside: avoid; }
        thead { display: table-header-group; }
        .num { font-variant-numeric: tabular-nums; white-space: nowrap; color: #57534e; }
        .private { font-style: italic; color: #78716c; }
        dl.facts { display: grid; grid-template-columns: 110pt 1fr; gap: 2pt 10pt; margin: 0; }
        dl.facts dt { color: #57534e; }
        dl.facts dd { margin: 0; }
        ul.plain { list-style: none; margin: 0; padding: 0; }
        ul.plain li { padding: 2pt 0; break-inside: avoid; }
        .outline div { break-inside: avoid; padding: 1.5pt 0; }
        .pre { white-space: pre-line; }
        @yield('styles')
    </style>
</head>
<body>
    <header class="cover">
        <div class="family">{{ $family->name }}</div>
        <h1>{{ $title }}</h1>
        <div class="muted small">{{ __('Prepared on :date for :name', ['date' => $generated, 'name' => $requester]) }}</div>
    </header>

    @yield('content')

    <p class="muted small" style="margin-top: 16pt;">
        {{ __('This report contains personal information about family members. It shows what :name is allowed to see; details of living relatives may have been left out. Please do not share it outside the family.', ['name' => $requester]) }}
    </p>
</body>
</html>
