<?php

namespace App\Http\Requests\CommunicationHub;

use App\Models\Organization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDiscussionRequest extends FormRequest
{
    /**
     * Authorization is enforced via OrganizationDiscussionPolicy in the controller.
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
        $organizationId = $this->resolveOrganizationId();

        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'category_id' => [
                'nullable',
                'integer',
                Rule::exists('organization_discussion_categories', 'id')->where(
                    fn ($query) => $organizationId ? $query->where('organization_id', $organizationId) : $query->whereRaw('1 = 0')
                ),
            ],
            'attachments' => ['nullable', 'array'],
            'attachments.*' => ['file', 'max:10240'],
        ];
    }

    /**
     * Resolve the organization scope from the route (organization model/id binding) or the
     * authenticated user's own organization when creating from a self-service dashboard.
     */
    protected function resolveOrganizationId(): ?int
    {
        $route = $this->route('organization');
        if ($route instanceof Organization) {
            return (int) $route->id;
        }
        if (is_numeric($route)) {
            return (int) $route;
        }

        return Organization::forAuthUser($this->user())?->id;
    }
}
