<?php

namespace App\Http\Controllers\Membership;

use App\Enums\MembershipAccountType;
use App\Http\Controllers\Controller;
use App\Models\CareAlliance;
use App\Models\Group;
use App\Models\Organization;
use App\Services\MembershipAccountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MembershipManagementController extends Controller
{
    public function __construct(
        private readonly MembershipAccountService $membershipAccountService,
    ) {}

    public function organization(Request $request): Response|RedirectResponse
    {
        $org = Organization::forAuthUser($request->user());
        if (! $org) {
            return redirect()->route('dashboard')->with('error', 'No organization found.');
        }

        return $this->renderManagementPage($org);
    }

    public function careAlliance(Request $request): Response|RedirectResponse
    {
        $alliance = CareAlliance::query()
            ->where('creator_user_id', $request->user()->id)
            ->first();

        if (! $alliance) {
            return redirect()->route('dashboard')->with('error', 'No Unity Impact Alliance found.');
        }

        return $this->renderManagementPage($alliance, settingsBranding: 'alliance');
    }

    public function group(Request $request, Group $group): Response|RedirectResponse
    {
        $this->membershipAccountService->authorizeManagement($request->user(), $group);

        return $this->renderManagementPage($group);
    }

    private function renderManagementPage(
        Organization|CareAlliance|Group $account,
        string $settingsBranding = 'default',
    ): Response {
        $payload = $this->membershipAccountService->managementPayload($account);
        $accountType = MembershipAccountType::fromModel($account);

        return Inertia::render('Membership/Index', [
            'membership' => $payload,
            'settingsBranding' => $settingsBranding,
            'membershipRoute' => match ($accountType) {
                MembershipAccountType::Organization => route('organization.membership.index'),
                MembershipAccountType::UnityImpactAlliance => route('care-alliance.membership.index'),
                MembershipAccountType::Group => route('groups.membership.index', $account),
            },
            'profileSettingsUrl' => match ($accountType) {
                MembershipAccountType::Organization, MembershipAccountType::UnityImpactAlliance => route('profile.edit').'#membership-settings',
                MembershipAccountType::Group => route('groups.profile.edit', $account).'#membership-settings',
            },
        ]);
    }
}
