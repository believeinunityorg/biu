<?php

namespace App\Http\Requests\CommunicationHub;

use App\Models\OrganizationAnnouncement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAnnouncementCommentRequest extends FormRequest
{
    /**
     * Authorization is enforced via OrganizationAnnouncementCommentPolicy::create() in the controller.
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
        $announcementId = $this->resolveAnnouncementId();

        return [
            'body' => ['required', 'string', 'max:5000'],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_announcement_comments', 'id')->where(
                    fn ($query) => $announcementId ? $query->where('announcement_id', $announcementId) : $query->whereRaw('1 = 0')
                ),
            ],
        ];
    }

    protected function resolveAnnouncementId(): ?int
    {
        $announcement = $this->route('announcement');
        if ($announcement instanceof OrganizationAnnouncement) {
            return (int) $announcement->id;
        }

        return is_numeric($announcement) ? (int) $announcement : null;
    }
}
