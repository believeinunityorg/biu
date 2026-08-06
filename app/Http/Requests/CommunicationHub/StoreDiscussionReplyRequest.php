<?php

namespace App\Http\Requests\CommunicationHub;

use App\Models\OrganizationDiscussion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDiscussionReplyRequest extends FormRequest
{
    /**
     * Authorization is enforced via OrganizationDiscussionPolicy::reply() in the controller.
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
        $discussionId = $this->resolveDiscussionId();

        return [
            'body' => ['required', 'string'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_discussion_replies', 'id')->where(
                    fn ($query) => $discussionId ? $query->where('discussion_id', $discussionId) : $query->whereRaw('1 = 0')
                ),
            ],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240'],
        ];
    }

    protected function resolveDiscussionId(): ?int
    {
        $discussion = $this->route('discussion');
        if ($discussion instanceof OrganizationDiscussion) {
            return (int) $discussion->id;
        }

        return is_numeric($discussion) ? (int) $discussion : null;
    }
}
