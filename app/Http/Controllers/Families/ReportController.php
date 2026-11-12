<?php

namespace App\Http\Controllers\Families;

use App\Enums\FamilyRole;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateReport;
use App\Models\Family;
use App\Models\FamilyMember;
use App\Models\ReportExport;
use App\Models\User;
use App\Services\Audit\HistoryRecorder;
use App\Services\Authorization\FamilyAccess;
use App\Services\Media\MediaLibrary;
use App\Services\Privacy\MemberPresenter;
use App\Services\Privacy\MemberPrivacy;
use App\Services\Reports\ReportBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PDF reports. Contributors and above may ask for one. A report shows what the person who
 * asked for it may see, so it belongs to them alone: nobody else can list or download it.
 */
class ReportController extends Controller
{
    public function index(Request $request, Family $family, MemberPrivacy $privacy, MemberPresenter $presenter): Response
    {
        $this->authorize('generateReports', $family);

        /** @var User $user */
        $user = $request->user();
        $viewer = $privacy->viewer($user, $family);

        // Who a new report starts from: the person asked about, else the viewer, else the root of the tree.
        $about = collect([$request->query('member'), $viewer->linkedMemberId, $family->root_member_id])
            ->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))
            ->map(fn (string $id): ?FamilyMember => FamilyMember::query()->inFamily($family)->find($id))
            ->first(fn (?FamilyMember $member): bool => $member !== null && $viewer->canView($member));

        return Inertia::render('reports/index', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'reports' => $family->reports()->where('requested_by', $user->id)->latest()->limit(30)->get()->map(fn (ReportExport $report): array => [
                'id' => $report->id,
                'type' => $report->type,
                'title' => $report->title,
                'status' => $report->status === 'ready' && ! $this->usable($report, $viewer->role) ? 'expired' : $report->status,
                'error' => $report->error,
                'size_bytes' => $report->size_bytes,
                'created_at' => $report->created_at?->toIso8601String(),
                'expires_at' => $report->expires_at->toIso8601String(),
                'download_url' => $this->usable($report, $viewer->role) ? route('families.reports.download', [$family, $report]) : null,
            ]),
            'types' => collect(ReportBuilder::TYPES)->map(fn (string $label, string $type): array => [
                'value' => $type,
                'label' => __($label),
                'personal' => in_array($type, ReportBuilder::PERSONAL, true) || $type === 'tree',
                'generations' => in_array($type, ['tree', 'descendants', 'ancestors'], true),
            ])->values(),
            'about' => $about === null ? null : $presenter->summary($about, $viewer),
            'limits' => [
                'generations' => (int) config('reports.max_generations'),
                'chart_generations' => (int) config('reports.max_chart_generations'),
                'keep_days' => (int) config('reports.keep_days'),
            ],
        ]);
    }

    public function store(Request $request, Family $family, MemberPrivacy $privacy, ReportBuilder $builder, HistoryRecorder $history): RedirectResponse
    {
        $this->authorize('generateReports', $family);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(ReportBuilder::TYPES))],
            'member_id' => ['nullable', 'uuid'],
            'generations' => ['nullable', 'integer', 'min:1', 'max:'.config('reports.max_generations')],
            'from' => ['nullable', 'integer', 'min:1', 'max:3000'],
            'to' => ['nullable', 'integer', 'min:1', 'max:3000', 'gte:from'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $type = (string) $data['type'];

        $subject = isset($data['member_id']) ? FamilyMember::query()->inFamily($family)->whereKey((string) $data['member_id'])->first() : null;

        // Someone the requester may not see is the same as nobody.
        if ($subject !== null && ! $privacy->viewer($user, $family)->canView($subject)) {
            $subject = null;
        }

        if ($subject === null && in_array($type, ReportBuilder::PERSONAL, true)) {
            throw ValidationException::withMessages(['member_id' => __('Choose the person this report is about.')]);
        }

        if ($subject === null && $type === 'tree' && $family->root_member_id === null) {
            throw ValidationException::withMessages(['member_id' => __('Choose the person the chart starts from.')]);
        }

        if ($family->reports()->where('requested_by', $user->id)->whereIn('status', ['pending', 'processing'])->count() >= 3) {
            throw ValidationException::withMessages(['type' => __('Three of your reports are still being made. Wait for one to finish first.')]);
        }

        $report = new ReportExport;
        $report->family_id = $family->id;
        $report->requested_by = $user->id;
        $report->type = $type;
        $report->title = Str::limit($builder->title($family, $type, $subject), 200);
        $report->parameters = array_filter([
            'member_id' => in_array($type, [...ReportBuilder::PERSONAL, 'tree'], true) ? $subject?->id : null,
            'generations' => in_array($type, ['tree', 'descendants', 'ancestors'], true) ? (int) ($data['generations'] ?? 4) : null,
            'from' => $type === 'timeline' ? ($data['from'] ?? null) : null,
            'to' => $type === 'timeline' ? ($data['to'] ?? null) : null,
        ], fn (mixed $value): bool => $value !== null);
        $report->expires_at = now()->addDays((int) config('reports.keep_days'));
        $report->save();

        $history->audit('report.requested', $family->id, 'report', $report->id, null, ['type' => $type, 'title' => $report->title], $user);

        GenerateReport::dispatch($report->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your report is being prepared.')]);

        return to_route('families.reports.index', $family);
    }

    public function download(Request $request, Family $family, ReportExport $report, MediaLibrary $library, HistoryRecorder $history, FamilyAccess $access): StreamedResponse
    {
        $this->authorize('generateReports', $family);

        /** @var User $user */
        $user = $request->user();

        // Another person's report does not exist, as far as anyone else can tell.
        abort_unless(
            $report->requested_by === $user->id
                && $this->usable($report, $access->role($user, $family))
                && $library->disk()->exists((string) $report->storage_key),
            404,
        );

        $history->audit('report.downloaded', $family->id, 'report', $report->id, null, ['title' => $report->title], $user);

        $name = trim((string) preg_replace('/[^\pL\pN _-]+/u', '', $report->title)) ?: 'report';

        return $library->disk()->download((string) $report->storage_key, mb_substr($name, 0, 80).'.pdf', [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    /**
     * A report holds what its requester could see in the role they had when it was printed.
     * After a change of role it is no longer handed out: they make a new one, which shows
     * what they may see now.
     */
    private function usable(ReportExport $report, ?FamilyRole $role): bool
    {
        return $report->isReady() && $role !== null && ($report->parameters['made_as'] ?? null) === $role->value;
    }

    public function destroy(Request $request, Family $family, ReportExport $report, MediaLibrary $library): RedirectResponse
    {
        $this->authorize('generateReports', $family);

        abort_unless($report->requested_by === $request->user()?->id, 404);

        if ($report->storage_key !== null) {
            $library->disk()->delete($report->storage_key);
        }

        $report->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report removed.')]);

        return back();
    }
}
