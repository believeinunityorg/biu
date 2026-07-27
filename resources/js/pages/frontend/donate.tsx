"use client"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { useState, useMemo, useRef, useEffect } from "react"
import { motion, AnimatePresence } from "framer-motion"
import { Heart, CreditCard, Shield, Search, X, Loader2, Coins, Lock, ChevronRight, Building2, UtensilsCrossed, Brain, Check, CheckCircle, Gift, Wrench, TrendingUp, Car, Package, FileText, Camera, ArrowLeft, Landmark, Info } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import type { PageProps as InertiaPageProps } from "@inertiajs/core"
import { router, usePage, Link } from "@inertiajs/react"
import { PageHead } from "@/components/frontend/PageHead"
import { useNotification } from "@/components/frontend/notification-provider"
import { SubscriptionRequiredModal } from "@/components/SubscriptionRequiredModal"
import { Avatar, AvatarFallback, AvatarImage } from "@/components/frontend/ui/avatar"
import SiteTitle from "@/components/site-title"
import { cn } from "@/lib/utils"
import type { ProcessingFeeRates } from "@/types"
import { Switch } from "@/components/frontend/ui/switch"
import {
  SavedPaymentMethodSelector,
  type SavedPaymentMethod,
} from "@/components/account/saved-payment-method-selector"
import {
  LockedPrimaryOrganizationFilter,
  type OrganizationFilterLock,
} from "@/components/frontend/locked-primary-organization-filter"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import {
  DonationPaymentMethods,
  type DonationPaymentMethodId,
} from "@/components/frontend/donation-payment-methods"
import {
  InstantDonate,
  type InstantDonateCause,
  type InstantDonatePayload,
} from "@/components/frontend/instant-donate"
import { Zap } from "lucide-react"

/** Matches `DonationController@index` `feePreview` prop (same rules as checkout). */
type FeePreviewRail = "card" | "bank"

interface FeePreviewFromServer {
  mode: "donor_covers" | "org_covers"
  rail?: FeePreviewRail
  base_gift_usd: number
  checkout_total_usd: number
  processing_fee_estimate: number
  estimated_net_to_org_usd: number
}

/** Nonprofit or Unity Impact Alliance (donation routes to hub org via organization_id). */
interface DonateCause {
  id: string
  kind: "organization" | "care_alliance"
  organization_id: number
  name: string
  description: string
  image: string | null
  raised: number
  goal: number
  supporters: number
  alliance_slug?: string
  care_alliance_id?: number
  /** Lifetime total this logged-in user has donated to this recipient (from server). */
  donated_total?: number
}

function CauseAvatar({
  name,
  src,
  className,
  shape = "circle",
}: {
  name: string
  src?: string | null
  className?: string
  /** `square` uses rounded corners like the search list thumbnails. */
  shape?: "circle" | "square"
}) {
  const initial = (name.trim().charAt(0) || "?").toUpperCase()
  const usableSrc = typeof src === "string" && src.trim() !== "" ? src.trim() : undefined
  const round = shape === "square" ? "rounded-md" : "rounded-full"
  return (
    <Avatar className={cn("shrink-0", round, className)}>
      <AvatarImage src={usableSrc} alt="" className="object-cover" />
      <AvatarFallback
        className={cn(
          round,
          "bg-purple-100 text-purple-800 text-sm font-semibold dark:bg-purple-900/40 dark:text-purple-100",
        )}
      >
        {initial}
      </AvatarFallback>
    </Avatar>
  )
}

function CauseKindBadge({ kind }: { kind: DonateCause["kind"] }) {
  return kind === "care_alliance" ? (
    <Badge className="inline-flex w-fit items-center gap-1 border-0 px-2 py-0.5 text-xs font-semibold shadow-sm bg-blue-600 text-white hover:bg-blue-600">
      <Building2 className="h-3 w-3 shrink-0" />
      Unity Impact Alliance
    </Badge>
  ) : (
    <Badge className="inline-flex w-fit items-center gap-1 border-0 px-2 py-0.5 text-xs font-semibold shadow-sm bg-purple-600 text-white hover:bg-purple-600">
      <CheckCircle className="h-3 w-3 shrink-0" />
      Organization
    </Badge>
  )
}

interface User {
  name: string
  email: string
  // Add other user properties if needed
}

interface TopOrganization {
  name: string
  total: number
}

/** Fallback if shared `processingFeeRates` is missing (should not happen on normal Inertia responses). */
const DEFAULT_PROCESSING_FEE_RATES: ProcessingFeeRates = {
  card_percent: 0.029,
  card_fixed_usd: 0.3,
  ach_percent: 0.008,
  ach_fee_cap_usd: 5,
}

interface DonatePageProps extends InertiaPageProps {
  seo?: { title: string; description?: string }
  organizations: DonateCause[]
  /** Causes the current user has donated to (with totals); empty when logged out. */
  donatedCauses?: DonateCause[]
  user?: User | null
  message?: string
  searchQuery?: string
  thisYearDonated?: number
  givingGoal?: number
  topOrganizations?: TopOrganization[]
  feePreview?: FeePreviewFromServer | null
  /** Checkout total for each rail (same gift + “Make Full Impact” as active preview). */
  feePreviewCheckoutTotalsByRail?: { card: number; bank: number } | null
  organizationFilterLock?: OrganizationFilterLock | null
  /** Profile setting: primary org cannot be changed except via My Profile. */
  primaryOrganizationLocked?: boolean
  /** Enabled payment rails for selected org (from server). */
  orgPaymentMethods?: Record<string, boolean>
  rewardPointsAmount?: number
  defaultPaymentMethods?: Record<string, boolean>
  /** Supporter's secondary organizations (from profile) for quick selection. */
  secondaryOrganizations?: DonateCause[]
}

const amountConfig = [
  { amount: 25 },
  { amount: 50, badge: "Most Popular" },
  { amount: 100, badge: "High Impact" },
  { amount: 250 },
  { amount: 500 },
  { amount: 1000 },
]

const TOP_ORG_ICONS = [Building2, UtensilsCrossed, Brain]

const DONATE_CARD =
  "rounded-2xl border border-purple-200/50 bg-white/80 shadow-lg shadow-purple-600/[0.08] backdrop-blur-xl text-gray-900 dark:border-purple-700/35 dark:bg-purple-950/35 dark:shadow-purple-950/25 dark:text-white"

const DONATE_CARD_HEADER =
  "border-b border-purple-100/80 bg-gradient-to-r from-purple-50/90 via-white/50 to-blue-50/80 dark:border-purple-800/30 dark:from-purple-900/40 dark:via-purple-950/20 dark:to-blue-950/35"

const DONATE_SEARCH_INPUT =
  "rounded-lg border-purple-200/60 bg-white/90 text-gray-900 placeholder:text-gray-400 focus-visible:ring-purple-600/30 focus-visible:border-purple-600 dark:border-purple-800/40 dark:bg-purple-950/25 dark:text-white dark:placeholder:text-white/40"

const DONATE_DROPDOWN =
  "absolute left-0 right-0 top-full z-50 mt-1.5 rounded-xl border border-purple-200/60 bg-white/95 shadow-xl shadow-purple-600/10 backdrop-blur-xl overflow-y-auto overscroll-contain [scrollbar-gutter:stable] [scrollbar-width:thin] [scrollbar-color:rgb(192_132_252_/_0.55)_rgb(250_245_255_/_0.6)] dark:[scrollbar-color:rgb(147_51_234_/_0.55)_rgb(59_7_100_/_0.45)] [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-track]:rounded-full [&::-webkit-scrollbar-track]:bg-purple-50/70 dark:[&::-webkit-scrollbar-track]:bg-purple-950/40 [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-gradient-to-b [&::-webkit-scrollbar-thumb]:from-purple-400/80 [&::-webkit-scrollbar-thumb]:to-blue-500/80 dark:[&::-webkit-scrollbar-thumb]:from-purple-500/80 dark:[&::-webkit-scrollbar-thumb]:to-blue-600/80 [&::-webkit-scrollbar-thumb:hover]:from-purple-500 [&::-webkit-scrollbar-thumb:hover]:to-blue-600 dark:border-purple-700/45 dark:bg-purple-950/95 dark:shadow-purple-950/40"

const DONATE_LOCKED_PRIMARY_PANEL =
  "mt-1.5 rounded-xl border border-purple-200/60 bg-white/95 shadow-xl shadow-purple-600/10 backdrop-blur-xl overflow-hidden dark:border-purple-700/45 dark:bg-purple-950/95 dark:shadow-purple-950/40"

const DONATE_DROPDOWN_SECTION =
  "sticky top-0 z-10 px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-purple-700 bg-gradient-to-r from-purple-50 to-blue-50/90 border-b border-purple-100/80 dark:text-purple-200/90 dark:from-purple-900/70 dark:to-blue-950/50 dark:border-purple-800/35"

const DONATE_DROPDOWN_ITEM =
  "w-full p-3 text-left flex items-center gap-3 text-gray-900 border-b border-purple-50/80 transition-colors hover:bg-purple-50/90 dark:text-white dark:border-purple-900/25 dark:hover:bg-purple-900/35"

