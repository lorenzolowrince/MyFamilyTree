<?php

namespace App\Http\Controllers\Families;

use App\Enums\FamilyPermission;
use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\User;
use App\Services\Privacy\MemberPrivacy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A family's history of changes.
 *
 * - The activity feed (readable sentences) is for Editors and above.
 * - The audit log (field-level before and after, address, time) is for the Owner and
 *   Administrators only.
 */
class ActivityController extends Controller
{
    public function index(Request $request, Family $family, MemberPrivacy $privacy): Response
    {
        $this->authorize('viewActivity', $family);

        /** @var User $user */
        $user = $request->user();
        $viewer = $privacy->viewer($user, $family);

        $activity = DB::table('activity_logs')
            ->leftJoin('users', 'users.id', '=', 'activity_logs.user_id')
            ->where('activity_logs.family_id', $family->id)
            // A line about a record this person may not see is not shown to them.
            ->whereIn('activity_logs.visibility', $viewer->visibleLevels())
            ->orderByDesc('activity_logs.created_at')
            ->orderByDesc('activity_logs.id')
            ->select(['activity_logs.id', 'activity_logs.verb', 'activity_logs.summary', 'activity_logs.created_at', 'users.name as actor'])
            ->paginate(50)
            ->withQueryString()
            ->through(fn (\stdClass $row): array => [
                'id' => (string) $row->id,
                'verb' => (string) $row->verb,
                'summary' => (string) $row->summary,
                'actor' => $row->actor === null ? null : (string) $row->actor,
                'created_at' => (string) $row->created_at,
            ]);

        return Inertia::render('activity/index', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'activity' => $activity,
            'canViewAudit' => $viewer->has(FamilyPermission::AuditView),
        ]);
    }

    public function audit(Request $request, Family $family): Response
    {
        $this->authorize('viewAudit', $family);

        $entries = DB::table('audit_logs')
            ->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('audit_logs.family_id', $family->id)
            ->orderByDesc('audit_logs.id')
            ->select(['audit_logs.id', 'audit_logs.action', 'audit_logs.auditable_type', 'audit_logs.old_values', 'audit_logs.new_values', 'audit_logs.ip_address', 'audit_logs.created_at', 'users.name as actor'])
            ->paginate(50)
            ->withQueryString()
            ->through(fn (\stdClass $row): array => [
                'id' => (int) $row->id,
                'action' => (string) $row->action,
                'type' => $row->auditable_type === null ? null : (string) $row->auditable_type,
                'old' => $row->old_values === null ? null : json_decode((string) $row->old_values, true),
                'new' => $row->new_values === null ? null : json_decode((string) $row->new_values, true),
                'ip' => $row->ip_address === null ? null : (string) $row->ip_address,
                'actor' => $row->actor === null ? null : (string) $row->actor,
                'created_at' => (string) $row->created_at,
            ]);

        return Inertia::render('activity/audit', [
            'family' => ['slug' => $family->slug, 'name' => $family->name],
            'entries' => $entries,
        ]);
    }
}
