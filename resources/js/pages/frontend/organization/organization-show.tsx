"use client"

import { useState, useEffect, useMemo, useRef } from "react"
import toast from "react-hot-toast"
import { Link, router, usePage, useForm } from "@inertiajs/react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import {
  ChevronDown,
  MapPin,
  Zap,
  Users,
  UserCheck,
  Mail,
  Heart,
  MoreHorizontal,
  ThumbsUp,
  Share2,
  CheckCircle,
  FileText,
  User,
  UserPlus,
  Clock,
  Calendar,
  MessageCircle,
  Building2,
  Globe,
  ShieldCheck,
  BadgeCheck,
  ShoppingBag,
  Briefcase,
  Phone,
  Bell,
  ArrowLeft,
  Send,
  Smile,
  Laugh,
  Angry,
  DollarSign,
  AlertCircle,
  TrendingUp,
  ExternalLink,
  UsersRound,
  MessagesSquare,
} from "lucide-react"
import CommunicationHubPanel from "@/components/communication-hub/CommunicationHubPanel"
import type {
  HubAnnouncement,
  HubCategory,
  HubDiscussion,
  HubPermissions,
} from "@/components/communication-hub/types"
import { Button } from "@/components/frontend/ui/button"
import { Avatar, AvatarFallback, AvatarImage } from "@/components/frontend/ui/avatar"
import { Badge } from "@/components/frontend/ui/badge"
import { Textarea } from "@/components/frontend/ui/textarea"
import DonationModal from "@/components/frontend/donation-modal"
import OrgFollowButton from "@/components/ui/OrgFollowButtonProps"
import InviteOrganizationPopup from "@/components/frontend/InviteOrganizationPopup"
import { PageHead } from "@/components/frontend/PageHead"
import { motion, AnimatePresence } from "framer-motion"
import useAxios from "@/hooks/useAxios"
import OrganizationSetupStatusBar from "@/components/organization/OrganizationSetupStatusBar"
import MembershipJoinCard, {
  type MembershipJoinPayload,
  MembershipJoinButton,
} from "@/components/membership/MembershipJoinCard"
import { type CommunityFeedItem } from "@/components/community/CommunityFeedList"

function supporterIsOrganizationAccount(supporter: {
  is_organization_follower?: boolean
  is_partner_organization?: boolean
  user?: { role?: string } | null
}): boolean {
  if (supporter.is_organization_follower || supporter.is_partner_organization) return true
  const r = supporter.user?.role
  return r === "organization" || r === "organization_pending"
}

function SupporterAccountTypeBadge({
  supporter,
  variant,
}: {
  supporter: {
    is_organization_follower?: boolean
    is_partner_organization?: boolean
    user?: { role?: string } | null
  }
  variant: "followers" | "supporters"
}) {
  const isOrg = supporterIsOrganizationAccount(supporter)
  if (variant === "followers") {
    return isOrg ? (
      <Badge className="mt-1 text-[10px] px-2 py-0.5 h-auto font-medium bg-indigo-600/90 hover:bg-indigo-600 text-white border-0 gap-1 w-fit">
        <Building2 className="h-3 w-3 shrink-0" />
        Organization
      </Badge>
    ) : (
      <Badge
        variant="outline"
        className="mt-1 text-[10px] px-2 py-0.5 h-auto font-medium border-gray-300 dark:border-white/20 text-gray-700 dark:text-gray-300 gap-1 w-fit"
      >
        <User className="h-3 w-3 shrink-0" />
        User
      </Badge>
    )
  }
  return isOrg ? (
    <Badge className="mt-1 text-[10px] px-2 py-0.5 h-auto font-medium bg-purple-600/90 hover:bg-purple-600 text-white border-0 gap-1 w-fit">
      <Building2 className="h-3 w-3 shrink-0" />
      Organization
    </Badge>
  ) : (
    <Badge
      variant="outline"
      className="mt-1 text-[10px] px-2 py-0.5 h-auto font-medium border-gray-300 dark:border-white/20 text-gray-700 dark:text-gray-300 gap-1 w-fit"
    >
      <User className="h-3 w-3 shrink-0" />
      User
    </Badge>
  )
}

interface OrganizationPageProps {
  auth?: any
  organization: any
  isFav?: boolean
  posts?: any[]
  postsCount?: number
  supportersCount?: number
  jobsCount?: number
  supporters?: any[]
  primarySupporters?: any[]
  secondarySupporters?: any[]
  primarySupportersCount?: number
  secondarySupportersCount?: number
  peopleYouMayKnow?: any[]
  trendingOrganizations?: any[]
  products?: any[]
  jobs?: any[]
  events?: any[] | { data: any[]; total: number; current_page?: number; last_page?: number }
  eventsCount?: number
  currentPage?: string
  believePointsEarned?: number
  believePointsSpent?: number
  believePointsBalance?: number
  postFilter?: string
  partnerOrganizationsCount?: number
  careAlliancePublic?: { slug: string; id?: number }
  careAllianceCampaigns?: Array<{ id: number; slug: string; name: string; description?: string | null }>
  careAllianceProfile?: {
    focus_areas: Array<{ id: number; name: string }>
    website?: string | null
    hub_lead_name?: string
    ein?: string | null
  }
  allianceMembers?: Array<{
    id: number
    name: string
    public_slug?: string | null
    image?: string | null
    city?: string | null
    state?: string | null
    joined_at?: string | null
  }>
  connectedCareAlliances?: Array<{ id: number; name: string; slug: string }>
  membershipJoin?: MembershipJoinPayload | null
  communityGroups?: Array<{
    id: number
    name: string
    slug: string
    description: string | null
    category: string | null
    cover_image: string | null
    is_featured: boolean
    is_pinned: boolean
    members_count: number
    url: string
  }>
  canCreateCommunityGroup?: boolean
  createCommunityGroupUrl?: string | null
  communityDiscussions?: CommunityFeedItem[]
  communityAnnouncements?: CommunityFeedItem[]
  canCreateCommunityDiscussion?: boolean
  canCreateCommunityAnnouncement?: boolean
  canModerateCommunity?: boolean
  communityParentType?: string | null
  communityParentId?: number | null
  communityReportReasons?: Record<string, string>
  communicationHub?: {
    announcements?: HubAnnouncement[]
    announcementsTotal?: number
    discussions?: HubDiscussion[]
    discussionsTotal?: number
    categories?: HubCategory[]
    permissions?: Partial<HubPermissions>
  } | null
  seo?: { title?: string; description?: string }
}

