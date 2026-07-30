"use client"

import { useState } from "react"
import type { ComponentType } from "react"
import { Head, Link, useForm, usePage } from "@inertiajs/react"
import { motion } from "framer-motion"
import AppLayout from "@/layouts/app-layout"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert"
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { UnifiedLedgerCard, type UnifiedLedgerRow } from "@/components/admin/unified-ledger-card"
import { transactionTypeBadgeClass, transactionTypeDisplayLabel } from "@/lib/transaction-type-labels"
import {
  ArrowLeft,
  ArrowRightLeft,
  CalendarClock,
  CheckCircle2,
  Clock,
  Info,
  Link2,
  ScrollText,
  User,
  XCircle,
  Ban,
  AlertCircle,
  ExternalLink,
  Heart,
  Building2,
  Network,
  ChevronDown,
  FilePenLine,
} from "lucide-react"
import type { BreadcrumbItem } from "@/types"
import { cn } from "@/lib/utils"

interface StripeSnapshot {
  customer_id: string | null
  customer_dashboard_url: string | null
  payment_intent: {
    id: string
    amount_cents: number
    amount_display: string
    currency: string
    status: string
    created: string | null
    description: string | null
    livemode: boolean | null
  } | null
  checkout_session: {
    id: string
    amount_total_cents: number
    amount_total_display: string
    currency: string
    payment_status: string
    status: string | null
    created: string | null
    payment_intent_id: string | null
    customer: string | null
  } | null
  charge: {
    id: string
    amount_cents: number
    amount_display: string
    currency: string
    status: string
    paid: boolean | null
    created: string | null
  } | null
  subscription: {
    id: string
    status: string
    currency: string
    customer: string | null
    current_period_end: string | null
    cancel_at_period_end: boolean | null
    price_id: string | null
    unit_amount_cents: number | null
    unit_amount_display: string | null
  } | null
  payment_intent_dashboard_url: string | null
  identifiers_found: {
    payment_intent_ids: string[]
    session_ids: string[]
    charge_ids: string[]
    subscription_ids: string[]
  }
  fetch_error: string | null
}

interface DonationLedgerInfo {
  /** Present on new API payloads; omit = treat as main Believe donation row */
  kind?: "donation" | "care_alliance_campaign"
  missing?: boolean
  donation_id?: number
  id?: number
  status?: string
  frequency?: string
  amount_display?: string
  amount_raw?: number | string
  payment_method?: string
  stripe_reference?: string | null
  payment_reference?: string | null
  currency?: string
  organization_name?: string | null
  care_alliance_name?: string | null
  campaign_name?: string | null
  message?: string | null
  donation_date?: string | null
  donor_user_id?: number | null
  recipient_user_id?: number | null
  /** Stripe checkout: donor paid processing on top vs nonprofit absorbs fee from charge */
  donor_covers_processing_fees?: boolean
  checkout_total?: number | null
  processing_fee_estimate?: number | null
}

/** Full financial breakdown (same shape as ledger index report payload). */
interface LedgerReportRow {
  date: string
  reference: string
  source_type: string
  gross_amount: number
  stripe_fee: number
  bridge_fee: number
  biu_fee: number
  split_deduction: number
  refund_amount: number
  net_to_organization: number | null
  payout_status: string | null
  organization_id: number | null
  organization_name: string | null
  supplier_payout?: number | null
  organization_payout?: number | null
  platform_payout?: number | null
  supporter_payout?: number | null
  supplier_name?: string | null
  supplier_type?: string | null
  subtotal_amount?: number | null
  sales_tax_amount?: number | null
  shipping_amount?: number | null
}

/** Who this row is primarily about: personal user, nonprofit wallet, or Unity Impact Alliance. */
interface LedgerActorContext {
  kind: "user" | "organization" | "care_alliance"
  label: string
  detail: string | null
  organization_id: number | null
  care_alliance_id: number | null
  care_alliance_name: string | null
}

interface TransactionDetail {
  id: number
  transaction_id: string
  type: string
  status: string
  amount: number
  fee: number
  currency: string
  payment_method: string | null
  related_type: string | null
  related_id: number | string | null
  related_kind: string
  related_purpose: string
  related_display_name: string
  related_label: string
  /** polymorphic row in DB | inferred from meta JSON | nothing found */
  related_source: "polymorphic" | "meta" | "none"
  processed_at: string | null
  created_at: string
  updated_at: string
  user: { id: number; name: string; email: string } | null
  meta: Record<string, unknown> | null
  donation: DonationLedgerInfo | null
  /** True when a donation record is linked (same as ledger index). */
  donation_badge?: boolean
  donation_badge_label?: string
  donation_ledger_perspective?: string | null
  ledger_actor_context?: LedgerActorContext | null
  ledger_report?: LedgerReportRow | null
  /** BIU unified ledger row (workbook + client export shape) — admin only */
  unified_ledger?: UnifiedLedgerRow | null
  stripe: StripeSnapshot
  is_ledger_adjustment?: boolean
  can_create_adjustment?: boolean
  ledger_adjustments?: LedgerAdjustmentSummary[]
  adjustment_of?: {
    id: number
    transaction_id: string
    type: string | null
    status: string | null
    amount: number | null
    currency: string | null
    created_at: string | null
  } | null
  ledger_adjustment_detail?: LedgerAdjustmentSummary | null
}

interface LedgerAdjustmentSummary {
  id: number
  transaction_id: string
  adjustment_type: string
  amount_adjusted: number
  previous_value: number | null
  new_value: number | null
  reason: string
  notes?: string | null
  supporting_reference?: string | null
  original_transaction_id: number
  original_transaction_number: string
  adjusted_by_admin_id: number | null
  adjusted_by_admin_name: string
  adjusted_by_admin_email: string
  adjusted_at: string
  currency: string
  created_at?: string | null
}

interface Props {
  transaction: TransactionDetail
}

interface FlashProps {
  flash?: { success?: string | null; error?: string | null }
}

function formatMoney(n: number, currency: string) {
  try {
    return new Intl.NumberFormat("en-US", {
      style: "currency",
      currency: currency || "USD",
      minimumFractionDigits: 2,
    }).format(n)
  } catch {
    return `${currency} ${n.toFixed(2)}`
  }
}

