"use client"

import React, { useState, useEffect, useRef, useMemo, FormEvent } from "react"
import { Head, router, usePage } from "@inertiajs/react"
import ProfileLayout from "@/components/frontend/layout/user-profile-layout"
import AppSidebarLayout from "@/layouts/app/app-sidebar-layout"
import { Button } from "@/components/ui/button"
import { Card, CardContent } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Coins,
  DollarSign,
  ArrowRight,
  CheckCircle2,
  AlertCircle,
  History,
  TrendingUp,
  RefreshCw,
  FileText,
  CreditCard,
  Landmark,
  Loader2,
  Receipt,
  CircleHelp,
  Smartphone,
  Wallet,
  CalendarClock,
} from "lucide-react"
import { showSuccessToast, showErrorToast } from "@/lib/toast"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { cn } from "@/lib/utils"
import { Badge } from "@/components/ui/badge"
import { Checkbox } from "@/components/ui/checkbox"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog"
import {
  SavedPaymentMethodSelector,
  filterMethodsForRail,
  type SavedPaymentMethod,
} from "@/components/account/saved-payment-method-selector"
import { BpBalanceHero } from "@/pages/BelievePoints/components/BpBalanceHero"
import { BpMoveToWalletPopup } from "@/pages/BelievePoints/components/BpMoveToWalletPopup"
import { BpCardHeader, bpCardClassName, bpCardContentClassName } from "@/pages/BelievePoints/components/BpCardHeader"
import { BpSectionHeader } from "@/pages/BelievePoints/components/BpSectionHeader"
import { BpWalletLedger, type BpWalletLedgerPagination } from "@/pages/BelievePoints/components/BpWalletLedger"
import {
  QuickAddBelievePointsModal,
  readQuickAddBelievePointsPrompt,
  resolveQuickAddPaymentMethod,
} from "@/components/believe-points/QuickAddBelievePointsModal"

interface Purchase {
  id: number
  amount: string
  points: string
  status: string
  created_at: string
  source?: string
  payment_rail?: string | null
  points_released?: boolean
  points_available_at?: string | null
  stripe_funds_available_at?: string | null
  bridge_reserve_confirmed_at?: string | null
  failure_code?: string | null
  failure_message?: string | null
  bp_status?: string
  settlement_status?: string
  settlement_date?: string | null
  settlement_reference?: string | null
  available_on?: string | null
  current_bp_owner?: { id: number; name: string | null; email: string | null } | null
}

interface AutoReplenishProps {
  enabled: boolean
  threshold: number | null
  amount: number | null
  has_payment_method: boolean
  saved_payment_method_id: string | null
  card_brand: string | null
  card_last4: string | null
  last_replenish_at: string | null
}

/** Matches `BelievePointController@index` fee preview for Add Believe Points purchases. */
interface BelievePointsFeePreview {
  mode: "buyer_covers"
  rail: "card" | "bank"
  bp_amount_usd: number
  platform_fee_usd: number
  processing_fee_usd: number
  checkout_total_usd: number
  brp_earned: number
  bp_availability: string
  card_processing_fee_usd: number
  ach_processing_fee_usd: number
  brp_value: number
  platform_fee_percent: number
  processing_fee_percent: number
  free_brp_award: number
  prime_brp_award: number
  brp_award: number
  card_hold_hours: number
  new_card_hold_hours?: number
  ach_hold_hours?: number
  supporter_pays_processing_fee?: boolean
  supporter_pays_platform_fee?: boolean
  card_settlement_business_days?: number
  ach_settlement_business_days?: number
  require_bridge_reserve_confirmation?: boolean
}

interface PurchaseSettings {
  brp_value: number
  platform_fee_percent: number
  processing_fee_percent: number
  /** BRP earned per qualifying BP purchase for Free (non-Prime) supporters. */
  free_brp_award: number
  /** BRP earned per qualifying BP purchase for Prime supporters. */
  prime_brp_award: number
  /** BRP earned per qualifying BP purchase for the current buyer's membership tier. */
  brp_award: number
  card_hold_hours: number
  new_card_hold_hours?: number
  ach_hold_hours?: number
  supporter_pays_processing_fee?: boolean
  supporter_pays_platform_fee?: boolean
  card_settlement_business_days?: number
  ach_settlement_business_days?: number
  require_bridge_reserve_confirmation?: boolean
}

interface WalletTransferSettings {
  enabled: boolean
  eligible?: boolean
  eligibility_message?: string | null
  min_amount: number
  max_amount: number
  sandbox_unavailable: boolean
}

interface WalletTransferActivity {
  id: number
  amount: number
  status: string
  bridge_transfer_state?: string | null
  failure_message?: string | null
  created_at: string
  completed_at?: string | null
}

type BelievePointsActivityItem =
  | { kind: "purchase"; sortAt: string; purchase: Purchase }
  | { kind: "wallet_transfer"; sortAt: string; transfer: WalletTransferActivity }

interface PageProps {
  currentBalance: number
  processingBalance?: number
  processingReleaseAt?: string | null
  processingBatches?: Array<{ amount: number; available_on: string | null }>
  minPurchaseAmount: number
  maxPurchaseAmount: number
  purchaseSettings?: PurchaseSettings
  purchases: {
    data: Purchase[]
    links: any
    meta: any
  }
  auth?: { user?: { role?: string } }
  feePreview?: BelievePointsFeePreview | null
  availableMethods?: Record<string, boolean>
  autoReplenish?: AutoReplenishProps
  savedPaymentMethods?: SavedPaymentMethod[]
  paymentMethodsUrl?: string
  walletTransfer?: WalletTransferSettings
  walletTransfers?: WalletTransferActivity[]
  walletLedger?: BpWalletLedgerPagination
  giftedBalance?: number
  holdingBalance?: number
  flash?: {
    success?: string
    error?: string
    info?: string
  }
}

const ADD_BELIEVE_POINTS_SECTION_ID = "add-believe-points"

type BelievePointsPaymentMethodId =
  | "stripe_card"
  | "stripe_ach"
  | "paypal"
  | "venmo"
  | "venmo_manual"
  | "cash_app_pay"
  | "cashapp"
  | "zelle"

const BP_METHOD_CONFIG: {
  id: BelievePointsPaymentMethodId
  label: string
  description: string
  icon: typeof CreditCard
  availabilityKey: string
}[] = [
  { id: "stripe_card", label: "Pay with Card", description: "Visa, Mastercard, Amex, Discover", icon: CreditCard, availabilityKey: "stripe_card" },
  { id: "stripe_ach", label: "Pay with ACH", description: "Direct bank transfer", icon: Landmark, availabilityKey: "stripe_ach" },
  { id: "paypal", label: "Pay with PayPal", description: "Secure payment with PayPal", icon: Wallet, availabilityKey: "paypal" },
  { id: "venmo", label: "Pay with Venmo", description: "Venmo via Stripe Checkout", icon: Smartphone, availabilityKey: "venmo" },
  { id: "venmo_manual", label: "Venmo (Manual)", description: "Send payment — admin verifies", icon: Smartphone, availabilityKey: "venmo_manual" },
  { id: "cash_app_pay", label: "Cash App Pay", description: "Cash App Pay via Stripe", icon: Smartphone, availabilityKey: "cash_app_pay" },
  { id: "cashapp", label: "Cash App", description: "Manual transfer — QR or cashtag", icon: Smartphone, availabilityKey: "cashapp" },
  { id: "zelle", label: "Zelle", description: "Manual bank transfer", icon: Landmark, availabilityKey: "zelle" },
]

function isStripeRail(method: BelievePointsPaymentMethodId): boolean {
  return method === "stripe_card" || method === "stripe_ach"
}

function isManualMethod(method: BelievePointsPaymentMethodId): boolean {
  return method === "cashapp" || method === "zelle" || method === "venmo_manual"
}

/** Refunded / failed transfers restore BP — show as credit (+), not debit (−). */
function isWalletTransferCredit(status: string): boolean {
  return status === "refunded" || status === "failed"
}

function walletTransferAmountLabel(status: string): string {
  if (status === "refunded") return "Returned to Available BP"
  if (status === "failed") return "Wallet transfer not completed"
  if (status === "pending" || status === "submitted") return "Moving to Believe wallet"
  return "Moved to Believe wallet"
}

function defaultPaymentMethod(available: Record<string, boolean>): BelievePointsPaymentMethodId {
  if (available.stripe_ach) return "stripe_ach"
  if (available.stripe_card) return "stripe_card"
  const first = BP_METHOD_CONFIG.find((m) => available[m.availabilityKey])
  return first?.id ?? "stripe_ach"
}

