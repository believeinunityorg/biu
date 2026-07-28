"use client"

import { Head, router, useForm, usePage } from "@inertiajs/react"
import { useMemo, useState } from "react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Badge } from "@/components/ui/badge"
import {
  ArrowLeft,
  Building2,
  Calendar,
  CheckCircle2,
  Coins,
  CreditCard,
  Gift,
  Globe,
  Info,
  Loader2,
  ShieldCheck,
  Sparkles,
  Wallet,
} from "lucide-react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import AppSidebarLayout from "@/layouts/app/app-sidebar-layout"
import toast from "react-hot-toast"
import { openWalletPopup } from "@/lib/open-wallet-popup"
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectSeparator,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select"
import { cn } from "@/lib/utils"

interface Brand {
  productId?: number
  productName?: string
  /** False for Visa/Mastercard — use Bridge Wallet Cards instead of BP */
  allowsGiftBp?: boolean
  /** True for open-loop Visa/MC — Bridge Wallet path */
  requiresBridgeWallet?: boolean
  productImage?: string
  denominations?: number[]
  valueRestrictions?: {
    minVal?: number
    maxVal?: number
  }
  productDescription?: string
  termsAndConditions?: string
  howToUse?: string
  expiryAndValidity?: string
  discount?: number
}

interface Organization {
  id: number
  name: string
  gift_card_terms_approved?: boolean
}

interface OrganizationGiftCardPurchase extends Organization {
  purchased_total?: number
}

interface PurchaseDetailsProps {
  brand: Brand
  country: string
  user?: {
    id: number
    name: string
    email: string
    role: string
  } | null
  /** Prime Supporter tier — required for Visa/MC Bridge Wallet Cards */
  is_prime_supporter?: boolean
  organizations: Organization[]
  giftCardPurchaseOrganizations?: OrganizationGiftCardPurchase[]
  platformFeeUsd?: number
}

/** Phaze HTML often includes inline dark colors; force readable text in light + dark mode */
const brandHtmlBlockClassName =
  "prose prose-sm max-w-none dark:prose-invert " +
  "text-slate-700 dark:text-slate-200 " +
  "[&_*]:!text-slate-700 dark:[&_*]:!text-slate-200 [&_a]:!text-purple-600 dark:[&_a]:!text-purple-400 " +
  "[&_strong]:!text-slate-900 dark:[&_strong]:!text-slate-100 [&_*]:break-words"

type InfoTab = "about" | "how" | "terms" | "expiry"

