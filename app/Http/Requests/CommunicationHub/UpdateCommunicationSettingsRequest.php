<?php

namespace App\Http\Requests\CommunicationHub;

use App\Services\CommunicationHub\CommunicationHubPermissionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCommunicationSettingsRequest extends FormRequest
{
    /**
     * Authorization is enforced via a manage-organization / staff check in the controller.
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
        $audiences = CommunicationHubPermissionService::VISIBILITY_AUDIENCES;

        return [
            'announcement_visibility' => ['sometimes', 'string', Rule::in($audiences)],
            'discussion_visibility' => ['sometimes', 'string', Rule::in($audiences)],
            'allow_followers_to_post' => ['sometimes', 'boolean'],
            'allow_members_to_post' => ['sometimes', 'boolean'],
            'require_approval' => ['sometimes', 'boolean'],
            'allow_attachments' => ['sometimes', 'boolean'],
            'allow_mentions' => ['sometimes', 'boolean'],
            'enable_reactions' => ['sometimes', 'boolean'],
            'enable_comments' => ['sometimes', 'boolean'],
            'enable_reporting' => ['sometimes', 'boolean'],
            'auto_archive_days' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