const DONATE_SELECTED_ORG =
  "mt-2 flex items-center justify-between p-2.5 rounded-lg border border-purple-200/60 bg-gradient-to-r from-purple-50/90 to-blue-50/50 dark:border-purple-700/40 dark:from-purple-900/35 dark:to-blue-950/25"

type DonationMode = "instant" | "cash_points" | "non_cash"
type NonCashType = "goods" | "services" | "stocks_crypto" | "vehicle" | "other"

const NON_CASH_TYPES: { id: NonCashType; label: string; icon: typeof Gift }[] = [
  { id: "goods", label: "Goods", icon: Gift },
  { id: "services", label: "Services", icon: Wrench },
  { id: "stocks_crypto", label: "Stocks / Crypto", icon: TrendingUp },
  { id: "vehicle", label: "Vehicle", icon: Car },
  { id: "other", label: "Other", icon: Package },
]

const CONDITION_OPTIONS = ["New", "Like New", "Good", "Fair", "Poor"]

// The component now accepts props from Laravel via Inertia
function causeMatchesSearch(cause: DonateCause, rawQuery: string): boolean {
  const q = rawQuery.trim().toLowerCase()
  if (!q) return true
  if (cause.name.toLowerCase().includes(q)) return true
  if (cause.description && cause.description.toLowerCase().includes(q)) return true
  return false
}