export default function PurchaseDetailsPage({
  brand,
  country,
  user,
  is_prime_supporter: isPrimeSupporterProp = false,
  organizations: organizationsProp,
  giftCardPurchaseOrganizations: giftCardPurchaseOrganizationsProp = [],
  platformFeeUsd: platformFeeUsdProp = 0.5,
}: PurchaseDetailsProps) {
  const page = usePage()
  const pageProps = page.props as PurchaseDetailsProps & { auth?: any }
  const organizations = pageProps.organizations ?? organizationsProp
  const giftCardPurchaseOrganizations =
    pageProps.giftCardPurchaseOrganizations ?? giftCardPurchaseOrganizationsProp
  const platformFeeUsd = Number(pageProps.platformFeeUsd ?? platformFeeUsdProp ?? 0.5) || 0
  const auth = pageProps.auth
  const availableBelievePoints = parseFloat(auth?.user?.believe_points) || 0
  const giftedBelievePoints = parseFloat(auth?.user?.gifted_believe_points) || 0
  const requiresBridgeWallet =
    brand.requiresBridgeWallet === true || brand.allowsGiftBp === false
  const spendableForSku = availableBelievePoints
  const planSlug =
    (auth?.user?.current_plan_details?.wallet_plan_slug as string | undefined) ??
    null
  const isPrimeSupporter =
    Boolean(pageProps.is_prime_supporter ?? isPrimeSupporterProp) ||
    planSlug === "prime_supporter"

  const isOrgOrAdmin = Boolean(user) && user!.role !== "user" && user!.role !== null
  const Layout = isOrgOrAdmin ? AppSidebarLayout : FrontendLayout

  const [selectedAmount, setSelectedAmount] = useState<number | null>(null)
  const [customAmount, setCustomAmount] = useState("")
  const [selectedOrganizationId, setSelectedOrganizationId] = useState<string>("")

  const infoTabs = useMemo(() => {
    const tabs: { id: InfoTab; label: string; html: string }[] = []
    if (brand.productDescription) {
      tabs.push({ id: "about", label: "About", html: brand.productDescription })
    }
    if (brand.howToUse) {
      tabs.push({ id: "how", label: "How to use", html: brand.howToUse })
    }
    if (brand.termsAndConditions) {
      tabs.push({ id: "terms", label: "Terms", html: brand.termsAndConditions })
    }
    if (brand.expiryAndValidity) {
      tabs.push({ id: "expiry", label: "Validity", html: brand.expiryAndValidity })
    }
    return tabs
  }, [brand.productDescription, brand.howToUse, brand.termsAndConditions, brand.expiryAndValidity])

  const [activeInfoTab, setActiveInfoTab] = useState<InfoTab | null>(null)
  const resolvedInfoTab =
    infoTabs.find((t) => t.id === activeInfoTab)?.id ?? infoTabs[0]?.id ?? null

  const { data, setData, post, processing, errors } = useForm({
    productId: brand.productId || 0,
    amount: 0,
    organization_id: 0,
    country: country,
    brand_name: brand.productName || "",
    currency: "USD",
    payment_method: "believe_points" as const,
  })

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency: "USD",
    }).format(amount)
  }

  const denominations = brand.denominations || []
  const valueRestrictions = brand.valueRestrictions || {
    minVal: 0.01,
    maxVal: 10000,
  }

  const minVal =
    valueRestrictions.minVal && !isNaN(valueRestrictions.minVal) && valueRestrictions.minVal > 0
      ? Number(valueRestrictions.minVal)
      : 0.01
  const maxVal =
    valueRestrictions.maxVal && !isNaN(valueRestrictions.maxVal) && valueRestrictions.maxVal > 0
      ? Number(valueRestrictions.maxVal)
      : null
  const hasMaxLimit = maxVal !== null && maxVal > 0
  const hasDiscount = (brand.discount ?? 0) > 0

  const handleAmountSelect = (amount: number) => {
    setSelectedAmount(amount)
    setCustomAmount(amount.toString())
    setData("amount", amount)
  }

  const handleCustomAmount = (value: string) => {
    setCustomAmount(value)
    setSelectedAmount(null)
    const numValue = parseFloat(value)
    if (!isNaN(numValue) && numValue >= minVal && (maxVal === null || numValue <= maxVal)) {
      setData("amount", numValue)
    } else {
      setData("amount", 0)
    }
  }

  const handleOrganizationSelect = (orgId: string) => {
    setSelectedOrganizationId(orgId)
    setData("organization_id", parseInt(orgId))
  }

  const purchaseOrgIds = useMemo(
    () => new Set(giftCardPurchaseOrganizations.map((o) => Number(o.id))),
    [giftCardPurchaseOrganizations],
  )
  const organizationsRest = useMemo(
    () => organizations.filter((o) => o.id != null && !purchaseOrgIds.has(Number(o.id))),
    [organizations, purchaseOrgIds],
  )

  const selectedOrganization = useMemo(() => {
    if (!selectedOrganizationId) return undefined
    return (
      giftCardPurchaseOrganizations.find(
        (org) => org.id != null && String(org.id) === selectedOrganizationId,
      ) ?? organizations.find((org) => org.id != null && String(org.id) === selectedOrganizationId)
    )
  }, [selectedOrganizationId, giftCardPurchaseOrganizations, organizations])
  const isOrganizationApproved = selectedOrganization?.gift_card_terms_approved ?? false

  const handlePurchase = (e: React.FormEvent) => {
    e.preventDefault()

    if (!selectedOrganizationId) {
      toast.error("Please select an organization")
      return
    }

    if (data.amount < minVal || (maxVal !== null && data.amount > maxVal)) {
      const maxMsg = maxVal !== null ? ` and ${formatCurrency(maxVal)}` : ""
      toast.error(`Amount must be at least ${formatCurrency(minVal)}${maxMsg}`)
      return
    }

    if (!user || user.role !== "user") {
      if (!user) {
        router.visit(route("login"))
      } else {
        toast.error("Only users can purchase gift cards")
      }
      return
    }

    post(route("gift-cards.purchase.store"), {
      preserveScroll: true,
      onSuccess: () => {
        // Redirect handled by response
      },
      onError: (errs) => {
        if (errs && typeof errs === "object") {
          Object.entries(errs).forEach(([, value]) => {
            if (value) {
              if (Array.isArray(value)) {
                value.forEach((err) => {
                  if (typeof err === "string") toast.error(err)
                })
              } else if (typeof value === "string") {
                toast.error(value)
              }
            }
          })
        } else {
          toast.error("Failed to process purchase. Please try again.")
        }
      },
    })
  }

  const isValidAmount =
    data.amount >= minVal && (maxVal === null || data.amount <= maxVal) && data.amount > 0
  const isValidForm = isValidAmount && selectedOrganizationId

  const totalChargedBp = data.amount > 0 ? Number((data.amount + platformFeeUsd).toFixed(2)) : 0
  const believePointsSufficientForSku =
    data.amount > 0 && spendableForSku >= totalChargedBp

  const activeInfoHtml = infoTabs.find((t) => t.id === resolvedInfoTab)?.html

  return (
    <Layout>
      <Head title={`${brand.productName || "Gift Card"} — Purchase`} />

      <div className="flex h-full min-w-0 flex-1 flex-col">
        <header className="relative w-full overflow-hidden bg-gradient-to-br from-purple-600 via-purple-600 to-blue-600 text-white shadow-lg">
          <div
            aria-hidden
            className="pointer-events-none absolute inset-0 opacity-30"
            style={{
              backgroundImage:
                "radial-gradient(circle at 15% 20%, rgba(255,255,255,0.35), transparent 45%), radial-gradient(circle at 85% 10%, rgba(255,255,255,0.2), transparent 40%), radial-gradient(circle at 70% 90%, rgba(59,130,246,0.5), transparent 50%)",
            }}
          />
          <div
            aria-hidden
            className="pointer-events-none absolute -right-16 -top-16 h-56 w-56 rounded-full bg-white/10 blur-2xl"
          />

          <div className="relative mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 sm:py-8 md:px-10">
            <button
              type="button"
              onClick={() => router.visit(route("gift-cards.index"))}
              className="mb-4 inline-flex items-center gap-2 rounded-lg text-sm font-medium text-white/85 transition hover:text-white"
            >
              <ArrowLeft className="h-4 w-4" />
              Back to Gift Cards
            </button>

            <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
              <div className="min-w-0">
                <p className="text-xs font-medium uppercase tracking-[0.16em] text-white/75">
                  Gift card
                </p>
                <h1 className="mt-1.5 text-2xl font-semibold tracking-tight sm:text-3xl md:text-4xl">
                  {brand.productName || "Gift Card"}
                </h1>
                <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-white/85">
                  <span className="inline-flex items-center gap-1.5">
                    <Globe className="h-3.5 w-3.5" />
                    {country}
                  </span>
                  {requiresBridgeWallet && (
                    <Badge className="border-0 bg-sky-950/55 text-[11px] font-medium text-white backdrop-blur-sm">
                      {isPrimeSupporter ? "Bridge Wallet · Prime" : "Prime benefit"}
                    </Badge>
                  )}
                  {hasDiscount && (
                    <Badge className="border-0 bg-white/20 text-[11px] font-semibold text-white backdrop-blur-sm">
                      {brand.discount}% off
                    </Badge>
                  )}
                </div>
              </div>

              {user && user.role === "user" && !requiresBridgeWallet && (
                <div className="flex items-center gap-2 rounded-xl bg-white/15 px-4 py-2.5 text-sm backdrop-blur-sm">
                  <Wallet className="h-4 w-4 shrink-0" />
                  <span className="tabular-nums">
                    {availableBelievePoints.toFixed(2)} Available BP
                  </span>
                </div>
              )}
            </div>
          </div>
        </header>

        <div className="container mx-auto flex min-w-0 flex-1 flex-col gap-6 px-3 py-4 sm:gap-8 sm:px-4 md:px-10 md:py-8">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-12 lg:gap-8">
            {/* Product visual + details */}
            <div className="space-y-6 lg:col-span-7">
              <div className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm dark:border-gray-800 dark:bg-gray-900/80">
                {brand.productImage ? (
                  <div className="aspect-[16/10] w-full overflow-hidden bg-muted dark:bg-gray-800">
                    <img
                      src={brand.productImage}
                      alt={brand.productName || "Gift Card"}
                      className="h-full w-full object-cover"
                    />
                  </div>
                ) : (
                  <div className="flex aspect-[16/10] w-full items-center justify-center bg-gradient-to-br from-purple-600/15 to-blue-600/15">
                    <Gift className="h-20 w-20 text-purple-600/40" />
                  </div>
                )}
              </div>

              {infoTabs.length > 0 && (
                <section className="rounded-2xl border border-border/70 bg-card p-4 shadow-sm sm:p-5 dark:border-gray-800 dark:bg-gray-900/80">
                  <div className="mb-4 flex flex-wrap gap-1.5 border-b border-border/60 pb-3 dark:border-gray-800">
                    {infoTabs.map((tab) => (
                      <button
                        key={tab.id}
                        type="button"
                        onClick={() => setActiveInfoTab(tab.id)}
                        className={cn(
                          "rounded-lg px-3 py-1.5 text-sm font-medium transition",
                          resolvedInfoTab === tab.id
                            ? "bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm"
                            : "text-muted-foreground hover:bg-muted hover:text-foreground",
                        )}
                      >
                        {tab.label}
                      </button>
                    ))}
                  </div>
                  <div
                    className={brandHtmlBlockClassName}
                    dangerouslySetInnerHTML={{
                      __html: activeInfoHtml ?? "",
                    }}
                  />
                </section>
              )}

              <section className="rounded-2xl border border-border/70 bg-card p-4 shadow-sm sm:p-5 dark:border-gray-800 dark:bg-gray-900/80">
                <h2 className="flex items-center gap-2 text-base font-semibold text-foreground">
                  <ShieldCheck className="h-4 w-4 text-purple-600" />
                  How it works
                </h2>
                <ul className="mt-4 space-y-3">
                  {[
                    `Pay with Available Believe Points${platformFeeUsd > 0 ? ` (includes ${formatCurrency(platformFeeUsd)} platform fee)` : ""}`,
                    "BP is deducted immediately when you submit",
                    "Gift card issuance begins after a 72-hour waiting period",
                    "You will be notified when your gift card is ready",
                  ].map((text) => (
                    <li key={text} className="flex items-start gap-2.5 text-sm text-muted-foreground">
                      <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0 text-purple-600" />
                      <span>{text}</span>
                    </li>
                  ))}
                </ul>
              </section>
            </div>

            {/* Purchase panel — closed-loop BP, or Bridge Wallet for Visa/MC */}
            <div className="lg:col-span-5">
              <div className="space-y-4 lg:sticky lg:top-4 lg:self-start">
                {requiresBridgeWallet ? (
                  <div className="rounded-2xl border border-sky-500/25 bg-card p-4 shadow-sm sm:p-5 dark:border-sky-500/20 dark:bg-gray-900/80">
                    <div className="mb-5 flex items-start gap-3">
                      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-sky-600 to-blue-700 text-white shadow-md shadow-sky-600/25">
                        <CreditCard className="h-5 w-5" />
                      </div>
                      <div>
                        <h2 className="text-lg font-semibold tracking-tight text-foreground">
                          {isPrimeSupporter
                            ? "Get this card via Bridge Wallet"
                            : "Prime Supporter benefit"}
                        </h2>
                        <p className="mt-0.5 text-sm text-muted-foreground">
                          {isPrimeSupporter
                            ? "Visa and Mastercard are open-loop. Believe Points cannot buy them — use your Bridge balance instead."
                            : "Visa and Mastercard open-loop cards are available through Bridge Wallet for Prime Supporters only."}
                        </p>
                      </div>
                    </div>

                    {isPrimeSupporter ? (
                      <>
                        <ol className="mb-5 space-y-3">
                          {[
                            {
                              title: "Open Bridge Wallet",
                              body: "Use the wallet in the header (or the button below).",
                            },
                            {
                              title: "Add money if needed",
                              body: "Fund your Bridge balance with ACH, wire, or supported rails.",
                            },
                            {
                              title: "Open Services → Cards",
                              body: "Issue a virtual card linked to your Bridge Wallet balance.",
                            },
                          ].map((step, index) => (
                            <li key={step.title} className="flex gap-3">
                              <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-sky-500/15 text-xs font-semibold text-sky-700 dark:text-sky-300">
                                {index + 1}
                              </span>
                              <div className="min-w-0 pt-0.5">
                                <p className="text-sm font-semibold text-foreground">{step.title}</p>
                                <p className="text-xs text-muted-foreground">{step.body}</p>
                              </div>
                            </li>
                          ))}
                        </ol>

                        <div className="mb-4 rounded-xl border border-border/70 bg-muted/40 p-3 dark:border-gray-800 dark:bg-gray-800/40">
                          <div className="flex items-start gap-2">
                            <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-sky-600" />
                            <p className="text-xs leading-relaxed text-muted-foreground">
                              Closed-loop brand cards (Walmart, Amazon, and similar) stay on Believe Points.
                              Network cards stay on Bridge so open-loop spend stays on your funded wallet.
                            </p>
                          </div>
                        </div>

                        {!user ? (
                          <Button
                            type="button"
                            size="lg"
                            className="h-12 w-full rounded-xl bg-gradient-to-r from-sky-600 to-blue-700 text-white shadow-md shadow-sky-600/20 hover:from-sky-500 hover:to-blue-600"
                            onClick={() => router.visit(route("login"))}
                          >
                            Login to open Bridge Wallet
                          </Button>
                        ) : user.role !== "user" ? (
                          <Button disabled className="h-12 w-full rounded-xl" variant="outline" size="lg">
                            Only supporters can use Bridge Cards here
                          </Button>
                        ) : (
                          <div className="space-y-2.5">
                            <Button
                              type="button"
                              size="lg"
                              className="h-12 w-full rounded-xl bg-gradient-to-r from-sky-600 to-blue-700 text-white shadow-md shadow-sky-600/20 hover:from-sky-500 hover:to-blue-600"
                              onClick={() => openWalletPopup({ view: "virtual_card" })}
                            >
                              <CreditCard className="mr-2 h-5 w-5" />
                              Open Bridge Wallet → Cards
                            </Button>
                            <Button
                              type="button"
                              size="lg"
                              variant="outline"
                              className="h-11 w-full rounded-xl"
                              onClick={() => openWalletPopup({ view: "addMoney" })}
                            >
                              <Wallet className="mr-2 h-4 w-4" />
                              Add money to Bridge
                            </Button>
                          </div>
                        )}
                      </>
                    ) : (
                      <>
                        <div className="mb-5 rounded-xl border border-purple-500/25 bg-gradient-to-r from-purple-600/[0.07] to-blue-600/[0.07] p-4 dark:from-purple-500/10 dark:to-blue-500/10">
                          <div className="flex items-start gap-3">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-purple-600 to-blue-600 text-white">
                              <Sparkles className="h-4 w-4" />
                            </div>
                            <div className="min-w-0">
                              <p className="text-sm font-semibold text-foreground">
                                Upgrade to unlock Bridge Cards
                              </p>
                              <p className="mt-1 text-xs leading-relaxed text-muted-foreground">
                                Prime Supporters can issue a Visa/Mastercard-style virtual card from Bridge Wallet.
                                Free supporters can still buy closed-loop brand gift cards with Believe Points.
                              </p>
                            </div>
                          </div>
                        </div>

                        {!user ? (
                          <Button
                            type="button"
                            size="lg"
                            className="h-12 w-full rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-md shadow-purple-600/20 hover:from-purple-500 hover:to-blue-500"
                            onClick={() =>
                              router.visit(
                                route("login", {}, false) +
                                  "?redirect=" +
                                  encodeURIComponent("/pricing?tab=supporters"),
                              )
                            }
                          >
                            Login to become a Prime Supporter
                          </Button>
                        ) : user.role !== "user" ? (
                          <Button disabled className="h-12 w-full rounded-xl" variant="outline" size="lg">
                            Only supporters can upgrade here
                          </Button>
                        ) : (
                          <Button
                            type="button"
                            size="lg"
                            className="h-12 w-full rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-md shadow-purple-600/20 hover:from-purple-500 hover:to-blue-500"
                            onClick={() => router.visit(route("pricing") + "?tab=supporters")}
                          >
                            <Sparkles className="mr-2 h-5 w-5" />
                            Become a Prime Supporter
                          </Button>
                        )}
                      </>
                    )}
                  </div>
                ) : (
                <form
                  onSubmit={handlePurchase}
                  className="rounded-2xl border border-border/70 bg-card p-4 shadow-sm sm:p-5 dark:border-gray-800 dark:bg-gray-900/80"
                >
                  <div className="mb-5 flex items-start gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-purple-600 to-blue-600 text-white shadow-md shadow-purple-600/25">
                      <Sparkles className="h-5 w-5" />
                    </div>
                    <div>
                      <h2 className="text-lg font-semibold tracking-tight text-foreground">
                        Purchase
                      </h2>
                      <p className="mt-0.5 text-sm text-muted-foreground">
                        Choose organization and amount
                      </p>
                    </div>
                  </div>

                  {((errors as any).auth || (errors as any).error) && (
                    <div className="mb-4 rounded-xl border border-destructive/20 bg-destructive/10 p-3">
                      <p className="text-sm text-destructive">
                        {(errors as any).auth || (errors as any).error}
                      </p>
                    </div>
                  )}

                  <div className="space-y-5">
                    {user && user.role === "user" && organizations.length > 0 && (
                      <div>
                        <Label className="mb-2 block text-sm font-medium">
                          Organization <span className="text-destructive">*</span>
                        </Label>
                        <Select
                          value={selectedOrganizationId}
                          onValueChange={handleOrganizationSelect}
                        >
                          <SelectTrigger className="h-11 w-full rounded-xl dark:border-gray-700 dark:bg-gray-900">
                            <Building2 className="mr-2 h-4 w-4 shrink-0 text-muted-foreground" />
                            <SelectValue placeholder="Choose an organization" />
                          </SelectTrigger>
                          <SelectContent className="z-[200] max-h-80 dark:border-gray-700 dark:bg-gray-900">
                            {giftCardPurchaseOrganizations.filter((org) => org.id != null).length >
                              0 && (
                              <SelectGroup>
                                <SelectLabel className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                  Your gift card purchases
                                </SelectLabel>
                                {giftCardPurchaseOrganizations
                                  .filter((org) => org.id != null)
                                  .map((org) => (
                                    <SelectItem
                                      key={org.id}
                                      value={String(org.id)}
                                      className="dark:focus:bg-gray-800 dark:hover:bg-gray-800"
                                    >
                                      {org.name}
                                      {typeof org.purchased_total === "number" &&
                                      org.purchased_total > 0
                                        ? ` — ${formatCurrency(org.purchased_total)} total`
                                        : ""}
                                    </SelectItem>
                                  ))}
                              </SelectGroup>
                            )}
                            {giftCardPurchaseOrganizations.length > 0 &&
                              organizationsRest.length > 0 && (
                                <SelectSeparator className="dark:bg-gray-700" />
                              )}
                            {organizationsRest.length > 0 && (
                              <SelectGroup>
                                {giftCardPurchaseOrganizations.length > 0 && (
                                  <SelectLabel className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                    All organizations
                                  </SelectLabel>
                                )}
                                {organizationsRest.map((org) => (
                                  <SelectItem
                                    key={org.id}
                                    value={String(org.id)}
                                    className="dark:focus:bg-gray-800 dark:hover:bg-gray-800"
                                  >
                                    {org.name}
                                  </SelectItem>
                                ))}
                              </SelectGroup>
                            )}
                          </SelectContent>
                        </Select>
                        {errors.organization_id && (
                          <p className="mt-2 text-sm text-destructive">{errors.organization_id}</p>
                        )}
                        {selectedOrganizationId && !isOrganizationApproved && (
                          <div className="mt-3 rounded-xl border border-amber-500/30 bg-amber-500/10 p-3">
                            <div className="flex items-start gap-2">
                              <Info className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" />
                              <div className="text-sm text-amber-800 dark:text-amber-200">
                                <p className="font-semibold">Organization approval required</p>
                                <p className="mt-0.5 text-amber-700/90 dark:text-amber-300/90">
                                  This organization has not approved gift card program terms yet.
                                </p>
                              </div>
                            </div>
                          </div>
                        )}
                      </div>
                    )}

                    <div>
                      <Label className="mb-2 block text-sm font-medium">Amount</Label>

                      {denominations.length > 0 && (
                        <div className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-3">
                          {denominations.map((amount) => (
                            <button
                              key={amount}
                              type="button"
                              onClick={() => handleAmountSelect(amount)}
                              className={cn(
                                "rounded-xl border-2 px-3 py-3 text-sm font-semibold tabular-nums transition",
                                selectedAmount === amount
                                  ? "border-purple-600 bg-gradient-to-r from-purple-600/10 to-blue-600/10 text-foreground shadow-sm"
                                  : "border-border/80 text-foreground hover:border-purple-500/50 dark:border-gray-700",
                              )}
                            >
                              {formatCurrency(amount)}
                            </button>
                          ))}
                        </div>
                      )}

                      <Label
                        htmlFor="custom-amount"
                        className="mb-1.5 block text-xs text-muted-foreground"
                      >
                        Or enter a custom amount
                      </Label>
                      <Input
                        id="custom-amount"
                        type="number"
                        placeholder={
                          hasMaxLimit
                            ? `${formatCurrency(minVal)} – ${formatCurrency(maxVal)}`
                            : `Min ${formatCurrency(minVal)}`
                        }
                        value={customAmount}
                        onChange={(e) => handleCustomAmount(e.target.value)}
                        min={minVal}
                        max={hasMaxLimit ? maxVal : undefined}
                        step="0.01"
                        className="h-11 rounded-xl text-base dark:border-gray-700 dark:bg-gray-900"
                      />
                      <div className="mt-1.5 flex justify-between text-xs text-muted-foreground">
                        <span>Min {formatCurrency(minVal)}</span>
                        {hasMaxLimit && <span>Max {formatCurrency(maxVal)}</span>}
                      </div>
                      {customAmount && (
                        <div className="mt-1.5 text-sm">
                          {(() => {
                            const numValue = parseFloat(customAmount)
                            if (isNaN(numValue) || customAmount === "") {
                              return (
                                <span className="text-muted-foreground">Enter a valid amount</span>
                              )
                            }
                            if (numValue < minVal) {
                              return (
                                <span className="text-destructive">
                                  Below minimum ({formatCurrency(minVal)})
                                </span>
                              )
                            }
                            if (hasMaxLimit && numValue > maxVal!) {
                              return (
                                <span className="text-destructive">
                                  Above maximum ({formatCurrency(maxVal!)})
                                </span>
                              )
                            }
                            return (
                              <span className="font-medium text-emerald-600 dark:text-emerald-400">
                                Valid: {formatCurrency(numValue)}
                              </span>
                            )
                          })()}
                        </div>
                      )}
                      {errors.amount && (
                        <p className="mt-2 text-sm text-destructive">{errors.amount}</p>
                      )}
                    </div>

                    {isValidAmount && (
                      <div className="rounded-xl border border-purple-500/25 bg-gradient-to-r from-purple-600/[0.07] to-blue-600/[0.07] p-4 dark:from-purple-500/10 dark:to-blue-500/10">
                        <div className="flex items-end justify-between gap-3">
                          <div>
                            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                              Total charged
                            </p>
                            <p className="mt-1 bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-3xl font-bold tabular-nums text-transparent">
                              {formatCurrency(totalChargedBp)}
                            </p>
                            <div className="mt-1.5 space-y-0.5 text-xs text-muted-foreground">
                              <p>Gift card: {formatCurrency(data.amount)}</p>
                              {platformFeeUsd > 0 && (
                                <p>Platform fee: {formatCurrency(platformFeeUsd)}</p>
                              )}
                            </div>
                          </div>
                          <Coins className="h-8 w-8 shrink-0 text-purple-600/50" />
                        </div>
                      </div>
                    )}

                    {isValidAmount && user && user.role === "user" && (
                      <div className="space-y-3 rounded-xl border border-border/70 bg-muted/40 p-4 dark:border-gray-800 dark:bg-gray-800/40">
                        <div className="flex items-start gap-3">
                          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-amber-500/15">
                            <Coins className="h-4 w-4 text-amber-600" />
                          </div>
                          <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                              <p className="font-semibold text-foreground">Believe Points</p>
                              {!believePointsSufficientForSku && (
                                <Badge variant="destructive" className="text-[10px]">
                                  Insufficient
                                </Badge>
                              )}
                            </div>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                              Available:{" "}
                              <span className="font-medium tabular-nums text-foreground">
                                {availableBelievePoints.toFixed(2)} BP
                              </span>
                            </p>
                            {giftedBelievePoints > 0 && (
                              <p className="mt-1 text-xs text-muted-foreground">
                                Gift BP:{" "}
                                <span className="font-medium tabular-nums text-foreground">
                                  {giftedBelievePoints.toFixed(2)}
                                </span>
                                {" "}
                                (reporting subset; decreases on closed-loop gift cards)
                              </p>
                            )}
                            {believePointsSufficientForSku && (
                              <p className="mt-1 text-xs text-emerald-600 dark:text-emerald-400">
                                {(spendableForSku - totalChargedBp).toFixed(2)} Available BP remaining after
                                redemption
                              </p>
                            )}
                          </div>
                        </div>

                        {!believePointsSufficientForSku && (
                          <p className="text-sm text-destructive">
                            {platformFeeUsd > 0
                              ? `You need ${totalChargedBp.toFixed(2)} Available BP (gift card ${data.amount.toFixed(2)} + fee ${platformFeeUsd.toFixed(2)}) but only have ${availableBelievePoints.toFixed(2)}.`
                              : `You need ${totalChargedBp.toFixed(2)} Available BP but only have ${availableBelievePoints.toFixed(2)}.`}
                          </p>
                        )}

                        <p className="text-xs text-muted-foreground">
                          Points are deducted immediately
                          {platformFeeUsd > 0
                            ? ` (includes a ${formatCurrency(platformFeeUsd)} platform fee)`
                            : ""}
                          . Issuance starts after 72 hours.
                        </p>
                      </div>
                    )}

                    {!user ? (
                      <Button
                        type="button"
                        size="lg"
                        className="h-12 w-full rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-md shadow-purple-600/20 hover:from-purple-500 hover:to-blue-500"
                        onClick={() => router.visit(route("login"))}
                      >
                        Login to purchase
                      </Button>
                    ) : user.role !== "user" ? (
                      <Button disabled className="h-12 w-full rounded-xl" variant="outline" size="lg">
                        Only supporters can purchase gift cards
                      </Button>
                    ) : organizations.length === 0 ? (
                      <Button disabled className="h-12 w-full rounded-xl" variant="outline" size="lg">
                        No organizations available
                      </Button>
                    ) : (
                      <Button
                        type="submit"
                        size="lg"
                        className="h-12 w-full rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-md shadow-purple-600/20 hover:from-purple-500 hover:to-blue-500 disabled:opacity-60"
                        disabled={
                          !isValidForm ||
                          processing ||
                          !isOrganizationApproved ||
                          !believePointsSufficientForSku
                        }
                      >
                        {processing ? (
                          <>
                            <Loader2 className="mr-2 h-5 w-5 animate-spin" />
                            Processing…
                          </>
                        ) : !isOrganizationApproved ? (
                          <>
                            <Info className="mr-2 h-5 w-5" />
                            Organization approval required
                          </>
                        ) : (
                          <>
                            <Coins className="mr-2 h-5 w-5" />
                            Pay with Believe Points
                          </>
                        )}
                      </Button>
                    )}
                  </div>
                </form>
                )}

                {/* Compact summary */}
                <div className="rounded-2xl border border-border/70 bg-card p-4 shadow-sm dark:border-gray-800 dark:bg-gray-900/80">
                  <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Summary
                  </p>
                  <dl className="mt-3 space-y-2.5 text-sm">
                    <div className="flex justify-between gap-3">
                      <dt className="text-muted-foreground">Brand</dt>
                      <dd className="max-w-[60%] truncate text-right font-medium text-foreground">
                        {brand.productName || "Gift Card"}
                      </dd>
                    </div>
                    <div className="flex justify-between gap-3">
                      <dt className="text-muted-foreground">Country</dt>
                      <dd>
                        <Badge variant="outline" className="font-normal dark:border-gray-700">
                          {country}
                        </Badge>
                      </dd>
                    </div>
                    <div className="flex justify-between gap-3">
                      <dt className="text-muted-foreground">Payment</dt>
                      <dd className="text-right font-medium text-foreground">
                        {requiresBridgeWallet
                          ? isPrimeSupporter
                            ? "Bridge Wallet"
                            : "Prime Supporter"
                          : "Believe Points"}
                      </dd>
                    </div>
                    {!requiresBridgeWallet && selectedOrganization && (
                      <div className="flex justify-between gap-3">
                        <dt className="text-muted-foreground">Organization</dt>
                        <dd className="max-w-[60%] truncate text-right font-medium text-foreground">
                          {selectedOrganization.name}
                        </dd>
                      </div>
                    )}
                    {!requiresBridgeWallet && (
                      <div className="flex justify-between gap-3 border-t border-border/60 pt-2.5 dark:border-gray-800">
                        <dt className="text-muted-foreground">Range</dt>
                        <dd className="font-medium tabular-nums text-foreground">
                          {formatCurrency(minVal)}
                          {hasMaxLimit ? ` – ${formatCurrency(maxVal!)}` : "+"}
                        </dd>
                      </div>
                    )}
                    {!requiresBridgeWallet && isValidAmount && (
                      <div className="flex justify-between gap-3">
                        <dt className="font-medium text-foreground">Total</dt>
                        <dd className="bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text font-bold tabular-nums text-transparent">
                          {formatCurrency(totalChargedBp)} BP
                        </dd>
                      </div>
                    )}
                  </dl>
                </div>

                {hasDiscount && (
                  <div className="flex items-center gap-3 rounded-2xl border border-rose-500/25 bg-rose-500/5 px-4 py-3">
                    <Calendar className="h-4 w-4 shrink-0 text-rose-600" />
                    <p className="text-sm font-medium text-rose-700 dark:text-rose-300">
                      {brand.discount}% discount available on this brand
                    </p>
                  </div>
                )}
              </div>
            </div>
          </div>
        </div>
      </div>
    </Layout>
  )
}
