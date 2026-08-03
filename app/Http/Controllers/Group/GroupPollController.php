<?php

namespace App\Http\Controllers\Group;

use App\Enums\GroupMemberRole;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\GroupPoll;
use App\Models\GroupPollOption;
use App\Models\GroupPollVote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class GroupPollController extends Controller
{
    public function store(Request $request, Group $group): RedirectResponse
    {
        $this->assertCanCreatePoll($request, $group);

        $validated = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'allow_multiple' => ['sometimes', 'boolean'],
            'closes_at' => ['nullable', 'date'],
            'options' => ['required', 'array', 'min:2', 'max:6'],
            'options.*' => ['required', 'string', 'max:200'],
        ]);

        $closesAt = ! empty($validated['closes_at'])
            ? \Carbon\Carbon::parse($validated['closes_at'])
            : null;

        if ($closesAt && $closesAt->isPast()) {
            throw ValidationException::withMessages([
                'closes_at' => 'Close time must be in the future.',
            ]);
        }

        $labels = collect($validated['options'])
            ->map(fn ($label) => trim((string) $label))
            ->filter()
            ->unique(fn ($label) => mb_strtolower($label))
            ->values();

        if ($labels->count() < 2) {
            throw ValidationException::withMessages([
                'options' => 'Add at least two unique options.',
            ]);
        }

        DB::transaction(function () use ($validated, $labels, $group, $request, $closesAt) {
            $poll = GroupPoll::create([
                'group_id' => $group->id,
                'creator_user_id' => $request->user()->id,
                'question' => $validated['question'],
                'allow_multiple' => $request->boolean('allow_multiple'),
                'closes_at' => $closesAt,
            ]);

            foreach ($labels as $index => $label) {
                GroupPollOption::create([
                    'group_poll_id' => $poll->id,
                    'label' => $label,
                    'sort_order' => $index,
                ]);
            }
        });

        return redirect()
            ->route('groups.show', ['group' => $group, 'tab' => 'polls'])
            ->with('success', 'Poll created.');
    }

    public function vote(Request $request, Group $group, GroupPoll $poll): RedirectResponse
    {
        $this->assertPollBelongsToGroup($group, $poll);
        $this->assertActiveMember($request, $group);

        if (! $group->allow_polls) {
            abort(403, 'Polls are disabled for this group.');
        }

        if ($poll->isClosed()) {
            return redirect()->back()->with('error', 'This poll is closed.');
        }

        $validated = $request->validate([
            'option_ids' => ['required', 'array', 'min:1'],
            'option_ids.*' => ['integer'],
        ]);

        $optionIds = collect($validated['option_ids'])->map(fn ($id) => (int) $id)->unique()->values();
        $validOptionIds = $poll->options()->whereIn('id', $optionIds)->pluck('id');

        if ($validOptionIds->count() !== $optionIds->count()) {
            throw ValidationException::withMessages([
                'option_ids' => 'One or more options are invalid.',
            ]);
        }

        if (! $poll->allow_multiple && $optionIds->count() > 1) {
            throw ValidationException::withMessages([
                'option_ids' => 'This poll only allows one choice.',
            ]);
        }

        $userId = $request->user()->id;

        DB::transaction(function () use ($poll, $validOptionIds, $userId) {
            GroupPollVote::query()
                ->where('group_poll_id', $poll->id)
                ->where('user_id', $userId)
                ->delete();

            foreach ($validOptionIds as $optionId) {
                GroupPollVote::create([
                    'group_poll_id' => $poll->id,
                    'group_poll_option_id' => $optionId,
                    'user_id' => $userId,
                ]);
            }
        });

        return redirect()
            ->route('groups.show', ['group' => $group, 'tab' => 'polls'])
            ->with('success', 'Your vote was recorded.');
    }

    public function close(Request $request, Group $group, GroupPoll $poll): RedirectResponse
    {
        $this->assertPollBelongsToGroup($group, $poll);
        $this->assertCanManagePoll($request, $group, $poll);

        if ($poll->closed_at === null) {
            $poll->update(['closed_at' => now()]);
        }

        return redirect()
            ->route('groups.show', ['group' => $group, 'tab' => 'polls'])
            ->with('success', 'Poll closed.');
    }

    public function destroy(Request $request, Group $group, GroupPoll $poll): RedirectResponse
    {
        $this->assertPollBelongsToGroup($group, $poll);
        $this->assertCanManagePoll($request, $group, $poll);

        $poll->delete();

        return redirect()
            ->route('groups.show', ['group' => $group, 'tab' => 'polls'])
            ->with('success', 'Poll deleted.');
    }

    private function assertPollBelongsToGroup(Group $group, GroupPoll $poll): void
    {
        if ((int) $poll->group_id !== (int) $group->id) {
            abort(404);
        }
    }

    private function assertCanCreatePoll(Request $request, Group $group): void
    {
        Gate::authorize('view', $group);

        if (! $group->allow_polls) {
            abort(403, 'Polls are disabled for this group.');
        }

        $this->assertActiveMember($request, $group);

        $member = GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $request->user()->id)
            ->where('membership_status', 'active')
            ->first();

        if ($member?->isPostingSuspended()) {
            abort(403, 'Your posting privileges are suspended.');
        }
    }

    private function assertActiveMember(Request $request, Group $group): void
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        $isActive = GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->where('membership_status', 'active')
            ->exists();

        if (! $isActive && ! Gate::allows('moderate', $group)) {
            abort(403, 'Only active group members can do that.');
        }
    }

    private function assertCanManagePoll(Request $request, Group $group, GroupPoll $poll): void
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if ((int) $poll->creator_user_id === (int) $user->id) {
            return;
        }

        if (Gate::allows('moderate', $group)) {
            return;
        }

        $role = GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->where('membership_status', 'active')
            ->value('role');

        if ($role instanceof GroupMemberRole && $role->canModerate()) {
            return;
        }

        if ($role === GroupMemberRole::Admin->value || $role === 'admin') {
            return;
        }

        abort(403);
    }
}
