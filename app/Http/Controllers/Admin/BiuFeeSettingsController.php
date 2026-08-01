<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Services\BiuPlatformFeeService;
use App\Support\BiuPlatformFeeModuleRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BiuFeeSettingsController extends Controller
{
    public function index(): Response
    {
        $values = BiuPlatformFeeModuleRegistry::currentValues();

        return Inertia::render('admin/biu-fee/Index', [
            ...$values,
            'groups' => BiuPlatformFeeModuleRegistry::groups(),
            'modules' => BiuPlatformFeeModuleRegistry::modulesForAdmin($values),
            'editable_fields' => BiuPlatformFeeModuleRegistry::editableSettingFields(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (BiuPlatformFeeModuleRegistry::editableSettingFields() as $field) {
            if ($field === 'gift_card_platform_fee_usd') {
                $rules[$field] = ['required', 'numeric', 'min:0', 'max:100'];
            } else {
                $rules[$field] = ['required', 'numeric', 'min:0', 'max:100'];
            }
        }

        $validated = $request->validate($rules);

        $settingMap = [
            'sales_platform_fee_percentage' => BiuPlatformFeeService::SETTING_KEY_SALES,
            'course_platform_fee_percentage' => BiuPlatformFeeService::SETTING_KEY_COURSE,
            'event_platform_fee_percentage' => BiuPlatformFeeService::SETTING_KEY_EVENT,
            'marketplace_printify_organization_fee_percentage' => BiuPlatformFeeService::SETTING_KEY_MARKETPLACE_PRINTIFY_ORG,
            'marketplace_merchant_pool_fee_percentage' => BiuPlatformFeeService::SETTING_KEY_MARKETPLACE_MERCHANT_POOL,
            'gift_card_platform_fee_usd' => BiuPlatformFeeService::SETTING_KEY_GIFT_CARD,
        ];

        foreach ($settingMap as $field => $key) {
            if (! array_key_exists($field, $validated)) {
                continue;
            }

            $value = $field === 'gift_card_platform_fee_usd'
                ? round((float) $validated[$field], 2)
                : (float) $validated[$field];

            AdminSetting::set($key, $value, 'float');
        }

        return redirect()
            ->route('admin.biu-fee.index')
            ->with('success', 'Platform fee settings saved.');
    }
}