/** Same rules as the ledger table “Transaction” column (icon + label). */
function transactionShowLedgerTypeDisplay(t: TransactionDetail): { label: string; className: string; icon: "arrows" | "heart" } {
  const meta = t.meta && typeof t.meta === "object" ? (t.meta as Record<string, unknown>) : {}
  const perspective = t.donation_ledger_perspective

  if (meta.ledger_role === "donor_payment" || perspective === "donor") {
    return {
      label: "Donation",
      className:
        "border-rose-500/40 bg-rose-500/[0.12] text-rose-900 shadow-sm shadow-rose-500/10 dark:text-rose-100",
      icon: "heart",
    }
  }
  if (perspective === "campaign" && t.type === "purchase") {
    return {
      label: "Campaign gift",
      className:
        "border-amber-500/40 bg-amber-500/[0.12] text-amber-950 shadow-sm shadow-amber-500/10 dark:text-amber-100",
      icon: "heart",
    }
  }

  if (t.donation_badge && t.type === "deposit") {
    if (perspective === "recipient_direct") {
      return {
        label: "Donation received",
        className:
          "border-emerald-500/45 bg-emerald-500/[0.12] text-emerald-900 shadow-sm shadow-emerald-500/10 dark:text-emerald-100",
        icon: "heart",
      }
    }
    if (perspective === "recipient_split") {
      return {
        label: "Donation received (split)",
        className:
          "border-teal-500/45 bg-teal-500/[0.12] text-teal-900 shadow-sm shadow-teal-500/10 dark:text-teal-100",
        icon: "heart",
      }
    }
    if (perspective === "alliance_fee") {
      return {
        label: "Alliance fee",
        className:
          "border-violet-500/45 bg-violet-500/[0.12] text-violet-900 shadow-sm shadow-violet-500/10 dark:text-violet-100",
        icon: "arrows",
      }
    }
  }

  return {
    label: transactionTypeDisplayLabel(t.type),
    className: transactionTypeBadgeClass(t.type),
    icon: "arrows",
  }
}

function statusBadgeClass(status: string): string {
  switch (status) {
    case "completed":
    case "deposit":
      return "border-emerald-500/40 bg-emerald-500/[0.1] text-emerald-900 dark:text-emerald-100"
    case "pending":
      return "border-amber-500/40 bg-amber-500/[0.1] text-amber-950 dark:text-amber-100"
    case "failed":
    case "rejected":
      return "border-red-500/40 bg-red-500/[0.1] text-red-900 dark:text-red-100"
    case "cancelled":
      return "border-muted-foreground/40 bg-muted/50 text-muted-foreground"
    case "withdrawal":
    case "refund":
    case "adjusted":
    case "reversed":
      return "border-sky-500/40 bg-sky-500/[0.1] text-sky-900 dark:text-sky-100"
    default:
      return "border-border/60 bg-muted/40 text-foreground"
  }
}

/** How gross vs net are interpreted on the unified ledger for Stripe Believe donations. */
function stripeDonationLedgerExplanation(d: DonationLedgerInfo | null): string | null {
  if (!d || d.missing || d.kind === "care_alliance_campaign") return null
  if (d.payment_method !== "stripe") return null
  if (d.donor_covers_processing_fees) {
    return "Donor covered payment provider fee: Gross is the total amount paid. Net is that payment minus the payment provider fee (and any other fees listed)—what the nonprofit receives. The fee row is the portion charged by Stripe or PayPal."
  }
  return "Nonprofit absorbs payment provider fee: NET is the donation amount minus the payment provider fee (and any other fees listed). Org payout matches that net settlement."
}

function ledgerActorContextIcon(kind: LedgerActorContext["kind"]): ComponentType<{ className?: string }> {
  switch (kind) {
    case "organization":
      return Building2
    case "care_alliance":
      return Network
    default:
      return User
  }
}

function ledgerActorBadgeClass(kind: LedgerActorContext["kind"]): string {
  switch (kind) {
    case "organization":
      return "border-sky-500/45 bg-sky-500/[0.1] text-sky-900 dark:text-sky-100"
    case "care_alliance":
      return "border-violet-500/45 bg-violet-500/[0.1] text-violet-900 dark:text-violet-100"
    default:
      return "border-amber-500/45 bg-amber-500/[0.1] text-amber-950 dark:text-amber-100"
  }
}

function donationPerspectiveBadgeClass(perspective: string | null | undefined): string {
  switch (perspective) {
    case "recipient_direct":
      return "border-emerald-500/45 bg-emerald-500/[0.1] text-emerald-900 dark:text-emerald-100"
    case "recipient_split":
      return "border-teal-500/45 bg-teal-500/[0.1] text-teal-900 dark:text-teal-100"
    case "alliance_fee":
      return "border-violet-500/45 bg-violet-500/[0.1] text-violet-900 dark:text-violet-100"
    case "campaign":
      return "border-amber-500/45 bg-amber-500/[0.1] text-amber-950 dark:text-amber-100"
    case "donor":
    default:
      return "border-rose-500/45 bg-rose-500/[0.1] text-rose-900 dark:text-rose-100"
  }
}

function relatedSourceBadge(source: TransactionDetail["related_source"]): { label: string; className: string } {
  switch (source) {
    case "polymorphic":
      return {
        label: "Database link",
        className: "border-primary/35 bg-primary/[0.08] text-primary",
      }
    case "meta":
      return {
        label: "From metadata",
        className: "border-violet-500/40 bg-violet-500/[0.1] text-violet-900 dark:text-violet-100",
      }
    case "none":
    default:
      return {
        label: "No polymorphic link",
        className: "border-border/50 bg-muted/35 text-muted-foreground",
      }
  }
}

function statusIcon(status: string) {
  switch (status) {
    case "completed":
    case "deposit":
      return <CheckCircle2 className="h-4 w-4 shrink-0" />
    case "pending":
      return <Clock className="h-4 w-4 shrink-0" />
    case "failed":
    case "rejected":
      return <XCircle className="h-4 w-4 shrink-0" />
    case "cancelled":
      return <Ban className="h-4 w-4 shrink-0" />
    default:
      return <AlertCircle className="h-4 w-4 shrink-0" />
  }
}

function stripePanelOpen(s: TransactionDetail["stripe"]): boolean {
  return !!(
    s.customer_id ||
    s.payment_intent ||
    s.subscription ||
    s.checkout_session ||
    s.charge ||
    s.identifiers_found.payment_intent_ids.length > 0 ||
    s.identifiers_found.session_ids.length > 0 ||
    s.identifiers_found.charge_ids.length > 0 ||
    s.identifiers_found.subscription_ids.length > 0
  )
}

