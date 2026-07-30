<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\ContentItem;
use App\Models\User;
use App\Jobs\SendCampaignCreatedEmails;
use App\Services\CampaignPlanner;
use App\Services\OpenAiService;
use App\Support\CampaignDeliveryChannels;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AiCampaignController extends Controller
{
    protected $openAiService;

    public function __construct(OpenAiService $openAiService)
    {
        $this->openAiService = $openAiService;
    }

    public function create()
    {
        $organizationId = auth()->user()->organization->id;

        $followers = auth()->user()->organization->followers()
            ->where('login_status', true)
            ->select('users.id', 'users.name', 'users.email', 'users.contact_number', 'users.whatsapp_opt_in', 'users.push_token')
            ->orderBy('users.name')
            ->get();

        return Inertia::render('Campaigns/AiCreate', [
            'defaultChannels' => ['web'],
            'users' => $followers,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date|after_or_equal:today',
            'send_time_local' => 'required|date_format:H:i',
            'channels' => 'required|array|min:1',
            'channels.*' => CampaignDeliveryChannels::validationRule(),
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'exists:users,id',
            'prompt' => 'required|string|min:10|max:1000',
            'content_count' => 'required|integer|min:1|max:30',
            'content_type' => 'required|in:prayer,devotional,scripture',
        ]);

        $user = auth()->user();
        $tokensIncluded = (int) ($user->ai_tokens_included ?? 0);
        $tokensUsed = (int) ($user->ai_tokens_used ?? 0);
        if ($tokensIncluded > 0 && $tokensUsed >= $tokensIncluded) {
            return back()
                ->withErrors([
                    'error' => 'You have used all your AI tokens for this period. Please add more tokens or upgrade your plan to continue.',
                ])
                ->withInput();
        }

        try {
            Log::info('Starting AI content generation', [
                'count' => $validated['content_count'],
                'type' => $validated['content_type'],
            ]);

            $result = $this->openAiService->generateContent(
                $validated['prompt'],
                $validated['content_count'],
                $validated['content_type']
            );
            $generatedContent = $result['items'] ?? $result;
            $totalTokens = (int) ($result['total_tokens'] ?? 0);
            if ($totalTokens > 0) {
                $user->consumeAiTokens($totalTokens);
            }

            Log::info('Content generated successfully', [
                'count' => count($generatedContent),
            ]);

            $organizationId = auth()->user()->organization->id;
            $contentItemIds = [];

            // Create content items
            foreach ($generatedContent as $content) {
                $contentItem = ContentItem::create([
                    'organization_id' => $organizationId,
                    'user_id' => auth()->id(),
                    'title' => $content['title'],
                    'body' => $content['body'],
                    'type' => $validated['content_type'],
                    'meta' => [
                        'scripture_ref' => $content['scripture_ref'] ?? '',
                        'tags' => $content['tags'] ?? [],
                        'generated_by_ai' => true,
                    ],
                    'is_approved' => true,
                ]);

                $contentItemIds[] = $contentItem->id;
            }

            // Calculate end date based on content count
            $startDate = Carbon::parse($validated['start_date']);
            $endDate = $startDate->copy()->addDays(count($contentItemIds) - 1);

            // Create campaign
            $campaign = Campaign::create([
                'organization_id' => $organizationId,
                'user_id' => auth()->id(),
                'name' => $validated['name'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'send_time_local' => $validated['send_time_local'],
                'channels' => $validated['channels'],
            ]);

            $campaign->selectedUsers()->attach($validated['user_ids']);

            // Get created content items and plan campaign
            $contentItems = ContentItem::whereIn('id', $contentItemIds)->get();
            $scheduledCount = CampaignPlanner::planDailyCampaign($campaign, $contentItems);

            if (CampaignDeliveryChannels::includesEmail($validated['channels'])) {
                SendCampaignCreatedEmails::dispatch($campaign->id);
            }

            Log::info('Campaign created successfully', [
                'campaign_id' => $campaign->id,
                'content_count' => count($contentItemIds),
                'scheduled_count' => $scheduledCount,
            ]);

            return redirect()->route('campaigns.show', $campaign)
                ->with('success', "AI Campaign created successfully! Generated " . count($contentItemIds) . " prayers and scheduled {$scheduledCount} daily deliveries for {$campaign->selectedUsers()->count()} users.");
        } catch (\Exception $e) {
            Log::error('AI Campaign creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()
                ->withErrors(['error' => 'Failed to generate content: ' . $e->getMessage()])
                ->withInput();
        }
    }
}
