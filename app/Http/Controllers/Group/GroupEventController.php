<?php

namespace App\Http\Controllers\Group;

use App\Enums\GroupMemberRole;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupEvent;
use App\Models\GroupMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class GroupEventController extends Controller
{
    public function store(Request $request, Group $group): RedirectResponse
    {
        Gate::authorize('view', $group);

        if (! ($group->allow_events ?? true)) {
            abort(403, 'Events are disabled for this group.');
        }

        $this->assertCanContribute($request, $group);

        $rules = [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],
            'cover_image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120'],
        ];

        if (Schema::hasColumn('group_events', 'location_url')) {
            $rules['location_url'] = ['nullable', 'url', 'max:500'];
        }

        $validated = $request->validate($rules);

        $startsAt = \Carbon\Carbon::parse($validated['starts_at']);
        if ($startsAt->isPast()) {
            throw ValidationException::withMessages([
                'starts_at' => 'Start time must be in the future.',
            ]);
        }

        $coverPath = null;
        if ($request->hasFile('cover_image')) {
            $coverPath = $request->file('cover_image')->store("groups/{$group->id}/events", 'public');
        }

        $attributes = [
            'group_id' => $group->id,
            'created_by' => $request->user()->id,
            'name' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'start_date' => $startsAt,
            'end_date' => ! empty($validated['ends_at']) ? \Carbon\Carbon::parse($validated['ends_at']) : null,
            'location' => $validated['location'] ?? null,
            'poster_image' => $coverPath,
            'status' => 'upcoming',
        ];

        if (Schema::hasColumn('group_events', 'location_url')) {
            $attributes['location_url'] = $validated['location_url'] ?? null;
        }

        GroupEvent::create($attributes);

        return redirect()
            ->route('groups.show', ['group' => $group, 'tab' => 'events'])
            ->with('success', 'Event created.');
    }

    public function cancel(Request $request, Group $group, GroupEvent $groupEvent): RedirectResponse
    {
        $this->assertEventBelongs($group, $groupEvent);
        $this->assertCanManage($request, $group, $groupEvent);

        if (! $groupEvent->isCancelled()) {
            $groupEvent->update(['status' => 'cancelled']);
        }

        return redirect()
            ->route('groups.show', ['group' => $group, 'tab' => 'events'])
            ->with('success', 'Event cancelled.');
    }

    public function destroy(Request $request, Group $group, GroupEvent $groupEvent): RedirectResponse
    {
        $this->assertEventBelongs($group, $groupEvent);
        $this->assertCanManage($request, $group, $groupEvent);

        $groupEvent->deleteCover();
        $groupEvent->delete();

        return redirect()
            ->route('groups.show', ['group' => $group, 'tab' => 'events'])
            ->with('success', 'Event deleted.');
    }

    private function assertEventBelongs(Group $group, GroupEvent $event): void
    {
        if ((int) $event->group_id !== (int) $group->id) {
            abort(404);
        }
    }

    private function assertCanContribute(Request $request, Group $group): void
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if (Gate::allows('moderate', $group)) {
            return;
        }

        $member = GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $user->id)
            ->where('membership_status', 'active')
            ->first();

        if (! $member) {
            abort(403, 'Only active group members can create events.');
        }

        if ($member->isPostingSuspended()) {
            abort(403, 'Your posting privileges are suspended.');
        }
    }

    private function assertCanManage(Request $request, Group $group, GroupEvent $event): void
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if ((int) $event->created_by === (int) $user->id) {
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