export default function TransactionShow({ transaction: t }: Props) {
  const [adjustOpen, setAdjustOpen] = useState(false)
  const { flash } = usePage().props as FlashProps

  const breadcrumbs: BreadcrumbItem[] = [
    { title: "Dashboard", href: "/dashboard" },
    { title: "Transaction ledger", href: route("admin.transactions.ledger") },
    { title: t.transaction_id, href: "#" },
  ]

  const txTypeDisplay = transactionShowLedgerTypeDisplay(t)

  const metaJson =
    t.meta && Object.keys(t.meta).length > 0 ? JSON.stringify(t.meta, null, 2) : null

  const relatedSource = relatedSourceBadge(t.related_source)

  const actorCtx = t.ledger_actor_context
  const ActorHeaderIcon = actorCtx ? ledgerActorContextIcon(actorCtx.kind) : User
  const donationStripeLedgerHint = stripeDonationLedgerExplanation(t.donation)

  const adjustForm = useForm({
    adjustment_type: "adjustment",
    amount_adjusted: String((-Number(t.amount) || 0).toFixed(2)),
    previous_value: String(Number(t.amount).toFixed(2)),
    new_value: "0.00",
    reason: "",
    notes: "",
    supporting_reference: "",
    original_status: "adjusted",
  })

  const openAdjustmentDialog = () => {
    adjustForm.setData({
      adjustment_type: "adjustment",
      amount_adjusted: String((-Number(t.amount) || 0).toFixed(2)),
      previous_value: String(Number(t.amount).toFixed(2)),
      new_value: "0.00",
      reason: "",
      notes: "",
      supporting_reference: "",
      original_status: "adjusted",
    })
    adjustForm.clearErrors()
    setAdjustOpen(true)
  }

  const onAdjustmentTypeChange = (next: string) => {
    if (next === "reversal") {
      adjustForm.setData({
        adjustment_type: next,
        amount_adjusted: String((-Number(t.amount) || 0).toFixed(2)),
        previous_value: String(Number(t.amount).toFixed(2)),
        new_value: "0.00",
        reason: adjustForm.data.reason,
        notes: adjustForm.data.notes,
        supporting_reference: adjustForm.data.supporting_reference,
        original_status: "reversed",
      })
      return
    }
    adjustForm.setData({
      ...adjustForm.data,
      adjustment_type: next,
      original_status: next === "correction" ? "adjusted" : adjustForm.data.original_status,
    })
  }

  const submitAdjustment = (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    adjustForm.post(route("admin.transactions.adjustments.store", t.id), {
      preserveScroll: true,
      onSuccess: () => setAdjustOpen(false),
    })
  }

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={`Transaction ${t.transaction_id}`} />

      <div className="relative mx-4 my-6 space-y-8 overflow-hidden sm:mx-8 lg:mx-10">
        <div className="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-primary/[0.09] via-transparent to-transparent" />

        <motion.div
          initial={{ opacity: 0, y: -6 }}
          animate={{ opacity: 1, y: 0 }}
          className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
          <div className="space-y-3">
            <Link
              href={route("admin.transactions.ledger")}
              className="inline-flex items-center gap-2 text-sm font-medium text-primary/90 transition-colors hover:text-primary hover:underline"
            >
              <ArrowLeft className="h-4 w-4" />
              Back to ledger
            </Link>
            <h1 className="font-mono text-2xl font-bold tracking-tight text-foreground sm:text-3xl">{t.transaction_id}</h1>
            <div className="flex flex-wrap items-center gap-2">
              <Tooltip delayDuration={350}>
                <TooltipTrigger asChild>
                  <span
                    className={cn(
                      "inline-flex max-w-full min-w-0 cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-1.5 text-xs font-semibold uppercase leading-none tracking-wide sm:text-sm",
                      txTypeDisplay.className,
                    )}
                  >
                    {txTypeDisplay.icon === "heart" ? (
                      <Heart className="h-3.5 w-3.5 shrink-0 opacity-90 sm:h-4 sm:w-4" aria-hidden />
                    ) : (
                      <ArrowRightLeft className="h-3.5 w-3.5 shrink-0 opacity-80 sm:h-4 sm:w-4" aria-hidden />
                    )}
                    <span className="min-w-0 max-w-[min(100%,14rem)] truncate sm:max-w-[18rem]">{txTypeDisplay.label}</span>
                  </span>
                </TooltipTrigger>
                <TooltipContent
                  side="top"
                  sideOffset={8}
                  className="max-w-xs px-3 py-2 text-left text-xs font-medium normal-case leading-snug tracking-normal text-popover-foreground"
                >
                  {t.donation_badge_label ? (
                    <>
                      <span className="font-semibold uppercase tracking-wide">{txTypeDisplay.label}</span>
                      <span className="mt-1 block text-[11px] font-normal text-muted-foreground">{t.donation_badge_label}</span>
                    </>
                  ) : (
                    txTypeDisplay.label
                  )}
                </TooltipContent>
              </Tooltip>
              <span
                className="inline-flex items-center rounded-md border border-border/50 bg-muted/30 px-2 py-1 font-mono text-sm font-semibold tabular-nums text-foreground"
                title={`Transaction id ${t.id}`}
              >
                #{t.id}
              </span>
              <Badge variant="outline" className={cn("capitalize leading-none", statusBadgeClass(t.status))}>
                <span className="inline-flex items-center gap-1.5">
                  {statusIcon(t.status)}
                  <span className="leading-none">{t.status}</span>
                </span>
              </Badge>
              {actorCtx && (
                <Badge
                  variant="outline"
                  className={cn(
                    "gap-1.5 font-sans font-semibold normal-case tracking-normal",
                    ledgerActorBadgeClass(actorCtx.kind),
                  )}
                >
                  <ActorHeaderIcon className="h-3.5 w-3.5 shrink-0" aria-hidden />
                  {actorCtx.label}
                </Badge>
              )}
            </div>
            <p className="max-w-2xl text-sm text-muted-foreground">
              Unified finance summary first; expand sections below only when you need Stripe, donation detail, or raw metadata.
            </p>
          </div>
          <div className="flex flex-wrap gap-2 sm:justify-end">
            <Button variant="outline" className="rounded-full border-border/55 bg-background/60 hover:bg-muted/40" asChild>
              <Link href={route("admin.transactions.ledger")}>Close</Link>
            </Button>
            {t.can_create_adjustment !== false && (
              <Button
                variant="default"
                className="rounded-full gap-2 bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-600/90 hover:to-blue-600/90"
                onClick={openAdjustmentDialog}
              >
                <FilePenLine className="h-4 w-4" />
                Create adjustment
              </Button>
            )}
          </div>
        </motion.div>

        {(flash?.success || flash?.error) && (
          <Alert
            className={cn(
              flash.error
                ? "border-red-500/30 bg-red-500/[0.08]"
                : "border-emerald-500/30 bg-emerald-500/[0.08]",
            )}
          >
            <Info className="h-4 w-4" />
            <AlertTitle>{flash.error ? "Could not save" : "Saved"}</AlertTitle>
            <AlertDescription>{flash.error || flash.success}</AlertDescription>
          </Alert>
        )}

        {t.adjustment_of && t.adjustment_of.id > 0 && (
          <Card className="border-sky-500/25 bg-sky-500/[0.05]">
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-2 text-base">
                <Link2 className="h-4 w-4 text-sky-700 dark:text-sky-300" />
                Linked original transaction
              </CardTitle>
              <CardDescription>
                This row is an Adjustment / Reversal / Correction. The original accounting record remains unchanged in the ledger.
              </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-wrap items-center gap-3 text-sm">
              <Link
                href={route("admin.transactions.show", t.adjustment_of.id)}
                className="font-mono font-semibold text-primary hover:underline"
              >
                {t.adjustment_of.transaction_id || `#${t.adjustment_of.id}`}
              </Link>
              {t.ledger_adjustment_detail && (
                <span className="text-muted-foreground">
                  {t.ledger_adjustment_detail.adjustment_type} · by{" "}
                  {t.ledger_adjustment_detail.adjusted_by_admin_name || "admin"} ·{" "}
                  {t.ledger_adjustment_detail.adjusted_at
                    ? new Date(t.ledger_adjustment_detail.adjusted_at).toLocaleString()
                    : "—"}
                </span>
              )}
            </CardContent>
          </Card>
        )}

        {t.ledger_adjustments && t.ledger_adjustments.length > 0 && (
          <Card className="border-border/70">
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-2 text-base">
                <FilePenLine className="h-4 w-4 text-primary/80" />
                Adjustment history
              </CardTitle>
              <CardDescription>
                Linked corrections remain visible with the original transaction for a complete audit trail.
              </CardDescription>
            </CardHeader>
            <CardContent className="overflow-x-auto">
              <table className="w-full min-w-[48rem] text-left text-sm">
                <thead>
                  <tr className="border-b border-border/60 text-xs uppercase tracking-wide text-muted-foreground">
                    <th className="px-2 py-2 font-medium">Adjustment #</th>
                    <th className="px-2 py-2 font-medium">Type</th>
                    <th className="px-2 py-2 font-medium text-right">Amount</th>
                    <th className="px-2 py-2 font-medium text-right">Previous</th>
                    <th className="px-2 py-2 font-medium text-right">New</th>
                    <th className="px-2 py-2 font-medium">Reason</th>
                    <th className="px-2 py-2 font-medium">Admin</th>
                    <th className="px-2 py-2 font-medium">When</th>
                  </tr>
                </thead>
                <tbody>
                  {t.ledger_adjustments.map((row) => (
                    <tr key={row.id} className="border-b border-border/40">
                      <td className="px-2 py-2.5">
                        <Link
                          href={route("admin.transactions.show", row.id)}
                          className="font-mono font-semibold text-primary hover:underline"
                        >
                          {row.transaction_id}
                        </Link>
                      </td>
                      <td className="px-2 py-2.5 capitalize">{row.adjustment_type}</td>
                      <td className="px-2 py-2.5 text-right tabular-nums">
                        {formatMoney(row.amount_adjusted, row.currency || t.currency || "USD")}
                      </td>
                      <td className="px-2 py-2.5 text-right tabular-nums text-muted-foreground">
                        {row.previous_value != null
                          ? formatMoney(row.previous_value, row.currency || t.currency || "USD")
                          : "—"}
                      </td>
                      <td className="px-2 py-2.5 text-right tabular-nums text-muted-foreground">
                        {row.new_value != null
                          ? formatMoney(row.new_value, row.currency || t.currency || "USD")
                          : "—"}
                      </td>
                      <td className="max-w-[14rem] truncate px-2 py-2.5" title={row.reason}>
                        {row.reason || "—"}
                      </td>
                      <td className="px-2 py-2.5">
                        <div className="leading-tight">
                          <p className="font-medium">{row.adjusted_by_admin_name || "—"}</p>
                          {row.adjusted_by_admin_email && (
                            <p className="text-xs text-muted-foreground">{row.adjusted_by_admin_email}</p>
                          )}
                        </div>
                      </td>
                      <td className="whitespace-nowrap px-2 py-2.5 text-muted-foreground">
                        {row.adjusted_at ? new Date(row.adjusted_at).toLocaleString() : "—"}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </CardContent>
          </Card>
        )}

        {t.unified_ledger && (
          <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.04, duration: 0.35 }}
            className="space-y-3"
          >
            <div className="flex flex-col gap-1">
              <div className="flex items-center gap-2 text-muted-foreground">
                <ScrollText className="h-4 w-4 shrink-0 text-primary/80" aria-hidden />
                <h2 className="text-sm font-semibold uppercase tracking-wide">Unified finance</h2>
              </div>
              <p className="text-xs text-muted-foreground">
                Final unified ledger row — same columns and supplier labels as the admin ledger table.
              </p>
            </div>
            <UnifiedLedgerCard data={t.unified_ledger} />
            {donationStripeLedgerHint && (
              <Alert className="border-border/60 bg-muted/30">
                <Info className="h-4 w-4" />
                <AlertTitle className="text-sm">Stripe donation · Gross and net</AlertTitle>
                <AlertDescription className="text-sm text-muted-foreground">{donationStripeLedgerHint}</AlertDescription>
              </Alert>
            )}
          </motion.div>
        )}

        <motion.div
          initial={{ opacity: 0, y: 10 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.05 }}
        >
          <Card className="border-border/50 bg-card/40 shadow-md ring-1 ring-border/30">
            <CardHeader className="pb-3">
              <CardTitle className="flex items-center gap-2 text-lg">
                <Link2 className="h-5 w-5 text-primary/90" />
                Record details
              </CardTitle>
              <CardDescription>Type, amounts, links, and user—fees are in the unified block above.</CardDescription>
            </CardHeader>
            <CardContent className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
              <div className="space-y-2">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Type &amp; status</p>
                <div className="flex flex-wrap items-center gap-2">
                  <Tooltip delayDuration={350}>
                    <TooltipTrigger asChild>
                      <span
                        className={cn(
                          "inline-flex max-w-full min-w-0 cursor-pointer items-center gap-1.5 rounded-full border px-2.5 py-1.5 text-xs font-semibold uppercase leading-none tracking-wide sm:text-sm",
                          txTypeDisplay.className,
                        )}
                      >
                        {txTypeDisplay.icon === "heart" ? (
                          <Heart className="h-3.5 w-3.5 shrink-0 opacity-90 sm:h-4 sm:w-4" aria-hidden />
                        ) : (
                          <ArrowRightLeft className="h-3.5 w-3.5 shrink-0 opacity-80 sm:h-4 sm:w-4" aria-hidden />
                        )}
                        <span className="min-w-0 max-w-[min(100%,14rem)] truncate sm:max-w-[18rem]">{txTypeDisplay.label}</span>
                      </span>
                    </TooltipTrigger>
                    <TooltipContent
                      side="top"
                      sideOffset={8}
                      className="max-w-xs px-3 py-2 text-left text-xs font-medium normal-case leading-snug tracking-normal text-popover-foreground"
                    >
                      {t.donation_badge_label ? (
                        <>
                          <span className="font-semibold uppercase tracking-wide">{txTypeDisplay.label}</span>
                          <span className="mt-1 block text-[11px] font-normal text-muted-foreground">{t.donation_badge_label}</span>
                        </>
                      ) : (
                        txTypeDisplay.label
                      )}
                    </TooltipContent>
                  </Tooltip>
                  <span className="rounded-md border border-border/50 bg-muted/25 px-2 py-0.5 font-mono text-xs font-semibold tabular-nums text-foreground">
                    #{t.id}
                  </span>
                  <Badge variant="outline" className={cn("capitalize leading-none", statusBadgeClass(t.status))}>
                    <span className="inline-flex items-center gap-1.5">
                      {statusIcon(t.status)}
                      <span className="leading-none">{t.status}</span>
                    </span>
                  </Badge>
                </div>
              </div>
              <div className="space-y-2">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Row amount / fee</p>
                <p className="text-sm tabular-nums text-foreground">
                  <span className="font-semibold">{formatMoney(t.amount, t.currency)}</span>
                  <span className="text-muted-foreground"> · fee </span>
                  {formatMoney(t.fee, t.currency)}
                </p>
              </div>
              <div className="space-y-2">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Payment method</p>
                {t.payment_method ? (
                  <Badge variant="outline" className="w-fit capitalize">
                    {t.payment_method.replace(/_/g, " ")}
                  </Badge>
                ) : (
                  <span className="text-sm text-muted-foreground">—</span>
                )}
              </div>

              {actorCtx && (
                <div className="space-y-2 sm:col-span-2">
                  <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Ledger context</p>
                  <div className="flex flex-wrap items-center gap-2 text-sm">
                    <Badge
                      variant="outline"
                      className={cn(
                        "gap-1.5 font-sans font-semibold normal-case tracking-normal",
                        ledgerActorBadgeClass(actorCtx.kind),
                      )}
                    >
                      <ActorHeaderIcon className="h-3.5 w-3.5 shrink-0" aria-hidden />
                      {actorCtx.label}
                    </Badge>
                    {actorCtx.detail && <span className="text-muted-foreground">{actorCtx.detail}</span>}
                    {actorCtx.organization_id != null && (
                      <span className="font-mono text-xs text-muted-foreground">org #{actorCtx.organization_id}</span>
                    )}
                    {actorCtx.care_alliance_id != null && (
                      <span className="font-mono text-xs text-muted-foreground">alliance #{actorCtx.care_alliance_id}</span>
                    )}
                  </div>
                </div>
              )}

              <div className="space-y-2 sm:col-span-2 lg:col-span-3">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Linked user</p>
                {t.user ? (
                  <div className="text-sm">
                    <span className="font-medium text-foreground">{t.user.name}</span>
                    <span className="text-muted-foreground"> · </span>
                    <span className="text-muted-foreground">{t.user.email}</span>
                    <Badge variant="secondary" className="ml-2 font-mono text-xs">
                      #{t.user.id}
                    </Badge>
                  </div>
                ) : (
                  <span className="text-sm text-muted-foreground">Unassigned</span>
                )}
              </div>

              <div className="space-y-2 lg:col-span-2">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Related</p>
                <div className="flex flex-wrap items-center gap-2 text-sm">
                  <span className="text-foreground">{t.related_kind}</span>
                  <Badge variant="outline" className={cn("text-xs uppercase", relatedSource.className)}>
                    {relatedSource.label}
                  </Badge>
                </div>
                <p className="mt-1 text-sm text-foreground">
                  {t.related_display_name !== "—" ? t.related_display_name : "—"}
                </p>
                <p className="mt-1 line-clamp-3 text-xs text-muted-foreground">{t.related_purpose}</p>
              </div>

              <div className="space-y-2">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Polymorphic</p>
                <p className="break-all font-mono text-xs text-foreground">
                  {t.related_type
                    ? `${t.related_type}${t.related_id != null && t.related_id !== "" ? ` #${t.related_id}` : ""}`
                    : "—"}
                </p>
              </div>

              <div className="space-y-2 sm:col-span-2 lg:col-span-3">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Reference (transaction_id)</p>
                <p className="break-all font-mono text-sm text-foreground">{t.transaction_id}</p>
              </div>
            </CardContent>
          </Card>
        </motion.div>

        {t.donation && (
          <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.105 }}>
            <Card className="border-border/50 bg-card/40 shadow-md ring-1 ring-border/30">
              <CardHeader>
                <CardTitle className="flex flex-wrap items-center gap-2 text-lg">
                  <Heart className="h-5 w-5 text-primary/90" />
                  {t.donation.kind === "care_alliance_campaign" ? "Campaign donation" : "Donation (Believe)"}
                  {t.donation_badge_label && (
                    <Badge
                      variant="outline"
                      className={cn(
                        "border font-sans font-semibold normal-case tracking-normal",
                        donationPerspectiveBadgeClass(t.donation_ledger_perspective),
                      )}
                    >
                      {t.donation_badge_label}
                    </Badge>
                  )}
                  <Badge variant="secondary" className="font-mono text-[10px] font-normal uppercase tracking-wide">
                    {t.donation.kind === "care_alliance_campaign"
                      ? "care_alliance_donations"
                      : "donations"}
                  </Badge>
                </CardTitle>
                <CardDescription>
                  {t.donation.kind === "care_alliance_campaign"
                    ? "Unity Impact Alliance campaign donation record."
                    : "Believe donation record linked to this ledger row."}
                </CardDescription>
              </CardHeader>
              <CardContent>
                {t.donation.missing ? (
                  <p className="text-sm text-muted-foreground">
                    Donation id <span className="font-mono text-foreground">#{t.donation.donation_id}</span> was referenced but the
                    row no longer exists.
                  </p>
                ) : t.donation.kind === "care_alliance_campaign" ? (
                  <div className="space-y-4 rounded-xl border border-border/50 bg-muted/20 p-4">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-2xl font-bold tabular-nums text-foreground">
                        {t.donation.currency ?? "USD"} {t.donation.amount_display}
                      </span>
                      <Badge variant="outline" className="capitalize">
                        {t.donation.status ?? "—"}
                      </Badge>
                    </div>
                    {t.donation.campaign_name && (
                      <p className="text-sm text-foreground">
                        <span className="text-muted-foreground">Campaign: </span>
                        {t.donation.campaign_name}
                      </p>
                    )}
                    {t.donation.care_alliance_name && (
                      <p className="text-sm text-foreground">
                        <span className="text-muted-foreground">Unity Impact Alliance: </span>
                        {t.donation.care_alliance_name}
                      </p>
                    )}
                    {t.donation.payment_reference && (
                      <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                          Payment reference (Stripe)
                        </p>
                        <p className="mt-1 break-all font-mono text-sm text-foreground">{t.donation.payment_reference}</p>
                      </div>
                    )}
                  </div>
                ) : (
                  <div className="space-y-4 rounded-xl border border-border/50 bg-muted/20 p-4">
                    <div className="flex flex-wrap items-center gap-2">
                      <span className="text-2xl font-bold tabular-nums text-foreground">${t.donation.amount_display}</span>
                      <Badge variant="outline" className="capitalize">
                        {t.donation.status ?? "—"}
                      </Badge>
                      {t.donation.frequency && (
                        <Badge variant="outline" className="capitalize">
                          {t.donation.frequency.replace(/-/g, " ")}
                        </Badge>
                      )}
                    </div>
                    {t.donation.organization_name && (
                      <p className="text-sm text-foreground">
                        <span className="text-muted-foreground">Recipient: </span>
                        {t.donation.organization_name}
                      </p>
                    )}
                    {t.donation.care_alliance_name && (
                      <p className="text-sm text-foreground">
                        <span className="text-muted-foreground">Unity Impact Alliance: </span>
                        {t.donation.care_alliance_name}
                      </p>
                    )}
                    {(t.donation.stripe_reference || t.donation.payment_reference) && (
                      <div>
                        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                          Stripe reference on donation
                        </p>
                        <p className="mt-1 break-all font-mono text-sm text-foreground">
                          {t.donation.stripe_reference ?? t.donation.payment_reference}
                        </p>
                      </div>
                    )}
                    {t.donation.message && (
                      <p className="text-sm text-muted-foreground">
                        <span className="font-medium text-foreground">Donor message: </span>
                        {t.donation.message}
                      </p>
                    )}
                    {t.donation.donation_date && (
                      <p className="text-xs text-muted-foreground">
                        Donation date:{" "}
                        {new Date(t.donation.donation_date).toLocaleString(undefined, {
                          dateStyle: "medium",
                          timeStyle: "short",
                        })}
                      </p>
                    )}
                  </div>
                )}
              </CardContent>
            </Card>
          </motion.div>
        )}

        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.11 }}>
          <Card className="border-border/50 bg-card/40 shadow-md ring-1 ring-border/30">
            <CardHeader className="pb-2">
              <CardTitle className="flex flex-wrap items-center gap-2 text-lg">
                <ExternalLink className="h-5 w-5 text-primary/90" />
                Stripe
                <Badge variant="secondary" className="font-mono text-xs font-normal uppercase tracking-wide">
                  API
                </Badge>
              </CardTitle>
              <CardDescription>Live objects from Cashier when ids exist on this row or the linked donation.</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              {t.stripe.fetch_error && (
                <Alert variant="destructive" className="border-destructive/40">
                  <AlertCircle className="h-4 w-4" />
                  <AlertTitle>Stripe API</AlertTitle>
                  <AlertDescription className="text-sm">{t.stripe.fetch_error}</AlertDescription>
                </Alert>
              )}

              {stripePanelOpen(t.stripe) ? (
                <Collapsible defaultOpen className="rounded-xl border border-border/50 bg-muted/10">
                  <CollapsibleTrigger className="flex w-full items-center gap-2 px-4 py-3 text-left text-sm font-medium text-foreground hover:bg-muted/30 [&[data-state=open]_svg]:rotate-180">
                    <ChevronDown className="h-4 w-4 shrink-0 text-muted-foreground transition-transform" />
                    PaymentIntent, customer, subscription, checkout…
                  </CollapsibleTrigger>
                  <CollapsibleContent className="space-y-4 border-t border-border/40 p-4">

              {t.stripe.customer_id && (
                <div className="rounded-xl border border-border/50 bg-muted/20 p-4">
                  <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Cashier customer</p>
                  <p className="mt-1 font-mono text-sm text-foreground">{t.stripe.customer_id}</p>
                  {t.stripe.customer_dashboard_url && (
                    <a
                      href={t.stripe.customer_dashboard_url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="mt-2 inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                    >
                      Open in Stripe Dashboard
                      <ExternalLink className="h-3.5 w-3.5" />
                    </a>
                  )}
                </div>
              )}

              {t.stripe.payment_intent && (
                <div className="rounded-xl border border-border/50 bg-muted/20 p-4">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">PaymentIntent</p>
                      <p className="mt-1 font-mono text-sm text-foreground">{t.stripe.payment_intent.id}</p>
                    </div>
                    <Badge variant="outline" className="capitalize">
                      {t.stripe.payment_intent.status}
                    </Badge>
                  </div>
                  <p className="mt-3 text-2xl font-bold tabular-nums text-foreground">
                    {t.stripe.payment_intent.currency}{" "}
                    {t.stripe.payment_intent.amount_display}
                  </p>
                  {t.stripe.payment_intent.description && (
                    <p className="mt-2 text-sm text-muted-foreground">{t.stripe.payment_intent.description}</p>
                  )}
                  {t.stripe.payment_intent.created && (
                    <p className="mt-1 text-xs text-muted-foreground">
                      {new Date(t.stripe.payment_intent.created).toLocaleString(undefined, {
                        dateStyle: "medium",
                        timeStyle: "short",
                      })}
                      {t.stripe.payment_intent.livemode != null && (
                        <span className="ml-2">
                          · {t.stripe.payment_intent.livemode ? "Live" : "Test"}
                        </span>
                      )}
                    </p>
                  )}
                  {t.stripe.payment_intent_dashboard_url && (
                    <p className="mt-3 text-xs text-muted-foreground">
                      <a
                        href={t.stripe.payment_intent_dashboard_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1 font-medium text-primary/80 hover:text-primary hover:underline"
                      >
                        Open in Stripe Dashboard
                        <ExternalLink className="h-3 w-3" />
                      </a>{" "}
                      (optional)
                    </p>
                  )}
                </div>
              )}

              {t.stripe.subscription && (
                <div className="rounded-xl border border-border/50 bg-muted/20 p-4">
                  <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                      <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Stripe subscription</p>
                      <p className="mt-1 font-mono text-sm text-foreground">{t.stripe.subscription.id}</p>
                    </div>
                    <Badge variant="outline" className="capitalize">
                      {t.stripe.subscription.status}
                    </Badge>
                  </div>
                  {t.stripe.subscription.unit_amount_display != null && (
                    <p className="mt-3 text-xl font-bold tabular-nums text-foreground">
                      {t.stripe.subscription.currency} {t.stripe.subscription.unit_amount_display}
                      <span className="text-sm font-normal text-muted-foreground"> / period</span>
                    </p>
                  )}
                  {t.stripe.subscription.current_period_end && (
                    <p className="mt-2 text-xs text-muted-foreground">
                      Current period ends{" "}
                      {new Date(t.stripe.subscription.current_period_end).toLocaleString(undefined, {
                        dateStyle: "medium",
                        timeStyle: "short",
                      })}
                    </p>
                  )}
                  {t.stripe.subscription.price_id && (
                    <p className="mt-1 font-mono text-[11px] text-muted-foreground">Price {t.stripe.subscription.price_id}</p>
                  )}
                </div>
              )}

              {t.stripe.checkout_session && !t.stripe.payment_intent && (
                <div className="rounded-xl border border-border/50 bg-muted/20 p-4">
                  <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Checkout session</p>
                  <p className="mt-1 font-mono text-sm text-foreground">{t.stripe.checkout_session.id}</p>
                  <p className="mt-2 text-lg font-semibold tabular-nums text-foreground">
                    {t.stripe.checkout_session.currency} {t.stripe.checkout_session.amount_total_display}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Payment {t.stripe.checkout_session.payment_status}
                    {t.stripe.checkout_session.status ? ` · ${t.stripe.checkout_session.status}` : ""}
                  </p>
                </div>
              )}

              {t.stripe.charge && (
                <div className="rounded-xl border border-border/50 bg-muted/20 p-4">
                  <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Charge</p>
                  <p className="mt-1 font-mono text-sm text-foreground">{t.stripe.charge.id}</p>
                  <p className="mt-2 text-lg font-semibold tabular-nums text-foreground">
                    {t.stripe.charge.currency} {t.stripe.charge.amount_display}
                  </p>
                  <p className="mt-1 text-xs capitalize text-muted-foreground">
                    {t.stripe.charge.status}
                    {t.stripe.charge.paid != null ? ` · paid: ${t.stripe.charge.paid ? "yes" : "no"}` : ""}
                  </p>
                </div>
              )}

              {(t.stripe.identifiers_found.payment_intent_ids.length > 0 ||
                t.stripe.identifiers_found.session_ids.length > 0 ||
                t.stripe.identifiers_found.charge_ids.length > 0 ||
                t.stripe.identifiers_found.subscription_ids.length > 0) &&
                !t.stripe.payment_intent &&
                !t.stripe.subscription &&
                !t.stripe.fetch_error && (
                  <p className="text-sm text-muted-foreground">
                    Stripe ids were found but not loaded—check API keys and account.
                  </p>
                )}

              {!t.stripe.customer_id &&
                !t.stripe.payment_intent &&
                !t.stripe.checkout_session &&
                !t.stripe.charge &&
                !t.stripe.subscription &&
                !t.stripe.fetch_error &&
                t.stripe.identifiers_found.payment_intent_ids.length === 0 &&
                t.stripe.identifiers_found.session_ids.length === 0 &&
                t.stripe.identifiers_found.charge_ids.length === 0 &&
                t.stripe.identifiers_found.subscription_ids.length === 0 && (
                  <p className="text-sm text-muted-foreground">
                    No Stripe objects loaded. Check donation above or raw metadata for ids.
                  </p>
                )}
                  </CollapsibleContent>
                </Collapsible>
              ) : !t.stripe.fetch_error ? (
                <p className="text-sm text-muted-foreground">
                  No Stripe ids on this row. Use donation or metadata if you need gateway details.
                </p>
              ) : null}
            </CardContent>
          </Card>
        </motion.div>

        <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.12 }}>
          <Card className="border-border/50 bg-card/40 shadow-md ring-1 ring-border/30">
            <CardHeader className="pb-2">
              <CardTitle className="flex items-center gap-2 text-lg">
                <CalendarClock className="h-5 w-5 text-primary/90" />
                Timestamps
              </CardTitle>
            </CardHeader>
            <CardContent className="grid gap-4 md:grid-cols-3">
              <div className="rounded-lg border border-border/50 bg-muted/10 p-3">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Created</p>
                <p className="mt-1 text-sm text-foreground">
                  {new Date(t.created_at).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" })}
                </p>
              </div>
              <div className="rounded-lg border border-border/50 bg-muted/10 p-3">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Processed</p>
                <p className="mt-1 text-sm text-foreground">
                  {t.processed_at
                    ? new Date(t.processed_at).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" })
                    : "—"}
                </p>
              </div>
              <div className="rounded-lg border border-border/50 bg-muted/10 p-3">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">Updated</p>
                <p className="mt-1 text-sm text-foreground">
                  {new Date(t.updated_at).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" })}
                </p>
              </div>
            </CardContent>
          </Card>
        </motion.div>

        {metaJson && (
          <motion.div initial={{ opacity: 0, y: 10 }} animate={{ opacity: 1, y: 0 }} transition={{ delay: 0.14 }}>
            <Card className="border-border/50 bg-card/40 shadow-md ring-1 ring-border/30">
              <CardHeader className="pb-2">
                <CardTitle className="flex flex-wrap items-center gap-2 text-lg">
                  Metadata
                  <Badge variant="secondary" className="font-mono text-xs font-normal uppercase tracking-wide">
                    JSON
                  </Badge>
                </CardTitle>
                <CardDescription>Structured extras from checkout or webhooks—expand only when debugging.</CardDescription>
              </CardHeader>
              <CardContent>
                <Collapsible className="rounded-xl border border-border/50 bg-muted/10">
                  <CollapsibleTrigger className="flex w-full items-center gap-2 px-4 py-3 text-left text-sm font-medium text-foreground hover:bg-muted/30 [&[data-state=open]_svg]:rotate-180">
                    <ChevronDown className="h-4 w-4 shrink-0 text-muted-foreground transition-transform" />
                    Show raw JSON
                  </CollapsibleTrigger>
                  <CollapsibleContent>
                    <pre className="max-h-96 overflow-auto border-t border-border/40 bg-muted/25 p-4 font-mono text-xs leading-relaxed text-foreground/95 dark:bg-muted/20">
                      {metaJson}
                    </pre>
                  </CollapsibleContent>
                </Collapsible>
              </CardContent>
            </Card>
          </motion.div>
        )}

        <Alert className="border-sky-500/30 bg-sky-500/[0.07] dark:border-sky-400/25 dark:bg-sky-500/[0.07]">
          <Info className="h-4 w-4 text-sky-700/90 dark:text-sky-300/95" />
          <AlertTitle className="text-sky-950/95 dark:text-sky-50/95">Permanent accounting records</AlertTitle>
          <AlertDescription className="text-sky-950/85 dark:text-sky-50/85">
            Ledger rows are never deleted. If a transaction was entered incorrectly or needs to be corrected, create an{" "}
            <span className="font-medium text-foreground">Adjustment</span>,{" "}
            <span className="font-medium text-foreground">Reversal</span>, or{" "}
            <span className="font-medium text-foreground">Correction</span>. The original record stays visible and linked.
            Gateway refunds (Stripe / PayPal) still require the usual payment tools when money must move.
          </AlertDescription>
        </Alert>
      </div>

      <Dialog open={adjustOpen} onOpenChange={setAdjustOpen}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
          <DialogHeader>
            <DialogTitle>Create ledger adjustment</DialogTitle>
            <DialogDescription>
              The original transaction <span className="font-mono font-medium text-foreground">{t.transaction_id}</span> stays
              unchanged. A new linked record will be added to Transaction History.
            </DialogDescription>
          </DialogHeader>
          <form onSubmit={submitAdjustment} className="space-y-4">
            <div className="space-y-1.5">
              <Label htmlFor="adjustment_type">Adjustment type</Label>
              <select
                id="adjustment_type"
                value={adjustForm.data.adjustment_type}
                onChange={(e) => onAdjustmentTypeChange(e.target.value)}
                className="flex h-10 w-full rounded-md border border-border/60 bg-background px-3 text-sm"
              >
                <option value="adjustment">Adjustment</option>
                <option value="reversal">Reversal</option>
                <option value="correction">Correction</option>
              </select>
              {adjustForm.errors.adjustment_type && (
                <p className="text-xs text-destructive">{adjustForm.errors.adjustment_type}</p>
              )}
            </div>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <div className="space-y-1.5">
                <Label htmlFor="previous_value">Previous value</Label>
                <Input
                  id="previous_value"
                  type="number"
                  step="0.01"
                  value={adjustForm.data.previous_value}
                  onChange={(e) => adjustForm.setData("previous_value", e.target.value)}
                />
                {adjustForm.errors.previous_value && (
                  <p className="text-xs text-destructive">{adjustForm.errors.previous_value}</p>
                )}
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="new_value">New value</Label>
                <Input
                  id="new_value"
                  type="number"
                  step="0.01"
                  value={adjustForm.data.new_value}
                  onChange={(e) => adjustForm.setData("new_value", e.target.value)}
                />
                {adjustForm.errors.new_value && (
                  <p className="text-xs text-destructive">{adjustForm.errors.new_value}</p>
                )}
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="amount_adjusted">Amount adjusted</Label>
                <Input
                  id="amount_adjusted"
                  type="number"
                  step="0.01"
                  value={adjustForm.data.amount_adjusted}
                  onChange={(e) => adjustForm.setData("amount_adjusted", e.target.value)}
                />
                {adjustForm.errors.amount_adjusted && (
                  <p className="text-xs text-destructive">{adjustForm.errors.amount_adjusted}</p>
                )}
              </div>
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="reason">Reason for adjustment</Label>
              <Textarea
                id="reason"
                rows={3}
                value={adjustForm.data.reason}
                onChange={(e) => adjustForm.setData("reason", e.target.value)}
                placeholder="Why this correction is required…"
                required
              />
              {adjustForm.errors.reason && (
                <p className="text-xs text-destructive">{adjustForm.errors.reason}</p>
              )}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="notes">Notes</Label>
              <Textarea
                id="notes"
                rows={2}
                value={adjustForm.data.notes}
                onChange={(e) => adjustForm.setData("notes", e.target.value)}
                placeholder="Optional supporting notes"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="supporting_reference">Supporting reference</Label>
              <Input
                id="supporting_reference"
                value={adjustForm.data.supporting_reference}
                onChange={(e) => adjustForm.setData("supporting_reference", e.target.value)}
                placeholder="Ticket #, email, Stripe refund id…"
              />
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="original_status">Mark original status as</Label>
              <select
                id="original_status"
                value={adjustForm.data.original_status}
                onChange={(e) => adjustForm.setData("original_status", e.target.value)}
                className="flex h-10 w-full rounded-md border border-border/60 bg-background px-3 text-sm"
              >
                <option value="adjusted">Adjusted</option>
                <option value="reversed">Reversed</option>
                <option value="cancelled">Cancelled</option>
                <option value="refunded">Refunded</option>
                <option value="unchanged">Leave status unchanged</option>
              </select>
            </div>
            <DialogFooter className="gap-2 sm:gap-0">
              <Button type="button" variant="outline" onClick={() => setAdjustOpen(false)}>
                Cancel
              </Button>
              <Button type="submit" disabled={adjustForm.processing}>
                {adjustForm.processing ? "Saving…" : "Save adjustment"}
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </AppLayout>
  )
}
