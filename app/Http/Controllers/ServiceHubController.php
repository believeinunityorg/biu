<?php

namespace App\Http\Controllers;

use App\Mail\OrderCompletedNotification;
use App\Mail\OrderPlacedNotification;
use App\Models\CustomOffer;
use App\Models\Gig;
use App\Models\GigFavorite;
use App\Models\GigImage;
use App\Models\GigPackage;
use App\Models\ServiceCategory;
use App\Models\ServiceChat;
use App\Models\ServiceChatMessage;
use App\Models\ServiceOrder;
use App\Models\ServiceReview;
use App\Models\ServiceSellerProfile;
use App\Models\Transaction;
use App\Services\ServiceHubFeeService;
use App\Services\StripeConfigService;
use App\Support\StripeCustomerChargeAmount;
use App\Support\UnifiedLedgerBpStatus;
use App\Support\UnifiedLedgerType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Refund;
use Stripe\Stripe;

class ServiceHubController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Gig::with(['category', 'user.serviceSellerProfile', 'primaryImage', 'images'])
            ->where('status', 'active')
            ->whereHas('user.serviceSellerProfile', function ($q) {
                $q->where('is_suspended', false);
            });

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%");
            });
        }

        // Category filter
        if ($request->has('category') && $request->category && $request->category !== 'all') {
            $query->whereHas('category', function ($q) use ($request) {
                $q->where('slug', $request->category);
            });
        }

        // Price range filter
        if ($request->has('price_min')) {
            $query->where('price', '>=', $request->price_min);
        }
        if ($request->has('price_max')) {
            $query->where('price', '<=', $request->price_max);
        }

        // Rating filter
        if ($request->has('min_rating')) {
            $query->where('rating', '>=', $request->min_rating);
        }

        // Delivery time filter
        if ($request->has('delivery_time')) {
            $query->where('delivery_time', $request->delivery_time);
        }

        // Sort
        $sortBy = $request->get('sort_by', 'best_selling');
        switch ($sortBy) {
            case 'newest':
                $query->orderBy('created_at', 'desc');
                break;
            case 'price_low':
                $query->orderBy('price', 'asc');
                break;
            case 'price_high':
                $query->orderBy('price', 'desc');
                break;
            case 'rating':
                $query->orderBy('rating', 'desc');
                break;
            default: // best_selling
                $query->orderBy('orders_count', 'desc');
                break;
        }

        $gigs = $query->paginate(12);

        // Transform gigs data for frontend
        $gigs->getCollection()->transform(function ($gig) {
            $primaryImage = $gig->images->where('is_primary', true)->first()
                ?? $gig->images->first();
            $imageUrl = $primaryImage ? Storage::url($primaryImage->image_path) : null;

            // Get seller profile image if available, otherwise use user image
            $sellerProfile = $gig->user->serviceSellerProfile;
            $sellerAvatar = null;
            if ($sellerProfile && $sellerProfile->profile_image) {
                $sellerAvatar = Storage::url($sellerProfile->profile_image);
            } elseif ($gig->user->image) {
                $sellerAvatar = Storage::url($gig->user->image);
            }

            return [
                'id' => $gig->id,
                'slug' => $gig->slug,
                'title' => $gig->title,
                'description' => $gig->description,
                'price' => (float) $gig->price,
                'deliveryTime' => $gig->delivery_time,
                'rating' => (float) $gig->rating,
                'reviews' => $gig->reviews_count,
                'image' => $imageUrl,
                'seller' => [
                    'id' => $gig->user->id,
                    'name' => $gig->user->name,
                    'avatar' => $sellerAvatar,
                ],
                'category' => $gig->category->name ?? '',
                'tags' => $gig->tags ?? [],
            ];
        });

        // Get categories for filter
        $categories = ServiceCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name', 'slug']);

        // Get user favorites if authenticated
        $favoriteIds = [];
        $totalUnread = 0;
        if (Auth::check()) {
            $favoriteIds = GigFavorite::where('user_id', Auth::id())
                ->pluck('gig_id')
                ->toArray();

            // Calculate total unread messages for service chats
            $chats = ServiceChat::where(function ($query) {
                $query->where('buyer_id', Auth::id())
                    ->orWhere('seller_id', Auth::id());
            })->get();

            $totalUnread = $chats->sum(function ($chat) {
                return $chat->unreadCountForUser(Auth::id());
            });
        }

        return Inertia::render('frontend/service-hub/index', [
            'gigs' => $gigs,
            'categories' => $categories,
            'favoriteIds' => $favoriteIds,
            'totalUnread' => $totalUnread,
            'filters' => [
                'search' => $request->get('search', ''),
                'category' => $request->get('category', 'all'),
                'price_min' => $request->get('price_min', 0),
                'price_max' => $request->get('price_max', 1000),
                'min_rating' => $request->get('min_rating', 0),
                'delivery_time' => $request->get('delivery_time'),
                'sort_by' => $sortBy,
            ],
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        $user = Auth::user();

        // Block admins from creating services
        if ($user->role === 'admin') {
            abort(403, 'Administrators cannot create services. Only organizations and regular users can sell services.');
        }

        // Check if user has a seller profile
        if (! $user->serviceSellerProfile) {
            return redirect()->route('service-hub.seller-profile.create')
                ->with('error', 'Please create a seller profile before creating a service.');
        }

        $categories = ServiceCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('frontend/service-hub/create', [
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = Auth::user();

        // Block admins from creating services
        if ($user->role === 'admin') {
            abort(403, 'Administrators cannot create services. Only organizations and regular users can sell services.');
        }

        // Check if user has a seller profile
        if (! $user->serviceSellerProfile) {
            return redirect()->route('service-hub.seller-profile.create')
                ->with('error', 'Please create a seller profile before creating a service.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:service_categories,id',
            'description' => 'required|string|max:500',
            'full_description' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'faqs' => 'nullable|array',
            'faqs.*.question' => 'required|string|max:255',
            'faqs.*.answer' => 'required|string|max:1000',
            'packages' => 'required|array|min:1',
            'packages.*.name' => 'required|string|max:100',
            'packages.*.price' => 'required|numeric|min:0',
            'packages.*.delivery_time' => 'required|string',
            'packages.*.description' => 'nullable|string',
            'packages.*.features' => 'nullable|array',
            'packages.*.features.*' => 'string|max:255',
            'images' => 'required|array|min:1|max:3',
            'images.*' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:3072',
            'accepts_believe_points' => 'nullable|boolean',
        ], [
            'images.required' => 'Please upload at least one image.',
            'images.max' => 'You can upload maximum 3 images.',
            'images.*.image' => 'File :position must be an image.',
            'images.*.mimes' => 'Image :position must be jpeg, png, jpg, gif or webp.',
            'images.*.max' => 'Each image may not be larger than 3MB.',
        ]);

        try {
            // Format FAQs
            $faqs = [];
            if (isset($validated['faqs']) && is_array($validated['faqs'])) {
                foreach ($validated['faqs'] as $faq) {
                    if (! empty($faq['question']) && ! empty($faq['answer'])) {
                        $faqs[] = [
                            'question' => $faq['question'],
                            'answer' => $faq['answer'],
                        ];
                    }
                }
            }

            $gig = Gig::create([
                'user_id' => Auth::id(),
                'category_id' => $validated['category_id'],
                'title' => $validated['title'],
                'description' => $validated['description'],
                'full_description' => $validated['full_description'] ?? null,
                'tags' => $validated['tags'] ?? [],
                'faqs' => $faqs,
                'price' => $validated['packages'][0]['price'] ?? 0,
                'delivery_time' => $validated['packages'][0]['delivery_time'] ?? '3 days',
                'accepts_believe_points' => $validated['accepts_believe_points'] ?? false,
                'status' => 'active',
            ]);
            // Create packages
            foreach ($validated['packages'] as $index => $packageData) {
                GigPackage::create([
                    'gig_id' => $gig->id,
                    'name' => $packageData['name'],
                    'price' => $packageData['price'],
                    'delivery_time' => $packageData['delivery_time'],
                    'description' => $packageData['description'] ?? null,
                    'features' => $packageData['features'] ?? [],
                    'is_popular' => $index === 1, // Mark second package as popular
                    'sort_order' => $index,
                ]);
            }

            // Upload images
            foreach ($request->file('images', []) as $index => $image) {
                $imagePath = $image->store('gigs/'.$gig->id, 'public');
                GigImage::create([
                    'gig_id' => $gig->id,
                    'image_path' => $imagePath,
                    'is_primary' => $index === 0,
                    'sort_order' => $index,
                ]);
            }

            return redirect()->route('service-hub.show', $gig->slug)
                ->with('success', 'Service created successfully!');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to create service: '.$e->getMessage()]);
        }
    }

    public function edit(Request $request, $slug): Response|RedirectResponse
    {
        $user = Auth::user();

        // Block admins from editing services
        if ($user->role === 'admin') {
            abort(403, 'Administrators cannot edit services. Only organizations and regular users can manage services.');
        }

        $gig = Gig::with(['category', 'packages', 'images'])
            ->where('slug', $slug)
            ->firstOrFail();

        // Check if user owns this service
        if ($gig->user_id !== Auth::id()) {
            abort(403, 'You do not have permission to edit this service.');
        }

        $categories = ServiceCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        // Format existing images
        $existingImages = $gig->images->map(function ($image) {
            return [
                'id' => $image->id,
                'url' => Storage::url($image->image_path),
                'path' => $image->image_path,
            ];
        })->toArray();

        // Format packages
        $packages = $gig->packages->map(function ($package) {
            return [
                'id' => $package->id,
                'name' => $package->name,
                'price' => (float) $package->price,
                'deliveryTime' => $package->delivery_time,
                'description' => $package->description ?? '',
                'features' => $package->features ?? [],
            ];
        })->toArray();

        return Inertia::render('frontend/service-hub/edit', [
            'gig' => [
                'id' => $gig->id,
                'slug' => $gig->slug,
                'title' => $gig->title,
                'category_id' => $gig->category_id,
                'description' => $gig->description,
                'fullDescription' => $gig->full_description ?? '',
                'tags' => $gig->tags ?? [],
                'faqs' => $gig->faqs ?? [],
                'accepts_believe_points' => $gig->accepts_believe_points ?? false,
                'images' => $existingImages,
                'packages' => $packages,
            ],
            'categories' => $categories,
        ]);
    }

    public function update(Request $request, $slug): RedirectResponse
    {
        $user = Auth::user();

        // Block admins from updating services
        if ($user->role === 'admin') {
            abort(403, 'Administrators cannot update services. Only organizations and regular users can manage services.');
        }

        $gig = Gig::where('slug', $slug)->firstOrFail();

        // Check if user owns this service
        if ($gig->user_id !== Auth::id()) {
            abort(403, 'You do not have permission to update this service.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category_id' => 'required|exists:service_categories,id',
            'description' => 'required|string|max:500',
            'full_description' => 'nullable|string',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'faqs' => 'nullable|array',
            'faqs.*.question' => 'required|string|max:255',
            'faqs.*.answer' => 'required|string|max:1000',
            'packages' => 'required|array|min:1',
            'packages.*.name' => 'required|string|max:100',
            'packages.*.price' => 'required|numeric|min:0',
            'packages.*.delivery_time' => 'required|string',
            'packages.*.description' => 'nullable|string',
            'packages.*.features' => 'nullable|array',
            'packages.*.features.*' => 'string|max:255',
            'images' => 'nullable|array|max:3',
            'images.*' => 'image|mimes:jpeg,png,jpg,gif,webp|max:3072', // 3MB
            'existing_images' => 'nullable|array',
            'existing_images.*' => 'nullable|string',
            'deleted_images' => 'nullable|array',
            'deleted_images.*' => 'nullable|integer',
            'accepts_believe_points' => 'nullable|boolean',
        ], [
            'images.required' => 'Please upload at least one image.',
            'images.max' => 'You can upload maximum 3 images.',
            'images.*.image' => 'File :position must be an image.',
            'images.*.mimes' => 'Image :position must be jpeg, png, jpg, gif or webp.',
            'images.*.max' => 'Each image may not be larger than 3MB.',
        ]);

        try {
            // Format FAQs
            $faqs = [];
            if (isset($validated['faqs']) && is_array($validated['faqs'])) {
                foreach ($validated['faqs'] as $faq) {
                    if (! empty($faq['question']) && ! empty($faq['answer'])) {
                        $faqs[] = [
                            'question' => $faq['question'],
                            'answer' => $faq['answer'],
                        ];
                    }
                }
            }

            // Update gig
            $gig->update([
                'category_id' => $validated['category_id'],
                'title' => $validated['title'],
                'description' => $validated['description'],
                'full_description' => $validated['full_description'] ?? null,
                'tags' => $validated['tags'] ?? [],
                'faqs' => $faqs,
                'price' => $validated['packages'][0]['price'] ?? 0,
                'delivery_time' => $validated['packages'][0]['delivery_time'] ?? '3 days',
                'accepts_believe_points' => $validated['accepts_believe_points'] ?? false,
            ]);

            // Handle deleted images
            if (isset($validated['deleted_images']) && is_array($validated['deleted_images'])) {
                foreach ($validated['deleted_images'] as $imageId) {
                    $image = GigImage::find($imageId);
                    if ($image && $image->gig_id === $gig->id) {
                        Storage::disk('public')->delete($image->image_path);
                        $image->delete();
                    }
                }
            }

            // Handle new images
            $existingImageCount = count($validated['existing_images'] ?? []);
            $newImageIndex = $existingImageCount;
            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $index => $image) {
                    $imagePath = $image->store('gigs/'.$gig->id, 'public');
                    GigImage::create([
                        'gig_id' => $gig->id,
                        'image_path' => $imagePath,
                        'is_primary' => $existingImageCount === 0 && $index === 0,
                        'sort_order' => $newImageIndex + $index,
                    ]);
                }
            }

            // Update packages
            $packageIds = [];
            foreach ($validated['packages'] as $index => $packageData) {
                if (isset($packageData['id'])) {
                    // Update existing package
                    $package = GigPackage::where('id', $packageData['id'])
                        ->where('gig_id', $gig->id)
                        ->first();
                    if ($package) {
                        $package->update([
                            'name' => $packageData['name'],
                            'price' => $packageData['price'],
                            'delivery_time' => $packageData['delivery_time'],
                            'description' => $packageData['description'] ?? null,
                            'features' => $packageData['features'] ?? [],
                            'is_popular' => $index === 1,
                            'sort_order' => $index,
                        ]);
                        $packageIds[] = $package->id;
                    }
                } else {
                    // Create new package
                    $package = GigPackage::create([
                        'gig_id' => $gig->id,
                        'name' => $packageData['name'],
                        'price' => $packageData['price'],
                        'delivery_time' => $packageData['delivery_time'],
                        'description' => $packageData['description'] ?? null,
                        'features' => $packageData['features'] ?? [],
                        'is_popular' => $index === 1,
                        'sort_order' => $index,
                    ]);
                    $packageIds[] = $package->id;
                }
            }

            // Delete packages that are no longer in the list
            GigPackage::where('gig_id', $gig->id)
                ->whereNotIn('id', $packageIds)
                ->delete();

            return redirect()->route('service-hub.show', $gig->slug)
                ->with('success', 'Service updated successfully!');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to update service: '.$e->getMessage()]);
        }
    }

    public function show(Request $request, $slug)
    {
        $gig = Gig::with([
            'category',
            'user.serviceSellerProfile',
            'packages' => function ($q) {
                $q->orderBy('sort_order');
            },
            'images' => function ($q) {
                $q->orderBy('sort_order');
            },
            'reviews.user' => function ($q) {
                $q->select('id', 'name', 'image');
            },
        ])->where('slug', $slug)->firstOrFail();

        // Check if seller is suspended
        if ($gig->user->serviceSellerProfile && $gig->user->serviceSellerProfile->is_suspended) {
            return redirect()->route('service-hub.index')
                ->with('error', 'This seller has been suspended. Their services are not available.');
        }

        if ($gig->status !== 'active') {
            abort(404);
        }

        // Get recent reviews (only buyer reviews)
        $recentReviews = ServiceReview::with('user:id,name,image')
            ->where('gig_id', $gig->id)
            ->where('reviewer_type', 'buyer')
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get()
            ->map(function ($review) {
                return [
                    'id' => $review->id,
                    'user' => [
                        'name' => $review->user->name,
                        'avatar' => $review->user->image ? Storage::url($review->user->image) : null,
                    ],
                    'rating' => $review->rating,
                    'comment' => $review->comment,
                    'date' => $review->created_at->diffForHumans(),
                ];
            });

        // Check if favorited
        $isFavorite = false;
        if (Auth::check()) {
            $isFavorite = GigFavorite::where('user_id', Auth::id())
                ->where('gig_id', $gig->id)
                ->exists();
        }

        // Format images
        $images = $gig->images->map(function ($image) {
            return Storage::url($image->image_path);
        })->toArray();

        // Format packages
        $packages = $gig->packages->map(function ($package) {
            return [
                'id' => $package->id,
                'name' => $package->name,
                'price' => (float) $package->price,
                'deliveryTime' => $package->delivery_time,
                'description' => $package->description,
                'features' => $package->features ?? [],
                'popular' => $package->is_popular,
            ];
        })->toArray();

        // Calculate buyer reviews count (only buyer reviews should be counted)
        $buyerReviewsCount = ServiceReview::where('gig_id', $gig->id)
            ->where('reviewer_type', 'buyer')
            ->count();

        // Format gig data
        $gigData = [
            'id' => $gig->id,
            'slug' => $gig->slug,
            'title' => $gig->title,
            'description' => $gig->description,
            'fullDescription' => $gig->full_description ?? '',
            'price' => (float) $gig->price,
            'deliveryTime' => $gig->delivery_time,
            'rating' => (float) $gig->rating,
            'reviews' => $buyerReviewsCount, // Use actual buyer reviews count
            'category' => $gig->category->name ?? '',
            'tags' => $gig->tags ?? [],
            'faqs' => $gig->faqs ?? [],
            'images' => $images,
            'packages' => $packages,
            'seller' => [
                'id' => $gig->user->id,
                'name' => $gig->user->name,
                'avatar' => $gig->user->serviceSellerProfile && $gig->user->serviceSellerProfile->profile_image
                    ? Storage::url($gig->user->serviceSellerProfile->profile_image)
                    : ($gig->user->image ? Storage::url($gig->user->image) : null),
                'phone' => $gig->user->serviceSellerProfile ? $gig->user->serviceSellerProfile->phone : null,
            ],
        ];

        // Check if current user is the owner
        $isOwner = Auth::check() && $gig->user_id === Auth::id();

        // Check if current user has a successful/paid order with this seller
        $hasSuccessfulOrder = false;
        if (Auth::check() && ! $isOwner) {
            $hasSuccessfulOrder = ServiceOrder::where('buyer_id', Auth::id())
                ->where('seller_id', $gig->user_id)
                ->where('payment_status', 'paid')
                ->exists();
        }

        // Get seller's gigs for offer creation
        $sellerGigs = [];
        if ($isOwner) {
            $sellerGigs = Gig::where('user_id', Auth::id())
                ->where('status', 'active')
                ->select('id', 'slug', 'title', 'description', 'price')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($g) {
                    $primaryImage = $g->images()->where('is_primary', true)->first()
                        ?? $g->images()->first();

                    return [
                        'id' => $g->id,
                        'slug' => $g->slug,
                        'title' => $g->title,
                        'description' => $g->description,
                        'price' => (float) $g->price,
                        'image' => $primaryImage ? Storage::url($primaryImage->image_path) : null,
                    ];
                })
                ->toArray();
        }

        return Inertia::render('frontend/service-hub/show', [
            'gig' => $gigData,
            'recentReviews' => $recentReviews,
            'isFavorite' => $isFavorite,
            'isOwner' => $isOwner,
            'sellerGigs' => $sellerGigs,
            'hasSuccessfulOrder' => $hasSuccessfulOrder,
        ]);
    }

    public function order(Request $request)
    {
        $user = Auth::user();

        // Block admins from purchasing services
        if ($user->role === 'admin') {
            abort(403, 'Administrators cannot purchase services. Admins can only view services.');
        }

        $gigId = $request->get('serviceId');
        $packageId = $request->get('packageId');

        if (! $gigId) {
            return redirect()->route('service-hub.index')
                ->with('error', 'Please select a service first.');
        }

        $gig = Gig::with(['packages', 'user.serviceSellerProfile', 'images'])
            ->where('status', 'active')
            ->findOrFail($gigId);

        // Check if seller is suspended
        if ($gig->user->serviceSellerProfile && $gig->user->serviceSellerProfile->is_suspended) {
            return redirect()->route('service-hub.index')
                ->with('error', 'This seller has been suspended. You cannot order from them.');
        }

        // Security check: Prevent users from purchasing their own services
        if ($user && $gig->user_id === $user->id) {
            return redirect()->route('service-hub.show', $gig->slug)
                ->with('error', 'You cannot purchase your own service.');
        }

        // Validate that the package belongs to this gig
        $package = $packageId ? GigPackage::findOrFail($packageId) : $gig->packages->first();

        if ($packageId && $package->gig_id !== $gig->id) {
            return redirect()->route('service-hub.show', $gig->slug)
                ->with('error', 'Invalid package selected for this service.');
        }

        // Format gig data
        $primaryImage = $gig->images->where('is_primary', true)->first()
            ?? $gig->images->first();
        $imageUrl = $primaryImage ? Storage::url($primaryImage->image_path) : null;

        $gigData = [
            'id' => $gig->id,
            'slug' => $gig->slug,
            'title' => $gig->title,
            'image' => $imageUrl,
            'accepts_believe_points' => $gig->accepts_believe_points ?? false,
            'seller' => [
                'id' => $gig->user->id,
                'name' => $gig->user->name,
                'avatar' => $gig->user->serviceSellerProfile && $gig->user->serviceSellerProfile->profile_image
                    ? Storage::url($gig->user->serviceSellerProfile->profile_image)
                    : ($gig->user->image ? Storage::url($gig->user->image) : null),
                'rating' => (float) $gig->rating,
            ],
        ];

        $packageData = [
            'id' => $package->id,
            'name' => $package->name,
            'price' => (float) $package->price,
            'deliveryTime' => $package->delivery_time,
            'features' => $package->features ?? [],
        ];

        // Purchased only — Gift BP is spendable only in the Gift Card module.
        $userBelievePoints = $user ? $user->purchasedBelievePointsBalance() : 0;

        // Calculate fees to check if user has enough points (only if gig accepts Believe Points)
        $sellerState = $gig->user->serviceSellerProfile->state ?? null;
        $pointsRequired = 0;
        $hasEnoughPoints = false;

        if ($gig->accepts_believe_points ?? false) {
            $fees = ServiceHubFeeService::calculateFees(
                $package->price,
                'believe_points',
                $sellerState,
                true, // gig accepts Believe Points
                $user,
                true // Services are typically for charitable use
            );
            $pointsRequired = $fees['total_buyer_pays'];
            $hasEnoughPoints = $userBelievePoints >= $pointsRequired;
        }

        return Inertia::render('frontend/service-hub/order', [
            'gig' => $gigData,
            'package' => $packageData,
            'userBelievePoints' => $userBelievePoints,
            'pointsRequired' => $pointsRequired,
            'hasEnoughPoints' => $hasEnoughPoints,
        ]);
    }

    public function orderStore(Request $request)
    {
        $user = Auth::user();

        $validated = $request->validate([
            'gig_id' => [
                'required',
                'exists:gigs,id',
                function ($attribute, $value, $fail) use ($user) {
                    $gig = Gig::find($value);
                    if ($gig && $gig->user_id === $user->id) {
                        $fail('You cannot purchase your own service.');
                    }
                    if ($gig && $gig->status !== 'active') {
                        $fail('This service is not available for purchase.');
                    }

                    // Check if seller is suspended
                    if ($gig && $gig->user->serviceSellerProfile && $gig->user->serviceSellerProfile->is_suspended) {
                        $fail('This seller has been suspended. You cannot order from them.');
                    }
                },
            ],
            'package_id' => [
                'required',
                'exists:gig_packages,id',
                function ($attribute, $value, $fail) use ($request) {
                    $gigId = $request->input('gig_id');
                    if ($gigId) {
                        $package = GigPackage::find($value);
                        if ($package && $package->gig_id != $gigId) {
                            $fail('The selected package does not belong to this service.');
                        }
                    }
                },
            ],
            'requirements' => 'required|string',
            'special_instructions' => 'nullable|string',
            'payment_method' => 'required|in:stripe,believe_points',
        ]);

        try {
            $gig = Gig::with('packages')->findOrFail($validated['gig_id']);
            $package = GigPackage::findOrFail($validated['package_id']);

            // Get seller's state from their profile
            $seller = $gig->user;
            $sellerState = $seller->serviceSellerProfile->state ?? null;

            // Validate Believe Points payment
            $paymentMethod = $validated['payment_method'];
            if ($paymentMethod === 'believe_points' && ! $gig->accepts_believe_points) {
                return back()->withErrors(['payment_method' => 'This service does not accept Believe Points payments.']);
            }

            // Get buyer for exemption check
            $buyer = Auth::user();

            // Calculate fees using ServiceHubFeeService (using seller's state)
            $fees = ServiceHubFeeService::calculateFees(
                $package->price,
                $paymentMethod,
                $sellerState,
                $gig->accepts_believe_points,
                $buyer,
                true // Services are typically for charitable use
            );

            // Handle Believe Points payment
            if ($paymentMethod === 'believe_points') {
                $pointsRequired = $fees['total_buyer_pays'];
                $user->refresh();

                if ($user->purchasedBelievePointsBalance() < $pointsRequired) {
                    $have = $user->purchasedBelievePointsBalance();

                    return back()->withErrors([
                        'payment_method' => "Insufficient purchased Believe Points. You need {$pointsRequired} points but only have {$have} (Gift BP can only be used for gift cards).",
                    ]);
                }

                // Deduct points
                if (! $user->deductBelievePoints($pointsRequired)) {
                    return back()->withErrors(['payment_method' => 'Failed to deduct Believe Points. Please try again.']);
                }
            }

            $order = ServiceOrder::create([
                'gig_id' => $gig->id,
                'buyer_id' => $user->id,
                'seller_id' => $gig->user_id,
                'package_id' => $package->id,
                'package_type' => $package->name,
                'amount' => $package->price,
                'platform_fee' => $fees['platform_fee'],
                'transaction_fee' => $fees['transaction_fee'],
                'sales_tax' => $fees['sales_tax'],
                'sales_tax_rate' => $fees['sales_tax_rate'],
                'buyer_state' => $validated['buyer_state'] ?? null,
                'seller_earnings' => $fees['seller_earnings'],
                'payment_method' => $paymentMethod,
                'requirements' => $validated['requirements'],
                'special_instructions' => $validated['special_instructions'] ?? null,
                'status' => 'pending',
                'payment_status' => $paymentMethod === 'believe_points' ? 'paid' : 'pending',
            ]);

            if ($paymentMethod === 'believe_points') {
                Transaction::record([
                    'user_id' => $user->id,
                    'related_id' => $order->id,
                    'related_type' => ServiceOrder::class,
                    'type' => 'purchase',
                    'ledger_type' => UnifiedLedgerType::BP,
                    'bp_status' => UnifiedLedgerBpStatus::AVAILABLE,
                    'status' => Transaction::STATUS_COMPLETED,
                    'amount' => $pointsRequired,
                    'fee' => 0,
                    'currency' => 'BP',
                    'payment_method' => 'believe_points',
                    'transaction_id' => 'bp_service_order:'.$order->id,
                    'meta' => [
                        'source' => 'service_hub_bp',
                        'ledger_type' => UnifiedLedgerType::BP,
                        'event_name' => 'Service Order',
                        'description' => 'Service Hub order paid with Believe Points',
                        'gig_id' => $gig->id,
                        'service_order_id' => $order->id,
                        'from_type' => 'module',
                        'from_name' => 'General Module',
                        'to_type' => 'module',
                        'to_name' => 'Service Hub Module',
                        'believe_points_used' => $pointsRequired,
                    ],
                    'processed_at' => now(),
                ]);
            }

            // Send email notification to seller
            try {
                $order->load(['seller', 'buyer', 'gig']);
                Mail::to($order->seller->email)->send(new OrderPlacedNotification($order));
            } catch (\Exception $e) {
                Log::error('Failed to send order placed email to seller', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Handle Believe Points payment
            if ($paymentMethod === 'believe_points') {
                return redirect()->route('service-hub.order.success')
                    ->with('order_id', $order->id);
            }

            // For card payments, return order ID so frontend can create checkout session
            // Check if this is an Inertia request
            if ($request->header('X-Inertia')) {
                return back()->with('order_id', $order->id);
            }

            // For API requests, return JSON with order ID
            return response()->json([
                'success' => true,
                'order_id' => $order->id,
                'message' => 'Order created successfully. Please create checkout session.',
            ]);
        } catch (\Exception $e) {
            Log::error('Service order creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->withErrors(['error' => 'Failed to place order: '.$e->getMessage()]);
        }
    }

    public function createCheckoutSession(Request $request)
    {
        $user = Auth::user();

        // Block admins from purchasing services
        if ($user->role === 'admin') {
            abort(403, 'Administrators cannot purchase services. Admins can only view services.');
        }

        $validated = $request->validate([
            'gig_id' => [
                'required',
                'exists:gigs,id',
                function ($attribute, $value, $fail) use ($user) {
                    $gig = Gig::find($value);
                    if ($gig && $gig->user_id === $user->id) {
                        $fail('You cannot purchase your own service.');
                    }
                    if ($gig && $gig->status !== 'active') {
                        $fail('This service is not available for purchase.');
                    }
                },
            ],
            'package_id' => [
                'required',
                'exists:gig_packages,id',
                function ($attribute, $value, $fail) use ($request) {
                    $gigId = $request->input('gig_id');
                    if ($gigId) {
                        $package = GigPackage::find($value);
                        if ($package && $package->gig_id != $gigId) {
                            $fail('The selected package does not belong to this service.');
                        }
                    }
                },
            ],
            'requirements' => 'required|string',
            'special_instructions' => 'nullable|string',
        ]);

        try {
            $gig = Gig::with('packages')->findOrFail($validated['gig_id']);
            $package = GigPackage::findOrFail($validated['package_id']);

            // Get seller's state from their profile
            $seller = $gig->user;
            $sellerState = $seller->serviceSellerProfile->state ?? null;

            // Get buyer for exemption check
            $buyer = Auth::user();

            // Calculate fees using ServiceHubFeeService (Stripe payment, using seller's state)
            $fees = ServiceHubFeeService::calculateFees(
                $package->price,
                'stripe',
                $sellerState,
                $gig->accepts_believe_points,
                $buyer,
                true // Services are typically for charitable use
            );

            // Step 1: Create the order
            $order = ServiceOrder::create([
                'gig_id' => $gig->id,
                'buyer_id' => $user->id,
                'seller_id' => $gig->user_id,
                'package_id' => $package->id,
                'package_type' => $package->name,
                'amount' => $package->price,
                'platform_fee' => $fees['platform_fee'],
                'transaction_fee' => $fees['transaction_fee'],
                'sales_tax' => $fees['sales_tax'],
                'sales_tax_rate' => $fees['sales_tax_rate'],
                'buyer_state' => $sellerState, // Store seller's state (used for sales tax calculation)
                'seller_earnings' => $fees['seller_earnings'],
                'payment_method' => 'stripe',
                'requirements' => $validated['requirements'],
                'special_instructions' => $validated['special_instructions'] ?? null,
                'status' => 'pending',
                'payment_status' => 'pending',
            ]);

            // Send email notification to seller
            try {
                $order->load(['seller', 'buyer', 'gig']);
                Mail::to($order->seller->email)->send(new OrderPlacedNotification($order));
            } catch (\Exception $e) {
                Log::error('Failed to send order placed email to seller', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Step 2: Create Stripe checkout session

            // Get Stripe credentials from database or fallback to .env
            $stripeEnv = StripeConfigService::getEnvironment();
            $credentials = StripeConfigService::getCredentials($stripeEnv);

            if ($credentials && ! empty($credentials['secret_key'])) {
                Stripe::setApiKey($credentials['secret_key']);
            } else {
                // Fallback to .env
                Stripe::setApiKey(config('services.stripe.secret'));
            }

            // Get Stripe credentials from database or fallback to .env
            $stripeEnv = StripeConfigService::getEnvironment();
            $credentials = StripeConfigService::getCredentials($stripeEnv);

            if ($credentials && ! empty($credentials['secret_key'])) {
                Stripe::setApiKey($credentials['secret_key']);
            } else {
                // Fallback to .env
                Stripe::setApiKey(config('services.stripe.secret'));
            }

            // Create Stripe checkout session
            $lineItems = [
                [
                    'price_data' => [
                        'currency' => 'usd',
                        'product_data' => [
                            'name' => $gig->title.' - '.$package->name,
                            'description' => 'Service Amount: $'.number_format($package->price, 2),
                        ],
                        'unit_amount' => StripeCustomerChargeAmount::chargeCentsFromNetUsd((float) $fees['total_buyer_pays'], 'card'),
                    ],
                    'quantity' => 1,
                ],
            ];

            $session = StripeSession::create([
                'payment_method_types' => ['card'],
                'line_items' => $lineItems,
                'mode' => 'payment',
                'customer_email' => $user->email,
                'success_url' => route('service-hub.order.success').'?session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => route('service-hub.order').'?serviceId='.$gig->id.'&packageId='.$package->id,
                'metadata' => [
                    'order_id' => $order->id,
                    'user_id' => $user->id,
                    'gig_id' => $gig->id,
                    'package_id' => $package->id,
                    'type' => 'service_order',
                ],
            ]);

            // Store Stripe session ID in order when checkout session is created
            $order->update([
                'stripe_session_id' => $session->id,
            ]);

            // Return JSON response with checkout URL
            return response()->json([
                'success' => true,
                'url' => $session->url,
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe checkout session creation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'gig_id' => $validated['gig_id'] ?? null,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to create checkout session: '.$e->getMessage(),
            ], 500);
        }
    }

    public function myOrders(Request $request): Response
    {
        $query = ServiceOrder::with(['gig', 'seller.serviceSellerProfile', 'package'])
            ->where('buyer_id', Auth::id());

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'LIKE', "%{$search}%")
                    ->orWhereHas('gig', function ($gq) use ($search) {
                        $gq->where('title', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('seller', function ($sq) use ($search) {
                        $sq->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        $orders = $query->with(['gig.images'])->orderBy('created_at', 'desc')->paginate(10);

        // Transform orders data
        $orders->getCollection()->transform(function ($order) {
            $gigImage = $order->gig->images->where('is_primary', true)->first()
                ?? $order->gig->images->first();
            $imageUrl = $gigImage ? Storage::url($gigImage->image_path) : null;

            return [
                'id' => $order->id,
                'orderNumber' => $order->order_number,
                'service' => [
                    'id' => $order->gig->id,
                    'slug' => $order->gig->slug,
                    'title' => $order->gig->title,
                    'image' => $imageUrl,
                ],
                'seller' => [
                    'id' => $order->seller->id,
                    'name' => $order->seller->name,
                    'avatar' => $order->seller->serviceSellerProfile && $order->seller->serviceSellerProfile->profile_image
                        ? Storage::url($order->seller->serviceSellerProfile->profile_image)
                        : ($order->seller->image ? Storage::url($order->seller->image) : null),
                ],
                'package' => $order->package_type ?? 'Standard',
                'amount' => (float) $order->amount,
                'platformFee' => (float) $order->platform_fee,
                'total' => (float) $order->amount, // Buyer pays only service amount, no fees
                'status' => $order->status,
                'paymentStatus' => $order->payment_status,
                'orderDate' => $order->created_at->format('Y-m-d'),
                'deliveryDate' => $order->created_at->copy()->addDays(3)->format('Y-m-d'), // TODO: Calculate from package delivery time
                'cancelledAt' => $order->cancelled_at ? $order->cancelled_at->format('Y-m-d H:i') : null,
                'cancellationReason' => $order->cancellation_reason,
                'requirements' => $order->requirements,
                'deliverables' => $order->deliverables ?? [],
                'canReview' => $order->status === 'completed' && ! ServiceReview::where('order_id', $order->id)->exists(),
                'canCancel' => in_array($order->status, ['pending', 'in_progress']),
            ];
        });

        return Inertia::render('frontend/service-hub/my-orders', [
            'orders' => $orders,
            'filters' => [
                'status' => $request->get('status', 'all'),
                'search' => $request->get('search', ''),
            ],
        ]);
    }

    public function sellerOrders(Request $request): Response
    {
        $user = Auth::user();

        // Block admins from accessing seller orders
        if ($user->role === 'admin') {
            abort(403, 'Administrators cannot access seller orders. Admins can only view services.');
        }

        $query = ServiceOrder::with(['gig', 'buyer', 'package'])
            ->where('seller_id', Auth::id());

        // Filter by status
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Search
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'LIKE', "%{$search}%")
                    ->orWhereHas('gig', function ($gq) use ($search) {
                        $gq->where('title', 'LIKE', "%{$search}%");
                    })
                    ->orWhereHas('buyer', function ($bq) use ($search) {
                        $bq->where('name', 'LIKE', "%{$search}%");
                    });
            });
        }

        $orders = $query->with(['gig.images'])->orderBy('created_at', 'desc')->paginate(10);

        // Transform orders data
        $orders->getCollection()->transform(function ($order) {
            $gigImage = $order->gig->images->where('is_primary', true)->first()
                ?? $order->gig->images->first();
            $imageUrl = $gigImage ? Storage::url($gigImage->image_path) : null;

            return [
                'id' => $order->id,
                'orderNumber' => $order->order_number,
                'service' => [
                    'id' => $order->gig->id,
                    'slug' => $order->gig->slug,
                    'title' => $order->gig->title,
                    'image' => $imageUrl,
                ],
                'buyer' => [
                    'id' => $order->buyer->id,
                    'name' => $order->buyer->name,
                    'avatar' => $order->buyer->image ? Storage::url($order->buyer->image) : null,
                ],
                'package' => $order->package_type ?? 'Standard',
                'amount' => (float) $order->amount,
                'platformFee' => (float) $order->platform_fee,
                'transactionFee' => (float) $order->transaction_fee,
                'salesTax' => (float) $order->sales_tax,
                'salesTaxRate' => (float) $order->sales_tax_rate,
                'sellerEarnings' => (float) $order->seller_earnings,
                'paymentMethod' => $order->payment_method ?? 'stripe',
                'status' => $order->status,
                'paymentStatus' => $order->payment_status,
                'orderDate' => $order->created_at->format('Y-m-d'),
                'deliveryDate' => $order->created_at->copy()->addDays(3)->format('Y-m-d'),
                'deliveredAt' => $order->delivered_at ? $order->delivered_at->format('Y-m-d H:i') : null,
                'requirements' => $order->requirements,
                'specialInstructions' => $order->special_instructions,
                'deliverables' => $order->deliverables ?? [],
                'canDeliver' => in_array($order->status, ['pending', 'in_progress']),
                'canApprove' => $order->status === 'pending',
                'canReject' => $order->status === 'pending',
            ];
        });

        return Inertia::render('frontend/service-hub/seller-orders', [
            'orders' => $orders,
            'filters' => [
                'status' => $request->get('status', 'all'),
                'search' => $request->get('search', ''),
            ],
        ]);
    }

    public function orderDetail(Request $request, $orderId): Response
    {
        $order = ServiceOrder::with([
            'gig.images',
            'gig.category',
            'buyer',
            'seller.serviceSellerProfile',
            'package',
            'buyerReview',
            'sellerReview',
        ])->findOrFail($orderId);

        // Check if user has access to this order
        if ($order->buyer_id !== Auth::id() && $order->seller_id !== Auth::id()) {
            abort(403, 'You do not have permission to view this order.');
        }

        $isBuyer = $order->buyer_id === Auth::id();
        $isSeller = $order->seller_id === Auth::id();

        $gigImage = $order->gig->images->where('is_primary', true)->first()
            ?? $order->gig->images->first();
        $imageUrl = $gigImage ? Storage::url($gigImage->image_path) : null;

        $orderData = [
            'id' => $order->id,
            'orderNumber' => $order->order_number,
            'service' => [
                'id' => $order->gig->id,
                'slug' => $order->gig->slug,
                'title' => $order->gig->title,
                'description' => $order->gig->description,
                'image' => $imageUrl,
                'category' => $order->gig->category->name ?? '',
            ],
            'buyer' => [
                'id' => $order->buyer->id,
                'name' => $order->buyer->name,
                'avatar' => $order->buyer->image ? Storage::url($order->buyer->image) : null,
            ],
            'seller' => [
                'id' => $order->seller->id,
                'name' => $order->seller->name,
                'avatar' => $order->seller->serviceSellerProfile && $order->seller->serviceSellerProfile->profile_image
                    ? Storage::url($order->seller->serviceSellerProfile->profile_image)
                    : ($order->seller->image ? Storage::url($order->seller->image) : null),
            ],
            'package' => [
                'id' => $order->package_id,
                'name' => $order->package_type ?? 'Standard',
                'price' => (float) $order->amount,
            ],
            'amount' => (float) $order->amount,
            'platformFee' => (float) $order->platform_fee,
            'transactionFee' => (float) $order->transaction_fee,
            'salesTax' => (float) $order->sales_tax,
            'salesTaxRate' => (float) $order->sales_tax_rate,
            'sellerEarnings' => (float) $order->seller_earnings,
            'paymentMethod' => $order->payment_method ?? 'stripe',
            'total' => (float) $order->amount, // Buyer pays only the service amount
            'status' => $order->status,
            'paymentStatus' => $order->payment_status,
            'orderDate' => $order->created_at->format('Y-m-d H:i'),
            'deliveredAt' => $order->delivered_at ? $order->delivered_at->format('Y-m-d H:i') : null,
            'completedAt' => $order->completed_at ? $order->completed_at->format('Y-m-d H:i') : null,
            'cancelledAt' => $order->cancelled_at ? $order->cancelled_at->format('Y-m-d H:i') : null,
            'cancellationReason' => $order->cancellation_reason,
            'requirements' => $order->requirements,
            'specialInstructions' => $order->special_instructions,
            'deliverables' => $order->deliverables ?? [],
            'canDeliver' => $isSeller && in_array($order->status, ['pending', 'in_progress']),
            'canAcceptDelivery' => $isBuyer && $order->status === 'delivered',
            'canComplete' => $isBuyer && $order->status === 'delivered',
            'canApprove' => $isSeller && $order->status === 'pending',
            'canReject' => $isSeller && $order->status === 'pending',
            'canCancel' => in_array($order->status, ['pending', 'in_progress']),
            'canReview' => $isBuyer && $order->status === 'completed' && ! $order->buyerReview,
            'canSellerReview' => $isSeller && $order->status === 'completed' && $order->buyerReview && ! $order->sellerReview,
            'canCancelByBuyer' => $order->canBeCancelledByBuyer(),
            'remainingCancellationHours' => $order->getRemainingCancellationTime(),
            'remainingAutoApprovalHours' => $order->getRemainingAutoApprovalTime(),
            'needsAutoApproval' => $order->needsAutomaticApproval(),
            'needsResubmission' => $order->needsResubmission(),
            'isWithinCancellationWindow' => $order->isWithinCancellationWindow(),
            'canResubmit' => $isSeller && $order->needsResubmission(),
            // 'needsResubmission' => $order->status === 'in_progress' && $order->delivered_at,
            // 'canResubmit' => $isSeller && $order->status === 'in_progress' && $order->delivered_at,
            'hasBuyerReview' => $order->buyerReview ? [
                'rating' => $order->buyerReview->rating,
                'comment' => $order->buyerReview->comment,
                'created_at' => $order->buyerReview->created_at->format('Y-m-d'),
            ] : null,
            'hasSellerReview' => $order->sellerReview ? [
                'rating' => $order->sellerReview->rating,
                'comment' => $order->sellerReview->comment,
                'created_at' => $order->sellerReview->created_at->format('Y-m-d'),
            ] : null,
        ];

        return Inertia::render('frontend/service-hub/order-detail', [
            'order' => $orderData,
            'isBuyer' => $isBuyer,
            'isSeller' => $isSeller,
        ]);
    }

    public function deliverOrder(Request $request, $orderId): RedirectResponse
    {
        $order = ServiceOrder::findOrFail($orderId);

        // Check if user is the seller
        if ($order->seller_id !== Auth::id()) {
            abort(403, 'Only the seller can deliver orders.');
        }

        // Check if order can be delivered
        if (! in_array($order->status, ['pending', 'in_progress'])) {
            return back()->withErrors(['error' => 'This order cannot be delivered in its current status.']);
        }

        $validated = $request->validate([
            'deliverables' => 'required|array|min:1',
            'deliverables.*.name' => 'required|string|max:255',
            'deliverables.*.description' => 'nullable|string|max:1000',
            'deliverables.*.file' => 'nullable|file|max:10240', // 10MB max
            'deliverables.*.url' => 'nullable|url',
            'deliverables.*.type' => 'nullable|string|max:50',
        ]);

        try {
            // Process deliverables with file uploads
            $processedDeliverables = [];
            foreach ($validated['deliverables'] as $index => $deliverable) {
                $deliverableData = [
                    'name' => $deliverable['name'],
                    'description' => $deliverable['description'] ?? null,
                    'type' => $deliverable['type'] ?? 'file',
                ];

                // Handle file upload
                if ($request->hasFile("deliverables.{$index}.file")) {
                    $file = $request->file("deliverables.{$index}.file");
                    $path = $file->store("orders/{$order->id}/deliverables", 'public');
                    $deliverableData['url'] = Storage::url($path);
                    $deliverableData['file_name'] = $file->getClientOriginalName();
                    $deliverableData['file_size'] = $file->getSize();
                    $deliverableData['mime_type'] = $file->getMimeType();
                } elseif (isset($deliverable['url'])) {
                    // If URL is provided instead of file
                    $deliverableData['url'] = $deliverable['url'];
                } else {
                    return back()->withErrors(['error' => "Deliverable {$index} must have either a file or URL."]);
                }

                $processedDeliverables[] = $deliverableData;
            }

            $order->markAsDelivered($processedDeliverables);

            return redirect()->route('service-hub.order.detail', $order->id)
                ->with('success', 'Order delivered successfully!');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to deliver order: '.$e->getMessage()]);
        }
    }

    public function acceptDelivery(Request $request, $orderId): RedirectResponse
    {
        $order = ServiceOrder::findOrFail($orderId);

        // Check if user is the buyer
        if ($order->buyer_id !== Auth::id()) {
            abort(403, 'Only the buyer can accept delivery.');
        }

        // Check if order is delivered
        if ($order->status !== 'delivered') {
            return back()->withErrors(['error' => 'This order is not in delivered status.']);
        }

        try {
            $order->markAsCompleted();

            // Send email notification to buyer
            try {
                $order->load(['seller', 'buyer', 'gig']);
                Mail::to($order->buyer->email)->send(new OrderCompletedNotification($order));
            } catch (\Exception $e) {
                Log::error('Failed to send order completed email to buyer', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return redirect()->route('service-hub.order.detail', $order->id)
                ->with('success', 'Order completed! You can now leave a review.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to accept delivery: '.$e->getMessage()]);
        }
    }

    public function completeOrder(Request $request, $orderId): RedirectResponse
    {
        return $this->acceptDelivery($request, $orderId);
    }

    public function approveOrder(Request $request, $orderId): RedirectResponse
    {
        $order = ServiceOrder::findOrFail($orderId);

        // Check if user is the seller
        if ($order->seller_id !== Auth::id()) {
            abort(403, 'Only the seller can approve this order.');
        }

        // Check if order is pending
        if ($order->status !== 'pending') {
            return back()->withErrors(['error' => 'Only pending orders can be approved.']);
        }

        try {
            $order->update([
                'status' => 'in_progress',
            ]);

            return redirect()->route('service-hub.seller-orders')
                ->with('success', 'Order approved successfully! You can now start working on it.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to approve order: '.$e->getMessage()]);
        }
    }

    public function rejectOrder(Request $request, $orderId)
    {
        $order = ServiceOrder::findOrFail($orderId);

        // Check if user is the seller
        if ($order->seller_id !== Auth::id()) {
            return response()->json(['error' => 'Only the seller can reject this order.']);
        }

        // Check if order is pending
        if ($order->status !== 'pending') {
            return response()->json(['error' => 'Only pending orders can be rejected.']);
        }

        $validated = $request->validate([
            'rejection_reason' => 'nullable|string|max:500',
        ]);

        try {
            DB::beginTransaction();

            // Update order status
            $order->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $validated['rejection_reason'] ?? 'Rejected by seller',
            ]);

            // Process refund if payment was made
            if ($order->payment_status === 'paid') {
                $this->refundOrderPayment($order, 'Order rejected by seller');
            }

            DB::commit();

            return redirect()->route('service-hub.seller-orders')
                ->with('success', 'Order rejected successfully. Refund has been processed if payment was made.');
        } catch (\Exception $e) {
            DB::rollBack();

            return back()->withErrors(['error' => 'Failed to reject order: '.$e->getMessage()]);
        }
    }
    // In ServiceHubController.php

    public function cancelOrder(Request $request, $orderId): \Illuminate\Http\JsonResponse
    {
        $order = ServiceOrder::findOrFail($orderId);

        // Check if user is the buyer
        if ($order->buyer_id !== Auth::id()) {
            return response()->json(['error' => 'Only the buyer can cancel this order.'], 403);
        }

        $validated = $request->validate([
            'cancellation_reason' => 'required|string|max:500',
        ]);

        try {
            DB::beginTransaction();

            if ($order->cancelByBuyer($validated['cancellation_reason'])) {

                // Process refund if payment was made
                if ($order->payment_status === 'paid') {
                    $this->refundOrderPayment($order, $validated['cancellation_reason']);
                }

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Order cancelled successfully. Refund has been processed if payment was made.',
                    'order' => [
                        'id' => $order->id,
                        'status' => $order->status,
                        'cancelled_at' => $order->cancelled_at,
                        'is_refunded' => $order->is_refunded,
                    ],
                ]);
            } else {
                $errorMessage = 'This order cannot be cancelled.';
                if ($order->status === 'in_progress' && ! $order->isWithinCancellationWindow()) {
                    $errorMessage = 'Cannot cancel this order anymore. The 24-hour cancellation period has expired since the seller approved it.';
                } elseif ($order->status === 'completed' || $order->status === 'cancelled') {
                    $errorMessage = 'This order is already '.$order->status.'.';
                }

                return response()->json(['error' => $errorMessage], 422);
            }
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Failed to cancel order: '.$e->getMessage()], 500);
        }
    }

    public function resubmitDelivery(Request $request, $orderId): \Illuminate\Http\JsonResponse
    {
        $order = ServiceOrder::findOrFail($orderId);

        // Check if user is the seller
        if ($order->seller_id !== Auth::id()) {
            return response()->json(['error' => 'Only the seller can resubmit delivery.'], 403);
        }

        // Check if order needs resubmission
        if (! $order->needsResubmission()) {
            return response()->json(['error' => 'This order does not need resubmission.'], 422);
        }

        $validated = $request->validate([
            'deliverables' => 'required|array|min:1',
            'deliverables.*.name' => 'required|string|max:255',
            'deliverables.*.description' => 'nullable|string|max:1000',
            'deliverables.*.file' => 'required|file|max:10240',
        ]);

        try {
            $processedDeliverables = [];
            foreach ($validated['deliverables'] as $index => $deliverable) {
                $file = $request->file("deliverables.{$index}.file");
                $path = $file->store("orders/{$order->id}/deliverables", 'public');

                $processedDeliverables[] = [
                    'name' => $deliverable['name'],
                    'description' => $deliverable['description'] ?? null,
                    'url' => Storage::url($path),
                    'file_name' => $file->getClientOriginalName(),
                    'file_size' => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                    'type' => 'file',
                ];
            }

            // Mark as delivered again
            $order->update([
                'status' => 'delivered',
                'delivered_at' => now(),
                'deliverables' => $processedDeliverables,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Delivery resubmitted successfully!',
                'order' => [
                    'id' => $order->id,
                    'status' => $order->status,
                    'delivered_at' => $order->delivered_at,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to resubmit delivery: '.$e->getMessage()], 500);
        }
    }

    public function getOrderStatusInfo(Request $request, $orderId): \Illuminate\Http\JsonResponse
    {
        $order = ServiceOrder::findOrFail($orderId);

        // Check if user has access to this order
        if ($order->buyer_id !== Auth::id() && $order->seller_id !== Auth::id()) {
            abort(403, 'You do not have permission to view this order.');
        }

        return response()->json([
            'can_cancel_by_buyer' => $order->canBeCancelledByBuyer(),
            'remaining_cancellation_hours' => $order->getRemainingCancellationTime(),
            'remaining_auto_approval_hours' => $order->getRemainingAutoApprovalTime(),
            'needs_auto_approval' => $order->needsAutomaticApproval(),
            'is_within_cancellation_window' => $order->isWithinCancellationWindow(),
            'needs_resubmission' => $order->needsResubmission(),
            'status' => $order->status,
            'delivered_at' => $order->delivered_at,
            'created_at' => $order->created_at,
        ]);
    }

    public function orderSuccess(Request $request): Response
    {
        $sessionId = $request->get('session_id');
        $orderId = null;
        $paymentStatus = 'pending';

        if ($sessionId) {
            try {
                // Get Stripe credentials
                $stripeEnv = StripeConfigService::getEnvironment();
                $credentials = StripeConfigService::getCredentials($stripeEnv);

                if ($credentials && ! empty($credentials['secret_key'])) {
                    Stripe::setApiKey($credentials['secret_key']);
                } else {
                    Stripe::setApiKey(config('services.stripe.secret'));
                }

                // Retrieve session from Stripe
                $session = StripeSession::retrieve($sessionId);

                // Convert Stripe session object to array for storage
                $stripeResponse = [
                    'session_id' => $session->id,
                    'payment_status' => $session->payment_status,
                    'payment_intent' => $session->payment_intent,
                    'customer_email' => $session->customer_email,
                    'amount_total' => $session->amount_total,
                    'currency' => $session->currency,
                    'metadata' => $session->metadata ? (array) $session->metadata : null,
                    'created' => $session->created,
                    'retrieved_at' => now()->toIso8601String(),
                ];

                if ($session->payment_status === 'paid' && isset($session->metadata->order_id)) {
                    $orderId = $session->metadata->order_id;
                    $order = ServiceOrder::find($orderId);

                    if ($order && $order->buyer_id === Auth::id()) {
                        // Update order payment status and store Stripe response
                        $order->update([
                            'payment_status' => 'paid',
                            'payment_method' => 'stripe',
                            'stripe_response' => $stripeResponse,
                            'stripe_session_id' => $sessionId,
                            'stripe_payment_intent_id' => $session->payment_intent ?? null,
                        ]);
                        $paymentStatus = 'paid';

                        // Send email notification to seller when payment is confirmed
                        try {
                            $order->load(['seller', 'buyer', 'gig']);
                            Mail::to($order->seller->email)->send(new OrderPlacedNotification($order));
                        } catch (\Exception $e) {
                            Log::error('Failed to send order placed email to seller after payment confirmation', [
                                'order_id' => $order->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                } elseif (isset($session->metadata->order_id)) {
                    // Store Stripe response even if payment is not completed yet
                    $orderId = $session->metadata->order_id;
                    $order = ServiceOrder::find($orderId);

                    if ($order && $order->buyer_id === Auth::id()) {
                        $order->update([
                            'stripe_response' => $stripeResponse,
                            'stripe_session_id' => $sessionId,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Stripe session retrieval failed', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return Inertia::render('frontend/service-hub/order-success', [
            'order_id' => $orderId,
            'payment_status' => $paymentStatus,
            'session_id' => $sessionId,
        ]);
    }

    public function sellerProfile(Request $request, $id): Response
    {
        $seller = \App\Models\User::with([
            'gigs' => function ($q) {
                $q->where('status', 'active')
                    ->with(['category', 'images', 'packages', 'reviews'])
                    ->orderBy('created_at', 'desc');
            },
            'serviceSellerProfile',
        ])->findOrFail($id);

        // Validation: Check if seller is suspended
        if ($seller->serviceSellerProfile && $seller->serviceSellerProfile->is_suspended) {
            abort(404, 'Seller profile not available.');
        }

        // // Validation: Check if user has a seller profile
        // if (!$seller->serviceSellerProfile) {
        //     abort(404, 'Seller profile not found. This user does not have a seller profile.');
        // }

        // Validation: Check if the seller profile is verified (optional - you can remove this if you want to show unverified profiles)
        // if ($seller->serviceSellerProfile->verification_status !== 'verified') {
        //     abort(404, 'Seller profile is not available. This profile is pending verification.');
        // }

        // Calculate seller stats
        $totalSales = ServiceOrder::where('seller_id', $seller->id)
            ->where('status', 'completed')
            ->count();

        $totalReviews = ServiceReview::whereHas('order', function ($q) use ($seller) {
            $q->where('seller_id', $seller->id);
        })->count();

        $avgRating = ServiceReview::whereHas('order', function ($q) use ($seller) {
            $q->where('seller_id', $seller->id);
        })->avg('rating') ?? 0;

        $recentReviews = ServiceReview::with(['user:id,name,image', 'gig:id,title'])
            ->whereHas('order', function ($q) use ($seller) {
                $q->where('seller_id', $seller->id);
            })
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get();

        $sellerProfile = $seller->serviceSellerProfile;

        // Transform gigs to services format
        $services = $seller->gigs->map(function ($gig) {
            $primaryImage = $gig->images->where('is_primary', true)->first()
                ?? $gig->images->first();
            $imageUrl = $primaryImage ? Storage::url($primaryImage->image_path) : null;

            // Get cheapest package price or base price
            $price = $gig->packages->min('price') ?? $gig->price ?? 0;

            return [
                'id' => $gig->id,
                'slug' => $gig->slug,
                'title' => $gig->title,
                'price' => (float) $price,
                'rating' => (float) $gig->rating ?? 0,
                'reviews' => $gig->reviews->count(),
                'image' => $imageUrl,
                'category' => $gig->category->name ?? '',
            ];
        })->toArray();

        // Transform seller data
        $sellerAvatar = null;
        if ($sellerProfile && $sellerProfile->profile_image) {
            $sellerAvatar = Storage::url($sellerProfile->profile_image);
        } elseif ($seller->image) {
            $sellerAvatar = Storage::url($seller->image);
        }

        $sellerData = [
            'id' => $seller->id,
            'name' => $seller->name,
            'avatar' => $sellerAvatar,
            'description' => $sellerProfile?->bio ?? '',
        ];

        // Transform recent reviews
        $transformedReviews = $recentReviews->map(function ($review) {
            return [
                'id' => $review->id,
                'user' => [
                    'name' => $review->user->name,
                    'avatar' => $review->user->image ? Storage::url($review->user->image) : null,
                ],
                'rating' => $review->rating,
                'comment' => $review->comment,
                'service' => $review->gig->title ?? '',
                'date' => $review->created_at->diffForHumans(),
            ];
        })->toArray();

        // Check if current user has a successful/paid order with this seller
        $hasSuccessfulOrder = false;
        if (Auth::check()) {
            $hasSuccessfulOrder = ServiceOrder::where('buyer_id', Auth::id())
                ->where('seller_id', $seller->id)
                ->where('payment_status', 'paid')
                ->exists();
        }

        return Inertia::render('frontend/service-hub/seller-profile', [
            'seller' => $sellerData,
            'sellerProfile' => $sellerProfile,
            'services' => $services,
            'stats' => [
                'totalSales' => $totalSales,
                'totalReviews' => $totalReviews,
                'avgRating' => round($avgRating, 1),
                'totalServices' => $seller->gigs->count(),
                'repeatBuyers' => 0, // Can be calculated later
                'onTimeDelivery' => 95, // Can be calculated later
                'orderCompletion' => 98, // Can be calculated later
            ],
            'recentReviews' => $transformedReviews,
            'isOwner' => Auth::check() && Auth::id() == $seller->id,
            'hasSuccessfulOrder' => $hasSuccessfulOrder,
        ]);
    }

    public function sellerReviews(Request $request, $id): Response
    {
        $seller = \App\Models\User::with('serviceSellerProfile')->findOrFail($id);
        $sellerProfile = $seller->serviceSellerProfile;

        // Validation: Check if user has a seller profile
        if (! $sellerProfile) {
            abort(404, 'Seller profile not found. This user does not have a seller profile.');
        }

        $reviews = ServiceReview::with(['user:id,name,image', 'gig:id,title,slug'])
            ->whereHas('order', function ($q) use ($seller) {
                $q->where('seller_id', $seller->id);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        // Calculate rating distribution
        $ratingDistribution = [];
        $totalReviews = ServiceReview::whereHas('order', function ($q) use ($seller) {
            $q->where('seller_id', $seller->id);
        })->count();

        for ($i = 5; $i >= 1; $i--) {
            $count = ServiceReview::whereHas('order', function ($q) use ($seller) {
                $q->where('seller_id', $seller->id);
            })->where('rating', $i)->count();
            $ratingDistribution[$i] = $totalReviews > 0 ? round(($count / $totalReviews) * 100, 1) : 0;
        }

        $avgRating = ServiceReview::whereHas('order', function ($q) use ($seller) {
            $q->where('seller_id', $seller->id);
        })->avg('rating') ?? 0;

        // Transform seller data
        $sellerAvatar = null;
        if ($sellerProfile && $sellerProfile->profile_image) {
            $sellerAvatar = Storage::url($sellerProfile->profile_image);
        } elseif ($seller->image) {
            $sellerAvatar = Storage::url($seller->image);
        }

        $sellerData = [
            'id' => $seller->id,
            'name' => $seller->name,
            'avatar' => $sellerAvatar,
        ];

        // Transform reviews
        $transformedReviews = $reviews->getCollection()->map(function ($review) {
            return [
                'id' => $review->id,
                'user' => [
                    'name' => $review->user->name,
                    'avatar' => $review->user->image ? Storage::url($review->user->image) : null,
                ],
                'rating' => $review->rating,
                'comment' => $review->comment,
                'service' => [
                    'id' => $review->gig->id,
                    'title' => $review->gig->title,
                    'slug' => $review->gig->slug,
                ],
                'date' => $review->created_at->diffForHumans(),
                'created_at' => $review->created_at->format('Y-m-d H:i:s'),
            ];
        })->toArray();

        return Inertia::render('frontend/service-hub/seller-reviews', [
            'seller' => $sellerData,
            'reviews' => [
                'data' => $transformedReviews,
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
            ],
            'ratingDistribution' => $ratingDistribution,
            'avgRating' => round($avgRating, 1),
            'totalReviews' => $totalReviews,
        ]);
    }

    public function reviews(Request $request, $slug): Response
    {
        $gig = Gig::with('category')->where('slug', $slug)->firstOrFail();

        $query = ServiceReview::with('user:id,name,image')
            ->where('gig_id', $gig->id)
            ->where('reviewer_type', 'buyer');

        // Handle sorting
        $sortBy = $request->get('sort_by', 'most_recent');
        switch ($sortBy) {
            case 'most_helpful':
                $query->orderBy('helpful_count', 'desc')->orderBy('created_at', 'desc');
                break;
            case 'highest_rated':
                $query->orderBy('rating', 'desc')->orderBy('created_at', 'desc');
                break;
            case 'lowest_rated':
                $query->orderBy('rating', 'asc')->orderBy('created_at', 'desc');
                break;
            case 'most_recent':
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $reviews = $query->paginate(10);

        // Calculate rating distribution (only for buyer reviews)
        $ratingDistribution = [];
        $totalBuyerReviews = ServiceReview::where('gig_id', $gig->id)
            ->where('reviewer_type', 'buyer')
            ->count();

        for ($i = 5; $i >= 1; $i--) {
            $count = ServiceReview::where('gig_id', $gig->id)
                ->where('reviewer_type', 'buyer')
                ->where('rating', $i)
                ->count();
            $ratingDistribution[$i] = $totalBuyerReviews > 0 ? round(($count / $totalBuyerReviews) * 100, 1) : 0;
        }

        // Format gig data
        $gigData = [
            'id' => $gig->id,
            'slug' => $gig->slug,
            'title' => $gig->title,
            'rating' => (float) $gig->rating ?? 0,
            'totalReviews' => $gig->reviews_count ?? 0,
        ];

        // Transform reviews
        $transformedReviews = $reviews->getCollection()->map(function ($review) {
            return [
                'id' => $review->id,
                'user' => [
                    'name' => $review->user->name,
                    'avatar' => $review->user->image ? Storage::url($review->user->image) : null,
                ],
                'rating' => $review->rating,
                'comment' => $review->comment,
                'date' => $review->created_at->diffForHumans(),
                'helpful' => $review->helpful_count ?? 0,
                'verified' => $review->is_verified ?? false,
            ];
        })->toArray();

        return Inertia::render('frontend/service-hub/reviews', [
            'gig' => $gigData,
            'reviews' => [
                'data' => $transformedReviews,
                'current_page' => $reviews->currentPage(),
                'last_page' => $reviews->lastPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
            ],
            'ratingDistribution' => $ratingDistribution,
        ]);
    }

    public function reviewsStore(Request $request, $slug)
    {
        $validated = $request->validate([
            'order_id' => 'required|exists:service_orders,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|max:1000',
        ]);

        try {
            $gig = Gig::where('slug', $slug)->firstOrFail();
            $order = ServiceOrder::where('id', $validated['order_id'])
                ->where('buyer_id', Auth::id())
                ->where('status', 'completed')
                ->firstOrFail();

            // Check if review already exists
            if (ServiceReview::where('order_id', $order->id)->exists()) {
                return back()->withErrors(['error' => 'You have already reviewed this order.']);
            }

            ServiceReview::create([
                'gig_id' => $gig->id,
                'order_id' => $order->id,
                'user_id' => Auth::id(),
                'reviewer_type' => 'buyer',
                'rating' => $validated['rating'],
                'comment' => $validated['comment'],
                'is_verified' => true,
            ]);

            return back()->with('success', 'Review submitted successfully!');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to submit review: '.$e->getMessage()]);
        }
    }

    public function sellerReviewStore(Request $request, $orderId)
    {
        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|max:1000',
        ]);

        try {
            $order = ServiceOrder::findOrFail($orderId);

            // Check if user is the seller
            if ($order->seller_id !== Auth::id()) {
                abort(403, 'Only the seller can submit a seller review.');
            }

            // Check if order is completed
            if ($order->status !== 'completed') {
                return back()->withErrors(['error' => 'Order must be completed before seller can review.']);
            }

            // Check if buyer has reviewed
            if (! $order->buyerReview) {
                return back()->withErrors(['error' => 'Buyer must review first before seller can review.']);
            }

            // Check if seller review already exists
            if ($order->sellerReview) {
                return back()->withErrors(['error' => 'You have already reviewed this order.']);
            }

            ServiceReview::create([
                'gig_id' => $order->gig_id,
                'order_id' => $order->id,
                'user_id' => Auth::id(),
                'reviewer_type' => 'seller',
                'rating' => $validated['rating'],
                'comment' => $validated['comment'],
                'is_verified' => true,
            ]);

            return redirect()->route('service-hub.order.detail', $order->id)
                ->with('success', 'Seller review submitted successfully!');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to submit review: '.$e->getMessage()]);
        }
    }

    public function toggleFavorite(Request $request, $slug)
    {
        $gig = Gig::where('slug', $slug)->firstOrFail();

        $favorite = GigFavorite::where('user_id', Auth::id())
            ->where('gig_id', $gig->id)
            ->first();

        if ($favorite) {
            $favorite->delete();

            return response()->json(['favorited' => false]);
        } else {
            GigFavorite::create([
                'user_id' => Auth::id(),
                'gig_id' => $gig->id,
            ]);

            return response()->json(['favorited' => true]);
        }
    }

    public function sellerProfileCreate(): Response|RedirectResponse
    {
        $user = Auth::user();

        // Block admins from creating seller profiles
        if ($user->role === 'admin') {
            abort(403, 'Administrators cannot create seller profiles. Only organizations and regular users can be sellers.');
        }

        // If user already has a seller profile, redirect to edit
        if ($user->serviceSellerProfile) {
            return redirect()->route('service-hub.seller-profile.edit')
                ->with('info', 'You already have a seller profile. You can update it here.');
        }

        // Get all states for sales tax dropdown
        $states = \App\Models\StateSalesTax::orderBy('state')->get(['state', 'state_code', 'base_sales_tax_rate']);

        $allSkills = \App\Models\SellerSkill::orderBy('name')->pluck('name')->toArray();
        $allLanguages = \App\Models\Language::orderBy('name')->pluck('name')->toArray();

        return Inertia::render('frontend/service-hub/seller-profile/create', [
            'states' => $states,
            'all_skills' => $allSkills,
            'all_languages' => $allLanguages,
        ]);
    }

    public function sellerProfileStore(Request $request): RedirectResponse
    {
        $user = Auth::user();

        if ($user->role === 'admin') {
            abort(403, 'Administrators cannot create seller profiles.');
        }

        if ($user->serviceSellerProfile) {
            return redirect()->route('service-hub.seller-profile.edit')
                ->with('error', 'You already have a seller profile.');
        }

        $validated = $request->validate([
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'bio' => 'required|string|max:1000',
            'location' => 'nullable|string|max:255',
            'state' => 'required|string|max:2',
            'timezone' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20',
            'skills' => 'nullable|array',
            'skills.*' => 'string|max:50',
            'languages' => 'nullable|array',
            'languages.*.name' => 'nullable|string|max:100',
            'languages.*.level' => 'nullable|in:basic,conversational,fluent,native',
            'education' => 'nullable|array',
            'experience' => 'nullable|array',
            'response_time' => 'nullable|string|max:50',
            'website' => 'nullable|string|max:255',      // ← url এর বদলে string
            'linkedin' => 'nullable|string|max:255',
            'twitter' => 'nullable|string|max:255',
            'facebook' => 'nullable|string|max:255',
            'instagram' => 'nullable|string|max:255',
        ]);

        $languages = $validated['languages'] ?? [];

        // Remove duplicates (just in case frontend fails or API abuse)
        $uniqueLanguages = [];
        $seen = [];

        foreach ($languages as $lang) {
            $name = trim($lang['name'] ?? '');
            if ($name && ! in_array($name, $seen)) {
                $uniqueLanguages[] = [
                    'name' => $name,
                    'level' => $lang['level'] ?? 'basic',
                ];
                $seen[] = $name;
            }
        }

        // Profile image
        $profileImage = null;
        if ($request->hasFile('profile_image')) {
            $profileImage = $request->file('profile_image')->store('seller-profiles', 'public');
        }

        // Normalize social links (add https:// automatically)
        $socialLinks = [
            'website' => $this->normalizeSocialUrl($validated['website'] ?? null, 'https://'),
            'linkedin' => $this->normalizeSocialUrl($validated['linkedin'] ?? null, 'https://www.linkedin.com/in/'),
            'twitter' => $this->normalizeSocialUrl($validated['twitter'] ?? null, 'https://twitter.com/', true),
            'facebook' => $this->normalizeSocialUrl($validated['facebook'] ?? null, 'https://facebook.com/'),
            'instagram' => $this->normalizeSocialUrl($validated['instagram'] ?? null, 'https://instagram.com/'),
        ];

        $profile = ServiceSellerProfile::create([
            'user_id' => $user->id,
            'bio' => $validated['bio'],
            'location' => $validated['location'] ?? null,
            'state' => $validated['state'],
            'timezone' => $validated['timezone'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'skills' => $validated['skills'] ?? [],
            'languages' => $uniqueLanguages,
            'education' => $validated['education'] ?? [],
            'experience' => $validated['experience'] ?? [],
            'response_time' => $validated['response_time'] ?? null,
            'website' => $socialLinks['website'],
            'linkedin' => $socialLinks['linkedin'],
            'twitter' => $socialLinks['twitter'],
            'facebook' => $socialLinks['facebook'],
            'instagram' => $socialLinks['instagram'],
            'profile_image' => $profileImage,
            'member_since' => now()->format('Y-m-d'),
        ]);

        return redirect()->route('service-hub.seller.profile', $user->id)
            ->with('success', 'Seller profile created successfully!');
    }

    /**
     * Normalize social URL: add protocol + correct prefix if missing
     */
    private function normalizeSocialUrl(?string $input, string $defaultPrefix, bool $removeAt = false): ?string
    {
        if (empty(trim($input ?? ''))) {
            return null;
        }

        $url = trim($input);

        // Already full URL → return as is
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        // Remove @ for twitter/x handles
        if ($removeAt && str_starts_with($url, '@')) {
            $url = substr($url, 1);
        }

        // Remove any accidental protocol/prefix user might have added
        $url = preg_replace('#^(https?://|http://|www\.)+#i', '', $url);

        // Remove trailing slash if any
        $url = rtrim($url, '/');

        return $defaultPrefix.$url;
    }

    public function sellerDashboard(Request $request): Response|RedirectResponse
    {
        $user = Auth::user();

        // Block admins from accessing seller dashboard
        if ($user->role === 'admin') {
            abort(403, 'Administrators cannot access seller dashboard. Only organizations and regular users can be sellers.');
        }

        $profile = $user->serviceSellerProfile;

        // Check if seller is suspended
        if ($profile && $profile->is_suspended) {
            return Inertia::render('frontend/service-hub/seller-suspended', [
                'suspension_reason' => $profile->suspension_reason,
                'suspended_at' => $profile->suspended_at,
            ]);
        }

        // Calculate statistics
        $totalOrders = ServiceOrder::where('seller_id', $user->id)->count();
        $pendingOrders = ServiceOrder::where('seller_id', $user->id)->where('status', 'pending')->count();
        $inProgressOrders = ServiceOrder::where('seller_id', $user->id)->where('status', 'in_progress')->count();
        $completedOrders = ServiceOrder::where('seller_id', $user->id)->where('status', 'completed')->count();

        // Calculate earnings
        $totalEarnings = ServiceOrder::where('seller_id', $user->id)
            ->where('payment_status', 'paid')
            ->sum('seller_earnings');

        $pendingEarnings = ServiceOrder::where('seller_id', $user->id)
            ->where('payment_status', 'paid')
            ->where('status', '!=', 'completed')
            ->sum('seller_earnings');

        $availableEarnings = ServiceOrder::where('seller_id', $user->id)
            ->where('payment_status', 'paid')
            ->where('status', 'completed')
            ->sum('seller_earnings');

        // Get active services
        $activeServices = Gig::where('user_id', $user->id)
            ->where('status', 'active')
            ->with(['category', 'images', 'packages'])
            ->orderBy('created_at', 'desc')
            ->limit(6)
            ->get()
            ->map(function ($gig) {
                $primaryImage = $gig->images->where('is_primary', true)->first() ?? $gig->images->first();

                return [
                    'id' => $gig->id,
                    'slug' => $gig->slug,
                    'title' => $gig->title,
                    'image' => $primaryImage ? Storage::url($primaryImage->image_path) : null,
                    'category' => $gig->category->name ?? 'Uncategorized',
                    'price' => (float) $gig->price,
                    'rating' => (float) $gig->rating,
                    'orders_count' => $gig->orders_count,
                    'reviews_count' => $gig->reviews_count,
                ];
            });

        // Get recent orders
        $recentOrders = ServiceOrder::where('seller_id', $user->id)
            ->with(['gig.images', 'buyer', 'package'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($order) {
                $gigImage = $order->gig->images->where('is_primary', true)->first() ?? $order->gig->images->first();

                return [
                    'id' => $order->id,
                    'orderNumber' => $order->order_number,
                    'serviceTitle' => $order->gig->title,
                    'serviceImage' => $gigImage ? Storage::url($gigImage->image_path) : null,
                    'buyerName' => $order->buyer->name,
                    'buyerAvatar' => $order->buyer->image ? Storage::url($order->buyer->image) : null,
                    'amount' => (float) $order->amount,
                    'sellerEarnings' => (float) $order->seller_earnings,
                    'status' => $order->status,
                    'paymentStatus' => $order->payment_status,
                    'createdAt' => $order->created_at->format('M d, Y'),
                ];
            });

        // Calculate average rating
        $avgRating = ServiceReview::whereHas('order', function ($q) use ($user) {
            $q->where('seller_id', $user->id);
        })->avg('rating') ?? 0;

        // Get total reviews
        $totalReviews = ServiceReview::whereHas('order', function ($q) use ($user) {
            $q->where('seller_id', $user->id);
        })->count();

        // Get total services
        $totalServices = Gig::where('user_id', $user->id)->count();
        $activeServicesCount = Gig::where('user_id', $user->id)->where('status', 'active')->count();

        return Inertia::render('frontend/service-hub/seller-dashboard', [
            'profile' => [
                'id' => $profile->id,
                'bio' => $profile->bio,
                'location' => $profile->location,
                'state' => $profile->state,
                'profile_image' => $profile->profile_image ? Storage::url($profile->profile_image) : null,
            ],
            'stats' => [
                'totalOrders' => $totalOrders,
                'pendingOrders' => $pendingOrders,
                'inProgressOrders' => $inProgressOrders,
                'completedOrders' => $completedOrders,
                'totalEarnings' => round($totalEarnings, 2),
                'pendingEarnings' => round($pendingEarnings, 2),
                'availableEarnings' => round($availableEarnings, 2),
                'avgRating' => round($avgRating, 2),
                'totalReviews' => $totalReviews,
                'totalServices' => $totalServices,
                'activeServices' => $activeServicesCount,
            ],
            'activeServices' => $activeServices,
            'recentOrders' => $recentOrders,
            'deleteUrl' => route('service-hub.services.destroy', ':id'),
        ]);
    }

    public function sellerProfileEdit(): Response|RedirectResponse
    {
        $user = Auth::user();

        // Block admins from editing seller profiles
        if ($user->role === 'admin') {
            abort(403, 'Administrators cannot edit seller profiles. Only organizations and regular users can be sellers.');
        }

        $profile = $user->serviceSellerProfile;

        if (! $profile) {
            return redirect()->route('service-hub.seller-profile.create')
                ->with('error', 'Please create a seller profile first.');
        }

        // Get all states for sales tax dropdown
        $states = \App\Models\StateSalesTax::orderBy('state')->get(['state', 'state_code', 'base_sales_tax_rate']);

        $allSkills = \App\Models\SellerSkill::orderBy('name')->pluck('name')->toArray();
        $allLanguages = \App\Models\Language::orderBy('name')->pluck('name')->toArray();

        return Inertia::render('frontend/service-hub/seller-profile/edit', [
            'profile' => [
                'id' => $profile->id,
                'bio' => $profile->bio,
                'location' => $profile->location,
                'state' => $profile->state,
                'timezone' => $profile->timezone,
                'phone' => $profile->phone,
                'skills' => $profile->skills ?? [],
                'languages' => $profile->languages ?? [],
                'education' => $profile->education ?? [],
                'experience' => $profile->experience ?? [],
                'response_time' => $profile->response_time,
                'website' => $profile->website,
                'linkedin' => $profile->linkedin,
                'twitter' => $profile->twitter,
                'facebook' => $profile->facebook,
                'instagram' => $profile->instagram,
                'profile_image' => $profile->profile_image,
            ],
            'states' => $states,
            'all_skills' => $allSkills,
            'all_languages' => $allLanguages,
        ]);
    }

    public function sellerProfileUpdate(Request $request): RedirectResponse
    {
        $user = Auth::user();

        // Block admins from updating seller profiles
        if ($user->role === 'admin') {
            abort(403, 'Administrators cannot update seller profiles. Only organizations and regular users can be sellers.');
        }

        $profile = $user->serviceSellerProfile;

        if (! $profile) {
            return redirect()->route('service-hub.seller-profile.create')
                ->with('error', 'Please create a seller profile first.');
        }

        $validated = $request->validate([
            'profile_image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'bio' => 'required|string|max:1000',
            'location' => 'nullable|string|max:255',
            'state' => 'required|string|max:2',
            'timezone' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:20',
            'skills' => 'nullable|array',
            'skills.*' => 'string|max:50',
            'languages' => 'nullable|array',
            'languages.*.name' => 'nullable|string|max:100',
            'languages.*.level' => 'nullable|in:basic,conversational,fluent,native',
            'education' => 'nullable|array',
            'education.*.institution' => 'nullable|string|max:255',
            'education.*.degree' => 'nullable|string|max:255',
            'education.*.year' => 'nullable|integer|min:1900|max:'.date('Y'),
            'experience' => 'nullable|array',
            'experience.*.company' => 'nullable|string|max:255',
            'experience.*.position' => 'nullable|string|max:255',
            'experience.*.duration' => 'nullable|string|max:100',
            'response_time' => 'nullable|string|max:50',
            'website' => 'nullable|string|max:255',  // ← changed from url
            'linkedin' => 'nullable|string|max:255',
            'twitter' => 'nullable|string|max:255',
            'facebook' => 'nullable|string|max:255',
            'instagram' => 'nullable|string|max:255',
        ]);

        $languages = $validated['languages'] ?? [];

        // Remove duplicates (just in case frontend fails or API abuse)
        $uniqueLanguages = [];
        $seen = [];

        foreach ($languages as $lang) {
            $name = trim($lang['name'] ?? '');
            if ($name && ! in_array($name, $seen)) {
                $uniqueLanguages[] = [
                    'name' => $name,
                    'level' => $lang['level'] ?? 'basic',
                ];
                $seen[] = $name;
            }
        }

        // Normalize social links (same logic as create)
        $social = [
            'website' => $this->normalizeSocialUrl($validated['website'] ?? null, 'https://'),
            'linkedin' => $this->normalizeSocialUrl($validated['linkedin'] ?? null, 'https://www.linkedin.com/in/'),
            'twitter' => $this->normalizeSocialUrl($validated['twitter'] ?? null, 'https://twitter.com/', true),
            'facebook' => $this->normalizeSocialUrl($validated['facebook'] ?? null, 'https://www.facebook.com/'),
            'instagram' => $this->normalizeSocialUrl($validated['instagram'] ?? null, 'https://www.instagram.com/'),
        ];

        // Handle profile image upload
        $updateData = [
            'bio' => $validated['bio'],
            'location' => $validated['location'] ?? null,
            'state' => $validated['state'],
            'timezone' => $validated['timezone'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'skills' => $validated['skills'] ?? [],
            'languages' => $uniqueLanguages ?? [],
            'education' => $validated['education'] ?? [],
            'experience' => $validated['experience'] ?? [],
            'response_time' => $validated['response_time'] ?? null,
            'website' => $social['website'],
            'linkedin' => $social['linkedin'],
            'twitter' => $social['twitter'],
            'facebook' => $social['facebook'],
            'instagram' => $social['instagram'],
        ];

        if ($request->hasFile('profile_image')) {
            // Delete old image if exists
            if ($profile->profile_image) {
                Storage::disk('public')->delete($profile->profile_image);
            }
            $updateData['profile_image'] = $request->file('profile_image')->store('seller-profiles', 'public');
        }

        $profile->update($updateData);

        return redirect()->route('service-hub.seller.profile', $user->id)
            ->with('success', 'Seller profile updated successfully!');
    }

    public function createOrGetServiceChat(Request $request, $slug)
    {
        $gig = Gig::where('slug', $slug)->firstOrFail();
        $buyerId = Auth::id();
        $sellerId = $gig->user_id;

        if ($buyerId === $sellerId) {
            return response()->json(['error' => 'You cannot chat with yourself.'], 403);
        }

        // Find or create service chat
        $serviceChat = ServiceChat::firstOrCreate(
            [
                'buyer_id' => $buyerId,
                'seller_id' => $sellerId,
            ],
            [
                'buyer_read' => true,
                'seller_read' => false,
            ]
        );

        // Load relationships
        $serviceChat->load([
            'buyer:id,name,image',
            'seller:id,name,image',
            'latestMessage.user:id,name,image',
        ]);

        return response()->json([
            'chat' => [
                'id' => $serviceChat->id,
                'buyer' => [
                    'id' => $serviceChat->buyer->id,
                    'name' => $serviceChat->buyer->name,
                    'avatar' => $serviceChat->buyer->image ? Storage::url($serviceChat->buyer->image) : null,
                ],
                'seller' => [
                    'id' => $serviceChat->seller->id,
                    'name' => $serviceChat->seller->name,
                    'avatar' => $serviceChat->seller->image ? Storage::url($serviceChat->seller->image) : null,
                ],
                'last_message' => $serviceChat->latestMessage->first() ? [
                    'message' => $serviceChat->latestMessage->first()->message,
                    'created_at' => $serviceChat->latestMessage->first()->created_at->toISOString(),
                    'user' => [
                        'id' => $serviceChat->latestMessage->first()->user->id,
                        'name' => $serviceChat->latestMessage->first()->user->name,
                    ],
                ] : null,
                'unread_count' => $serviceChat->unreadCountForUser($buyerId),
            ],
        ]);
    }

    public function getServiceChatMessages(Request $request, $chatId)
    {
        $serviceChat = ServiceChat::with(['buyer', 'seller'])
            ->where(function ($query) {
                $query->where('buyer_id', Auth::id())
                    ->orWhere('seller_id', Auth::id());
            })
            ->findOrFail($chatId);

        $allMessages = ServiceChatMessage::where('service_chat_id', $chatId)
            ->orderBy('created_at', 'asc')
            ->get();

        Log::info('ServiceChat Messages Query', [
            'chat_id' => $chatId,
            'total_messages' => $allMessages->count(),
            'message_ids' => $allMessages->pluck('id')->toArray(),
            'auth_id' => Auth::id(),
        ]);

        $messages = $allMessages->map(function ($message) {
            // Load user directly
            $user = \App\Models\User::select('id', 'name', 'image')->find($message->user_id);

            Log::info('Processing message', [
                'message_id' => $message->id,
                'user_id' => $message->user_id,
                'has_user' => $user !== null,
                'is_mine' => $message->user_id === Auth::id(),
            ]);

            return [
                'id' => $message->id,
                'user_id' => $message->user_id,
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->image ? Storage::url($user->image) : null,
                ] : null,
                'message' => $message->message,
                'attachments' => $message->attachments ?? [],
                'created_at' => $message->created_at->toISOString(),
                'is_mine' => $message->user_id === Auth::id(),
            ];
        });

        // Convert to array to ensure all messages are included
        $messagesArray = $messages->values()->toArray();

        Log::info('ServiceChat Messages Response', [
            'total_messages_returned' => count($messagesArray),
            'message_ids' => array_column($messagesArray, 'id'),
        ]);

        // Mark messages as read
        if (Auth::id() === $serviceChat->buyer_id) {
            $serviceChat->update(['buyer_read' => true]);
        } else {
            $serviceChat->update(['seller_read' => true]);
        }

        ServiceChatMessage::where('service_chat_id', $chatId)
            ->where('user_id', '!=', Auth::id())
            ->update(['is_read' => true]);

        return response()->json(['messages' => $messagesArray]);
    }

    public function sendServiceChatMessage(Request $request, $chatId)
    {
        $serviceChat = ServiceChat::where(function ($query) {
            $query->where('buyer_id', Auth::id())
                ->orWhere('seller_id', Auth::id());
        })->findOrFail($chatId);

        $request->validate([
            'message' => 'required|string|max:2000',
            'attachments.*' => 'nullable|file|max:10240',
        ]);

        $attachmentsData = [];
        if ($request->hasFile('attachments')) {
            foreach ($request->file('attachments') as $file) {
                $path = $file->store('service_chat_attachments', 'public');
                $attachmentsData[] = [
                    'name' => $file->getClientOriginalName(),
                    'url' => Storage::url($path),
                    'type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                ];
            }
        }

        $message = ServiceChatMessage::create([
            'service_chat_id' => $chatId,
            'user_id' => Auth::id(),
            'message' => $request->input('message'),
            'attachments' => $attachmentsData,
            'is_read' => false,
        ]);

        // Update chat last message time and read status
        $isBuyer = Auth::id() === $serviceChat->buyer_id;
        $serviceChat->update([
            'last_message_at' => now(),
            $isBuyer ? 'buyer_read' : 'seller_read' => true,
            ! $isBuyer ? 'buyer_read' : 'seller_read' => false,
        ]);

        // Reload the message with user relationship to ensure it's fresh
        $message->refresh();
        $message->load(['user' => function ($query) {
            $query->select('id', 'name', 'image');
        }]);

        // Ensure user is loaded - if not, load it again
        if (! $message->user) {
            $message->load('user:id,name,image');
        }

        // Double check user exists
        if (! $message->user) {
            \Illuminate\Support\Facades\Log::error('Message created but user not found', [
                'message_id' => $message->id,
                'user_id' => $message->user_id,
                'chat_id' => $chatId,
            ]);

            return response()->json(['error' => 'User not found for message'], 500);
        }

        return response()->json([
            'message' => [
                'id' => $message->id,
                'user_id' => $message->user_id,
                'user' => [
                    'id' => $message->user->id,
                    'name' => $message->user->name,
                    'avatar' => $message->user->image ? Storage::url($message->user->image) : null,
                ],
                'message' => $message->message,
                'attachments' => $message->attachments ?? [],
                'created_at' => $message->created_at->toISOString(),
                'is_mine' => true,
            ],
        ]);
    }

    public function destroyService(int $id)
    {
        $gig = Gig::findOrFail($id);

        // Check authorization
        if ($gig->user_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Check for active orders
        if ($gig->orders()->whereIn('status', ['pending', 'in_progress'])->exists()) {
            return response()->json([
                'error' => 'Cannot delete service with active/pending orders.',
            ], 422);
        }

        // Soft delete if using soft deletes
        $gig->delete();

        // Return JSON response for Inertia
        return response()->json(['success' => 'Service deleted successfully.']);
    }

    public function getServiceChats(Request $request)
    {
        $chats = ServiceChat::with([
            'buyer:id,name,image',
            'seller:id,name,image',
            'latestMessage.user:id,name,image',
        ])
            ->where(function ($query) {
                $query->where('buyer_id', Auth::id())
                    ->orWhere('seller_id', Auth::id());
            })
            ->orderBy('last_message_at', 'desc')
            ->get()
            ->map(function ($chat) {
                $latestMessage = $chat->latestMessage->first();
                $otherUser = Auth::id() === $chat->buyer_id ? $chat->seller : $chat->buyer;

                return [
                    'id' => $chat->id,
                    'other_user' => [
                        'id' => $otherUser->id,
                        'name' => $otherUser->name,
                        'avatar' => $otherUser->image ? Storage::url($otherUser->image) : null,
                    ],
                    'last_message' => $latestMessage ? [
                        'message' => $latestMessage->message,
                        'created_at' => $latestMessage->created_at->toISOString(),
                        'user' => [
                            'id' => $latestMessage->user->id,
                            'name' => $latestMessage->user->name,
                        ],
                    ] : null,
                    'unread_count' => $chat->unreadCountForUser(Auth::id()),
                    'updated_at' => $chat->updated_at->toISOString(),
                ];
            });

        $totalUnread = array_sum(array_column($chats->toArray(), 'unread_count'));

        return response()->json([
            'chats' => $chats,
            'total_unread' => $totalUnread,
        ]);
    }

    public function getUnreadCount(Request $request)
    {

        if (! Auth::check()) {
            return response()->json(['total_unread' => 0]);
        }

        $chats = ServiceChat::where(function ($query) {
            $query->where('buyer_id', Auth::id())
                ->orWhere('seller_id', Auth::id());
        })->get();

        $totalUnread = $chats->sum(function ($chat) {
            return $chat->unreadCountForUser(Auth::id());
        });

        return response()->json([
            'total_unread' => $totalUnread,
        ]);
    }

    public function chats(Request $request): Response
    {
        $chats = ServiceChat::with([
            'buyer:id,name,image',
            'seller:id,name,image',
            'latestMessage.user:id,name,image',
        ])
            ->where(function ($query) {
                $query->where('buyer_id', Auth::id())
                    ->orWhere('seller_id', Auth::id());
            })
            ->orderBy('last_message_at', 'desc')
            ->get()
            ->map(function ($chat) {
                $latestMessage = $chat->latestMessage->first();
                $otherUser = Auth::id() === $chat->buyer_id ? $chat->seller : $chat->buyer;

                return [
                    'id' => $chat->id,
                    'other_user' => [
                        'id' => $otherUser->id,
                        'name' => $otherUser->name,
                        'avatar' => $otherUser->image ? Storage::url($otherUser->image) : null,
                    ],
                    'last_message' => $latestMessage ? [
                        'message' => $latestMessage->message,
                        'created_at' => $latestMessage->created_at->toISOString(),
                        'user' => [
                            'id' => $latestMessage->user->id,
                            'name' => $latestMessage->user->name,
                        ],
                    ] : null,
                    'unread_count' => $chat->unreadCountForUser(Auth::id()),
                    'updated_at' => $chat->updated_at->toISOString(),
                ];
            });

        $totalUnread = array_sum(array_column($chats->toArray(), 'unread_count'));

        return Inertia::render('frontend/service-hub/chats', [
            'chats' => $chats,
            'total_unread' => $totalUnread,
        ]);
    }

    public function serviceChat(Request $request, $chatId)
    {
        $serviceChat = ServiceChat::with([
            'buyer:id,name,image',
            'seller:id,name,image',
        ])
            ->where(function ($query) {
                $query->where('buyer_id', Auth::id())
                    ->orWhere('seller_id', Auth::id());
            })
            ->findOrFail($chatId);

        $otherUser = Auth::id() === $serviceChat->buyer_id ? $serviceChat->seller : $serviceChat->buyer;

        $chatData = [
            'id' => $serviceChat->id,
            'other_user' => [
                'id' => $otherUser->id,
                'name' => $otherUser->name,
                'avatar' => $otherUser->image ? Storage::url($otherUser->image) : null,
            ],
            'buyer_id' => $serviceChat->buyer_id,
            'seller_id' => $serviceChat->seller_id,
        ];

        // Get seller's gigs if current user is seller
        $sellerGigs = [];
        $isSeller = Auth::id() === $serviceChat->seller_id;
        if ($isSeller) {
            $sellerGigs = Gig::where('user_id', Auth::id())
                ->where('status', 'active')
                ->with(['images' => function ($q) {
                    $q->where('is_primary', true)->orWhere(function ($q2) {
                        $q2->orderBy('sort_order')->limit(1);
                    });
                }])
                ->select('id', 'slug', 'title', 'description', 'price')
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($g) {
                    $primaryImage = $g->images->first();

                    return [
                        'id' => $g->id,
                        'slug' => $g->slug,
                        'title' => $g->title,
                        'description' => $g->description,
                        'price' => (float) $g->price,
                        'image' => $primaryImage ? Storage::url($primaryImage->image_path) : null,
                    ];
                })
                ->toArray();
        }

        // Get offers for this chat
        $offers = CustomOffer::with(['gig'])
            ->where(function ($query) use ($serviceChat) {
                $query->where('buyer_id', $serviceChat->buyer_id)
                    ->where('seller_id', $serviceChat->seller_id);
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($offer) {
                return [
                    'id' => $offer->id,
                    'gig_id' => $offer->gig_id,
                    'title' => $offer->title,
                    'description' => $offer->description,
                    'price' => (float) $offer->price,
                    'delivery_time' => $offer->delivery_time,
                    'requirements' => $offer->requirements,
                    'status' => $offer->status,
                    'created_at' => $offer->created_at->toISOString(),
                ];
            })
            ->toArray();

        return Inertia::render('frontend/service-hub/chat', [
            'chat' => $chatData,
            'sellerGigs' => $sellerGigs,
            'isSeller' => $isSeller,
            'offers' => $offers,
        ]);
    }

    public function createCustomOffer(Request $request, $slug): \Illuminate\Http\JsonResponse
    {
        $user = Auth::user();

        // Block admins from creating offers
        if ($user->role === 'admin') {
            return response()->json(['error' => 'Administrators cannot create offers. Admins can only view services.'], 403);
        }

        $gig = Gig::where('slug', $slug)->firstOrFail();

        // Check if user is the seller
        if ($gig->user_id !== Auth::id()) {
            return response()->json(['error' => 'Only the seller can create offers.'], 403);
        }

        $validated = $request->validate([
            'buyer_id' => 'nullable|exists:users,id',
            'chat_id' => 'nullable|exists:service_chats,id',
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'price' => 'required|numeric|min:5',
            'delivery_time' => 'required|string|max:100',
            'requirements' => 'nullable|string',
        ]);

        // Get buyer_id from chat if chat_id is provided
        $buyerId = $validated['buyer_id'] ?? null;
        if (! $buyerId && isset($validated['chat_id']) && $validated['chat_id']) {
            $serviceChat = ServiceChat::findOrFail($validated['chat_id']);
            $buyerId = Auth::id() === $serviceChat->seller_id ? $serviceChat->buyer_id : $serviceChat->seller_id;
        }

        // Check if buyer is not the seller
        if ($buyerId && $buyerId == Auth::id()) {
            return response()->json(['error' => 'You cannot create an offer for yourself.'], 400);
        }

        // If buyer_id is still not provided, return error
        if (! $buyerId || $buyerId == 0) {
            return response()->json([
                'error' => 'Buyer ID is required. Please provide buyer ID or chat ID.',
            ], 400);
        }

        $offer = CustomOffer::create([
            'gig_id' => $gig->id,
            'seller_id' => Auth::id(),
            'buyer_id' => $buyerId,
            'title' => $validated['title'],
            'description' => $validated['description'],
            'price' => $validated['price'],
            'delivery_time' => $validated['delivery_time'],
            'requirements' => $validated['requirements'] ?? null,
            'status' => 'pending',
            'expires_at' => now()->addDays(7), // Offer expires in 7 days
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Custom offer created successfully!',
            'offer' => [
                'id' => $offer->id,
                'title' => $offer->title,
                'price' => $offer->price,
                'delivery_time' => $offer->delivery_time,
            ],
        ]);
    }

    public function acceptCustomOffer(Request $request, $offerId): RedirectResponse
    {
        $offer = CustomOffer::with(['gig', 'seller', 'buyer'])->findOrFail($offerId);

        // Check if user is the buyer
        if ($offer->buyer_id !== Auth::id()) {
            abort(403, 'You do not have permission to accept this offer.');
        }

        // Check if offer is still valid
        if ($offer->status !== 'pending') {
            return back()->withErrors(['error' => 'This offer is no longer available.']);
        }

        if ($offer->isExpired()) {
            $offer->update(['status' => 'expired']);

            return back()->withErrors(['error' => 'This offer has expired.']);
        }

        try {
            $gig = $offer->gig;

            // Get seller's state from their profile
            $seller = $gig->user;
            $sellerState = $seller->serviceSellerProfile->state ?? null;

            // Get buyer for exemption check
            $buyer = Auth::user();

            // Calculate fees using ServiceHubFeeService (default to Stripe for custom offers)
            // Note: Custom offers will need payment method selection in future
            $fees = ServiceHubFeeService::calculateFees(
                $offer->price,
                'stripe', // Default to Stripe for custom offers
                $sellerState,
                $gig->accepts_believe_points ?? false,
                $buyer,
                true // Services are typically for charitable use
            );

            // Create order from offer
            $order = ServiceOrder::create([
                'gig_id' => $offer->gig_id,
                'buyer_id' => $offer->buyer_id,
                'seller_id' => $offer->seller_id,
                'package_id' => null, // Custom offer, no package
                'package_type' => 'Custom Offer',
                'amount' => $offer->price,
                'platform_fee' => $fees['platform_fee'],
                'transaction_fee' => $fees['transaction_fee'],
                'sales_tax' => $fees['sales_tax'],
                'sales_tax_rate' => $fees['sales_tax_rate'],
                'seller_earnings' => $fees['seller_earnings'],
                'payment_method' => 'stripe', // Default for custom offers
                'requirements' => $offer->requirements,
                'special_instructions' => $offer->description,
                'status' => 'pending',
                'payment_status' => 'pending',
            ]);

            // Mark offer as accepted
            $offer->accept();

            return redirect()->route('service-hub.order.detail', $order->id)
                ->with('success', 'Offer accepted! Order created successfully.');
        } catch (\Exception $e) {
            return back()->withErrors(['error' => 'Failed to accept offer: '.$e->getMessage()]);
        }
    }

    public function rejectCustomOffer(Request $request, $offerId): RedirectResponse
    {
        $offer = CustomOffer::findOrFail($offerId);

        // Check if user is the buyer
        if ($offer->buyer_id !== Auth::id()) {
            abort(403, 'You do not have permission to reject this offer.');
        }

        if ($offer->status !== 'pending') {
            return back()->withErrors(['error' => 'This offer is no longer available.']);
        }

        $offer->reject();

        return back()->with('success', 'Offer rejected.');
    }

    /**
     * Test email notifications with detailed debugging
     * Usage: /service-hub/test-email?order_id=1&type=placed
     *        /service-hub/test-email?order_id=1&type=completed
     */
    public function testEmailNotifications(Request $request)
    {
        try {
            $type = $request->get('type', 'placed'); // 'placed' or 'completed'
            $orderId = $request->get('order_id');
            $showConfig = $request->get('show_config', false);

            // Show mail configuration for debugging
            if ($showConfig) {
                $smtpConfig = config('mail.mailers.smtp');
                $testConnection = $request->get('test_connection', false);

                $response = [
                    'mail_config' => [
                        'default_mailer' => config('mail.default'),
                        'from_address' => config('mail.from.address'),
                        'from_name' => config('mail.from.name'),
                        'mail_host' => $smtpConfig['host'],
                        'mail_port' => $smtpConfig['port'],
                        'mail_username' => $smtpConfig['username'] ? '***set***' : 'not set',
                        'mail_password' => $smtpConfig['password'] ? '***set***' : 'not set',
                        'mail_encryption' => $smtpConfig['encryption'] ?? 'not set',
                        'env_mail_mailer' => env('MAIL_MAILER'),
                        'env_mail_from' => env('MAIL_FROM_ADDRESS'),
                    ],
                    'note' => 'If MAIL_MAILER=log, emails are written to storage/logs/laravel.log instead of being sent',
                ];

                // Test SMTP connection if requested
                if ($testConnection && config('mail.default') === 'smtp') {
                    try {
                        // Try to send a test email to verify SMTP connection
                        $testEmail = 'test@example.com';
                        Mail::raw('SMTP Connection Test', function ($message) use ($testEmail) {
                            $message->to($testEmail)
                                ->subject('SMTP Test');
                        });

                        $response['smtp_test'] = [
                            'status' => 'success',
                            'message' => 'SMTP configuration appears valid (test email attempted)',
                            'note' => 'Check if test email was actually sent to verify connection',
                        ];
                    } catch (\Exception $e) {
                        $response['smtp_test'] = [
                            'status' => 'failed',
                            'error' => $e->getMessage(),
                            'class' => get_class($e),
                        ];
                    }
                }

                return response()->json($response);
            }

            if (! $orderId) {
                return response()->json([
                    'success' => false,
                    'error' => 'Order ID is required. Use ?order_id=1&type=placed or ?order_id=1&type=completed',
                    'usage' => [
                        'test_placed' => '/service-hub/test-email?order_id=1&type=placed',
                        'test_completed' => '/service-hub/test-email?order_id=1&type=completed',
                        'show_config' => '/service-hub/test-email?show_config=1',
                    ],
                ], 400);
            }

            $order = ServiceOrder::with(['seller', 'buyer', 'gig'])->find($orderId);

            if (! $order) {
                return response()->json([
                    'success' => false,
                    'error' => "Order #{$orderId} not found",
                ], 404);
            }

            // Validate order has required relationships
            if (! $order->seller || ! $order->buyer || ! $order->gig) {
                return response()->json([
                    'success' => false,
                    'error' => 'Order is missing required relationships (seller, buyer, or gig)',
                    'order_data' => [
                        'has_seller' => $order->seller ? true : false,
                        'has_buyer' => $order->buyer ? true : false,
                        'has_gig' => $order->gig ? true : false,
                    ],
                ], 400);
            }

            $emailSent = false;
            $emailTo = '';
            $emailSubject = '';
            $mailError = null;

            if ($type === 'placed') {
                // Test OrderPlacedNotification (to seller)
                $emailTo = $order->seller->email;

                Log::info('Testing OrderPlacedNotification email', [
                    'order_id' => $order->id,
                    'to' => $emailTo,
                    'mailer' => config('mail.default'),
                ]);

                try {
                    $mailable = new OrderPlacedNotification($order);
                    $emailSubject = $mailable->envelope()->subject;

                    // Use Mail::send() with error handling
                    Mail::to($emailTo)->send($mailable);
                    $emailSent = true;

                    Log::info('OrderPlacedNotification email sent successfully', [
                        'order_id' => $order->id,
                        'to' => $emailTo,
                        'subject' => $emailSubject,
                        'mailer' => config('mail.default'),
                    ]);
                } catch (\Exception $mailException) {
                    // Check if it's an SMTP/transport error
                    $errorMessage = $mailException->getMessage();
                    if (str_contains($errorMessage, 'SMTP') || str_contains($errorMessage, 'transport') || str_contains($errorMessage, 'connection')) {
                        $mailError = 'SMTP/Transport Error: '.$errorMessage;
                    } else {
                        $mailError = $errorMessage;
                    }
                    $mailError = $mailException->getMessage();
                    Log::error('Failed to send OrderPlacedNotification email', [
                        'order_id' => $order->id,
                        'to' => $emailTo,
                        'error' => $mailException->getMessage(),
                        'class' => get_class($mailException),
                        'trace' => $mailException->getTraceAsString(),
                    ]);
                }

                return response()->json([
                    'success' => $emailSent,
                    'message' => $emailSent
                        ? "Order placed email sent successfully to seller: {$emailTo}"
                        : "Failed to send email: {$mailError}",
                    'debug' => [
                        'email_to' => $emailTo,
                        'email_subject' => $emailSubject,
                        'mailer_used' => config('mail.default'),
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'seller_name' => $order->seller->name,
                        'buyer_name' => $order->buyer->name,
                        'service_title' => $order->gig->title,
                    ],
                    'note' => config('mail.default') === 'log'
                        ? 'Emails are being logged to storage/logs/laravel.log (not actually sent). Check the log file for email content.'
                        : 'Email should be sent via '.config('mail.default').'. Check your inbox and spam folder.',
                    'error' => $mailError,
                ]);
            } elseif ($type === 'completed') {
                // Test OrderCompletedNotification (to buyer)
                $emailTo = $order->buyer->email;

                Log::info('Testing OrderCompletedNotification email', [
                    'order_id' => $order->id,
                    'to' => $emailTo,
                    'mailer' => config('mail.default'),
                ]);

                try {
                    $mailable = new OrderCompletedNotification($order);
                    $emailSubject = $mailable->envelope()->subject;

                    // Use Mail::send() with error handling
                    Mail::to($emailTo)->send($mailable);
                    $emailSent = true;

                    Log::info('OrderCompletedNotification email sent successfully', [
                        'order_id' => $order->id,
                        'to' => $emailTo,
                        'subject' => $emailSubject,
                        'mailer' => config('mail.default'),
                    ]);
                } catch (\Exception $mailException) {
                    // Check if it's an SMTP/transport error
                    $errorMessage = $mailException->getMessage();
                    if (str_contains($errorMessage, 'SMTP') || str_contains($errorMessage, 'transport') || str_contains($errorMessage, 'connection')) {
                        $mailError = 'SMTP/Transport Error: '.$errorMessage;
                    } else {
                        $mailError = $errorMessage;
                    }
                    $mailError = $mailException->getMessage();
                    Log::error('Failed to send OrderCompletedNotification email', [
                        'order_id' => $order->id,
                        'to' => $emailTo,
                        'error' => $mailException->getMessage(),
                        'class' => get_class($mailException),
                        'trace' => $mailException->getTraceAsString(),
                    ]);
                }

                return response()->json([
                    'success' => $emailSent,
                    'message' => $emailSent
                        ? "Order completed email sent successfully to buyer: {$emailTo}"
                        : "Failed to send email: {$mailError}",
                    'debug' => [
                        'email_to' => $emailTo,
                        'email_subject' => $emailSubject,
                        'mailer_used' => config('mail.default'),
                        'order_id' => $order->id,
                        'order_number' => $order->order_number,
                        'seller_name' => $order->seller->name,
                        'buyer_name' => $order->buyer->name,
                        'service_title' => $order->gig->title,
                    ],
                    'note' => config('mail.default') === 'log'
                        ? 'Emails are being logged to storage/logs/laravel.log (not actually sent). Check the log file for email content.'
                        : 'Email should be sent via '.config('mail.default').'. Check your inbox and spam folder.',
                    'error' => $mailError,
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'error' => "Invalid type. Use 'placed' or 'completed'",
                ], 400);
            }
        } catch (\Exception $e) {
            Log::error('Email test failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'trace' => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Calculate fees for an order (API endpoint)
     */
    public function calculateFees(Request $request)
    {
        $validated = $request->validate([
            'gig_id' => 'required|exists:gigs,id',
            'package_id' => 'required|exists:gig_packages,id',
            'payment_method' => 'required|in:stripe,believe_points',
        ]);

        try {
            $gig = Gig::with('user.serviceSellerProfile')->findOrFail($validated['gig_id']);
            $package = GigPackage::findOrFail($validated['package_id']);

            // Get seller's state from their profile
            $sellerState = $gig->user->serviceSellerProfile->state ?? null;

            // Validate Believe Points payment
            if ($validated['payment_method'] === 'believe_points' && ! $gig->accepts_believe_points) {
                return response()->json([
                    'error' => 'This service does not accept Believe Points payments.',
                ], 400);
            }

            // Get buyer for exemption check
            $buyer = Auth::user();

            $fees = ServiceHubFeeService::getFeeBreakdown(
                $package->price,
                $validated['payment_method'],
                $sellerState,
                $gig->accepts_believe_points,
                $buyer,
                true // Services are typically for charitable use
            );

            return response()->json([
                'success' => true,
                'fees' => $fees,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Handle refund for a cancelled/rejected order
     */
    private function refundOrderPayment(ServiceOrder $order, string $cancellationReason): void
    {
        try {
            // Check if payment was already refunded
            if ($order->is_refunded || $order->refunded_at) {
                Log::info('Order already refunded', ['order_id' => $order->id]);

                return;
            }

            // Only refund if payment was actually paid
            if ($order->payment_status !== 'paid') {
                Log::info('Order payment not paid, skipping refund', ['order_id' => $order->id]);

                return;
            }

            // Handle refund based on payment method
            if ($order->payment_method === 'believe_points') {
                $this->refundBelievePoints($order, $cancellationReason);
            } elseif ($order->payment_method === 'stripe') {
                $this->refundStripePayment($order, $cancellationReason);
            }

            // Mark order as refunded
            $order->update([
                'is_refunded' => true,
                'refunded_at' => now(),
                'refund_reason' => $cancellationReason,
            ]);

            Log::info('Order refund processed successfully', ['order_id' => $order->id]);
        } catch (\Exception $e) {
            Log::error('Failed to refund order payment', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // You might want to queue a retry or notify admins
            $this->notifyAdminAboutRefundFailure($order, $e->getMessage());
        }
    }

    /**
     * Refund Believe Points to buyer
     */
    private function refundBelievePoints(ServiceOrder $order, string $cancellationReason): bool
    {
        try {
            $buyer = $order->buyer;
            $refundAmount = $order->amount;

            // Add points back to buyer's account
            $buyer->increment('believe_points', $refundAmount);

            // Create a refund record
            \App\Models\BelievePointsRefund::create([
                'order_id' => $order->id,
                'user_id' => $buyer->id,
                'amount' => $refundAmount,
                'reason' => $cancellationReason,
                'status' => 'completed',
            ]);

            Transaction::record([
                'user_id' => $buyer->id,
                'related_id' => $order->id,
                'related_type' => ServiceOrder::class,
                'type' => 'refund',
                'ledger_type' => UnifiedLedgerType::BP,
                'bp_status' => UnifiedLedgerBpStatus::AVAILABLE,
                'status' => Transaction::STATUS_COMPLETED,
                'amount' => $refundAmount,
                'fee' => 0,
                'currency' => 'BP',
                'payment_method' => 'believe_points',
                'transaction_id' => 'bp_service_order_refund:'.$order->id,
                'meta' => [
                    'source' => 'service_hub_bp_refund',
                    'ledger_type' => UnifiedLedgerType::BP,
                    'event_name' => 'Service Order Refund',
                    'description' => $cancellationReason,
                    'service_order_id' => $order->id,
                    'from_type' => 'module',
                    'from_name' => 'Service Hub Module',
                    'to_type' => 'module',
                    'to_name' => 'General Module',
                ],
                'processed_at' => now(),
            ]);

            // Log the transaction
            Log::info('Believe Points refunded', [
                'order_id' => $order->id,
                'buyer_id' => $buyer->id,
                'amount' => $refundAmount,
                'reason' => $cancellationReason,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to refund Believe Points', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Refund Stripe payment using Laravel Cashier
     */
    private function refundStripePayment(ServiceOrder $order, string $cancellationReason): bool
    {
        try {
            // Get Stripe credentials
            $stripeEnv = StripeConfigService::getEnvironment();
            $credentials = StripeConfigService::getCredentials($stripeEnv);

            if ($credentials && ! empty($credentials['secret_key'])) {
                Stripe::setApiKey($credentials['secret_key']);
            } else {
                // Fallback to default Stripe config
                Stripe::setApiKey(config('services.stripe.secret'));
            }

            // Check if we have a Stripe payment intent ID
            if (! $order->stripe_payment_intent_id && ! $order->stripe_session_id) {
                throw new \Exception('No Stripe payment intent or session ID found for order');
            }

            // Try to get payment intent from session first
            $paymentIntentId = $order->stripe_payment_intent_id;

            if (! $paymentIntentId && $order->stripe_session_id) {
                try {
                    $session = \Stripe\Checkout\Session::retrieve($order->stripe_session_id);
                    $paymentIntentId = $session->payment_intent;
                } catch (\Exception $e) {
                    Log::warning('Could not retrieve Stripe session', [
                        'order_id' => $order->id,
                        'session_id' => $order->stripe_session_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if (! $paymentIntentId) {
                throw new \Exception('No Stripe payment intent ID available for refund');
            }

            // Create refund using Stripe API
            $refundData = [
                'payment_intent' => $paymentIntentId,
                'reason' => 'requested_by_customer',
                'metadata' => [
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'cancellation_reason' => $cancellationReason,
                    'refund_initiated_by' => Auth::id(),
                ],
            ];

            // If you want partial refund (without fees), adjust amount
            // For full refund of service amount only:
            $refundAmount = (int) ($order->amount * 100); // Convert to cents

            $refundData['amount'] = $refundAmount;

            // Create the refund
            $refund = Refund::create($refundData);

            // Store refund information
            $order->update([
                'stripe_refund_id' => $refund->id,
                'refund_amount' => $refundAmount / 100, // Convert back to dollars
                'refund_status' => $refund->status,
            ]);

            // Create local refund record
            \App\Models\StripeRefund::create([
                'order_id' => $order->id,
                'refund_id' => $refund->id,
                'payment_intent_id' => $paymentIntentId,
                'amount' => $refundAmount / 100,
                'currency' => $refund->currency,
                'reason' => $cancellationReason,
                'status' => $refund->status,
                'stripe_response' => json_encode($refund->toArray()),
            ]);

            Log::info('Stripe refund created successfully', [
                'order_id' => $order->id,
                'refund_id' => $refund->id,
                'amount' => $refundAmount / 100,
                'status' => $refund->status,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to create Stripe refund', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
                'stripe_error' => $e instanceof \Stripe\Exception\ApiErrorException ? $e->getJsonBody() : null,
            ]);
            throw $e;
        }
    }

    /**
     * Notify admin about refund failure
     */
    private function notifyAdminAboutRefundFailure(ServiceOrder $order, string $errorMessage): void
    {
        try {
            // Send email to admin
            $adminEmail = config('mail.admin_email', 'support@believeinunity.org');

            Mail::to($adminEmail)->send(new \App\Mail\RefundFailedNotification(
                $order,
                $errorMessage
            ));

            // Or send notification via your notification system
            // Notification::send($admins, new RefundFailed($order, $errorMessage));

            Log::warning('Admin notified about refund failure', [
                'order_id' => $order->id,
                'admin_email' => $adminEmail,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to notify admin about refund failure', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
