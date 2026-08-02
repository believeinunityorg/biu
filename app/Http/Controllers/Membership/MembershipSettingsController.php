<?php

namespace App\Http\Controllers\Membership;

use App\Http\Controllers\Controller;
use App\Http\Requests\Membership\MembershipSettingsUpdateRequest;
use App\Services\MembershipAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MembershipSettingsController extends Controller
{
    public function __construct(
        private readonly MembershipAccountService $membershipAccountService,
    ) {}

    public function update(MembershipSettingsUpdateRequest $request): RedirectResponse
    {
        $resolved = $this->membershipAccountService->resolveForUser(
            $request->user(),
            $request->input('account_type'),
            $request->integer('account_id') ?: null,
        );

        $this->membershipAccountService->updateSettings(
            $resolved['account'],
            $request->validated(),
        );

        return back()->with('success', 'Membership settings saved successfully.');
    }
}
