<?php

namespace App\Http\Controllers;

use App\Models\FundMeCampaign;
use App\Models\FundraiseLead;
use App\Services\SeoService;
use App\Services\TurnstileService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class FundraiseController extends Controller
{
    private const WEFUNDER_URL = 'https://wefunder.com/raise?utm_source=believeinunity&utm_medium=partner&utm_campaign=qualified_founders';

    /**
     * Public branded funnel (Option 3): Step 1 Explain → Step 2 Qualify form → Step 3 Redirect to Wefunder.
     * Captures leads; no login required.
     */
    public function index()
    {
        return Inertia::render('frontend/Fundraise', [
            'seo' => SeoService::forPage('fundraise'),
            'wefunderUrl' => self::WEFUNDER_URL,
        ]);
    }

    /**
     * Public: Support a Project — Choose how to participate: Give (Donation / FundMe) or Grow (Investment / Wefunder).
     */
    public function supportAProject()
    {
        return Inertia::render('frontend/SupportAProject', [
            'seo' => array_merge(SeoService::forPage('fundraise'), ['title' => 'Support Mission Projects']),
            'fundMeUrl' => route('fundme.index'),
            'investUrl' => route('fundraise'),
            'projectsUrl' => route('fundraise.applications'),
            'wefunderUrl' => self::WEFUNDER_URL,
        ]);
    }

    /**
     * Org-only: Support Community Projects (Donation vs Investment cards, Wefunder).
     */
    public function communityProjects(Request $request)
    {
        $user = $request->user();
        $org = $user?->organization;
        if (!$org) {
            abort(403, 'Organization accounts only. No organization associated with your account.');
        }

        $projects = FundMeCampaign::forOrganization($org->id)
            ->orderByDesc('updated_at')
            ->get(['id', 'title', 'slug', 'status'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'title' => $c->title,
                'slug' => $c->slug,
                'status' => $c->status,
            ]);

        return Inertia::render('frontend/FundraiseCommunityProjects', [
            'seo' => array_merge(SeoService::forPage('fundraise'), ['title' => 'Support Community Projects']),
            'wefunderUrl' => self::WEFUNDER_URL,
            'fundraiseApplyUrl' => route('fundraise'),
            'projects' => $projects,
            'fundMeCreateUrl' => route('fundme.campaigns.create'),
            'fundMeIndexUrl' => route('fundme.index'),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate(array_merge([
            'name' => 'required|string|max:255',
            'company' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'project_summary' => 'required|string|max:2000',
        ], TurnstileService::rules()));

        try {
            FundraiseLead::create($validated);
            Log::info('Fundraise lead captured', [
                'email' => $validated['email'],
                'company' => $validated['company'],
            ]);
        } catch (\Exception $e) {
            Log::error('Fundraise lead save failed', [
                'error' => $e->getMessage(),
                'data' => $validated,
            ]);
        }

        return Inertia::location(self::WEFUNDER_URL);
    }

    /**
     * Redirect to Project Applications: supporters → profile; organization users → dashboard.
     */
    public function projectApplications(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return redirect()->route('login', ['redirect' => $request->fullUrl()]);
        }

        if ($user->hasRole(['organization', 'organization_pending', 'admin'])) {
            return redirect()->route('dashboard.project-applications');
        }

        return redirect()->route('user.profile.project-applications');
    }

    /**
     * Dashboard Project Applications — for organization users. Shows only applications submitted by the current user (email).
     */
    public function dashboardProjectApplications(Request $request)
    {
        $user = $request->user();
        $query = FundraiseLead::query()
            ->where('email', $user->email)
            ->orderByDesc('created_at');

        $leads = $query->paginate(15)->withQueryString();

        $leads->getCollection()->transform(fn (FundraiseLead $lead) => [
            'id' => $lead->id,
            'name' => $lead->name,
            'company' => $lead->company,
            'email' => $lead->email,
            'project_summary' => $lead->project_summary,
            'wefunder_project_url' => $lead->wefunder_project_url,
            'created_at' => $lead->created_at->toIso8601String(),
        ]);

        return Inertia::render('dashboard/ProjectApplications', [
            'projectApplicationsLeads' => $leads,
            'projectApplicationsTotal' => $leads->total(),
        ]);
    }

    /**
     * Update project application (e.g. set Wefunder link when approved). Allowed if lead email matches user or user is admin.
     */
    public function updateProjectApplication(Request $request, FundraiseLead $lead)
    {
        $user = $request->user();
        if ($lead->email !== $user->email && !$user->hasRole('admin')) {
            abort(403, 'You can only update your own project application.');
        }

        $validated = $request->validate([
            'wefunder_project_url' => ['nullable', 'string', 'url', 'max:500'],
        ]);

        $lead->update($validated);

        if ($request->wantsJson() || $request->header('X-Inertia')) {
            return back()->with('success', 'Wefunder link updated.');
        }
        return redirect()->route('dashboard.project-applications')->with('success', 'Wefunder link updated.');
    }
}