export default function DonatePage({
  seo,
  organizations: initialOrganizations,
  donatedCauses: initialDonatedCauses = [],
  user,
  searchQuery: initialSearchQuery = "",
  thisYearDonated = 0,
  givingGoal = 1000,
  topOrganizations = [],
  organizationFilterLock = null,
  primaryOrganizationLocked = false,
  secondaryOrganizations: initialSecondaryOrganizations = [],
  rewardPointsAmount = 1,
  defaultPaymentMethods = {},
}: DonatePageProps) {
  const page = usePage<DonatePageProps & { processingFeeRates?: ProcessingFeeRates }>()
  const processingFeeRates = page.props.processingFeeRates ?? DEFAULT_PROCESSING_FEE_RATES
  const feePreview = page.props.feePreview ?? null
  const feePreviewCheckoutTotalsByRail = page.props.feePreviewCheckoutTotalsByRail ?? null
  const flash = page.props
  const { showNotification } = useNotification()

  // Check for subscription required flash message
  useEffect(() => {
    if ((flash as any)?.subscription_required || (flash as any)?.errors?.subscription) {
      setShowSubscriptionModal(true)
    }
  }, [flash])

  const [donationMode, setDonationMode] = useState<DonationMode>("cash_points")
  const [selectedAmount, setSelectedAmount] = useState<number | null>(null)
  const [customAmount, setCustomAmount] = useState("")
  const [selectedCauseId, setSelectedCauseId] = useState<string | null>(() => {
    const primaryId = organizationFilterLock?.primary_id
    const defaultToPrimary =
      organizationFilterLock?.donate_to_primary_default ??
      (organizationFilterLock?.locked !== false && Boolean(primaryId))
    if (!primaryId || !defaultToPrimary) {
      return null
    }
    const pools = [initialOrganizations, initialDonatedCauses, initialSecondaryOrganizations]
    for (const pool of pools) {
      const found = pool.find((cause) => cause.organization_id === primaryId)
      if (found) return found.id
    }
    if (organizationFilterLock?.primary_name) {
      return `org-${primaryId}`
    }
    return null
  })
  const [paymentMethod, setPaymentMethod] = useState<DonationPaymentMethodId>('stripe_card')
  const [donorCoversProcessingFees, setDonorCoversProcessingFees] = useState(true)
  const [feePreviewRail, setFeePreviewRail] = useState<FeePreviewRail>("card")
  const [feePreviewLoading, setFeePreviewLoading] = useState(false)
  const [savedPaymentMethodId, setSavedPaymentMethodId] = useState<string | null>(null)

  // Non-cash donation state
  const [nonCashType, setNonCashType] = useState<NonCashType>("goods")
  const [nonCashItemName, setNonCashItemName] = useState("")
  const [nonCashEstimatedValue, setNonCashEstimatedValue] = useState("")
  const [nonCashCondition, setNonCashCondition] = useState("New")
  const [nonCashPreferredOrgId, setNonCashPreferredOrgId] = useState<number | null>(null)
  const [nonCashUploadPhotos, setNonCashUploadPhotos] = useState(false)
  const [isSubmittingNonCash, setIsSubmittingNonCash] = useState(false)
  const [nonCashSearchQuery, setNonCashSearchQuery] = useState("")
  const [nonCashSearchFocused, setNonCashSearchFocused] = useState(false)

  // Get user's Believe Points balance
  const pageProps = usePage().props as any
  const authUser = pageProps.auth?.user || null
  const savedPaymentMethods = (pageProps.savedPaymentMethods ?? []) as SavedPaymentMethod[]
  const paymentMethodsUrl =
    pageProps.paymentMethodsUrl ?? route("user.profile.payment-methods.index")
  const donatedCauses = (pageProps.donatedCauses as DonateCause[] | undefined) ?? initialDonatedCauses
  const organizations = (pageProps.organizations as DonateCause[] | undefined) ?? initialOrganizations
  const secondaryOrganizations =
    (pageProps.secondaryOrganizations as DonateCause[] | undefined) ?? initialSecondaryOrganizations
  const availablePaymentMethods =
    (pageProps.orgPaymentMethods as Record<string, boolean> | undefined) ??
    defaultPaymentMethods

  const loadedPaymentMethodsOrgIdRef = useRef<number | null>(
    pageProps.orgPaymentMethods &&
    organizationFilterLock?.primary_id &&
    (organizationFilterLock?.donate_to_primary_default ??
      organizationFilterLock?.locked !== false)
      ? organizationFilterLock.primary_id
      : null,
  )

  const [paymentMethodsLoading, setPaymentMethodsLoading] = useState(() => {
    const primaryId = organizationFilterLock?.primary_id
    const defaultToPrimary =
      organizationFilterLock?.donate_to_primary_default ??
      (organizationFilterLock?.locked !== false && Boolean(primaryId))
    return defaultToPrimary && !page.props.orgPaymentMethods
  })

  const currentBalance =
    parseFloat(String(authUser?.donateable_believe_points ?? authUser?.purchased_believe_points ?? "0")) || 0
  const availableBalance = parseFloat(String(authUser?.believe_points ?? "0")) || 0
  const processingBalance = parseFloat(String(authUser?.processing_believe_points ?? "0")) || 0
  const isStripeRail = paymentMethod === "stripe_card" || paymentMethod === "stripe_ach"
  const [searchQuery, setSearchQuery] = useState(initialSearchQuery)
  const [isSearchFocused, setIsSearchFocused] = useState(false)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [submissionError, setSubmissionError] = useState<string | null>(null)
  const [isSearchingOrganizations, setIsSearchingOrganizations] = useState(false)
  const [showSubscriptionModal, setShowSubscriptionModal] = useState(false)

  const [donateToPrimary, setDonateToPrimary] = useState(() => {
    if (organizationFilterLock?.donate_to_primary_default !== undefined) {
      return organizationFilterLock.donate_to_primary_default
    }
    if (!organizationFilterLock?.primary_id) return false
    return organizationFilterLock.locked !== false
  })

  const effectiveLock = useMemo((): OrganizationFilterLock | null => {
    if (!organizationFilterLock) return null
    return {
      ...organizationFilterLock,
      locked: donateToPrimary,
    }
  }, [organizationFilterLock, donateToPrimary])

  const listingFilterLocked = donateToPrimary && Boolean(organizationFilterLock?.primary_id)

  const donatePartialReloadSkipRef = useRef(true)

  const [name, setName] = useState(user?.name || "")
  const [email, setEmail] = useState(user?.email || "")
  const [phone, setPhone] = useState("")
  const [donorMessage, setDonorMessage] = useState("") // Renamed to avoid conflict with page message prop

  const searchContainerRef = useRef<HTMLDivElement>(null)
  const nonCashOrgSearchRef = useRef<HTMLDivElement>(null)

  // Close search results when clicking outside
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (searchContainerRef.current && !searchContainerRef.current.contains(event.target as Node)) {
        setIsSearchFocused(false)
      }
      if (nonCashOrgSearchRef.current && !nonCashOrgSearchRef.current.contains(event.target as Node)) {
        setNonCashSearchFocused(false)
      }
    }
    document.addEventListener("mousedown", handleClickOutside)
    return () => {
      document.removeEventListener("mousedown", handleClickOutside)
    }
  }, [])

  useEffect(() => {
    if (flash?.warning) {
      // Show warning notification
      showNotification({
        type: "warning",
        message: typeof flash?.warning === "string" ? flash.warning : "Warning",
      })
    }
  }, [flash, showNotification])

  const handleAmountSelect = (amount: number) => {
    setSelectedAmount(amount)
    setCustomAmount("")
  }

  const handleCustomAmountChange = (value: string) => {
    setCustomAmount(value)
    setSelectedAmount(null)
  }

  const getCurrentAmount = () => {
    return selectedAmount || Number.parseFloat(customAmount) || 0
  }

  const pointsRequired = getCurrentAmount()
  const hasEnoughPoints = currentBalance >= pointsRequired
  const canUseBelievePoints = hasEnoughPoints

  const givingProgress = givingGoal > 0 ? Math.min(100, (thisYearDonated / givingGoal) * 100) : 0

  const selectedCause = useMemo(() => {
    if (!selectedCauseId) return undefined
    const pools = [organizations, donatedCauses, secondaryOrganizations]
    for (const pool of pools) {
      const found = pool.find((cause) => cause.id === selectedCauseId)
      if (found) return found
    }
    return undefined
  }, [selectedCauseId, organizations, donatedCauses, secondaryOrganizations])

  const hasOrgSelected = Boolean(selectedCause?.organization_id)

  /** Track org id when payment methods are already present (e.g. initial server render). */
  useEffect(() => {
    const orgId = selectedCause?.organization_id
    if (orgId && pageProps.orgPaymentMethods) {
      loadedPaymentMethodsOrgIdRef.current = orgId
      setPaymentMethodsLoading(false)
    }
  }, [selectedCause?.organization_id, pageProps.orgPaymentMethods])

  /** Load org-specific payment methods when recipient changes. */
  useEffect(() => {
    const orgId = selectedCause?.organization_id
    if (!orgId) {
      return
    }
    if (loadedPaymentMethodsOrgIdRef.current === orgId) {
      return
    }
    setPaymentMethodsLoading(true)
    router.get(
      route("donate"),
      { organization_id: orgId },
      {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        only: ["orgPaymentMethods"],
        onFinish: () => {
          loadedPaymentMethodsOrgIdRef.current = orgId
          setPaymentMethodsLoading(false)
        },
        onCancel: () => setPaymentMethodsLoading(false),
      },
    )
  }, [selectedCause?.organization_id])

  /** Keep selected payment method valid for this org. */
  useEffect(() => {
    if (!hasOrgSelected || paymentMethodsLoading) return
    const methods = pageProps.orgPaymentMethods as Record<string, boolean> | undefined
    if (!methods) return
    if (methods[paymentMethod] === false) {
      const fallback = (
        ["stripe_card", "stripe_ach", "paypal", "venmo", "venmo_manual", "cash_app_pay", "cashapp", "zelle"] as const
      ).find((m) => methods[m])
      if (fallback) {
        setPaymentMethod(fallback)
      }
    }
  }, [pageProps.orgPaymentMethods, hasOrgSelected, paymentMethodsLoading, paymentMethod])

  /** Inertia partial reload: organizations (search), feePreview, org payment methods. */
  useEffect(() => {
    if (donatePartialReloadSkipRef.current) {
      donatePartialReloadSkipRef.current = false
      return
    }
    const t = window.setTimeout(() => {
      const q: Record<string, string | number> = {}
      const sq = searchQuery.trim()
      if (sq) q.search = sq
      if (selectedCause?.organization_id) {
        q.organization_id = selectedCause.organization_id
      } else if (!listingFilterLocked) {
        const orgParam = new URLSearchParams(window.location.search).get("organization_id")
        if (orgParam) q.organization_id = orgParam
      }
      const baseUsd = (selectedAmount ?? Number.parseFloat(customAmount)) || 0
      if (isStripeRail && baseUsd > 0) {
        q.fee_preview_amount = baseUsd
        q.fee_preview_donor_covers = donorCoversProcessingFees ? 1 : 0
        q.fee_preview_rail = feePreviewRail
      }
      setFeePreviewLoading(true)
      setIsSearchingOrganizations(true)
      router.get(route("donate"), q, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        only: ["organizations", "searchQuery", "feePreview", "feePreviewCheckoutTotalsByRail", "secondaryOrganizations", "orgPaymentMethods"],
        onFinish: () => {
          setFeePreviewLoading(false)
          setIsSearchingOrganizations(false)
        },
        onCancel: () => {
          setFeePreviewLoading(false)
          setIsSearchingOrganizations(false)
        },
      })
    }, 300)
    return () => clearTimeout(t)
  }, [searchQuery, selectedAmount, customAmount, paymentMethod, donorCoversProcessingFees, feePreviewRail, listingFilterLocked, isStripeRail, selectedCause?.organization_id])

  const primaryCause = useMemo(() => {
    const primaryId = organizationFilterLock?.primary_id
    if (!primaryId) return undefined
    return (
      organizations.find((c) => c.organization_id === primaryId) ??
      donatedCauses.find((c) => c.organization_id === primaryId) ??
      secondaryOrganizations.find((c) => c.organization_id === primaryId) ??
      (organizations.length === 1 ? organizations[0] : undefined)
    )
  }, [organizationFilterLock?.primary_id, organizations, donatedCauses, secondaryOrganizations])

  const primaryCauseForDropdown = useMemo((): DonateCause | undefined => {
    if (primaryCause) return primaryCause
    const primaryId = organizationFilterLock?.primary_id
    const name = organizationFilterLock?.primary_name
    if (!primaryId || !name) return undefined
    return {
      id: `org-${primaryId}`,
      kind: "organization",
      organization_id: primaryId,
      name,
      description: "No description available.",
      image: null,
      raised: 0,
      goal: 0,
      supporters: 0,
    }
  }, [primaryCause, organizationFilterLock?.primary_id, organizationFilterLock?.primary_name])

  const donateToPrimaryOrganization = listingFilterLocked

  const primaryDisplayName =
    primaryCause?.name ?? organizationFilterLock?.primary_name ?? ""

  useEffect(() => {
    if (donateToPrimaryOrganization && primaryCauseForDropdown) {
      setSelectedCauseId(primaryCauseForDropdown.id)
      setIsSearchFocused(false)
    }
  }, [donateToPrimaryOrganization, primaryCauseForDropdown?.id])

  const handleDonateToPrimaryToggle = (checked: boolean) => {
    setDonateToPrimary(checked)

    if (checked) {
      if (primaryCauseForDropdown) {
        setSelectedCauseId(primaryCauseForDropdown.id)
        loadedPaymentMethodsOrgIdRef.current = null
      }
      setSearchQuery("")
      setIsSearchFocused(false)
      router.get(route("donate"), {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ["organizations", "donatedCauses", "organizationFilterLock", "secondaryOrganizations"],
      })
      return
    }

    setSelectedCauseId(null)
    loadedPaymentMethodsOrgIdRef.current = null
    setSearchQuery("")
    setIsSearchFocused(true)
    router.get(route("donate"), { organization_id: "all" }, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      only: ["organizations", "donatedCauses", "organizationFilterLock", "secondaryOrganizations"],
    })
  }

  const primaryCausesFiltered = useMemo(() => {
    if (!primaryCauseForDropdown) return []
    if (!searchQuery.trim()) return [primaryCauseForDropdown]
    return causeMatchesSearch(primaryCauseForDropdown, searchQuery) ? [primaryCauseForDropdown] : []
  }, [primaryCauseForDropdown, searchQuery])

  const secondaryCausesFiltered = useMemo(() => {
    if (!searchQuery.trim()) {
      return secondaryOrganizations
    }
    return secondaryOrganizations.filter((c) => causeMatchesSearch(c, searchQuery))
  }, [secondaryOrganizations, searchQuery])


  const donatedCausesFiltered = useMemo(() => {
    const primaryOrgId = organizationFilterLock?.primary_id
    return donatedCauses
      .filter((c) => causeMatchesSearch(c, searchQuery))
      .filter((c) => !primaryOrgId || c.organization_id !== primaryOrgId)
  }, [donatedCauses, searchQuery, organizationFilterLock?.primary_id])

  const donationSubscriptionModalRecipientKind = useMemo((): "organization" | "care_alliance" => {
    if (selectedCause?.kind === "care_alliance") {
      return "care_alliance"
    }
    if (donationMode === "non_cash" && nonCashPreferredOrgId != null) {
      const row = organizations.find((o) => o.organization_id === nonCashPreferredOrgId)
      if (row?.kind === "care_alliance") {
        return "care_alliance"
      }
    }
    return "organization"
  }, [selectedCause, donationMode, nonCashPreferredOrgId, organizations])

  const handleCauseSelect = (id: string) => {
    loadedPaymentMethodsOrgIdRef.current = null
    setSelectedCauseId(id)
    setSearchQuery("") // Clear search query after selection
    setIsSearchFocused(false) // Hide search results
  }

  const handleClearCauseSelection = () => {
    loadedPaymentMethodsOrgIdRef.current = null
    setSelectedCauseId(null)
    setSearchQuery("") // Clear search query when clearing selection
    setIsSearchFocused(true) // Show search input again
  }

  /** Quick-pick recipients for Donate Instant: primary, secondaries, and previously supported. */
  const instantCauses = useMemo<InstantDonateCause[]>(() => {
    const pool: DonateCause[] = [
      ...(primaryCauseForDropdown ? [primaryCauseForDropdown] : []),
      ...((secondaryOrganizations as DonateCause[]) ?? []),
      ...((donatedCauses as DonateCause[]) ?? []),
      ...((organizations as DonateCause[]) ?? []),
    ]
    const seen = new Set<string>()
    const result: InstantDonateCause[] = []
    for (const cause of pool) {
      if (!cause?.organization_id || seen.has(cause.id)) continue
      seen.add(cause.id)
      result.push({
        id: cause.id,
        kind: cause.kind,
        organization_id: cause.organization_id,
        name: cause.name,
        description: cause.description,
        image: cause.image,
        care_alliance_id: cause.care_alliance_id,
      })
    }
    return result
  }, [primaryCauseForDropdown, secondaryOrganizations, donatedCauses, organizations])

  const defaultInstantCause = useMemo<InstantDonateCause | undefined>(() => {
    if (primaryCauseForDropdown?.organization_id) {
      return instantCauses.find((c) => c.id === primaryCauseForDropdown.id) ?? instantCauses[0]
    }
    return instantCauses[0]
  }, [instantCauses, primaryCauseForDropdown])

  const handleInstantDonate = (payload: InstantDonatePayload) => {
    setSubmissionError(null)
    setIsSubmitting(true)
    router.post("/donate", {
      ...payload,
      message: "",
      name,
      email,
      phone,
    }, {
      onStart: () => setIsSubmitting(true),
      onFinish: () => setIsSubmitting(false),
      onError: (errors) => {
        if (errors.subscription || (flash as any)?.subscription_required) {
          setShowSubscriptionModal(true)
          setSubmissionError(null)
        } else {
          setSubmissionError(
            errors.subscription || errors.payment_method || errors.message ||
            "Failed to process donation. Please try again.",
          )
        }
      },
    })
  }

  const handleSubmit = async () => {
    setSubmissionError(null)
    setIsSubmitting(true)

    if (!selectedCauseId || !selectedCause) {
      setSubmissionError("Please select a non-profit organization.")
      setIsSubmitting(false)
      return
    }

    if (getCurrentAmount() <= 0) {
      setSubmissionError("Please enter a valid donation amount.")
      setIsSubmitting(false)
      return
    }

    const donationData = {
      organization_id: selectedCause.organization_id,
      recipient_kind: selectedCause.kind,
      care_alliance_id:
        selectedCause.kind === "care_alliance" && selectedCause.care_alliance_id != null
          ? selectedCause.care_alliance_id
          : undefined,
      amount: getCurrentAmount(),
      frequency: 'one-time',
      message: donorMessage,
      payment_method: paymentMethod,
      donor_covers_processing_fees: isStripeRail ? donorCoversProcessingFees : false,
      donation_fee_rail: isStripeRail ? feePreviewRail : undefined,
      ...(isStripeRail && savedPaymentMethodId
        ? { saved_payment_method_id: savedPaymentMethodId }
        : {}),
      name: name,
      email: email,
      phone: phone,
    }

    // In a real Inertia.js application, you would use:
    router.post("/donate", donationData, {
      onStart: () => setIsSubmitting(true),
      onFinish: () => setIsSubmitting(false),
      onSuccess: () => {

      },
      onError: (errors) => {
        // Check if subscription is required
        if (errors.subscription || (flash as any)?.subscription_required) {
          setShowSubscriptionModal(true)
          setSubmissionError(null)
        } else {
          setSubmissionError(errors.subscription || errors.payment_method || errors.message || "Failed to process donation. Please try again.")
        }
        console.error("Donation submission errors:", errors)
      },
    })
  }

  const handleNonCashSubmit = () => {
    setSubmissionError(null)
    if (!nonCashItemName.trim()) {
      setSubmissionError("Please enter the donation item name.")
      return
    }
    const value = Number.parseFloat(nonCashEstimatedValue)
    if (Number.isNaN(value) || value < 0) {
      setSubmissionError("Please enter a valid estimated fair market value.")
      return
    }
    if (!nonCashPreferredOrgId) {
      setSubmissionError("Please select a preferred receiving organization.")
      return
    }
    setIsSubmittingNonCash(true)
    router.post(route("donations.non-cash.store"), {
      non_cash_type: nonCashType,
      item_name: nonCashItemName.trim(),
      estimated_fair_market_value: value,
      condition: nonCashCondition,
      organization_id: nonCashPreferredOrgId,
      upload_photos: nonCashUploadPhotos,
    }, {
      preserveState: true,
      onFinish: () => setIsSubmittingNonCash(false),
      onSuccess: () => {
        setNonCashItemName("")
        setNonCashEstimatedValue("")
        setNonCashCondition("New")
        setNonCashPreferredOrgId(null)
        setNonCashSearchQuery("")
        setNonCashUploadPhotos(false)
        showNotification({ type: "success", message: "Your non-cash donation request has been submitted. We'll be in touch shortly." })
      },
      onError: (errors) => {
        const msg = typeof errors?.message === "string" ? errors.message : (errors && Object.values(errors)[0]?.[0])
        setSubmissionError(msg || "Failed to submit donation request. Please try again.")
      },
    })
  }

  return (
    <FrontendLayout>
      <PageHead title={seo?.title ?? "Donate"} description={seo?.description} />
      <div
        className="min-h-screen relative overflow-hidden bg-gradient-to-br from-slate-50 via-purple-50/80 to-blue-50/60 dark:from-gray-950 dark:via-purple-950/40 dark:to-blue-950/30"
      >
        {/* Light-mode speckles */}
        <div
          className="absolute inset-0 pointer-events-none opacity-70 block dark:hidden"
          style={{
            backgroundImage: `radial-gradient(circle at 10% 30%, rgba(147,51,234,0.10) 0%, transparent 2%),
              radial-gradient(circle at 90% 20%, rgba(37,99,235,0.10) 0%, transparent 2%),
              radial-gradient(circle at 50% 70%, rgba(255,255,255,0.35) 0%, transparent 1.5%),
              radial-gradient(circle at 30% 80%, rgba(59,130,246,0.08) 0%, transparent 2%),
              radial-gradient(circle at 70% 50%, rgba(126,34,206,0.08) 0%, transparent 2%)`,
          }}
        />

        {/* Dark-mode speckles (existing style) */}
        <div
          className="absolute inset-0 pointer-events-none opacity-40 hidden dark:block"
          style={{
            backgroundImage: `radial-gradient(circle at 10% 30%, rgba(147,51,234,0.18) 0%, transparent 2%),
              radial-gradient(circle at 90% 20%, rgba(37,99,235,0.18) 0%, transparent 2%),
              radial-gradient(circle at 50% 70%, rgba(255,255,255,0.06) 0%, transparent 1.5%),
              radial-gradient(circle at 30% 80%, rgba(59,130,246,0.12) 0%, transparent 2%),
              radial-gradient(circle at 70% 50%, rgba(126,34,206,0.12) 0%, transparent 2%)`,
          }}
        />
        <div className="container relative mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
          {/* Back to Home */}
          <Link
            href="/"
            className="inline-flex items-center gap-2 text-sm font-medium text-slate-900/70 hover:text-slate-900 dark:text-white/70 dark:hover:text-white mb-6 transition-colors"
          >
            <ArrowLeft className="h-4 w-4" />
            Back to Home
          </Link>

          {/* Page header: branding | title + subtitle | user profile */}
          <motion.header
            initial={{ opacity: 0, y: -10 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.4 }}
            className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-6 mb-8 sm:mb-10"
          >
            {/* Left: BELIEVE IN UNITY branding */}
            <SiteTitle className="shrink-0 order-2 lg:order-1 justify-center lg:justify-start" />

            {/* Center: Make a Difference Today + subtitle */}
            <div className="text-center order-1 lg:order-2 flex-1 px-0 lg:px-4">
              <h1 className="text-2xl sm:text-3xl md:text-4xl font-bold mb-1 bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent">
                Make a Difference Today
              </h1>
              <p className="text-sm sm:text-base text-slate-900/80 max-w-xl mx-auto dark:text-white/80">
                Your donation, no matter the size, creates real impact in communities worldwide.
              </p>
            </div>

            {/* Right: User profile (when logged in) */}
            <div className="flex items-center justify-center lg:justify-end gap-3 shrink-0 order-3">
              {authUser ? (
                <div className="flex items-center gap-3 rounded-xl border border-slate-200/70 bg-white/70 dark:border-white/10 dark:bg-white/5 px-4 py-2.5 backdrop-blur-sm">
                  <Avatar className="h-9 w-9 border border-slate-200/70 dark:border-white/20">
                    <AvatarImage src={typeof (authUser as any).image === 'string' && (authUser as any).image ? ((authUser as any).image.startsWith('/') ? (authUser as any).image : `/${(authUser as any).image}`) : undefined} />
                    <AvatarFallback className="bg-purple-100 text-purple-800 text-sm dark:bg-purple-900/50 dark:text-purple-100">
                      {(authUser as any).name?.charAt(0)?.toUpperCase() || "U"}
                    </AvatarFallback>
                  </Avatar>
                  <div className="text-left min-w-0">
                    <p className="font-medium text-slate-900 text-sm truncate dark:text-white">{(authUser as any).name}</p>
                    <p className="flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400">
                      <Check className="h-3.5 w-3.5 shrink-0" />
                      {(authUser as any).role === "organization" || (authUser as any).role === "organization_pending" ? "Organization" : "Verified Supporter"}
                    </p>
                  </div>
                </div>
              ) : (
                <Link
                  href={route("login")}
                  className="text-sm font-medium text-slate-900/70 hover:text-slate-900 dark:text-white/80 dark:hover:text-white transition-colors"
                >
                  Sign in
                </Link>
              )}
            </div>
          </motion.header>

          {/* Donation entry points: Instant / Cash-Points / Non-Cash */}
          {donationMode !== "instant" && (
            <motion.div
              initial={{ opacity: 0 }}
              animate={{ opacity: 1 }}
              className="mb-8 grid grid-cols-1 items-stretch gap-4 md:grid-cols-2 lg:grid-cols-3"
            >
              {/* Donate Instant — green 3D */}
              <button
                type="button"
                onClick={() => {
                  setSubmissionError(null)
                  setDonationMode("instant")
                }}
                className="group relative flex h-full w-full items-center gap-4 overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-500 via-green-600 to-green-800 px-5 py-5 text-left text-white shadow-xl shadow-emerald-700/40 ring-1 ring-white/15 transition-all duration-300 hover:-translate-y-1 hover:shadow-2xl hover:shadow-emerald-700/55 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70"
              >
                <span aria-hidden className="pointer-events-none absolute inset-x-0 top-0 h-1/2 bg-gradient-to-b from-white/30 to-transparent" />
                <span className="relative flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-white/20 shadow-inner ring-1 ring-white/40">
                  <Zap className="h-6 w-6 drop-shadow-sm" />
                </span>
                <span className="relative min-w-0 flex-1">
                  <span className="block text-base font-bold sm:text-lg">Donate Instant</span>
                  <span className="mt-0.5 block text-xs text-white/90 sm:text-sm">Quick impact. One click. Make a difference now.</span>
                </span>
                <ChevronRight className="relative h-6 w-6 shrink-0 text-white/85 transition-transform group-hover:translate-x-0.5" />
              </button>

              {/* Donate Cash / Points — purple → blue 3D */}
              <button
                type="button"
                onClick={() => setDonationMode("cash_points")}
                className={cn(
                  "group relative flex h-full w-full items-center gap-4 overflow-hidden rounded-2xl bg-gradient-to-br from-purple-600 via-purple-700 to-blue-800 px-5 py-5 text-left text-white shadow-xl shadow-purple-700/40 ring-1 ring-white/15 transition-all duration-300 hover:-translate-y-1 hover:shadow-2xl hover:shadow-purple-700/55 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70",
                  donationMode === "cash_points" && "ring-2 ring-white/70",
                )}
              >
                <span aria-hidden className="pointer-events-none absolute inset-x-0 top-0 h-1/2 bg-gradient-to-b from-white/30 to-transparent" />
                <span className="relative flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-white/20 shadow-inner ring-1 ring-white/40">
                  <Coins className="h-6 w-6 drop-shadow-sm" />
                </span>
                <span className="relative min-w-0 flex-1">
                  <span className="block text-base font-bold sm:text-lg">Donate Cash</span>
                  <span className="mt-0.5 block text-xs text-white/90 sm:text-sm">Give using card, bank transfer, or other cash payment methods.</span>
                </span>
                <ChevronRight className="relative h-6 w-6 shrink-0 text-white/85 transition-transform group-hover:translate-x-0.5" />
              </button>

              {/* Donate Non-Cash Asset — dark slate 3D */}
              <button
                type="button"
                onClick={() => setDonationMode("non_cash")}
                className={cn(
                  "group relative flex h-full w-full items-center gap-4 overflow-hidden rounded-2xl bg-gradient-to-br from-slate-800 via-slate-900 to-black px-5 py-5 text-left text-white shadow-xl shadow-black/50 ring-1 ring-white/10 transition-all duration-300 hover:-translate-y-1 hover:shadow-2xl hover:shadow-black/65 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-400/80 md:col-span-2 lg:col-span-1",
                  donationMode === "non_cash" && "ring-2 ring-purple-400/70",
                )}
              >
                <span aria-hidden className="pointer-events-none absolute inset-x-0 top-0 h-1/2 bg-gradient-to-b from-white/15 to-transparent" />
                <span className="relative flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-white/10 shadow-inner ring-1 ring-white/20">
                  <Gift className="h-6 w-6 drop-shadow-sm" />
                </span>
                <span className="relative min-w-0 flex-1">
                  <span className="block text-base font-bold sm:text-lg">Donate Non-Cash Asset</span>
                  <span className="mt-0.5 block text-xs text-white/75 sm:text-sm">Donate stocks, crypto, or other assets.</span>
                </span>
                <ChevronRight className="relative h-6 w-6 shrink-0 text-white/75 transition-transform group-hover:translate-x-0.5" />
              </button>
            </motion.div>
          )}

          {donationMode === "instant" ? (
            <InstantDonate
              causes={instantCauses}
              defaultCause={defaultInstantCause}
              savedPaymentMethods={savedPaymentMethods}
              paymentMethodsUrl={paymentMethodsUrl}
              processingFeeRates={processingFeeRates}
              isSubmitting={isSubmitting}
              submissionError={submissionError}
              onBack={() => {
                setSubmissionError(null)
                setDonationMode("cash_points")
              }}
              onDonate={handleInstantDonate}
            />
          ) : donationMode === "cash_points" ? (
          <>
          {/* Three glass cards — Cash donations */}
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8 items-start">
            {/* Card 1: Select Your Donation */}
            <motion.div
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.5 }}
              className={cn(DONATE_CARD, "relative z-30 overflow-visible")}
            >
              <div className={cn(DONATE_CARD_HEADER, "rounded-t-2xl px-5 py-4 flex items-center gap-2")}>
                <Heart className="h-5 w-5 text-purple-600 fill-purple-500/30 shrink-0 dark:text-purple-400" />
                <h2 className="text-lg font-bold text-slate-900 dark:text-white">Select Your Donation</h2>
              </div>
              <div className="p-5 space-y-5 rounded-b-2xl">
                {organizationFilterLock?.primary_id ? (
                  <div className="space-y-2">
                    <div className="flex items-center justify-between gap-3">
                      <div className="flex min-w-0 items-center gap-2">
                        <label
                          htmlFor="donate-to-primary-toggle"
                          className="text-sm font-semibold text-slate-900 dark:text-white"
                        >
                          Donate to my Primary Organization
                        </label>
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <button
                              type="button"
                              className="shrink-0 text-slate-500 hover:text-purple-600 dark:text-white/50 dark:hover:text-purple-300"
                              aria-label="About primary organization donations"
                            >
                              <Info className="h-4 w-4" />
                            </button>
                          </TooltipTrigger>
                          <TooltipContent side="top" className="max-w-xs">
                            {primaryOrganizationLocked
                              ? "Your primary organization is set in My Profile and stays locked here so your impact and rewards stay consistent."
                              : "Donations go to your primary organization by default. Turn off to search and choose another nonprofit."}
                          </TooltipContent>
                        </Tooltip>
                      </div>
                      <Switch
                        id="donate-to-primary-toggle"
                        checked={donateToPrimaryOrganization}
                        onCheckedChange={handleDonateToPrimaryToggle}
                        aria-label="Donate to my Primary Organization"
                      />
                    </div>
                    {primaryOrganizationLocked ? (
                      <p className="text-xs text-slate-600 dark:text-white/60">
                        Your primary organization is locked in profile settings, but you can turn this off to donate
                        to your secondary organizations.{" "}
                        <Link
                          href={route("user.profile.edit")}
                          className="font-medium text-purple-600 hover:text-purple-700 dark:text-purple-400 dark:hover:text-purple-300"
                        >
                          Manage in My Profile
                        </Link>
                      </p>
                    ) : (
                      <p className="text-xs text-slate-600 dark:text-white/60">
                        Turn off to donate to your secondary organizations saved in My Profile.
                      </p>
                    )}
                  </div>
                ) : (
                  <p className="text-sm text-slate-600 dark:text-white/70">Choose amount to donate.</p>
                )}

                <div className="relative" ref={searchContainerRef}>
                  <div className="relative">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-purple-500/70 dark:text-purple-300/70" />
                    <Input
                      type="text"
                      placeholder="Search for non-profit organization..."
                      value={donateToPrimaryOrganization ? "" : searchQuery}
                      readOnly={donateToPrimaryOrganization}
                      disabled={donateToPrimaryOrganization}
                      onChange={(e) => setSearchQuery(e.target.value)}
                      onFocus={() => {
                        if (!donateToPrimaryOrganization) setIsSearchFocused(true)
                      }}
                      className={cn(
                        DONATE_SEARCH_INPUT,
                        "pl-10 h-11 text-sm",
                        donateToPrimaryOrganization && "cursor-not-allowed opacity-90",
                      )}
                    />
                    {!donateToPrimaryOrganization && searchQuery && (
                      <button
                        type="button"
                        onClick={() => setSearchQuery("")}
                        aria-label="Clear search"
                        className="absolute right-2 top-1/2 -translate-y-1/2 text-gray-500 hover:text-purple-700 dark:text-white/60 dark:hover:text-white"
                      >
                        <X className="h-4 w-4" />
                      </button>
                    )}
                  </div>

                  {donateToPrimaryOrganization && primaryCauseForDropdown ? (
                    <div className={DONATE_LOCKED_PRIMARY_PANEL}>
                      <div className={DONATE_DROPDOWN_SECTION}>
                        Your primary organization
                        {primaryOrganizationLocked ? " (locked)" : ""}
                      </div>
                      <div
                        className={cn(
                          DONATE_DROPDOWN_ITEM,
                          "pointer-events-none cursor-default border-b-0",
                        )}
                      >
                        <CauseAvatar
                          name={primaryDisplayName}
                          src={primaryCauseForDropdown.image}
                          shape="square"
                          className="h-9 w-9"
                        />
                        <div className="min-w-0 flex flex-1 flex-col gap-1">
                          <div className="font-medium text-sm truncate">{primaryDisplayName}</div>
                          <Badge className="inline-flex w-fit items-center gap-1 border-0 bg-gradient-to-r from-purple-600 to-blue-600 px-2 py-0.5 text-xs font-semibold text-white shadow-sm hover:from-purple-600 hover:to-blue-600">
                            <Building2 className="h-3 w-3 shrink-0" aria-hidden />
                            Primary Organization
                          </Badge>
                        </div>
                        <Lock
                          className="h-5 w-5 shrink-0 text-slate-600 dark:text-white/75"
                          aria-hidden
                        />
                      </div>
                      {primaryOrganizationLocked && (
                        <div className="flex gap-3 border-t border-purple-100/80 bg-blue-50/80 p-3 dark:border-purple-800/35 dark:bg-blue-950/30">
                          <Shield className="h-5 w-5 shrink-0 text-blue-600 dark:text-blue-400" aria-hidden />
                          <p className="text-sm text-slate-700 dark:text-white/80">
                            Your primary organization is locked in your profile. To change it, go to{" "}
                            <Link
                              href={route("user.profile.edit")}
                              className="font-semibold text-blue-700 hover:text-blue-800 dark:text-blue-300 dark:hover:text-blue-200"
                            >
                              My Profile
                            </Link>
                            .
                          </p>
                        </div>
                      )}
                    </div>
                  ) : null}

                  {!donateToPrimaryOrganization && (
                    <AnimatePresence>
                      {selectedCause && (
                        <motion.div
                          initial={{ opacity: 0, height: 0 }}
                          animate={{ opacity: 1, height: "auto" }}
                          exit={{ opacity: 0, height: 0 }}
                          className={DONATE_SELECTED_ORG}
                        >
                          <div className="flex items-center gap-2 min-w-0">
                            <CauseAvatar name={selectedCause.name} src={selectedCause.image} className="h-8 w-8" />
                            <div className="min-w-0 flex flex-col gap-1">
                              <span className="font-medium text-slate-900 truncate dark:text-white">{selectedCause.name}</span>
                              <CauseKindBadge kind={selectedCause.kind} />
                            </div>
                          </div>
                          <Button
                            variant="ghost"
                            size="icon"
                            className="shrink-0 h-8 w-8 text-slate-700/80 hover:text-slate-900 hover:bg-white/80 dark:text-white/80 dark:hover:text-white dark:hover:bg-white/10"
                            onClick={handleClearCauseSelection}
                          >
                            <X className="h-4 w-4" />
                          </Button>
                        </motion.div>
                      )}
                    </AnimatePresence>
                  )}
                  {!donateToPrimaryOrganization &&
                    isSearchFocused &&
                    !selectedCause &&
                    (searchQuery ||
                      donatedCauses.length > 0 ||
                      secondaryOrganizations.length > 0 ||
                      primaryCauseForDropdown) && (
                    <div className={cn(DONATE_DROPDOWN, "max-h-72")}>
                      {isSearchingOrganizations ? (
                        <div className="p-3 text-center text-sm text-purple-700/70 dark:text-purple-200/70 flex items-center justify-center gap-2">
                          <Loader2 className="h-4 w-4 animate-spin text-purple-600 dark:text-purple-400" /> Searching...
                        </div>
                      ) : donatedCausesFiltered.length === 0 &&
                        secondaryCausesFiltered.length === 0 &&
                        primaryCausesFiltered.length === 0 ? (
                        <p className="p-3 text-sm text-gray-500 dark:text-purple-200/60">No organizations found.</p>
                      ) : (
                        <>
                          {primaryCausesFiltered.length > 0 && (
                            <div role="group" aria-label="Your primary organization">
                              <div className={DONATE_DROPDOWN_SECTION}>
                                Your primary organization
                              </div>
                              {primaryCausesFiltered.map((cause) => (
                                <button
                                  key={cause.id}
                                  type="button"
                                  className={DONATE_DROPDOWN_ITEM}
                                  onClick={() => handleCauseSelect(cause.id)}
                                >
                                  <CauseAvatar name={cause.name} src={cause.image} shape="square" className="h-9 w-9" />
                                  <div className="min-w-0 flex flex-col gap-1">
                                    <div className="font-medium text-sm truncate">{cause.name}</div>
                                    <CauseKindBadge kind={cause.kind} />
                                  </div>
                                </button>
                              ))}
                            </div>
                          )}
                          {secondaryCausesFiltered.length > 0 && (
                            <div role="group" aria-label="Your secondary organizations">
                              <div className={DONATE_DROPDOWN_SECTION}>
                                Your secondary organizations
                              </div>
                              {secondaryCausesFiltered.map((cause) => (
                                <button
                                  key={cause.id}
                                  type="button"
                                  className={DONATE_DROPDOWN_ITEM}
                                  onClick={() => handleCauseSelect(cause.id)}
                                >
                                  <CauseAvatar name={cause.name} src={cause.image} shape="square" className="h-9 w-9" />
                                  <div className="min-w-0 flex flex-col gap-1">
                                    <div className="font-medium text-sm truncate">{cause.name}</div>
                                    <CauseKindBadge kind={cause.kind} />
                                  </div>
                                </button>
                              ))}
                            </div>
                          )}
                          {donatedCausesFiltered.length > 0 && (
                            <div role="group" aria-label="Organizations you have donated to">
                              <div className={DONATE_DROPDOWN_SECTION}>
                                Organizations you&apos;ve supported
                              </div>
                              {donatedCausesFiltered.map((cause) => (
                                <button
                                  key={cause.id}
                                  type="button"
                                  className={DONATE_DROPDOWN_ITEM}
                                  onClick={() => handleCauseSelect(cause.id)}
                                >
                                  <CauseAvatar name={cause.name} src={cause.image} shape="square" className="h-9 w-9" />
                                  <div className="min-w-0 flex flex-col gap-0.5 flex-1">
                                    <div className="font-medium text-sm truncate">{cause.name}</div>
                                    <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                      <CauseKindBadge kind={cause.kind} />
                                      {typeof cause.donated_total === "number" && cause.donated_total > 0 && (
                                        <span className="text-xs text-slate-600 dark:text-white/55">
                                          You gave $
                                          {cause.donated_total.toLocaleString("en-US", {
                                            minimumFractionDigits: 0,
                                            maximumFractionDigits: 2,
                                          })}
                                        </span>
                                      )}
                                    </div>
                                  </div>
                                </button>
                              ))}
                            </div>
                          )}
                        </>
                      )}
                    </div>
                  )}
                </div>
                {/* Amount buttons */}
                <div className="grid grid-cols-3 gap-2">
                  {amountConfig.map(({ amount, badge, impact }) => (
                    <button
                      key={amount}
                      type="button"
                      onClick={() => handleAmountSelect(amount)}
                      className={`relative rounded-xl border-2 p-3 text-center transition-all ${
                        selectedAmount === amount
                          ? "border-purple-600 bg-purple-50 text-purple-900 shadow-sm dark:border-purple-500 dark:bg-purple-950/40 dark:text-white"
                          : "border-purple-100/80 bg-white/80 hover:border-purple-400 hover:bg-purple-50/60 text-gray-800 dark:border-purple-800/30 dark:bg-white/[0.04] dark:hover:bg-purple-950/25 dark:text-white"
                      }`}
                    >
                      {badge && (
                        <span className="absolute -top-1.5 left-1/2 -translate-x-1/2 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-gradient-to-r from-purple-600 to-blue-600 text-white whitespace-nowrap">
                          {badge}
                        </span>
                      )}
                      <span className="block text-lg font-bold">${amount}</span>
                      {impact && <span className="block text-xs text-slate-600/70 mt-0.5 dark:text-white/60">{impact}</span>}
                    </button>
                  ))}
                </div>
                <div>
                  <Label className="text-xs text-slate-600/70 mb-1 block dark:text-white/70">Other amount</Label>
                  <div className="relative">
                    <span className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-600/70 dark:text-white/60">$</span>
                    <Input
                      type="number"
                      placeholder="0.00"
                      value={customAmount}
                      onChange={(e) => handleCustomAmountChange(e.target.value)}
                      className="pl-8 h-11 rounded-lg border-slate-200/60 bg-white/60 text-slate-900 placeholder:text-slate-500 focus-visible:ring-purple-600/30 focus-visible:border-purple-600 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-white/40"
                    />
                  </div>
                </div>
              </div>
            </motion.div>

            {/* Card 2: Pay Securely */}
            <motion.div
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.5, delay: 0.1 }}
              className={cn(DONATE_CARD, "overflow-hidden")}
            >
              <div className={cn(DONATE_CARD_HEADER, "px-5 py-4 flex items-center gap-2")}>
                <Lock className="h-5 w-5 text-blue-600 shrink-0 dark:text-blue-400" />
                <h2 className="text-lg font-bold text-slate-900 dark:text-white">Choose Payment Method</h2>
              </div>
              <DonationPaymentMethods
                paymentMethod={paymentMethod}
                onPaymentMethodChange={setPaymentMethod}
                availableMethods={availablePaymentMethods}
                hasOrgSelected={hasOrgSelected}
                paymentMethodsLoading={paymentMethodsLoading}
                currentBalance={currentBalance}
                availableBalance={availableBalance}
                processingBalance={processingBalance}
                canUseBelievePoints={canUseBelievePoints}
                amount={getCurrentAmount()}
                feePreviewRail={feePreviewRail}
                onFeePreviewRailChange={setFeePreviewRail}
                donorCoversProcessingFees={donorCoversProcessingFees}
                onDonorCoversChange={setDonorCoversProcessingFees}
                feePreview={feePreview}
                feePreviewLoading={feePreviewLoading}
                feePreviewCheckoutTotalsByRail={feePreviewCheckoutTotalsByRail}
                processingFeeRates={processingFeeRates}
                savedPaymentMethods={savedPaymentMethods}
                savedPaymentMethodId={savedPaymentMethodId}
                onSavedPaymentMethodChange={setSavedPaymentMethodId}
                paymentMethodsUrl={paymentMethodsUrl}
                authUser={Boolean(authUser)}
                rewardPointsAmount={rewardPointsAmount}
              />
            </motion.div>

            {/* Card 3: Your Year-to-Date Giving */}
            <motion.div
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.5, delay: 0.2 }}
              className={cn(DONATE_CARD, "overflow-hidden")}
            >
              <div className={cn(DONATE_CARD_HEADER, "px-5 py-4")}>
                <h2 className="text-lg font-bold text-gray-900 dark:text-white">Your Year-to-Date Giving</h2>
              </div>
              <div className="p-5 space-y-4">
                <div>
                  <div className="text-2xl font-bold text-slate-900 dark:text-white">
                    ${typeof thisYearDonated === 'number' ? thisYearDonated.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) : 0}
                  </div>
                  <div className="text-sm text-slate-600/70 dark:text-white/60">/ ${givingGoal.toLocaleString()} Goal</div>
                </div>
                <div className="h-2.5 rounded-full bg-slate-200/50 dark:bg-white/20 overflow-hidden">
                  <div
                    className="h-full rounded-full bg-gradient-to-r from-purple-600 to-blue-600 transition-all duration-500"
                    style={{ width: `${givingProgress}%` }}
                  />
                </div>
                <div className="rounded-xl border border-purple-200/60 bg-gradient-to-r from-purple-50/90 to-blue-50/50 p-4 dark:border-purple-700/40 dark:from-purple-900/35 dark:to-blue-950/25">
                  <div className="flex items-center gap-2 text-sm font-semibold text-purple-800 dark:text-purple-200">
                    <Gift className="h-4 w-4 shrink-0" />
                    You will receive +{rewardPointsAmount} BRP (Believe Reward Points) after confirmation
                  </div>
                </div>
                {topOrganizations.length > 0 ? (
                  <div className="space-y-2">
                    <div className="text-sm font-semibold text-slate-900 dark:text-white">Top 3 Organizations</div>
                    <ul className="space-y-2">
                      {topOrganizations.slice(0, 3).map((org, i) => {
                        const Icon = TOP_ORG_ICONS[i] ?? Building2
                        return (
                          <li key={i} className="flex items-center gap-2 text-sm text-slate-700/90 dark:text-white/80">
                            <Icon className="h-4 w-4 text-blue-500 shrink-0 dark:text-blue-400" />
                            <span className="truncate flex-1">{org.name}</span>
                            <span className="font-medium text-slate-900 shrink-0 dark:text-white">
                              ${org.total.toLocaleString('en-US', { minimumFractionDigits: 2 })}
                            </span>
                          </li>
                        )
                      })}
                    </ul>
                  </div>
                ) : (
                  <p className="text-sm text-slate-600/70 dark:text-white/60">Your top supported organizations will appear here after you donate.</p>
                )}
                <Link
                  href={route("profile.donations")}
                  className="inline-flex items-center justify-center w-full py-2.5 rounded-xl border border-gray-200 bg-white hover:bg-purple-50 hover:border-purple-500 text-gray-900 dark:border-gray-600 dark:bg-gray-800/50 dark:hover:bg-purple-950/30 dark:hover:border-purple-500 dark:text-white font-medium text-sm transition-all"
                >
                  View Giving Dashboard
                </Link>
              </div>
            </motion.div>
          </div>

          {/* Make My Impact CTA + Trust footer — Cash/Points only */}
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            transition={{ duration: 0.5, delay: 0.3 }}
            className="mt-8 space-y-6"
          >
            {submissionError && (
              <div className="rounded-xl border border-red-300/60 bg-red-50/80 dark:border-red-400/30 dark:bg-red-500/20 backdrop-blur-sm p-4 flex items-start gap-3">
                <X className="h-5 w-5 text-red-600 dark:text-red-300 shrink-0 mt-0.5" />
                <p className="text-sm font-medium text-red-800 dark:text-red-200">{submissionError}</p>
              </div>
            )}
            <Button
              size="lg"
              className="w-full max-w-2xl mx-auto flex h-14 text-lg font-bold bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white shadow-lg shadow-blue-500/20 rounded-xl border-0"
              onClick={handleSubmit}
              disabled={getCurrentAmount() === 0 || !selectedCauseId || isSubmitting}
            >
              {isSubmitting ? (
                <>
                  <Loader2 className="mr-2 h-5 w-5 animate-spin" /> Processing...
                </>
              ) : (
                <>
                  Make My Impact <span className="ml-1">→</span>
                </>
              )}
            </Button>
          </motion.div>
          </>
          ) : (
          /* ——— Non-Cash Asset: three panels ——— */
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 lg:gap-8 items-start">
            {/* Left: Select a type */}
            <motion.div
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              className={cn(DONATE_CARD, "overflow-hidden")}
            >
              <div className={cn(DONATE_CARD_HEADER, "px-5 py-4")}>
                <h2 className="text-lg font-bold text-gray-900 dark:text-white">Donate Non-Cash Asset</h2>
              </div>
              <div className="p-5 space-y-3">
                <p className="text-sm text-slate-600/70 dark:text-white/70">Select a type:</p>
                {NON_CASH_TYPES.map(({ id, label, icon: Icon }) => (
                  <button
                    key={id}
                    type="button"
                    onClick={() => setNonCashType(id)}
                    className={`w-full flex items-center justify-between gap-3 p-3 rounded-xl border-2 text-left transition-all ${
                      nonCashType === id
                        ? "border-purple-600 bg-purple-50 text-purple-900 dark:border-purple-500 dark:bg-purple-950/40 dark:text-white"
                        : "border-gray-200 bg-white text-gray-800 hover:bg-purple-50/50 hover:border-purple-400 dark:border-gray-600 dark:bg-gray-800/50 dark:text-white dark:hover:border-purple-500"
                    }`}
                  >
                    <span className="flex items-center gap-2">
                      <Icon className="h-4 w-4 shrink-0" />
                      {label}
                    </span>
                    <span className="text-xs font-medium text-slate-600/70 dark:text-white/70">Donate</span>
                  </button>
                ))}
              </div>
            </motion.div>

            {/* Center: Non-cash form */}
            <motion.div
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.1 }}
              className={cn(DONATE_CARD, "relative z-30 overflow-visible")}
            >
              <div className="rounded-t-2xl px-5 py-4 flex items-center gap-2 border-b border-slate-200/80 dark:border-white/10">
                <FileText className="h-5 w-5 text-purple-300 shrink-0" />
                <h2 className="text-lg font-bold text-slate-900 dark:text-white">Donate a Non-Cash Asset</h2>
              </div>
              <div className="p-5 space-y-4 rounded-b-2xl">
                <div>
                  <Label className="text-sm text-slate-700/80 dark:text-white/80 mb-1 block">Donation Item</Label>
                  <Input
                    placeholder="e.g. Office furniture, laptops..."
                    value={nonCashItemName}
                    onChange={(e) => setNonCashItemName(e.target.value)}
                    className="rounded-lg border-slate-200/60 bg-white/60 text-slate-900 placeholder:text-slate-500 focus-visible:ring-purple-600/30 focus-visible:border-purple-600 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-white/40"
                  />
                </div>
                <div>
                  <Label className="text-sm text-slate-700/80 dark:text-white/80 mb-1 block">Estimated Fair Market Value</Label>
                  <div className="relative">
                    <span className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-600/70 dark:text-white/60">$</span>
                    <Input
                      type="number"
                      step="0.01"
                      placeholder="0.00"
                      value={nonCashEstimatedValue}
                      onChange={(e) => setNonCashEstimatedValue(e.target.value)}
                      className="pl-8 rounded-lg border-slate-200/60 bg-white/60 text-slate-900 placeholder:text-slate-500 focus-visible:ring-purple-600/30 focus-visible:border-purple-600 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-white/40"
                    />
                  </div>
                </div>
                <div>
                  <Label className="text-sm text-slate-700/80 dark:text-white/80 mb-1 block">Condition</Label>
                  <select
                    value={nonCashCondition}
                    onChange={(e) => setNonCashCondition(e.target.value)}
                    className="w-full h-10 rounded-lg border border-slate-200/60 bg-white/60 text-slate-900 px-3 focus:ring-2 focus:ring-purple-600/30 focus:border-purple-600 dark:border-white/20 dark:bg-white/5 dark:text-white"
                  >
                    {CONDITION_OPTIONS.map((opt) => (
                      <option key={opt} value={opt} className="bg-white text-slate-900 dark:bg-purple-950 dark:text-white">{opt}</option>
                    ))}
                  </select>
                </div>
                <LockedPrimaryOrganizationFilter
                  lock={effectiveLock}
                  onUnlock={() => {
                    setDonateToPrimary(false)
                    setIsSearchFocused(true)
                    const q: Record<string, string> = { organization_id: "all" }
                    const sq = searchQuery.trim()
                    if (sq) q.search = sq
                    router.get(route("donate"), q, {
                      preserveState: true,
                      preserveScroll: true,
                      replace: true,
                      only: ["organizations", "donatedCauses", "organizationFilterLock", "secondaryOrganizations"],
                    })
                  }}
                >
                <div className="relative" ref={nonCashOrgSearchRef}>
                  <Label className="text-sm text-slate-700/80 dark:text-white/80 mb-1 block">Preferred Receiving Organization</Label>
                  <div className="relative">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-purple-500/70 dark:text-purple-300/70" />
                    <Input
                      type="text"
                      placeholder="Select an organization"
                      value={
                        nonCashSearchQuery ||
                        (nonCashPreferredOrgId
                          ? organizations.find((o) => o.organization_id === nonCashPreferredOrgId)?.name ?? ""
                          : "")
                      }
                      onChange={(e: React.ChangeEvent<HTMLInputElement>) => {
                        setNonCashSearchQuery(e.target.value)
                        if (nonCashPreferredOrgId) setNonCashPreferredOrgId(null)
                      }}
                      onFocus={() => setNonCashSearchFocused(true)}
                      className={cn(DONATE_SEARCH_INPUT, "pl-10 pr-9 h-10")}
                    />
                    {nonCashPreferredOrgId ? (
                      <button
                        type="button"
                        onClick={() => {
                          setNonCashPreferredOrgId(null)
                          setNonCashSearchQuery("")
                        }}
                        className="absolute right-2 top-1/2 -translate-y-1/2 text-slate-600/70 hover:text-slate-900 dark:text-white/60 dark:hover:text-white p-1"
                        aria-label="Clear organization"
                      >
                        <X className="h-4 w-4" />
                      </button>
                    ) : null}
                  </div>
                  {nonCashSearchFocused && (
                    <div className={cn(DONATE_DROPDOWN, "max-h-48")}>
                      {organizations
                        .filter((o) => !nonCashSearchQuery || o.name.toLowerCase().includes(nonCashSearchQuery.toLowerCase()))
                        .slice(0, 8)
                        .map((org) => (
                          <button
                            key={org.id}
                            type="button"
                            className={cn(DONATE_DROPDOWN_ITEM, "last:border-b-0")}
                            onClick={() => {
                              setNonCashPreferredOrgId(org.organization_id)
                              setNonCashSearchQuery("")
                              setNonCashSearchFocused(false)
                            }}
                          >
                            <CauseAvatar name={org.name} src={org.image} className="h-8 w-8" />
                            <div className="min-w-0 flex flex-col gap-1">
                              <span className="font-medium text-sm truncate">{org.name}</span>
                              <CauseKindBadge kind={org.kind} />
                            </div>
                          </button>
                        ))}
                    </div>
                  )}
                </div>
                </LockedPrimaryOrganizationFilter>
                <label className="flex items-center gap-2 cursor-pointer text-slate-700/80 dark:text-white/80 text-sm">
                  <input
                    type="checkbox"
                    checked={nonCashUploadPhotos}
                    onChange={(e) => setNonCashUploadPhotos(e.target.checked)}
                    className="rounded border-slate-200/60 bg-white/60 text-purple-600 focus:ring-purple-600/30 dark:border-white/20 dark:bg-white/5 dark:text-purple-500"
                  />
                  <Camera className="h-4 w-4 shrink-0" />
                  Upload Photos
                </label>
                {submissionError && (
                  <div className="rounded-lg border border-red-300/60 bg-red-50/80 dark:border-red-400/30 dark:bg-red-500/20 p-3 text-sm text-red-800 dark:text-red-200">
                    {submissionError}
                  </div>
                )}
                <div className="flex flex-col gap-2 pt-2">
                  <Button
                    size="lg"
                    className="w-full bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white border-0 shadow-lg"
                    onClick={handleNonCashSubmit}
                    disabled={isSubmittingNonCash}
                  >
                    {isSubmittingNonCash ? <Loader2 className="h-5 w-5 animate-spin mx-auto" /> : "Submit Donation Request"}
                  </Button>
                  <button
                    type="button"
                    onClick={() => setDonationMode("cash_points")}
                    className="text-sm text-slate-600/70 hover:text-slate-900 dark:text-white/60 dark:hover:text-white transition-colors"
                  >
                    Cancel
                  </button>
                </div>
              </div>
            </motion.div>

            {/* Right: Quick links + Year-to-Date */}
            <motion.div
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: 0.2 }}
              className={cn(DONATE_CARD, "overflow-hidden")}
            >
              <div className={cn(DONATE_CARD_HEADER, "px-5 py-4")}>
                <h2 className="text-lg font-bold text-gray-900 dark:text-white">Donate a Non-Cash Asset</h2>
              </div>
              <div className="p-5 space-y-3">
                {NON_CASH_TYPES.map(({ id, label, icon: Icon }) => (
                  <button
                    key={id}
                    type="button"
                    onClick={() => setNonCashType(id)}
                    className="w-full flex items-center justify-between gap-3 p-3 rounded-xl border border-slate-200/80 bg-white/75 hover:bg-white/90 text-slate-800 text-sm transition-colors dark:border-white/10 dark:bg-white/5 dark:hover:bg-white/10 dark:text-white"
                  >
                    <span className="flex items-center gap-2">
                      <Icon className="h-4 w-4 shrink-0" />
                      {label}
                    </span>
                    <ChevronRight className="h-4 w-4 text-slate-600/50 dark:text-white/50" />
                  </button>
                ))}
                <div className="pt-4 border-t border-slate-200/80 dark:border-white/10">
                  <div className="text-lg font-bold text-slate-900 dark:text-white">
                    ${typeof thisYearDonated === "number" ? thisYearDonated.toLocaleString("en-US", { maximumFractionDigits: 0 }) : 0}
                  </div>
                  <div className="text-sm text-slate-600/70 dark:text-white/60">/ ${givingGoal.toLocaleString()} Goal</div>
                  <div className="h-2 rounded-full bg-slate-200/50 mt-2 overflow-hidden dark:bg-white/20">
                    <div
                      className="h-full rounded-full bg-gradient-to-r from-purple-600 to-blue-600 transition-all"
                      style={{ width: `${givingProgress}%` }}
                    />
                  </div>
                  <Link
                    href={route("profile.donations")}
                    className="inline-flex items-center justify-center w-full py-2.5 rounded-xl border border-gray-200 bg-white hover:bg-purple-50 hover:border-purple-500 text-gray-900 font-medium text-sm mt-3 transition-all dark:border-gray-600 dark:bg-gray-800/50 dark:hover:bg-purple-950/30 dark:hover:border-purple-500 dark:text-white"
                  >
                    View Giving Dashboard
                  </Link>
                </div>
              </div>
            </motion.div>
          </div>
          )}

          {/* Trust footer — cash & non-cash modes (instant view has its own) */}
          {donationMode !== "instant" && (
          <motion.div
            initial={{ opacity: 0 }}
            animate={{ opacity: 1 }}
            className="mt-8 flex flex-wrap items-center justify-center gap-6 text-sm text-slate-600/70 dark:text-white/70"
          >
            <span className="flex items-center gap-1.5">
              <Shield className="h-4 w-4 text-blue-600 dark:text-blue-400" /> 100% Secure Donations
            </span>
            <span className="flex items-center gap-1.5">501(c)(3) EIN: 12-3456789</span>
            <span>IRS Compliant</span>
            <span className="flex items-center gap-1 text-slate-600/70 dark:text-white/60">Stripe · card &amp; ACH</span>
          </motion.div>
          )}
        </div>

        {/* Subscription Required Modal - Supporter View */}
        <SubscriptionRequiredModal
          isOpen={showSubscriptionModal}
          onClose={() => setShowSubscriptionModal(false)}
          feature="donations"
          isSupporterView={true}
          donationRecipientKind={donationSubscriptionModalRecipientKind}
        />
      </div>
    </FrontendLayout>
  )
}