const defaultAutoReplenish: AutoReplenishProps = {
  enabled: false,
  threshold: null,
  amount: null,
  has_payment_method: false,
  saved_payment_method_id: null,
  card_brand: null,
  card_last4: null,
  last_replenish_at: null,
}

export default function BelievePointsIndex({
  currentBalance,
  processingBalance = 0,
  processingReleaseAt = null,
  minPurchaseAmount,
  maxPurchaseAmount,
  purchases,
  autoReplenish: autoReplenishProp,
}: PageProps) {
  const autoReplenish = { ...defaultAutoReplenish, ...autoReplenishProp }
  const page = usePage<PageProps>()
  const {
    flash,
    auth,
    feePreview: feePreviewProp,
    purchaseSettings: purchaseSettingsProp,
    availableMethods: availableMethodsProp = {},
    savedPaymentMethods = [],
    paymentMethodsUrl = "/profile/payment-methods",
    walletTransfers = [],
    walletLedger,
    walletTransfer,
    giftedBalance = 0,
    holdingBalance = 0,
    processingBatches = [],
  } = page.props
  const purchaseSettings = purchaseSettingsProp ?? {
    brp_value: 0.005,
    platform_fee_percent: 1,
    processing_fee_percent: 1,
    free_brp_award: 1,
    prime_brp_award: 2,
    brp_award: 1,
    card_hold_hours: 0,
  }
  const availableMethods = availableMethodsProp
  const isSupporter = auth?.user?.role === 'user' || !auth?.user?.role
  const [paymentMethod, setPaymentMethod] = useState<BelievePointsPaymentMethodId>(() =>
    defaultPaymentMethod(availableMethodsProp)
  )
  const isAchPayment = paymentMethod === "stripe_ach"
  const isCardPayment = paymentMethod === "stripe_card"
  const feePreview = feePreviewProp ?? null
  const participationBrp =
    feePreview?.brp_award ??
    feePreview?.brp_earned ??
    purchaseSettings.brp_award ??
    1
  const cardSettlementDays = purchaseSettings.card_settlement_business_days ?? 1
  const achSettlementDays = purchaseSettings.ach_settlement_business_days ?? 3
  const cardSettlementLabel =
    cardSettlementDays <= 1
      ? "Processing BP until card payout + reserve settlement (~1 business day)"
      : `Processing BP until card payout + reserve settlement (~${cardSettlementDays} business days)`
  const achSettlementLabel =
    achSettlementDays <= 1
      ? "Processing BP until ACH + reserve settlement (~1 business day)"
      : `Processing BP until ACH + reserve settlement (~${achSettlementDays} business days)`
  const [formData, setFormData] = useState({
    amount: "",
    policyAccepted: false,
  })
  const [savedPaymentMethodId, setSavedPaymentMethodId] = useState<string | null>(null)
  const [feePreviewLoading, setFeePreviewLoading] = useState(false)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [policyDialogOpen, setPolicyDialogOpen] = useState(false)
  // Supporter must explicitly approve the displayed total before paying. Reset whenever
  // the amount, method, or computed total changes so they re-approve the new total.
  const [totalApproved, setTotalApproved] = useState(false)
  const [arState, setArState] = useState({
    enabled: autoReplenish.enabled,
    threshold:
      autoReplenish.threshold != null && !Number.isNaN(autoReplenish.threshold)
        ? String(autoReplenish.threshold)
        : "",
    amount:
      autoReplenish.amount != null && !Number.isNaN(autoReplenish.amount)
        ? String(autoReplenish.amount)
        : "",
    policyAccepted: false,
  })
  const [arSubmitting, setArSubmitting] = useState(false)
  const [localBalance, setLocalBalance] = useState(currentBalance)
  const [arSavedCardId, setArSavedCardId] = useState<string | null>(
    autoReplenish.saved_payment_method_id,
  )
  const [quickAddOpen, setQuickAddOpen] = useState(false)
  const [quickAddPmId, setQuickAddPmId] = useState<string | null>(null)
  const [quickAddRail, setQuickAddRail] = useState<"card" | "bank">("card")
  const [walletTransferAmount, setWalletTransferAmount] = useState("")
  const [walletTransferOpen, setWalletTransferOpen] = useState(false)
  const [walletTransferSubmitting, setWalletTransferSubmitting] = useState(false)
  const walletTransferIdempotencyKeyRef = useRef<string | null>(null)
  const savedCards = useMemo(
    () => filterMethodsForRail(savedPaymentMethods, "card"),
    [savedPaymentMethods],
  )

  useEffect(() => {
    setLocalBalance(currentBalance)
  }, [currentBalance])

  const believePointsFeePreviewSkipRef = useRef(true)

  const paymentMethodsManageUrl = useMemo(() => {
    const base = paymentMethodsUrl
    const separator = base.includes("?") ? "&" : "?"
    return `${base}${separator}return=${encodeURIComponent("/believe-points")}`
  }, [paymentMethodsUrl])

  useEffect(() => {
    const prompt = readQuickAddBelievePointsPrompt()
    if (!prompt) return
    setQuickAddPmId(prompt.savedPaymentMethodId)
    setQuickAddRail(prompt.paymentRail)
    setQuickAddOpen(true)
    if (prompt.paymentRail === "bank") {
      setPaymentMethod("stripe_ach")
      setSavedPaymentMethodId(prompt.savedPaymentMethodId)
    } else {
      setPaymentMethod("stripe_card")
      setSavedPaymentMethodId(prompt.savedPaymentMethodId)
    }
  }, [])

  const openQuickAddBelievePoints = (preferredId?: string | null) => {
    const resolved = resolveQuickAddPaymentMethod(savedPaymentMethods, preferredId)
    if (!resolved) {
      showErrorToast("Add a saved card or bank in Payment Methods to use quick buy.")
      return
    }
    setQuickAddPmId(resolved.id)
    setQuickAddRail(resolved.rail)
    setQuickAddOpen(true)
  }

  const hasSavedStripeMethods = savedPaymentMethods.length > 0
  const quickBuyDefaultMethod = useMemo(
    () => resolveQuickAddPaymentMethod(savedPaymentMethods, savedPaymentMethodId),
    [savedPaymentMethods, savedPaymentMethodId],
  )

  /** Profile menu (mobile): scroll past sidebar/header to the purchase form. */
  useEffect(() => {
    if (typeof window === "undefined") return
    if (window.location.hash !== `#${ADD_BELIEVE_POINTS_SECTION_ID}`) return
    if (!window.matchMedia("(max-width: 1023px)").matches) return

    const scrollToSection = () => {
      document.getElementById(ADD_BELIEVE_POINTS_SECTION_ID)?.scrollIntoView({
        behavior: "smooth",
        block: "start",
      })
    }

    const timeoutId = window.setTimeout(scrollToSection, 450)
    window.addEventListener("hashchange", scrollToSection)

    return () => {
      window.clearTimeout(timeoutId)
      window.removeEventListener("hashchange", scrollToSection)
    }
  }, [page.url])

  /** Inertia partial reload: feePreview from backend (same pattern as /donate). */
  useEffect(() => {
    if (believePointsFeePreviewSkipRef.current) {
      believePointsFeePreviewSkipRef.current = false
      return
    }
    const t = window.setTimeout(() => {
      const q: Record<string, string | number> = {}
      const raw = formData.amount.trim()
      const n = parseFloat(raw)
      const inRange =
        raw !== "" && !Number.isNaN(n) && n >= minPurchaseAmount && n <= maxPurchaseAmount
      if (inRange) {
        q.fee_preview_amount = n
        q.fee_preview_rail = paymentMethod === "stripe_ach" ? "bank" : "card"
      }
      setFeePreviewLoading(true)
      router.get(route("believe-points.index"), q, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        only: ["feePreview"],
        onFinish: () => setFeePreviewLoading(false),
        onCancel: () => setFeePreviewLoading(false),
      })
    }, 300)
    return () => window.clearTimeout(t)
  }, [formData.amount, paymentMethod, minPurchaseAmount, maxPurchaseAmount])

  useEffect(() => {
    setArState({
      enabled: autoReplenish.enabled,
      threshold:
        autoReplenish.threshold != null && !Number.isNaN(autoReplenish.threshold)
          ? String(autoReplenish.threshold)
          : "",
      amount:
        autoReplenish.amount != null && !Number.isNaN(autoReplenish.amount)
          ? String(autoReplenish.amount)
          : "",
      policyAccepted: false,
    })
  }, [
    autoReplenish.enabled,
    autoReplenish.threshold,
    autoReplenish.amount,
    autoReplenish.has_payment_method,
    autoReplenish.saved_payment_method_id,
  ])

  useEffect(() => {
    if (arSavedCardId && savedCards.some((card) => card.id === arSavedCardId)) {
      return
    }

    const preferred =
      autoReplenish.saved_payment_method_id &&
      savedCards.find((card) => card.id === autoReplenish.saved_payment_method_id)?.id

    const defaultCard = savedCards.find((card) => card.is_default)?.id ?? savedCards[0]?.id ?? null

    setArSavedCardId(preferred ?? defaultCard)
  }, [arSavedCardId, autoReplenish.saved_payment_method_id, savedCards])

  const amountNum = parseFloat(formData.amount)
  const validPurchaseAmount =
    formData.amount !== "" &&
    !Number.isNaN(amountNum) &&
    amountNum >= minPurchaseAmount &&
    amountNum <= maxPurchaseAmount

  useEffect(() => {
    setTotalApproved(false)
  }, [formData.amount, paymentMethod, savedPaymentMethodId, feePreview?.checkout_total_usd])

  const handleChange = (value: string) => {
    // Allow only numbers and one decimal point
    const numericValue = value.replace(/[^0-9.]/g, '')
    // Ensure only one decimal point
    const parts = numericValue.split('.')
    const formattedValue = parts.length > 2
      ? parts[0] + '.' + parts.slice(1).join('')
      : numericValue

    setFormData({ amount: formattedValue })

    // Clear error
    if (errors.amount) {
      setErrors({})
    }
  }

  const validateForm = (): boolean => {
    const newErrors: Record<string, string> = {}
    const amount = parseFloat(formData.amount)

    if (!formData.amount || isNaN(amount) || amount < minPurchaseAmount) {
      newErrors.amount = `Minimum purchase amount is $${minPurchaseAmount.toFixed(2)}`
    }
    if (amount > maxPurchaseAmount) {
      newErrors.amount = `Maximum purchase amount is $${maxPurchaseAmount.toFixed(2)}`
    }
    if (!formData.policyAccepted) {
      newErrors.policyAccepted = 'You must accept the Points Policy to proceed'
    }
    if (!totalApproved) {
      newErrors.totalApproved = 'Please approve the total amount before submitting payment'
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const saveAutoReplenish = (e: FormEvent) => {
    e.preventDefault()
    if (arState.enabled) {
      if (!arSavedCardId) {
        showErrorToast("Select a saved card for auto top-up, or add one in Payment Methods.")
        return
      }
      const th = parseFloat(arState.threshold)
      const amt = parseFloat(arState.amount)
      if (arState.threshold === "" || Number.isNaN(th) || th < 0) {
        showErrorToast("Enter a valid threshold (0 or higher).")
        return
      }
      if (arState.amount === "" || Number.isNaN(amt) || amt < minPurchaseAmount || amt > maxPurchaseAmount) {
        showErrorToast(
          `Top-up amount must be between ${formatCurrency(minPurchaseAmount)} and ${formatCurrency(maxPurchaseAmount)}.`
        )
        return
      }
      if (!arState.policyAccepted) {
        showErrorToast("Accept the auto top-up terms to continue.")
        return
      }
    }

    setArSubmitting(true)
    router.post(
      route("believe-points.auto-replenish.settings"),
      arState.enabled
        ? {
            enabled: true,
            threshold: parseFloat(arState.threshold),
            amount: parseFloat(arState.amount),
            auto_replenish_policy_accepted: true,
            saved_payment_method_id: arSavedCardId,
          }
        : { enabled: false },
      {
        preserveScroll: true,
        onFinish: () => setArSubmitting(false),
        onError: () => {
          showErrorToast("Could not save auto top-up settings.")
          setArSubmitting(false)
        },
      }
    )
  }

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault()

    if (!validateForm()) {
      showErrorToast("Please fix the errors before submitting")
      return
    }

    setIsSubmitting(true)

    router.post(
      route("believe-points.purchase"),
      {
        amount: parseFloat(formData.amount),
        payment_method: paymentMethod,
        payment_rail: paymentMethod === "stripe_ach" ? "bank" : paymentMethod === "stripe_card" ? "card" : undefined,
        ...(savedPaymentMethodId && isStripeRail(paymentMethod) ? { saved_payment_method_id: savedPaymentMethodId } : {}),
      },
      {
        onError: (errors) => {
          setErrors(errors as Record<string, string>)
          showErrorToast("Failed to process purchase. Please check the errors.")
          setIsSubmitting(false)
        },
        onFinish: () => {
          setIsSubmitting(false)
        },
      }
    )
  }

  const formatCurrency = (value: number | string): string => {
    const num = typeof value === 'string' ? parseFloat(value) : value
    if (isNaN(num)) return "$0.00"
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency: "USD",
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    }).format(num)
  }

  const formatPoints = (value: number | string): string => {
    const num = typeof value === 'string' ? parseFloat(value) : value
    if (isNaN(num)) return "0"
    return new Intl.NumberFormat("en-US", {
      minimumFractionDigits: 0,
      maximumFractionDigits: 2,
    }).format(num)
  }

  const handleTransferToWallet = async () => {
    const amount = parseFloat(walletTransferAmount)
    const min = walletTransfer?.min_amount ?? 1
    const max = walletTransfer?.max_amount ?? 10000

    if (!amount || amount < min || amount > max) {
      showErrorToast(`Enter an amount between $${min.toFixed(2)} and $${max.toFixed(2)}`)
      return
    }

    if (amount > localBalance + 0.0001) {
      showErrorToast("Insufficient Available Believe Points to move to your wallet.")
      return
    }

    setWalletTransferSubmitting(true)
    try {
      if (!walletTransferIdempotencyKeyRef.current) {
        walletTransferIdempotencyKeyRef.current = crypto.randomUUID()
      }
      const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute("content") ?? ""
      const response = await fetch(route("believe-points.transfer-to-wallet"), {
        method: "POST",
        headers: {
          Accept: "application/json",
          "Content-Type": "application/json",
          "X-CSRF-TOKEN": csrf,
          "X-Requested-With": "XMLHttpRequest",
        },
        credentials: "include",
        body: JSON.stringify({
          amount,
          idempotency_key: walletTransferIdempotencyKeyRef.current,
        }),
      })

      const data = await response.json()
      if (data.success) {
        walletTransferIdempotencyKeyRef.current = null
        showSuccessToast(data.message || "Wallet funding started")
        if (typeof data.data?.believe_points_balance === "number") {
          setLocalBalance(data.data.believe_points_balance)
        } else {
          setLocalBalance((prev) => Math.max(0, prev - amount))
        }
        setWalletTransferAmount("")
        setWalletTransferOpen(false)
        router.reload({ only: ["walletTransfers", "currentBalance", "processingBalance", "processingReleaseAt", "processingBatches", "walletLedger"] })
      } else {
        // Definitive failure — allow a fresh idempotency key on the next attempt
        walletTransferIdempotencyKeyRef.current = null
        if (typeof data.data?.believe_points_balance === "number") {
          setLocalBalance(data.data.believe_points_balance)
        }
        showErrorToast(data.message || "Failed to move Believe Points to wallet")
      }
    } catch {
      showErrorToast("Failed to move Believe Points to wallet")
    } finally {
      setWalletTransferSubmitting(false)
    }
  }

  const openMoveToWalletPopup = () => {
    setWalletTransferOpen(true)
  }

  const formatAvailableOnLabel = (iso: string | null | undefined): string | null => {
    if (!iso) return null
    const d = new Date(iso)
    if (Number.isNaN(d.getTime())) return null
    return d.toLocaleString(undefined, {
      month: "short",
      day: "numeric",
      year: "numeric",
      hour: "numeric",
      minute: "2-digit",
    })
  }

  const getPurchaseStatusBadge = (purchase: Purchase) => {
    const compact = "h-6 shrink-0 px-2 text-xs font-semibold"

    if (purchase.status === "completed" && purchase.points_released === false) {
      return (
        <Badge
          variant="secondary"
          className={cn(
            compact,
            "border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200",
          )}
        >
          Processing
        </Badge>
      )
    }

    const config: Record<
      string,
      { variant: "default" | "secondary" | "destructive" | "outline"; className?: string; label?: string }
    > = {
      completed: {
        variant: "default",
        className: "border-0 bg-emerald-600 hover:bg-emerald-600/90",
        label: "Available",
      },
      pending: { variant: "secondary", label: "Processing payment" },
      failed: { variant: "destructive", label: "Failed" },
      cancelled: { variant: "outline", label: "Cancelled" },
    }
    const item = config[purchase.status] ?? { variant: "outline" as const, label: "Activity" }
    return (
      <Badge variant={item.variant} className={cn(compact, item.className)}>
        {item.label}
      </Badge>
    )
  }

  const purchaseAvailableOn = (purchase: Purchase): string | null => {
    if (purchase.status !== "completed" || purchase.points_released !== false) return null
    return (
      formatAvailableOnLabel(purchase.available_on) ||
      formatAvailableOnLabel(purchase.points_available_at) ||
      formatAvailableOnLabel(purchase.stripe_funds_available_at)
    )
  }

  const getWalletTransferStatusBadge = (transfer: WalletTransferActivity) => {
    const bridgeState = (transfer.bridge_transfer_state ?? "").toLowerCase()
    const bridgeComplete = ["payment_processed", "completed", "settled", "funds_received"].includes(bridgeState)
    const effectiveStatus = transfer.status === "submitted" && bridgeComplete ? "completed" : transfer.status
    const compact = "h-6 shrink-0 px-2 text-xs font-semibold"

    const config: Record<
      string,
      { variant: "default" | "secondary" | "destructive" | "outline"; className?: string; label: string }
    > = {
      completed: {
        variant: "default",
        className: "border-0 bg-emerald-600 hover:bg-emerald-600/90",
        label: "In wallet",
      },
      submitted: { variant: "secondary", label: "Processing" },
      pending: { variant: "secondary", label: "Processing" },
      failed: { variant: "destructive", label: "Failed" },
      refunded: { variant: "outline", label: "Returned" },
    }

    const item = config[effectiveStatus] ?? {
      variant: "outline" as const,
      label: "Activity",
    }

    return (
      <Badge variant={item.variant} className={cn(compact, item.className)}>
        {item.label}
      </Badge>
    )
  }

  const recentActivity = useMemo<BelievePointsActivityItem[]>(() => {
    const items: BelievePointsActivityItem[] = [
      ...purchases.data.map((purchase) => ({
        kind: "purchase" as const,
        sortAt: purchase.created_at,
        purchase,
      })),
      ...walletTransfers.map((transfer) => ({
        kind: "wallet_transfer" as const,
        sortAt: transfer.created_at,
        transfer,
      })),
    ]

    return items
      .sort((a, b) => new Date(b.sortAt).getTime() - new Date(a.sortAt).getTime())
      .slice(0, 12)
  }, [purchases.data, walletTransfers])

  const formatProcessingReleaseHint = (iso: string | null | undefined): string | null => {
    if (!iso) return null
    const releaseAt = new Date(iso)
    if (Number.isNaN(releaseAt.getTime())) return null
    const dateLabel = releaseAt.toLocaleString(undefined, {
      month: "short",
      day: "numeric",
      year: "numeric",
      hour: "numeric",
      minute: "2-digit",
    })
    return `Available On ${dateLabel}`
  }

  const scrollToAddPoints = () => {
    document.getElementById(ADD_BELIEVE_POINTS_SECTION_ID)?.scrollIntoView({ behavior: "smooth", block: "start" })
  }

  const content = (
    <>
      <div className="mx-auto w-full min-w-0 max-w-6xl space-y-8">
        <div className="space-y-3">
          {flash?.success && !quickAddOpen && (
            <Alert className="border border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950/25">
              <CheckCircle2 className="h-4 w-4 text-green-600 dark:text-green-400" />
              <AlertDescription className="text-green-900 dark:text-green-100">{flash.success}</AlertDescription>
            </Alert>
          )}
          {flash?.error && (
            <Alert className="border border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950/25">
              <AlertCircle className="h-4 w-4 text-red-600 dark:text-red-400" />
              <AlertDescription className="text-red-900 dark:text-red-100">{flash.error}</AlertDescription>
            </Alert>
          )}
          {flash?.info && (
            <Alert className="border border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950/25">
              <AlertCircle className="h-4 w-4 text-blue-600 dark:text-blue-400" />
              <AlertDescription className="text-blue-900 dark:text-blue-100">{flash.info}</AlertDescription>
            </Alert>
          )}
        </div>

        <div className="grid min-w-0 grid-cols-1 gap-8 lg:grid-cols-12">
          <div className="min-w-0 space-y-8 lg:col-span-8">
            <BpBalanceHero
              balance={localBalance}
              processingBalance={processingBalance}
              processingReleaseHint={formatProcessingReleaseHint(processingReleaseAt)}
              processingBatches={processingBatches}
              giftedBalance={giftedBalance}
              holdingBalance={holdingBalance}
              formatPoints={formatPoints}
              onRefunds={() => router.visit(route("believe-points.refunds"))}
              onAddPoints={scrollToAddPoints}
              showWalletAction={
                localBalance > 0 &&
                Boolean(
                  walletTransfer?.enabled ||
                    walletTransfer?.eligible ||
                    walletTransfer?.sandbox_unavailable,
                )
              }
              onMoveToWallet={openMoveToWalletPopup}
            />

            <BpMoveToWalletPopup
              isOpen={walletTransferOpen}
              onClose={() => {
                if (walletTransferSubmitting) return
                setWalletTransferOpen(false)
              }}
              balance={localBalance}
              amount={walletTransferAmount}
              onAmountChange={setWalletTransferAmount}
              walletTransfer={walletTransfer}
              isSubmitting={walletTransferSubmitting}
              onSubmit={handleTransferToWallet}
              formatCurrency={formatCurrency}
              formatPoints={formatPoints}
            />

            {hasSavedStripeMethods && quickBuyDefaultMethod && (
              <Card className="border border-purple-200 bg-gradient-to-r from-purple-50/90 to-violet-50/90 shadow-sm dark:border-purple-800 dark:from-purple-950/25 dark:to-violet-950/20">
                <CardContent className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                  <div className="min-w-0">
                    <p className="text-sm font-semibold text-purple-900 dark:text-purple-100">
                      Quick add with saved payment
                    </p>
                    <p className="mt-1 text-xs leading-relaxed text-purple-800/90 dark:text-purple-200/80 sm:text-sm">
                      Charge your saved {quickBuyDefaultMethod.rail === "bank" ? "bank account" : "card"} instantly — pick an amount and add BP in seconds.
                    </p>
                  </div>
                  <Button
                    type="button"
                    className="h-10 shrink-0 border border-emerald-950/80 !bg-gradient-to-b !from-emerald-700 !via-emerald-800 !to-emerald-950 px-5 font-semibold !text-white shadow-[inset_0_1px_0_0_rgba(255,255,255,0.18),0_4px_0_0_#064e3b,0_6px_14px_rgba(0,0,0,0.35)] transition-all hover:!from-emerald-600 hover:!via-emerald-700 hover:!to-emerald-900 hover:shadow-[inset_0_1px_0_0_rgba(255,255,255,0.22),0_4px_0_0_#064e3b,0_8px_16px_rgba(0,0,0,0.4)] active:translate-y-1 active:shadow-[inset_0_2px_4px_rgba(0,0,0,0.35),0_1px_0_0_#064e3b]"
                    onClick={() => openQuickAddBelievePoints(quickBuyDefaultMethod.id)}
                  >
                    <Coins className="mr-2 h-4 w-4" />
                    Quick buy Believe Points
                  </Button>
                </CardContent>
              </Card>
            )}

            <Card
              id={ADD_BELIEVE_POINTS_SECTION_ID}
              className={cn("scroll-mt-24", bpCardClassName)}
            >
              <BpCardHeader
                icon={DollarSign}
                title="Add Believe Points"
                description={`Choose a payment method below. Purchased BP credits as Processing BP until settlement. Each qualifying purchase earns ${formatPoints(purchaseSettings.brp_award)} BRP per transaction.`}
                trailing={
                  <Badge variant="secondary" className="w-fit font-normal">
                    Secure checkout · Stripe
                  </Badge>
                }
              />
              <CardContent className="p-0">
                <form onSubmit={handleSubmit} className="divide-y divide-border">
                  <div className="space-y-4 p-4 sm:p-6">
                    <BpSectionHeader step="Step 1" title="Purchase amount" />
                    <div className="relative">
                      <DollarSign className="pointer-events-none absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 text-muted-foreground" />
                      <Input
                        id="amount"
                        type="text"
                        value={formData.amount}
                        onChange={(e) => handleChange(e.target.value)}
                        className={cn(
                          "h-14 border-2 bg-background pl-10 text-xl font-semibold tabular-nums shadow-sm placeholder:text-muted-foreground focus-visible:border-purple-500 focus-visible:ring-2 focus-visible:ring-purple-500/20",
                          errors.amount && "border-destructive focus-visible:border-destructive focus-visible:ring-destructive/20",
                        )}
                        placeholder="0.00"
                        disabled={isSubmitting}
                      />
                    </div>
                    {errors.amount && (
                      <p className="text-sm text-red-600 dark:text-red-400 flex items-center gap-1">
                        <AlertCircle className="h-4 w-4" />
                        {errors.amount}
                      </p>
                    )}
                    <p className="text-xs text-muted-foreground">
                      Allowed range {formatCurrency(minPurchaseAmount)} – {formatCurrency(maxPurchaseAmount)}
                    </p>
                  </div>

                  <div className="space-y-4 p-4 sm:p-6">
                    <BpSectionHeader
                      step="Step 2"
                      title="Payment method"
                      description={
                        isAchPayment
                          ? `Earn ${formatPoints(purchaseSettings.brp_award)} BRP per transaction. ${achSettlementLabel}.`
                          : isCardPayment
                            ? `Earn ${formatPoints(purchaseSettings.brp_award)} BRP per transaction. ${cardSettlementLabel}.`
                            : isManualMethod(paymentMethod)
                              ? "Transfer outside Stripe, then confirm. An admin verifies before points are credited."
                              : "Complete checkout with your selected payment provider."
                      }
                    />

                    <div className="grid gap-2 sm:grid-cols-2">
                      {BP_METHOD_CONFIG.map(({ id, label, description, icon: Icon, availabilityKey }) => {
                        if (!availableMethods[availabilityKey]) return null
                        const selected = paymentMethod === id
                        return (
                          <button
                            key={id}
                            type="button"
                            onClick={() => {
                              setPaymentMethod(id)
                              if (!isStripeRail(id)) setSavedPaymentMethodId(null)
                            }}
                            className={cn(
                              "flex h-full w-full items-center gap-3 rounded-xl border-2 p-3.5 text-left transition-all sm:p-4",
                              selected
                                ? "border-purple-600 bg-purple-50/80 shadow-sm ring-1 ring-purple-600/20 dark:border-purple-500 dark:bg-purple-950/30"
                                : "border-border bg-card hover:border-purple-300 hover:bg-muted/40",
                            )}
                          >
                            <div
                              className={cn(
                                "flex h-10 w-10 shrink-0 items-center justify-center rounded-lg",
                                selected ? "bg-purple-600 text-white" : "bg-muted text-muted-foreground",
                              )}
                            >
                              <Icon className="h-5 w-5" />
                            </div>
                            <div className="min-w-0 flex-1">
                              <div className="font-semibold text-sm">{label}</div>
                              <div className="text-xs text-muted-foreground line-clamp-2">{description}</div>
                            </div>
                          </button>
                        )
                      })}
                      {Object.values(availableMethods).every((v) => !v) && (
                        <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">
                          No payment methods are enabled yet. Please contact support or try again later.
                        </p>
                      )}
                    </div>

                    {isStripeRail(paymentMethod) && (
                    <>
                    <div className="space-y-2">
                      <p className="text-sm font-medium text-foreground">Use a saved method</p>
                      <SavedPaymentMethodSelector
                        methods={savedPaymentMethods}
                        rail={paymentMethod === "stripe_ach" ? "bank" : "card"}
                        value={savedPaymentMethodId}
                        onChange={setSavedPaymentMethodId}
                        manageHref={paymentMethodsManageUrl}
                      />
                    </div>
                    <p className="flex items-start gap-1.5 text-xs leading-snug text-muted-foreground">
                      <CircleHelp className="mt-0.5 h-3.5 w-3.5 shrink-0 opacity-80" aria-hidden />
                      <span>
                        {savedPaymentMethodId
                          ? "Your saved payment method will be charged directly."
                          : `Stripe Checkout will only show ${paymentMethod === "stripe_ach" ? "US bank account (ACH)" : "card"} for this purchase.`}
                      </span>
                    </p>
                    </>
                    )}

                    {isManualMethod(paymentMethod) && (
                      <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">
                        After clicking Add Believe Points, you&apos;ll see payment instructions. Transfer manually, upload a receipt if you have one, and an admin will verify before your Believe Points are credited.
                      </div>
                    )}
                    {!isStripeRail(paymentMethod) && !isManualMethod(paymentMethod) && (
                      <div className="rounded-xl border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                        You&apos;ll be redirected to complete payment with {paymentMethod === "paypal" ? "PayPal" : "Stripe"}.
                      </div>
                    )}
                  </div>

                  <div className="space-y-5 bg-muted/25 p-4 sm:p-6">
                    <BpSectionHeader title="Order summary" description="Fees and what you receive" />
                  {feePreview && validPurchaseAmount && (
                    <div
                      className={cn(
                        "relative overflow-hidden rounded-xl border bg-card p-5 text-sm shadow-sm",
                        feePreviewLoading && "opacity-60",
                      )}
                    >
                      {feePreviewLoading && (
                        <div className="absolute right-3 top-3 flex items-center gap-1.5 text-xs text-muted-foreground">
                          <Loader2 className="h-3.5 w-3.5 animate-spin" />
                          Updating
                        </div>
                      )}
                      <div className="mb-3 flex items-center gap-2 font-semibold text-foreground">
                        <Receipt className="h-4 w-4 text-muted-foreground" />
                        Purchase summary
                      </div>
                      <ul className="mt-3 space-y-2 text-sm text-muted-foreground">
                        <li className="flex flex-wrap justify-between gap-2 border-b border-border pb-2">
                          <span>Believe Points Purchased</span>
                          <span className="font-semibold tabular-nums text-foreground">
                            {formatCurrency(feePreview.bp_amount_usd)}
                          </span>
                        </li>
                        {(feePreview.supporter_pays_processing_fee ?? feePreview.processing_fee_usd > 0) && (
                          <li className="flex flex-wrap justify-between gap-2 border-b border-border pb-2">
                            <span>Payment Provider Fee</span>
                            <span className="font-semibold tabular-nums text-foreground">
                              {formatCurrency(feePreview.processing_fee_usd)}
                            </span>
                          </li>
                        )}
                        {(feePreview.supporter_pays_platform_fee ?? feePreview.platform_fee_usd > 0) && (
                          <li className="flex flex-wrap justify-between gap-2 border-b border-border pb-2">
                            <span>Platform Fee ({feePreview.platform_fee_percent}%)</span>
                            <span className="font-semibold tabular-nums text-foreground">
                              {formatCurrency(feePreview.platform_fee_usd)}
                            </span>
                          </li>
                        )}
                        <li className="flex flex-wrap justify-between gap-2 border-b border-border pb-2">
                          <span className="font-medium text-foreground">Total Amount Charged</span>
                          <span className="text-lg font-bold tabular-nums text-foreground">
                            {formatCurrency(feePreview.checkout_total_usd)}
                          </span>
                        </li>
                        <li className="flex flex-wrap justify-between gap-2 border-b border-border pb-2">
                          <span>Believe Reward Points (BRP) Earned</span>
                          <span className="font-semibold tabular-nums text-blue-700 dark:text-blue-300">
                            {Math.round(feePreview.brp_earned).toLocaleString("en-US")} BRP
                          </span>
                        </li>
                        <li className="flex flex-wrap justify-between gap-2 pt-0.5">
                          <span>BP Availability</span>
                          <span className="font-medium text-foreground">
                            {feePreview.bp_availability}
                          </span>
                        </li>
                      </ul>
                      <p className="mt-3 text-xs text-muted-foreground">
                        1 BRP = {formatCurrency(feePreview.brp_value)}.
                        {isAchPayment ? ` ${achSettlementLabel}.` : ` ${cardSettlementLabel}.`}
                      </p>
                      {isStripeRail(paymentMethod) && (
                        <p className="mt-2 text-xs text-muted-foreground">
                          Modeled Stripe fee on {isAchPayment ? "ACH" : "card"} charge (informational):{" "}
                          <span className="font-medium tabular-nums text-foreground">
                            {formatCurrency(
                              isAchPayment
                                ? feePreview.ach_processing_fee_usd
                                : feePreview.card_processing_fee_usd,
                            )}
                          </span>
                        </p>
                      )}
                      <div className="mt-4 flex items-start gap-3 rounded-lg border bg-muted/30 p-3">
                        <Checkbox
                          id="total-approve"
                          checked={totalApproved}
                          onCheckedChange={(checked) => {
                            setTotalApproved(checked === true)
                            if (errors.totalApproved) {
                              setErrors({ ...errors, totalApproved: '' })
                            }
                          }}
                          className="mt-0.5 size-5 min-h-5 min-w-5"
                        />
                        <Label htmlFor="total-approve" className="cursor-pointer text-sm font-medium text-foreground">
                          I approve the total of {formatCurrency(feePreview.checkout_total_usd)} charged to my payment method for {formatPoints(feePreview.bp_amount_usd)} BP.
                        </Label>
                      </div>
                      {errors.totalApproved && (
                        <p className="mt-2 flex items-center gap-1 text-sm text-red-600 dark:text-red-400">
                          <AlertCircle className="h-4 w-4" />
                          {errors.totalApproved}
                        </p>
                      )}
                    </div>
                  )}

                  {validPurchaseAmount && (
                    <div className="flex flex-col gap-4 rounded-xl border border-purple-200/80 bg-gradient-to-r from-purple-50 to-blue-50 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5 dark:border-purple-800/50 dark:from-purple-950/30 dark:to-blue-950/20">
                      <div className="min-w-0">
                        <p className="text-xs font-semibold uppercase tracking-wide text-purple-700 dark:text-purple-300">
                          You receive
                        </p>
                        <p className="mt-1 text-3xl font-bold tracking-tight text-purple-900 tabular-nums dark:text-purple-100">
                          {formatPoints(amountNum)} BP
                        </p>
                        <p className="mt-1 text-sm text-muted-foreground">
                          {isAchPayment ? achSettlementLabel : cardSettlementLabel}
                        </p>
                      </div>
                      <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-purple-600 to-blue-600 text-white shadow-md">
                        <Coins className="h-7 w-7" />
                      </div>
                    </div>
                  )}

                  <div className="rounded-xl border bg-card p-4">
                    <h4 className="flex items-center gap-2 text-sm font-semibold">
                      <CircleHelp className="h-4 w-4 shrink-0 text-muted-foreground" />
                      Quick facts
                    </h4>
                    <ul className="mt-3 grid gap-2 text-sm text-muted-foreground">
                      <li className="flex gap-2 rounded-lg border bg-muted/40 px-3 py-2.5">
                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-purple-600" />
                        <span>Earn {formatPoints(purchaseSettings.brp_award)} BRP per transaction ({formatPoints(purchaseSettings.free_brp_award)} Free · {formatPoints(purchaseSettings.prime_brp_award)} Prime) · Minimum purchase {formatCurrency(minPurchaseAmount)} · BRP = {formatCurrency(purchaseSettings.brp_value)} each.</span>
                      </li>
                      <li className="flex gap-2 rounded-lg border bg-muted/40 px-3 py-2.5">
                        <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-purple-600" />
                        <span>
                          {cardSettlementLabel}. {achSettlementLabel}. Wallet and marketplace use Available BP only.
                        </span>
                      </li>
                    </ul>
                  </div>
                </div>

                <div className="space-y-5 p-4 sm:p-6">
                  <BpSectionHeader step="Step 3" title="Agree & pay" />
                  <div className="space-y-3">
                    <div className="flex items-start gap-3 rounded-xl border bg-muted/30 p-4">
                      <Checkbox
                        id="policy-accept"
                        checked={formData.policyAccepted}
                        onCheckedChange={(checked) => {
                          setFormData({ ...formData, policyAccepted: checked === true })
                          if (errors.policyAccepted) {
                            setErrors({ ...errors, policyAccepted: '' })
                          }
                        }}
                        className="mt-1 size-5 min-h-5 min-w-5"
                      />
                      <div className="flex-1 space-y-2">
                        <Label
                          htmlFor="policy-accept"
                          className="cursor-pointer text-sm font-medium text-foreground"
                        >
                          I have read and agree to the Believe Points Policy
                        </Label>
                        <div className="space-y-1 text-xs text-muted-foreground">
                          <p className="font-semibold">Summary:</p>
                          <p>Believe Points (BP) are platform credits used only inside Believe. BP are not money, cannot be cashed out by supporters, and do not interact directly with personal wallets or bank accounts. Limited refunds may be available within 7 days for unused BP only.</p>
                          <Dialog open={policyDialogOpen} onOpenChange={setPolicyDialogOpen}>
                            <DialogTrigger asChild>
                              <button
                                type="button"
                                className="mt-1 font-medium text-purple-600 hover:underline dark:text-purple-400"
                                onClick={(e) => {
                                  e.preventDefault()
                                  setPolicyDialogOpen(true)
                                }}
                              >
                                <FileText className="h-3 w-3 inline mr-1" />
                                Read Full Policy
                              </button>
                            </DialogTrigger>
                            <DialogContent className="max-h-[85vh] w-[calc(100vw-1.5rem)] max-w-[calc(100vw-1.5rem)] overflow-y-auto p-4 sm:max-w-3xl sm:p-6">
                              <DialogHeader>
                                <DialogTitle>Believe Platform – Points Policy</DialogTitle>
                                <DialogDescription>
                                  Please read and understand the complete Points Policy before purchasing.
                                </DialogDescription>
                              </DialogHeader>
                              <div className="space-y-6 text-sm">
                                <div>
                                  <h3 className="font-semibold text-base mb-2">1. Purpose of Points</h3>
                                  <p className="text-muted-foreground">
                                    Points are closed‑loop platform credits issued by Believe for use only within the Believe website ecosystem. Points are designed to enable purchases and organizational activity on the platform and are not a substitute for money.
                                  </p>
                                </div>

                                <div>
                                  <h3 className="font-semibold text-base mb-2">2. Nature of Points</h3>
                                  <ul className="text-muted-foreground space-y-1 list-disc list-inside">
                                    <li>Points are not money, not cash, and not legal tender.</li>
                                    <li>Points do not represent a bank account, wallet balance, or stored monetary value.</li>
                                    <li>Points do not exist within the Believe wallet, virtual account, debit card, or Bridge‑powered payment system.</li>
                                  </ul>
                                </div>

                                <div>
                                  <h3 className="font-semibold text-base mb-2">3. How Points Are Obtained</h3>
                                  <p className="text-muted-foreground mb-2">Points may be obtained by:</p>
                                  <ul className="text-muted-foreground space-y-1 list-disc list-inside mb-2">
                                    <li>Purchasing Points directly on the Believe website</li>
                                    <li>Receiving Points through promotions, grants, or organizational programs</li>
                                    <li>Receiving Points as part of nonprofit or platform initiatives</li>
                                  </ul>
                                  <p className="text-muted-foreground font-semibold">Points cannot:</p>
                                  <ul className="text-muted-foreground space-y-1 list-disc list-inside">
                                    <li>Be purchased using wallet balances</li>
                                    <li>Be funded via Believe wallet balances, virtual accounts, or Bridge (purchases on this page use Stripe card or US bank)</li>
                                  </ul>
                                  <p className="text-muted-foreground mt-2">
                                    Available Believe Points may be moved into your verified Believe Bridge wallet when that feature is enabled.
                                  </p>
                                </div>

                                <div>
                                  <h3 className="font-semibold text-base mb-2">4. Permitted Uses of Points</h3>
                                  <p className="text-muted-foreground mb-2">Points may be used to:</p>
                                  <ul className="text-muted-foreground space-y-1 list-disc list-inside">
                                    <li>Purchase products or services available on the Believe website</li>
                                    <li>Donate to qualified nonprofit organizations</li>
                                    <li>Allocate to churches or nonprofit organizations</li>
                                    <li>Enable organizational barter between verified organizations</li>
                                    <li>Purchase gift cards or benefits available within the Believe ecosystem only</li>
                                  </ul>
                                  <p className="text-muted-foreground mt-2">Points have no use outside the Believe platform.</p>
                                </div>

                                <div>
                                  <h3 className="font-semibold text-base mb-2">5. Prohibited Uses</h3>
                                  <p className="text-muted-foreground mb-2">Points may not be:</p>
                                  <ul className="text-muted-foreground space-y-1 list-disc list-inside">
                                    <li>Withdrawn as cash</li>
                                    <li>Eligible for conversion to U.S. dollars or cryptocurrency per policy</li>
                                    <li>Converted into wallet balances or bank funds</li>
                                    <li>Transferred peer‑to‑peer between individual users</li>
                                    <li>Sold, traded, or exchanged on secondary markets</li>
                                    <li>Converted into open‑loop prepaid instruments (e.g., Visa or Mastercard gift cards)</li>
                                  </ul>
                                </div>

                                <div>
                                  <h3 className="font-semibold text-base mb-2">6. Separation From Wallet & Payments</h3>
                                  <p className="text-muted-foreground">
                                    Believe operates a separate financial wallet system for real money transactions. Available Points may be moved into your verified Believe Bridge wallet when enabled. Wallet funds cannot be used to acquire Points.
                                  </p>
                                </div>

                                <div>
                                  <h3 className="font-semibold text-base mb-2">7. Purchase Limits</h3>
                                  <p className="text-muted-foreground">
                                    Believe may impose reasonable limits on per‑transaction point purchases, monthly point purchases, and maximum point balances. Higher limits may be available to verified organizations. Limits exist to ensure Points function as usage credits, not stored value.
                                  </p>
                                </div>

                                <div>
                                  <h3 className="font-semibold text-base mb-2">8. Expiration & Forfeiture</h3>
                                  <p className="text-muted-foreground">
                                    Points may carry an expiration date. Unused Points may expire after a defined period. Expired Points may be forfeited or reallocated to platform or nonprofit programs. Expiration terms are disclosed prior to purchase.
                                  </p>
                                </div>

                                <div>
                                  <h3 className="font-semibold text-base mb-2">9. Refund Policy</h3>
                                  <p className="text-muted-foreground mb-2">Points are generally non‑refundable. Limited refunds may be granted:</p>
                                  <ul className="text-muted-foreground space-y-1 list-disc list-inside">
                                    <li>Only for unused Points</li>
                                    <li>Within a short timeframe (e.g., 7–14 days)</li>
                                    <li>At Believe's discretion</li>
                                    <li>Returned to the original payment method</li>
                                  </ul>
                                  <p className="text-muted-foreground mt-2">Refunds are treated as purchase reversals for unused Believe Points (BP) only.</p>
                                </div>

                                <div>
                                  <h3 className="font-semibold text-base mb-2">10. No Ownership or Investment Rights</h3>
                                  <p className="text-muted-foreground mb-2">Points do not represent:</p>
                                  <ul className="text-muted-foreground space-y-1 list-disc list-inside">
                                    <li>Ownership</li>
                                    <li>Equity</li>
                                    <li>Profit participation</li>
                                    <li>Revenue share</li>
                                    <li>Investment or appreciation</li>
                                  </ul>
                                  <p className="text-muted-foreground mt-2">Points provide no financial return.</p>
                                </div>

                                <div>
                                  <h3 className="font-semibold text-base mb-2">11. Abuse, Enforcement & Termination</h3>
                                  <p className="text-muted-foreground">
                                    Believe reserves the right to suspend or terminate Points access for abuse or misuse, revoke Points obtained through fraud or policy violations, and modify or discontinue the Points program at any time.
                                  </p>
                                </div>

                                <div>
                                  <h3 className="font-semibold text-base mb-2">12. Modifications to This Policy</h3>
                                  <p className="text-muted-foreground">
                                    Believe may update this policy periodically. Material changes will be communicated where required.
                                  </p>
                                </div>

                                <div className="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mt-4">
                                  <h3 className="font-semibold text-base mb-2">Plain‑English Summary</h3>
                                  <p className="text-sm text-muted-foreground">
                                    Believe Points (BP) are platform credits used only inside Believe. BP are not money, cannot be cashed out by supporters, and do not interact directly with personal wallets or bank accounts. Limited refunds may be available within 7 days for unused BP only.
                                  </p>
                                </div>
                              </div>
                              <DialogFooter>
                                <Button
                                  variant="outline"
                                  onClick={() => setPolicyDialogOpen(false)}
                                >
                                  Close
                                </Button>
                                <Button
                                  onClick={() => {
                                    setFormData({ ...formData, policyAccepted: true })
                                    setPolicyDialogOpen(false)
                                    if (errors.policyAccepted) {
                                      setErrors({ ...errors, policyAccepted: '' })
                                    }
                                  }}
                                >
                                  I Understand & Accept
                                </Button>
                              </DialogFooter>
                            </DialogContent>
                          </Dialog>
                        </div>
                      </div>
                    </div>
                    {errors.policyAccepted && (
                      <p className="text-sm text-red-600 dark:text-red-400 flex items-center gap-1">
                        <AlertCircle className="h-4 w-4" />
                        {errors.policyAccepted}
                      </p>
                    )}
                  </div>

                  <Button
                    type="submit"
                    disabled={isSubmitting || !formData.amount || !!errors.amount || !formData.policyAccepted || (validPurchaseAmount && !totalApproved)}
                    className="h-12 w-full rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 text-base font-semibold text-white shadow-md hover:from-purple-700 hover:to-blue-700 disabled:opacity-50"
                    size="lg"
                  >
                    {isSubmitting ? (
                      <>
                        <Loader2 className="mr-2 h-5 w-5 animate-spin" />
                        Processing…
                      </>
                    ) : (
                      <>
                        Add Believe Points
                        <ArrowRight className="ml-2 h-5 w-5" />
                      </>
                    )}
                  </Button>
                </div>
                </form>
              </CardContent>
            </Card>

            <Card className={bpCardClassName}>
              <BpCardHeader
                icon={TrendingUp}
                title="Auto top-up"
                description="Optional — charge your saved card when balance drops below a threshold. Max once per hour."
              />
              <CardContent className={cn(bpCardContentClassName, "space-y-5 pt-0")}>
                <p className="text-sm leading-relaxed text-muted-foreground">
                  Choose a card from your saved payment methods. If charges fail, pick another card or add a new one in{" "}
                  <a href={paymentMethodsManageUrl} className="font-medium text-purple-600 underline-offset-2 hover:underline dark:text-purple-400">
                    Payment Methods
                  </a>
                  .
                </p>
                <SavedPaymentMethodSelector
                  methods={savedPaymentMethods}
                  rail="card"
                  value={arSavedCardId}
                  onChange={setArSavedCardId}
                  manageHref={paymentMethodsManageUrl}
                  showNewOption={false}
                />
                {autoReplenish.enabled && arSavedCardId && (
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="w-full text-destructive sm:w-auto"
                    onClick={() => {
                      if (!confirm("Turn off auto top-up?")) return
                      router.post(route("believe-points.auto-replenish.settings"), { enabled: false }, { preserveScroll: true })
                    }}
                  >
                    Turn off auto top-up
                  </Button>
                )}
                {autoReplenish.last_replenish_at && (
                  <p className="text-xs text-muted-foreground">
                    Last top-up: {new Date(autoReplenish.last_replenish_at).toLocaleString(undefined, {
                      dateStyle: "medium",
                      timeStyle: "short",
                    })}
                  </p>
                )}
                <form onSubmit={saveAutoReplenish} className="space-y-4 border-t border-border pt-5">
                  <div className="flex items-start gap-3 rounded-xl border bg-muted/30 p-4">
                    <Checkbox
                      id="ar-enabled"
                      checked={arState.enabled}
                      onCheckedChange={(checked) => setArState((s) => ({ ...s, enabled: checked === true }))}
                      className="mt-1 size-5 min-h-5 min-w-5"
                    />
                    <Label htmlFor="ar-enabled" className="cursor-pointer text-sm font-medium leading-snug">
                      Enable automatic top-up when my balance is at or below the threshold
                    </Label>
                  </div>
                  {arState.enabled && (
                    <>
                      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                          <Label htmlFor="ar-threshold">When balance ≤</Label>
                          <div className="relative">
                            <DollarSign className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                              id="ar-threshold"
                              className="h-11 border-2 bg-background pl-9 font-medium tabular-nums shadow-sm focus-visible:border-purple-500 focus-visible:ring-2 focus-visible:ring-purple-500/20"
                              value={arState.threshold}
                              onChange={(e) =>
                                setArState((s) => ({ ...s, threshold: e.target.value.replace(/[^0-9.]/g, "") }))
                              }
                              placeholder="e.g. 25"
                              disabled={arSubmitting}
                            />
                          </div>
                        </div>
                        <div className="space-y-2">
                          <Label htmlFor="ar-amount">Top-up amount</Label>
                          <div className="relative">
                            <DollarSign className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                              id="ar-amount"
                              className="h-11 border-2 bg-background pl-9 font-medium tabular-nums shadow-sm focus-visible:border-purple-500 focus-visible:ring-2 focus-visible:ring-purple-500/20"
                              value={arState.amount}
                              onChange={(e) =>
                                setArState((s) => ({ ...s, amount: e.target.value.replace(/[^0-9.]/g, "") }))
                              }
                              placeholder="e.g. 50"
                              disabled={arSubmitting}
                            />
                          </div>
                        </div>
                      </div>
                      <p className="text-xs text-muted-foreground">
                        Between {formatCurrency(minPurchaseAmount)} and {formatCurrency(maxPurchaseAmount)}.
                      </p>
                      <div className="flex items-start gap-3 rounded-xl border bg-muted/30 p-4">
                        <Checkbox
                          id="ar-policy"
                          checked={arState.policyAccepted}
                          onCheckedChange={(checked) =>
                            setArState((s) => ({ ...s, policyAccepted: checked === true }))
                          }
                          className="mt-1 size-5 min-h-5 min-w-5"
                        />
                        <Label htmlFor="ar-policy" className="cursor-pointer text-sm leading-snug">
                          I authorize Believe to charge my saved card for automatic top-ups. Failed charges turn auto
                          top-up off until I add a card again.
                        </Label>
                      </div>
                    </>
                  )}
                  <Button
                    type="submit"
                    disabled={arSubmitting}
                    variant="outline"
                    className="h-11 w-full rounded-xl border-purple-200 font-medium hover:bg-purple-50 dark:border-purple-800 dark:hover:bg-purple-950/30 sm:w-auto"
                  >
                    {arSubmitting ? "Saving…" : "Save auto top-up settings"}
                  </Button>
                </form>
              </CardContent>
            </Card>
          </div>

          <aside className="min-w-0 lg:col-span-4 lg:sticky lg:top-6 lg:self-start">
            <Card className={bpCardClassName}>
              <BpCardHeader
                icon={History}
                title="Recent activity"
                description="Purchases and wallet transfers"
              />
              <CardContent className={bpCardContentClassName}>
                {recentActivity.length === 0 ? (
                  <div className="flex flex-col items-center justify-center py-12 text-center">
                    <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-purple-500/15 to-blue-500/15">
                      <Coins className="h-8 w-8 text-purple-600" />
                    </div>
                    <p className="text-sm font-semibold">No activity yet</p>
                    <p className="mt-1 max-w-[240px] text-sm text-muted-foreground">
                      Add Believe Points or move them to your wallet to see activity here.
                    </p>
                    <Button type="button" variant="outline" size="sm" className="mt-4" onClick={scrollToAddPoints}>
                      Add Believe Points
                    </Button>
                  </div>
                ) : (
                  <ul className="space-y-2">
                    {recentActivity.map((item) => {
                      if (item.kind === "purchase") {
                        const availableOn = purchaseAvailableOn(item.purchase)
                        const purchasedAt = new Date(item.purchase.created_at).toLocaleString(undefined, {
                          month: "short",
                          day: "numeric",
                          hour: "numeric",
                          minute: "2-digit",
                        })

                        return (
                          <li
                            key={`purchase-${item.purchase.id}`}
                            className="overflow-hidden rounded-xl border border-purple-200/60 bg-gradient-to-r from-purple-50/50 via-card to-blue-50/40 shadow-sm dark:border-purple-800/40 dark:from-purple-950/30 dark:via-card dark:to-blue-950/20"
                          >
                            <div className="flex">
                              <div
                                aria-hidden
                                className="w-1 shrink-0 bg-gradient-to-b from-purple-600 to-blue-600"
                              />
                              <div className="flex min-w-0 flex-1 items-center gap-3 px-3 py-2.5">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-purple-600 to-blue-600 text-white shadow-sm shadow-purple-600/25">
                                  <Coins className="h-4 w-4" />
                                </div>

                                <div className="min-w-0 flex-1">
                                  <div className="flex items-center justify-between gap-2">
                                    <div className="flex min-w-0 items-center gap-2">
                                      <span className="bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-base font-bold tabular-nums text-transparent">
                                        +{formatPoints(item.purchase.points)} BP
                                      </span>
                                      {item.purchase.source === "auto_replenish" && (
                                        <span className="rounded-full bg-purple-600/10 px-2 py-0.5 text-xs font-semibold text-purple-700 ring-1 ring-purple-500/25 dark:bg-purple-500/15 dark:text-purple-300">
                                          Auto
                                        </span>
                                      )}
                                    </div>
                                    {getPurchaseStatusBadge(item.purchase)}
                                  </div>
                                  <p className="mt-1 text-sm text-muted-foreground">
                                    Purchased · {purchasedAt}
                                  </p>
                                  {availableOn ? (
                                    <Badge
                                      variant="secondary"
                                      className="mt-1.5 h-auto border-amber-200 bg-amber-50 px-2 py-1 text-xs font-semibold tabular-nums text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
                                    >
                                      <CalendarClock className="h-3 w-3 shrink-0 opacity-80" aria-hidden />
                                      Available On {availableOn}
                                    </Badge>
                                  ) : null}
                                  {item.purchase.status === "completed" &&
                                  item.purchase.points_released &&
                                  item.purchase.settlement_date ? (
                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                      Available {formatAvailableOnLabel(item.purchase.settlement_date)}
                                    </p>
                                  ) : null}
                                  {item.purchase.status === "failed" && item.purchase.failure_message && (
                                    <p className="mt-1 line-clamp-2 text-sm text-destructive">
                                      {item.purchase.failure_message}
                                    </p>
                                  )}
                                </div>
                              </div>
                            </div>
                          </li>
                        )
                      }

                      const isCredit = isWalletTransferCredit(item.transfer.status)
                      const amountLabel = formatPoints(String(item.transfer.amount))
                      const activityDate =
                        isCredit && item.transfer.completed_at
                          ? item.transfer.completed_at
                          : item.transfer.created_at
                      const transferAt = new Date(activityDate).toLocaleString(undefined, {
                        month: "short",
                        day: "numeric",
                        hour: "numeric",
                        minute: "2-digit",
                      })

                      return (
                        <li
                          key={`wallet-transfer-${item.transfer.id}`}
                          className="overflow-hidden rounded-xl border border-blue-200/60 bg-gradient-to-r from-blue-50/50 via-card to-purple-50/40 shadow-sm dark:border-blue-800/40 dark:from-blue-950/30 dark:via-card dark:to-purple-950/20"
                        >
                          <div className="flex">
                            <div
                              aria-hidden
                              className="w-1 shrink-0 bg-gradient-to-b from-blue-600 to-purple-600"
                            />
                            <div className="flex min-w-0 flex-1 items-center gap-3 px-3 py-2.5">
                              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-blue-600 to-purple-600 text-white shadow-sm shadow-blue-600/25">
                                <Wallet className="h-4 w-4" />
                              </div>

                              <div className="min-w-0 flex-1">
                                <div className="flex items-center justify-between gap-2">
                                  <span
                                    className={cn(
                                      "truncate text-base font-bold tabular-nums",
                                      isCredit
                                        ? "bg-gradient-to-r from-emerald-600 to-teal-600 bg-clip-text text-transparent"
                                        : "bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent",
                                    )}
                                  >
                                    {isCredit ? "+" : "−"}
                                    {amountLabel} BP
                                  </span>
                                  {getWalletTransferStatusBadge(item.transfer)}
                                </div>
                                <p className="mt-1 text-sm text-muted-foreground">
                                  {walletTransferAmountLabel(item.transfer.status)} · {transferAt}
                                </p>
                                {(item.transfer.status === "refunded" || item.transfer.status === "failed") &&
                                  item.transfer.failure_message && (
                                    <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">
                                      {item.transfer.failure_message}
                                    </p>
                                  )}
                              </div>
                            </div>
                          </div>
                        </li>
                      )
                    })}
                  </ul>
                )}
              </CardContent>
            </Card>
          </aside>
        </div>

        {walletLedger && (
          <Card className={bpCardClassName}>
            <BpCardHeader
              icon={History}
              title="Wallet ledger"
              description="Your Believe Point activity — purchases, Available dates, transfers, and adjustments."
            />
            <CardContent className={bpCardContentClassName}>
              <BpWalletLedger ledger={walletLedger} />
            </CardContent>
          </Card>
        )}
      </div>

      {quickAddPmId && (
        <QuickAddBelievePointsModal
          open={quickAddOpen}
          onOpenChange={setQuickAddOpen}
          savedPaymentMethodId={quickAddPmId}
          paymentRail={quickAddRail}
          paymentMethods={savedPaymentMethods}
          minPurchaseAmount={minPurchaseAmount}
          maxPurchaseAmount={maxPurchaseAmount}
          purchaseSettings={purchaseSettings}
          currentBalance={currentBalance}
          feePreview={feePreview}
          feePreviewUrl={route("believe-points.index")}
          paymentSavedMessage="Buy Believe Points instantly with your saved payment method."
        />
      )}
    </>
  )

  if (isSupporter) {
    return (
      <>
        <Head title="Believe Points" />
        <ProfileLayout title="BP Wallet" description="Purchase platform credits and manage auto top-up.">
          {content}
        </ProfileLayout>
      </>
    )
  }

  // For organizations and admins, use AppSidebarLayout
  return (
    <AppSidebarLayout>
      <Head title="Add Believe Points" />
      <div className="m-3 min-w-0 space-y-8 md:m-6">
        <div className="mx-auto max-w-6xl">
          <div className="relative overflow-hidden rounded-2xl border border-border/60 bg-card p-6 shadow-sm sm:p-8">
            <div className="absolute inset-y-0 right-0 w-1/3 bg-gradient-to-l from-purple-500/5 to-transparent" />
            <div className="relative max-w-2xl">
              <p className="text-sm font-medium text-purple-600 dark:text-purple-400">Believe Points</p>
              <h1 className="mt-1 text-2xl font-bold tracking-tight sm:text-3xl">Add & manage platform credits</h1>
              <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                Buy BP for eligible purchases. Secure checkout via Stripe.
              </p>
            </div>
          </div>
        </div>
        {content}
      </div>
    </AppSidebarLayout>
  )
}

