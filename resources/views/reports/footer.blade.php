<!DOCTYPE html>
<html>
<head>
    <style>
        body { margin: 0; width: 100%; }
        table { width: 100%; margin: 0 0.55in; border-collapse: collapse; font-family: Helvetica, Arial, sans-serif; font-size: 7.5pt; color: #78716c; }
        td { padding: 0; white-space: nowrap; }
        td.page { text-align: right; padding-left: 12pt; }
    </style>
</head>
<body>
    {{-- Chromium fills in the page numbers. A table keeps the two halves apart in its footer box. --}}
    <table style="width: calc(100% - 1.1in);">
        <tr>
            <td>{{ $family->name }} · {{ $title }} · {{ $generated }}</td>
            <td class="page">{{ __('Page') }} <span class="pageNumber"></span> / <span class="totalPages"></span></td>
        </tr>
    </table>
</body>
</html>
