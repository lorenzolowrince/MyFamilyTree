<?php

namespace App\Http\Controllers\Families;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGedcomImport;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\GedcomImport;
use App\Models\User;
use App\Services\Audit\HistoryRecorder;
use App\Services\Gedcom\GedcomImporter;
use App\Services\Gedcom\GedcomWriter;
use App\Services\Media\MediaLibrary;
use App\Services\Privacy\MemberPrivacy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GEDCOM: taking the tree out to other genealogy programs, and bringing a tree in.
 * Both are for the Owner and Administrators only.
 */
class GedcomController extends Controller
{
    private const MAX_BYTES = 10 * 1024 * 1024;

    public function index(Request $request, Family $family): Response
    {
        $this->authorize('view', $family);

        /** @var User $user */
        $user = $request->user();
        $canImport = $user->can('importGedcom', $family);

        abort_unless($canImport || $user->can('exportGedcom', $family), 403);

        return Inertia::render('gedcom/index', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'members' => FamilyMember::query()->inFamily($family)->count(),
            'imports' => ! $canImport ? [] : $family->imports()->with('uploader')->latest()->limit(10)->get()
                ->map(fn (GedcomImport $import): array => self::present($import, false)),
            'limits' => ['max_bytes' => self::MAX_BYTES, 'max_people' => GedcomImporter::MAX_PEOPLE],
            'can' => ['export' => $user->can('exportGedcom', $family), 'import' => $canImport],
        ]);
    }

    /** A file safe to pass on: living people and private records without their names or details. */
    public function export(Request $request, Family $family, GedcomWriter $writer, MemberPrivacy $privacy, HistoryRecorder $history): StreamedResponse
    {
        return $this->download($request, $family, $writer, $privacy, $history, complete: false);
    }

    /**
     * Everything, for backup or for moving to another program. The route asks for the
     * password again first: this one file holds every living relative's details.
     */
    public function exportComplete(Request $request, Family $family, GedcomWriter $writer, MemberPrivacy $privacy, HistoryRecorder $history): StreamedResponse
    {
        return $this->download($request, $family, $writer, $privacy, $history, complete: true);
    }

    private function download(Request $request, Family $family, GedcomWriter $writer, MemberPrivacy $privacy, HistoryRecorder $history, bool $complete): StreamedResponse
    {
        $this->authorize('exportGedcom', $family);

        /** @var User $user */
        $user = $request->user();

        $gedcom = $writer->write($family, $privacy->viewer($user, $family), $complete);

        $history->audit('gedcom.exported', $family->id, 'family', $family->id, null, [
            'people' => substr_count($gedcom, ' INDI'),
            'kind' => $complete ? 'complete' : 'for sharing',
        ], $user);

        return response()->streamDownload(
            function () use ($gedcom): void {
                echo $gedcom;
            },
            Str::slug($family->name).($complete ? '-complete-' : '-').now()->format('Y-m-d').'.ged',
            ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'],
        );
    }

    public function store(Request $request, Family $family, MediaLibrary $library): RedirectResponse
    {
        $this->authorize('importGedcom', $family);

        $request->validate(['file' => ['required', 'file', 'max:'.(self::MAX_BYTES / 1024)]], [
            'file.max' => __('The file is larger than 10 MB.'),
        ]);

        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);

        // GEDCOM is plain text that begins with a header line. Anything else is not accepted.
        $start = (string) file_get_contents((string) $file->getRealPath(), false, null, 0, 512);
        $start = ltrim((string) preg_replace('/^(\xEF\xBB\xBF|\xFF\xFE|\xFE\xFF)/', '', $start), "\x00 \t\r\n");

        if (preg_match('/^0\x00? ?\x00?H\x00?E\x00?A\x00?D/', $start) !== 1) {
            throw ValidationException::withMessages(['file' => __('This does not look like a GEDCOM file. Export a .ged file from your genealogy program and upload that.')]);
        }

        if ($family->imports()->whereIn('status', ['uploaded', 'parsing', 'importing'])->exists()) {
            throw ValidationException::withMessages(['file' => __('Another import is still being processed. Wait for it to finish first.')]);
        }

        /** @var User $user */
        $user = $request->user();

        $import = new GedcomImport;
        $import->id = (string) Str::uuid7();
        $import->family_id = $family->id;
        $import->uploaded_by = $user->id;
        $import->storage_key = "families/{$family->id}/imports/{$import->id}.ged";
        $import->original_filename = Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 150, '');

        $stream = fopen((string) $file->getRealPath(), 'rb');
        abort_if($stream === false || ! $library->disk()->writeStream($import->storage_key, $stream), 500);

        $import->save();

        ProcessGedcomImport::dispatch($import->id, 'prepare');

        return to_route('families.gedcom.show', [$family, $import]);
    }

    public function show(Request $request, Family $family, GedcomImport $import): Response
    {
        $this->authorize('importGedcom', $family);

        return Inertia::render('gedcom/show', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'import' => self::present($import, true),
        ]);
    }

    /** The reviewer has looked at the preview: apply the file. */
    public function confirm(Request $request, Family $family, GedcomImport $import): RedirectResponse
    {
        $this->authorize('importGedcom', $family);

        // Already applied, cancelled or failed: there is nothing to confirm.
        if ($import->status !== 'preview_ready') {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('This import can no longer be confirmed.')]);

            return to_route('families.gedcom.show', [$family, $import]);
        }

        $data = $request->validate([
            'decisions' => ['present', 'array', 'max:'.GedcomImporter::MAX_PEOPLE],
            'decisions.*' => ['required', 'string', 'max:36'],
        ]);

        /** @var array<string, array<string, mixed>> $people */
        $people = array_column((array) ($import->summary['people'] ?? []), null, 'xref');
        $mapping = [];
        $taken = [];

        foreach ((array) $data['decisions'] as $xref => $decision) {
            $xref = (string) $xref;

            if (! isset($people[$xref])) {
                continue;
            }

            if (in_array($decision, [GedcomImporter::CREATE, GedcomImporter::SKIP], true)) {
                $mapping[$xref] = $decision;

                continue;
            }

            // "The same person as…" must be one of the people offered for this entry, once.
            $offered = array_column((array) ($people[$xref]['matches'] ?? []), 'id');

            if (! in_array($decision, $offered, true) || isset($taken[$decision])) {
                throw ValidationException::withMessages(['decisions' => __('Each person in the tree can be matched with only one person in the file. Check the choices for ":name".', ['name' => $people[$xref]['name']])]);
            }

            $taken[$decision] = true;
            $mapping[$xref] = $decision;
        }

        /** @var User $user */
        $user = $request->user();

        $import->forceFill(['mapping' => $mapping, 'status' => 'importing'])->save();

        ProcessGedcomImport::dispatch($import->id, 'run', $user->id);

        return to_route('families.gedcom.show', [$family, $import]);
    }

    public function cancel(Family $family, GedcomImport $import, MediaLibrary $library): RedirectResponse
    {
        $this->authorize('importGedcom', $family);

        if (in_array($import->status, ['uploaded', 'preview_ready', 'failed'], true)) {
            $library->disk()->delete($import->storage_key);
            $import->forceFill(['status' => $import->status === 'failed' ? 'failed' : 'cancelled', 'summary' => array_diff_key($import->summary, ['people' => true])])->save();

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Import cancelled. Nothing was changed in the tree.')]);
        }

        return to_route('families.gedcom.index', $family);
    }

    /**
     * @return array<string, mixed>
     */
    private static function present(GedcomImport $import, bool $full): array
    {
        $summary = $import->summary;

        return [
            'id' => $import->id,
            'filename' => $import->original_filename,
            'status' => $import->status,
            'error' => $import->error,
            'uploaded_by' => $import->uploader?->name,
            'created_at' => $import->created_at?->toIso8601String(),
            'counts' => $summary['counts'] ?? null,
            'result' => $import->result,
            ...($full ? [
                'version' => $summary['version'] ?? null,
                'source' => $summary['source'] ?? null,
                'warnings' => $summary['warnings'] ?? [],
                // Everyone in the file who may already be in the tree, and a sample of the rest.
                'duplicates' => array_values(array_filter((array) ($summary['people'] ?? []), fn (array $person): bool => $person['matches'] !== [])),
                'sample' => array_slice(array_values(array_filter((array) ($summary['people'] ?? []), fn (array $person): bool => $person['matches'] === [])), 0, 100),
                'suggested' => (object) ($summary['suggested'] ?? []),
            ] : []),
        ];
    }
}
