<?php

namespace App\Http\Controllers;

use App\Services\SupporterOnboardingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SupporterOnboardingController extends Controller
{
    public function __construct(
        private readonly SupporterOnboardingService $onboardingService,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $this->onboardingService->needsOnboarding($user)) {
            return redirect()->route('users.show', $user->slug ?? $user->id);
        }

        return Inertia::render(
            'frontend/supporter-onboarding/Index',
            $this->onboardingService->payloadForUser($user, $request),
        );
    }

    public function saveStep(Request $request)
    {
        $user = $request->user();

        if (! $this->onboardingService->needsOnboarding($user)) {
            return redirect()->route('users.show', $user->slug ?? $user->id);
        }

        $result = $this->onboardingService->saveStep($user, $request);

        if (! ($result['success'] ?? false)) {
            return back()->withErrors($result['errors'] ?? [])->withInput();
        }

        return back();
    }

    public function finish(Request $request): RedirectResponse|\Symfony\Component\HttpFoundation\Response
    {
        $user = $request->user();

        if (! $this->onboardingService->needsOnboarding($user)) {
            $slug = trim((string) ($user->slug ?? ''));

            return redirect()->route('users.show', $slug !== '' ? $slug : $user->id);
        }

        $validated = $request->validate([
            'destination' => ['nullable', 'string', Rule::in(['dashboard', 'organizations'])],
        ]);

        try {
            $url = $this->onboardingService->finish(
                $user,
                $validated['destination'] ?? 'dashboard',
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        } catch (\Throwable $e) {
            Log::error('Supporter onboarding finish failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors([
                'finish' => 'We could not complete your setup. Please try again.',
            ]);
        }

        return Inertia::location($url);
    }
}
