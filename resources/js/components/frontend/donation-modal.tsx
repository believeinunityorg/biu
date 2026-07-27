"use client"

import { useEffect, useState } from "react"
import { motion } from "framer-motion"
import { Heart, CreditCard, DollarSign, Info, Check, Shield, Loader2 } from "lucide-react"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent } from "@/components/frontend/ui/card"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/frontend/ui/dialog"
import { Separator } from "@/components/frontend/ui/separator"
import { router, usePage } from "@inertiajs/react"
import { useNotification } from "./notification-provider"
import { SubscriptionRequiredModal } from "@/components/SubscriptionRequiredModal"
import {
  DonationPaymentMethods,
  type DonationPaymentMethodId,
} from "@/components/frontend/donation-payment-methods"
import type { SavedPaymentMethod } from "@/components/account/saved-payment-method-selector"
import type { ProcessingFeeRates } from "@/types"

interface DonationModalProps {
  isOpen: boolean
  onClose: () => void
  organization: {
    id: number
    name: string
    description?: string
    mission?: string
    user?: {
      image?: string | null
      name?: string | null
      email?: string | null
      phone?: string | null
    } | null
    registered_organization?: {
      id: number
      name?: string | null
      user?: {
        image?: string | null
        name?: string | null
        email?: string | null
      } | null
    } | null
  }
}

type FeePreviewRail = "card" | "bank"

interface FeePreviewFromServer {
  mode: "donor_covers" | "org_covers"
  rail?: FeePreviewRail
  base_gift_usd: number
  checkout_total_usd: number
  processing_fee_estimate: number
  estimated_net_to_org_usd: number
}

const donationAmounts = [25, 50, 100, 250, 500, 1000]

const DEFAULT_PROCESSING_FEE_RATES: ProcessingFeeRates = {
  card_percent: 0.029,
  card_fixed_usd: 0.3,
  ach_percent: 0.008,
  ach_fee_cap_usd: 5,
}

const FALLBACK_METHODS: Record<string, boolean> = {
  stripe_card: false,
  stripe_ach: false,
  venmo: false,
  venmo_manual: false,
  cash_app_pay: false,
  paypal: false,
  cashapp: false,
  zelle: false,
}

/** Normalize a stored image path into a browser-resolvable URL. */
function resolveImageSrc(src?: string | null): string | undefined {
  if (!src || typeof src !== "string" || src.trim() === "") return undefined
  const value = src.trim()
  if (
    value.startsWith("http://") ||
    value.startsWith("https://") ||
    value.startsWith("blob:") ||
    value.startsWith("data:") ||
    value.startsWith("/storage/") ||
    value.startsWith("/")
  ) {
    return value
  }
  return `/storage/${value}`
}

const METHOD_ORDER: DonationPaymentMethodId[] = [
  "stripe_card",
  "stripe_ach",
  "paypal",
  "venmo",
  "venmo_manual",
  "cash_app_pay",
  "cashapp",
  "zelle",
]

