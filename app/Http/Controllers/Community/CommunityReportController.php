<?php

namespace App\Http\Controllers\Community;

use App\Http\Controllers\Controller;
use App\Models\CommunityContent;
use App\Models\CommunityReply;
use App\Models\ContentReport;
use App\Models\Group;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommunityReportController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $reasons = array_keys(config('community.report_reasons', []));

        $validated = $request->validate([
            'reportable_type' => ['required', Rule::in(['Group', 'CommunityContent', 'CommunityReply'])],
            'reportable_id' => ['required', 'integer'],
            'reason' => ['required', Rule::in($reasons)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $reportable = match ($validated['reportable_type']) {
            'Group' => Group::find($validated['reportable_id']),
            'CommunityContent' => CommunityContent::find($validated['reportable_id']),
            'CommunityReply' => CommunityReply::find($validated['reportable_id']),
            default => null,
        };

        if (! $reportable) {
            return redirect()->back()->with('error', 'Unable to report this item.');
        }

        $exists = ContentReport::query()
            ->where('reportable_type', $reportable->getMorphClass())
            ->where('reportable_id', $reportable->getKey())
            ->where('reporter_id', $request->user()->id)
            ->where('status', 'open')
            ->exists();

        if ($exists) {
            return redirect()->back()->with('error', 'You already have an open report for this item.');
        }

        ContentReport::create([
            'reportable_type' => $reportable->getMorphClass(),
            'reportable_id' => $reportable->getKey(),
            'reporter_id' => $request->user()->id,
            'reason' => $validated['reason'],
            'notes' => $validated['notes'] ?? null,
            'status' => 'open',
        ]);

        return redirect()->back()->with('success', 'Report submitted to BIU moderation. Thank you.');
    }
}
