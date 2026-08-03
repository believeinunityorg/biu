<?php

namespace App\Http\Controllers\Group;

use App\Enums\GroupMemberRole;
use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupLibraryItem;
use App\Models\GroupMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class GroupLibraryController extends Controller
{
    public function store(Request $request, Group $group): RedirectResponse
    {
        Gate::authorize('view', $group);

        $validated = $request->validate([
            'type' => ['required', Rule::in([
                GroupLibraryItem::TYPE_PHOTO,
                GroupLibraryItem::TYPE_VIDEO,
                GroupLibraryItem::TYPE_DOCUMENT,
            ])],
            'title' => ['nullable', 'string', 'max:200'],
            'caption' => ['nullable', 'string', 'max:2000'],
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['required', 'file'],
        ]);

        $type = $validated['type'];
        $this->assertTypeEnabled($group, $type);
        $this->assertCanContribute($request, $group);

        $fileRules = match ($type) {
            GroupLibraryItem::TYPE_PHOTO => ['files.*' => ['required', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:5120']],
            GroupLibraryItem::TYPE_VIDEO => ['files.*' => ['required', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:51200']],
            default => ['files.*' => ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip', 'max:10240']],
        };

        $request->validate($fileRules);

        $folder = match ($type) {
            GroupLibraryItem::TYPE_PHOTO => 'photos',
            GroupLibraryItem::TYPE_VIDEO => 'videos',
            default => 'documents',
        };

        $uploaded = 0;
        foreach ($request->file('files', []) as $file) {
            if (! $file) {
                continue;
            }

            $path = $file->store("groups/{$group->id}/{$folder}", 'public');

            GroupLibraryItem::create([
                'group_id' => $group->id,
                'uploader_user_id' => $request->user()->id,
                'type' => $type,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => (int) $file->getSize(),
                'title' => $validated['title'] ?? null,
                'caption' => $validated['caption'] ?? null,
                'visibility_status' => 'visible',
            ]);
            $uploaded++;
        }

        if ($uploaded === 0) {
            return redirect()->back()->with('error', 'No files were uploaded.');
        }

        $tab = match ($type) {
            GroupLibraryItem::TYPE_PHOTO => 'photos',
            GroupLibraryItem::TYPE_VIDEO => 'videos',
            default => 'files',
        };

        $label = match ($type) {
            GroupLibraryItem::TYPE_PHOTO => $uploaded === 1 ? 'photo' : 'photos',
            GroupLibraryItem::TYPE_VIDEO => $uploaded === 1 ? 'video' : 'videos',
            default => $uploaded === 1 ? 'file' : 'files',
        };

        return redirect()
            ->route('groups.show', ['group' => $group, 'tab' => $tab])
            ->with('success', "{$uploaded} {$label} uploaded.");
    }

    public function destroy(Request $request, Group $group, GroupLibraryItem $item): RedirectResponse
    {
        Gate::authorize('view', $group);

        if ((int) $item->group_id !== (int) $group->id) {
            abort(404);
        }

        $this->assertCanManageItem($request, $group, $item);

        $type = $item->type;
        $item->deleteFile();
        $item->delete();

        $tab = match ($type) {
            GroupLibraryItem::TYPE_PHOTO => 'photos',
            GroupLibraryItem::TYPE_VIDEO => 'videos',
            default => 'files',
        };

        return redirect()
            ->route('groups.show', ['group' => $group, 'tab' => $tab])
            ->with('success', 'Item removed.');
    }

    private function assertTypeEnabled(Group $group, string $type): void
    {
        $allowed = match ($type) {
            GroupLibraryItem::TYPE_PHOTO => (bool) ($group->allow_photos ?? true),
            GroupLibraryItem::TYPE_VIDEO => (bool) ($group->allow_videos ?? true),
            GroupLibraryItem::TYPE_DOCUMENT => (bool) ($group->allow_documents ?? true),
            default => false,
        };

        if (! $allowed) {
            abort(403, 'This media type is disabled for this group.');
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
            abort(403, 'Only active group members can upload.');
        }

        if ($member->isPostingSuspended()) {
            abort(403, 'Your posting privileges are suspended.');
        }
    }

    private function assertCanManageItem(Request $request, Group $group, GroupLibraryItem $item): void
    {
        $user = $request->user();
        if (! $user) {
            abort(403);
        }

        if ((int) $item->uploader_user_id === (int) $user->id) {
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
