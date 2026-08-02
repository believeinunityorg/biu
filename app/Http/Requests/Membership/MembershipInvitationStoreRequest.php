<?php

namespace App\Http\Requests\Membership;

use App\Enums\MembershipAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MembershipInvitationStoreRequest extends FormRequest
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
        return [
            'account_type' => ['required', 'string', Rule::in(MembershipAccountType::values())],
            'account_id' => ['required', 'integer', 'min:1'],
            'email' => ['required', 'email', 'max:255'],
        ];
    }
}