export default function OrganizationPage({
  auth,
  organization,
  isFav = false,
  posts = [],
  postsCount = 0,
  supportersCount = 0,
  jobsCount = 0,
  supporters = [],
  primarySupporters = [],
  secondarySupporters = [],
  primarySupportersCount = 0,
  secondarySupportersCount = 0,
  peopleYouMayKnow = [],
  trendingOrganizations = [],
  products = [],
  jobs = [],
  events = [],
  eventsCount: eventsCountProp,
  currentPage,
  believePointsEarned = 0,
  believePointsSpent = 0,
  believePointsBalance = 0,
  postFilter = 'organization',
  partnerOrganizationsCount = 0,
  careAlliancePublic,
  careAllianceCampaigns = [],
  careAllianceProfile,
  allianceMembers = [],
  connectedCareAlliances = [],
  membershipJoin = null,
  communityGroups = [],
  canCreateCommunityGroup = false,
  createCommunityGroupUrl = null,
  communityDiscussions = [],
  communityAnnouncements = [],
  canCreateCommunityDiscussion = false,
  canCreateCommunityAnnouncement = false,
  canModerateCommunity = false,
  communityParentType = null,
  communityParentId = null,
  communityReportReasons = {},
  communicationHub = null,
  seo: seoProp,
}: OrganizationPageProps) {
  const { url } = usePage()
  const page = usePage()
  const axios = useAxios()
  const currentPostFilter = postFilter || (page.props as any).postFilter || 'organization'
  // Use organization.is_favorited directly, fallback to isFav prop
  // Convert to boolean explicitly to handle null/undefined
  const isFavorite = Boolean(organization.is_favorited ?? isFav ?? false)
  const [showDonationModal, setShowDonationModal] = useState(false)
  const [showInvitePopup, setShowInvitePopup] = useState(false)
  const [followingStates, setFollowingStates] = useState<Record<number, boolean>>({})
  const [loadingFollow, setLoadingFollow] = useState<Record<number, boolean>>({})

  // Detect which page we're on based on currentPage prop or route - memoize to prevent infinite loops
  const currentPath = useMemo(() => {
    return typeof window !== 'undefined' ? window.location.pathname : url
  }, [url])

  const pageType = useMemo(() => {
    return currentPage || (currentPath.includes('/products') ? 'products' :
                    currentPath.includes('/jobs') ? 'jobs' :
                    currentPath.includes('/events') ? 'events' :
                    currentPath.includes('/about') ? 'about' :
                    currentPath.includes('/contact') ? 'contact' :
                    currentPath.includes('/members') ? 'members' :
                    currentPath.includes('/supporters') ? 'supporters' : null)
  }, [currentPage, currentPath])

  /** Alliance public UI: backend flag and/or URL (props alone can be missing after transforms). */
  const isCareAlliancePublic = Boolean(
    organization?.is_care_alliance_public ??
      (organization as { isCareAlliancePublic?: boolean })?.isCareAlliancePublic ??
      careAlliancePublic?.slug ??
      String(currentPath || url || "").includes("/alliances/")
  )
  /** Slug for /alliances/{slug} — props, then browser path (handles full URLs and Inertia `url`). */
  const alliancePublicSlug = useMemo(() => {
    const fromProps = careAlliancePublic?.slug ?? organization?.registered_organization?.user?.slug
    if (fromProps) {
      return fromProps
    }
    const raw = String(currentPath || url || "").split("?")[0].split("#")[0]
    let pathOnly = raw
    if (raw.includes("://")) {
      try {
        pathOnly = new URL(raw).pathname
      } catch {
        pathOnly = raw
      }
    }
    const m = pathOnly.match(/\/alliances\/([^/]+)/)
    return m ? decodeURIComponent(m[1]) : undefined
  }, [careAlliancePublic?.slug, organization?.registered_organization?.user?.slug, currentPath, url])

  /**
   * Normal org: hide on own profile. Unity Impact Alliance public: show Follow (toggle-favorite) whenever we can resolve
   * the alliance slug — same POST as nonprofit profiles; supporter accounts can follow the hub.
   */
  const showOrgFollowButton =
    (!organization.is_own_organization && !isCareAlliancePublic) ||
    (isCareAlliancePublic && Boolean(alliancePublicSlug))

  const allianceHubOwner = Boolean(isCareAlliancePublic && organization.is_own_organization)

  // Normalize events: backend sends paginator { data, total } or plain array
  const eventsList = useMemo(() => Array.isArray(events) ? events : (events?.data ?? []), [events])
  const eventsCountFromData = useMemo(() => Array.isArray(events) ? events.length : (events?.total ?? eventsList.length), [events, eventsList.length])
  const eventsCount = eventsCountProp ?? eventsCountFromData

  const isSubPage = pageType !== null

  // Determine active tab based on current page - memoize to prevent infinite loops
  const initialTab = useMemo(() => {
    // For unregistered organizations, default to "About" instead of "Community Feed"
    let tab = organization.is_registered ? "Community Feed" : "About"
    if (typeof window !== "undefined") {
      const tabParam = new URLSearchParams(window.location.search).get("tab")
      if (tabParam === "membership" && membershipJoin) {
        tab = "Membership"
      } else if (tabParam === "groups") {
        tab = "Groups"
      } else if (tabParam === "discussion" || tabParam === "announcements" || tabParam === "communication-hub" || tabParam === "communication_hub") {
        tab = "Communication Hub"
      }
    }
    if (pageType === 'products') tab = "Products"
    else if (pageType === 'jobs') tab = "Opportunities"
    else if (pageType === 'events') tab = "Events"
    else if (pageType === 'about') tab = "About"
    else if (pageType === 'contact') tab = "Contact"
    else if (pageType === 'members') tab = "Members"
    else if (pageType === 'supporters') {
      tab = organization.is_care_alliance_public ? "Followers" : "Supporters"
    }

    // Ensure the initial tab is valid for unregistered organizations
    if (!organization.is_registered && tab !== "About" && tab !== "Contact" && tab !== "Supporters") {
      tab = "About"
    }

    return tab
  }, [pageType, organization.is_registered, organization.is_care_alliance_public, membershipJoin])

  const [activeTab, setActiveTab] = useState(initialTab)
  const [postsState, setPostsState] = useState<any[]>(posts)
  const [showReactionPicker, setShowReactionPicker] = useState<number | null>(null)
  const [commentInputs, setCommentInputs] = useState<Record<number | string, string>>({})
  const [showComments, setShowComments] = useState<Record<number, boolean>>({})
  const [isPageLoading, setIsPageLoading] = useState(true)
  const [isGeneratingAbout, setIsGeneratingAbout] = useState(false)
  const [hasAutoGenerated, setHasAutoGenerated] = useState(false)
  const hasAutoGeneratedRef = useRef(false)

  // Update posts state when posts prop changes - use length and IDs to detect actual changes
  const postsKey = useMemo(() => {
    if (!posts || posts.length === 0) return 'empty'
    return posts.map(p => p?.id || '').filter(Boolean).join(',')
  }, [posts])

  useEffect(() => {
    setPostsState(posts)
  }, [postsKey])

  // Set loading to false once component is mounted and data is ready
  useEffect(() => {
    // Set loading to false when component mounts with data
    setIsPageLoading(false)
  }, [organization?.id, currentPage])

  // Navigate to sub-page when tab is clicked
  const handleTabChange = (tabName: string) => {
    // For unregistered organizations, only allow About, Contact, and Supporters tabs
    if (!organization.is_registered && tabName !== "About" && tabName !== "Contact" && tabName !== "Supporters") {
        return
      }

    setActiveTab(tabName)
    setIsPageLoading(true) // Set loading immediately when navigation starts
    const slug = organization.registered_organization?.user?.slug || organization.id

    if (tabName === "Membership" && membershipJoin && !isSubPage) {
      setIsPageLoading(false)
      return
    }

    let routePath = ''
    if (isCareAlliancePublic && alliancePublicSlug) {
      switch (tabName) {
        case "Products":
          routePath = route('alliances.products', alliancePublicSlug)
          break
        case "Opportunities":
          routePath = route('alliances.jobs', alliancePublicSlug)
          break
        case "Events":
          routePath = route('alliances.events', alliancePublicSlug)
          break
        case "About":
          routePath = route('alliances.about', alliancePublicSlug)
          break
        case "Contact":
          routePath = route('alliances.contact', alliancePublicSlug)
          break
        case "Members":
          routePath = route('alliances.members', alliancePublicSlug)
          break
        case "Followers":
          routePath = route('alliances.supporters', alliancePublicSlug)
          break
        case "Membership":
          routePath = `${route('alliances.show', alliancePublicSlug)}?tab=membership`
          break
        case "Groups":
          routePath = `${route('alliances.show', alliancePublicSlug)}?tab=groups`
          break
        case "Communication Hub":
          routePath = `${route('alliances.show', alliancePublicSlug)}?tab=communication-hub`
          break
        case "Discussion":
        case "Announcements":
          routePath = `${route('alliances.show', alliancePublicSlug)}?tab=communication-hub`
          break
        default:
          routePath = route('alliances.show', alliancePublicSlug)
      }
    } else {
      switch (tabName) {
        case "Products":
          routePath = route('organizations.products', slug)
          break
        case "Opportunities":
          routePath = route('organizations.jobs', slug)
          break
        case "Events":
          routePath = route('organizations.events', slug)
          break
        case "About":
          routePath = route('organizations.about', slug)
          break
        case "Contact":
          routePath = route('organizations.contact', slug)
          break
        case "Supporters":
          routePath = route('organizations.supporters', slug)
          break
        case "Membership":
          routePath = `${route('organizations.show', slug)}?tab=membership`
          break
        case "Groups":
          routePath = `${route('organizations.show', slug)}?tab=groups`
          break
        case "Communication Hub":
          routePath = `${route('organizations.show', slug)}?tab=communication-hub`
          break
        case "Discussion":
        case "Announcements":
          routePath = `${route('organizations.show', slug)}?tab=communication-hub`
          break
        default:
          routePath = route('organizations.show', slug)
      }
    }

    router.visit(routePath, {
      preserveState: false,
      preserveScroll: false,
      onStart: () => {
        setIsPageLoading(true)
      },
      onFinish: () => {
        setIsPageLoading(false)
      },
      onError: () => {
        setIsPageLoading(false)
      },
    })
  }

  // Sync activeTab with route on mount - use initialTab directly to avoid infinite loops
  useEffect(() => {
    setActiveTab(initialTab)
  }, [initialTab])

  // Primitives only for effect deps to avoid "Maximum update depth" from object reference churn
  const orgId = organization?.id
  const orgDescription = (typeof organization?.description === 'string' ? organization.description : '') || ''

  // Auto-generate description if missing when About tab is active (ref guards against re-run / reload loop)
  useEffect(() => {
    const storageKey = `org_${orgId}_about_generated`
    const alreadyTriggered = hasAutoGeneratedRef.current || (typeof sessionStorage !== 'undefined' && sessionStorage.getItem(storageKey) === '1')
    const needsDescription = !orgDescription ||
      orgDescription.trim() === '' ||
      orgDescription === 'This organization is listed in our database but has not yet registered for additional features.'

    if (alreadyTriggered || !needsDescription || activeTab !== 'About' || isCareAlliancePublic) return

    hasAutoGeneratedRef.current = true
    if (typeof sessionStorage !== 'undefined') sessionStorage.setItem(storageKey, '1')
    setIsGeneratingAbout(true)
    setHasAutoGenerated(true)

    axios.post(route('organizations.generate-mission', orgId))
      .then(response => {
        if (response.data?.success) {
          router.reload()
        } else {
          hasAutoGeneratedRef.current = false
          if (typeof sessionStorage !== 'undefined') sessionStorage.removeItem(storageKey)
          setIsGeneratingAbout(false)
          setHasAutoGenerated(false)
        }
      })
      .catch(error => {
        console.error('Error generating about:', error)
        hasAutoGeneratedRef.current = false
        if (typeof sessionStorage !== 'undefined') sessionStorage.removeItem(storageKey)
        setIsGeneratingAbout(false)
        setHasAutoGenerated(false)
      })
  }, [activeTab, orgId, orgDescription, isCareAlliancePublic])

  const handleDonateNow = () => {
    if (!organization.is_registered) {
      return
    }
    if (isCareAlliancePublic) {
      return
    }

    if (!auth?.user) {
      router.visit(route("login", { redirect: route("organizations.show", { slug: organization?.registered_organization?.user?.slug || organization.id }) }), {
        replace: true,
      })
    } else {
      setShowDonationModal(true)
    }
  }

  const handleMessageClick = () => {
    if (!auth?.user) {
      router.visit(route("login", { redirect: "/chat" }), { replace: true })
      return
    }

    router.visit("/chat")
  }

  // Reaction configuration
  const reactionConfig = {
    like: { emoji: '👍', icon: ThumbsUp, color: 'text-blue-600' },
    love: { emoji: '❤️', icon: Heart, color: 'text-red-500' },
    care: { emoji: '🤗', icon: Heart, color: 'text-yellow-500' },
    angry: { emoji: '😠', icon: Angry, color: 'text-orange-500' },
    haha: { emoji: '😂', icon: Laugh, color: 'text-yellow-500' },
  }

  // Get CSRF token
  const getCsrfToken = () => {
    const metaToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    return metaToken || ''
  }

  // Handle reaction (like, love, etc.)
  const handleReaction = async (postId: number | string, type: 'like' | 'love' | 'care' | 'angry' | 'haha') => {
    if (!auth?.user) {
      router.visit(route("login"))
      return
    }

    // Skip Facebook posts (they have string IDs starting with 'fb_')
    if (typeof postId === 'string' && postId.startsWith('fb_')) {
      return
    }

    const post = postsState.find(p => p.id === postId)
    const currentReaction = post?.user_reaction

    if (currentReaction?.type === type) {
      // Remove reaction if clicking the same one
      try {
        const token = getCsrfToken()
        const response = await fetch(`/posts/${postId}/reaction`, {
          method: 'DELETE',
          headers: {
            'X-CSRF-TOKEN': token,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'include',
        })

        if (response.ok) {
          setPostsState(prev => prev.map(p => {
            if (p.id === postId) {
              // Remove user's reaction from reactions array
              const updatedReactions = (p.reactions || []).filter((r: any) => r.user_id !== auth?.user?.id)
              return {
                ...p,
                reactions_count: Math.max(0, (p.reactions_count || 0) - 1),
                user_reaction: null,
                reactions: updatedReactions,
              }
            }
            return p
          }))
        }
      } catch (error) {
        console.error('Error removing reaction:', error)
      }
    } else {
      // Add or update reaction
      try {
        const token = getCsrfToken()
        const response = await fetch(`/posts/${postId}/react`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'include',
          body: JSON.stringify({ type }),
        })

        if (response.ok) {
          const data = await response.json()
          setPostsState(prev => prev.map(p => {
            if (p.id === postId) {
              // Update reactions array
              const currentReactions = p.reactions || []
              const existingReactionIndex = currentReactions.findIndex((r: any) => r.user_id === auth?.user?.id)

              let updatedReactions = [...currentReactions]
              if (data.reaction) {
                // Add or update reaction with user data from backend
                const reactionWithUser = {
                  id: data.reaction.id,
                  type: data.reaction.type,
                  user_id: data.reaction.user_id,
                  user: data.reaction.user || (auth?.user ? {
                    id: auth.user.id,
                    name: auth.user.name,
                    image: auth.user.image,
                  } : null),
                }

                if (existingReactionIndex >= 0) {
                  updatedReactions[existingReactionIndex] = reactionWithUser
      } else {
                  updatedReactions.push(reactionWithUser)
                }
              }

              return {
                ...p,
                reactions_count: data.reactions_count || p.reactions_count || 0,
                user_reaction: data.reaction ? {
                  id: data.reaction.id,
                  type: data.reaction.type,
                  user_id: data.reaction.user_id,
                } : null,
                reactions: updatedReactions,
              }
            }
            return p
          }))
        }
      } catch (error) {
        console.error('Error adding reaction:', error)
      }
    }
    setShowReactionPicker(null)
  }

  // Handle comment
  const handleComment = async (postId: number | string) => {
    if (!auth?.user) {
      router.visit(route("login"))
      return
    }

    // Skip Facebook posts (they have string IDs starting with 'fb_')
    if (typeof postId === 'string' && postId.startsWith('fb_')) {
      return
    }

    const comment = commentInputs[postId]?.trim()
    if (!comment) return

    try {
      const token = getCsrfToken()
      const response = await fetch(`/posts/${postId}/comment`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': token,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
        body: JSON.stringify({ content: comment }),
      })

      if (response.ok) {
        const data = await response.json()
        setPostsState(prev => prev.map(p => {
          if (p.id === postId) {
            return {
              ...p,
              comments: [...(p.comments || []), data.comment],
              comments_count: (p.comments_count || 0) + 1,
            }
          }
          return p
        }))
        setCommentInputs(prev => ({ ...prev, [postId]: '' }))
        setShowComments(prev => ({ ...prev, [postId]: true }))
      }
    } catch (error) {
      console.error('Error adding comment:', error)
    }
  }

  // Handle share
  const handleShare = async (postId: number) => {
    const post = postsState.find(p => p.id === postId)
    if (!post) return

    const shareSlug = organization.registered_organization?.user?.slug || organization.id
    const shareUrl = isCareAlliancePublic && alliancePublicSlug
      ? `${window.location.origin}/alliances/${alliancePublicSlug}?post=${postId}`
      : `${window.location.origin}/organizations/${shareSlug}?post=${postId}`
    const shareText = post.content || post.title || 'Check out this post'

    if (navigator.share) {
      try {
        await navigator.share({
          title: organization.name,
          text: shareText,
          url: shareUrl,
        })
      } catch (error) {
        // User cancelled or error occurred
        console.log('Share cancelled or failed')
      }
      } else {
      // Fallback: copy to clipboard
      try {
        await navigator.clipboard.writeText(shareUrl)
        alert('Link copied to clipboard!')
      } catch (error) {
        console.error('Failed to copy link:', error)
      }
    }
  }

  // Format date for "Member since"
  const memberSince = organization.created_at
    ? new Date(organization.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long' })
    : 'N/A'

  // Get organization handle/slug
  const organizationHandle = organization.registered_organization?.user?.slug
    ? `@${organization.registered_organization.user.slug}`
    : `@${organization.name.toLowerCase().replace(/\s+/g, '')}`

  // Get organization image
  const orgImage = organization.registered_organization?.user?.image
    ? `/storage/${organization.registered_organization.user.image}`
    : "/placeholder.svg"

  // Get cover image
  const coverImage = organization.registered_organization?.user?.cover_img
    ? `/storage/${organization.registered_organization.user.cover_img}`
    : null

  // Fallback for user posts
  const userImage = orgImage

  // Get location - include zipcode if available
  const locationParts = [organization.city, organization.state].filter(Boolean)
  const location = locationParts.length > 0
    ? locationParts.join(', ') + (organization.zipcode ? ` ${organization.zipcode}` : '')
    : 'Location not specified'

  const allianceSidebarBlurb = useMemo(() => {
    if (!organization.is_care_alliance_public || !organization.description) {
      return ''
    }
    const raw = String(organization.description)
    const plain = raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
    if (!plain || plain === 'This organization is listed in our database but has not yet registered for additional features.') {
      return ''
    }
    return plain.length > 260 ? `${plain.slice(0, 257)}…` : plain
  }, [organization.is_care_alliance_public, organization.description])

  // Profile tabs - only show About and Contact for unregistered organizations
  const registeredOrgTabs = [
    { name: "Community Feed", count: postsCount || 0 },
    { name: "About", count: null },
    { name: "Communication Hub", count: null },
    { name: "Groups", count: communityGroups.length || null },
    { name: "Events", count: eventsCount },
    { name: "Opportunities", count: jobsCount || 0 },
    { name: "Supporters", count: supportersCount || 0 },
    { name: "Products", count: products?.length || 0 },
    { name: "Contact", count: null },
  ]

  const careAllianceTabs = [
    { name: "Community Feed", count: postsCount || 0 },
    { name: "Members", count: partnerOrganizationsCount || 0 },
    { name: "Followers", count: supportersCount || 0 },
    { name: "About", count: null },
    { name: "Communication Hub", count: null },
    { name: "Groups", count: communityGroups.length || null },
    { name: "Events", count: eventsCount },
    { name: "Opportunities", count: jobsCount || 0 },
    { name: "Products", count: products?.length || 0 },
    { name: "Contact", count: null },
  ]

  const profileTabs = useMemo(() => {
    const base = organization.is_registered
      ? (organization.is_care_alliance_public ? [...careAllianceTabs] : [...registeredOrgTabs])
      : registeredOrgTabs.filter(tab => tab.name === "About" || tab.name === "Contact" || tab.name === "Supporters")

    if (!membershipJoin) {
      return base
    }

    const contactIndex = base.findIndex((tab) => tab.name === "Contact")
    const membershipTab = { name: "Membership", count: null as number | null }
    if (contactIndex >= 0) {
      const next = [...base]
      next.splice(contactIndex, 0, membershipTab)
      return next
    }

    return [...base, membershipTab]
  }, [
    organization.is_registered,
    organization.is_care_alliance_public,
    membershipJoin,
    postsCount,
    eventsCount,
    jobsCount,
    supportersCount,
    products?.length,
    partnerOrganizationsCount,
    communityGroups.length,
  ])

  // Use only dynamic data from backend - no static defaults - memoize to prevent infinite loops
  const peopleToShow = useMemo(() => {
    return peopleYouMayKnow.length > 0 ? peopleYouMayKnow : []
  }, [peopleYouMayKnow])

  const trendingOrgsToShow = useMemo(() => {
    return trendingOrganizations.length > 0 ? trendingOrganizations : []
  }, [trendingOrganizations])

  const navItems = ["Home", "About", "Donate", "Community", "Services", "More"]

  // Initialize following states for people you may know - use stable key
  const peopleToShowKey = useMemo(() => {
    if (!peopleToShow || peopleToShow.length === 0) return 'empty'
    return peopleToShow.map(p => p?.id || '').filter(Boolean).join(',')
  }, [peopleToShow])

  useEffect(() => {
    const initialStates: Record<number, boolean> = {}
    peopleToShow.forEach((person) => {
      if (person.id && typeof person.id === 'number') {
        initialStates[person.id] = false // Default to not following
      }
    })
    setFollowingStates(initialStates)
  }, [peopleToShowKey])

  // Handle follow/unfollow for people you may know
  const handleFollowPerson = async (person: any) => {
    if (!auth?.user) {
      router.visit(route("login"))
      return
    }

    // Use excel_data_id if available, otherwise fall back to id
    const excelDataId = person.excel_data_id || person.id
    if (!excelDataId) return

    const personId = person.id
    setLoadingFollow(prev => ({ ...prev, [personId]: true }))
    let routePath
    try {
      routePath = route("organizations.toggle-favorite", excelDataId)
    } catch {
      routePath = `/organizations/${excelDataId}/toggle-favorite`
    }
    try {
      const res = await axios.post(routePath, { toggle_favorite_context: 'excel' })
      if (res.data?.success !== false) {
        setFollowingStates(prev => ({ ...prev, [personId]: !prev[personId] }))
      }
    } catch (error: any) {
      const msg = error.response?.data?.message || (error.response?.status === 403
        ? 'Following is for supporter accounts only. Please log in with your personal (supporter) account to follow organizations.'
        : 'Could not update follow.')
      toast.error(msg)
    } finally {
      setLoadingFollow(prev => ({ ...prev, [personId]: false }))
    }
  }

  const orgName = organization?.name ?? "Organization"
  const metaDescription = organization?.description ?? organization?.mission ?? undefined

  return (
    <FrontendLayout>
      <PageHead
        title={seoProp?.title ?? orgName}
        description={seoProp?.description ?? (metaDescription ? String(metaDescription).slice(0, 160) : undefined)}
        image={seoProp?.share_image}
      />
        <div className="min-h-screen bg-gray-50 dark:bg-[#0a0f1a] text-gray-900 dark:text-white">

          {/* Profile Banner */}
                  <div className="relative">
            <div
              className="h-32 sm:h-40 bg-gradient-to-r from-purple-900 via-blue-800 to-purple-900"
              style={
                coverImage
                  ? {
                      backgroundImage: `url(${coverImage})`,
                      backgroundSize: 'cover',
                      backgroundPosition: 'center',
                    }
                  : {}
              }
            />
            <div className="max-w-[95rem] mx-auto px-3 sm:px-4">
              <div className="relative -mt-12 sm:-mt-16 md:-mt-20 pb-3 sm:pb-4 flex flex-col gap-3 sm:gap-4">
                {/* Top Row: Avatar and Name */}
                <div className="flex flex-col sm:flex-row items-center sm:items-end gap-3 sm:gap-4">
                  <div className="relative">
                    <Avatar className="w-28 h-28 sm:w-32 sm:h-32 border-4 border-white dark:border-[#0a0f1a] ring-2 ring-green-500/50">
                      <AvatarImage src={orgImage} alt={organization.name} />
                      <AvatarFallback className="bg-gradient-to-br from-gray-600 to-gray-700 text-3xl">
                        {organization.name.charAt(0).toUpperCase()}
                      </AvatarFallback>
                    </Avatar>
                    {organization.is_registered && !organization.is_care_alliance_public && (
                      <div className="absolute -bottom-1 -right-1 w-7 h-7 bg-green-500 rounded-full border-3 border-[#0a0f1a] flex items-center justify-center">
                        <CheckCircle className="w-5 h-5 text-white" />
                      </div>
                    )}
                </div>

                  <div className="text-center sm:text-left flex-1 w-full sm:w-auto">
                    <div className="flex items-center justify-center sm:justify-start gap-2 mb-1">
                      <h1 className="text-2xl sm:text-3xl font-bold break-words text-gray-900 dark:text-white">{organization.name}</h1>
                      <div className="w-6 h-6 bg-gray-600 rounded-full flex items-center justify-center flex-shrink-0">
                        <Building2 className="w-4 h-4" />
                    </div>
                      </div>
                    <p className="text-gray-600 dark:text-gray-400 text-sm mb-1">{organizationHandle}</p>
                    <div className="flex flex-wrap items-center justify-center sm:justify-start gap-2">
                      {organization.is_care_alliance_public && (
                        <Badge className="bg-indigo-600 hover:bg-indigo-600 text-white text-xs px-2 py-0.5 flex items-center gap-1">
                          <Building2 className="w-3 h-3" />
                          Unity Impact Alliance
                        </Badge>
                      )}
                      {organization.is_registered && !organization.is_care_alliance_public && (
                        <Badge className="bg-green-600 hover:bg-green-600 text-white text-xs px-2 py-0.5 flex items-center gap-1">
                          <CheckCircle className="w-3 h-3" />
                          Registered
                        </Badge>
                      )}
                      {organization.is_registered && organization.registered_organization?.user?.email && (
                        <Badge className="bg-green-600 hover:bg-green-600 text-white text-xs px-2 py-0.5 flex items-center gap-1">
                          <ShieldCheck className="w-3 h-3" />
                          Verified
                        </Badge>
                      )}
                      {!organization.is_registered && (
                        <Badge className="bg-orange-500 hover:bg-orange-600 text-white text-xs px-2 py-0.5 flex items-center gap-1">
                          <AlertCircle className="w-3 h-3" />
                          Not Registered
                        </Badge>
                      )}
                    </div>
                  </div>
                </div>

                {/* Action Buttons Row - responsive wrap */}
                <div className="flex flex-wrap items-center justify-center sm:justify-end gap-2 sm:gap-3 w-full">
                {/* Follow — same POST as nonprofits: organizations.toggle-favorite (ExcelData id or hub organizations.id). */}
                {showOrgFollowButton && (
                <OrgFollowButton
                  key={`follow-${organization.is_care_alliance_public ? alliancePublicSlug : organization.id}`}
                  organization={organization}
                  auth={auth}
                  allianceHubOwner={allianceHubOwner}
                  careAlliancePublicId={careAlliancePublic?.id ?? null}
                  initialIsFollowing={isFavorite}
                  initialNotifications={organization.notifications_enabled || false}
                />
                )}
                {membershipJoin && !organization.is_own_organization && (
                  <MembershipJoinButton
                    membershipJoin={membershipJoin}
                    onOpenMembership={() => handleTabChange("Membership")}
                  />
                )}
                {organization.is_registered && !organization.is_own_organization && !organization.is_care_alliance_public && (
                    <>
                    <Button
                        size="sm"
                        className="bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white text-xs sm:text-sm h-9 sm:h-10 px-3 sm:px-4 shrink-0"
                      onClick={handleDonateNow}
                    >
                        <Heart className="w-4 h-4 sm:mr-1.5 mr-2" />
                        <span className="whitespace-nowrap">Donate</span>
                    </Button>
                        <Button
                        size="sm"
                        className="bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white text-xs sm:text-sm h-9 sm:h-10 px-3 sm:px-4 shrink-0"
                        onClick={handleMessageClick}
                        >
                        <MessageCircle className="w-4 h-4 sm:mr-1.5 mr-2" />
                        <span className="whitespace-nowrap">Message</span>
                        </Button>
                    </>
                  )}
                {organization.is_registered && organization.is_own_organization && (
                    <Button
                      size="sm"
                      variant="outline"
                      className="bg-gray-100 dark:bg-white/10 border-gray-300 dark:border-white/20 text-xs sm:text-sm h-9 sm:h-10 px-3 sm:px-4 shrink-0"
                      onClick={handleMessageClick}
                    >
                      <MessageCircle className="w-4 h-4 sm:mr-1.5 mr-2" />
                      <span className="whitespace-nowrap">Message</span>
                    </Button>
                  )}
                {auth?.user && !organization.is_own_organization && (
                    <Link href={
                      (auth.user as any)?.role === 'organization' || (auth.user as any)?.role === 'organization_pending'
                        ? route('organizations.show', (auth.user as any)?.organization?.public_view_slug ?? (auth.user as any)?.slug ?? auth.user.id)
                        : route('users.show', (auth.user as any)?.slug ?? auth.user.id)
                    }>
                      <Button size="sm" variant="outline" className="bg-gray-100 dark:bg-white/10 border-gray-300 dark:border-white/20 text-xs sm:text-sm h-9 sm:h-10 px-3 sm:px-4 shrink-0">
                        <Globe className="w-4 h-4 sm:mr-1.5 mr-2" />
                        <span className="whitespace-nowrap hidden sm:inline">My public view</span>
                        <span className="whitespace-nowrap sm:hidden">My view</span>
                      </Button>
                    </Link>
                  )}
                  {!organization.is_registered && auth?.user && (
                      <Button
                        size="sm"
                        variant="outline"
                      className="bg-gray-100 dark:bg-white/10 hover:bg-gray-200 dark:hover:bg-white/20 border-gray-300 dark:border-white/20 text-xs sm:text-sm h-9 sm:h-10 px-3 sm:px-4 shrink-0"
                      onClick={() => setShowInvitePopup(true)}
                      >
                      <Mail className="w-4 h-4 sm:mr-1.5 mr-2" />
                      <span className="whitespace-nowrap">Invite</span>
                      </Button>
                    )}
                  </div>
              </div>

              {/* Organization setup status — own profile only */}
              {organization.is_own_organization &&
                organization.is_registered &&
                !organization.is_care_alliance_public && (
                  <div className="mb-2 mt-1">
                    <OrganizationSetupStatusBar visible />
                  </div>
                )}

              {/* Profile Stats */}
              <div className="flex flex-wrap items-center justify-center sm:justify-start gap-3 sm:gap-4 md:gap-6 py-3 px-3 sm:px-0 text-sm text-gray-600 dark:text-gray-400 border-t border-gray-200 dark:border-white/10">
                {(organization.is_registered || organization.is_care_alliance_public) && memberSince !== 'N/A' ? (
                  <div className="flex items-center gap-1.5">
                    <Users className="w-4 h-4" />
                    <span className="whitespace-nowrap text-gray-900 dark:text-white">
                      {organization.is_care_alliance_public ? `Alliance since ${memberSince}` : `Member since ${memberSince}`}
                    </span>
                  </div>
                ) : null}
                <div className="flex items-center gap-1.5">
                  <MapPin className="w-4 h-4" />
                  <span className="truncate max-w-[150px] sm:max-w-none text-gray-900 dark:text-white">{location}</span>
                </div>
                {organization.is_own_organization && (
                  <div className="flex items-center gap-1.5">
                    <Zap className="w-4 h-4 text-yellow-500" />
                    <span className="text-gray-900 dark:text-white font-medium">
                      {believePointsBalance >= 1000
                        ? `${(believePointsBalance / 1000).toFixed(1)}K`
                        : believePointsBalance.toLocaleString()}
                    </span>
                    <span className="text-gray-900 dark:text-white">Believe Points</span>
                  </div>
                )}
                <div className="flex items-center gap-1.5">
                  <Users className="w-4 h-4" />
                  <span className="text-gray-900 dark:text-white">
                    {organization.is_care_alliance_public
                      ? `${partnerOrganizationsCount || 0} partner orgs`
                      : `${supportersCount || 0} supporters`}
                  </span>
                </div>
                <div className="flex items-center gap-1.5">
                  <Calendar className="w-4 h-4" />
                  <span className="text-gray-900 dark:text-white">
                    {organization.is_care_alliance_public ? 'Nonprofit network' : 'Organized'}
                  </span>
                </div>
              </div>
          </div>
        </div>

            {/* Main Content */}
          <main className="max-w-[95rem] mx-auto px-3 sm:px-4 py-4 sm:py-6">
            <div className="grid grid-cols-1 lg:grid-cols-12 gap-6">
              {/* Left Sidebar - Public Profile Info */}
              <aside className="lg:col-span-3 space-y-4">
                <div className="bg-white dark:bg-[#111827] rounded-xl p-4 animate-in fade-in slide-in-from-left-4 duration-500">
                  <nav className="space-y-1">
                    {profileTabs.map((tab) => (
                      <button
                        key={tab.name}
                        onClick={() => handleTabChange(tab.name)}
                        className={`w-full flex items-center gap-3 px-3 py-2.5 rounded-lg transition-all text-left ${
                          activeTab === tab.name
                            ? "bg-gradient-to-r from-purple-600 to-blue-600 text-white"
                            : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white"
                        }`}
                      >
                        {tab.name === "Community Feed" && <FileText className="w-5 h-5" />}
                        {tab.name === "About" && <User className="w-5 h-5" />}
                        {tab.name === "Events" && <Calendar className="w-5 h-5" />}
                        {tab.name === "Opportunities" && <Briefcase className="w-5 h-5" />}
                        {tab.name === "Supporters" && <UserPlus className="w-5 h-5" />}
                        {tab.name === "Followers" && <UserPlus className="w-5 h-5" />}
                        {tab.name === "Members" && <UsersRound className="w-5 h-5" />}
                        {tab.name === "Groups" && <UsersRound className="w-5 h-5" />}
                        {tab.name === "Communication Hub" && <MessagesSquare className="w-5 h-5" />}
                        {tab.name === "Membership" && <BadgeCheck className="w-5 h-5" />}
                        {tab.name === "Products" && <ShoppingBag className="w-5 h-5" />}
                        {tab.name === "Contact" && <Phone className="w-5 h-5" />}
                        <span className="text-sm">{tab.name}</span>
                        {tab.count !== null && (
                          <span className="ml-auto text-xs text-gray-500 dark:text-gray-500">({tab.count})</span>
                        )}
                      </button>
                    ))}
                  </nav>
                </div>

                  {organization.is_registered && organization.is_care_alliance_public && (
                  <div className="rounded-xl border border-indigo-500/20 dark:border-indigo-400/15 bg-white dark:bg-[#111827] shadow-sm animate-in fade-in slide-in-from-left-4 duration-500 delay-100 p-4">
                    <p className="text-xs font-medium text-gray-500 dark:text-gray-400 mb-3">Activity snapshot</p>
                    <div className="flex items-center justify-between">
                      {organization.is_own_organization && (
                        <div className="text-center flex-1">
                          <p className="text-lg font-bold text-gray-900 dark:text-white tabular-nums">
                            {believePointsBalance >= 1000
                              ? `${(believePointsBalance / 1000).toFixed(1)}k`
                              : believePointsBalance.toLocaleString()}
                          </p>
                          <p className="text-[10px] text-gray-500 dark:text-gray-500">Network points</p>
                          <div className="mt-1 space-y-0.5">
                            <p className="text-[9px] text-green-400">+{believePointsEarned.toLocaleString()} earned</p>
                            {believePointsSpent > 0 && (
                              <p className="text-[9px] text-red-400">-{believePointsSpent.toLocaleString()} spent</p>
                            )}
                          </div>
                        </div>
                      )}
                      <div
                        className={`text-center flex-1 px-1 ${
                          organization.is_own_organization
                            ? "border-x border-gray-200 dark:border-white/10"
                            : "border-r border-gray-200 dark:border-white/10"
                        }`}
                      >
                        <p className="text-lg font-bold text-gray-900 dark:text-white tabular-nums">{partnerOrganizationsCount || 0}</p>
                        <p className="text-[10px] text-gray-500 dark:text-gray-500">Member orgs</p>
                      </div>
                      <div className="text-center flex-1">
                        <p className="text-lg font-bold text-gray-900 dark:text-white tabular-nums">{postsCount || 0}</p>
                        <p className="text-[10px] text-gray-500 dark:text-gray-500">Feed posts</p>
                      </div>
                    </div>
                    {careAllianceProfile?.ein && (
                      <p className="text-[10px] text-gray-500 dark:text-gray-500 mt-3 pt-3 border-t border-gray-200 dark:border-white/10 text-center">
                        EIN {careAllianceProfile.ein}
                      </p>
                    )}
                  </div>
                )}

                  {organization.is_registered && !organization.is_care_alliance_public && (
                  <div className="bg-white dark:bg-[#111827] rounded-xl p-4 animate-in fade-in slide-in-from-left-4 duration-500 delay-100">
                    <p className="text-xs text-gray-500 dark:text-gray-500 mb-3">Organization Info</p>
                    <div className="flex items-center gap-3 mb-4">
                      <Avatar className="w-10 h-10">
                        <AvatarImage src={orgImage} />
                        <AvatarFallback className="bg-emerald-600 text-sm">
                          {organization.name.charAt(0).toUpperCase()}
                        </AvatarFallback>
                      </Avatar>
                      <div>
                        <p className="text-sm font-medium text-gray-900 dark:text-white">
                          {organization.name}
                          {organization.classification && (
                            <span className="text-gray-600 dark:text-gray-400 font-normal"> - {organization.classification}</span>
                          )}
                        </p>
                        {organization.ntee_code && (
                          <Badge className="bg-emerald-600/20 text-emerald-400 text-[10px] px-1.5 py-0">
                            {organization.ntee_code}
                          </Badge>
                        )}
                      </div>
                    </div>

                    <div className="flex items-center justify-between py-3 border-t border-gray-200 dark:border-white/10">
                      {organization.is_own_organization && (
                        <div className="text-center">
                          <p className="text-lg font-bold text-gray-900 dark:text-white">
                            {believePointsBalance >= 1000
                              ? `${(believePointsBalance / 1000).toFixed(1)}k`
                              : believePointsBalance.toLocaleString()}
                          </p>
                          <p className="text-[10px] text-gray-500 dark:text-gray-500">Believe Points</p>
                          <div className="mt-1 space-y-0.5">
                            <p className="text-[9px] text-green-400">+{believePointsEarned.toLocaleString()} earned</p>
                            {believePointsSpent > 0 && (
                              <p className="text-[9px] text-red-400">-{believePointsSpent.toLocaleString()} spent</p>
                            )}
                          </div>
                        </div>
                      )}
                      <div className="text-center">
                        <p className="text-lg font-bold text-gray-900 dark:text-white">{supportersCount || 0}</p>
                        <p className="text-[10px] text-gray-500 dark:text-gray-500">Supporters</p>
                      </div>
                      <div className="text-center">
                        <p className="text-lg font-bold text-gray-900 dark:text-white">{postsCount || 0}</p>
                        <p className="text-[10px] text-gray-500 dark:text-gray-500">Community Feed</p>
                      </div>
                    </div>
                  </div>
                )}

                {organization.is_registered &&
                  !organization.is_care_alliance_public &&
                  connectedCareAlliances.length > 0 && (
                    <div className="bg-white dark:bg-[#111827] rounded-xl p-4 animate-in fade-in slide-in-from-left-4 duration-500 delay-150 border border-indigo-500/15 dark:border-indigo-400/10">
                      <h3 className="font-semibold mb-1 text-gray-900 dark:text-white flex items-center gap-2">
                        <UsersRound className="w-4 h-4 text-indigo-600 dark:text-indigo-400 shrink-0" />
                        Unity Impact Alliances
                      </h3>
                      <p className="text-xs text-gray-500 dark:text-gray-400 mb-3">
                        This organization is connected to these Unity Impact Alliances.
                      </p>
                      <ul className="space-y-2">
                        {connectedCareAlliances.map((a) => (
                          <li key={a.id}>
                            <Link
                              href={route("alliances.show", a.slug)}
                              className="flex items-center gap-2 text-sm text-indigo-600 dark:text-indigo-400 hover:underline font-medium"
                            >
                              <Building2 className="w-3.5 h-3.5 shrink-0 opacity-80" />
                              <span className="break-words">{a.name}</span>
                              <ExternalLink className="w-3 h-3 shrink-0 opacity-60 ml-auto" />
                            </Link>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}

                {!organization.is_care_alliance_public && organization.mission && organization.mission !== 'Mission statement not available for unregistered organizations.' && (
                  <div className="bg-white dark:bg-[#111827] rounded-xl p-4 animate-in fade-in slide-in-from-left-4 duration-500 delay-200">
                    <h3 className="font-semibold mb-3 text-gray-900 dark:text-white">Mission</h3>
                    <p className="text-sm text-gray-700 dark:text-gray-300 leading-relaxed">{organization.mission}</p>
                  </div>
                )}
              </aside>

              {/* Main Feed */}
              <section className="lg:col-span-6 space-y-4">
                {isPageLoading ? (
                  <div className="bg-white dark:bg-[#111827] rounded-xl p-8 flex items-center justify-center min-h-[400px]">
                      <div className="text-center">
                        <div className="w-12 h-12 border-4 border-purple-600 border-t-transparent rounded-full animate-spin mx-auto mb-4"></div>
                        <p className="text-gray-600 dark:text-gray-400">Loading content...</p>
                    </div>
                  </div>
                ) : (
                  <>
                    {membershipJoin && !organization.is_own_organization && activeTab !== "Membership" && (
                      <div className="rounded-xl border border-indigo-200 bg-indigo-50 p-4 dark:border-indigo-800 dark:bg-indigo-950/30">
                        <MembershipJoinCard membershipJoin={membershipJoin} compact />
                      </div>
                    )}

                    {/* Community Feed - Only for registered organizations */}
                    {activeTab === "Community Feed" && organization.is_registered && (
                  <div className="space-y-4">
                    {/* Post Filter Tabs */}
                    <div className="flex items-center gap-2 border-b border-gray-200 dark:border-white/10 pb-2 mb-4">
                      <button
                        onClick={() => {
                          const slug = organization.registered_organization?.user?.slug || organization.id;
                          const feedUrl = isCareAlliancePublic && alliancePublicSlug
                            ? route('alliances.show', alliancePublicSlug)
                            : route('organizations.show', slug);
                          router.get(feedUrl, { filter: 'organization' }, {
                            preserveState: true,
                            preserveScroll: true,
                            only: ['posts', 'postFilter'],
                          });
                        }}
                        className={`px-4 py-2 text-sm font-medium transition-all border-b-2 ${
                          currentPostFilter === 'organization'
                            ? 'border-purple-600 text-purple-600 dark:text-purple-400'
                            : 'border-transparent text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'
                        }`}
                      >
                        Organization Posts
                      </button>
                      <button
                        onClick={() => {
                          const slug = organization.registered_organization?.user?.slug || organization.id;
                          const feedUrl = isCareAlliancePublic && alliancePublicSlug
                            ? route('alliances.show', alliancePublicSlug)
                            : route('organizations.show', slug);
                          router.get(feedUrl, { filter: 'all' }, {
                            preserveState: true,
                            preserveScroll: true,
                            only: ['posts', 'postFilter'],
                          });
                        }}
                        className={`px-4 py-2 text-sm font-medium transition-all border-b-2 ${
                          currentPostFilter === 'all'
                            ? 'border-purple-600 text-purple-600 dark:text-purple-400'
                            : 'border-transparent text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white'
                        }`}
                      >
                        All Posts
                      </button>
                    </div>
                    {postsState.length > 0 ? (
                      postsState.map((postItem, index) => {
                        const postId = postItem.id
                        const currentReaction = postItem.user_reaction
                        const reactionsCount = postItem.reactions_count || postItem.likes_count || 0
                        const commentsCount = postItem.comments_count || 0
                        const postComments = postItem.comments || []
                        const allianceFeed = Boolean(organization.is_care_alliance_public)
                        const feedAuthorName = allianceFeed ? organization.name : (postItem.creator_name || organization.name)
                        const feedAuthorHref = allianceFeed && alliancePublicSlug
                          ? route('alliances.show', alliancePublicSlug)
                          : postItem.creator_slug
                            ? (postItem.creator_type === 'organization'
                              ? route('organizations.show', postItem.creator_slug)
                              : route('users.show', postItem.creator_slug))
                            : null
                        const feedAvatarSrc = allianceFeed
                          ? orgImage
                          : (postItem.creator_image || (postItem.creator_type === 'organization' ? orgImage : userImage))
                        const feedSubline = allianceFeed
                          ? organizationHandle
                          : (postItem.creator_type === 'organization' ? organizationHandle : `@${postItem.creator_slug || postItem.user?.slug || ''}`)
                        // Use a unique key that combines post ID with creator info to ensure uniqueness
                        const creatorId = postItem.creator?.id || postItem.user?.id || 'unknown'
                        const uniqueKey = postId?.toString().startsWith('fb_')
                          ? postId
                          : `post_${postId}_${creatorId}_${index}`

                        return (
                          <article
                            key={uniqueKey}
                            className="bg-white dark:bg-[#111827] rounded-xl overflow-hidden animate-in fade-in slide-in-from-bottom-4 duration-500 delay-100"
                          >
                            <div className="p-5">
                              {/* Header */}
                              <div className="flex items-start justify-between mb-4">
                                <div className="flex items-center gap-3">
                                  <Avatar className="w-12 h-12">
                                    <AvatarImage src={feedAvatarSrc} />
                                    <AvatarFallback className="bg-gradient-to-br from-teal-500 to-cyan-500 text-lg">
                                      {feedAuthorName.charAt(0).toUpperCase()}
                                    </AvatarFallback>
                                  </Avatar>
                                  <div>
                                    <div className="flex items-center gap-2">
                                      {feedAuthorHref ? (
                                        <Link
                                          href={feedAuthorHref}
                                          className="font-semibold text-base text-gray-900 dark:text-white hover:underline"
                                        >
                                          {feedAuthorName}
                                        </Link>
                                      ) : (
                                        <h4 className="font-semibold text-base text-gray-900 dark:text-white">
                                          {feedAuthorName}
                                        </h4>
                                      )}
                                      {allianceFeed && organization.is_registered && (
                                        <Badge className="bg-indigo-600/90 text-white text-[10px] px-1.5 py-0 border-0">Unity Impact Alliance</Badge>
                                      )}
                                      {!allianceFeed && postItem.creator_type === 'organization' && organization.is_registered && (
                                        <CheckCircle className="w-4 h-4 text-blue-400" />
                                      )}
                                    </div>
                                    <p className="text-sm text-gray-600 dark:text-gray-400">
                                      {feedSubline}
                                    </p>
                                  </div>
                                </div>
                                <MoreHorizontal className="w-5 h-5 text-gray-500 dark:text-gray-500 cursor-pointer hover:text-gray-700 dark:hover:text-gray-300" />
                              </div>

                              {/* Title */}
                              {postItem.title && (
                                <h3 className="text-xl font-bold mb-4 text-gray-900 dark:text-white">{postItem.title}</h3>
                              )}

                              {/* Content Text - Now on top */}
                              {postItem.content && (
                                <p className="text-gray-700 dark:text-gray-300 text-base mb-5 leading-relaxed whitespace-pre-wrap">
                                  {postItem.content}
                                </p>
                              )}

                              {/* Main Image */}
                              {(postItem.image || (postItem.images && postItem.images.length > 0)) && (
                                <div className="mb-5 rounded-lg overflow-hidden">
                                  {(() => {
                                    // Handle both single image and array of images
                                    const imageUrl = postItem.image || (postItem.images && postItem.images[0]);
                                    if (!imageUrl) return null;

                                    // Check if it's already a full URL or needs /storage/ prefix
                                    const src = imageUrl.startsWith('http') || imageUrl.startsWith('/storage/') || imageUrl.startsWith('/')
                                      ? imageUrl
                                      : `/storage/${imageUrl}`;

                                    return (
                                      <img
                                        src={src}
                                        alt={postItem.title || 'Post image'}
                                        className="w-full h-auto object-cover"
                                        onError={(e) => {
                                          // Fallback if image fails to load
                                          e.currentTarget.style.display = 'none';
                                        }}
                                      />
                                    );
                                  })()}
                                </div>
                              )}

                              {/* Show multiple images if available */}
                              {postItem.images && postItem.images.length > 1 && (
                                <div className="grid grid-cols-2 gap-3 mb-5">
                                  {postItem.images.slice(1, 5).map((img: string, idx: number) => {
                                    const src = img.startsWith('http') || img.startsWith('/storage/') || img.startsWith('/')
                                      ? img
                                      : `/storage/${img}`;
                                    return (
                                      <div key={idx} className="rounded-lg overflow-hidden">
                                        <img
                                          src={src}
                                          alt={`${postItem.title || 'Post'} image ${idx + 2}`}
                                          className="w-full h-56 object-cover"
                                          onError={(e) => {
                                            e.currentTarget.style.display = 'none';
                                          }}
                                        />
                                      </div>
                                    );
                                  })}
                                </div>
                              )}

                              {/* Event Card Style for Posts with Event Info */}
                              {postItem.event_date && (
                                <div className="bg-gradient-to-br from-gray-100 to-gray-200 dark:from-[#1a2744] dark:to-[#0f1a2e] rounded-lg overflow-hidden border border-gray-200 dark:border-white/10 mb-5 p-4">
                                  <div className="flex flex-wrap gap-4 text-sm">
                                    <div className="flex items-center gap-2">
                                      <CheckCircle className="w-4 h-4 text-green-500" />
                                      <span className="text-gray-900 dark:text-white">{postItem.event_date}</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                      <Clock className="w-4 h-4 text-cyan-400" />
                                      <span className="text-gray-900 dark:text-white">{postItem.event_time || '2:00 PM'}</span>
                                    </div>
                                    {postItem.event_location && (
                                      <div className="flex items-center gap-2 text-green-600 dark:text-green-400">
                                        <MapPin className="w-4 h-4" />
                                        <span className="text-gray-900 dark:text-white">{postItem.event_location}</span>
                                      </div>
                                    )}
                                  </div>
                                </div>
                              )}
                            </div>

                            {/* Engagement */}
                            {reactionsCount > 0 || commentsCount > 0 ? (
                              <div className="px-5 py-3 border-t border-gray-200 dark:border-white/10">
                                <div className="flex items-center justify-between text-sm text-gray-600 dark:text-gray-400">
                                  {reactionsCount > 0 && (
                                    <div className="flex items-center gap-2">
                                      {/* Show user avatars with their reaction emojis */}
                                      {(() => {
                                        const reactions = postItem.reactions || []

                                        if (reactions.length > 0) {
                                          return (
                                            <div className="flex items-center gap-1.5">
                                              {/* Show user avatars with reaction emojis */}
                                              <div className="flex items-center -space-x-1">
                                                {reactions.slice(0, 6).map((reaction: any, idx: number) => (
                                                  <div
                                                    key={reaction.id || idx}
                                                    className="relative group"
                                                    title={organization.is_care_alliance_public ? `Reaction: ${reaction.type}` : (reaction.user?.name ? `${reaction.user.name} reacted with ${reaction.type}` : 'User')}
                                                  >
                                                    <Avatar className="w-6 h-6 border-2 border-[#111827] hover:z-10 transition-all hover:scale-110">
                                                      <AvatarImage
                                                        src={organization.is_care_alliance_public ? undefined : (reaction.user?.image ? (reaction.user.image.startsWith('http') || reaction.user.image.startsWith('/storage/') || reaction.user.image.startsWith('/')
                                                          ? reaction.user.image
                                                          : `/storage/${reaction.user.image}`) : undefined)}
                                                      />
                                                      <AvatarFallback className="bg-gradient-to-br from-purple-500 to-blue-500 text-xs">
                                                        {organization.is_care_alliance_public ? '•' : (reaction.user?.name?.charAt(0).toUpperCase() || 'U')}
                                                      </AvatarFallback>
                                                    </Avatar>
                                                    {/* Show reaction emoji on hover or as overlay */}
                                                    <div className="absolute -bottom-1 -right-1 w-4 h-4 bg-white dark:bg-[#111827] rounded-full border border-gray-200 dark:border-white/20 flex items-center justify-center text-xs">
                                                      {reactionConfig[reaction.type as keyof typeof reactionConfig]?.emoji || '👍'}
                                                    </div>
                                                  </div>
                                                ))}
                                                {reactions.length > 6 && (
                                                  <div className="w-6 h-6 rounded-full border-2 border-white dark:border-[#111827] bg-gray-100 dark:bg-white/10 flex items-center justify-center text-xs font-semibold">
                                                    +{reactions.length - 6}
                                                  </div>
                                                )}
                                              </div>
                                              {/* Show reaction type emojis (grouped) */}
                                              <div className="flex items-center gap-0.5">
                                                {(() => {
                                                  // Group reactions by type to show unique emojis
                                                  const reactionGroups = reactions.reduce((acc: any, r: any) => {
                                                    if (r && r.type) {
                                                      if (!acc[r.type]) {
                                                        acc[r.type] = true
                                                      }
                                                    }
                                                    return acc
                                                  }, {})

                                                  return Object.keys(reactionGroups).slice(0, 4).map((type) => (
                                                    <span
                                                      key={type}
                                                      className="text-base"
                                                      title={type}
                                                    >
                                                      {reactionConfig[type as keyof typeof reactionConfig]?.emoji || '👍'}
                                                    </span>
                                                  ))
                                                })()}
                                              </div>
                                            </div>
                                          )
                                        }

                                        // Fallback: if no reactions data but count > 0, show reaction emojis only
                                        return (
                                          <div className="flex items-center gap-1.5">
                                            <span className="text-base">👍</span>
                                          </div>
                                        )
                                      })()}
                                    </div>
                                  )}
                                  {commentsCount > 0 && (
                                    <button
                                      onClick={() => setShowComments(prev => ({ ...prev, [postId]: !prev[postId] }))}
                                      className="flex items-center gap-1 hover:text-gray-900 dark:hover:text-white transition-colors"
                                    >
                                      <MessageCircle className="w-4 h-4" /> {commentsCount} {commentsCount === 1 ? 'Comment' : 'Comments'}
                                    </button>
                                  )}
                                </div>
                              </div>
                            ) : null}

                            {/* Action Buttons */}
                            <div className="grid grid-cols-3 border-t border-gray-200 dark:border-white/10">
                              <div
                                className="relative flex-1"
                                onMouseEnter={() => setShowReactionPicker(postId)}
                                onMouseLeave={() => setShowReactionPicker(null)}
                              >
                                <button
                                  onClick={() => {
                                    if (typeof postId === 'string' && postId.startsWith('fb_')) {
                                      return
                                    }
                                    if (currentReaction) {
                                      handleReaction(postId, currentReaction.type)
                                    } else {
                                      handleReaction(postId, 'like')
                                    }
                                  }}
                                  disabled={typeof postId === 'string' && postId.startsWith('fb_')}
                                  className={`w-full flex items-center justify-center gap-2 py-3 transition-all hover:bg-gray-100 dark:hover:bg-white/5 disabled:opacity-50 disabled:cursor-not-allowed ${
                                    currentReaction ? reactionConfig[currentReaction.type as keyof typeof reactionConfig]?.color || 'text-blue-500' : 'text-gray-600 dark:text-gray-400'
                                  }`}
                                >
                                  {currentReaction ? (
                                    <>
                                      <span className="text-lg">{reactionConfig[currentReaction.type as keyof typeof reactionConfig]?.emoji}</span>
                                      <span className="text-sm font-medium capitalize text-gray-900 dark:text-white">{currentReaction.type}</span>
                                    </>
                                  ) : (
                                    <>
                                      <ThumbsUp className="w-5 h-5" />
                                      <span className="text-sm font-medium text-gray-900 dark:text-white">Like</span>
                    </>
                  )}
                                </button>
                                <AnimatePresence>
                                  {showReactionPicker === postId && (
                                    <motion.div
                                      initial={{ opacity: 0, y: 10 }}
                                      animate={{ opacity: 1, y: 0 }}
                                      exit={{ opacity: 0, y: 10 }}
                                      className="absolute bottom-full left-0 mb-2 bg-white dark:bg-[#1a1a2e] rounded-full p-1 flex items-center gap-1 shadow-lg border border-gray-200 dark:border-white/10 z-10"
                                    >
                                      {Object.entries(reactionConfig).map(([type, config]) => (
                                        <motion.button
                                          key={type}
                                          whileHover={{ scale: 1.2 }}
                                          whileTap={{ scale: 0.9 }}
                                          onClick={() => {
                                            if (typeof postId === 'string' && postId.startsWith('fb_')) {
                                              return
                                            }
                                            handleReaction(postId, type as any)
                                          }}
                                          disabled={typeof postId === 'string' && postId.startsWith('fb_')}
                                          className="text-2xl transition-all p-2 rounded-full hover:bg-gray-100 dark:hover:bg-white/10 cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                          title={type.charAt(0).toUpperCase() + type.slice(1)}
                                        >
                                          {config.emoji}
                                        </motion.button>
                                      ))}
                                    </motion.div>
                                  )}
                                </AnimatePresence>
                </div>
                              <button
                                onClick={() => setShowComments(prev => ({ ...prev, [postId]: !prev[postId] }))}
                                className="flex items-center justify-center gap-2 py-3 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white transition-all"
                              >
                                <MessageCircle className="w-5 h-5" />
                                <span className="text-sm font-medium text-gray-900 dark:text-white">Comment</span>
                              </button>
                              <button
                                onClick={() => handleShare(postId)}
                                className="flex items-center justify-center gap-2 py-3 text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-white/5 hover:text-gray-900 dark:hover:text-white transition-all"
                              >
                                <Share2 className="w-5 h-5" />
                                <span className="text-sm font-medium text-gray-900 dark:text-white">Share</span>
                              </button>
              </div>

                            {/* Comments Section */}
                            {showComments[postId] && (
                              <div className="border-t border-gray-200 dark:border-white/10 p-4 space-y-4">
                                {/* Comments List */}
                                {postComments.length > 0 && (
                                  <div className="space-y-3">
                                    {postComments.map((comment: any) => (
                                      <div key={comment.id} className="flex gap-3">
                                        <Avatar className="w-8 h-8">
                                          <AvatarImage src={organization.is_care_alliance_public ? undefined : (comment.user?.image ? `/storage/${comment.user.image}` : undefined)} />
                                          <AvatarFallback className="bg-gradient-to-br from-purple-500 to-blue-500 text-xs">
                                            {organization.is_care_alliance_public ? '+' : (comment.user?.name?.charAt(0).toUpperCase() || 'U')}
                                          </AvatarFallback>
                                        </Avatar>
                                        <div className="flex-1">
                                          <div className="bg-gray-50 dark:bg-white/5 rounded-lg p-3">
                                            <p className="text-sm font-semibold mb-1 text-gray-900 dark:text-white">
                                              {organization.is_care_alliance_public ? 'Supporter' : (comment.user?.name || 'Anonymous')}
                                            </p>
                                            <p className="text-sm text-gray-700 dark:text-gray-300">{comment.content}</p>
                                          </div>
                                          <p className="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                            {new Date(comment.created_at).toLocaleDateString()}
                                          </p>
                                        </div>
                                      </div>
                                    ))}
                                  </div>
                                )}

                                {/* Comment Input */}
                                {auth?.user && (
                                  <div className="relative">
                                    <Textarea
                                      placeholder="Write a comment..."
                                      value={commentInputs[postId] || ''}
                                      onChange={(e) => setCommentInputs(prev => ({ ...prev, [postId]: e.target.value }))}
                                      onKeyDown={(e) => {
                                        if (e.key === 'Enter' && !e.shiftKey) {
                                          e.preventDefault()
                                          handleComment(postId)
                                        }
                                      }}
                                      className="pr-12 rounded-lg bg-gray-50 dark:bg-white/5 border-gray-200 dark:border-white/10 focus-visible:ring-2 focus-visible:ring-purple-500 resize-none text-gray-900 dark:text-white placeholder:text-gray-500 dark:placeholder:text-gray-500"
                                      rows={2}
                                    />
                                    {commentInputs[postId]?.trim() && (
                          <Button
                                        onClick={() => handleComment(postId)}
                            size="sm"
                                        className="absolute right-2 bottom-2 h-8 w-8 p-0 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700"
                                      >
                                        <Send className="w-4 h-4" />
                                      </Button>
                                    )}
                                  </div>
                                )}
                                {!auth?.user && (
                                  <p className="text-sm text-gray-600 dark:text-gray-400 text-center py-2">
                                    <Link href={route("login")} className="text-purple-400 hover:text-purple-300">
                                      Log in
                                    </Link> to comment
                                  </p>
                                )}
                              </div>
                            )}
                          </article>
                        )
                      })
                    ) : (
                      <article className="bg-white dark:bg-[#111827] rounded-xl p-12 text-center animate-in fade-in slide-in-from-bottom-4 duration-500">
                        <p className="text-gray-600 dark:text-gray-400">No posts available yet.</p>
                      </article>
                    )}
                  </div>
                )}

                {/* About Tab Content */}
                {activeTab === "About" && (
                  <div className="bg-white dark:bg-[#111827] rounded-xl p-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
                    <div className="flex items-start justify-between mb-4">
                      <div className="flex-1">
                        <h2 className="text-2xl font-bold mb-2 text-gray-900 dark:text-white">About</h2>
                        <h3 className="text-xl font-bold bg-gradient-to-r from-purple-400 to-blue-400 bg-clip-text text-transparent">{organization.name}</h3>
                        {/* Auto-generated note - show for unregistered orgs or if we just generated it */}
                        {(!organization.is_registered || hasAutoGenerated) && organization.description &&
                          organization.description.trim() !== '' &&
                          organization.description !== 'This organization is listed in our database but has not yet registered for additional features.' && (
                          <div className="mt-3 p-3 bg-gradient-to-r from-blue-50 to-purple-50 dark:from-blue-900/20 dark:to-purple-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                            <p className="text-sm text-blue-700 dark:text-blue-300 flex items-start">
                              <AlertCircle className="w-4 h-4 mr-2 mt-0.5 flex-shrink-0 text-blue-500 dark:text-blue-400" />
                              <span>✨ Hey there! We've created this summary using AI to help you discover more about this amazing organization. While they haven't completed their profile yet, we wanted to make sure you have helpful information to explore! If you know someone from this organization, we'd love your help inviting them to join our community. As a thank you for helping us grow together, we'll reward you with special points! Let's build something beautiful together! 🌟💙</span>
                            </p>
                          </div>
                        )}
                      </div>
                    </div>

                    {/* Loading state while generating */}
                    {isGeneratingAbout && (!organization.description ||
                      organization.description.trim() === '' ||
                      organization.description === 'This organization is listed in our database but has not yet registered for additional features.') && (
                      <div className="flex flex-col items-center justify-center py-12">
                        <div className="w-12 h-12 border-4 border-purple-600 border-t-transparent rounded-full animate-spin mb-4"></div>
                        <p className="text-gray-600 dark:text-gray-400 text-lg font-medium">Generating organization summary with AI...</p>
                        <p className="text-gray-500 dark:text-gray-500 text-sm mt-2">This may take a few moments</p>
                      </div>
                    )}

                    {/* Description content */}
                    {organization.description &&
                      organization.description.trim() !== '' &&
                      organization.description !== 'This organization is listed in our database but has not yet registered for additional features.' && (
                      <div className="text-gray-700 dark:text-gray-300 mb-4 leading-relaxed">
                        {organization.description.split('\n').map((paragraph: string, index: number) => {
                          const trimmedParagraph = paragraph.trim();
                          if (!trimmedParagraph) return null;
                          return (
                            <p key={index} className="mb-4 last:mb-0">
                              {trimmedParagraph}
                            </p>
                          );
                        })}
                      </div>
                    )}
                      {!organization.is_care_alliance_public && organization.mission && organization.mission !== 'Mission statement not available for unregistered organizations.' && (
                      <div className="mb-4">
                        <h3 className="text-lg font-semibold mb-2 text-gray-900 dark:text-white">Mission</h3>
                        <p className="text-gray-700 dark:text-gray-300 leading-relaxed">{organization.mission}</p>
                        </div>
                      )}
                    {organization.website && (
                          <div className="flex items-center gap-2">
                        <Globe className="w-5 h-5 text-gray-500 dark:text-gray-400" />
                              <a
                                href={organization.website.startsWith('http') ? organization.website : `https://${organization.website}`}
                                target="_blank"
                                rel="noopener noreferrer"
                            className="text-emerald-400 hover:underline"
                              >
                                {organization.website.replace(/^https?:\/\//, '')}
                              </a>
                        </div>
                      )}
                    {organization.wefunder_project_url && (
                          <div className="flex items-center gap-2 mt-2">
                        <TrendingUp className="w-5 h-5 text-gray-500 dark:text-gray-400" />
                              <a
                                href={organization.wefunder_project_url.startsWith('http') ? organization.wefunder_project_url : `https://${organization.wefunder_project_url}`}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-emerald-400 hover:underline"
                              >
                                Invest on Wefunder
                              </a>
                        </div>
                      )}
                </div>
                )}

                {/* Products Tab Content - Only for registered organizations */}
                {activeTab === "Products" && organization.is_registered && (
                  <div className="bg-white dark:bg-[#111827] rounded-xl p-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
                    <div className="flex items-center justify-between mb-6">
                      <h2 className="text-2xl font-bold flex items-center gap-2 text-gray-900 dark:text-white">
                        <ShoppingBag className="w-6 h-6" />
                        Products
                      </h2>
                      <Badge className="bg-gradient-to-r from-purple-600 to-blue-600 text-white">
                        {products?.length || 0} Products
                      </Badge>
                    </div>
                    {products && products.length > 0 ? (
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {products.map((product: any) => (
                          <div
                            key={product.id}
                            className="bg-gray-50 dark:bg-[#0a0f1a] rounded-lg p-4 border border-gray-200 dark:border-white/10 hover:border-purple-500/50 transition-all"
                          >
                            {product.image && (
                              <div className="mb-3 rounded-lg overflow-hidden">
                                <img
                                  src={product.image.startsWith('http') ? product.image : `/storage/${product.image}`}
                                  alt={product.name}
                                  className="w-full h-48 object-cover"
                                />
                              </div>
                            )}
                            <h3 className="text-lg font-semibold mb-2 text-gray-900 dark:text-white">{product.name}</h3>
                            {product.description && (
                              <p className="text-gray-600 dark:text-gray-400 text-sm mb-3 line-clamp-2">{product.description}</p>
                            )}
                            {product.price && (
                              <div className="flex items-center justify-between">
                                <span className="text-xl font-bold text-purple-600 dark:text-purple-400">${product.price}</span>
                                <Link href={route("product.show", product.id)}>
                                  <Button className="bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white text-sm">
                                    View Details
                                  </Button>
                    </Link>
                              </div>
                    )}
                  </div>
                        ))}
                </div>
                    ) : (
                      <div className="text-center py-12">
                        <ShoppingBag className="w-16 h-16 text-gray-600 mx-auto mb-4" />
                        <p className="text-gray-600 dark:text-gray-400">No products available yet.</p>
              </div>
                    )}
                    {isCareAlliancePublic && alliancePublicSlug && careAllianceCampaigns.length > 0 && (
                      <div className="mt-8 pt-8 border-t border-gray-200 dark:border-white/10">
                        <h3 className="text-lg font-semibold text-gray-900 dark:text-white mb-4">Alliance campaigns</h3>
                        <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                          {careAllianceCampaigns.map((c) => (
                            <Link
                              key={c.id}
                              href={route('care-alliance.campaigns.donate', { allianceSlug: alliancePublicSlug, campaign: c.slug })}
                              className="block rounded-lg border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-[#0a0f1a] p-4 hover:border-purple-500/50 transition-all"
                            >
                              <p className="font-medium text-gray-900 dark:text-white">{c.name}</p>
                              {c.description && (
                                <p className="text-sm text-gray-600 dark:text-gray-400 mt-1 line-clamp-2">{c.description}</p>
                              )}
                              <span className="text-sm text-purple-600 dark:text-purple-400 mt-2 inline-block">Donate →</span>
                            </Link>
                          ))}
                        </div>
                      </div>
                    )}
            </div>
                )}

                {/* Opportunities Tab Content */}
                {activeTab === "Opportunities" && (
                  <div className="bg-white dark:bg-[#111827] rounded-xl p-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
                    <div className="flex items-center justify-between mb-6">
                      <h2 className="text-2xl font-bold flex items-center gap-2 text-gray-900 dark:text-white">
                        <Briefcase className="w-6 h-6" />
                        Opportunities
                      </h2>
                      <Badge className="bg-gradient-to-r from-purple-600 to-blue-600 text-white">
                        {jobs?.length || 0} Openings
                      </Badge>
                    </div>
                    {jobs && jobs.length > 0 ? (
                      <div className="space-y-4">
                        {jobs.map((job: any) => (
                          <div
                            key={job.id}
                            className="bg-gray-50 dark:bg-[#0a0f1a] rounded-lg p-5 border border-gray-200 dark:border-white/10 hover:border-purple-500/50 transition-all"
                          >
                            <div className="flex items-start justify-between mb-3">
                              <div className="flex-1">
                                <h3 className="text-xl font-semibold mb-2 text-gray-900 dark:text-white">{job.title}</h3>
                                <div className="flex flex-wrap items-center gap-3 text-sm text-gray-600 dark:text-gray-400 mb-3">
                                  {job.location && (
                                    <div className="flex items-center gap-1">
                                      <MapPin className="w-4 h-4" />
                                      <span className="text-gray-900 dark:text-white">{job.location}</span>
                                    </div>
                                  )}
                                  {job.type && (
                                    <Badge className="bg-purple-600/20 text-purple-400 text-xs">
                                      {job.type}
                                    </Badge>
                                  )}
                                  {job.salary && (
                                    <span className="text-green-600 dark:text-green-400 font-medium">${job.salary}</span>
                                  )}
                                </div>
                                {job.description && (
                                  <p className="text-gray-700 dark:text-gray-300 text-sm mb-4 line-clamp-3">{job.description}</p>
                                )}
                              </div>
                            </div>
                            <div className="flex items-center gap-3">
                              {auth?.user?.role === 'user' && (job.status === 'open' || !job.status) && !job.has_applied ? (
                                <Link href={route("jobs.apply.show", job.id)}>
                                  <Button className="bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white">
                                    Apply Now
                                  </Button>
                                </Link>
                              ) : (
                        <Button
                                  disabled
                                  className="bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white opacity-50 cursor-not-allowed"
                                >
                                  {job.has_applied ? 'Already Applied' : (job.status === 'open' || !job.status) ? (auth?.user ? 'Not Available' : 'Sign in to Apply') : 'Not Available'}
                        </Button>
                              )}
                              <Link href={route("jobs.show", job.id)}>
                                <Button variant="outline" className="bg-transparent border-gray-300 dark:border-white/20 hover:bg-gray-100 dark:hover:bg-white/10 text-gray-900 dark:text-white">
                                  View Details
                                </Button>
                              </Link>
                            </div>
                          </div>
                      ))}
                    </div>
                    ) : (
                      <div className="text-center py-12">
                        <Briefcase className="w-16 h-16 text-gray-600 mx-auto mb-4" />
                        <p className="text-gray-600 dark:text-gray-400">No job openings available at the moment.</p>
                      </div>
                    )}
                  </div>
                )}

                {/* Events Tab Content - Only for registered organizations */}
                {activeTab === "Events" && organization.is_registered && (
                  <div className="bg-white dark:bg-[#111827] rounded-xl p-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
                    <div className="flex items-center justify-between mb-6">
                      <h2 className="text-2xl font-bold flex items-center gap-2 text-gray-900 dark:text-white">
                        <Calendar className="w-6 h-6" />
                        Events
                      </h2>
                      <Badge className="bg-gradient-to-r from-purple-600 to-blue-600 text-white">
                        {eventsCount} Events
                      </Badge>
                    </div>
                    {eventsList.length > 0 ? (
                      <div className="space-y-4">
                        {eventsList.map((event: any) => {
                          const startDt = event.start_date ? new Date(event.start_date) : null
                          const endDt = event.end_date ? new Date(event.end_date) : null
                          const formatDate = (d: Date) => d.toLocaleDateString('en-US', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' })
                          const formatTime = (d: Date) => d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })
                          const startDateStr = startDt ? formatDate(startDt) : null
                          const startTimeStr = startDt ? (event.start_time || formatTime(startDt)) : null
                          const endDateStr = endDt ? formatDate(endDt) : null
                          const endTimeStr = endDt ? (event.end_time || formatTime(endDt)) : null
                          return (
                          <div
                            key={event.id}
                            className="bg-[#0a0f1a] rounded-lg p-5 border border-white/10 hover:border-purple-500/50 transition-all"
                          >
                            <div className="flex items-start justify-between mb-3">
                              <div className="flex-1">
                                <h3 className="text-xl font-semibold mb-2 text-gray-900 dark:text-white">{event.title || event.name}</h3>
                                <div className="flex flex-wrap items-center gap-3 text-sm text-gray-600 dark:text-gray-400 mb-2">
                                  {event.location && (
                                    <div className="flex items-center gap-1 text-green-600 dark:text-green-400">
                                      <MapPin className="w-4 h-4" />
                                      <span className="text-gray-900 dark:text-white">{event.location}</span>
                                    </div>
                                  )}
                                  {(event.event_type || event.eventType) && (
                                    <Badge className="bg-purple-600/20 text-purple-400 text-xs">
                                      {(event.event_type || event.eventType)?.name || event.event_type || event.eventType}
                                    </Badge>
                                  )}
                                </div>
                                {(startDateStr || startTimeStr || endDateStr || endTimeStr) && (
                                  <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm mb-3 p-3 rounded-lg bg-white/5 dark:bg-black/20 border border-white/10">
                                    {startDateStr && (
                                      <div>
                                        <span className="text-gray-500 dark:text-gray-400">Start</span>
                                        <div className="flex items-center gap-1.5 text-gray-900 dark:text-white">
                                          <Calendar className="w-4 h-4 text-purple-400" />
                                          <span>{startDateStr}</span>
                                          {startTimeStr && <span className="text-gray-600 dark:text-gray-300">· {startTimeStr}</span>}
                                        </div>
                                      </div>
                                    )}
                                    {endDt && (
                                      <div>
                                        <span className="text-gray-500 dark:text-gray-400">End</span>
                                        <div className="flex items-center gap-1.5 text-gray-900 dark:text-white">
                                          <Clock className="w-4 h-4 text-blue-400" />
                                          <span>{endDateStr}</span>
                                          {endTimeStr && <span className="text-gray-600 dark:text-gray-300">· {endTimeStr}</span>}
                                        </div>
                                      </div>
                                    )}
                                  </div>
                                )}
                                {event.description && (
                                  <p className="text-gray-700 dark:text-gray-300 text-sm mb-4 line-clamp-3">{event.description}</p>
                                )}
                              </div>
                            </div>
                            <div className="flex items-center gap-3">
                              <Link href={route("viewEvent", event.id)}>
                                <Button className="bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white">
                                  View Details
                  </Button>
                              </Link>
                              <Button variant="outline" className="bg-transparent border-gray-300 dark:border-white/20 hover:bg-gray-100 dark:hover:bg-white/10 text-gray-900 dark:text-white">
                                Register
                              </Button>
            </div>
          </div>
                          )
                        })}
        </div>
                    ) : (
                      <div className="text-center py-12">
                        <Calendar className="w-16 h-16 text-gray-600 mx-auto mb-4" />
                        <p className="text-gray-600 dark:text-gray-400">No events scheduled at the moment.</p>
                      </div>
                    )}
                  </div>
                )}

                {/* Membership Tab Content */}
                {activeTab === "Membership" && membershipJoin && (
                  <div className="animate-in fade-in slide-in-from-bottom-4 duration-500">
                    <MembershipJoinCard membershipJoin={membershipJoin} />
                  </div>
                )}

                {(activeTab === "Communication Hub" || activeTab === "Discussion" || activeTab === "Announcements") &&
                  (organization.is_registered || organization.is_care_alliance_public) && (
                  <CommunicationHubPanel
                    organization={{
                      id: organization.id,
                      name: organization.name,
                      slug: organization.registered_organization?.user?.slug || organization.user?.slug || undefined,
                    }}
                    announcements={communicationHub?.announcements ?? []}
                    announcementsTotal={communicationHub?.announcementsTotal ?? 0}
                    discussions={communicationHub?.discussions ?? []}
                    discussionsTotal={communicationHub?.discussionsTotal ?? 0}
                    categories={communicationHub?.categories ?? []}
                    permissions={
                      communicationHub?.permissions ?? {
                        can_create_announcement: canCreateCommunityAnnouncement,
                        can_create_discussion: canCreateCommunityDiscussion,
                        can_manage_announcements: canModerateCommunity,
                        can_moderate_discussions: canModerateCommunity,
                      }
                    }
                    manageMode={Boolean(organization.is_own_organization)}
                  />
                )}

                {/* Community Groups Tab */}
                {activeTab === "Groups" && (organization.is_registered || organization.is_care_alliance_public) && (
                  <div className="animate-in fade-in slide-in-from-bottom-4 duration-500 space-y-4">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                      <div>
                        <h2 className="text-2xl font-bold text-gray-900 dark:text-white">Community Groups</h2>
                        <p className="text-sm text-muted-foreground">
                          Followers and members can create groups instantly — no org approval required.
                        </p>
                      </div>
                      {canCreateCommunityGroup && createCommunityGroupUrl && (
                        <Button asChild className="cursor-pointer bg-gradient-to-r from-purple-600 to-blue-600">
                          <Link href={createCommunityGroupUrl}>Create Group</Link>
                        </Button>
                      )}
                    </div>
                    {communityGroups.length === 0 ? (
                      <div className="rounded-xl border bg-white p-10 text-center dark:bg-[#111827]">
                        <p className="text-muted-foreground">No public groups yet.</p>
                      </div>
                    ) : (
                      <div className="grid gap-4 sm:grid-cols-2">
                        {communityGroups.map((g) => (
                          <Link
                            key={g.id}
                            href={g.url}
                            className="overflow-hidden rounded-xl border bg-white shadow-sm transition hover:shadow-md dark:bg-[#111827]"
                          >
                            {g.cover_image ? (
                              <img src={g.cover_image} alt="" className="h-32 w-full object-cover" />
                            ) : (
                              <div className="flex h-32 items-center justify-center bg-gradient-to-r from-purple-600 to-blue-600">
                                <UsersRound className="h-8 w-8 text-white" />
                              </div>
                            )}
                            <div className="space-y-1 p-4">
                              <div className="flex flex-wrap gap-2">
                                {g.is_pinned && <Badge variant="secondary">Pinned</Badge>}
                                {g.is_featured && <Badge variant="secondary">Featured</Badge>}
                                {g.category && <Badge variant="outline">{g.category}</Badge>}
                              </div>
                              <h3 className="font-semibold text-gray-900 dark:text-white">{g.name}</h3>
                              <p className="line-clamp-2 text-sm text-muted-foreground">{g.description}</p>
                              <p className="text-xs text-muted-foreground">{g.members_count} members</p>
                            </div>
                          </Link>
                        ))}
                      </div>
                    )}
                  </div>
                )}

                {/* Contact Tab Content */}
                {activeTab === "Contact" && (
                  <div className="bg-white dark:bg-[#111827] rounded-xl p-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
                    <h2 className="text-2xl font-bold mb-6 flex items-center gap-2 text-gray-900 dark:text-white">
                      <Phone className="w-6 h-6" />
                      Contact Information
                    </h2>
                    <div className="space-y-4">
                      {organization.phone && (
                          <div className="flex items-center gap-3 p-4 bg-gray-50 dark:bg-[#0a0f1a] rounded-lg border border-gray-200 dark:border-white/10">
                          <div className="w-10 h-10 bg-gradient-to-r from-purple-600 to-blue-600 rounded-full flex items-center justify-center">
                            <Phone className="w-5 h-5 text-white" />
                          </div>
                          <div>
                            <p className="text-sm text-gray-600 dark:text-gray-400">Phone</p>
                            <a href={`tel:${organization.phone}`} className="text-gray-900 dark:text-white hover:text-purple-600 dark:hover:text-purple-400 transition-colors">
                              {organization.phone}
                            </a>
                          </div>
                        </div>
                      )}
                      {organization.email && (
                          <div className="flex items-center gap-3 p-4 bg-gray-50 dark:bg-[#0a0f1a] rounded-lg border border-gray-200 dark:border-white/10">
                          <div className="w-10 h-10 bg-gradient-to-r from-purple-600 to-blue-600 rounded-full flex items-center justify-center">
                            <Mail className="w-5 h-5 text-white" />
      </div>
                          <div>
                            <p className="text-sm text-gray-600 dark:text-gray-400">Email</p>
                            <a href={`mailto:${organization.email}`} className="text-gray-900 dark:text-white hover:text-purple-600 dark:hover:text-purple-400 transition-colors">
                              {organization.email}
                            </a>
                          </div>
                        </div>
                      )}
                      {organization.website && (
                          <div className="flex items-center gap-3 p-4 bg-gray-50 dark:bg-[#0a0f1a] rounded-lg border border-gray-200 dark:border-white/10">
                          <div className="w-10 h-10 bg-gradient-to-r from-purple-600 to-blue-600 rounded-full flex items-center justify-center">
                            <Globe className="w-5 h-5 text-white" />
                          </div>
                          <div>
                            <p className="text-sm text-gray-600 dark:text-gray-400">Website</p>
                            <a
                              href={organization.website.startsWith('http') ? organization.website : `https://${organization.website}`}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="text-gray-900 dark:text-white hover:text-purple-600 dark:hover:text-purple-400 transition-colors"
                            >
                              {organization.website.replace(/^https?:\/\//, '')}
                            </a>
                          </div>
                        </div>
                      )}
                      {(organization.social_accounts?.facebook) && (
                          <div className="flex items-center gap-3 p-4 bg-gray-50 dark:bg-[#0a0f1a] rounded-lg border border-gray-200 dark:border-white/10">
                          <div className="w-10 h-10 bg-gradient-to-r from-purple-600 to-blue-600 rounded-full flex items-center justify-center">
                            <ExternalLink className="w-5 h-5 text-white" />
                          </div>
                          <div>
                            <p className="text-sm text-gray-600 dark:text-gray-400">Facebook</p>
                            <a
                              href={organization.social_accounts.facebook.startsWith('http') ? organization.social_accounts.facebook : `https://${organization.social_accounts.facebook}`}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="text-gray-900 dark:text-white hover:text-purple-600 dark:hover:text-purple-400 transition-colors"
                            >
                              {organization.social_accounts.facebook.replace(/^https?:\/\//, '')}
                            </a>
                          </div>
                        </div>
                      )}
                      {(organization.social_accounts?.instagram) && (
                          <div className="flex items-center gap-3 p-4 bg-gray-50 dark:bg-[#0a0f1a] rounded-lg border border-gray-200 dark:border-white/10">
                          <div className="w-10 h-10 bg-gradient-to-r from-purple-600 to-blue-600 rounded-full flex items-center justify-center">
                            <ExternalLink className="w-5 h-5 text-white" />
                          </div>
                          <div>
                            <p className="text-sm text-gray-600 dark:text-gray-400">Instagram</p>
                            <a
                              href={organization.social_accounts.instagram.startsWith('http') ? organization.social_accounts.instagram : `https://${organization.social_accounts.instagram}`}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="text-gray-900 dark:text-white hover:text-purple-600 dark:hover:text-purple-400 transition-colors"
                            >
                              {organization.social_accounts.instagram.replace(/^https?:\/\//, '')}
                            </a>
                          </div>
                        </div>
                      )}
                      {(organization.social_accounts?.youtube || organization.youtube_channel_url) && (
                          <div className="flex items-center gap-3 p-4 bg-gray-50 dark:bg-[#0a0f1a] rounded-lg border border-gray-200 dark:border-white/10">
                          <div className="w-10 h-10 bg-gradient-to-r from-purple-600 to-blue-600 rounded-full flex items-center justify-center">
                            <ExternalLink className="w-5 h-5 text-white" />
                          </div>
                          <div>
                            <p className="text-sm text-gray-600 dark:text-gray-400">YouTube</p>
                            <a
                              href={(() => { const u = organization.social_accounts?.youtube || organization.youtube_channel_url; return u?.startsWith('http') ? u : `https://${u}`; })()}
                              target="_blank"
                              rel="noopener noreferrer"
                              className="text-gray-900 dark:text-white hover:text-purple-600 dark:hover:text-purple-400 transition-colors"
                            >
                              {(organization.social_accounts?.youtube || organization.youtube_channel_url || '').replace(/^https?:\/\//, '')}
                            </a>
                          </div>
                        </div>
                      )}
                      {location && location !== 'Location not specified' && (
                          <div className="flex items-center gap-3 p-4 bg-gray-50 dark:bg-[#0a0f1a] rounded-lg border border-gray-200 dark:border-white/10">
                          <div className="w-10 h-10 bg-gradient-to-r from-purple-600 to-blue-600 rounded-full flex items-center justify-center">
                            <MapPin className="w-5 h-5 text-white" />
                          </div>
                          <div>
                            <p className="text-sm text-gray-600 dark:text-gray-400">Address</p>
                            <p className="text-gray-900 dark:text-white">{location}</p>
                            {organization.address && (
                              <p className="text-gray-700 dark:text-gray-300 text-sm mt-1">{organization.address}</p>
                            )}
                          </div>
                        </div>
                      )}
                      {(!organization.phone && !organization.email && !organization.website && !organization.social_accounts?.facebook && !organization.social_accounts?.instagram && !organization.social_accounts?.youtube && !organization.youtube_channel_url && (!location || location === 'Location not specified')) && (
                        <div className="text-center py-12">
                          <Phone className="w-16 h-16 text-gray-600 mx-auto mb-4" />
                          <p className="text-gray-600 dark:text-gray-400">Contact information not available.</p>
                        </div>
                      )}
                    </div>
                  </div>
                )}

                {/* Members — Unity Impact Alliance member nonprofits */}
                {activeTab === "Members" && organization.is_registered && organization.is_care_alliance_public && (
                  <div className="bg-white dark:bg-[#111827] rounded-xl p-6 animate-in fade-in slide-in-from-bottom-4 duration-500 border border-indigo-500/15 dark:border-indigo-400/10">
                    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-2">
                      <h2 className="text-2xl font-bold flex items-center gap-2 text-gray-900 dark:text-white">
                        <UsersRound className="w-7 h-7 text-indigo-600 dark:text-indigo-400" />
                        Member organizations
                      </h2>
                      <Badge className="bg-gradient-to-r from-indigo-600 to-violet-600 text-white w-fit">
                        {allianceMembers.length || partnerOrganizationsCount || 0} members
                      </Badge>
                    </div>
                    <p className="text-sm text-gray-600 dark:text-gray-400 mb-6">
                      Registered nonprofits in this Unity Impact Alliance. Visit a member&apos;s public profile to learn more or support their work.
                    </p>
                    {allianceMembers.length > 0 ? (
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        {allianceMembers.map((m) => {
                          const href = m.public_slug ? route('organizations.show', m.public_slug) : null
                          return (
                            <Link
                              key={m.id}
                              href={href || '#'}
                              onClick={(e) => {
                                if (!href) e.preventDefault()
                              }}
                              className="group flex gap-4 rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50/80 dark:bg-[#0a0f1a] p-4 hover:border-indigo-500/40 hover:shadow-md transition-all"
                            >
                              <Avatar className="w-14 h-14 shrink-0 ring-2 ring-indigo-500/20">
                                <AvatarImage src={m.image ? `/storage/${m.image}` : undefined} />
                                <AvatarFallback className="bg-indigo-600 text-white text-lg">
                                  {m.name.charAt(0).toUpperCase()}
                                </AvatarFallback>
                              </Avatar>
                              <div className="min-w-0 flex-1">
                                <h3 className="font-semibold text-gray-900 dark:text-white group-hover:text-indigo-600 dark:group-hover:text-indigo-400 truncate">
                                  {m.name}
                                </h3>
                                {(m.city || m.state) && (
                                  <p className="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1 mt-1">
                                    <MapPin className="w-3.5 h-3.5 shrink-0" />
                                    {[m.city, m.state].filter(Boolean).join(', ')}
                                  </p>
                                )}
                                {m.joined_at && (
                                  <p className="text-[11px] text-gray-500 dark:text-gray-500 mt-2">
                                    Member since {new Date(m.joined_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}
                                  </p>
                                )}
                              </div>
                            </Link>
                          )
                        })}
                      </div>
                    ) : (
                      <div className="text-center py-16 rounded-xl border border-dashed border-gray-300 dark:border-white/10">
                        <UsersRound className="w-16 h-16 text-indigo-500/50 mx-auto mb-4" />
                        <p className="text-gray-600 dark:text-gray-400 font-medium">No member organizations yet</p>
                        <p className="text-gray-500 dark:text-gray-500 text-sm mt-2 max-w-md mx-auto">
                          When nonprofits join this alliance, they will be listed here with a link to their public profiles.
                        </p>
                      </div>
                    )}
                  </div>
                )}

                {/* Followers — users following the alliance hub (same data as org supporters) */}
                {activeTab === "Followers" && organization.is_registered && organization.is_care_alliance_public && (
                  <div className="bg-white dark:bg-[#111827] rounded-xl p-6 animate-in fade-in slide-in-from-bottom-4 duration-500">
                    <div className="flex items-center justify-between mb-6">
                      <h2 className="text-2xl font-bold flex items-center gap-2 text-gray-900 dark:text-white">
                        <Users className="w-6 h-6" />
                        Followers
                      </h2>
                      <Badge className="bg-gradient-to-r from-indigo-600 to-violet-600 text-white">
                        {supportersCount || 0} {supportersCount === 1 ? "follower" : "followers"}
                      </Badge>
                    </div>
                    {Array.isArray(supporters) && supporters.length > 0 ? (
                      <div className="grid grid-cols-2 gap-3 sm:gap-4">
                        {supporters.map((supporter: any, index: number) => {
                          const userSlug = supporter.user?.slug || supporter.user?.id
                          const orgPublicSlug = supporter.organization_public_slug
                          const displayName =
                            supporter.follower_display_name ??
                            supporter.user?.name ??
                            supporter.name ??
                            'Anonymous'
                          const avatarFile =
                            supporter.follower_avatar ?? supporter.user?.image ?? supporter.avatar
                          const userRoute =
                            orgPublicSlug &&
                            (supporter.is_organization_follower || supporter.is_partner_organization)
                              ? route('organizations.show', orgPublicSlug)
                              : userSlug
                                ? route('users.show', userSlug)
                                : null

                          return (
                            <Link
                              key={supporter.id || supporter.user_id || `follower-${index}`}
                              href={userRoute || '#'}
                              onClick={(e) => {
                                if (!userRoute) {
                                  e.preventDefault()
                                }
                              }}
                              className="bg-gray-50 dark:bg-[#0a0f1a] rounded-lg p-4 border border-gray-200 dark:border-white/10 hover:border-indigo-500/50 transition-all cursor-pointer block"
                            >
                              <div className="flex items-center gap-3 mb-3">
                                <Avatar className="w-12 h-12 flex-shrink-0">
                                  <AvatarImage
                                    src={avatarFile ? `/storage/${avatarFile}` : "/placeholder.svg"}
                                  />
                                  <AvatarFallback className="bg-gradient-to-br from-indigo-500 to-violet-500 text-sm">
                                    {displayName
                                      .split(/\s+/)
                                      .filter(Boolean)
                                      .slice(0, 2)
                                      .map((n: string) => n[0])
                                      .join('')
                                      .toUpperCase() || 'U'}
                                  </AvatarFallback>
                                </Avatar>
                                <div className="flex-1 min-w-0">
                                  <h3 className="font-semibold truncate text-gray-900 dark:text-white">
                                    {displayName}
                                  </h3>
                                  <SupporterAccountTypeBadge supporter={supporter} variant="followers" />
                                </div>
                              </div>
                              <div className="space-y-2">
                                {supporter.joined_at && (
                                  <p className="text-xs text-gray-500 dark:text-gray-500">
                                    Following since{" "}
                                    {new Date(supporter.joined_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })}
                                  </p>
                                )}
                                {supporter.notifications && (
                                  <Badge className="bg-green-600/20 text-green-400 text-xs inline-flex items-center gap-1">
                                    <Bell className="w-3 h-3" />
                                    Notifications on
                                  </Badge>
                                )}
                              </div>
                            </Link>
                          )
                        })}
                      </div>
                    ) : (
                      <div className="text-center py-12">
                        <Users className="w-16 h-16 text-gray-600 mx-auto mb-4" />
                        <p className="text-gray-600 dark:text-gray-400">No followers yet.</p>
                        <p className="text-gray-500 dark:text-gray-500 text-sm mt-2">
                          Follow this alliance from its hub profile to show up here.
                        </p>
                      </div>
                    )}
                  </div>
                )}

                {/* Supporters Tab Content - For both registered and unregistered organizations */}
                {activeTab === "Supporters" && !organization.is_care_alliance_public && (
                  <div className="bg-white dark:bg-[#111827] rounded-xl p-6 animate-in fade-in slide-in-from-bottom-4 duration-500 space-y-8">
                    {organization.is_registered ? (
                      <>
                        <div>
                          <div className="flex items-center justify-between mb-4">
                            <h2 className="text-xl font-bold flex items-center gap-2 text-gray-900 dark:text-white">
                              <UserCheck className="w-5 h-5" />
                              Primary Supporters (Members)
                            </h2>
                            <Badge className="bg-gradient-to-r from-purple-600 to-blue-600 text-white">
                              {primarySupportersCount || 0}
                            </Badge>
                          </div>
                          {primarySupporters.length > 0 ? (
                            <div className="grid grid-cols-2 gap-3 sm:gap-4">
                              {primarySupporters.map((supporter: any, index: number) => {
                                const userSlug = supporter.user?.slug || supporter.slug || supporter.user?.id
                                const userRoute = userSlug ? route('users.show', userSlug) : null
                                const displayName = supporter.user?.name || supporter.name || 'Anonymous'
                                const avatarFile = supporter.user?.image || supporter.image
                                return (
                                  <Link
                                    key={supporter.user_id || supporter.id || `primary-${index}`}
                                    href={userRoute || '#'}
                                    onClick={(e) => { if (!userRoute) e.preventDefault() }}
                                    className="bg-gray-50 dark:bg-[#0a0f1a] rounded-lg p-4 border border-gray-200 dark:border-white/10 hover:border-purple-500/50 transition-all block"
                                  >
                                    <div className="flex items-center gap-3">
                                      <Avatar className="w-10 h-10">
                                        <AvatarImage src={avatarFile ? `/storage/${avatarFile}` : '/placeholder.svg'} />
                                        <AvatarFallback className="bg-gradient-to-br from-purple-500 to-blue-500 text-xs">
                                          {displayName.split(/\s+/).slice(0, 2).map((n: string) => n[0]).join('').toUpperCase()}
                                        </AvatarFallback>
                                      </Avatar>
                                      <div className="min-w-0">
                                        <p className="font-semibold truncate text-gray-900 dark:text-white">{displayName}</p>
                                        <Badge variant="outline" className="mt-1 text-[10px]">Primary</Badge>
                                      </div>
                                    </div>
                                  </Link>
                                )
                              })}
                            </div>
                          ) : (
                            <p className="text-sm text-gray-600 dark:text-gray-400">No primary supporters yet.</p>
                          )}
                        </div>

                        <div>
                          <div className="flex items-center justify-between mb-4">
                            <h2 className="text-xl font-bold flex items-center gap-2 text-gray-900 dark:text-white">
                              <Users className="w-5 h-5" />
                              Secondary Supporters
                            </h2>
                            <Badge variant="secondary">
                              {secondarySupportersCount || 0}
                            </Badge>
                          </div>
                          {secondarySupporters.length > 0 ? (
                            <div className="grid grid-cols-2 gap-3 sm:gap-4">
                              {secondarySupporters.map((supporter: any, index: number) => {
                                const userSlug = supporter.user?.slug || supporter.slug || supporter.user?.id
                                const userRoute = userSlug ? route('users.show', userSlug) : null
                                const displayName = supporter.user?.name || supporter.name || 'Anonymous'
                                const avatarFile = supporter.user?.image || supporter.image
                                return (
                                  <Link
                                    key={supporter.user_id || supporter.id || `secondary-${index}`}
                                    href={userRoute || '#'}
                                    onClick={(e) => { if (!userRoute) e.preventDefault() }}
                                    className="bg-gray-50 dark:bg-[#0a0f1a] rounded-lg p-4 border border-gray-200 dark:border-white/10 hover:border-indigo-500/50 transition-all block"
                                  >
                                    <div className="flex items-center gap-3">
                                      <Avatar className="w-10 h-10">
                                        <AvatarImage src={avatarFile ? `/storage/${avatarFile}` : '/placeholder.svg'} />
                                        <AvatarFallback className="bg-gradient-to-br from-indigo-500 to-violet-500 text-xs">
                                          {displayName.split(/\s+/).slice(0, 2).map((n: string) => n[0]).join('').toUpperCase()}
                                        </AvatarFallback>
                                      </Avatar>
                                      <div className="min-w-0">
                                        <p className="font-semibold truncate text-gray-900 dark:text-white">{displayName}</p>
                                        <Badge variant="outline" className="mt-1 text-[10px]">Secondary</Badge>
                                      </div>
                                    </div>
                                  </Link>
                                )
                              })}
                            </div>
                          ) : (
                            <p className="text-sm text-gray-600 dark:text-gray-400">No secondary supporters yet.</p>
                          )}
                        </div>
                      </>
                    ) : (
                      <>
                        <div className="flex items-center justify-between mb-6">
                          <h2 className="text-2xl font-bold flex items-center gap-2 text-gray-900 dark:text-white">
                            <Users className="w-6 h-6" />
                            Supporters
                          </h2>
                          <Badge className="bg-gradient-to-r from-purple-600 to-blue-600 text-white">
                            {supporters?.length || supportersCount || 0} Supporters
                          </Badge>
                        </div>
                        {Array.isArray(supporters) && supporters.length > 0 ? (
                          <div className="grid grid-cols-2 gap-3 sm:gap-4">
                            {supporters.map((supporter: any, index: number) => {
                              const userSlug = supporter.user?.slug || supporter.user?.id
                              const orgPubSlug = supporter.organization_public_slug
                              const userRoute = supporter.is_partner_organization && orgPubSlug
                                ? route('organizations.show', orgPubSlug)
                                : userSlug
                                  ? route('users.show', userSlug)
                                  : null

                              return (
                                <Link
                                  key={supporter.id || supporter.user_id || `supporter-${index}`}
                                  href={userRoute || '#'}
                                  onClick={(e) => {
                                    if (!userRoute) {
                                      e.preventDefault()
                                    }
                                  }}
                                  className="bg-gray-50 dark:bg-[#0a0f1a] rounded-lg p-4 border border-gray-200 dark:border-white/10 hover:border-purple-500/50 transition-all cursor-pointer block"
                                >
                                  <div className="flex items-center gap-3 mb-3">
                                    <Avatar className="w-12 h-12 flex-shrink-0">
                                      <AvatarImage
                                        src={supporter.user?.image ? `/storage/${supporter.user.image}` : supporter.avatar || "/placeholder.svg"}
                                      />
                                      <AvatarFallback className="bg-gradient-to-br from-purple-500 to-blue-500 text-sm">
                                        {supporter.user?.name
                                          ? supporter.user.name.split(' ').map((n: string) => n[0]).join('').toUpperCase()
                                          : supporter.name
                                          ? supporter.name.split(' ').map((n: string) => n[0]).join('').toUpperCase()
                                          : 'U'}
                                      </AvatarFallback>
                                    </Avatar>
                                    <div className="flex-1 min-w-0">
                                      <h3 className="font-semibold truncate text-gray-900 dark:text-white">
                                        {supporter.user?.name || supporter.name || 'Anonymous'}
                                      </h3>
                                      <SupporterAccountTypeBadge supporter={supporter} variant="supporters" />
                                    </div>
                                  </div>
                                </Link>
                              )
                            })}
                          </div>
                        ) : (
                          <div className="text-center py-12">
                            <Users className="w-16 h-16 text-gray-600 mx-auto mb-4" />
                            <p className="text-gray-600 dark:text-gray-400">No supporters yet.</p>
                          </div>
                        )}
                      </>
                    )}
                  </div>
                )}
                  </>
                )}
              </section>

              {/* Right Sidebar */}
              <aside className="lg:col-span-3 space-y-4">
                {organization.is_care_alliance_public && (
                  <div className="rounded-xl overflow-hidden border border-indigo-500/25 dark:border-indigo-400/15 bg-white dark:bg-[#111827] shadow-sm animate-in fade-in slide-in-from-right-4 duration-500">
                    <div className="bg-gradient-to-r from-indigo-600 via-violet-600 to-purple-600 px-4 py-2.5">
                      <p className="text-[10px] font-semibold uppercase tracking-wider text-white/90">About this alliance</p>
                      <p className="text-xs text-white/80 mt-1 leading-snug">Mission, focus, and how to connect</p>
                    </div>
                    <div className="p-4 space-y-3">
                      {allianceSidebarBlurb && (
                        <p className="text-xs text-gray-600 dark:text-gray-400 leading-relaxed">{allianceSidebarBlurb}</p>
                      )}
                      {careAllianceProfile?.website && (
                        <a
                          href={careAllianceProfile.website.startsWith('http') ? careAllianceProfile.website : `https://${careAllianceProfile.website}`}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="inline-flex items-center gap-1.5 text-xs text-indigo-600 dark:text-indigo-400 hover:underline"
                        >
                          <Globe className="w-3.5 h-3.5 shrink-0" />
                          <span className="truncate">Alliance website</span>
                        </a>
                      )}
                      <div className="rounded-lg bg-violet-500/[0.07] dark:bg-violet-500/10 p-3 text-center border border-violet-500/10">
                        <p className="text-xl font-bold text-violet-700 dark:text-violet-300 tabular-nums">{careAllianceCampaigns.length}</p>
                        <p className="text-[10px] text-gray-500 dark:text-gray-500 mt-0.5">Active fundraising campaigns</p>
                      </div>
                      {careAllianceProfile?.focus_areas && careAllianceProfile.focus_areas.length > 0 && (
                        <div>
                          <p className="text-[10px] font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-1.5">Focus areas</p>
                          <div className="flex flex-wrap gap-1.5">
                            {careAllianceProfile.focus_areas.map((a) => (
                              <span
                                key={a.id}
                                className="text-[10px] px-2 py-0.5 rounded-full border border-indigo-500/30 bg-indigo-500/5 text-indigo-800 dark:text-indigo-200"
                              >
                                {a.name}
                              </span>
                            ))}
                          </div>
                        </div>
                      )}
                      {location && location !== 'Location not specified' && (
                        <p className="text-xs text-gray-600 dark:text-gray-400 flex items-center gap-1.5">
                          <MapPin className="w-3.5 h-3.5 shrink-0 text-indigo-500" />
                          {location}
                        </p>
                      )}
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="w-full border-indigo-500/30 text-indigo-700 dark:text-indigo-300 hover:bg-indigo-500/10"
                        onClick={() => handleTabChange('Members')}
                      >
                        <UsersRound className="w-4 h-4 mr-2" />
                        View member organizations
                      </Button>
                    </div>
                  </div>
                )}

                {/* Organizations You May Know */}
                {peopleToShow.length > 0 && (
                  <div className="bg-white dark:bg-[#111827] rounded-xl p-4 animate-in fade-in slide-in-from-right-4 duration-500">
                    <div className="flex items-center justify-between mb-4">
                      <h3 className="font-semibold text-gray-900 dark:text-white">Organizations You May Know</h3>
                      <Link href={route('organizations')} className="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition-colors flex items-center gap-1">
                        View All <ChevronDown className="w-3 h-3 -rotate-90" />
                      </Link>
                    </div>
                    <div className="space-y-3">
                      {peopleToShow.map((person, index) => (
                      <div
                        key={person.id || index}
                        className="flex items-center gap-3 animate-in fade-in slide-in-from-right-2 duration-300"
                        style={{ animationDelay: `${index * 100}ms` }}
                      >
                        <Link
                          href={person.excel_data_id
                            ? route('organizations.show', person.excel_data_id)
                            : person.slug
                              ? route('organizations.show', person.slug)
                              : '#'}
                          onClick={(e) => {
                            if (!person.excel_data_id && !person.slug) {
                              e.preventDefault()
                            }
                          }}
                          className="flex-shrink-0"
                        >
                          <Avatar className="w-10 h-10 hover:ring-2 hover:ring-purple-500 transition-all cursor-pointer">
                            <AvatarImage src={person.avatar || "/placeholder.svg"} />
                            <AvatarFallback className="bg-gradient-to-br from-purple-500 to-pink-500 text-xs">
                              {typeof person.avatar === 'string' && person.avatar.length === 2
                                ? person.avatar
                                : person.name
                                    .split(' ')
                                    .map((n: string) => n[0])
                                    .join('')
                                    .toUpperCase()}
                            </AvatarFallback>
                          </Avatar>
                        </Link>
                        <div className="flex-1 min-w-0 overflow-hidden">
                          <Link
                            href={person.excel_data_id
                              ? route('organizations.show', person.excel_data_id)
                              : person.slug
                                ? route('organizations.show', person.slug)
                                : '#'}
                            className="text-sm font-medium truncate text-gray-900 dark:text-white hover:text-purple-600 dark:hover:text-purple-400 transition-colors cursor-pointer block"
                            onClick={(e) => {
                              if (!person.excel_data_id && !person.slug) {
                                e.preventDefault()
                              }
                            }}
                          >
                            {person.name}
                          </Link>
                          <p className="text-xs text-gray-500 dark:text-gray-500 truncate">{person.org || person.description}</p>
                        </div>
                      </div>
                    ))}
                    </div>
                  </div>
                )}

                {/* Trending Organizations */}
                {trendingOrgsToShow.length > 0 && (
                  <div className="bg-white dark:bg-[#111827] rounded-xl p-4 animate-in fade-in slide-in-from-right-4 duration-500 delay-100">
                    <div className="flex items-center justify-between mb-4">
                      <h3 className="font-semibold text-gray-900 dark:text-white">Trending Organizations</h3>
                      <Link href={route('organizations')} className="text-xs text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white transition-colors flex items-center gap-1">
                        View All <ChevronDown className="w-3 h-3 -rotate-90" />
                      </Link>
                    </div>
                    <div className="space-y-3">
                      {trendingOrgsToShow.map((org, index) => {
                      // Determine the route parameter (slug or excel_data_id)
                      const orgRouteParam = org.slug || org.excel_data_id || org.id

                      return (
                        <Link
                          key={org.id || index}
                          href={route('organizations.show', orgRouteParam)}
                          className="flex items-start gap-3 p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-white/5 transition-colors animate-in fade-in slide-in-from-right-2 duration-300"
                          style={{ animationDelay: `${index * 100 + 200}ms` }}
                        >
                          <div className={`w-10 h-10 ${org.color || 'bg-emerald-500'} rounded-lg flex items-center justify-center flex-shrink-0`}>
                            <Heart className="w-5 h-5 text-white" />
            </div>
                          <div className="min-w-0 flex-1">
                            <p className="text-sm font-medium text-gray-900 dark:text-white hover:text-purple-600 dark:hover:text-purple-400 transition-colors">
                              {org.name}
                            </p>
                            <p className="text-xs text-gray-500 dark:text-gray-500 truncate">{org.desc || org.description}</p>
                          </div>
                        </Link>
                      )
                      })}
          </div>
        </div>
      )}
              </aside>
            </div>
          </main>


          {/* Modals */}
          {organization.is_registered && !organization.is_care_alliance_public && (
            <DonationModal
              isOpen={showDonationModal}
              onClose={() => setShowDonationModal(false)}
              organization={organization}
            />
          )}

          {!organization.is_registered && auth?.user && (
            <InviteOrganizationPopup
              isOpen={showInvitePopup}
              onClose={() => setShowInvitePopup(false)}
              organization={{
                id: organization.id,
                name: organization.name,
                ein: organization.ein,
              }}
            />
          )}
        </div>
    </FrontendLayout>
  )
}
