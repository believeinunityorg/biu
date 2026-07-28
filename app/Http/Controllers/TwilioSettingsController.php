<?php

namespace App\Http\Controllers;

use App\Services\TwilioConfigService;
use App\Services\TwilioTestService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TwilioSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('role:admin');
    }

    public function index()
    {
        if (auth()->user()->role !== 'admin') {
            abort(403, 'Only administrators can access Twilio settings.');
        }

        return Inertia::render('settings/twilio', [
            'settings' => TwilioConfigService::adminSettingsPayload(),
        ]);
    }

    public function update(Request $request)
    {
        if (auth()->user()->role !== 'admin') {
            abort(403, 'Only administrators can update Twilio settings.');
        }

        $validated = $request->validate([
            'account_sid' => ['nullable', 'string', 'max:64'],
            'auth_token' => ['nullable', 'string', 'max:255'],
            'whatsapp_from' => ['nullable', 'string', 'max:64'],
            'sms_from' => ['nullable', 'string', 'max:64'],
            'sms_messaging_service_sid' => ['nullable', 'string', 'max:64'],
            'mode_environment' => ['required', 'string', 'in:sandbox,live'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $existing = TwilioConfigService::fromDatabase();
        $sid = trim((string) ($validated['account_sid'] ?? ''));
        $token = trim((string) ($validated['auth_token'] ?? ''));

        if ($sid === '' && empty($existing['account_sid'] ?? null)) {
            return back()->withErrors([
                'account_sid' => 'Account SID is required.',
            ]);
        }

        if ($token === '' && empty($existing['auth_token'] ?? null)) {
            return back()->withErrors([
                'auth_token' => 'Auth Token is required the first time you save.',
            ]);
        }

        if ($sid !== '' && ! str_starts_with($sid, 'AC')) {
            return back()->withErrors([
                'account_sid' => 'Account SID should start with AC (use Live credentials for WhatsApp sandbox).',
            ]);
        }

        $whatsappFrom = trim((string) ($validated['whatsapp_from'] ?? ''));
        $whatsappFrom = preg_replace('/^whatsapp:/i', '', $whatsappFrom) ?? '';
        $whatsappFrom = trim($whatsappFrom);
        if ($whatsappFrom !== '') {
            $whatsappFrom = 'whatsapp:'.$whatsappFrom;
        }

        TwilioConfigService::save([
            'account_sid' => $sid !== '' ? $sid : null,
            'auth_token' => $token !== '' ? $token : null,
            'whatsapp_from' => $whatsappFrom !== '' ? $whatsappFrom : null,
            'sms_from' => $validated['sms_from'] ?? null,
            'sms_messaging_service_sid' => $validated['sms_messaging_service_sid'] ?? null,
            'mode_environment' => $validated['mode_environment'],
            'enabled' => $request->boolean('enabled', true),
        ]);

        return redirect()->back()->with('success', 'Twilio settings saved. WhatsApp and SMS will use these credentials.');
    }

    public function test(Request $request, TwilioTestService $tester)
    {
        if (auth()->user()->role !== 'admin') {
            abort(403, 'Only administrators can test Twilio.');
        }

        $validated = $request->validate([
            'channel' => ['required', 'string', 'in:connection,whatsapp,sms'],
            'to' => ['nullable', 'string', 'max:32'],
        ]);

        $result = $tester->run(
            $validated['channel'],
            $validated['to'] ?? null,
        );

        return redirect()->back()->with([
            $result['ok'] ? 'success' : 'error' => $result['message'],
            'twilio_test' => $result,
        ]);
    }
}
