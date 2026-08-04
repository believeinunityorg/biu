<?php

namespace App\Http\Requests\CommunicationHub;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModerateRequest extends FormRequest
{
    /**
     * Known moderation actions across announcements, discussions, comments, and replies.
     *
     * @var list<string>
     */
    public const ACTIONS = [
        'hide',
        'unhide',
        'restore',
        'restore_deleted',
        'lock',
        'unlock',
        'pin',
        'unpin',
        'archive',
        'unarchive',
        'suspend_posting',
        'unsuspend_posting',
        'approve',
        'delete',
        'publish',
        'schedule',
        'toggle_comments',
    ];

    /**
     * Authorization is enforced via the relevant CommunicationHub policy's moderate ability in the controller.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'reason' => ['nullable', 'string', 'max:2000'],
            'scheduled_at' => ['required_if:action,schedule', 'nullable', 'date'],
        ];
    }
}
