<?php

namespace App\Http\Requests\CommunicationHub;

use App\Enums\OrganizationAnnouncementStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementRequest extends FormRequest
{
    /**
     * Authorization is enforced via OrganizationAnnouncementPolicy in the controller.
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
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'category' => ['nullable', 'string', Rule::in(config('communication_hub.announcement_categories', []))],
            'cover_image' => ['nullable', 'image', 'max:5120'],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240'],
            'published_at' => ['nullable', 'date'],
            'scheduled_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'is_pinned' => ['sometimes', 'boolean'],
            'allow_comments' => ['sometimes', 'boolean'],
            'status' => ['nullable', 'string', Rule::in(array_map(fn (OrganizationAnnouncementStatus $case) => $case->value, OrganizationAnnouncementStatus::cases()))],
            'publish_now' => ['sometimes', 'boolean'],
        ];
    }
}