export default function DonationModal({ isOpen, onClose, organization }: DonationModalProps) {
  const pageProps = usePage().props as any
  const processingFeeRates: ProcessingFeeRates = pageProps.processingFeeRates ?? DEFAULT_PROCESSING_FEE_RATES
  const rewardPointsAmount = Number(pageProps.donationRewardPointsAmount ?? pageProps.rewardPointsAmount ?? 1)
  const authUser = pageProps.auth?.user || null
  const flash = usePage().props
  const { showNotification } = useNotification()

  const savedPaymentMethods = (pageProps.savedPaymentMethods ?? []) as SavedPaymentMethod[]
  const paymentMethodsUrl =
    pageProps.paymentMethodsUrl ?? route("user.profile.payment-methods.index")

  // Registered organization id (the one that can actually receive donations).
  const currentBalance =
    parseFloat(
      String(
        authUser?.donateable_believe_points ??
          authUser?.purchased_believe_points ??
          "0"
      )
    ) || 0
  const availableBalance = parseFloat(String(authUser?.believe_points ?? "0")) || 0
  const processingBalance = parseFloat(String(authUser?.processing_believe_points ?? "0")) || 0

  const organizationId = (organization as any).registered_organization?.id || organization.id

  const [selectedAmount, setSelectedAmount] = useState<number | null>(null)
  const [customAmount, setCustomAmount] = useState("")
  const [paymentMethod, setPaymentMethod] = useState<DonationPaymentMethodId>("stripe_card")
  const [savedPaymentMethodId, setSavedPaymentMethodId] = useState<string | null>(null)
  const [donorCoversProcessingFees, setDonorCoversProcessingFees] = useState(true)
  const [feePreviewRail, setFeePreviewRail] = useState<FeePreviewRail>("card")
  const [donorMessage, setDonorMessage] = useState("")

  const [availableMethods, setAvailableMethods] = useState<Record<string, boolean> | null>(null)
  const [methodsLoading, setMethodsLoading] = useState(false)
  const [feePreview, setFeePreview] = useState<FeePreviewFromServer | null>(null)
  const [feePreviewCheckoutTotalsByRail, setFeePreviewCheckoutTotalsByRail] = useState<{
    card: number
    bank: number
  } | null>(null)
  const [feePreviewLoading, setFeePreviewLoading] = useState(false)

  const [isProcessing, setIsProcessing] = useState(false)
  const [isSuccess, setIsSuccess] = useState(false)
  const [showSubscriptionModal, setShowSubscriptionModal] = useState(false)

  const getCurrentAmount = () => selectedAmount || Number.parseFloat(customAmount) || 0
  const amount = getCurrentAmount()
  const isStripeRail = paymentMethod === "stripe_card" || paymentMethod === "stripe_ach"
  const canUseBelievePoints = authUser ? currentBalance >= amount : false

  const handleAmountSelect = (value: number) => {
    setSelectedAmount(value)
    setCustomAmount("")
  }

  const handleCustomAmountChange = (value: string) => {
    setCustomAmount(value)
    setSelectedAmount(null)
  }

  // Subscription-required + warning flash handling.
  useEffect(() => {
    if ((flash as any)?.warning) {
      showNotification({
        type: "warning",
        message: typeof (flash as any)?.warning === "string" ? (flash as any).warning : "Warning",
      })
    }
    if ((flash as any)?.subscription_required || (flash as any)?.errors?.subscription) {
      setShowSubscriptionModal(true)
    }
  }, [flash, showNotification])

  // Load the organization's enabled payment methods when the modal opens.
  useEffect(() => {
    if (!isOpen || !organizationId) return
    let cancelled = false
    setMethodsLoading(true)
    fetch(`/donate/organization-context?organization_id=${organizationId}`, {
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    })
      .then((res) => (res.ok ? res.json() : Promise.reject(res)))
      .then((data) => {
        if (cancelled) return
        setAvailableMethods(data?.methods ?? FALLBACK_METHODS)
      })
      .catch(() => {
        if (!cancelled) setAvailableMethods(FALLBACK_METHODS)
      })
      .finally(() => {
        if (!cancelled) setMethodsLoading(false)
      })
    return () => {
      cancelled = true
    }
  }, [isOpen, organizationId])

  // Keep the selected method valid for this organization.
  useEffect(() => {
    if (!availableMethods) return
    if (availableMethods[paymentMethod] === false) {
      const fallback = METHOD_ORDER.find((m) => availableMethods[m])
      if (fallback) setPaymentMethod(fallback)
    }
  }, [availableMethods, paymentMethod])

  // Debounced fee preview when amount / rail / fee-coverage changes (Stripe rails only).
  useEffect(() => {
    if (!isOpen || !organizationId) return
    if (!isStripeRail || amount <= 0) {
      setFeePreview(null)
      setFeePreviewCheckoutTotalsByRail(null)
      return
    }
    let cancelled = false
    const t = window.setTimeout(() => {
      setFeePreviewLoading(true)
      const params = new URLSearchParams({
        organization_id: String(organizationId),
        fee_preview_amount: String(amount),
        fee_preview_donor_covers: donorCoversProcessingFees ? "1" : "0",
        fee_preview_rail: feePreviewRail,
      })
      fetch(`/donate/organization-context?${params.toString()}`, {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
      })
        .then((res) => (res.ok ? res.json() : Promise.reject(res)))
        .then((data) => {
          if (cancelled) return
          setFeePreview(data?.feePreview ?? null)
          setFeePreviewCheckoutTotalsByRail(data?.feePreviewCheckoutTotalsByRail ?? null)
        })
        .catch(() => {
          if (!cancelled) {
            setFeePreview(null)
            setFeePreviewCheckoutTotalsByRail(null)
          }
        })
        .finally(() => {
          if (!cancelled) setFeePreviewLoading(false)
        })
    }, 300)
    return () => {
      cancelled = true
      clearTimeout(t)
    }
  }, [isOpen, organizationId, amount, isStripeRail, feePreviewRail, donorCoversProcessingFees])

  const resetForm = () => {
    setSelectedAmount(null)
    setCustomAmount("")
    setDonorMessage("")
    setSavedPaymentMethodId(null)
    setFeePreview(null)
    setFeePreviewCheckoutTotalsByRail(null)
  }

  const handleDonate = () => {
    setIsProcessing(true)
    const orgId = (organization as any).registered_organization?.id || organization.id
    router.post(
      route("donations.store"),
      {
        organization_id: orgId,
        amount: getCurrentAmount(),
        frequency: "one-time",
        message: donorMessage,
        payment_method: paymentMethod,
        donor_covers_processing_fees: isStripeRail ? donorCoversProcessingFees : false,
        donation_fee_rail: isStripeRail ? feePreviewRail : undefined,
        ...(isStripeRail && savedPaymentMethodId ? { saved_payment_method_id: savedPaymentMethodId } : {}),
        name: authUser?.name || "",
        email: authUser?.email || "",
        phone: authUser?.phone || "",
      },
      {
        onSuccess: () => {
          setIsProcessing(false)
          setIsSuccess(false)
          onClose()
          resetForm()
        },
        onError: (errors) => {
          setIsProcessing(false)
          if (errors.subscription || (flash as any)?.subscription_required) {
            setShowSubscriptionModal(true)
          } else {
            showNotification({
              type: "error",
              title: "Donation Failed",
              message:
                errors.payment_method ||
                errors.message ||
                errors.organization_id ||
                "Failed to process donation. Please try again.",
            })
          }
        },
      },
    )
  }

  const getImpactText = (value: number) => {
    if (value >= 1000) return "Fund a major project initiative"
    if (value >= 500) return "Support 50+ families for a month"
    if (value >= 250) return "Provide resources for 25 families"
    if (value >= 100) return "Help 10 families in need"
    if (value >= 50) return "Support 5 families"
    if (value >= 25) return "Help 2-3 families"
    return "Every dollar makes a difference"
  }

  const orgAvatar = resolveImageSrc(
    (organization as any)?.registered_organization?.user?.image || organization?.user?.image || null,
  )

  const orgDescription =
    (organization?.description && organization.description.trim() !== ""
      ? organization.description
      : organization?.mission) || ""

  const hasMethodsReady = Boolean(availableMethods) && !methodsLoading
  const submitDisabled = amount <= 0 || isProcessing || !hasMethodsReady

  if (isSuccess) {
    return (
      <Dialog open={isOpen} onOpenChange={onClose}>
        <DialogContent className="max-w-md rounded-2xl border border-purple-200/60 bg-gradient-to-br from-slate-50 via-purple-50/80 to-blue-50/60 text-gray-900 shadow-2xl backdrop-blur-xl dark:border-purple-700/40 dark:from-gray-950 dark:via-purple-950/50 dark:to-blue-950/40 dark:text-white">
          <div className="text-center py-8 px-2">
            <motion.div
              initial={{ scale: 0 }}
              animate={{ scale: 1 }}
              transition={{ duration: 0.5 }}
              className="w-16 h-16 bg-emerald-500/15 border border-emerald-500/30 rounded-full flex items-center justify-center mx-auto mb-4"
            >
              <Check className="h-8 w-8 text-emerald-500 dark:text-emerald-400" />
            </motion.div>
            <h3 className="text-xl font-bold mb-2">Thank You!</h3>
            <p className="text-gray-600 dark:text-white/80 mb-4">
              Your ${amount} donation to {organization.name} has been processed successfully.
            </p>
            <p className="text-sm text-gray-500 dark:text-white/60">You'll receive a confirmation email shortly.</p>
          </div>
        </DialogContent>
      </Dialog>
    )
  }

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl border border-purple-200/60 bg-gradient-to-br from-slate-50 via-purple-50/80 to-blue-50/60 p-0 text-gray-900 shadow-2xl backdrop-blur-xl dark:border-purple-700/40 dark:from-gray-950 dark:via-purple-950/50 dark:to-blue-950/40 dark:text-white">
        <div className="border-b border-purple-100/80 bg-gradient-to-r from-purple-50/90 via-white/50 to-blue-50/80 p-6 dark:border-purple-800/30 dark:from-purple-900/40 dark:via-purple-950/20 dark:to-blue-950/35">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-3">
              <span className="w-10 h-10 rounded-xl bg-purple-500/15 border border-purple-400/30 flex items-center justify-center dark:bg-purple-500/20">
                <Heart className="h-5 w-5 text-purple-600 fill-purple-500/40 dark:text-purple-300 dark:fill-purple-400/80" />
              </span>
              <span className="leading-tight">
                <span className="block text-sm text-gray-500 dark:text-white/70">Donate</span>
                <span className="block text-xl font-semibold tracking-tight bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent dark:from-purple-300 dark:to-blue-300">
                  {organization.name}
                </span>
              </span>
            </DialogTitle>
            <DialogDescription className="text-gray-500 dark:text-white/70">
              Support this organization and make a real impact.
            </DialogDescription>
          </DialogHeader>
        </div>

        <div className="space-y-6 p-6">
          {/* Organization Info */}
          <Card className="rounded-xl border border-purple-200/60 bg-white/80 dark:border-purple-700/40 dark:bg-white/[0.04]">
            <CardContent className="pt-4">
              <div className="flex items-center gap-4">
                <div className="w-14 h-14 rounded-2xl bg-purple-50 border border-purple-200/60 overflow-hidden flex items-center justify-center flex-shrink-0 dark:bg-white/10 dark:border-white/10">
                  {orgAvatar ? (
                    <img
                      src={orgAvatar}
                      alt={organization.name}
                      className="w-full h-full object-cover"
                      onError={(e) => {
                        ;(e.currentTarget as HTMLImageElement).style.display = "none"
                      }}
                    />
                  ) : (
                    <span className="text-lg font-semibold text-purple-700 dark:text-white/80">
                      {organization?.name?.[0]?.toUpperCase?.() ?? "O"}
                    </span>
                  )}
                </div>
                <div className="flex-1">
                  <h4 className="font-semibold text-gray-900 dark:text-white">{organization.name}</h4>
                  {orgDescription ? (
                    <p className="text-sm text-gray-600 mt-1 line-clamp-2 dark:text-white/70">{orgDescription}</p>
                  ) : (
                    <p className="text-sm text-gray-500 mt-1 dark:text-white/60">Support their mission with a donation.</p>
                  )}
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Amount Selection */}
          <div>
            <Label className="text-base font-semibold mb-3 block text-gray-900 dark:text-white">Select Amount</Label>
            <div className="grid grid-cols-3 gap-3 mb-4">
              {donationAmounts.map((value) => (
                <Button
                  key={value}
                  variant={selectedAmount === value ? "default" : "outline"}
                  onClick={() => handleAmountSelect(value)}
                  className={
                    selectedAmount === value
                      ? "h-12 text-lg bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 border-0 text-white"
                      : "h-12 text-lg border-2 border-purple-100/80 bg-white/80 text-gray-800 hover:border-purple-400 hover:bg-purple-50/60 dark:border-purple-800/30 dark:bg-white/[0.04] dark:text-white dark:hover:bg-purple-950/25"
                  }
                >
                  ${value}
                </Button>
              ))}
            </div>
            <div>
              <Label htmlFor="custom-amount" className="text-sm text-gray-500 mb-2 block dark:text-white/70">
                Or enter custom amount
              </Label>
              <div className="relative">
                <DollarSign className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400 h-4 w-4 dark:text-white/50" />
                <Input
                  id="custom-amount"
                  type="number"
                  placeholder="0.00"
                  value={customAmount}
                  onChange={(e) => handleCustomAmountChange(e.target.value)}
                  className="pl-10 h-12 text-lg border-purple-200/60 bg-white/90 text-gray-900 placeholder:text-gray-400 focus-visible:ring-purple-600/30 focus-visible:border-purple-600 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-white/40"
                />
              </div>
            </div>
          </div>

          {/* Payment Method Selection — enabled methods from the organization */}
          <div className="rounded-xl border border-purple-200/60 bg-white/70 overflow-hidden dark:border-purple-700/40 dark:bg-white/[0.03]">
            <div className="px-5 pt-4 pb-1">
              <Label className="text-base font-semibold text-gray-900 dark:text-white">Choose Payment Method</Label>
            </div>
            <DonationPaymentMethods
              paymentMethod={paymentMethod}
              onPaymentMethodChange={setPaymentMethod}
              availableMethods={availableMethods ?? {}}
              hasOrgSelected={true}
              paymentMethodsLoading={methodsLoading || !availableMethods}
              currentBalance={currentBalance}
              availableBalance={availableBalance}
              processingBalance={processingBalance}
              canUseBelievePoints={canUseBelievePoints}
              amount={amount}
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
          </div>

          {/* Impact Preview */}
          {amount > 0 && (
            <Card className="border-blue-200/60 bg-blue-50/70 dark:border-blue-500/20 dark:bg-blue-500/10">
              <CardContent className="pt-4">
                <div className="flex items-start gap-3">
                  <Info className="h-5 w-5 text-blue-600 mt-0.5 dark:text-blue-200" />
                  <div>
                    <h4 className="font-medium text-blue-900 mb-1 dark:text-blue-100">Your Impact</h4>
                    <p className="text-blue-800/80 text-sm dark:text-blue-100/80">{getImpactText(amount)}</p>
                  </div>
                </div>
              </CardContent>
            </Card>
          )}

          {/* Message */}
          <div>
            <Label htmlFor="message" className="text-gray-900 dark:text-white">Message (Optional)</Label>
            <Input
              id="message"
              placeholder="Share why you're supporting this cause..."
              value={donorMessage}
              onChange={(e) => setDonorMessage(e.target.value)}
              className="border-purple-200/60 bg-white/90 text-gray-900 placeholder:text-gray-400 focus-visible:ring-purple-600/30 focus-visible:border-purple-600 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-white/40"
            />
          </div>

          <Separator className="bg-purple-100/80 dark:bg-white/10" />

          {/* Action Buttons */}
          <div className="flex gap-3 pt-2">
            <Button
              variant="outline"
              onClick={onClose}
              className="flex-1 border border-purple-200/60 bg-white/80 text-gray-800 hover:bg-purple-50/60 dark:border-white/10 dark:bg-white/5 dark:text-white dark:hover:bg-white/10"
              disabled={isProcessing}
            >
              Cancel
            </Button>
            <Button
              onClick={handleDonate}
              className="flex-1 bg-gradient-to-r from-purple-500 via-purple-600 to-pink-500 hover:from-purple-600 hover:via-purple-700 hover:to-pink-600 border-0 shadow-lg shadow-purple-500/20"
              disabled={submitDisabled}
            >
              {isProcessing ? (
                <div className="flex items-center gap-2">
                  <Loader2 className="w-4 h-4 animate-spin" />
                  Processing...
                </div>
              ) : (
                <>
                  <CreditCard className="mr-2 h-4 w-4" />
                  Donate ${amount.toFixed(2)}
                </>
              )}
            </Button>
          </div>

          {/* Security Notice */}
          <div className="text-center text-xs text-gray-500 flex items-center justify-center gap-2 dark:text-white/60">
            <Shield className="h-3.5 w-3.5 text-green-600 dark:text-green-400" />
            <span>100% Secure Donations · secured by Stripe</span>
          </div>
        </div>
      </DialogContent>

      {/* Subscription Required Modal - Supporter View */}
      <SubscriptionRequiredModal
        isOpen={showSubscriptionModal}
        onClose={() => setShowSubscriptionModal(false)}
        feature="donations"
        isSupporterView={true}
      />
    </Dialog>
  )
}
