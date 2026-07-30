<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\AiChatConversation;
use App\Models\AiChatMessage;
use App\Models\Campaign;
use App\Models\ContentItem;
use App\Jobs\SendCampaignCreatedEmails;
use App\Services\OpenAiService;
use App\Services\CampaignPlanner;
use App\Support\CampaignDeliveryChannels;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class AiChatController extends Controller
{
    protected $openAiService;

    public function __construct(OpenAiService $openAiService)
    {
        $this->openAiService = $openAiService;
    }

    public function index(Request $request)
    {
        $user = auth()->user();
        $conversationId = $request->get('conversation_id');
        
        // For organization users, scope conversations to the organization's primary user
        // This ensures each organization has its own memory
        $userIdForConversations = $user->id;
        if ($user->role === 'organization' && $user->organization) {
            // Use the organization's primary user_id for conversations
            $userIdForConversations = $user->organization->user_id ?? $user->id;
        }
        
        // Get conversations list with pagination
        $conversations = AiChatConversation::where('user_id', $userIdForConversations)
            ->orderBy('last_message_at', 'desc')
            ->paginate(20, ['*'], 'page', $request->get('page', 1));

        // Get current conversation if specified
        $currentConversation = null;
        $messages = [];

        if ($conversationId) {
            $currentConversation = AiChatConversation::where('user_id', $userIdForConversations)
                ->where('id', $conversationId)
                ->with(['messages' => function ($query) {
                    $query->orderBy('created_at');
                }])
                ->first();

            if ($currentConversation) {
                $messages = $currentConversation->messages->map(function ($msg) {
                    return [
                        'id' => $msg->id,
                        'role' => $msg->role,
                        'content' => $msg->content,
                        'timestamp' => $msg->created_at,
                    ];
                })->toArray();
            }
        }
        
        return Inertia::render('AiChat/Index', [
            'credits' => $user->credits ?? 0,
            'conversations' => [
                'data' => $conversations->items(),
                'current_page' => $conversations->currentPage(),
                'has_more_pages' => $conversations->hasMorePages(),
            ],
            'currentConversation' => $currentConversation,
            'messages' => $messages,
        ]);
    }

    public function getConversations(Request $request)
    {
        $user = auth()->user();
        $page = $request->get('page', 1);
        
        // For organization users, scope conversations to the organization's primary user
        $userIdForConversations = $user->id;
        if ($user->role === 'organization' && $user->organization) {
            $userIdForConversations = $user->organization->user_id ?? $user->id;
        }
        
        $conversations = AiChatConversation::where('user_id', $userIdForConversations)
            ->orderBy('last_message_at', 'desc')
            ->paginate(20, ['*'], 'page', $page);

        return response()->json([
            'conversations' => $conversations->items(),
            'has_more' => $conversations->hasMorePages(),
            'current_page' => $conversations->currentPage(),
        ]);
    }

    public function getConversation($id)
    {
        $user = auth()->user();
        
        $conversation = AiChatConversation::where('user_id', $user->id)
            ->where('id', $id)
            ->with(['messages' => function ($query) {
                $query->orderBy('created_at');
            }])
            ->firstOrFail();

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'user_id' => $conversation->user_id,
                'title' => $conversation->title,
                'last_message_at' => $conversation->last_message_at,
                'created_at' => $conversation->created_at,
                'updated_at' => $conversation->updated_at,
            ],
            'messages' => $conversation->messages->map(function ($msg) {
                return [
                    'id' => $msg->id,
                    'role' => $msg->role,
                    'content' => $msg->content,
                    'timestamp' => $msg->created_at,
                ];
            }),
        ]);
    }

    public function sendMessage(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|min:1|max:2000',
            'conversation_id' => 'nullable|exists:ai_chat_conversations,id',
        ]);

        $user = auth()->user();
        $creditsNeeded = 1; // kept for optional future use; AI chat is token-based now
        $currentCredits = $user->credits ?? 0;

        // Token-based allowance: only allow if user has remaining AI tokens (when plan has a limit)
        $tokensIncluded = (int) ($user->ai_tokens_included ?? 0);
        $tokensUsed = (int) ($user->ai_tokens_used ?? 0);
        if ($tokensIncluded > 0 && $tokensUsed >= $tokensIncluded) {
            return response()->json([
                'success' => false,
                'error' => 'insufficient_tokens',
                'message' => 'You have used all your AI tokens for this period. Please upgrade your plan or wait for your next allocation.',
                'ai_tokens_used' => $tokensUsed,
                'ai_tokens_included' => $tokensIncluded,
            ], 402);
        }

        try {
            // For organization users, use the organization's primary user_id for conversations
            // This ensures each organization has its own memory
            $userIdForConversations = $user->id;
            if ($user->role === 'organization' && $user->organization) {
                $userIdForConversations = $user->organization->user_id ?? $user->id;
            }
            
            // Get or create conversation
            if ($validated['conversation_id']) {
                $conversation = AiChatConversation::where('user_id', $userIdForConversations)
                    ->where('id', $validated['conversation_id'])
                    ->firstOrFail();
            } else {
                // Create new conversation
                $conversation = AiChatConversation::create([
                    'user_id' => $userIdForConversations,
                    'title' => Str::limit($validated['message'], 50),
                    'last_message_at' => now(),
                ]);
            }

            // Save user message
            $userMessage = AiChatMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'user',
                'content' => $validated['message'],
            ]);

            // Get conversation history from database
            $historyMessages = AiChatMessage::where('conversation_id', $conversation->id)
                ->orderBy('created_at')
                ->get();

            // Check if this is the first message (only 1 user message exists) and it's a simple greeting
            $isFirstMessage = $historyMessages->where('role', 'user')->count() === 1;
            $isSimpleGreeting = $this->isSimpleGreeting($validated['message']);
            
            if ($isFirstMessage && $isSimpleGreeting) {
                // Return branded welcome message for first greetings
                $welcomeMessage = "Hello! I'm your AI Believe Assistant, here to help you with your organization's needs. I can assist you with:\n\n" .
                    "• Creating and managing campaigns\n" .
                    "• Generating content (prayers, devotionals, scriptures)\n" .
                    "• Answering questions about your campaigns and content\n" .
                    "• Providing insights and suggestions\n\n" .
                    "Feel free to ask me anything! How can I help you today?";
                
                // Save assistant message
                $assistantMessage = AiChatMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $welcomeMessage,
                ]);

                // Update conversation timestamp
                $conversation->update(['last_message_at' => now()]);

                // No credit deduction — AI chat uses token-based usage only
                return response()->json([
                    'success' => true,
                    'response' => $welcomeMessage,
                    'conversation_id' => $conversation->id,
                    'ai_tokens_used' => $user->ai_tokens_used,
                    'ai_tokens_included' => $user->ai_tokens_included,
                ]);
            }

            // Check for title generation requests first
            $isTitleGenerationRequest = $this->isTitleGenerationRequest($validated['message']);
            
            if ($isTitleGenerationRequest) {
                // Fetch campaigns and content data for context if available
                $campaignsData = null;
                $contentData = null;
                if ($this->isCampaignQuery($validated['message'])) {
                    $campaignsData = $this->getUserCampaigns($user);
                }
                if ($this->isContentQuery($validated['message'])) {
                    $contentData = $this->getUserContent($user);
                }
                
                $titleSuggestions = $this->generateTitleSuggestions($validated['message'], $user, $campaignsData ?: $contentData);
                
                // Save assistant message
                AiChatMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $titleSuggestions,
                ]);

                // Update conversation timestamp
                $conversation->update(['last_message_at' => now()]);

                return response()->json([
                    'success' => true,
                    'response' => $titleSuggestions,
                    'conversation_id' => $conversation->id,
                    'ai_tokens_used' => $user->ai_tokens_used,
                    'ai_tokens_included' => $user->ai_tokens_included,
                    'is_title_generation' => true,
                ]);
            }

            // Check for content operations
            // BUT skip if it's actually a campaign status update (has "status" with "active/inactive/paused/cancelled")
            $isCampaignStatusUpdate = preg_match('/\bstatus\s+(?:active|inactive|paused|cancelled)/i', $validated['message']) && 
                                       stripos($validated['message'], 'content') === false &&
                                       (stripos($validated['message'], 'campaign') !== false || stripos($validated['message'], 'first one') !== false || stripos($validated['message'], 'first campaign') !== false);
            
            if ($isCampaignStatusUpdate) {
                Log::info('Skipping content operation check - detected as campaign status update', [
                    'message' => substr($validated['message'], 0, 100),
                ]);
            } else {
                $contentOperation = $this->detectContentOperation($validated['message']);
                
                Log::info('Checking for content operation', [
                    'message' => substr($validated['message'], 0, 100),
                    'detected_operation' => $contentOperation,
                ]);
                
                if ($contentOperation) {
                    Log::info('Content operation detected', [
                        'operation' => $contentOperation,
                        'user_id' => $user->id,
                        'message' => substr($validated['message'], 0, 100),
                    ]);

                $operationResult = $this->handleContentOperation($contentOperation, $validated['message'], $user, $conversation);
                
                // Get the message content (use 'response' if available, otherwise 'message')
                $responseContent = $operationResult['response'] ?? $operationResult['message'] ?? '';
                
                // Save assistant message with operation result
                AiChatMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $responseContent,
                ]);

                // Update conversation timestamp
                $conversation->update(['last_message_at' => now()]);
                
                if ($operationResult['success']) {
                    Log::info('Content operation successful', [
                        'operation' => $contentOperation,
                        'user_id' => $user->id,
                    ]);

                            return response()->json([
                                'success' => true,
                                'response' => $responseContent,
                                'conversation_id' => $conversation->id,
                                'ai_tokens_used' => $user->ai_tokens_used,
                                'ai_tokens_included' => $user->ai_tokens_included,
                                'operation' => $contentOperation,
                            ]);
                } else {
                    Log::warning('Content operation failed', [
                        'operation' => $contentOperation,
                        'user_id' => $user->id,
                        'error_message' => $operationResult['message'],
                    ]);

                    return response()->json([
                        'success' => false,
                        'error' => 'operation_failed',
                        'message' => $operationResult['message'],
                        'conversation_id' => $conversation->id,
                    ], 400);
                }
                }
            }

            // Check for campaign operations (create/edit/delete) - MUST happen before regular chat
            $campaignOperation = $this->detectCampaignOperation($validated['message']);
            
            Log::info('Checking for campaign operation', [
                'message' => substr($validated['message'], 0, 100),
                'detected_operation' => $campaignOperation,
            ]);
            
            if ($campaignOperation) {
                Log::info('Campaign operation detected', [
                    'operation' => $campaignOperation,
                    'user_id' => $user->id,
                    'message' => substr($validated['message'], 0, 100),
                ]);

                $operationResult = $this->handleCampaignOperation($campaignOperation, $validated['message'], $user, $conversation);
                
                // Debug: Log what we received
                Log::info('Campaign operation result', [
                    'has_show_form' => isset($operationResult['show_form']),
                    'show_form_value' => $operationResult['show_form'] ?? 'not set',
                    'success' => $operationResult['success'] ?? 'not set',
                    'has_users' => isset($operationResult['users']),
                    'users_count' => is_array($operationResult['users'] ?? null) ? count($operationResult['users']) : 0,
                ]);
                
                // Get the message content (use 'response' if available, otherwise 'message')
                $campaignResponseContent = $operationResult['response'] ?? $operationResult['message'] ?? '';
                            
                // Check if form should be shown (even if success is false) - check BEFORE saving message
                if (!empty($operationResult['show_form']) && $operationResult['show_form'] === true) {
                    // Save a message indicating form is being shown
                    $formMessage = $operationResult['message'] ?? 'Please fill in the campaign details below:';
                    AiChatMessage::create([
                        'conversation_id' => $conversation->id,
                        'role' => 'assistant',
                        'content' => $formMessage,
                    ]);
                    
                    // Update conversation timestamp
                    $conversation->update(['last_message_at' => now()]);
                    
                    // Don't deduct credits for showing form
                    Log::info('Campaign operation - showing form', [
                        'operation' => $campaignOperation,
                        'user_id' => $user->id,
                        'form_type' => $operationResult['form_type'] ?? 'unknown',
                        'users_count' => count($operationResult['users'] ?? []),
                        'content_items_count' => count($operationResult['content_items'] ?? []),
                    ]);

                    return response()->json([
                        'success' => true, // Set to true so frontend doesn't treat as error
                        'response' => $formMessage,
                        'conversation_id' => $conversation->id,
                        'credits_remaining' => $user->credits,
                        'credits_deducted' => 0, // No credit deduction for showing form
                        'operation' => $campaignOperation,
                        'show_form' => true,
                        'form_type' => $operationResult['form_type'] ?? 'campaign_create',
                        'form_data' => $operationResult['form_data'] ?? [],
                        'users' => $operationResult['users'] ?? [],
                        'content_items' => $operationResult['content_items'] ?? [],
                        'default_channels' => $operationResult['default_channels'] ?? ['web', 'whatsapp'],
                    ]);
                }

                // Save assistant message with operation result (only if not showing form)
                AiChatMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $campaignResponseContent,
                ]);

                // Update conversation timestamp
                $conversation->update(['last_message_at' => now()]);

                if ($operationResult['success']) {
                    Log::info('Campaign operation successful', [
                        'operation' => $campaignOperation,
                        'user_id' => $user->id,
                    ]);

                    return response()->json([
                        'success' => true,
                        'response' => $campaignResponseContent,
                        'conversation_id' => $conversation->id,
                        'ai_tokens_used' => $user->ai_tokens_used,
                        'ai_tokens_included' => $user->ai_tokens_included,
                        'operation' => $campaignOperation,
                    ]);
                            } else {
                                Log::warning('Campaign operation failed', [
                                    'operation' => $campaignOperation,
                                    'user_id' => $user->id,
                                    'error_message' => $operationResult['message'],
                                ]);

                                return response()->json([
                                    'success' => false,
                                    'error' => 'operation_failed',
                                    'message' => $operationResult['message'],
                                    'conversation_id' => $conversation->id,
                                ], 400);
                            }
                        }

            // Check if user is asking about campaigns or content
            $isCampaignQuery = $this->isCampaignQuery($validated['message']);
            $isContentQuery = $this->isContentQuery($validated['message']);
            
            $campaignsData = null;
            $contentData = null;
            $contentStats = null;

            if ($isCampaignQuery) {
                // Fetch user's campaigns
                $campaignsData = $this->getUserCampaigns($user);
            }
            
            if ($isContentQuery) {
                // Fetch user's content items and statistics
                $contentData = $this->getUserContent($user);
                $contentStats = $this->getContentStats($user);
            }

            // Build messages for OpenAI
            $messages = [];
            
            // Build enhanced system prompt with both campaigns and content data
            $systemPrompt = $this->buildSystemPrompt($campaignsData, $contentData, $contentStats);
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];

            foreach ($historyMessages as $msg) {
                $messages[] = [
                    'role' => $msg->role,
                    'content' => $msg->content,
                ];
            }

            // Call OpenAI API
            $result = $this->openAiService->chatCompletion($messages);
            $response = $result['content'];
            $totalTokens = $result['total_tokens'] ?? 0;

            // Save assistant message
            $assistantMessage = AiChatMessage::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $response,
            ]);

            // Update conversation title if it's the first message
            if (!$conversation->title) {
                $conversation->update([
                    'title' => Str::limit($validated['message'], 50),
                ]);
            }

            // Update last message timestamp
            $conversation->update([
                'last_message_at' => now(),
            ]);

            // Track actual token usage from OpenAI response (real deduction)
            $totalTokens = (int) ($result['total_tokens'] ?? 0);
            if ($totalTokens > 0) {
                $user->consumeAiTokens($totalTokens);
            }
            $user->refresh();

            Log::info('AI Chat message sent', [
                'user_id' => $user->id,
                'conversation_id' => $conversation->id,
                'tokens_used' => $totalTokens,
                'ai_tokens_used_total' => $user->ai_tokens_used,
            ]);

            return response()->json([
                'success' => true,
                'response' => $response,
                'conversation_id' => $conversation->id,
                'tokens_used' => $totalTokens,
                'ai_tokens_used' => $user->ai_tokens_used,
                'ai_tokens_included' => $user->ai_tokens_included,
            ]);
        } catch (\Exception $e) {
            Log::error('AI Chat error', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'api_error',
                'message' => 'Failed to get response: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user context for audio assistant (Campaigns, Content, Stats)
     */
    /*
    public function getContext(Request $request)
    {
        // ... (removed for now as per user request to decouple backend) ...
        return response()->json(['user' => ['name' => 'Guest']]);
    }
    */

    public function updateConversation(Request $request, $id)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
        ]);

        $user = auth()->user();
        
        $conversation = AiChatConversation::where('user_id', $user->id)
            ->where('id', $id)
            ->firstOrFail();

        $conversation->update([
            'title' => $validated['title'],
        ]);

        return response()->json([
            'success' => true,
            'conversation' => [
                'id' => $conversation->id,
                'user_id' => $conversation->user_id,
                'title' => $conversation->title,
                'last_message_at' => $conversation->last_message_at,
                'created_at' => $conversation->created_at,
                'updated_at' => $conversation->updated_at,
            ],
        ]);
    }

    public function deleteConversation($id)
    {
        $user = auth()->user();
        
        // For organization users, scope conversations to the organization's primary user
        $userIdForConversations = $user->id;
        if ($user->role === 'organization' && $user->organization) {
            $userIdForConversations = $user->organization->user_id ?? $user->id;
        }
        
        $conversation = AiChatConversation::where('user_id', $userIdForConversations)
            ->where('id', $id)
            ->firstOrFail();

        $conversation->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Check if the message is a simple greeting
     */
    protected function isSimpleGreeting(string $message): bool
    {
        $message = trim(strtolower($message));
        
        // Common greetings
        $greetings = [
            'hi', 'hello', 'hey', 'hey there', 'hi there', 'hello there',
            'greetings', 'good morning', 'good afternoon', 'good evening',
            'gm', 'gn', 'morning', 'afternoon', 'evening',
            'sup', 'what\'s up', 'whats up', 'wassup',
            'yo', 'howdy', 'hiya', 'hola', 'bonjour'
        ];
        
        // Check if message is exactly a greeting (allowing for punctuation)
        $messageClean = preg_replace('/[^\w\s]/', '', $message);
        
        return in_array($messageClean, $greetings) || 
               in_array($message, $greetings) ||
               preg_match('/^(hi|hello|hey|greetings|good\s+(morning|afternoon|evening))[\s!.,]*$/i', $message);
    }

    /**
     * Check if the user message is asking about campaigns
     */
    protected function isCampaignQuery(string $message): bool
    {
        $message = strtolower($message);
        $campaignKeywords = [
            'campaign', 'campaigns', 'list my campaigns', 'show campaigns',
            'what campaigns', 'my campaigns', 'active campaigns', 'campaign status',
            'campaign details', 'tell me about my campaigns', 'campaign info',
            'get campaigns', 'fetch campaigns', 'all campaigns'
        ];

        foreach ($campaignKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the user message is asking about content
     */
    protected function isContentQuery(string $message): bool
    {
        $message = strtolower($message);
        $contentKeywords = [
            'content', 'contents', 'list my content', 'show content',
            'what content', 'my content', 'content items', 'content details',
            'tell me about my content', 'content info', 'get content',
            'fetch content', 'all content', 'how much content', 'how many content',
            'content count', 'show me content', 'display content',
            'prayer', 'prayers', 'devotional', 'devotionals', 'scripture', 'scriptures',
            'list prayers', 'show prayers', 'my prayers', 'prayer content',
            'devotional content', 'scripture content'
        ];

        foreach ($contentKeywords as $keyword) {
            if (str_contains($message, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get user's campaigns formatted for AI context
     */
    protected function getUserCampaigns(User $user): ?array
    {
        try {
            $organizationId = $user->organization?->id;
            
            if (!$organizationId) {
                return null;
            }

            $campaigns = Campaign::with(['user', 'selectedUsers'])
                ->withCount('scheduledDrops')
                ->forOrganization($organizationId)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($campaign) {
                    return [
                        'id' => $campaign->id,
                        'name' => $campaign->name,
                        'status' => $campaign->status,
                        'start_date' => $campaign->start_date->format('Y-m-d'),
                        'end_date' => $campaign->end_date->format('Y-m-d'),
                        'send_time_local' => $campaign->send_time_local,
                        'channels' => $campaign->channels ?? [],
                        'created_at' => $campaign->created_at->format('Y-m-d H:i:s'),
                        'creator' => $campaign->user->name ?? 'Unknown',
                        'scheduled_drops_count' => $campaign->scheduled_drops_count ?? 0,
                        'selected_users_count' => $campaign->selectedUsers->count(),
                    ];
                })
                ->toArray();

            return $campaigns;
        } catch (\Exception $e) {
            Log::error('Error fetching campaigns for AI chat', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get user's content items formatted for AI context
     */
    protected function getUserContent(User $user): ?array
    {
        try {
            $organizationId = $user->organization?->id;
            
            if (!$organizationId) {
                return null;
            }

            $contentItems = ContentItem::with(['user'])
                ->forOrganization($organizationId)
                ->approved()
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($content) {
                    $meta = $content->meta ?? [];
                    return [
                        'id' => $content->id,
                        'title' => $content->title,
                        'type' => $content->type,
                        'body_preview' => Str::limit(strip_tags($content->body), 150),
                        'body_length' => strlen(strip_tags($content->body)),
                        'scripture_ref' => $meta['scripture_ref'] ?? null,
                        'tags' => $meta['tags'] ?? [],
                        'created_at' => $content->created_at->format('Y-m-d H:i:s'),
                        'creator' => $content->user->name ?? 'Unknown',
                        'is_approved' => $content->is_approved,
                    ];
                })
                ->toArray();

            return $contentItems;
        } catch (\Exception $e) {
            Log::error('Error fetching content for AI chat', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get content statistics for user
     */
    protected function getContentStats(User $user): ?array
    {
        try {
            $organizationId = $user->organization?->id;
            
            if (!$organizationId) {
                return null;
            }

            $stats = [
                'total' => ContentItem::forOrganization($organizationId)->approved()->count(),
                'prayers' => ContentItem::forOrganization($organizationId)->approved()->prayers()->count(),
                'devotionals' => ContentItem::forOrganization($organizationId)->approved()->where('type', 'devotional')->count(),
                'scriptures' => ContentItem::forOrganization($organizationId)->approved()->where('type', 'scripture')->count(),
            ];

            return $stats;
        } catch (\Exception $e) {
            Log::error('Error fetching content stats for AI chat', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Build system prompt with campaign and content context if available
     */
    protected function buildSystemPrompt(?array $campaignsData = null, ?array $contentData = null, ?array $contentStats = null): string
    {
        $basePrompt = <<<PROMPT
You are a professional, intelligent, and efficient AI assistant for the Believe App platform - a spiritual engagement and content management system.

YOUR ROLE:
- Provide professional, courteous, and helpful assistance
- Use clear, concise, and well-structured responses
- Maintain a supportive and respectful tone
- Be proactive in understanding user needs
- Offer suggestions when appropriate
- Acknowledge requests professionally without unnecessary verbosity

COMMUNICATION STYLE:
- Be direct and action-oriented
- Use professional language without being overly formal
- Provide complete, actionable information
- When operations are handled automatically, acknowledge briefly
- Offer helpful context when relevant
- Use proper formatting (lists, structure) for complex information

CAPABILITIES:
You can assist with:
- Campaign management (create, edit, delete, query)
- Content creation (prayers, devotionals, scriptures)
- Generating titles, content, and suggestions via AI
- Providing campaign analytics and insights
- Answering questions about the platform
- Offering recommendations and best practices
PROMPT;

        $additionalContext = '';
        
        if ($campaignsData !== null && !empty($campaignsData)) {
            $campaignsJson = json_encode($campaignsData, JSON_PRETTY_PRINT);
            $additionalContext .= <<<CAMPAIGNS

═══════════════════════════════════════════════════════════════════════════════
CAMPAIGN DATA:
═══════════════════════════════════════════════════════════════════════════════
{$campaignsJson}

CAMPAIGN QUERY INSTRUCTIONS:
1. When the user asks about campaigns, analyze the campaign data above and provide a comprehensive summary.
2. Include key details like campaign names, status (active/paused/cancelled), dates, delivery channels, and scheduled drops count.
3. If there are no campaigns, inform the user politely.
4. Format your response in a clear, organized way - use lists or structured format for multiple campaigns.
5. Highlight important information like active campaigns, upcoming dates, or campaigns with many scheduled drops.
6. Be conversational and helpful - explain what each campaign is about based on its name and dates.
7. If the user asks specific questions about a campaign, use the ID or name to reference it accurately.

CAMPAIGN QUERY EXAMPLES:
- "What campaigns do I have?"
- "Show me my active campaigns"
- "Tell me about campaign [name or ID]"
- "How many campaigns are scheduled?"
- "What campaigns start/end on [date]?"

CAMPAIGNS;
        }
        
        if ($contentData !== null && !empty($contentData)) {
            $contentJson = json_encode($contentData, JSON_PRETTY_PRINT);
            $statsJson = $contentStats ? json_encode($contentStats, JSON_PRETTY_PRINT) : '{}';
            
            $additionalContext .= <<<CONTENTDATA

═══════════════════════════════════════════════════════════════════════════════
CONTENT ITEMS DATA:
═══════════════════════════════════════════════════════════════════════════════
{$contentJson}

CONTENT STATISTICS:
{$statsJson}

CONTENT QUERY INSTRUCTIONS:
1. When the user asks about content, analyze the content data above and provide detailed information.
2. Include key details like content titles, types (prayer/devotional/scripture), creators, creation dates, scripture references, and tags.
3. Show content previews (first 150 characters of body) when relevant.
4. If there are no content items, inform the user politely.
5. Format your response clearly - use lists or structured format for multiple content items.
6. Group content by type when asked (e.g., "how many prayers do I have?").
7. If the user asks specific questions about content, use the ID or title to reference it accurately.
8. For questions like "how much content" or "how many", use the statistics provided above.

CONTENT QUERY EXAMPLES:
- "What content do I have?"
- "Show me my content"
- "How many content items do I have?"
- "How many prayers/devotionals/scriptures?"
- "Tell me about content [title or ID]"
- "Show me content details"
- "List my prayers"
- "What scriptures do I have?"
- "Show content with scripture reference John 3:16"
- "Content created by [creator name]"
- "Content with tags [tag name]"

CONTENTDATA;
        }
        
        if (empty($additionalContext)) {
            return $basePrompt;
        }

        return $basePrompt . "\n\n" . <<<PROMPT
{$additionalContext}

ADVANCED CAPABILITIES:

1. TITLE & CONTENT GENERATION:
When users request title generation (e.g., "generate a title", "suggest a campaign title", "create a title for..."), you can:
- Generate creative, relevant titles based on context
- Suggest multiple options when appropriate
- Use professional, spiritual language appropriate for the platform
- Consider the campaign type, dates, and purpose

2. SMART OPERATIONS:
- If user says "generate a title" or "suggest a title", provide AI-generated title suggestions
- If user says "create campaign with generated title", the system will auto-generate
- If user says "generate content", provide AI-generated content suggestions
- Support natural language like: "create a campaign with AI-generated title about morning prayers"

3. AUTOMATIC OPERATION HANDLING:
When users request CREATE, EDIT, or DELETE operations:
- The system automatically detects and executes these operations
- Respond briefly and professionally: "✓ Done" or "✓ Campaign updated" 
- DO NOT generate lengthy explanations about what "will happen"
- DO NOT use phrases like "I will update..." or "The update will be processed..."
- Simply acknowledge the completion once the system handles it

FOR CAMPAIGNS:
- CREATE/EDIT/DELETE: System executes automatically. Respond with brief confirmation only.

FOR CONTENT:
- CREATE/EDIT/DELETE: System executes automatically. Respond with brief confirmation only.

FOR TITLE GENERATION:
- If user requests title generation, provide professional, relevant suggestions
- Format: "Here are some title suggestions: [list of titles]"
- After suggesting, user can say "use the first one" or "change it to [title]"
PROMPT;
    }

    /**
     * Check if user is requesting title generation
     */
    protected function isTitleGenerationRequest(string $message): bool
    {
        $messageLower = strtolower(trim($message));
        
        $patterns = [
            '/\b(generate|create|suggest|make|give)\s+(?:me\s+)?(?:a\s+)?(?:campaign\s+)?title/i',
            '/\b(title|name)\s+(?:generation|suggestion|idea)/i',
            '/\b(generate|suggest|create)\s+(?:a\s+)?(?:good|better|professional|creative)\s+(?:campaign\s+)?(?:title|name)/i',
            '/\b(what|can\s+you)\s+(?:suggest|generate|create)\s+(?:for\s+)?(?:a\s+)?(?:campaign\s+)?(?:title|name)/i',
            '/\b(help\s+me|i\s+need)\s+(?:a\s+)?(?:title|name)/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $messageLower)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Generate campaign title suggestions using AI
     */
    protected function generateTitleSuggestions(string $message, User $user, ?array $campaignsData = null): string
    {
        try {
            // Extract context from message
            $context = '';
            if (preg_match('/(?:for|about|regarding)\s+(.+?)(?:\s+campaign|$)/i', $message, $matches)) {
                $context = trim($matches[1]);
            }
            
            $systemPrompt = <<<PROMPT
You are a creative professional specializing in generating compelling campaign titles for spiritual and religious engagement platforms.

Generate 3-5 professional, engaging campaign title suggestions. Titles should be:
- Clear and descriptive
- Spiritually appropriate
- Professional yet warm
- 3-8 words maximum
- Action-oriented when appropriate
- Reflect the purpose or theme

Return ONLY a numbered list of titles, one per line, like:
1. Title One
2. Title Two
3. Title Three

No explanations, no markdown, just the list.
PROMPT;

            $userPrompt = !empty($context) 
                ? "Generate campaign title suggestions for: {$context}"
                : "Generate professional campaign title suggestions for a spiritual engagement campaign.";

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ];

            $result = $this->openAiService->chatCompletion($messages);
            $suggestions = $result['content'] ?? '';
            $totalTokens = (int) ($result['total_tokens'] ?? 0);
            if ($totalTokens > 0) {
                $user->consumeAiTokens($totalTokens);
            }

            // Format the response professionally
            return "Here are some campaign title suggestions:\n\n" . trim($suggestions) . "\n\nYou can use any of these by saying: \"create campaign with title [number or exact title]\" or \"use the first one\".";
            
        } catch (\Exception $e) {
            Log::error('Failed to generate title suggestions', [
                'error' => $e->getMessage(),
            ]);
            return "I encountered an error generating title suggestions. Please try again or provide a title directly.";
        }
    }

    /**
     * Detect if user wants to create, edit, or delete a campaign
     */
    protected function detectCampaignOperation(string $message): ?string
    {
        $messageLower = strtolower(trim($message));
        
        // More flexible pattern matching
        $createPatterns = [
            '/\b(create|make|add|start|build|generate|new)\s+(?:a\s+)?(?:new\s+)?campaign/i',
            '/campaign.*\b(create|make|add|start|build|generate)\b/i',
            '/\b(create|make)\s+again/i', // "create again", "make again"
            '/\b(create|make)\s+with\s+same/i', // "create with same", "make with same"
            '/\b(create|make)\s+.*\s+again/i', // "create campaign again", "make it again"
        ];
        
        $editPatterns = [
            '/\b(edit|update|change|modify|alter|adjust|revise)\s+(?:a\s+)?(?:the\s+)?campaign/i',
            '/campaign.*\b(edit|update|change|modify|alter|adjust|revise)\b/i',
            // Patterns for "edit CampaignName title/name to NewName"
            '/\b(edit|update|change)\s+[A-Za-z0-9\s]+?(?:campaign|title|name)\s+to\s+/i',
            // Pattern for "edit CampaignName to NewName" - more specific
            '/\b(edit|update|change)\s+([A-Z][A-Za-z0-9\s]+?)\s+to\s+([A-Z][A-Za-z0-9\s]+)/i',
            // Pattern for title/name updates specifically - must have "to" after
            '/\b(edit|update|change)\s+.*\s+(?:title|name)\s+to\s+/i',
            // Generic "edit X to Y" pattern (when X and Y are capital-cased, likely campaign names)
            '/\b(edit|update|change)\s+([A-Z][^\s]+(?:\s+[A-Z][^\s]+)*)\s+to\s+([A-Z][^\s]+(?:\s+[A-Z][^\s]+)*)/i',
            // Patterns for status updates with ordinals - "update first one status", "update first campaign status"
            '/\b(update|change|edit)\s+(?:first|second|third|last|1st|2nd|3rd)\s+(?:one|campaign).*status/i',
            '/\bstatus\s+(?:active|inactive|paused|cancelled)\s+to\s+(?:active|inactive|paused|cancelled)/i',
            // "update first one status active to inactive"
            '/\b(update|change|edit)\s+(?:first|second|third|last)\s+(?:one|campaign)\s+status/i',
        ];
        
        $deletePatterns = [
            '/\b(delete|remove|cancel|drop|erase|destroy|get\s+rid\s+of|take\s+down)\s+(?:a\s+)?(?:the\s+)?(?:campaign|it|this|that)/i',
            '/\b(delete|remove|cancel|drop|erase|destroy)\s+(?:the|campaign\s+named|campaign\s+called)/i',
            '/campaign.*\b(delete|remove|cancel|drop|erase|destroy)\b/i',
            '/\b(delete|remove|cancel|drop|erase|destroy)\s+.*campaign/i',
        ];

        // Check delete first (most destructive, needs to be caught)
        foreach ($deletePatterns as $pattern) {
            if (preg_match($pattern, $messageLower)) {
                Log::info('Delete operation detected', [
                    'message' => substr($message, 0, 100),
                    'pattern' => $pattern,
                ]);
                return 'delete';
            }
        }

        // Check create
        foreach ($createPatterns as $pattern) {
            if (preg_match($pattern, $messageLower)) {
                Log::info('Create operation detected', [
                    'message' => substr($message, 0, 100),
                    'pattern' => $pattern,
                ]);
                return 'create';
            }
        }

        // Check edit
        foreach ($editPatterns as $pattern) {
            if (preg_match($pattern, $messageLower)) {
                Log::info('Edit operation detected', [
                    'message' => substr($message, 0, 100),
                    'pattern' => $pattern,
                ]);
                return 'edit';
            }
        }

        return null;
    }

    /**
     * Detect if user wants to create, edit, or delete content
     */
    protected function detectContentOperation(string $message): ?string
    {
        $messageLower = strtolower(trim($message));
        
        // Pattern matching for content operations
        $createPatterns = [
            '/\b(create|make|add|write|generate|new)\s+(?:a\s+)?(?:new\s+)?(?:content|prayer|devotional|scripture)/i',
            '/\b(create|make|add|write|generate)\s+(?:a\s+)?(?:prayer|devotional|scripture|content\s+item)/i',
            '/(?:prayer|devotional|scripture|content).*\b(create|make|add|write|generate)\b/i',
        ];
        
        $editPatterns = [
            '/\b(edit|update|change|modify|alter|adjust|revise)\s+(?:a\s+)?(?:the\s+)?(?:content|prayer|devotional|scripture)/i',
            '/(?:content|prayer|devotional|scripture).*\b(edit|update|change|modify|alter|adjust|revise)\b/i',
            // Patterns for updating specific fields - but exclude campaign status
            '/\b(update|change|edit|set)\s+(?:the\s+)?(?:content\s+)?(?:title|body|type|scripture|tags?)\s+/i',
            '/\b(edit|update)\s+(?:first|second|third|last|1st|2nd|3rd)\s+(?:content|item|one)\s+(?!status|campaign)/i',
        ];
        
        $deletePatterns = [
            '/\b(delete|remove|cancel|drop|erase|destroy)\s+(?:a\s+)?(?:the\s+)?(?:content|prayer|devotional|scripture)/i',
            '/(?:content|prayer|devotional|scripture).*\b(delete|remove|cancel|drop|erase|destroy)\b/i',
            // Delete all patterns
            '/\b(delete|remove|clear|erase|drop)\s+(?:all|every)\s+(?:content|items|prayers|devotionals|scriptures)/i',
            '/\b(delete|remove|clear|erase|drop)\s+(?:all|every)\s+(?:my\s+)?(?:content|items)/i',
        ];

        // Check delete first (most destructive)
        foreach ($deletePatterns as $pattern) {
            if (preg_match($pattern, $messageLower)) {
                Log::info('Content delete operation detected', [
                    'message' => substr($message, 0, 100),
                    'pattern' => $pattern,
                ]);
                // Check if it's "delete all"
                if (preg_match('/\b(?:delete|remove|clear|erase|drop)\s+(?:all|every)\s+/i', $messageLower)) {
                    return 'delete_all';
                }
                return 'delete';
            }
        }

        // Check create
        foreach ($createPatterns as $pattern) {
            if (preg_match($pattern, $messageLower)) {
                Log::info('Content create operation detected', [
                    'message' => substr($message, 0, 100),
                    'pattern' => $pattern,
                ]);
                return 'create';
            }
        }

        // Check edit
        foreach ($editPatterns as $pattern) {
            if (preg_match($pattern, $messageLower)) {
                Log::info('Content edit operation detected', [
                    'message' => substr($message, 0, 100),
                    'pattern' => $pattern,
                ]);
                return 'edit';
            }
        }

        return null;
    }

    /**
     * Handle content operations (create, edit, delete)
     */
    protected function handleContentOperation(string $operation, string $message, User $user, AiChatConversation $conversation): array
    {
        try {
            switch ($operation) {
                case 'create':
                    return $this->createContentFromChat($message, $user, $conversation);
                case 'edit':
                    return $this->editContentFromChat($message, $user);
                case 'delete':
                    return $this->deleteContentFromChat($message, $user);
                case 'delete_all':
                    return $this->deleteAllContentFromChat($message, $user);
                default:
                    return [
                        'success' => false,
                        'message' => 'Unknown operation. Please specify create, edit, or delete content.',
                    ];
            }
        } catch (\Exception $e) {
            Log::error('Content operation failed', [
                'operation' => $operation,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Sorry, I encountered an error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Handle campaign operations (create, edit, delete)
     */
    protected function handleCampaignOperation(string $operation, string $message, User $user, AiChatConversation $conversation): array
    {
        try {
            switch ($operation) {
                case 'create':
                    return $this->createCampaignFromChat($message, $user, $conversation);
                case 'edit':
                    return $this->editCampaignFromChat($message, $user);
                case 'delete':
                    return $this->deleteCampaignFromChat($message, $user);
                default:
                    return [
                        'success' => false,
                        'message' => 'Unknown operation. Please specify create, edit, or delete campaign.',
                    ];
            }
        } catch (\Exception $e) {
            Log::error('Campaign operation failed', [
                'operation' => $operation,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Sorry, I encountered an error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Create a campaign from chat message using AI to extract data
     */
    protected function createCampaignFromChat(string $message, User $user, ?AiChatConversation $conversation = null): array
    {
        // Load organization relationship if not already loaded
        if (!$user->relationLoaded('organization')) {
            $user->load('organization');
        }

        $organizationId = $user->organization?->id;
        
        // Alternative: Try to get organization_id from board memberships if organization relationship is null
        if (!$organizationId) {
            $boardMembership = \App\Models\BoardMember::where('user_id', $user->id)->first();
            if ($boardMembership) {
                $organizationId = $boardMembership->organization_id;
                Log::info('Found organization via board membership', [
                    'user_id' => $user->id,
                    'organization_id' => $organizationId,
                ]);
            }
        }
        
        if (!$organizationId) {
            Log::warning('User attempted to create campaign without organization', [
                'user_id' => $user->id,
                'user_role' => $user->role,
                'organization_role' => $user->organization_role,
                'has_organization_relation' => $user->organization !== null,
                'board_memberships_count' => \App\Models\BoardMember::where('user_id', $user->id)->count(),
            ]);

            return [
                'success' => false,
                'message' => 'You must be part of an organization to create campaigns. Please contact your administrator to be added to an organization.',
            ];
        }

        // Get conversation history if available (for "create again with same details")
        $conversationHistory = null;
        if ($conversation) {
            // Get recent messages (both user and assistant) to find campaign details
            $recentMessages = AiChatMessage::where('conversation_id', $conversation->id)
                ->orderBy('created_at', 'desc')
                ->limit(10) // Get more messages to find campaign details
                ->get(['role', 'content'])
                ->reverse() // Reverse to chronological order
                ->map(function ($msg) {
                    $role = $msg->role === 'user' ? 'User' : 'Assistant';
                    return "[{$role}]: {$msg->content}";
                })
                ->toArray();
            
            if (!empty($recentMessages)) {
                $conversationHistory = implode("\n", $recentMessages);
                Log::info('Including conversation history for campaign creation', [
                    'user_id' => $user->id,
                    'conversation_id' => $conversation->id,
                    'messages_count' => count($recentMessages),
                ]);
            }
        }

        // Use OpenAI to extract campaign data from natural language
        $extractedData = $this->extractCampaignDataFromMessage($message, $user, 'create', $conversationHistory);
        
        if (!$extractedData['success']) {
            Log::warning('Failed to extract campaign data', [
                'user_id' => $user->id,
                'message' => substr($message, 0, 200),
                'error' => $extractedData['message'] ?? 'Unknown error',
            ]);
            return $extractedData;
        }

        $data = $extractedData['data'];

        Log::info('Campaign data extracted', [
            'user_id' => $user->id,
            'extracted_data' => array_merge($data, [
                'content_items_count' => count($data['content_items'] ?? []),
                'user_ids_count' => count($data['user_ids'] ?? []),
            ]),
        ]);

        // Check if user wants AI-generated title
        $messageLower = strtolower($message);
        $wantsGeneratedTitle = preg_match('/\b(generate|ai|auto|suggest).*title/i', $message) || 
                               preg_match('/\bwith\s+(?:generated|ai|auto|suggested)\s+title/i', $message);
        
        // Check if user gave permission to use defaults ("with your own data", "you choose", etc.)
        $useDefaultsPatterns = [
            '/\bwith\s+(?:your\s+)?(?:own\s+)?data\b/i',
            '/\byou\s+(?:can\s+)?(?:make|create|use|choose|decide)\b/i',
            '/\b(?:make|create|use)\s+(?:it\s+)?with\s+(?:your\s+)?(?:own\s+)?data\b/i',
            '/\b(?:use|apply)\s+defaults?\b/i',
            '/\bgo\s+ahead\b/i',
            '/\bproceed\b/i',
            '/\bcreate\s+it\b/i',
            '/\byou\s+decide\b/i',
            '/\bleave\s+it\s+to\s+you\b/i',
        ];
        
        $useDefaults = false;
        foreach ($useDefaultsPatterns as $pattern) {
            if (preg_match($pattern, $messageLower)) {
                $useDefaults = true;
                break;
            }
        }
        
        Log::info('Checking for default permission and title generation', [
            'user_id' => $user->id,
            'message' => substr($message, 0, 100),
            'use_defaults' => $useDefaults,
            'wants_generated_title' => $wantsGeneratedTitle,
        ]);

        // Validation - but allow defaults if user gave permission
        $errors = [];
        if (empty($data['name'])) {
            if ($useDefaults || $wantsGeneratedTitle) {
                // Generate title using AI
                try {
                    $data['name'] = $this->generateCampaignTitle($message, $user);
                    Log::info('Generated campaign title', ['name' => $data['name']]);
                } catch (\Exception $e) {
                    Log::error('Failed to generate title, using fallback', ['error' => $e->getMessage()]);
                    // Fallback to date-based name
                    $monthYear = Carbon::now()->format('F Y');
                    $data['name'] = "Daily Prayer Campaign - {$monthYear}";
                }
            } else {
                $errors[] = 'Campaign name is required. You can say "generate a title" or "use your own data" for AI-generated titles.';
            }
        }
        if (empty($data['start_date'])) {
            if ($useDefaults) {
                // Default to tomorrow
                $data['start_date'] = Carbon::tomorrow()->format('Y-m-d');
                Log::info('Using default start date', ['start_date' => $data['start_date']]);
            } else {
                $errors[] = 'Start date is required';
            }
        }
        if (empty($data['end_date'])) {
            if ($useDefaults) {
                // Default to 30 days from start date (or next month)
                $startDate = Carbon::parse($data['start_date'] ?? Carbon::tomorrow());
                $data['end_date'] = $startDate->copy()->addDays(29)->format('Y-m-d'); // 30 days total
                Log::info('Using default end date', ['end_date' => $data['end_date']]);
            } else {
                $errors[] = 'End date is required';
            }
        }
        if (empty($data['send_time_local'])) {
            $data['send_time_local'] = '07:00:00'; // Default time in H:i:s format
        } else {
            // Ensure time is in H:i:s format for database
            try {
                $timeParts = explode(':', $data['send_time_local']);
                if (count($timeParts) >= 2) {
                    $hour = (int) $timeParts[0];
                    $minute = (int) $timeParts[1];
                    $second = isset($timeParts[2]) ? (int) $timeParts[2] : 0;
                    $data['send_time_local'] = sprintf('%02d:%02d:%02d', $hour, $minute, $second);
                } else {
                    // Try to parse as natural time and convert
                    $parsedTime = Carbon::parse($data['send_time_local']);
                    $data['send_time_local'] = $parsedTime->format('H:i:s');
                }
            } catch (\Exception $e) {
                $data['send_time_local'] = '07:00:00'; // Default on parse error
            }
        }
        
        if (empty($data['channels']) || !is_array($data['channels'])) {
            $data['channels'] = ['web']; // Default channel
        }
        
        // Validate channels are valid
        $validChannels = CampaignDeliveryChannels::VALUES;
        $data['channels'] = array_values(array_filter($data['channels'], function ($channel) use ($validChannels) {
            return in_array(strtolower((string) $channel), $validChannels, true);
        }));
        
        if (empty($data['channels'])) {
            $data['channels'] = ['web']; // Fallback to web if all invalid
        }
        // Handle content items - if not specified, use all approved content items for this organization
        if (empty($data['content_items']) || !is_array($data['content_items'])) {
            $allContentItems = ContentItem::forOrganization($organizationId)->approved()->pluck('id')->toArray();
            if (empty($allContentItems)) {
                $errors[] = 'No content items are available. Please create some content first.';
            } else {
                $data['content_items'] = $allContentItems;
                Log::info('Using all available content items', [
                    'count' => count($data['content_items']),
                    'organization_id' => $organizationId,
                ]);
            }
        }
        
        // Handle user IDs - if not specified, use all active users
        if (empty($data['user_ids']) || !is_array($data['user_ids'])) {
            $allActiveUsers = User::where('login_status', true)->pluck('id')->toArray();
            if (empty($allActiveUsers)) {
                $errors[] = 'No active users found.';
            } else {
                $data['user_ids'] = $allActiveUsers;
                Log::info('Using all active users', [
                    'count' => count($data['user_ids']),
                ]);
            }
        }

        // If we're missing required fields, return form data for interactive UI
        if (!empty($errors)) {
            // Get available users and content items for the form
            $availableUsers = User::where('login_status', true)
                ->select('id', 'name', 'email', 'contact_number', 'whatsapp_opt_in', 'push_token')
                ->orderBy('name')
                ->get();
            
            $availableContentItems = ContentItem::forOrganization($organizationId)
                ->approved()
                ->select('id', 'title', 'body', 'type', 'meta')
                ->orderBy('created_at', 'desc')
                ->get();
            
            // Pre-fill what we have from extraction
            $formData = [
                'name' => $data['name'] ?? '',
                'start_date' => $data['start_date'] ?? '',
                'end_date' => $data['end_date'] ?? '',
                'send_time_local' => $data['send_time_local'] ?? '07:00',
                'channels' => $data['channels'] ?? ['web'],
                'content_items' => $data['content_items'] ?? [],
                'user_ids' => $data['user_ids'] ?? [],
            ];

            Log::info('Returning campaign creation form data', [
                'users_count' => $availableUsers->count(),
                'content_items_count' => $availableContentItems->count(),
                'form_data' => $formData,
            ]);

            return [
                'success' => false, // Will be converted to success: true when show_form is detected
                'show_form' => true,
                'form_type' => 'campaign_create',
                'form_data' => $formData,
                'users' => $availableUsers->map(function($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'contact_number' => $user->contact_number,
                        'whatsapp_opt_in' => $user->whatsapp_opt_in,
                        'push_token' => $user->push_token,
                    ];
                })->toArray(),
                'content_items' => $availableContentItems->map(function($item) {
                    return [
                        'id' => $item->id,
                        'title' => $item->title,
                        'body' => $item->body,
                        'type' => $item->type,
                        'meta' => $item->meta,
                    ];
                })->toArray(),
                'default_channels' => ['web', 'whatsapp', 'email'],
                'message' => 'Please fill in the campaign details below:',
            ];
        }

        // Parse dates
        try {
            $startDate = Carbon::parse($data['start_date'])->format('Y-m-d');
            $endDate = Carbon::parse($data['end_date'])->format('Y-m-d');
            
            if ($startDate < Carbon::today()->format('Y-m-d')) {
                return [
                    'success' => false,
                    'message' => 'Start date must be today or in the future.',
                ];
            }
            
            if ($endDate <= $startDate) {
                return [
                    'success' => false,
                    'message' => 'End date must be after start date.',
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Invalid date format. Please provide dates in a format like "January 1, 2025" or "2025-01-01".',
            ];
        }

        // Validate and filter content items - ensure they're IDs and belong to organization
        $contentItemIds = array_filter(array_map('intval', $data['content_items']), function($id) {
            return $id > 0;
        });
        
        if (empty($contentItemIds)) {
            return [
                'success' => false,
                'message' => 'No valid content items specified. Please provide content item IDs.',
            ];
        }

        $contentItems = ContentItem::whereIn('id', $contentItemIds)
            ->forOrganization($organizationId)
            ->approved()
            ->get();

        if ($contentItems->isEmpty()) {
            return [
                'success' => false,
                'message' => 'No content items were found or available for your organization. Please create some content first.',
            ];
        }

        // If fewer items found than requested, use what we have (log it)
        if ($contentItems->count() < count($contentItemIds)) {
            Log::warning('Some content items not found', [
                'requested_ids' => $contentItemIds,
                'found_count' => $contentItems->count(),
                'found_ids' => $contentItems->pluck('id')->toArray(),
            ]);
        }

        // Validate and filter users - ensure they're IDs and are active
        $userIds = array_filter(array_map('intval', $data['user_ids']), function($id) {
            return $id > 0;
        });
        
        if (empty($userIds)) {
            return [
                'success' => false,
                'message' => 'No valid users specified. Please provide user IDs.',
            ];
        }

        $users = User::whereIn('id', $userIds)
            ->where('login_status', true)
            ->get();

        if ($users->isEmpty()) {
            return [
                'success' => false,
                'message' => 'No active users were found. Please ensure users are active.',
            ];
        }

        // If fewer users found than requested, use what we have (log it)
        if ($users->count() < count($userIds)) {
            Log::warning('Some users not found or inactive', [
                'requested_ids' => $userIds,
                'found_count' => $users->count(),
                'found_ids' => $users->pluck('id')->toArray(),
            ]);
        }

        // Update data with validated IDs
        $data['content_items'] = $contentItems->pluck('id')->toArray();
        $data['user_ids'] = $users->pluck('id')->toArray();

        // Create the campaign
        try {
            // Ensure send_time_local is properly formatted for database (H:i:s)
            $sendTime = $data['send_time_local'];
            if (strlen($sendTime) === 5) { // H:i format (07:00)
                $sendTime .= ':00'; // Add seconds to make it H:i:s (07:00:00)
            }
            
            Log::info('Creating campaign with data', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'name' => $data['name'],
                'start_date' => $startDate,
                'end_date' => $endDate,
                'send_time_local' => $sendTime,
                'channels' => $data['channels'],
                'content_items_count' => count($data['content_items']),
                'user_ids_count' => count($data['user_ids']),
            ]);

            // Create campaign with proper error handling
            try {
                $campaign = Campaign::create([
                    'organization_id' => $organizationId,
                    'user_id' => $user->id,
                    'name' => trim($data['name']),
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'send_time_local' => $sendTime,
                    'channels' => $data['channels'],
                    'status' => 'active',
                ]);
                
                Log::info('Campaign model created', [
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                Log::error('Database error creating campaign', [
                    'error' => $e->getMessage(),
                    'sql' => $e->getSql(),
                    'bindings' => $e->getBindings(),
                ]);
                throw $e;
            } catch (\Exception $e) {
                Log::error('General error creating campaign', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }

            // Load relationships needed for CampaignPlanner
            $campaign->load(['organization', 'organization.user', 'user']);

            // Attach selected users
            if (!empty($data['user_ids'])) {
                try {
                    $campaign->selectedUsers()->attach($data['user_ids']);
                    Log::info('Users attached to campaign', [
                        'campaign_id' => $campaign->id,
                        'users_count' => count($data['user_ids']),
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to attach users', [
                        'campaign_id' => $campaign->id,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }

            // Schedule campaign drops
            $scheduledCount = 0;
            if ($contentItems->isNotEmpty()) {
                try {
                    $scheduledCount = CampaignPlanner::planDailyCampaign($campaign, $contentItems);
                    Log::info('Campaign drops scheduled', [
                        'campaign_id' => $campaign->id,
                        'scheduled_count' => $scheduledCount,
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to schedule campaign drops', [
                        'campaign_id' => $campaign->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Don't throw - campaign is created, scheduling can fail
                    $scheduledCount = 0;
                }
            } else {
                Log::warning('No content items to schedule', [
                    'campaign_id' => $campaign->id,
                ]);
            }

            // Refresh to get accurate user count
            $campaign->refresh();
            $usersCount = $campaign->selectedUsers()->count();

            if (CampaignDeliveryChannels::includesEmail($data['channels'] ?? [])) {
                SendCampaignCreatedEmails::dispatch($campaign->id);
            }

            Log::info('Campaign created successfully via AI chat', [
                'campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'scheduled_drops' => $scheduledCount,
                'users_count' => $usersCount,
            ]);

            // Format time for display (remove seconds)
            $displayTime = preg_replace('/:00$/', '', $sendTime);

            return [
                'success' => true,
                'message' => "Campaign '{$campaign->name}' created successfully! Scheduled {$scheduledCount} daily prayers for {$usersCount} users. The campaign runs from {$startDate} to {$endDate}, sending daily at {$displayTime} via " . implode(', ', $data['channels']) . ".",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to create campaign', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to create campaign: ' . $e->getMessage() . '. Please try again or contact support.',
            ];
        }
    }

    /**
     * Edit a campaign from chat message
     */
    protected function editCampaignFromChat(string $message, User $user): array
    {
        $organizationId = $user->organization?->id;
        
        if (!$organizationId) {
            return [
                'success' => false,
                'message' => 'You must be part of an organization to edit campaigns.',
            ];
        }

        // Extract campaign identifier and update data
        $extractedData = $this->extractCampaignDataFromMessage($message, $user, 'edit');
        
        if (!$extractedData['success']) {
            return $extractedData;
        }

        $data = $extractedData['data'];
        $campaignId = $data['campaign_id'] ?? null;
        $newName = $data['name'] ?? null; // This will be the new name if updating title

        // For title/name updates, we need to identify the campaign first
        // Check if user mentioned an old campaign name to identify which campaign to update
        $messageLower = strtolower($message);
        $campaignNameToFind = null;
        
        // Look for patterns like "edit Test Campaign title to Owner Campaign"
        // More flexible patterns to catch various phrasings
        $titleUpdatePatterns = [
            // "edit Test Campaign title to Owner Campaign"
            '/(?:edit|update|change)\s+([\'"]?)([^\'"]+?)\1\s+(?:title|name)\s+to\s+([\'"]?)([^\'"]+?)\3/i',
            // "edit Test Campaign title to Owner Campaign" (no quotes)
            '/(?:edit|update|change)\s+([A-Za-z0-9\s]+?)\s+(?:title|name)\s+to\s+([A-Za-z0-9\s]+?)(?:\s|$|[.,!?])/i',
            // "can you edit Test Campaign title to Owner Campaign"
            '/(?:can\s+you\s+)?(?:edit|update|change)\s+([A-Za-z0-9\s]+?)\s+(?:title|name)\s+to\s+([A-Za-z0-9\s]+?)(?:\s|$|[.,!?])/i',
            // "change title of Test Campaign to Owner Campaign"
            '/(?:change|edit|update)\s+(?:title|name)\s+of\s+([A-Za-z0-9\s]+?)\s+to\s+([A-Za-z0-9\s]+?)(?:\s|$|[.,!?])/i',
        ];
        
        foreach ($titleUpdatePatterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                // Handle both quoted and unquoted versions
                if (count($matches) >= 5) {
                    // Quoted version (first pattern)
                    $campaignNameToFind = trim($matches[2]);
                    $newName = trim($matches[4]);
                } else {
                    // Unquoted version
                    $campaignNameToFind = trim($matches[1]);
                    $newName = trim($matches[2]);
                }
                
                // Clean up any trailing words like "please", "now", etc.
                $newName = preg_replace('/\s+(please|now|\.)$/i', '', $newName);
                $newName = trim($newName);
                
                Log::info('Detected title update pattern', [
                    'pattern' => $pattern,
                    'campaign_to_find' => $campaignNameToFind,
                    'new_name' => $newName,
                ]);
                break; // Found a match, stop checking
            }
        }

        if (!$campaignId) {
            // Check if message mentions "first one" or ordinals with "status" - likely a campaign status update
            $ordinalPattern = '/\b(?:first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|last|1st|2nd|3rd|4th|5th)\s+(?:one|campaign)\s+(?:.*\s+)?status/i';
            if (preg_match($ordinalPattern, $message, $ordinalMatch) || preg_match('/\bstatus\s+(?:active|inactive|paused|cancelled)/i', $message)) {
                // Find campaign by ordinal if mentioned
                $ordinal = null;
                if (preg_match('/\b(?:first|second|third|fourth|fifth|last|1st|2nd|3rd)\s+/i', $message, $ordMatch)) {
                    $ordinal = strtolower(trim($ordMatch[1] ?? ''));
                }
                
                $orderByDesc = true;
                $skipCount = 0;
                $ordinalMap = [
                    'first' => 0, '1st' => 0,
                    'second' => 1, '2nd' => 1,
                    'third' => 2, '3rd' => 2,
                    'fourth' => 3, '4th' => 3,
                    'fifth' => 4, '5th' => 4,
                ];
                
                if ($ordinal === 'last') {
                    $orderByDesc = false;
                    $skipCount = 0;
                } elseif ($ordinal && isset($ordinalMap[$ordinal])) {
                    $skipCount = $ordinalMap[$ordinal];
                }
                
                $query = Campaign::forOrganization($organizationId);
                if ($orderByDesc) {
                    $query->orderBy('created_at', 'desc');
                } else {
                    $query->orderBy('created_at', 'asc');
                }
                
                $camp = $query->skip($skipCount)->first();
                
                if ($camp) {
                    $campaignId = $camp->id;
                    Log::info('Found campaign by ordinal for status update', [
                        'campaign_id' => $campaignId,
                        'ordinal' => $ordinal ?? 'first',
                        'skip_count' => $skipCount,
                    ]);
                }
            }
            
            // Try to find campaign by name from extraction or pattern matching
            $campaignName = $campaignNameToFind ?? ($data['name'] ?? null);
            
            if (!$campaignId && $campaignName) {
                $campaign = Campaign::forOrganization($organizationId)
                    ->where('name', 'LIKE', '%' . $campaignName . '%')
                    ->first();
                
                if ($campaign) {
                    $campaignId = $campaign->id;
                    Log::info('Found campaign by name for edit', [
                        'campaign_id' => $campaignId,
                        'searched_name' => $campaignName,
                    ]);
                }
            }

            if (!$campaignId) {
                // Check if this is a generic request (no specific campaign mentioned)
                $isGenericRequest = $this->isGenericRequest($message, ['campaign']);
                
                if ($isGenericRequest) {
                    // Show list of campaigns with details
                    $availableCampaigns = Campaign::forOrganization($organizationId)
                        ->with(['user', 'selectedUsers'])
                        ->orderBy('created_at', 'desc')
                        ->get();
                    
                    if ($availableCampaigns->isEmpty()) {
                        return [
                            'success' => false,
                            'message' => 'No campaigns found. Please create a campaign first.',
                        ];
                    }
                    
                    $campaignList = "Here are your campaigns:\n\n";
                    foreach ($availableCampaigns as $index => $camp) {
                        $statusEmoji = match($camp->status) {
                            'active' => '✅',
                            'paused' => '⏸️',
                            'cancelled' => '❌',
                            default => '📋'
                        };
                        $startDate = Carbon::parse($camp->start_date)->format('M d, Y');
                        $endDate = Carbon::parse($camp->end_date)->format('M d, Y');
                        $recipientCount = $camp->selectedUsers->count() ?? 0;
                        $channels = is_array($camp->channels) ? implode(', ', $camp->channels) : ($camp->channels ?? 'N/A');
                        
                        $campaignList .= sprintf(
                            "%d. %s %s\n   • Period: %s to %s\n   • Send Time: %s\n   • Channels: %s\n   • Recipients: %d users\n   • Status: %s\n\n",
                            $index + 1,
                            $statusEmoji,
                            $camp->name,
                            $startDate,
                            $endDate,
                            $camp->send_time_local ?? 'N/A',
                            $channels,
                            $recipientCount,
                            ucfirst($camp->status)
                        );
                    }
                    
                    $campaignList .= "Which campaign would you like to edit? You can:\n";
                    $campaignList .= "• Say the campaign name (e.g., \"Edit {$availableCampaigns->first()->name}\")\n";
                    $campaignList .= "• Say the number (e.g., \"Edit campaign 1\")\n";
                    $campaignList .= "• Say \"first\", \"second\", \"last\", etc.";
                    
                    return [
                        'success' => true,
                        'response' => $campaignList,
                    ];
                }
                
                // Try harder to find campaign - match any words from message against campaign names
                $availableCampaigns = Campaign::forOrganization($organizationId)->get(['id', 'name']);
                
                if ($availableCampaigns->isEmpty()) {
                    return [
                        'success' => false,
                        'message' => 'No campaigns found. Please create a campaign first.',
                    ];
                }

                // Try to match campaign names with words from the message
                $messageWords = preg_split('/\s+/', $message);
                $messageWords = array_map('strtolower', $messageWords);
                $messageWords = array_filter($messageWords, function($word) {
                    return strlen($word) > 2 && !in_array(strtolower($word), ['the', 'and', 'or', 'to', 'for', 'edit', 'update', 'change', 'campaign', 'title', 'name']);
                });

                foreach ($availableCampaigns as $camp) {
                    $campNameLower = strtolower($camp->name);
                    $campNameWords = preg_split('/\s+/', $campNameLower);
                    
                    // Check if any significant words from message match campaign name
                    foreach ($messageWords as $word) {
                        if (in_array($word, $campNameWords) || str_contains($campNameLower, $word)) {
                            $campaignId = $camp->id;
                            Log::info('Found campaign by word matching', [
                                'campaign_id' => $campaignId,
                                'matched_word' => $word,
                                'campaign_name' => $camp->name,
                            ]);
                            break 2;
                        }
                    }
                    
                    // Also check if campaign name contains significant words from message
                    foreach ($messageWords as $word) {
                        if (strlen($word) > 3 && str_contains($campNameLower, $word)) {
                            $campaignId = $camp->id;
                            Log::info('Found campaign by reverse word matching', [
                                'campaign_id' => $campaignId,
                                'matched_word' => $word,
                                'campaign_name' => $camp->name,
                            ]);
                            break 2;
                        }
                    }
                }

                // If still not found, try partial string matching
                if (!$campaignId) {
                    foreach ($availableCampaigns as $camp) {
                        // Extract potential campaign name from message (capitalized words)
                        if (preg_match('/\b([A-Z][A-Za-z0-9\s]{3,})\b/', $message, $matches)) {
                            $potentialName = trim($matches[1]);
                            if (stripos($camp->name, $potentialName) !== false || stripos($potentialName, $camp->name) !== false) {
                                $campaignId = $camp->id;
                                Log::info('Found campaign by capitalized word matching', [
                                    'campaign_id' => $campaignId,
                                    'potential_name' => $potentialName,
                                    'campaign_name' => $camp->name,
                                ]);
                                break;
                            }
                        }
                    }
                }

                // If still nothing found, just use the first campaign (as fallback)
                if (!$campaignId && $availableCampaigns->count() === 1) {
                    $campaignId = $availableCampaigns->first()->id;
                    Log::info('Using only available campaign as fallback', [
                        'campaign_id' => $campaignId,
                    ]);
                }

                // Only show error if we really can't figure it out
                if (!$campaignId) {
                    return [
                        'success' => false,
                        'message' => 'Could not identify which campaign to edit. Please specify the campaign name more clearly.',
                    ];
                }
            }
        }

        // Find the campaign
        $campaign = Campaign::forOrganization($organizationId)
            ->where('id', $campaignId)
            ->first();

        if (!$campaign) {
            return [
                'success' => false,
                'message' => 'Campaign not found. Please check the campaign name or ID.',
            ];
        }

        // Update fields - Support individual field updates with flexible pattern matching
        $updates = [];
        $messageLower = strtolower($message);
        
        // Pattern matching for individual field updates (similar to content updates)
        // Update name/title patterns
        if (preg_match('/\b(?:update|change|edit|set)\s+(?:the\s+)?(?:campaign\s+)?(?:name|title)\s+(?:to|as|is)\s+["\']?([^"\']+)["\']?/i', $message, $nameMatch)) {
            $newNameValue = trim($nameMatch[1] ?? '');
            if (!empty($newNameValue) && strlen($newNameValue) > 2) {
                $updates['name'] = trim($newNameValue);
            }
        } elseif (!empty($newName)) {
            $updates['name'] = trim($newName);
        } elseif (!empty($data['name'])) {
            $updates['name'] = trim($data['name']);
        }
        
        // Update start date patterns - "update start date to January 1st", "change start to tomorrow"
        if (preg_match('/\b(?:update|change|edit|set)\s+(?:the\s+)?(?:campaign\s+)?(?:start\s+date|start\s+date|begins?|start)\s+(?:to|as|is|on)\s+["\']?([^"\']+)["\']?/i', $message, $startDateMatch)) {
            $newStartDateStr = trim($startDateMatch[1] ?? '');
            if (!empty($newStartDateStr)) {
                try {
                    $parsedStartDate = Carbon::parse($newStartDateStr);
                    if ($parsedStartDate->format('Y-m-d') >= Carbon::today()->format('Y-m-d')) {
                        $updates['start_date'] = $parsedStartDate->format('Y-m-d');
                    }
                } catch (\Exception $e) {
                    // Try extracting from extracted data as fallback
                }
            }
        } elseif (!empty($data['start_date'])) {
            try {
                $startDate = Carbon::parse($data['start_date'])->format('Y-m-d');
                if ($startDate >= Carbon::today()->format('Y-m-d')) {
                    $updates['start_date'] = $startDate;
                }
            } catch (\Exception $e) {
                // Invalid date, skip
            }
        }
        
        // Update end date patterns - "update end date to January 31st", "change end to next month"
        if (preg_match('/\b(?:update|change|edit|set)\s+(?:the\s+)?(?:campaign\s+)?(?:end\s+date|end\s+date|ends?|finish)\s+(?:to|as|is|on)\s+["\']?([^"\']+)["\']?/i', $message, $endDateMatch)) {
            $newEndDateStr = trim($endDateMatch[1] ?? '');
            if (!empty($newEndDateStr)) {
                try {
                    $parsedEndDate = Carbon::parse($newEndDateStr);
                    $startDate = $updates['start_date'] ?? $campaign->start_date->format('Y-m-d');
                    if ($parsedEndDate->format('Y-m-d') > $startDate) {
                        $updates['end_date'] = $parsedEndDate->format('Y-m-d');
                    }
                } catch (\Exception $e) {
                    // Try extracting from extracted data as fallback
                }
            }
        } elseif (!empty($data['end_date'])) {
            try {
                $endDate = Carbon::parse($data['end_date'])->format('Y-m-d');
                $startDate = $updates['start_date'] ?? $campaign->start_date->format('Y-m-d');
                if ($endDate > $startDate) {
                    $updates['end_date'] = $endDate;
                }
            } catch (\Exception $e) {
                // Invalid date, skip
            }
        }
        
        // Update send time patterns - "update send time to 8 AM", "change time to 09:00"
        if (preg_match('/\b(?:update|change|edit|set)\s+(?:the\s+)?(?:campaign\s+)?(?:send\s+time|time|schedule)\s+(?:to|as|is|at)\s+["\']?([^"\']+)["\']?/i', $message, $timeMatch)) {
            $newTimeStr = trim($timeMatch[1] ?? '');
            if (!empty($newTimeStr)) {
                // Try to parse time formats like "8 AM", "09:00", "8am", etc.
                try {
                    $parsedTime = Carbon::parse($newTimeStr);
                    $timeFormat = $parsedTime->format('H:i');
                    if (preg_match('/^\d{2}:\d{2}$/', $timeFormat)) {
                        $updates['send_time_local'] = $timeFormat;
                    }
                } catch (\Exception $e) {
                    // Try to extract HH:MM format directly
                    if (preg_match('/(\d{1,2}):(\d{2})\s*(?:AM|PM)?/i', $newTimeStr, $timeParts)) {
                        $hour = (int)$timeParts[1];
                        $minute = (int)$timeParts[2];
                        // Check if AM/PM specified
                        if (preg_match('/PM/i', $newTimeStr) && $hour < 12) {
                            $hour += 12;
                        } elseif (preg_match('/AM/i', $hour == 12)) {
                            $hour = 0;
                        }
                        $updates['send_time_local'] = sprintf('%02d:%02d', $hour, $minute);
                    }
                }
            }
        } elseif (!empty($data['send_time_local'])) {
            $updates['send_time_local'] = $data['send_time_local'];
        }
        
        // Update channels patterns - "update channels to whatsapp", "change channels to web and whatsapp"
        if (preg_match('/\b(?:update|change|edit|set)\s+(?:the\s+)?(?:campaign\s+)?channels?\s+(?:to|as|is)\s+["\']?([^"\']+)["\']?/i', $message, $channelsMatch)) {
            $channelsStr = strtolower(trim($channelsMatch[1] ?? ''));
            if (!empty($channelsStr)) {
                $detectedChannels = [];
                if (stripos($channelsStr, 'whatsapp') !== false || stripos($channelsStr, 'whats') !== false) {
                    $detectedChannels[] = 'whatsapp';
                }
                if (stripos($channelsStr, 'web') !== false || stripos($channelsStr, 'notification') !== false) {
                    $detectedChannels[] = 'web';
                }
                if (stripos($channelsStr, 'email') !== false || stripos($channelsStr, 'gmail') !== false || stripos($channelsStr, 'mail') !== false) {
                    $detectedChannels[] = 'email';
                }
                if (stripos($channelsStr, 'push') !== false) {
                    $detectedChannels[] = 'push';
                }
                if (!empty($detectedChannels)) {
                    $updates['channels'] = array_values(array_unique($detectedChannels));
                }
            }
        } elseif (!empty($data['channels']) && is_array($data['channels'])) {
            $updates['channels'] = $data['channels'];
        }
        
        // Update status patterns - "update status to active", "change status to paused", "status active to inactive"
        // Handle patterns like "status active to inactive" or "status to inactive"
        $statusUpdatePatterns = [
            '/\b(?:update|change|edit|set)\s+(?:the\s+)?(?:campaign\s+)?status\s+(?:from\s+\w+\s+)?to\s+["\']?([^"\']+)["\']?/i',
            '/\bstatus\s+(?:active|paused|cancelled)\s+to\s+["\']?([^"\']+)["\']?/i',
            '/\bstatus\s+to\s+["\']?([^"\']+)["\']?/i',
            '/\b(?:update|change|edit|set)\s+(?:the\s+)?(?:campaign\s+)?status\s+(?:as|is)\s+["\']?([^"\']+)["\']?/i',
        ];
        
        $statusMatched = false;
        foreach ($statusUpdatePatterns as $pattern) {
            if (preg_match($pattern, $message, $statusMatch)) {
                $newStatus = strtolower(trim($statusMatch[1] ?? ''));
                
                // Map common status variations
                $statusMap = [
                    'inactive' => 'paused',
                    'deactivate' => 'paused',
                    'activate' => 'active',
                    'enable' => 'active',
                    'disable' => 'paused',
                    'stop' => 'paused',
                    'cancel' => 'cancelled',
                    'delete' => 'cancelled',
                ];
                
                // Check if status needs mapping
                if (isset($statusMap[$newStatus])) {
                    $newStatus = $statusMap[$newStatus];
                }
                
                $validStatuses = ['active', 'paused', 'cancelled'];
                if (in_array($newStatus, $validStatuses)) {
                    $updates['status'] = $newStatus;
                    $statusMatched = true;
                    Log::info('Campaign status update matched', [
                        'original' => $statusMatch[1] ?? '',
                        'mapped_to' => $newStatus,
                    ]);
                    break;
                }
            }
        }
        
        // Fallback to extracted data if pattern matching didn't work
        if (!$statusMatched && !empty($data['status'])) {
            $newStatus = strtolower($data['status']);
            $statusMap = [
                'inactive' => 'paused',
                'deactivate' => 'paused',
                'activate' => 'active',
            ];
            if (isset($statusMap[$newStatus])) {
                $newStatus = $statusMap[$newStatus];
            }
            $validStatuses = ['active', 'paused', 'cancelled'];
            if (in_array($newStatus, $validStatuses)) {
                $updates['status'] = $newStatus;
            }
        }

        if (empty($updates)) {
            return [
                'success' => false,
                'message' => 'Please specify what you want to update. For example: "Edit campaign X to change the name to Y" or "Update campaign X to start on January 1st".',
            ];
        }

        // Perform the update
        try {
            $campaign->update($updates);
            $campaign->refresh(); // Refresh to get updated values
            
            Log::info('Campaign updated successfully via AI chat', [
                'campaign_id' => $campaign->id,
                'user_id' => $user->id,
                'updates' => array_keys($updates),
            ]);

            $updateMessage = "✓ Campaign updated successfully.\n\n";
            
            if (!empty($updates)) {
                $updateMessage .= "Updated fields:\n";
                foreach ($updates as $field => $value) {
                    $displayField = ucwords(str_replace('_', ' ', $field));
                    if ($field === 'name') {
                        $updateMessage .= "• {$displayField}: \"{$value}\"\n";
                    } elseif ($field === 'start_date' || $field === 'end_date') {
                        $formattedDate = Carbon::parse($value)->format('M d, Y');
                        $updateMessage .= "• {$displayField}: {$formattedDate}\n";
                    } elseif ($field === 'send_time_local') {
                        $updateMessage .= "• {$displayField}: {$value}\n";
                    } elseif ($field === 'channels' && is_array($value)) {
                        $updateMessage .= "• {$displayField}: " . implode(', ', $value) . "\n";
                    } elseif ($field === 'status') {
                        $updateMessage .= "• {$displayField}: " . ucfirst($value) . "\n";
                    } else {
                        $preview = is_string($value) ? Str::limit($value, 100) : (is_array($value) ? implode(', ', $value) : $value);
                        $updateMessage .= "• {$displayField}: {$preview}\n";
                    }
                }
            }
            
            return [
                'success' => true,
                'message' => trim($updateMessage),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to update campaign', [
                'user_id' => $user->id,
                'campaign_id' => $campaignId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Sorry, I encountered an error while updating the campaign: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Delete a campaign from chat message
     */
    protected function deleteCampaignFromChat(string $message, User $user): array
    {
        // Load organization relationship if not already loaded
        if (!$user->relationLoaded('organization')) {
            $user->load('organization');
        }

        $organizationId = $user->organization?->id;
        
        // Alternative: Try to get organization_id from board memberships if organization relationship is null
        if (!$organizationId) {
            $boardMembership = \App\Models\BoardMember::where('user_id', $user->id)->first();
            if ($boardMembership) {
                $organizationId = $boardMembership->organization_id;
                Log::info('Found organization via board membership for delete', [
                    'user_id' => $user->id,
                    'organization_id' => $organizationId,
                ]);
            }
        }
        
        if (!$organizationId) {
            Log::warning('User attempted to delete campaign without organization', [
                'user_id' => $user->id,
                'user_role' => $user->role,
                'organization_role' => $user->organization_role,
            ]);

            return [
                'success' => false,
                'message' => 'You must be part of an organization to delete campaigns. Please contact your administrator.',
            ];
        }

        // Extract campaign identifier - also try to extract from message directly
        $extractedData = $this->extractCampaignDataFromMessage($message, $user, 'delete');
        
        // If extraction fails, try to find campaign name directly in message
        $campaignName = null;
        $campaignId = null;

        if ($extractedData['success']) {
            $data = $extractedData['data'];
            $campaignId = $data['campaign_id'] ?? null;
            $campaignName = $data['name'] ?? null;
        }

        // If no campaign name/ID extracted, try to find it in the message
        if (!$campaignId && !$campaignName) {
            // Get user's campaigns to help match
            $userCampaigns = Campaign::forOrganization($organizationId)
                ->get(['id', 'name']);
            
            // Try to find campaign name in message
            foreach ($userCampaigns as $campaign) {
                if (stripos($message, $campaign->name) !== false) {
                    $campaignName = $campaign->name;
                    $campaignId = $campaign->id;
                    Log::info('Found campaign in message by name matching', [
                        'campaign_id' => $campaignId,
                        'campaign_name' => $campaignName,
                    ]);
                    break;
                }
            }
        }

        // Try to find campaign by name if we have a name but no ID
        if (!$campaignId && $campaignName) {
            $campaign = Campaign::forOrganization($organizationId)
                ->where('name', 'LIKE', '%' . $campaignName . '%')
                ->first();
            
            if ($campaign) {
                $campaignId = $campaign->id;
                Log::info('Found campaign by name search', [
                    'campaign_id' => $campaignId,
                    'searched_name' => $campaignName,
                ]);
            }
        }

        if (!$campaignId) {
            // Check if this is a generic request (no specific campaign mentioned)
            $isGenericRequest = $this->isGenericRequest($message, ['campaign']);
            
            if ($isGenericRequest) {
                // Show list of campaigns with details
                $availableCampaigns = Campaign::forOrganization($organizationId)
                    ->with(['user', 'selectedUsers'])
                    ->orderBy('created_at', 'desc')
                    ->get();
                
                if ($availableCampaigns->isEmpty()) {
                    return [
                        'success' => false,
                        'message' => 'No campaigns found to delete.',
                    ];
                }
                
                $campaignList = "Here are your campaigns:\n\n";
                foreach ($availableCampaigns as $index => $camp) {
                    $statusEmoji = match($camp->status) {
                        'active' => '✅',
                        'paused' => '⏸️',
                        'cancelled' => '❌',
                        default => '📋'
                    };
                    $startDate = Carbon::parse($camp->start_date)->format('M d, Y');
                    $endDate = Carbon::parse($camp->end_date)->format('M d, Y');
                    $recipientCount = $camp->selectedUsers->count() ?? 0;
                    $channels = is_array($camp->channels) ? implode(', ', $camp->channels) : ($camp->channels ?? 'N/A');
                    
                    $campaignList .= sprintf(
                        "%d. %s %s\n   • Period: %s to %s\n   • Send Time: %s\n   • Channels: %s\n   • Recipients: %d users\n   • Status: %s\n\n",
                        $index + 1,
                        $statusEmoji,
                        $camp->name,
                        $startDate,
                        $endDate,
                        $camp->send_time_local ?? 'N/A',
                        $channels,
                        $recipientCount,
                        ucfirst($camp->status)
                    );
                }
                
                $campaignList .= "Which campaign would you like to delete? You can:\n";
                $campaignList .= "• Say the campaign name (e.g., \"Delete {$availableCampaigns->first()->name}\")\n";
                $campaignList .= "• Say the number (e.g., \"Delete campaign 1\")\n";
                $campaignList .= "• Say \"first\", \"second\", \"last\", etc.";
                
                return [
                    'success' => true,
                    'response' => $campaignList,
                ];
            }
            
            // Try harder to find campaign - match any words from message against campaign names
            $availableCampaigns = Campaign::forOrganization($organizationId)->get(['id', 'name']);
            
            if ($availableCampaigns->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'No campaigns found to delete.',
                ];
            }

            // Try to match campaign names with words from the message
            $messageWords = preg_split('/\s+/', $message);
            $messageWords = array_map('strtolower', $messageWords);
            $messageWords = array_filter($messageWords, function($word) {
                return strlen($word) > 2 && !in_array(strtolower($word), ['the', 'and', 'or', 'to', 'for', 'delete', 'remove', 'campaign', 'title', 'name']);
            });

            foreach ($availableCampaigns as $camp) {
                $campNameLower = strtolower($camp->name);
                $campNameWords = preg_split('/\s+/', $campNameLower);
                
                // Check if any significant words from message match campaign name
                foreach ($messageWords as $word) {
                    if (in_array($word, $campNameWords) || str_contains($campNameLower, $word)) {
                        $campaignId = $camp->id;
                        Log::info('Found campaign for deletion by word matching', [
                            'campaign_id' => $campaignId,
                            'matched_word' => $word,
                            'campaign_name' => $camp->name,
                        ]);
                        break 2;
                    }
                }
                
                // Also check if campaign name contains significant words from message
                foreach ($messageWords as $word) {
                    if (strlen($word) > 3 && str_contains($campNameLower, $word)) {
                        $campaignId = $camp->id;
                        Log::info('Found campaign for deletion by reverse word matching', [
                            'campaign_id' => $campaignId,
                            'matched_word' => $word,
                            'campaign_name' => $camp->name,
                        ]);
                        break 2;
                    }
                }
            }

            // If still not found, try partial string matching
            if (!$campaignId) {
                foreach ($availableCampaigns as $camp) {
                    // Extract potential campaign name from message (capitalized words)
                    if (preg_match('/\b([A-Z][A-Za-z0-9\s]{3,})\b/', $message, $matches)) {
                        $potentialName = trim($matches[1]);
                        if (stripos($camp->name, $potentialName) !== false || stripos($potentialName, $camp->name) !== false) {
                            $campaignId = $camp->id;
                            Log::info('Found campaign for deletion by capitalized word matching', [
                                'campaign_id' => $campaignId,
                                'potential_name' => $potentialName,
                                'campaign_name' => $camp->name,
                            ]);
                            break;
                        }
                    }
                }
            }

            // If still nothing found, just use the first campaign (as fallback)
            if (!$campaignId && $availableCampaigns->count() === 1) {
                $campaignId = $availableCampaigns->first()->id;
                Log::info('Using only available campaign for deletion as fallback', [
                    'campaign_id' => $campaignId,
                ]);
            }

            // Only show error if we really can't figure it out
            if (!$campaignId) {
                return [
                    'success' => false,
                    'message' => 'Could not identify which campaign to delete. Please specify the campaign name more clearly.',
                ];
            }
        }

        // Find the campaign
        $campaign = Campaign::forOrganization($organizationId)
            ->where('id', $campaignId)
            ->first();

        if (!$campaign) {
            return [
                'success' => false,
                'message' => 'Campaign not found. Please check the campaign name or ID.',
            ];
        }

        $campaignName = $campaign->name;

        // Cancel pending drops and delete campaign
        $campaign->scheduledDrops()
            ->where('status', 'pending')
            ->update(['status' => 'cancelled']);

        $campaign->update(['status' => 'cancelled']);
        $campaign->delete();

        return [
            'success' => true,
            'message' => "Campaign '{$campaignName}' has been deleted successfully.",
        ];
    }

    /**
     * Create content from chat message using AI to extract and generate data
     */
    protected function createContentFromChat(string $message, User $user, ?AiChatConversation $conversation = null): array
    {
        // Load organization relationship if not already loaded
        if (!$user->relationLoaded('organization')) {
            $user->load('organization');
        }

        $organizationId = $user->organization?->id;
        
        // Alternative: Try to get organization_id from board memberships if organization relationship is null
        if (!$organizationId) {
            $boardMembership = \App\Models\BoardMember::where('user_id', $user->id)->first();
            if ($boardMembership) {
                $organizationId = $boardMembership->organization_id;
                Log::info('Found organization via board membership for content creation', [
                    'user_id' => $user->id,
                    'organization_id' => $organizationId,
                ]);
            }
        }
        
        if (!$organizationId) {
            Log::warning('User attempted to create content without organization', [
                'user_id' => $user->id,
            ]);

            return [
                'success' => false,
                'message' => 'You must be part of an organization to create content. Please contact your administrator.',
            ];
        }

        // Use OpenAI to extract content data from natural language
        $extractedData = $this->extractContentDataFromMessage($message, $user);
        
        if (!$extractedData['success']) {
            Log::warning('Failed to extract content data', [
                'user_id' => $user->id,
                'message' => substr($message, 0, 200),
                'error' => $extractedData['message'] ?? 'Unknown error',
            ]);
            return $extractedData;
        }

        $data = $extractedData['data'];

        Log::info('Content data extracted', [
            'user_id' => $user->id,
            'extracted_data' => $data,
        ]);

        // Check if user explicitly wants to generate content
        $messageLower = strtolower($message);
        $wantsGenerateTitle = preg_match('/\b(generate|create|make|auto).*title\b/i', $message) ||
                              preg_match('/\bgenerate\s+(?:a\s+)?title\b/i', $message);
        $wantsGenerateBody = preg_match('/\b(generate|create|make|auto).*body\b/i', $message) ||
                             preg_match('/\b(generate|create|make).*(?:content|text|body)\b/i', $message);
        $wantsGenerateBoth = preg_match('/\bgenerate\s+(?:both|all|everything)\b/i', $message) ||
                             preg_match('/\b(generate|create|make)\s+(?:it\s+)?(?:with\s+)?(?:your\s+)?(?:own\s+)?data\b/i', $message) ||
                             preg_match('/\bwith\s+(?:your\s+)?(?:own\s+)?data\b/i', $message) ||
                             preg_match('/\byou\s+(?:can\s+)?(?:generate|create|make|choose)\b/i', $message);
        
        Log::info('Content creation generation check', [
            'user_id' => $user->id,
            'wants_generate_title' => $wantsGenerateTitle,
            'wants_generate_body' => $wantsGenerateBody,
            'wants_generate_both' => $wantsGenerateBoth,
            'has_title' => !empty($data['title']),
            'has_body' => !empty($data['body']),
        ]);

        // Validation - DO NOT auto-generate unless explicitly requested
        $errors = [];
        
        // Generate title ONLY if user explicitly requests it or wants to generate both
        if (empty($data['title'])) {
            if ($wantsGenerateTitle || $wantsGenerateBoth) {
                // User explicitly wants title generated
                try {
                    $typeDescription = $data['type'] ?? 'prayer';
                    $titlePrompt = "Generate a professional {$typeDescription} title";
                    if (!empty($data['scripture_ref'])) {
                        $titlePrompt .= " related to {$data['scripture_ref']}";
                    }
                    $data['title'] = $this->generateContentTitle($titlePrompt, $data['type'] ?? 'prayer', $user);
                    Log::info('Generated content title', ['title' => $data['title']]);
                } catch (\Exception $e) {
                    Log::error('Failed to generate content title', ['error' => $e->getMessage()]);
                    $typeName = ucfirst($data['type'] ?? 'prayer');
                    $data['title'] = "{$typeName} - " . date('F j, Y');
                }
            } else {
                $errors[] = 'Content title is required. Please provide a title or say "generate title" or "generate both" to auto-generate.';
            }
        }
        
        // Generate content body ONLY if user explicitly requests it or wants to generate both
        if (empty($data['body'])) {
            if ($wantsGenerateBody || $wantsGenerateBoth) {
                // User explicitly wants body generated
                $finalTitle = $data['title'] ?? ($data['type'] ?? 'Prayer');
                try {
                    $generatedContent = $this->generateContentBody($finalTitle, $data['type'] ?? 'prayer', $data['scripture_ref'] ?? null, $user);
                    $data['body'] = trim($generatedContent);
                    
                    if (strlen($data['body']) < 10) {
                        throw new \Exception('Generated content is too short.');
                    }
                    
                    Log::info('Generated content body using AI', [
                        'user_id' => $user->id,
                        'title' => $finalTitle,
                        'type' => $data['type'] ?? 'prayer',
                        'body_length' => strlen($data['body']),
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to generate content body', [
                        'user_id' => $user->id,
                        'error' => $e->getMessage(),
                    ]);
                    try {
                        $data['body'] = $this->generateContentBodySimple($finalTitle, $data['type'] ?? 'prayer', $user);
                        Log::info('Generated content body using simple method', [
                            'user_id' => $user->id,
                            'body_length' => strlen($data['body']),
                        ]);
                    } catch (\Exception $e2) {
                        Log::error('Failed to generate content body with simple method', [
                            'error' => $e2->getMessage(),
                        ]);
                        $errors[] = 'Content body is required. I tried to generate it but encountered an error. Please provide the content text manually or try again.';
                    }
                }
            } else {
                $errors[] = 'Content body is required. Please provide the content text or say "generate body" or "generate both" to auto-generate it.';
            }
        }
        if (empty($data['type'])) {
            $data['type'] = 'prayer'; // Default type
        } else {
            // Validate type
            $validTypes = ['prayer', 'devotional', 'scripture'];
            if (!in_array(strtolower($data['type']), $validTypes)) {
                $data['type'] = 'prayer'; // Default to prayer if invalid
            } else {
                $data['type'] = strtolower($data['type']);
            }
        }

        if (!empty($errors)) {
            $errorMessage = 'To create content, I need: ' . implode(', ', $errors);
            $errorMessage .= "\n\nOptions:\n";
            $errorMessage .= "• Provide details manually (title, body, type)\n";
            $errorMessage .= "• Say \"generate title\" to generate only the title\n";
            $errorMessage .= "• Say \"generate body\" to generate only the content body\n";
            $errorMessage .= "• Say \"generate both\" to generate both title and content automatically";
            
            return [
                'success' => false,
                'message' => $errorMessage,
            ];
        }

        // Prepare meta data
        $metaData = [
            'scripture_ref' => $data['scripture_ref'] ?? '',
            'tags' => $data['tags'] ?? [],
        ];

        // Create the content item
        try {
            $contentItem = ContentItem::create([
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'title' => trim($data['title']),
                'body' => trim($data['body']),
                'type' => $data['type'],
                'meta' => $metaData,
                'is_approved' => true, // Auto-approve content created by organization users
            ]);
            
            Log::info('Content created successfully via AI chat', [
                'content_id' => $contentItem->id,
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'type' => $contentItem->type,
            ]);

            $successMessage = "✓ Content created successfully!\n\n";
            $successMessage .= "• Title: {$contentItem->title}\n";
            $successMessage .= "• Type: " . ucfirst($contentItem->type) . "\n";
            if (!empty($metaData['scripture_ref'])) {
                $successMessage .= "• Scripture: {$metaData['scripture_ref']}\n";
            }
            if (!empty($metaData['tags'])) {
                $successMessage .= "• Tags: " . implode(', ', $metaData['tags']) . "\n";
            }
            $successMessage .= "\nReady to use in your campaigns.";
            
            return [
                'success' => true,
                'message' => $successMessage,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to create content', [
                'user_id' => $user->id,
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Sorry, I encountered an error while creating the content: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Generate content title using AI
     */
    protected function generateContentTitle(string $prompt, string $type = 'prayer', ?User $user = null): string
    {
        $systemPrompt = <<<PROMPT
You are a creative professional specializing in generating compelling titles for spiritual content.

Generate ONE clear, professional title for {$type} content.
- 3-8 words
- Professional and clear
- Spiritually appropriate
- Inspiring and meaningful

Return ONLY the title, nothing else. No quotes, no markdown.
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $prompt],
        ];

        $result = $this->openAiService->chatCompletion($messages);
            $title = trim($result['content'] ?? '');
            $totalTokens = (int) ($result['total_tokens'] ?? 0);
            if ($totalTokens > 0 && $user) {
                $user->consumeAiTokens($totalTokens);
            }
            $title = trim($title, ' "\'');
        
        if (empty($title) || strlen($title) < 3) {
            $typeName = ucfirst($type);
            $title = "{$typeName} - " . date('F j, Y');
        }
        
        return $title;
    }

    /**
     * Generate content body using AI
     */
    protected function generateContentBody(string $title, string $type, ?string $scriptureRef = null, ?User $user = null): string
    {
        $typeDescriptions = [
            'prayer' => 'a heartfelt prayer',
            'devotional' => 'a devotional reflection',
            'scripture' => 'a scripture reflection',
        ];

        $typeDescription = $typeDescriptions[$type] ?? 'a prayer';
        $scriptureContext = $scriptureRef ? " Include a reference to {$scriptureRef}." : '';

        $prompt = "Write {$typeDescription} with the title '{$title}'.{$scriptureContext} Make it personal, inspiring, and 50-150 words. Write in first person as if someone is praying or reflecting.";

        $messages = [
            [
                'role' => 'system',
                'content' => 'You are a spiritual content creator specializing in Christian prayers and devotions. Generate heartfelt, personal, and inspiring content.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        $result = $this->openAiService->chatCompletion($messages);
        $totalTokens = (int) ($result['total_tokens'] ?? 0);
        if ($totalTokens > 0 && $user) {
            $user->consumeAiTokens($totalTokens);
        }
        return $result['content'] ?? '';
    }

    /**
     * Generate content body using simpler approach (fallback)
     */
    protected function generateContentBodySimple(string $title, string $type, ?User $user = null): string
    {
        $typeDescriptions = [
            'prayer' => 'prayer',
            'devotional' => 'devotional reflection',
            'scripture' => 'scripture reflection',
        ];

        $typeDescription = $typeDescriptions[$type] ?? 'prayer';
        $prompt = "Write a {$typeDescription} titled '{$title}'. Make it 50-100 words, personal and inspiring.";

        $messages = [
            [
                'role' => 'system',
                'content' => 'Generate spiritual content. Return only the content text, no explanations.',
            ],
            [
                'role' => 'user',
                'content' => $prompt,
            ],
        ];

        $result = $this->openAiService->chatCompletion($messages);
        $totalTokens = (int) ($result['total_tokens'] ?? 0);
        if ($totalTokens > 0 && $user) {
            $user->consumeAiTokens($totalTokens);
        }
        return trim($result['content'] ?? '');
    }

    /**
     * Extract content data from natural language using OpenAI
     */
    protected function extractContentDataFromMessage(string $message, User $user): array
    {
        try {
            $systemPrompt = <<<PROMPT
You are a professional AI assistant specialized in extracting structured content data from natural language messages with high accuracy and precision.

═══════════════════════════════════════════════════════════════════════════════
REQUIRED JSON OUTPUT STRUCTURE:
═══════════════════════════════════════════════════════════════════════════════
Return ONLY a valid JSON object with this EXACT structure (no markdown, no code blocks):
{
  "title": "content title string or null",
  "body": "full content body text or null",
  "type": "prayer" or "devotional" or "scripture" or null,
  "scripture_ref": "Bible verse reference like 'John 3:16' or null",
  "tags": ["tag1", "tag2"] or null (array of strings)
}

═══════════════════════════════════════════════════════════════════════════════
CRITICAL EXTRACTION RULES:
═══════════════════════════════════════════════════════════════════════════════

1. TITLE EXTRACTION PATTERNS:
   Search for and extract titles using these patterns:
   - After keywords: "titled", "called", "named", "with title", "titled as", "about"
   - Direct mentions: "Create a prayer Morning Devotion" → extract "Morning Devotion"
   - Quoted text: "Create 'Evening Prayer' content" → extract "Evening Prayer"
   - Topic-based: "Write a prayer about peace" → title: "Prayer for Peace"
   - Description-based: "Create content for morning motivation" → title: "Morning Motivation"
   - Examples:
     * "Create a prayer titled Morning Devotion" → title: "Morning Devotion"
     * "Write a devotional called Evening Reflection" → title: "Evening Reflection"
     * "Make content about gratitude" → title: "Content about Gratitude" or infer better title
     * "Create prayer for strength" → title: "Prayer for Strength"

2. BODY EXTRACTION PATTERNS:
   Extract the actual content text if provided:
   - Quoted text: User provides content in quotes "..." → extract entire quoted text
   - After keywords: "content:", "body:", "text:", "prayer text:"
   - Full message: If user provides complete prayer/devotional text → extract it all
   - Multi-line content: Extract all lines between markers or in quotes
   - Examples:
     * "Create a prayer with this text: 'Heavenly Father, thank you...'" → extract quoted text as body
     * "Body: [prayer text here]" → extract text after "Body:"
     * If user only provides title → return null (system will AI-generate the body)

3. TYPE IDENTIFICATION PATTERNS:
   Determine content type from keywords (must be lowercase):
   - "prayer", "pray", "praying", "prayer content" → "prayer"
   - "devotional", "devotion", "devotional reflection", "reflection" → "devotional"
   - "scripture", "bible verse", "verse", "bible passage", "scripture reflection" → "scripture"
   - Default: If unclear, return "prayer"
   - Examples:
     * "Create a prayer" → type: "prayer"
     * "Write a devotional" → type: "devotional"
     * "Make scripture content" → type: "scripture"
     * "Create content" (no type mentioned) → type: "prayer" (default)

4. SCRIPTURE REFERENCE EXTRACTION PATTERNS:
   Extract Bible verse references (any format):
   - Standard format: "John 3:16", "Psalm 23", "Matthew 5:3-12", "Romans 8:28"
   - With keywords: "verse John 3:16", "scripture Psalm 23", "from Matthew 5"
   - Book only: "Genesis", "Psalms", "Proverbs" (include as scripture_ref)
   - Common patterns:
     * Book name (capitalized) + Chapter:Verse
     * Book name + Chapter
     * "Psalm" or "Psalms" + number
     * "Proverbs" + Chapter:Verse
   - Examples:
     * "with verse John 3:16" → scripture_ref: "John 3:16"
     * "based on Psalm 23" → scripture_ref: "Psalm 23"
     * "scripture Matthew 5:3-12" → scripture_ref: "Matthew 5:3-12"
     * "from Romans chapter 8" → scripture_ref: "Romans 8"

5. TAGS EXTRACTION PATTERNS:
   Extract tags from explicit mentions or keywords:
   - After keywords: "tags:", "with tags", "tagged as", "tags are", "categorized as"
   - Comma-separated: "tags peace, hope, faith" → ["peace", "hope", "faith"]
   - Listed: "tags: morning, prayer, daily" → ["morning", "prayer", "daily"]
   - From context: Infer tags from title/content theme if appropriate
   - Examples:
     * "with tags peace, hope, faith" → tags: ["peace", "hope", "faith"]
     * "tagged as morning prayer" → tags: ["morning", "prayer"]
     * "tags: gratitude, thanksgiving" → tags: ["gratitude", "thanksgiving"]
     * No tags mentioned → tags: null

═══════════════════════════════════════════════════════════════════════════════
ADVANCED SCENARIOS:
═══════════════════════════════════════════════════════════════════════════════

- If user says "create content" without details → extract what you can, return nulls for missing fields
- If user says "create content with the data" or "with your own data" or "generate both" → return null for BOTH title AND body (system will generate everything)
- If user says "with the data please" → return null for title and body (system will auto-generate)
- If user provides only title → return title, null for body (AI will generate it)
- If user provides title + body → extract both
- If user provides full prayer text → extract as body, infer title if missing
- Multiple content items: If user wants to create multiple, focus on the first one mentioned

CRITICAL: When user says "with the data", "with your own data", "generate both", "please generate", etc.:
→ Return {"title": null, "body": null, "type": "prayer", "scripture_ref": null, "tags": null}
→ The system will automatically generate both title and body

═══════════════════════════════════════════════════════════════════════════════
OUTPUT FORMATTING REQUIREMENTS:
═══════════════════════════════════════════════════════════════════════════════

CRITICAL: Return ONLY valid JSON - NO MARKDOWN, NO CODE BLOCKS, NO EXPLANATIONS
- Do NOT wrap response in ```json or ``` code blocks
- Do NOT add any text before or after the JSON
- Do NOT include explanatory comments in the JSON
- Return ONLY the raw JSON object starting with { and ending with }

CORRECT OUTPUT FORMAT:
{"title":"Morning Prayer","body":null,"type":"prayer","scripture_ref":"Psalm 23","tags":["morning","peace"]}

═══════════════════════════════════════════════════════════════════════════════
Now extract the content data from the user's message and return ONLY the JSON object:
PROMPT;

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $message],
            ];

            $result = $this->openAiService->chatCompletion($messages);
            $responseContent = $result['content'] ?? '';
            $totalTokens = (int) ($result['total_tokens'] ?? 0);
            if ($totalTokens > 0) {
                $user->consumeAiTokens($totalTokens);
            }
            
            // Parse JSON from response
            $cleanResponse = trim($responseContent);
            $cleanResponse = preg_replace('/^```json\s*/i', '', $cleanResponse);
            $cleanResponse = preg_replace('/\s*```$/i', '', $cleanResponse);
            $cleanResponse = preg_replace('/^```\s*/i', '', $cleanResponse);
            $cleanResponse = trim($cleanResponse);

            // Try to extract JSON object
            if (preg_match('/\{[\s\S]*\}/', $cleanResponse, $matches)) {
                $jsonString = $matches[0];
            } else {
                $jsonString = $cleanResponse;
            }

            $data = json_decode($jsonString, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to parse JSON: ' . json_last_error_msg());
            }

            return [
                'success' => true,
                'data' => [
                    'title' => $data['title'] ?? null,
                    'body' => $data['body'] ?? null,
                    'type' => $data['type'] ?? 'prayer',
                    'scripture_ref' => $data['scripture_ref'] ?? null,
                    'tags' => $data['tags'] ?? null,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Failed to extract content data', [
                'error' => $e->getMessage(),
                'response' => substr($responseContent ?? 'No response', 0, 200),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to extract content information from your message. Please try again with clearer details.',
            ];
        }
    }

    /**
     * Edit content from chat message
     */
    protected function editContentFromChat(string $message, User $user): array
    {
        $organizationId = $user->organization?->id;
        
        if (!$organizationId) {
            return [
                'success' => false,
                'message' => 'You must be part of an organization to edit content.',
            ];
        }

        // Extract content identifier and update data
        $extractedData = $this->extractContentDataFromMessage($message, $user);
        
        // If extraction failed, try to parse manually for title updates
        $contentId = null;
        $newTitle = null;
        $contentIdentifier = null;
        $messageLower = strtolower($message);
        
        // Patterns for "update title of first/content to X" or "edit content title to X"
        $titleUpdatePatterns = [
            // "please update the title first one to Test Worship" - more flexible word order
            '/(?:please\s+)?(?:update|edit|change)\s+(?:the\s+)?title\s+(?:of\s+)?(?:the\s+)?(?:first|1st)\s+(?:one|content|item)\s+to\s+["\']?([^"\'.!?\n]+?)["\']?(?:\s*[.,!?]|\s*$|$)/i',
            // "edit first content title to Test Worship"
            '/(?:edit|update|change)\s+(?:the\s+)?(?:first|1st)\s+(?:content|item)\s+title\s+to\s+["\']?([^"\'.!?\n]+?)["\']?(?:\s*[.,!?]|\s*$|$)/i',
            // "update title first one to Test Worship"
            '/(?:update|edit|change)\s+title\s+(?:of\s+)?(?:the\s+)?(?:first|1st)\s+(?:one|content|item)\s+to\s+["\']?([^"\'.!?\n]+?)["\']?(?:\s*[.,!?]|\s*$|$)/i',
            // "update first one title to Test Worship" - alternative word order
            '/(?:update|edit|change)\s+(?:the\s+)?(?:first|1st)\s+(?:one|content|item)\s+title\s+to\s+["\']?([^"\'.!?\n]+?)["\']?(?:\s*[.,!?]|\s*$|$)/i',
            // "edit content [title] title to [new title]"
            '/(?:edit|update|change)\s+(?:content|item)\s+([\'"]?)([^\'"]+?)\1\s+title\s+to\s+([\'"]?)([^\'"]+?)\3/i',
            // "update title of [title] to [new title]"
            '/(?:update|edit|change)\s+(?:the\s+)?title\s+of\s+([\'"]?)([^\'"]+?)\1\s+to\s+([\'"]?)([^\'"]+?)\3/i',
        ];
        
        foreach ($titleUpdatePatterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                if (count($matches) >= 5) {
                    // Quoted version with content identifier
                    $contentIdentifier = trim($matches[2]);
                    $newTitle = trim($matches[4]);
                } else {
                    // Unquoted version - extract new title
                    $newTitle = trim($matches[1]);
                }
                
                // Clean up trailing words and punctuation
                $newTitle = preg_replace('/\s+(please|now|\.|!|\?)$/i', '', $newTitle);
                $newTitle = trim($newTitle);
                
                Log::info('Detected content title update pattern', [
                    'pattern' => $pattern,
                    'content_identifier' => $contentIdentifier ?? 'first',
                    'new_title' => $newTitle,
                    'matches' => $matches,
                ]);
                break;
            }
        }
        
        // Find the content to edit - Support "first", "second", "last", etc.
        if (!$contentId) {
            // Check for ordinal positions (first, second, third, last, etc.)
            $ordinalPattern = '/\b(?:first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|last|1st|2nd|3rd|4th|5th)\s+(?:one|content|item|prayer|devotional|scripture)\b/i';
            if (preg_match($ordinalPattern, $message, $ordinalMatch)) {
                $ordinal = strtolower(trim($ordinalMatch[1] ?? ''));
                $orderByDesc = true;
                $skipCount = 0;
                
                // Map ordinals to skip count
                $ordinalMap = [
                    'first' => 0, '1st' => 0,
                    'second' => 1, '2nd' => 1,
                    'third' => 2, '3rd' => 2,
                    'fourth' => 3, '4th' => 3,
                    'fifth' => 4, '5th' => 4,
                    'sixth' => 5,
                    'seventh' => 6,
                    'eighth' => 7,
                    'ninth' => 8,
                    'tenth' => 9,
                ];
                
                if ($ordinal === 'last') {
                    $orderByDesc = false; // Order ascending to get last
                    $skipCount = 0;
                } elseif (isset($ordinalMap[$ordinal])) {
                    $skipCount = $ordinalMap[$ordinal];
                }
                
                $query = ContentItem::forOrganization($organizationId)
                    ->approved();
                
                if ($orderByDesc) {
                    $query->orderBy('created_at', 'desc');
                } else {
                    $query->orderBy('created_at', 'asc');
                }
                
                $contentItem = $query->skip($skipCount)->first();
                
                if ($contentItem) {
                    $contentId = $contentItem->id;
                    Log::info('Found content item by ordinal', [
                        'content_id' => $contentId,
                        'ordinal' => $ordinal,
                        'skip_count' => $skipCount,
                        'current_title' => $contentItem->title,
                    ]);
                }
            } elseif (preg_match('/\b(?:first|1st)\s+(?:one|content|item)\b/i', $message)) {
                // Legacy support for "first one"
                $contentItem = ContentItem::forOrganization($organizationId)
                    ->approved()
                    ->orderBy('created_at', 'desc')
                    ->first();
                
                if ($contentItem) {
                    $contentId = $contentItem->id;
                    Log::info('Found first content item for edit', [
                        'content_id' => $contentId,
                        'current_title' => $contentItem->title,
                    ]);
                }
            } elseif (!empty($contentIdentifier)) {
                // Try to find by title
                $contentItem = ContentItem::forOrganization($organizationId)
                    ->approved()
                    ->where('title', 'LIKE', '%' . $contentIdentifier . '%')
                    ->first();
                
                if ($contentItem) {
                    $contentId = $contentItem->id;
                    Log::info('Found content by title for edit', [
                        'content_id' => $contentId,
                        'searched_title' => $contentIdentifier,
                    ]);
                }
            } elseif ($extractedData['success'] && !empty($extractedData['data']['title'])) {
                // Try using extracted title
                $searchTitle = $extractedData['data']['title'];
                $contentItem = ContentItem::forOrganization($organizationId)
                    ->approved()
                    ->where('title', 'LIKE', '%' . $searchTitle . '%')
                    ->first();
                
                if ($contentItem) {
                    $contentId = $contentItem->id;
                }
            }
            
            if (!$contentId) {
                // Check if this is a generic request (no specific content mentioned)
                $isGenericRequest = $this->isGenericRequest($message, ['content', 'prayer', 'devotional', 'scripture']);
                
                if ($isGenericRequest) {
                    // Show list of content with details
                    $availableContent = ContentItem::forOrganization($organizationId)
                        ->approved()
                        ->orderBy('created_at', 'desc')
                        ->get();
                    
                    if ($availableContent->isEmpty()) {
                        return [
                            'success' => false,
                            'message' => 'No content found. Please create some content first.',
                        ];
                    }
                    
                    $contentList = "Here are your content items:\n\n";
                    foreach ($availableContent as $index => $content) {
                        $typeEmoji = match($content->type) {
                            'prayer' => '🙏',
                            'devotional' => '📖',
                            'scripture' => '✝️',
                            default => '📝'
                        };
                        $createdDate = Carbon::parse($content->created_at)->format('M d, Y');
                        $bodyPreview = Str::limit(strip_tags($content->body ?? ''), 80);
                        $scriptureRef = $content->meta['scripture_ref'] ?? null;
                        $tags = !empty($content->meta['tags']) && is_array($content->meta['tags']) 
                            ? implode(', ', $content->meta['tags']) 
                            : null;
                        
                        $contentList .= sprintf(
                            "%d. %s %s\n   • Type: %s\n   • Created: %s",
                            $index + 1,
                            $typeEmoji,
                            $content->title,
                            ucfirst($content->type),
                            $createdDate
                        );
                        
                        if ($scriptureRef) {
                            $contentList .= "\n   • Scripture: {$scriptureRef}";
                        }
                        
                        if ($tags) {
                            $contentList .= "\n   • Tags: {$tags}";
                        }
                        
                        if ($bodyPreview) {
                            $contentList .= "\n   • Preview: {$bodyPreview}";
                        }
                        
                        $contentList .= "\n\n";
                    }
                    
                    $contentList .= "Which content would you like to edit? You can:\n";
                    $contentList .= "• Say the content title (e.g., \"Edit {$availableContent->first()->title}\")\n";
                    $contentList .= "• Say the number (e.g., \"Edit content 1\")\n";
                    $contentList .= "• Say \"first\", \"second\", \"last\", etc.";
                    
                    return [
                        'success' => true,
                        'response' => $contentList,
                    ];
                }
                
                // Try harder to find content - match any words from message against content titles
                $availableContent = ContentItem::forOrganization($organizationId)
                    ->approved()
                    ->orderBy('created_at', 'desc')
                    ->get(['id', 'title']);
                
                if ($availableContent->isEmpty()) {
                    return [
                        'success' => false,
                        'message' => 'No content found. Please create some content first.',
                    ];
                }

                // Try to match content titles with words from the message
                $messageWords = preg_split('/\s+/', $message);
                $messageWords = array_map('strtolower', $messageWords);
                $messageWords = array_filter($messageWords, function($word) {
                    return strlen($word) > 2 && !in_array(strtolower($word), ['the', 'and', 'or', 'to', 'for', 'edit', 'update', 'change', 'content', 'title', 'name', 'first', 'second', 'third', 'last']);
                });

                foreach ($availableContent as $content) {
                    $contentTitleLower = strtolower($content->title);
                    $contentTitleWords = preg_split('/\s+/', $contentTitleLower);
                    
                    // Check if any significant words from message match content title
                    foreach ($messageWords as $word) {
                        if (in_array($word, $contentTitleWords) || str_contains($contentTitleLower, $word)) {
                            $contentId = $content->id;
                            Log::info('Found content by word matching', [
                                'content_id' => $contentId,
                                'matched_word' => $word,
                                'content_title' => $content->title,
                            ]);
                            break 2;
                        }
                    }
                    
                    // Also check if content title contains significant words from message
                    foreach ($messageWords as $word) {
                        if (strlen($word) > 3 && str_contains($contentTitleLower, $word)) {
                            $contentId = $content->id;
                            Log::info('Found content by reverse word matching', [
                                'content_id' => $contentId,
                                'matched_word' => $word,
                                'content_title' => $content->title,
                            ]);
                            break 2;
                        }
                    }
                }

                // If still not found, try partial string matching with capitalized words
                if (!$contentId) {
                    foreach ($availableContent as $content) {
                        // Extract potential content title from message (capitalized words)
                        if (preg_match('/\b([A-Z][A-Za-z0-9\s]{3,})\b/', $message, $matches)) {
                            $potentialTitle = trim($matches[1]);
                            if (stripos($content->title, $potentialTitle) !== false || stripos($potentialTitle, $content->title) !== false) {
                                $contentId = $content->id;
                                Log::info('Found content by capitalized word matching', [
                                    'content_id' => $contentId,
                                    'potential_title' => $potentialTitle,
                                    'content_title' => $content->title,
                                ]);
                                break;
                            }
                        }
                    }
                }

                // If still nothing found and only one content, use it
                if (!$contentId && $availableContent->count() === 1) {
                    $contentId = $availableContent->first()->id;
                    Log::info('Using only available content as fallback', [
                        'content_id' => $contentId,
                    ]);
                }

                // Only show error if we really can't figure it out
                if (!$contentId) {
                    return [
                        'success' => false,
                        'message' => 'Could not identify which content to edit. Please specify the content title or use "first", "second", or "last" content.',
                    ];
                }
            }
        }

        // Find the content item
        $contentItem = ContentItem::forOrganization($organizationId)
            ->where('id', $contentId)
            ->first();

        if (!$contentItem) {
            return [
                'success' => false,
                'message' => 'Content not found. Please check the content title or ID.',
            ];
        }

        // Update fields - Support individual field updates
        $updates = [];
        $meta = $contentItem->meta ?? [];
        $metaUpdated = false;
        
        // Pattern matching for individual field updates
        // Update title patterns
        if (preg_match('/\b(?:update|change|edit|set)\s+(?:the\s+)?(?:content\s+)?title\s+(?:to|as|is)\s+["\']?([^"\']+)["\']?/i', $message, $titleMatch)) {
            $newTitleValue = trim($titleMatch[1] ?? '');
            if (!empty($newTitleValue)) {
                $updates['title'] = trim($newTitleValue);
            }
        } elseif (!empty($newTitle)) {
            $updates['title'] = trim($newTitle);
        } elseif ($extractedData['success'] && !empty($extractedData['data']['title'])) {
            $updates['title'] = trim($extractedData['data']['title']);
        }
        
        // Update body patterns
        if (preg_match('/\b(?:update|change|edit|set)\s+(?:the\s+)?(?:content\s+)?(?:body|text|content)\s+(?:to|as|is)\s+["\']?([^"\']+)["\']?/i', $message, $bodyMatch)) {
            $newBodyValue = trim($bodyMatch[1] ?? '');
            if (!empty($newBodyValue) && strlen($newBodyValue) > 10) {
                $updates['body'] = trim($newBodyValue);
            }
        } elseif ($extractedData['success'] && !empty($extractedData['data']['body'])) {
            $updates['body'] = trim($extractedData['data']['body']);
        }
        
        // Update type patterns - "change type to devotional", "set type as prayer"
        if (preg_match('/\b(?:update|change|edit|set)\s+(?:the\s+)?(?:content\s+)?type\s+(?:to|as|is)\s+(\w+)/i', $message, $typeMatch)) {
            $newType = strtolower(trim($typeMatch[1] ?? ''));
            $validTypes = ['prayer', 'devotional', 'scripture'];
            if (in_array($newType, $validTypes)) {
                $updates['type'] = $newType;
            }
        } elseif ($extractedData['success'] && !empty($extractedData['data']['type'])) {
            $validTypes = ['prayer', 'devotional', 'scripture'];
            if (in_array(strtolower($extractedData['data']['type']), $validTypes)) {
                $updates['type'] = strtolower($extractedData['data']['type']);
            }
        }
        
        // Update scripture reference - "update scripture to John 3:16", "change scripture ref to..."
        if (preg_match('/\b(?:update|change|edit|set)\s+(?:the\s+)?(?:scripture|scripture\s+ref|verse)\s+(?:to|as|is)\s+["\']?([^"\']+)["\']?/i', $message, $scriptureMatch)) {
            $newScripture = trim($scriptureMatch[1] ?? '');
            if (!empty($newScripture)) {
                $meta['scripture_ref'] = trim($newScripture);
                $metaUpdated = true;
            }
        } elseif ($extractedData['success'] && !empty($extractedData['data']['scripture_ref'])) {
            $meta['scripture_ref'] = trim($extractedData['data']['scripture_ref']);
            $metaUpdated = true;
        }
        
        // Update tags - "update tags to peace, hope, faith" or "change tags to..."
        if (preg_match('/\b(?:update|change|edit|set)\s+(?:the\s+)?tags?\s+(?:to|as|is)\s+["\']?([^"\']+)["\']?/i', $message, $tagsMatch)) {
            $newTagsStr = trim($tagsMatch[1] ?? '');
            if (!empty($newTagsStr)) {
                $newTags = array_map('trim', explode(',', $newTagsStr));
                $newTags = array_filter($newTags, function($tag) {
                    return !empty($tag) && strlen($tag) > 0;
                });
                if (!empty($newTags)) {
                    $meta['tags'] = array_values($newTags);
                    $metaUpdated = true;
                }
            }
        } elseif ($extractedData['success'] && !empty($extractedData['data']['tags']) && is_array($extractedData['data']['tags'])) {
            $meta['tags'] = $extractedData['data']['tags'];
            $metaUpdated = true;
        }
        
        if ($metaUpdated) {
            $updates['meta'] = $meta;
        }

        if (empty($updates)) {
            return [
                'success' => false,
                'message' => 'Please specify what you want to update. For example: "Edit first content title to [new title]" or "Update content [title] body to [new body]".',
            ];
        }

        // Perform the update
        try {
            $contentItem->update($updates);
            
            Log::info('Content updated successfully via AI chat', [
                'content_id' => $contentItem->id,
                'user_id' => $user->id,
                'updates' => array_keys($updates),
            ]);

            $updateMessage = "✓ Content updated successfully.\n\n";
            
            if (!empty($updates)) {
                $updateMessage .= "Updated fields:\n";
                foreach ($updates as $field => $value) {
                    $displayField = ucwords(str_replace('_', ' ', $field));
                    if ($field === 'meta' && is_array($value)) {
                        foreach ($value as $metaKey => $metaValue) {
                            $metaDisplayKey = ucwords(str_replace('_', ' ', $metaKey));
                            if ($metaKey === 'tags' && is_array($metaValue)) {
                                $updateMessage .= "• {$metaDisplayKey}: " . implode(', ', $metaValue) . "\n";
                            } elseif (!empty($metaValue)) {
                                $updateMessage .= "• {$metaDisplayKey}: {$metaValue}\n";
                            }
                        }
                    } elseif ($field === 'title') {
                        $updateMessage .= "• {$displayField}: \"{$value}\"\n";
                    } elseif ($field === 'type') {
                        $updateMessage .= "• {$displayField}: " . ucfirst($value) . "\n";
                    } else {
                        $preview = is_string($value) ? Str::limit($value, 100) : $value;
                        $updateMessage .= "• {$displayField}: {$preview}\n";
                    }
                }
            }
            
            return [
                'success' => true,
                'message' => trim($updateMessage),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to update content', [
                'user_id' => $user->id,
                'content_id' => $contentId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Sorry, I encountered an error while updating the content: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Delete content from chat message
     */
    protected function deleteContentFromChat(string $message, User $user): array
    {
        $organizationId = $user->organization?->id;
        
        if (!$organizationId) {
            return [
                'success' => false,
                'message' => 'You must be part of an organization to delete content.',
            ];
        }

        // Extract content identifier from message
        $extractedData = $this->extractContentDataFromMessage($message, $user);
        
        $contentId = null;
        $contentTitle = null;

        if ($extractedData['success']) {
            $data = $extractedData['data'];
            $contentId = $data['content_id'] ?? null;
            $contentTitle = $data['title'] ?? null;
        }

        // If no content ID/title extracted, try to find it in the message by matching against user's content
        if (!$contentId && !$contentTitle) {
            // Get user's content items to help match
            $userContentItems = ContentItem::forOrganization($organizationId)
                ->approved()
                ->get(['id', 'title']);
            
            // Try to find content title in message
            foreach ($userContentItems as $content) {
                if (stripos($message, $content->title) !== false) {
                    $contentTitle = $content->title;
                    $contentId = $content->id;
                    Log::info('Found content in message by title matching', [
                        'content_id' => $contentId,
                        'content_title' => $contentTitle,
                    ]);
                    break;
                }
            }
        }

        // Try to find content by title if we have a title but no ID
        if (!$contentId && $contentTitle) {
            $content = ContentItem::forOrganization($organizationId)
                ->approved()
                ->where('title', 'LIKE', '%' . $contentTitle . '%')
                ->first();
            
            if ($content) {
                $contentId = $content->id;
                Log::info('Found content by title search', [
                    'content_id' => $contentId,
                    'searched_title' => $contentTitle,
                ]);
            }
        }

        // Try to extract numeric ID from message if still not found
        if (!$contentId) {
            if (preg_match('/\b(?:content|item)\s+(?:id|#)?\s*(\d+)\b/i', $message, $matches)) {
                $contentId = (int) $matches[1];
                Log::info('Found content ID in message', ['content_id' => $contentId]);
            }
        }

        if (!$contentId) {
            // Check if this is a generic request (no specific content mentioned)
            $isGenericRequest = $this->isGenericRequest($message, ['content', 'prayer', 'devotional', 'scripture']);
            
            if ($isGenericRequest) {
                // Show list of content with details
                $availableContent = ContentItem::forOrganization($organizationId)
                    ->approved()
                    ->orderBy('created_at', 'desc')
                    ->get();
                
                if ($availableContent->isEmpty()) {
                    return [
                        'success' => false,
                        'message' => 'No content found to delete.',
                    ];
                }
                
                $contentList = "Here are your content items:\n\n";
                foreach ($availableContent as $index => $content) {
                    $typeEmoji = match($content->type) {
                        'prayer' => '🙏',
                        'devotional' => '📖',
                        'scripture' => '✝️',
                        default => '📝'
                    };
                    $createdDate = Carbon::parse($content->created_at)->format('M d, Y');
                    $bodyPreview = Str::limit(strip_tags($content->body ?? ''), 80);
                    $scriptureRef = $content->meta['scripture_ref'] ?? null;
                    $tags = !empty($content->meta['tags']) && is_array($content->meta['tags']) 
                        ? implode(', ', $content->meta['tags']) 
                        : null;
                    
                    $contentList .= sprintf(
                        "%d. %s %s\n   • Type: %s\n   • Created: %s",
                        $index + 1,
                        $typeEmoji,
                        $content->title,
                        ucfirst($content->type),
                        $createdDate
                    );
                    
                    if ($scriptureRef) {
                        $contentList .= "\n   • Scripture: {$scriptureRef}";
                    }
                    
                    if ($tags) {
                        $contentList .= "\n   • Tags: {$tags}";
                    }
                    
                    if ($bodyPreview) {
                        $contentList .= "\n   • Preview: {$bodyPreview}";
                    }
                    
                    $contentList .= "\n\n";
                }
                
                $contentList .= "Which content would you like to delete? You can:\n";
                $contentList .= "• Say the content title (e.g., \"Delete {$availableContent->first()->title}\")\n";
                $contentList .= "• Say the number (e.g., \"Delete content 1\")\n";
                $contentList .= "• Say \"first\", \"second\", \"last\", etc.";
                
                return [
                    'success' => true,
                    'response' => $contentList,
                ];
            }
            
            // Try harder to find content - match any words from message against content titles
            $availableContent = ContentItem::forOrganization($organizationId)
                ->approved()
                ->orderBy('created_at', 'desc')
                ->get(['id', 'title']);
            
            if ($availableContent->isEmpty()) {
                return [
                    'success' => false,
                    'message' => 'No content found to delete.',
                ];
            }

            // Try to match content titles with words from the message
            $messageWords = preg_split('/\s+/', $message);
            $messageWords = array_map('strtolower', $messageWords);
            $messageWords = array_filter($messageWords, function($word) {
                return strlen($word) > 2 && !in_array(strtolower($word), ['the', 'and', 'or', 'to', 'for', 'delete', 'remove', 'content', 'title', 'name', 'first', 'second', 'third', 'last']);
            });

            foreach ($availableContent as $content) {
                $contentTitleLower = strtolower($content->title);
                $contentTitleWords = preg_split('/\s+/', $contentTitleLower);
                
                // Check if any significant words from message match content title
                foreach ($messageWords as $word) {
                    if (in_array($word, $contentTitleWords) || str_contains($contentTitleLower, $word)) {
                        $contentId = $content->id;
                        Log::info('Found content for deletion by word matching', [
                            'content_id' => $contentId,
                            'matched_word' => $word,
                            'content_title' => $content->title,
                        ]);
                        break 2;
                    }
                }
                
                // Also check if content title contains significant words from message
                foreach ($messageWords as $word) {
                    if (strlen($word) > 3 && str_contains($contentTitleLower, $word)) {
                        $contentId = $content->id;
                        Log::info('Found content for deletion by reverse word matching', [
                            'content_id' => $contentId,
                            'matched_word' => $word,
                            'content_title' => $content->title,
                        ]);
                        break 2;
                    }
                }
            }

            // If still not found, try partial string matching with capitalized words
            if (!$contentId) {
                foreach ($availableContent as $content) {
                    // Extract potential content title from message (capitalized words)
                    if (preg_match('/\b([A-Z][A-Za-z0-9\s]{3,})\b/', $message, $matches)) {
                        $potentialTitle = trim($matches[1]);
                        if (stripos($content->title, $potentialTitle) !== false || stripos($potentialTitle, $content->title) !== false) {
                            $contentId = $content->id;
                            Log::info('Found content for deletion by capitalized word matching', [
                                'content_id' => $contentId,
                                'potential_title' => $potentialTitle,
                                'content_title' => $content->title,
                            ]);
                            break;
                        }
                    }
                }
            }

            // If still nothing found and only one content, use it
            if (!$contentId && $availableContent->count() === 1) {
                $contentId = $availableContent->first()->id;
                Log::info('Using only available content for deletion as fallback', [
                    'content_id' => $contentId,
                ]);
            }

            // Only show error if we really can't figure it out
            if (!$contentId) {
                return [
                    'success' => false,
                    'message' => 'Could not identify which content to delete. Please specify the content title.',
                ];
            }
        }

        // Find the content item
        $contentItem = ContentItem::forOrganization($organizationId)
            ->where('id', $contentId)
            ->first();

        if (!$contentItem) {
            return [
                'success' => false,
                'message' => 'Content not found. Please check the content title or ID.',
            ];
        }

        $contentTitleForMessage = $contentItem->title;

        // Delete associated image if exists
        try {
            if (isset($contentItem->meta['image_url']) && !empty($contentItem->meta['image_url'])) {
                $oldPath = str_replace('/storage/', '', $contentItem->meta['image_url']);
                \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to delete content image', [
                'content_id' => $contentItem->id,
                'error' => $e->getMessage(),
            ]);
            // Continue with deletion even if image deletion fails
        }

        // Delete the content item
        try {
            $contentItem->delete();
            
            Log::info('Content deleted successfully via AI chat', [
                'content_id' => $contentId,
                'user_id' => $user->id,
                'content_title' => $contentTitleForMessage,
            ]);

            return [
                'success' => true,
                'message' => "✓ Content '{$contentTitleForMessage}' has been deleted successfully.",
            ];
        } catch (\Exception $e) {
            Log::error('Failed to delete content', [
                'content_id' => $contentId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Sorry, I encountered an error while deleting the content: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Delete all content from chat message
     */
    protected function deleteAllContentFromChat(string $message, User $user): array
    {
        $organizationId = $user->organization?->id;
        
        if (!$organizationId) {
            return [
                'success' => false,
                'message' => 'You must be part of an organization to delete content.',
            ];
        }

        // Double check user really wants to delete ALL content
        $messageLower = strtolower($message);
        $confirmPatterns = [
            '/\b(?:delete|remove|clear|erase)\s+(?:all|every)\s+(?:content|items|prayers|devotionals|scriptures)/i',
            '/\b(?:delete|remove|clear|erase)\s+(?:all|every)\s+(?:my\s+)?(?:content|items)/i',
            '/\b(?:yes|confirm|proceed|go\s+ahead)\s+(?:delete|remove|clear)\s+(?:all|everything)/i',
        ];
        
        $hasConfirmPattern = false;
        foreach ($confirmPatterns as $pattern) {
            if (preg_match($pattern, $messageLower)) {
                $hasConfirmPattern = true;
                break;
            }
        }

        if (!$hasConfirmPattern) {
            $contentCount = ContentItem::forOrganization($organizationId)->approved()->count();
            return [
                'success' => false,
                'message' => "⚠️ WARNING: This will delete ALL {$contentCount} content items permanently!\n\nTo confirm, say: \"Delete all content\" or \"Yes, delete all my content\"",
            ];
        }

        // Get all content items for the organization
        $contentItems = ContentItem::forOrganization($organizationId)->approved()->get();
        
        if ($contentItems->isEmpty()) {
            return [
                'success' => false,
                'message' => 'No content found to delete.',
            ];
        }

        $deletedCount = 0;
        $errors = [];

        foreach ($contentItems as $contentItem) {
            try {
                // Delete associated image if exists
                if (isset($contentItem->meta['image_url']) && !empty($contentItem->meta['image_url'])) {
                    try {
                        $oldPath = str_replace('/storage/', '', $contentItem->meta['image_url']);
                        \Illuminate\Support\Facades\Storage::disk('public')->delete($oldPath);
                    } catch (\Exception $e) {
                        Log::warning('Failed to delete content image', [
                            'content_id' => $contentItem->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                $contentItem->delete();
                $deletedCount++;
            } catch (\Exception $e) {
                $errors[] = "Failed to delete '{$contentItem->title}': " . $e->getMessage();
                Log::error('Failed to delete content item', [
                    'content_id' => $contentItem->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Bulk content deletion completed', [
            'user_id' => $user->id,
            'total_items' => $contentItems->count(),
            'deleted_count' => $deletedCount,
            'error_count' => count($errors),
        ]);

        if ($deletedCount === 0) {
            return [
                'success' => false,
                'message' => 'Failed to delete any content items. ' . (empty($errors) ? 'No content found.' : implode(' ', $errors)),
            ];
        }

        $message = "✓ Deleted {$deletedCount} content item(s) successfully.";
        if (!empty($errors)) {
            $message .= "\n\n⚠️ Errors encountered:\n" . implode("\n", array_slice($errors, 0, 5));
            if (count($errors) > 5) {
                $message .= "\n... and " . (count($errors) - 5) . " more error(s)";
            }
        }

        return [
            'success' => true,
            'message' => $message,
        ];
    }

    /**
     * Check if message is a generic request without specific item mentioned
     */
    protected function isGenericRequest(string $message, array $itemTypes): bool
    {
        $messageLower = strtolower(trim($message));
        
        // Check if message contains generic phrases
        $genericPatterns = [
            '/\b(?:can\s+you|please|will\s+you)\s+(?:delete|edit|update|remove)\s+(?:this|that|a\s+)?(?:campaign|content|item)?\??$/i',
            '/\b(?:delete|edit|update|remove)\s+(?:a\s+)?(?:campaign|content|item)\??$/i',
            '/\b(?:delete|edit|update|remove)\s+(?:this|that)\??$/i',
        ];
        
        foreach ($genericPatterns as $pattern) {
            if (preg_match($pattern, $messageLower)) {
                return true;
            }
        }
        
        // Check if message mentions item type but no specific identifier
        foreach ($itemTypes as $type) {
            if (stripos($messageLower, $type) !== false) {
                // Check if there's a specific identifier (name, number, "first", etc.)
                $hasSpecificIdentifier = preg_match('/\b(?:first|second|third|last|1st|2nd|3rd|\d+|"[^"]+"|\'[^\']+\'|[A-Z][A-Za-z\s]{3,})\b/', $message);
                
                if (!$hasSpecificIdentifier) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Generate a campaign title using AI
     */
    protected function generateCampaignTitle(string $message, User $user): string
    {
        try {
            // Extract context from the message
            $context = '';
            if (preg_match('/(?:about|for|regarding)\s+(.+?)(?:\s+campaign|$)/i', $message, $matches)) {
                $context = trim($matches[1]);
            }
            
            // Extract dates/time if mentioned
            $dateContext = '';
            if (preg_match('/(?:start|from|beginning)\s+(.+?)(?:\s|$)/i', $message, $matches)) {
                $dateContext = ' starting ' . trim($matches[1]);
            }
            
            $systemPrompt = <<<PROMPT
You are a professional campaign naming specialist. Generate ONE clear, professional campaign title.

The title should be:
- 3-8 words
- Professional and clear
- Appropriate for a spiritual/religious platform
- Reflect the campaign purpose or theme
- No quotes, no markdown, just the title text

Return ONLY the title, nothing else.
PROMPT;

            $userPrompt = !empty($context) 
                ? "Generate a professional campaign title for: {$context}{$dateContext}"
                : "Generate a professional campaign title for a spiritual engagement campaign.{$dateContext}";

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ];

            $result = $this->openAiService->chatCompletion($messages);
            $title = trim(is_array($result) ? ($result['content'] ?? '') : $result);
            $totalTokens = (int) ($result['total_tokens'] ?? 0);
            if ($totalTokens > 0) {
                $user->consumeAiTokens($totalTokens);
            }
            
            // Clean up any quotes or extra text
            $title = trim($title, ' "\'');
            
            // If title is empty or too short, generate a generic one
            if (empty($title) || strlen($title) < 5) {
                $title = "Daily Prayer Campaign - " . date('F Y');
            }
            
            return $title;
            
        } catch (\Exception $e) {
            Log::error('Failed to generate campaign title', [
                'error' => $e->getMessage(),
            ]);
            // Fallback to default title
            return "Daily Prayer Campaign - " . date('F Y');
        }
    }

    /**
     * Extract campaign data from natural language using OpenAI
     */
    protected function extractCampaignDataFromMessage(string $message, User $user, string $operation, bool $wantsGeneratedTitle = false, ?string $conversationHistory = null): array
    {
        try {
            // Get available content items and users for context
            $organizationId = $user->organization?->id;
            
            // If no organization, try board membership
            if (!$organizationId) {
                $boardMembership = \App\Models\BoardMember::where('user_id', $user->id)->first();
                if ($boardMembership) {
                    $organizationId = $boardMembership->organization_id;
                }
            }
            
            $contentItems = $organizationId ? ContentItem::forOrganization($organizationId)->approved()->limit(50)->get(['id', 'title']) : collect();
            $users = User::where('login_status', true)->limit(50)->get(['id', 'name', 'email']);
            
            // Get user's campaigns for delete/edit operations
            $campaigns = $organizationId ? Campaign::forOrganization($organizationId)->get(['id', 'name', 'status']) : collect();

            $contentItemsList = $contentItems->map(fn($item) => "ID: {$item->id}, Title: {$item->title}")->join("\n");
            $usersList = $users->map(fn($u) => "ID: {$u->id}, Name: {$u->name}, Email: {$u->email}")->join("\n");
            $campaignsList = $campaigns->map(fn($c) => "ID: {$c->id}, Name: {$c->name}, Status: {$c->status}")->join("\n");
            
            $today = Carbon::today();
            $tomorrow = Carbon::tomorrow();
            $nextWeek = Carbon::today()->addWeek();
            $nextMonth = Carbon::today()->addMonth();

            // Build conversation history context if available
            $historyContext = '';
            if ($conversationHistory) {
                $historyContext = <<<HISTORY

PREVIOUS CONVERSATION CONTEXT (use this to extract "same details" or "again" references):
{$conversationHistory}

IMPORTANT: If the user says "create again", "create with same details", "create with previous details", "create again with same details", or similar phrases:
1. Look in the PREVIOUS CONVERSATION CONTEXT above for campaign details that were mentioned or displayed
2. Search for patterns like:
   - "Campaign Details:", "Name:", "Start Date:", "End Date:", "Send Time:", "Delivery Channels:", "Selected Users"
   - Any JSON-like structures with campaign information
   - Dates mentioned in formats like "December 4, 2025" or "2025-12-04"
   - Times like "7:00 AM" or "07:00"
   - Channel names like "Web", "WhatsApp", "Push"
3. Extract ALL campaign details (name, start_date, end_date, send_time_local, channels) from the previous conversation
4. Convert dates to YYYY-MM-DD format (e.g., "December 4, 2025" → "2025-12-04")
5. Convert times to HH:MM format (e.g., "7:00 AM" → "07:00", "2:30 PM" → "14:30")
6. Use those extracted details in your JSON response
7. If the user says "create again" but no previous campaign details exist in context, return nulls for required fields

HISTORY;
            }

            $systemPrompt = <<<PROMPT
You are a professional AI assistant specialized in extracting structured campaign data from natural language messages with high accuracy and precision.

═══════════════════════════════════════════════════════════════════════════════
CURRENT DATE & TIME CONTEXT (CRITICAL for relative date calculations):
═══════════════════════════════════════════════════════════════════════════════
- Today: {$today->format('Y-m-d')} ({$today->format('l, F j, Y')})
- Tomorrow: {$tomorrow->format('Y-m-d')} ({$tomorrow->format('l, F j, Y')})
- Next Week: {$nextWeek->format('Y-m-d')} ({$nextWeek->format('l, F j, Y')})
- Next Month: {$nextMonth->format('Y-m-d')} ({$nextMonth->format('l, F j, Y')})
- Current Year: {$today->format('Y')}
- Current Month: {$today->format('F')}
- Day of Week: {$today->format('l')}
{$historyContext}

═══════════════════════════════════════════════════════════════════════════════
REQUIRED JSON OUTPUT STRUCTURE:
═══════════════════════════════════════════════════════════════════════════════
Return ONLY a valid JSON object with this EXACT structure (no markdown, no code blocks):
{
  "campaign_id": null or number (only for edit/delete operations),
  "name": "campaign name string or null",
  "start_date": "YYYY-MM-DD format string or null (e.g., '2025-12-04')",
  "end_date": "YYYY-MM-DD format string or null (e.g., '2025-12-10')",
  "send_time_local": "HH:MM format string or null (e.g., '07:00', '14:30')",
  "channels": ["web", "whatsapp", "push", "email"] or null (array of lowercase strings),
  "status": "active" or "paused" or "cancelled" or null,
  "content_items": [1, 2, 3] or null (array of numeric IDs),
  "user_ids": [5, 10, 15] or null (array of numeric IDs)
}

═══════════════════════════════════════════════════════════════════════════════
CRITICAL EXTRACTION RULES FOR CREATE OPERATION:
═══════════════════════════════════════════════════════════════════════════════

1. EXTRACTION PRINCIPLE:
   - Extract EVERY piece of information provided by the user
   - If user provides partial information, extract what is available
   - If user says "create a campaign" without details, return nulls for missing fields
   - NEVER make assumptions beyond what is explicitly stated, EXCEPT when user gives permission for defaults

2. PERMISSION-BASED DEFAULTS:
   - If user says ANY of these phrases, extract available info but return nulls for missing fields (system will apply smart defaults):
     * "with your own data" / "with your data" / "use your own data"
     * "you choose" / "you decide" / "you can choose" / "you can decide"
     * "use defaults" / "apply defaults" / "use default values"
     * "go ahead" / "proceed" / "create it" / "make it"
     * "leave it to you" / "your choice"
     * "you can make" / "you can create" / "you can use"

3. CAMPAIGN NAME EXTRACTION PATTERNS:
   Search for and extract names using these patterns:
   - After keywords: "named", "called", "titled", "with name", "titled as"
   - Direct mentions: "Daily Prayer campaign" → extract "Daily Prayer"
   - Quoted text: "Create 'Morning Devotion' campaign" → extract "Morning Devotion"
   - After "for": "campaign for Morning Prayer" → extract "Morning Prayer"
   - Examples:
     * "Create Daily Prayer campaign" → name: "Daily Prayer"
     * "Make a campaign named Evening Reflection" → name: "Evening Reflection"
     * "Campaign called 'Weekly Devotion'" → name: "Weekly Devotion"

4. START DATE EXTRACTION PATTERNS:
   Look for these keywords and extract dates that follow:
   - "starting", "starts", "begin", "begins", "from", "start on", "beginning", "commence"
   - "on [date]", "from [date]", "beginning [date]", "effective [date]"
   - Relative dates: "tomorrow", "next week", "next Monday", "this Friday"
   - Date calculations:
     * "tomorrow" = {$tomorrow->format('Y-m-d')}
     * "next week" = {$nextWeek->format('Y-m-d')}
     * "next month" = {$nextMonth->format('Y-m-d')}
     * "in a week" = {$nextWeek->format('Y-m-d')}
     * "in a month" = {$nextMonth->format('Y-m-d')}
     * "next Monday" = calculate next Monday from today
     * "this Friday" = calculate this week's Friday
   - Partial dates: "January 1st" → if before today this year, use next year; otherwise use this year
   - Full dates: "December 4, 2025" → "2025-12-04"
   - Examples:
     * "starting tomorrow" → start_date: "{$tomorrow->format('Y-m-d')}"
     * "begin January 1st" → start_date: calculate based on current date
     * "from next Monday" → start_date: next Monday's date

5. END DATE EXTRACTION PATTERNS:
   Look for these keywords and calculate end dates:
   - "ending", "ends", "until", "to [date]", "through [date]", "till", "finish", "conclude"
   - Duration phrases (calculate from start_date):
     * "for a week" / "for 7 days" → add 6 days to start_date (7 days total)
     * "for a month" / "for 30 days" → add 29 days to start_date (30 days total)
     * "for 2 weeks" / "for 14 days" → add 13 days to start_date
     * "for X days" → add (X-1) days to start_date
     * "weekly for 4 weeks" → add 27 days to start_date
   - Specific end dates: "until January 31st", "ending on Dec 10"
   - Examples:
     * "for a week starting tomorrow" → start: tomorrow, end: tomorrow + 6 days
     * "ending December 10, 2025" → end_date: "2025-12-10"
     * "campaign for 30 days" → calculate end from start

6. TIME EXTRACTION PATTERNS:
   Search for time mentions and convert to HH:MM format (24-hour):
   - Keywords: "at", "sending at", "daily at", "time", "schedule", "send time"
   - AM/PM formats: "7 AM" → "07:00", "2:30 PM" → "14:30", "9:15 AM" → "09:15"
   - 24-hour format: "14:00" → "14:00", "08:30" → "08:30"
   - Written formats: "seven AM" → "07:00", "two thirty PM" → "14:30"
   - Time ranges: "between 7 and 8 AM" → use start time "07:00"
   - Examples:
     * "at 8 AM" → send_time_local: "08:00"
     * "sending at 7:30 in the morning" → send_time_local: "07:30"
     * "daily at 2 PM" → send_time_local: "14:00"
     * "time is 9:15 AM" → send_time_local: "09:15"

7. CHANNELS EXTRACTION PATTERNS:
   Identify delivery channels (must be lowercase: "web", "whatsapp", "push", "email"):
   - Keywords: "using", "via", "through", "with", "on", "channel"
   - Patterns: "using web", "via whatsapp", "with push notifications", "web and whatsapp", "via email"
   - Multiple channels: "web and whatsapp" → ["web", "whatsapp"]
   - Channel synonyms:
     * "web" / "web notifications" / "browser" → "web"
     * "whatsapp" / "whats app" / "wa" → "whatsapp"
     * "push" / "push notifications" / "mobile push" → "push"
     * "email" / "gmail" / "mail" / "e-mail" → "email"
   - Examples:
     * "using web notifications" → channels: ["web"]
     * "via whatsapp and web" → channels: ["whatsapp", "web"]
     * "with push and whatsapp" → channels: ["push", "whatsapp"]
     * "web and email" → channels: ["web", "email"]

8. CONTENT ITEMS & USER IDs EXTRACTION:
   - Match content item titles/names from AVAILABLE CONTENT ITEMS list below
   - Match user names/emails from AVAILABLE USERS list below
   - Return numeric IDs only, not names
   - If user says "all" or "all content" or "all users", return empty array [] (system will use all available)
   - Partial matches: "Daily Prayer" content → find matching IDs from list
   - Multiple selection: "content 1, 2, and 3" → [1, 2, 3]

═══════════════════════════════════════════════════════════════════════════════
ADVANCED EXTRACTION SCENARIOS & EDGE CASES:
═══════════════════════════════════════════════════════════════════════════════

9. COMPLEX DATE SCENARIOS:
   - "Starting Monday for 2 weeks" → start: next Monday, end: start + 13 days
   - "From December 1st to 15th" → start: "2025-12-01", end: "2025-12-15"
   - "Next month for 30 days" → start: next month's first day, end: start + 29 days
   - "This coming Friday until the following Friday" → calculate both Fridays
   - "Beginning of next week" → start: next Monday
   - "End of this month" → start: today (if given), end: last day of current month
   - "First day of next month" → start: first day of next month

10. TIME CONVERSION REFERENCE:
    AM/PM to 24-hour conversion examples:
    - "12:00 AM" → "00:00" (midnight)
    - "1:00 AM" → "01:00"
    - "6:30 AM" → "06:30"
    - "12:00 PM" → "12:00" (noon)
    - "1:00 PM" → "13:00"
    - "11:59 PM" → "23:59"
    - "7 in the morning" → "07:00"
    - "8 at night" → "20:00" (assume PM)
    - "noon" → "12:00"
    - "midnight" → "00:00"

11. CHANNEL COMBINATION RULES:
    - "web and whatsapp" → ["web", "whatsapp"]
    - "all channels" / "all available channels" → ["web", "whatsapp", "push", "email"]
    - "notifications" (without specifying) → ["web"]
    - "both web and push" → ["web", "push"]
    - "whatsapp or web" → ["whatsapp", "web"] (interpret "or" as "and")
    - "web and email" / "email and web notifications" → ["web", "email"]

12. PARTIAL INFORMATION HANDLING:
    - If user provides ONLY name → extract name, return nulls for dates/time/channels
    - If user provides ONLY dates → extract dates, return nulls for name/time/channels
    - If user provides name + start date → extract both, return nulls for end_date/time/channels
    - ALWAYS extract what is provided, NEVER invent missing information unless user gives permission

═══════════════════════════════════════════════════════════════════════════════
EDIT & DELETE OPERATION RULES:
═══════════════════════════════════════════════════════════════════════════════

FOR EDIT OPERATION:
- Extract campaign_id (numeric) OR name (string) to identify the campaign
- Extract ONLY the fields that need to be updated (name, start_date, end_date, send_time_local, channels, status)
- Return null for fields that are NOT being changed
- SPECIAL: When user says "edit X title to Y" or "change X name to Y" → extract campaign name/ID as identifier AND new name in the "name" field
- Examples:
  * "Edit campaign 1 to start tomorrow" → campaign_id: 1, start_date: tomorrow, others: null
  * "Change 'Daily Prayer' campaign to send at 8 AM" → name: "Daily Prayer", send_time_local: "08:00", others: null
  * "Edit Test Campaign title to Owner Campaign" → name: "Test Campaign" (to find it), but also extract new_name field OR update the name field with "Owner Campaign"
  * "Update campaign name from X to Y" → extract both: identify campaign by "X", new name is "Y"
  
IMPORTANT FOR TITLE/NAME UPDATES:
- Pattern: "title to X", "name to X", "rename to X", "change title to X", "update name to X"
- Extract: campaign identifier (original name or ID) AND the new name
- Put the NEW name in the "name" field, not the old one

FOR DELETE OPERATION:
- Extract campaign_id (numeric) OR name (string) to identify the campaign
- Only campaign_id or name is required, all other fields can be null
- Match name exactly as user says it (case-insensitive, but preserve original casing in response)
- Examples:
  * "Delete campaign 5" → campaign_id: 5, others: null
  * "Remove 'Test Campaign'" → name: "Test Campaign", others: null
  * "Cancel the Morning Prayer campaign" → name: "Morning Prayer", others: null

═══════════════════════════════════════════════════════════════════════════════
OUTPUT FORMATTING REQUIREMENTS:
═══════════════════════════════════════════════════════════════════════════════

CRITICAL: Return ONLY valid JSON - NO MARKDOWN, NO CODE BLOCKS, NO EXPLANATIONS
- Do NOT wrap response in ```json or ``` code blocks
- Do NOT add any text before or after the JSON
- Do NOT include explanatory comments in the JSON
- Return ONLY the raw JSON object starting with { and ending with }

CORRECT OUTPUT FORMAT:
{"campaign_id":null,"name":"Daily Prayer","start_date":"2025-12-04","end_date":"2025-12-10","send_time_local":"07:00","channels":["web"],"status":null,"content_items":null,"user_ids":null}

INCORRECT FORMATS (DO NOT USE):
- ```json\n{...}\n``` (markdown code block)
- "Here's the campaign data: {...}" (explanatory text)
- { // campaign data\n...\n} (comments in JSON)
- {"result": {...}} (nested structure)

═══════════════════════════════════════════════════════════════════════════════
AVAILABLE RESOURCES (use for matching):
═══════════════════════════════════════════════════════════════════════════════

AVAILABLE CONTENT ITEMS:
{$contentItemsList}

AVAILABLE USERS:
{$usersList}

AVAILABLE CAMPAIGNS (for delete/edit operations):
{$campaignsList}

═══════════════════════════════════════════════════════════════════════════════
FINAL VALIDATION CHECKLIST:
═══════════════════════════════════════════════════════════════════════════════

Before returning your response, verify:
✓ Dates are in YYYY-MM-DD format (e.g., "2025-12-04")
✓ Time is in HH:MM format (e.g., "07:00", "14:30")
✓ Channels are lowercase array: ["web"], ["whatsapp"], or ["web", "whatsapp"]
✓ Content item IDs are numeric array: [1, 2, 3] or []
✓ User IDs are numeric array: [5, 10, 15] or []
✓ Campaign name is a string or null (not empty string "")
✓ All null values are lowercase: null (not NULL, None, or "null")
✓ Response is valid JSON that can be parsed by json_decode()
✓ No markdown, no code blocks, no extra text

═══════════════════════════════════════════════════════════════════════════════
Now extract the campaign data from the user's message and return ONLY the JSON object:
PROMPT;

            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $message],
            ];

            $result = $this->openAiService->chatCompletion($messages);
            $responseContent = $result['content'] ?? '';
            $totalTokens = (int) ($result['total_tokens'] ?? 0);
            if ($totalTokens > 0) {
                $user->consumeAiTokens($totalTokens);
            }
            
            // Parse JSON from response
            $cleanResponse = trim($responseContent);
            $cleanResponse = preg_replace('/^```json\s*/i', '', $cleanResponse);
            $cleanResponse = preg_replace('/\s*```$/i', '', $cleanResponse);
            $cleanResponse = preg_replace('/^```\s*/i', '', $cleanResponse);
            $cleanResponse = trim($cleanResponse);

            // Try to extract JSON object
            if (preg_match('/\{[\s\S]*\}/', $cleanResponse, $matches)) {
                $jsonString = $matches[0];
            } else {
                $jsonString = $cleanResponse;
            }

            $data = json_decode($jsonString, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to parse JSON: ' . json_last_error_msg());
            }

            // Parse natural language dates with better context
            if (!empty($data['start_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['start_date'])) {
                try {
                    // Try parsing with context
                    $parsed = Carbon::parse($data['start_date']);
                    
                    // If the parsed date is in the past, it might be next year
                    if ($parsed->isPast() && !$parsed->isToday()) {
                        // Check if it's a date without year (like "January 1st")
                        // If so, add a year
                        $parsed = Carbon::parse($data['start_date'])->addYear();
                    }
                    
                    $data['start_date'] = $parsed->format('Y-m-d');
                } catch (\Exception $e) {
                    Log::warning('Failed to parse start_date', [
                        'date_string' => $data['start_date'],
                        'error' => $e->getMessage(),
                    ]);
                    $data['start_date'] = null;
                }
            }

            if (!empty($data['end_date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['end_date'])) {
                try {
                    // Try parsing with context
                    $parsed = Carbon::parse($data['end_date']);
                    
                    // If the parsed date is in the past, it might be next year
                    if ($parsed->isPast()) {
                        $parsed = Carbon::parse($data['end_date'])->addYear();
                    }
                    
                    $data['end_date'] = $parsed->format('Y-m-d');
                } catch (\Exception $e) {
                    Log::warning('Failed to parse end_date', [
                        'date_string' => $data['end_date'],
                        'error' => $e->getMessage(),
                    ]);
                    $data['end_date'] = null;
                }
            }
            
            // Handle relative durations (e.g., "for a week", "for 30 days")
            // If start_date is set but end_date is not, and we detect a duration, calculate it
            if (!empty($data['start_date']) && empty($data['end_date'])) {
                // Check if message contains duration hints
                $messageLower = strtolower($message);
                if (preg_match('/for\s+(\d+)\s+days?/i', $messageLower, $matches)) {
                    $days = (int) $matches[1];
                    try {
                        $startDate = Carbon::parse($data['start_date']);
                        $endDate = $startDate->copy()->addDays($days - 1); // Subtract 1 because start day counts
                        $data['end_date'] = $endDate->format('Y-m-d');
                        Log::info('Calculated end_date from duration', [
                            'days' => $days,
                            'start_date' => $data['start_date'],
                            'end_date' => $data['end_date'],
                        ]);
                    } catch (\Exception $e) {
                        // Ignore
                    }
                } elseif (preg_match('/for\s+(?:a\s+)?week/i', $messageLower)) {
                    try {
                        $startDate = Carbon::parse($data['start_date']);
                        $endDate = $startDate->copy()->addDays(6);
                        $data['end_date'] = $endDate->format('Y-m-d');
                    } catch (\Exception $e) {
                        // Ignore
                    }
                } elseif (preg_match('/for\s+(?:a\s+)?month/i', $messageLower)) {
                    try {
                        $startDate = Carbon::parse($data['start_date']);
                        $endDate = $startDate->copy()->addMonth()->subDay(); // Approximate 30 days
                        $data['end_date'] = $endDate->format('Y-m-d');
                    } catch (\Exception $e) {
                        // Ignore
                    }
                }
            }

            return [
                'success' => true,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to extract campaign data', [
                'message' => $message,
                'operation' => $operation,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'I had trouble understanding your request. Please try rephrasing it. For example: "Create a campaign named Daily Prayer starting January 1st, ending January 31st, sending at 7 AM"',
            ];
        }
    }
}

