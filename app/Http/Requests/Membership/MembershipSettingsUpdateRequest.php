<?php

namespace App\Http\Requests\Membership;

use App\Enums\MembershipJoinMethod;
use App\Enums\MembershipType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $membershipsEnabled = $this->boolean('memberships_enabled');

        return [
            'memberships_enabled' => ['required', 'boolean'],
            'account_type' => ['nullable', 'string', Rule::in(\App\Enums\MembershipAccountType::values())],
            'account_id' => ['nullable', 'integer', 'min:1'],
            'membership_name' => [
                Rule::requiredIf($membershipsEnabled),
                'nullable',
                'string',
                'max:255',
            ],
            'membership_type' => [
                Rule::requiredIf($membershipsEnabled),
                'nullable',
                'string',
                Rule::in(MembershipType::values()),
            ],
            'membership_fee' => [
                Rule::requiredIf($membershipsEnabled && $this->input('membership_type') === MembershipType::Paid->value),
                'nullable',
                'numeric',
                'min:0',
            ],
            'billing_frequency' => ['nullable', 'string', 'max:32'],
            'join_method' => [
                Rule::requiredIf($membershipsEnabled),
                'nullable',
                'string',
                Rule::in(MembershipJoinMethod::values()),
            ],
        ];
    }
}
