<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommunityContent;
use App\Models\CommunityReply;
use App\Models\ContentReport;
use App\Models\Group;
use App\Models\GroupMember;
use App\Services\CommunityModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ModerationQueueController extends Controller
{
    public function __construct(
        private readonly CommunityModerationService $moderation,
    ) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() ?: 'open';

        $reports = ContentReport::query()
            ->with(['reporter:id,name,email'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->through(function (ContentReport $report) {
                $reportable = $report->reportable;
                $summary = match (true) {
                    $reportable instanceof Group => 'Group: '.$reportable->name,
                    $reportable instanceof CommunityContent => ($reportable->type?->value ?? 'content').': '.$reportable->title,
                    $reportable instanceof CommunityReply => 'Reply: '.str($reportable->body)->limit(80),
                    default => class_basename((string) $report->reportable_type).' #'.$report->reportable_id,
                };

                return [
                    'id' => $report->id,
                    'reason' => $report->reason,
                    'reason_label' => config('community.report_reasons.'.$report->reason, $report->reason),
                    'notes' => $report->notes,
                    'status' => $report->status,
                    'created_at' => $report->created_at?->toIso8601String(),
                    'summary' => $summary,
                    'reportable_type' => class_basename((string) $report->reportable_type),
                    'reportable_id' => $report->reportable_id,
                    'reporter' => $report->reporter ? [
                        'id' => $report->reporter->id,
                        'name' => $report->reporter->name,
                        'email' => $report->reporter->email,
                    ] : null,
                ];
            });

        return Inertia::render('admin/moderation/Index', [
            'reports' => $reports,
            'filters' => ['status' => $status],
            'reportReasons' => config('community.report_reasons'),
        ]);
    }

    public function update(Request $request, ContentReport $report): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['reviewed', 'actioned', 'dismissed'])],
            'moderation_action' => ['nullable', Rule::in(['hide', 'remove', 'restore', 'lock', 'hide_group', 'suspend_member'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $reportable = $report->reportable;
        $actor = $request->user();

        if ($validated['moderation_action'] && $reportable) {
            match ($validated['moderation_action']) {
                'hide' => $reportable instanceof CommunityContent
                    ? $this->moderation->hideContent($reportable, $actor, $validated['reason'] ?? $report->reason)
                    : ($reportable instanceof CommunityReply
                        ? $this->moderation->hideReply($reportable, $actor, $validated['reason'] ?? $report->reason)
                        : null),
                'remove' => $reportable instanceof CommunityContent
                    ? $this->moderation->removeContentFromView($reportable, $actor, $validated['reason'] ?? $report->reason)
                    : null,
                'restore' => $reportable instanceof CommunityContent
                    ? $this->moderation->restoreContent($reportable, $actor, $validated['reason'] ?? $report->reason)
                    : ($reportable instanceof CommunityReply
                        ? $this->moderation->restoreReply($reportable, $actor, $validated['reason'] ?? $report->reason)
                        : null),
                'lock' => $reportable instanceof CommunityContent
                    ? $this->moderation->lockContent($reportable, $actor, $validated['reason'] ?? $report->reason)
                    : null,
                'hide_group' => $reportable instanceof Group
                    ? $this->moderation->hideGroupOnParent($reportable, $actor, $validated['reason'] ?? $report->reason)
                    : null,
                'suspend_member' => $this->suspendFromReport($reportable, $actor, $validated['reason'] ?? $report->reason),
                default => null,
            };
        }

        $report->update([
            'status' => $validated['status'],
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Report updated.');
    }

    private function suspendFromReport(mixed $reportable, $actor, ?string $reason): void
    {
        $userId = null;
        $groupId = null;

        if ($reportable instanceof CommunityContent && $reportable->parent instanceof Group) {
            $userId = $reportable->author_id;
            $groupId = $reportable->parent_id;
        }
        if ($reportable instanceof CommunityReply) {
            $userId = $reportable->author_id;
            $content = $reportable->content;
            if ($content?->parent instanceof Group) {
                $groupId = $content->parent_id;
            }
        }

        if (! $userId || ! $groupId) {
            return;
        }

        $member = GroupMember::query()
            ->where('group_id', $groupId)
            ->where('user_id', $userId)
            ->first();

        if ($member) {
            $this->moderation->suspendMemberPosting($member, $actor, now()->addDays(7), $reason);
        }
    }
}
