<?php

namespace App\Http\Requests\Settings;

use App\Models\CommunityOrganizationType;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            'phone' => ['nullable', 'string'],
            'dob' => ['nullable', 'date'],
        ];

        if ($this->user()->hasRole('care_alliance')) {
            $rules = array_merge($rules, [
                'alliance_name' => ['required', 'string', 'max:255'],
                'description' => ['required', 'string'],
                'website' => ['nullable', 'string', 'url'],
                'alliance_city' => ['nullable', 'string', 'max:128'],
                'alliance_state' => ['nullable', 'string', 'max:64'],
                'alliance_ein' => ['nullable', 'string', 'max:32'],
                'primary_action_category_ids' => ['required', 'array', 'min:1'],
                'primary_action_category_ids.*' => ['integer', 'distinct', Rule::exists('primary_action_categories', 'id')->where('is_active', true)],
            ]);
        } elseif ($this->user()->role === 'organization') {
            $otherId = CommunityOrganizationType::otherId();
            $familyReunionId = CommunityOrganizationType::familyReunionId();

            $rules = array_merge($rules, [
                'contact_title' => ['required', 'string'],
                'website' => ['nullable', 'string', 'url'],
                'wefunder_project_url' => ['nullable', 'string', 'url', 'max:500'],
                'description' => ['required', 'string'],
                'mission' => ['required', 'string'],
                'gift_card_terms_approved' => ['nullable', 'boolean'],
                'primary_action_category_ids' => ['required', 'array', 'min:1'],
                'primary_action_category_ids.*' => ['integer', 'distinct', Rule::exists('primary_action_categories', 'id')->where('is_active', true)],
                'community_organization_type_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('community_organization_types', 'id')->where('is_active', true),
                ],
                'community_organization_type_other' => [
                    Rule::requiredIf(fn () => (int) $this->input('community_organization_type_id') === (int) $otherId),
                    'nullable',
                    'string',
                    'max:255',
                ],
                'family_name' => ['nullable', 'string', 'max:255'],
                'family_motto' => ['nullable', 'string', 'max:255'],
                'family_history' => ['nullable', 'string', 'max:5000'],
                'reunion_established' => ['nullable', 'date'],
                'reunion_frequency' => ['nullable', 'string', 'max:64'],
                'reunion_location' => ['nullable', 'string', 'max:255'],
                'tree_visibility' => ['nullable', 'string', 'in:public,family_members_only,branch_members_only,administrators_only'],
                'allow_members_add_children' => ['nullable', 'boolean'],
                'allow_members_invite_relatives' => ['nullable', 'boolean'],
                'require_member_approval' => ['nullable', 'boolean'],
                'allow_branch_administrators' => ['nullable', 'boolean'],
                'grandfather_name' => [
                    Rule::requiredIf(fn () => (int) $this->input('community_organization_type_id') === (int) $familyReunionId),
                    'nullable',
                    'string',
                    'max:255',
                ],
                'grandmother_name' => [
                    Rule::requiredIf(fn () => (int) $this->input('community_organization_type_id') === (int) $familyReunionId),
                    'nullable',
                    'string',
                    'max:255',
                ],
            ]);
        }

        return $rules;
    }
}
