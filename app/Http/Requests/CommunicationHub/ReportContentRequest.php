<?php

namespace App\Http\Requests\CommunicationHub;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReportContentRequest extends FormRequest
{
    /**
     * Authorization is enforced via OrganizationDiscussionPolicy::report() in the controller.
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
            'reason' => ['required', 'string', Rule::in(array_keys(config('communication_hub.report_reasons', [])))],
            'details' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
