"use client"

import { useEffect, useMemo, useRef, useState, type ReactNode } from "react"
import { Head, Link, router } from "@inertiajs/react"
import { ConfirmationModal } from "@/components/admin/confirmation-modal"
import type { UnifiedLedgerRow } from "@/components/admin/unified-ledger-card"
import { transactionTypeBadgeClass, transactionTypeDisplayLabel } from "@/lib/transaction-type-labels"
import { connectionHubTypeLabel } from "@/lib/connection-hub-type"
import { motion } from "framer-motion"
import AppLayout from "@/layouts/app-layout"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { LedgerOrganizationCombobox } from "@/components/admin/ledger-organization-combobox"
import { Label } from "@/components/ui/label"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import {
  ArrowRightLeft,
  Ban,
  AlertCircle,
  Banknote,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Clock,
  Coins,
  CreditCard,
  Info,
  Landmark,
  Layers,
  Minus,
  ScrollText,
  Search,
  TrendingUp,
  Wallet,
  XCircle,
  Eye,
  Trash2,
  Heart,
  Building2,
  Package,
  Store,
  UserCircle,
  Download,
  Loader2,
  Link2,
  IdCard,
  Split,
  UserPlus,
  Cog,
  Gift,
  ShoppingBag,
} from "lucide-react"
import type { BreadcrumbItem } from "@/types"
import { cn } from "@/lib/utils"

const breadcrumbs: BreadcrumbItem[] = [
  { title: "Dashboard", href: "/dashboard" },
  { title: "Transaction ledger", href: "#" },
]

interface LedgerRow {
  id: number
  transaction_id: string
  type: string
  status: string
  amount: number
  fee: number
  currency: string
  payment_method: string | null
  related_kind: string
  related_purpose: string
  related_display_name: string
  related_label: string
  related_source: "polymorphic" | "meta" | "none"
  /** True when this row is linked to a Believe or campaign donation (same rules as the detail page). */
  donation_badge?: boolean
  donation_badge_label?: string
  /** donor | recipient_direct | recipient_split | alliance_fee | campaign — drives label + badge styling */
  donation_ledger_perspective?: string | null
  processed_at: string | null
  created_at: string
  user: { id: number; name: string; email: string } | null
  meta: Record<string, unknown> | null
  /** Server-computed report columns (fees from metadata when stored). */
  ledger_report?: LedgerReport
  /** BIU unified row for finance / client export alignment */
  unified_ledger?: UnifiedLedgerRow
}

interface LedgerReport {
  date: string
  reference: string
  stripe_mode?: "live" | "test" | "local" | "unknown"
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
  /** Marketplace orders: line subtotal, tax, shipping (from Order) */
  subtotal_amount?: number | null
  sales_tax_amount?: number | null
  shipping_amount?: number | null
  /** Selling modules: settlement splits (supplier/merchant, nonprofit, platform) */
  supplier_payout?: number | null
  organization_payout?: number | null
  platform_payout?: number | null
  /** From Order / ServiceOrder merge (Printify, merchant storefront, Service Hub seller). */
  supplier_name?: string | null
  supplier_type?: string | null
  supporter_payout?: number | null
}

interface LaravelPagination<T> {
  data: T[]
  current_page: number
  first_page_url: string
  from: number | null
  last_page: number
  last_page_url: string
  links: Array<{
    url: string | null
    label: string
    active: boolean
  }>
  next_page_url: string | null
  path: string
  per_page: number
  prev_page_url: string | null
  to: number | null
  total: number
}

interface Props {
  transactions: LaravelPagination<LedgerRow>
  stats: {
    total_records: number
    completed_sum: number
    pending_count: number
    failed_count: number
  }
  filters: {
    search: string
    type: string
    status: string
    per_page?: number
    organization_id: number | null
    module: string
    period: string
    ledger_type: string
    connection_hub_type: string
  }
  typeOptions: string[]
  statusOptions: string[]
  moduleOptions: string[]
  connectionHubTypeOptions: string[]
  ledgerTypeOptions: string[]
  /** Selected org label when URL has organization_id (combobox display before open). */
  ledgerOrganizationInitial: Array<{ value: string; label: string }>
}

const container = {
  hidden: { opacity: 0 },
  show: {
    opacity: 1,
    transition: { staggerChildren: 0.06, delayChildren: 0.05 },
  },
}

const item = {
  hidden: { opacity: 0, y: 14 },
  show: { opacity: 1, y: 0, transition: { type: "spring" as const, stiffness: 380, damping: 28 } },
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

type LedgerKind = "money" | "bp" | "brp"

function resolveLedgerKind(
  u: UnifiedLedgerRow | undefined,
  currency: string,
  pointsPay: boolean,
): LedgerKind {
  if (u?.ledger_type === "brp" || currency.toUpperCase() === "BRP") {
    return "brp"
  }
  if (u?.ledger_type === "bp" || pointsPay) {
    return "bp"
  }
  return "money"
}

function ledgerKindFilterLabel(lt: string): string {
  switch (lt) {
    case "money":
      return "Money (USD)"
    case "bp":
      return "Believe Points (BP)"
    case "brp":
      return "Believe Reward Points (BRP)"
    default:
      return lt
  }
}

function formatAmountForLedger(kind: LedgerKind, n: number, currency: string, className?: string) {
  if (kind === "brp") {
    const sign = n > 0 ? "+" : n < 0 ? "−" : ""
    const abs = Math.abs(n)
    return (
      <span
        className={cn(
          "inline-flex items-center justify-end gap-1.5 tabular-nums",
          n > 0 && "text-blue-700 dark:text-blue-300",
          n < 0 && "text-foreground",
          className,
        )}
      >
        <Gift className="h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400" aria-hidden />
        {sign}
        {abs.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}{" "}
        <span className="text-[11px] font-semibold uppercase tracking-wide text-blue-600/90 dark:text-blue-400/90">
          BRP
        </span>
      </span>
    )
  }

  if (kind === "bp") {
    const sign = n > 0 ? "+" : n < 0 ? "−" : ""
    const abs = Math.abs(n)
    return (
      <span
        className={cn(
          "inline-flex items-center justify-end gap-1.5 tabular-nums",
          n > 0 && "text-emerald-700 dark:text-emerald-300",
          n < 0 && "text-foreground",
          className,
        )}
      >
        <Coins className="h-4 w-4 shrink-0 text-purple-600 dark:text-purple-400" aria-hidden />
        {sign}
        {abs.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}{" "}
        <span className="text-[11px] font-semibold uppercase tracking-wide text-purple-600/90 dark:text-purple-400/90">
          BP
        </span>
      </span>
    )
  }

  return formatMoney(n, currency)
}

/** Commerce columns — BRP: —; BP: coins + BP; money: USD. */
function formatMoneyColumnForLedger(
  kind: LedgerKind,
  n: number | null | undefined,
  currency: string,
  className?: string,
): ReactNode {
  if (kind === "brp") {
    return "—"
  }
  if (n == null || n === undefined || Number.isNaN(Number(n))) {
    return "—"
  }
  return formatAmountForLedger(kind, Number(n), currency, className)
}

/** Net — BRP/BP rows show points; money: USD. */
function formatNetColumnForLedger(
  kind: LedgerKind,
  n: number | null | undefined,
  currency: string,
  fallback?: number | null,
  className?: string,
): ReactNode {
  const raw = n != null && !Number.isNaN(Number(n)) ? Number(n) : fallback
  if (raw == null || raw === undefined || Number.isNaN(Number(raw))) {
    return "—"
  }
  return formatAmountForLedger(kind, Number(raw), currency, className)
}

/** Workbook line amounts: prefer unified_ledger, fall back to ledger_report. */
function ledgerReportLineAmount(
  kind: LedgerKind,
  u: UnifiedLedgerRow | undefined,
  rep: LedgerReport | undefined,
  key: "subtotal_amount" | "sales_tax_amount" | "shipping_amount",
  cur: string,
): ReactNode {
  if (kind === "brp") {
    return "—"
  }
  const fromU = u != null ? u[key] : undefined
  const fromRep = rep?.[key]
  const raw = fromU !== undefined && fromU !== null ? fromU : fromRep
  if (raw === undefined || raw === null) return "—"
  return formatAmountForLedger(kind, Number(raw), cur, "text-muted-foreground")
}

function ledgerEventLabel(u: UnifiedLedgerRow | undefined): string {
  if (!u) {
    return "—"
  }
  const eventName = u.event_name?.trim()
  if (eventName) {
    return eventName
  }
  return u.transaction_type.replace(/_/g, " ")
}

function ledgerSupplierName(u: UnifiedLedgerRow | undefined, rep: LedgerReport | undefined): string {
  const v = u?.supplier_name ?? rep?.supplier_name
  return v != null && String(v).trim() !== "" ? String(v) : "—"
}

function ledgerSupplierType(u: UnifiedLedgerRow | undefined, rep: LedgerReport | undefined): string {
  const v = u?.supplier_type ?? rep?.supplier_type
  return v != null && String(v).trim() !== "" ? String(v) : "—"
}

/** Catalog base cost (unified_ledger); hidden for BRP rows. */
function ledgerSupplierCostCell(kind: LedgerKind, u: UnifiedLedgerRow | undefined, cur: string): ReactNode {
  if (kind === "brp" || !u) return "—"
  const n = u.supplier_cost_amount
  if (n === null || n === undefined || !Number.isFinite(Number(n))) return "—"
  return formatAmountForLedger(kind, Number(n), cur, "text-muted-foreground")
}

/** Margin % + dollar markup when present (unified_ledger). */
function ledgerMarkupCell(kind: LedgerKind, u: UnifiedLedgerRow | undefined, cur: string): ReactNode {
  if (!u) return "—"
  const p = u.selling_price_markup_percent
  const a = u.selling_price_markup_amount
  if (p == null && (a === null || a === undefined)) return "—"
  const pctStr =
    p != null
      ? Number.isInteger(Number(p))
        ? `${Number(p)}%`
        : `${Number(p).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}%`
      : ""
  if (kind === "brp") {
    return pctStr || "—"
  }
  if (a != null && a !== undefined && Number.isFinite(Number(a))) {
    return (
      <span className="text-muted-foreground">
        {pctStr ? (
          <>
            {pctStr}
            <span className="mx-0.5 text-border">·</span>
          </>
        ) : null}
        <span className="tabular-nums text-foreground">
          {kind === "bp" ? formatAmountForLedger("bp", Number(a), cur) : formatMoney(Number(a), cur)}
        </span>
      </span>
    )
  }
  return pctStr || "—"
}

function ledgerPlatformFeeCell(kind: LedgerKind, u: UnifiedLedgerRow | undefined, rep: LedgerReport | undefined, cur: string): ReactNode {
  if (kind === "brp") return "—"
  const raw = u != null ? u.biu_fee_amount : rep?.biu_fee
  if (raw === undefined || raw === null) return "—"
  return formatAmountForLedger(kind, Number(raw), cur, "text-muted-foreground")
}

/** Workbook-style labels (all caps in UI). */
function ledgerSupplierTypeDisplayLabel(supplierType: string): string {
  switch (supplierType.toUpperCase()) {
    case "PRINTIFY":
      return "PRINTIFY"
    case "MERCHANT_HUB":
      return "MERCHANT HUB"
    case "ORG_STOREFRONT":
      return "ORGANIZATION"
    case "MERCHANT":
      return "STOREFRONT"
    case "SUPPORTER":
    case "SELLER":
      return "SUPPORTER"
    default:
      return supplierType.replace(/_/g, " ").toUpperCase()
  }
}

function formatLedgerDate(iso: string) {
  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" })
  } catch {
    return iso
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

function statusClass(status: string) {
  switch (status) {
    case "completed":
    case "deposit":
      return "border-emerald-500/40 bg-emerald-500/[0.12] text-emerald-800 shadow-sm shadow-emerald-500/10 dark:text-emerald-200"
    case "pending":
      return "border-amber-500/40 bg-amber-500/[0.12] text-amber-900 shadow-sm shadow-amber-500/10 dark:text-amber-100"
    case "failed":
    case "rejected":
      return "border-red-500/40 bg-red-500/[0.12] text-red-800 shadow-sm shadow-red-500/10 dark:text-red-200"
    case "cancelled":
      return "border-muted-foreground/25 bg-muted/60 text-muted-foreground"
    default:
      return "border-primary/35 bg-primary/10 text-primary shadow-sm shadow-primary/10"
  }
}

/** Normalize wallet `type` — some rows may have empty/null type in DB or legacy payloads. */
function ledgerRowWalletType(row: LedgerRow): string {
  const raw = row.type
  const s = raw == null ? "" : String(raw).trim()
  if (s !== "") {
    return s
  }
  const fromUnified = row.unified_ledger?.transaction_type
  const u = fromUnified == null ? "" : String(fromUnified).trim()
  if (u !== "") {
    return u
  }
  const meta = row.meta && typeof row.meta === "object" ? (row.meta as Record<string, unknown>) : {}
  const metaType = meta.type
  const m = metaType == null ? "" : String(metaType).trim()
  return m
}

/** Wallet row type for the table: one pill only — icon + label (no stacked donation badge). */
function ledgerRowTypeDisplay(row: LedgerRow): { label: string; className: string; icon: "arrows" | "heart" } {
  const meta = row.meta && typeof row.meta === "object" ? (row.meta as Record<string, unknown>) : {}
  const perspective = row.donation_ledger_perspective
  const walletType = ledgerRowWalletType(row)

  if (meta.ledger_role === "donor_payment" || perspective === "donor") {
    return {
      label: "Donation",
      className: "border-rose-500/40 bg-rose-500/[0.12] text-rose-900 shadow-sm shadow-rose-500/10 dark:text-rose-100",
      icon: "heart",
    }
  }
  if (perspective === "campaign" && walletType === "purchase") {
    return {
      label: "Campaign gift",
      className: "border-amber-500/40 bg-amber-500/[0.12] text-amber-950 shadow-sm shadow-amber-500/10 dark:text-amber-100",
      icon: "heart",
    }
  }

  // Deposit / wallet credit from a donation: show a clear label — not a bare "Deposit" + mystery heart.
  if (row.donation_badge && walletType === "deposit") {
    if (perspective === "recipient_direct") {
      return {
        label: "Donation received",
        className: "border-emerald-500/45 bg-emerald-500/[0.12] text-emerald-900 shadow-sm shadow-emerald-500/10 dark:text-emerald-100",
        icon: "heart",
      }
    }
    if (perspective === "recipient_split") {
      return {
        label: "Donation received (split)",
        className: "border-teal-500/45 bg-teal-500/[0.12] text-teal-900 shadow-sm shadow-teal-500/10 dark:text-teal-100",
        icon: "heart",
      }
    }
    if (perspective === "alliance_fee") {
      return {
        label: "Alliance fee",
        className: "border-violet-500/45 bg-violet-500/[0.12] text-violet-900 shadow-sm shadow-violet-500/10 dark:text-violet-100",
        icon: "arrows",
      }
    }
  }

  return {
    label: transactionTypeDisplayLabel(walletType),
    className: transactionTypeBadgeClass(walletType === "" ? "purchase" : walletType),
    icon: "arrows",
  }
}

function moduleTableLabel(m: string) {
  const map: Record<string, string> = {
    donation: "Donation",
    fundme: "Support a project",
    campaign: "Campaign",
    believe_points: "Believe Points",
    reward: "Reward",
    wallet: "Wallet",
    marketplace: "Marketplace",
    gift_card: "Gift card",
    servicehub: "Service Hub",
    connection_hub: "Connection Hub",
    course: "Connection Hub",
    merchant_hub: "Merchant Hub",
    organization_subscription: "Org sub",
    supporter_subscription: "Supporter sub",
    merchant_subscription: "Merchant sub",
    payout: "Payout",
    refund: "Refund",
    adjustment: "Adjustment",
  }
  return map[m] ?? m.replace(/_/g, " ")
}

function moduleCellLabel(u: UnifiedLedgerRow | undefined): string {
  if (!u) return "—"
  const base = moduleTableLabel(u.module)
  const hubType = u.connection_hub_type_label?.trim()
  if ((u.module === "connection_hub" || u.module === "course") && hubType) {
    return `${base} · ${hubType}`
  }
  return base
}

function supplierTypeBadgeClassTable(supplierType: string) {
  switch (supplierType.toUpperCase()) {
    case "PRINTIFY":
      return "border-orange-500/40 bg-orange-500/10 text-orange-950 dark:text-orange-100"
    case "MERCHANT_HUB":
      return "border-amber-500/45 bg-amber-500/10 text-amber-950 dark:text-amber-100"
    case "ORG_STOREFRONT":
    case "MERCHANT":
      return "border-violet-500/40 bg-violet-500/10 text-violet-900 dark:text-violet-100"
    case "SUPPORTER":
    case "SELLER":
      return "border-sky-500/40 bg-sky-500/10 text-sky-900 dark:text-sky-100"
    default:
      return "border-border/50 bg-muted/40 text-foreground"
  }
}

function supplierTypeIconTable(supplierType: string): ReactNode {
  const cls = "size-3 shrink-0 opacity-90"
  switch (supplierType.toUpperCase()) {
    case "PRINTIFY":
      return <Package className={cls} aria-hidden />
    case "MERCHANT_HUB":
      return <ShoppingBag className={cls} aria-hidden />
    case "ORG_STOREFRONT":
    case "MERCHANT":
      return <Store className={cls} aria-hidden />
    case "SUPPORTER":
      return <Heart className={cls} aria-hidden />
    case "SELLER":
      return <UserCircle className={cls} aria-hidden />
    default:
      return <Building2 className={cls} aria-hidden />
  }
}

function providerBadgeClassTable(p: string) {
  switch (p) {
    case "stripe":
      return "border-violet-500/35 bg-violet-500/10 text-violet-900 dark:text-violet-100"
    case "bridge":
      return "border-sky-500/35 bg-sky-500/10 text-sky-900 dark:text-sky-100"
    case "points":
      return "border-amber-500/35 bg-amber-500/10 text-amber-950 dark:text-amber-100"
    default:
      return "border-border/50 bg-muted/40 text-foreground"
  }
}

function processorFeeRailBadgeClass(kind: "stripe" | "bridge") {
  return kind === "stripe" ? providerBadgeClassTable("stripe") : providerBadgeClassTable("bridge")
}

function partiesSummary(u: UnifiedLedgerRow | undefined): string {
  if (!u) return "—"
  if (u.module === "believe_points") {
    const name = (u.from_name ?? u.from_type)?.trim()
    return name ? `Purchaser: ${name}` : "—"
  }
  const from = u.from_name ?? u.from_type
  const to = u.to_name ?? u.to_type
  return `${from} → ${to}`
}

function donationPaymentMethodFromMeta(meta: Record<string, unknown> | null | undefined): string {
  const v = meta?.donation_payment_method
  return typeof v === "string" ? v.toLowerCase() : ""
}

/** Points-style ledger row: unified provider, txn payment_method, or donation meta (recipient deposit rows). */
function isLedgerRowPaidWithBelievePoints(
  u: UnifiedLedgerRow | undefined,
  paymentMethod: string | null | undefined,
  row: Pick<LedgerRow, "meta">,
): boolean {
  if (u?.ledger_type === "bp" || u?.ledger_type === "brp") return true
  const pm = (paymentMethod || "").toLowerCase()
  if (pm === "believe_points" || u?.provider === "points") return true
  const dpm = donationPaymentMethodFromMeta(row.meta)
  return dpm === "believe_points" || dpm.includes("believe_point")
}

/** Donation module / donation badge + paid with Believe Points → show BIU Points in Provider & Payment. */
function isDonationLedgerPointsRow(
  u: UnifiedLedgerRow | undefined,
  row: Pick<LedgerRow, "meta" | "donation_badge">,
): boolean {
  const isDonation = u?.module === "donation" || row.donation_badge === true
  return isDonation && isLedgerRowPaidWithBelievePoints(u, row.payment_method, row)
}

function ledgerProviderDisplayLabel(u: UnifiedLedgerRow | undefined, row: LedgerRow): string {
  if (u?.ledger_type === "brp") {
    return "Believe Reward Points"
  }
  if (isLedgerRowPaidWithBelievePoints(u, row.payment_method, row)) {
    return isDonationLedgerPointsRow(u, row) ? "BIU Points" : "Believe Points"
  }
  if (u?.provider === "points") return "Believe Points"
  if (!u) return "—"
  return u.provider || "—"
}

/** Payment column badge when row is paid with Believe Points (donations → BIU Points). */
function ledgerPointsPaymentLabel(u: UnifiedLedgerRow | undefined, row: LedgerRow): string {
  if (u?.ledger_type === "brp") {
    return "Believe Reward Points"
  }
  return isDonationLedgerPointsRow(u, row) ? "BIU Points" : "Believe Points"
}

function ledgerPaymentMethodIcon(
  paymentMethodLower: string,
  provider: string | undefined,
  kind: LedgerKind,
): ReactNode {
  if (kind === "brp") {
    return <Gift className="h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400" aria-hidden />
  }
  if (kind === "bp") {
    return <Coins className="h-4 w-4 shrink-0 text-purple-600 dark:text-purple-400" aria-hidden />
  }
  if (provider === "stripe") {
    return <CreditCard className="h-4 w-4 shrink-0 text-violet-600 dark:text-violet-400" aria-hidden />
  }
  if (provider === "bridge") {
    return <Landmark className="h-4 w-4 shrink-0 text-sky-600 dark:text-sky-400" aria-hidden />
  }
  if (provider === "points") {
    return <Coins className="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />
  }

  const pm = paymentMethodLower.trim()
  const exact: Record<string, ReactNode> = {
    stripe: <CreditCard className="h-4 w-4 shrink-0 text-violet-600 dark:text-violet-400" aria-hidden />,
    card: <IdCard className="h-4 w-4 shrink-0 text-violet-500 dark:text-violet-300" aria-hidden />,
    link: <Link2 className="h-4 w-4 shrink-0 text-indigo-600 dark:text-indigo-400" aria-hidden />,
    us_bank_account: <Landmark className="h-4 w-4 shrink-0 text-sky-600 dark:text-sky-400" aria-hidden />,
    ach: <Landmark className="h-4 w-4 shrink-0 text-sky-700 dark:text-sky-300" aria-hidden />,
    sepa_debit: <Landmark className="h-4 w-4 shrink-0 text-teal-600 dark:text-teal-400" aria-hidden />,
    donation: <Heart className="h-4 w-4 shrink-0 text-rose-500 dark:text-rose-400" aria-hidden />,
    care_alliance_split: <Split className="h-4 w-4 shrink-0 text-violet-600 dark:text-violet-400" aria-hidden />,
    believe_points: <Coins className="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />,
    points: <Coins className="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />,
    cash: <Banknote className="h-4 w-4 shrink-0 text-emerald-700 dark:text-emerald-300" aria-hidden />,
    referral: <UserPlus className="h-4 w-4 shrink-0 text-cyan-600 dark:text-cyan-400" aria-hidden />,
    free: <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" aria-hidden />,
    system: <Cog className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />,
    apple_pay: <Wallet className="h-4 w-4 shrink-0 text-slate-700 dark:text-slate-300" aria-hidden />,
    google_pay: <Wallet className="h-4 w-4 shrink-0 text-blue-700 dark:text-blue-300" aria-hidden />,
    paypal: <Wallet className="h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400" aria-hidden />,
    gift_card: <Gift className="h-4 w-4 shrink-0 text-fuchsia-600 dark:text-fuchsia-400" aria-hidden />,
  }
  if (pm && exact[pm]) {
    return exact[pm]
  }

  if (paymentMethodLower.includes("stripe")) {
    return <CreditCard className="h-4 w-4 shrink-0 text-violet-600 dark:text-violet-400" aria-hidden />
  }
  if (paymentMethodLower === "link" || paymentMethodLower.endsWith("_link")) {
    return <Link2 className="h-4 w-4 shrink-0 text-indigo-600 dark:text-indigo-400" aria-hidden />
  }
  if (paymentMethodLower.includes("card")) {
    return <IdCard className="h-4 w-4 shrink-0 text-violet-500 dark:text-violet-300" aria-hidden />
  }
  if (
    paymentMethodLower.includes("bank") ||
    paymentMethodLower.includes("ach") ||
    paymentMethodLower.includes("bridge") ||
    paymentMethodLower.includes("sepa")
  ) {
    return <Landmark className="h-4 w-4 shrink-0 text-sky-600 dark:text-sky-400" aria-hidden />
  }
  if (paymentMethodLower.includes("paypal")) {
    return <Wallet className="h-4 w-4 shrink-0 text-blue-600 dark:text-blue-400" aria-hidden />
  }
  if (paymentMethodLower.includes("point") || paymentMethodLower.includes("believe_point")) {
    return <Coins className="h-4 w-4 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />
  }
  if (paymentMethodLower.includes("donat")) {
    return <Heart className="h-4 w-4 shrink-0 text-rose-500 dark:text-rose-400" aria-hidden />
  }
  if (paymentMethodLower.includes("split") || paymentMethodLower.includes("alliance")) {
    return <Split className="h-4 w-4 shrink-0 text-violet-600 dark:text-violet-400" aria-hidden />
  }
  if (paymentMethodLower.includes("subscription") || paymentMethodLower.includes("plan")) {
    return <Layers className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
  }
  if (paymentMethodLower.includes("wallet")) {
    return <Wallet className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
  }
  if (paymentMethodLower.includes("cash") || paymentMethodLower.includes("money")) {
    return <Banknote className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
  }
  if (paymentMethodLower.includes("commission") || paymentMethodLower.includes("payout")) {
    return <TrendingUp className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
  }
  if (paymentMethodLower.includes("referral")) {
    return <UserPlus className="h-4 w-4 shrink-0 text-cyan-600 dark:text-cyan-400" aria-hidden />
  }

  if (paymentMethodLower === "") {
    return <Minus className="h-4 w-4 shrink-0 text-muted-foreground/60" aria-hidden />
  }

  return <Wallet className="h-4 w-4 shrink-0 text-muted-foreground" aria-hidden />
}

function moduleLabel(key: string): string {
  const map: Record<string, string> = {
    donation: "Donation",
    fundme: "Support a project",
    campaign: "Campaign",
    believe_points: "Believe Points",
    reward: "Reward",
    wallet: "Wallet",
    marketplace: "Marketplace",
    gift_card: "Gift card",
    servicehub: "Service hub",
    connection_hub: "Connection Hub",
    course: "Connection Hub",
    merchant_hub: "Merchant hub",
    organization_subscription: "Organization subscription",
    supporter_subscription: "Supporter subscription",
    merchant_subscription: "Merchant subscription",
    payout: "Payout",
    refund: "Refund",
    adjustment: "Adjustment",
  }
  return map[key] ?? key.replace(/_/g, " ").replace(/\b\w/g, (c) => c.toUpperCase())
}

function periodLabel(key: string): string {
  const map: Record<string, string> = {
    all: "All time",
    day: "Today",
    week: "This week",
    month: "This month",
    year: "This year",
  }
  return map[key] ?? key
}

export default function TransactionLedger({
  transactions,
  stats,
  filters,
  typeOptions,
  statusOptions,
  moduleOptions,
  connectionHubTypeOptions,
  ledgerTypeOptions,
  ledgerOrganizationInitial,
}: Props) {
  const [search, setSearch] = useState(filters.search || "")
  const [type, setType] = useState(filters.type || "all")
  const [status, setStatus] = useState(filters.status || "all")
  const [perPage, setPerPage] = useState(String(filters.per_page ?? 10))
  const [organizationId, setOrganizationId] = useState(
    filters.organization_id != null ? String(filters.organization_id) : "all",
  )
  const [module, setModule] = useState(filters.module ?? "all")
  const [period, setPeriod] = useState(filters.period ?? "all")
  const [ledgerType, setLedgerType] = useState(filters.ledger_type ?? "all")
  const [connectionHubType, setConnectionHubType] = useState(filters.connection_hub_type ?? "all")
  const skipSearchDebounceOnce = useRef(true)
  const [deleteModal, setDeleteModal] = useState<{ open: boolean; id: number | null; ref: string }>({
    open: false,
    id: null,
    ref: "",
  })
  const [isDeleting, setIsDeleting] = useState(false)
  const [isExporting, setIsExporting] = useState(false)

  const filterSelectClass =
    "flex h-9 w-full min-w-0 rounded-md border border-border/60 bg-background px-2 py-1.5 text-xs text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/25 sm:h-10 sm:text-sm"

  const ledgerQueryParams = (): Record<string, string> => {
    const params: Record<string, string> = {}
    if (search.trim()) params.search = search.trim()
    if (type && type !== "all") params.type = type
    if (status && status !== "all") params.status = status
    if (perPage && perPage !== "10") params.per_page = perPage
    if (organizationId && organizationId !== "all") params.organization_id = organizationId
    if (module && module !== "all") params.module = module
    if (period && period !== "all") params.period = period
    if (ledgerType && ledgerType !== "all") params.ledger_type = ledgerType
    if (connectionHubType && connectionHubType !== "all") params.connection_hub_type = connectionHubType
    return params
  }

  const orgPickerBaseParams = useMemo(
    () => ledgerQueryParams(),
    [search, type, status, perPage, organizationId, module, period, ledgerType, connectionHubType],
  )

  /** Export URL; `router.visit` sends X-Inertia → server returns 409 + `Inertia::location` → full GET downloads XLSX. */
  const ledgerExportUrl = useMemo(() => {
    const qs = new URLSearchParams(ledgerQueryParams()).toString()
    const base = route("admin.transactions.ledger.export")
    return qs ? `${base}?${qs}` : base
  }, [search, type, status, perPage, organizationId, module, period, ledgerType, connectionHubType])

  useEffect(() => {
    if (skipSearchDebounceOnce.current) {
      skipSearchDebounceOnce.current = false
      return
    }
    const id = setTimeout(() => {
      router.get(route("admin.transactions.ledger"), ledgerQueryParams(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
      })
    }, 420)
    return () => clearTimeout(id)
    // eslint-disable-next-line react-hooks/exhaustive-deps -- intentional: avoid double fetch when filters change via selects
  }, [search])

  const applySelectFilters = (nextType: string, nextStatus: string) => {
    const params: Record<string, string> = {}
    if (search.trim()) params.search = search.trim()
    if (nextType && nextType !== "all") params.type = nextType
    if (nextStatus && nextStatus !== "all") params.status = nextStatus
    if (perPage && perPage !== "10") params.per_page = perPage
    if (organizationId && organizationId !== "all") params.organization_id = organizationId
    if (module && module !== "all") params.module = module
    if (period && period !== "all") params.period = period
    if (ledgerType && ledgerType !== "all") params.ledger_type = ledgerType
    if (connectionHubType && connectionHubType !== "all") params.connection_hub_type = connectionHubType

    router.get(route("admin.transactions.ledger"), params, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    })
  }

  const applyPerPage = (next: string) => {
    setPerPage(next)
    const params: Record<string, string> = {}
    if (search.trim()) params.search = search.trim()
    if (type && type !== "all") params.type = type
    if (status && status !== "all") params.status = status
    if (next && next !== "10") params.per_page = next
    if (organizationId && organizationId !== "all") params.organization_id = organizationId
    if (module && module !== "all") params.module = module
    if (period && period !== "all") params.period = period
    if (ledgerType && ledgerType !== "all") params.ledger_type = ledgerType
    if (connectionHubType && connectionHubType !== "all") params.connection_hub_type = connectionHubType
    router.get(route("admin.transactions.ledger"), params, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    })
  }

  const applyLedgerFilter = (patch: Partial<{ organizationId: string; module: string; period: string; ledgerType: string; connectionHubType: string }>) => {
    const nextOrg = patch.organizationId ?? organizationId
    const nextMod = patch.module ?? module
    const nextPeriod = patch.period ?? period
    const nextLedgerType = patch.ledgerType ?? ledgerType
    const nextConnectionHubType = patch.connectionHubType ?? connectionHubType
    if (patch.organizationId !== undefined) setOrganizationId(patch.organizationId)
    if (patch.module !== undefined) setModule(patch.module)
    if (patch.period !== undefined) setPeriod(patch.period)
    if (patch.ledgerType !== undefined) setLedgerType(patch.ledgerType)
    if (patch.connectionHubType !== undefined) setConnectionHubType(patch.connectionHubType)

    const params: Record<string, string> = {}
    if (search.trim()) params.search = search.trim()
    if (type && type !== "all") params.type = type
    if (status && status !== "all") params.status = status
    if (perPage && perPage !== "10") params.per_page = perPage
    if (nextOrg && nextOrg !== "all") params.organization_id = nextOrg
    if (nextMod && nextMod !== "all") params.module = nextMod
    if (nextPeriod && nextPeriod !== "all") params.period = nextPeriod
    if (nextLedgerType && nextLedgerType !== "all") params.ledger_type = nextLedgerType
    if (nextConnectionHubType && nextConnectionHubType !== "all") params.connection_hub_type = nextConnectionHubType

    router.get(route("admin.transactions.ledger"), params, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    })
  }

  const getNumericLinks = (links: LaravelPagination<LedgerRow>["links"]) => {
    return links.filter((link) => {
      const label = link.label.replace(/&laquo;|&raquo;/g, "").trim()
      return !isNaN(Number(label)) && label !== "Previous" && label !== "Next"
    })
  }

  const confirmDelete = () => {
    if (deleteModal.id == null) return
    setIsDeleting(true)
    router.delete(route("admin.transactions.destroy", deleteModal.id), {
      preserveScroll: true,
      onSuccess: () => {
        setDeleteModal({ open: false, id: null, ref: "" })
      },
      onFinish: () => setIsDeleting(false),
    })
  }

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title="Transaction ledger" />

      <div className="mx-4 my-5 space-y-8 sm:mx-8 lg:mx-10">
        <motion.div
          initial={{ opacity: 0, y: -8 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.45 }}
          className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
          <div className="space-y-2">
            <div className="inline-flex items-center gap-2 rounded-full border border-primary/25 bg-primary/10 px-3 py-1 text-xs font-medium text-primary">
              <ScrollText className="h-3.5 w-3.5" />
              Admin · Money movement
            </div>
            <h1 className="text-3xl font-bold tracking-tight text-foreground sm:text-4xl">Transaction ledger</h1>
            <p className="max-w-2xl text-sm text-muted-foreground sm:text-base">
              Every wallet transaction in one place — columns follow the{" "}
              <span className="font-medium text-foreground">unified ledger (all modules)</span> flow: identity, order lines
              (subtotal, shipping, tax), supplier name/type,{" "}
              <span className="font-medium text-foreground">catalog supplier cost</span> and{" "}
              <span className="font-medium text-foreground">markup</span>, settlement (
              <span className="font-medium text-foreground">supplier payout</span>, payment provider fee,{" "}
              <span className="font-medium text-foreground">BIU platform fee</span>,{" "}
              <span className="font-medium text-foreground">platform payout</span>), org / supporter payouts, net — plus provider,
              payment, and related for ops.
            </p>
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="shrink-0 gap-2 self-start sm:self-auto min-w-[9.5rem]"
            disabled={isExporting}
            aria-busy={isExporting}
            onClick={() => {
              router.visit(ledgerExportUrl, {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setIsExporting(true),
                onFinish: () => setIsExporting(false),
                onError: () => setIsExporting(false),
                onCancel: () => setIsExporting(false),
              })
            }}
          >
            {isExporting ? (
              <Loader2 className="h-4 w-4 shrink-0 animate-spin" aria-hidden />
            ) : (
              <Download className="h-4 w-4 shrink-0" aria-hidden />
            )}
            {isExporting ? "Exporting…" : "Export Excel"}
          </Button>
        </motion.div>

        <motion.div
          variants={container}
          initial="hidden"
          animate="show"
          className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
        >
          {[
            {
              label: "Total records",
              value: stats.total_records.toLocaleString(),
              icon: Layers,
              accent: "from-primary/25 to-primary/5",
            },
            {
              label: "Completed volume",
              value: formatMoney(stats.completed_sum, "USD"),
              icon: TrendingUp,
              accent: "from-emerald-500/25 to-emerald-500/5",
            },
            {
              label: "Pending",
              value: stats.pending_count.toLocaleString(),
              icon: Clock,
              accent: "from-amber-500/25 to-amber-500/5",
            },
            {
              label: "Failed",
              value: stats.failed_count.toLocaleString(),
              icon: XCircle,
              accent: "from-red-500/20 to-red-500/5",
            },
          ].map((card, i) => (
            <motion.div key={card.label} variants={item} custom={i}>
              <Card
                className={cn(
                  "overflow-hidden border-border/80 bg-card/80 shadow-sm backdrop-blur-sm transition-all hover:border-primary/35 hover:shadow-md",
                )}
              >
                <div className={cn("h-1 bg-gradient-to-r", card.accent)} />
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium text-muted-foreground">{card.label}</CardTitle>
                  <card.icon className="h-4 w-4 text-primary/80" />
                </CardHeader>
                <CardContent>
                  <p className="text-2xl font-semibold tracking-tight text-foreground">{card.value}</p>
                </CardContent>
              </Card>
            </motion.div>
          ))}
        </motion.div>

        <motion.div
          initial={{ opacity: 0, y: 8 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ delay: 0.06 }}
          className="space-y-4"
        >
          <div className="flex flex-col gap-1 border-b border-border/60 pb-4">
            <h2 className="flex flex-wrap items-center gap-2 text-base font-semibold text-foreground sm:text-lg">
              <Wallet className="h-4 w-4 shrink-0 text-muted-foreground" />
              Ledger table
              <span className="font-normal text-muted-foreground">· {transactions.total} entries</span>
            </h2>
            <p className="max-w-2xl text-sm text-muted-foreground">
              Scroll horizontally on smaller screens. Amounts use the unified row when present (same as the transaction detail
              card). Use <span className="font-medium text-foreground">View</span> for full formulas and Stripe breakdown.
            </p>
          </div>

          <div className="space-y-4">
            {/* Row 1: Search + Type + Status + Per page (inputs aligned) */}
            <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:gap-3">
              <div className="min-w-0 flex-1 space-y-1.5">
                <Label htmlFor="ledger-search" className="text-xs uppercase tracking-wide text-muted-foreground">
                  Search
                </Label>
                <div className="relative">
                  <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    id="ledger-search"
                    placeholder="Ref, user, email, payment method…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="h-10 border-border/60 bg-background pl-9 pr-3 text-sm"
                    autoComplete="off"
                  />
                </div>
              </div>
              <div className="grid grid-cols-1 gap-3 min-[480px]:grid-cols-3 lg:flex lg:shrink-0 lg:gap-3">
                <div className="min-w-0 space-y-1 min-[480px]:min-w-[7.5rem] lg:w-[9.5rem]">
                  <Label htmlFor="ledger-type" className="text-xs uppercase tracking-wide text-muted-foreground">
                    Type
                  </Label>
                  <select
                    id="ledger-type"
                    aria-label="Filter by transaction type"
                    title="Transaction type"
                    value={type}
                    onChange={(e) => {
                      const v = e.target.value
                      setType(v)
                      applySelectFilters(v, status)
                    }}
                    className={filterSelectClass}
                  >
                    <option value="all">All types</option>
                    {typeOptions.map((ot) => (
                      <option key={ot} value={ot}>
                        {ot.replace(/_/g, " ")}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="min-w-0 space-y-1 min-[480px]:min-w-[7.5rem] lg:w-[9.5rem]">
                  <Label htmlFor="ledger-status" className="text-xs uppercase tracking-wide text-muted-foreground">
                    Status
                  </Label>
                  <select
                    id="ledger-status"
                    aria-label="Filter by transaction status"
                    title="Transaction status"
                    value={status}
                    onChange={(e) => {
                      const v = e.target.value
                      setStatus(v)
                      applySelectFilters(type, v)
                    }}
                    className={filterSelectClass}
                  >
                    <option value="all">All statuses</option>
                    {statusOptions.map((s) => (
                      <option key={s} value={s}>
                        {s}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="min-w-0 space-y-1 min-[480px]:min-w-[5.5rem] lg:w-[6.5rem]">
                  <Label htmlFor="ledger-per-page" className="text-xs uppercase tracking-wide text-muted-foreground">
                    Per page
                  </Label>
                  <select
                    id="ledger-per-page"
                    aria-label="Rows per page"
                    title="Rows per page"
                    value={perPage}
                    onChange={(e) => applyPerPage(e.target.value)}
                    className={filterSelectClass}
                  >
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                  </select>
                </div>
              </div>
            </div>

            {/* Row 2: Period, Organization, Module, Ledger type */}
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <div className="min-w-0 space-y-1">
                <Label htmlFor="ledger-period" className="text-xs uppercase tracking-wide text-muted-foreground">
                  Period
                </Label>
                <select
                  id="ledger-period"
                  aria-label="Filter by date range"
                  title="Period"
                  value={period}
                  onChange={(e) => applyLedgerFilter({ period: e.target.value })}
                  className={filterSelectClass}
                >
                  <option value="all">{periodLabel("all")}</option>
                  <option value="day">{periodLabel("day")}</option>
                  <option value="week">{periodLabel("week")}</option>
                  <option value="month">{periodLabel("month")}</option>
                  <option value="year">{periodLabel("year")}</option>
                </select>
              </div>
              <div className="min-w-0 space-y-1">
                <Label className="text-xs uppercase tracking-wide text-muted-foreground">
                  <span className="inline-flex items-center gap-1.5">
                    <Building2 className="h-3.5 w-3.5" aria-hidden />
                    Organization
                  </span>
                </Label>
                <LedgerOrganizationCombobox
                  key={filters.organization_id != null ? `org-${filters.organization_id}` : "org-all"}
                  ledgerQueryParams={orgPickerBaseParams}
                  initialOptions={ledgerOrganizationInitial}
                  value={organizationId}
                  onValueChange={(v) => applyLedgerFilter({ organizationId: v === "" ? "all" : v })}
                  className="h-9 min-h-9 border-border/60 text-xs sm:h-10 sm:min-h-10 sm:text-sm"
                />
              </div>
              <div className="min-w-0 space-y-1 sm:col-span-2 lg:col-span-1">
                <Label htmlFor="ledger-module" className="text-xs uppercase tracking-wide text-muted-foreground">
                  Module
                </Label>
                <select
                  id="ledger-module"
                  aria-label="Filter by unified ledger module"
                  title="Module"
                  value={module}
                  onChange={(e) => applyLedgerFilter({ module: e.target.value })}
                  className={filterSelectClass}
                >
                  <option value="all">All modules</option>
                  {moduleOptions.map((m) => (
                    <option key={m} value={m}>
                      {moduleLabel(m)}
                    </option>
                  ))}
                </select>
              </div>
              <div className="min-w-0 space-y-1 sm:col-span-2 lg:col-span-1">
                <Label htmlFor="ledger-connection-hub-type" className="text-xs uppercase tracking-wide text-muted-foreground">
                  Connection Hub type
                </Label>
                <select
                  id="ledger-connection-hub-type"
                  aria-label="Filter by Connection Hub listing type"
                  title="Connection Hub type"
                  value={connectionHubType}
                  onChange={(e) => applyLedgerFilter({ connectionHubType: e.target.value })}
                  className={filterSelectClass}
                >
                  <option value="all">All hub types</option>
                  {connectionHubTypeOptions.map((hubType) => (
                    <option key={hubType} value={hubType}>
                      {connectionHubTypeLabel(hubType)}
                    </option>
                  ))}
                </select>
              </div>
              <div className="min-w-0 space-y-1">
                <Label htmlFor="ledger-type" className="text-xs uppercase tracking-wide text-muted-foreground">
                  Ledger type
                </Label>
                <select
                  id="ledger-type"
                  aria-label="Filter by ledger type"
                  title="Ledger type"
                  value={ledgerType}
                  onChange={(e) => applyLedgerFilter({ ledgerType: e.target.value })}
                  className={filterSelectClass}
                >
                  <option value="all">All ledger types</option>
                  {ledgerTypeOptions.map((lt) => (
                    <option key={lt} value={lt}>
                      {ledgerKindFilterLabel(lt)}
                    </option>
                  ))}
                </select>
              </div>
            </div>
          </div>

          <div>
              {transactions.data.length === 0 ? (
                <div className="flex flex-col items-center justify-center gap-2 py-12 text-center">
                  <ScrollText className="h-8 w-8 text-muted-foreground" />
                  <p className="text-sm font-medium text-foreground">No transactions found</p>
                  <p className="max-w-sm text-xs text-muted-foreground">Try adjusting search or filters.</p>
                </div>
              ) : (
                <div className="overflow-hidden rounded-xl border border-border/60 bg-card shadow-sm ring-1 ring-border/20">
                  <div className="overflow-x-auto">
                    <table className="w-full min-w-[3200px] border-collapse text-left text-base">
                      <thead>
                        <tr className="border-b border-border/60 bg-muted text-xs font-semibold uppercase tracking-wide text-muted-foreground sm:text-sm">
                          <th
                            className="sticky left-0 z-[2] w-64 min-w-[16rem] max-w-[20rem] bg-muted px-3 py-3.5 pl-3 text-left align-top shadow-[4px_0_12px_-4px_rgba(0,0,0,0.08)] dark:shadow-[4px_0_12px_-4px_rgba(0,0,0,0.35)] sm:px-4 sm:pl-4"
                            title="Transaction type (deposit, purchase, donation, …)"
                          >
                            Transaction
                          </th>
                          <th
                            className="sticky left-64 z-[2] w-[4.75rem] min-w-[4.25rem] bg-muted py-3.5 pl-2 pr-1 text-left font-mono normal-case shadow-[4px_0_12px_-4px_rgba(0,0,0,0.06)] dark:shadow-[4px_0_12px_-4px_rgba(0,0,0,0.3)]"
                            title="Internal transaction id"
                          >
                            Txn
                          </th>
                          <th className="whitespace-nowrap px-4 py-3.5">When</th>
                          <th className="min-w-[8rem] whitespace-nowrap px-4 py-3.5">Reference</th>
                          <th className="whitespace-nowrap px-4 py-3.5">Status</th>
                          <th className="whitespace-nowrap px-4 py-3.5">Ledger Type</th>
                          <th className="whitespace-nowrap px-4 py-3.5" title="Believe Points (BP) settlement status — not used for BRP rows">
                            BP Status
                          </th>
                          <th className="whitespace-nowrap px-4 py-3.5">Available Date</th>
                          <th className="min-w-[8rem] whitespace-nowrap px-4 py-3.5">Current Owner</th>
                          <th className="whitespace-nowrap px-4 py-3.5" title="Believe Reward Points (BRP) activity — Earned, Redeemed, etc.">
                            BRP Activity
                          </th>
                          <th className="whitespace-nowrap px-4 py-3.5">Module</th>
                          <th className="min-w-[7rem] whitespace-nowrap px-4 py-3.5">Event</th>
                          <th className="min-w-[12rem] px-4 py-3.5">From → To</th>
                          <th className="whitespace-nowrap px-4 py-3.5 text-right" title="Wallet ledger line amount">
                            Wallet amt
                          </th>
                          <th className="whitespace-nowrap px-4 py-3.5 text-right">Gross</th>
                          <th className="whitespace-nowrap px-4 py-3.5 text-right" title="Product subtotal (order lines)">
                            Subtotal
                          </th>
                          <th className="whitespace-nowrap px-4 py-3.5 text-right">Shipping</th>
                          <th className="whitespace-nowrap px-4 py-3.5 text-right">Sales tax</th>
                          <th className="min-w-[7rem] px-4 py-3.5" title="From marketplace order (Printify, merchant) or Service Hub seller">
                            Supplier name
                          </th>
                          <th
                            className="min-w-[6rem] whitespace-nowrap px-4 py-3.5"
                            title="PRINTIFY, MERCHANT HUB, ORGANIZATION, SUPPORTER (Service Hub & courses)"
                          >
                            Supplier type
                          </th>
                          <th
                            className="whitespace-nowrap px-4 py-3.5 text-right"
                            title="Catalog base cost (Subtotal ÷ (1 + markup%) on lines; or stored source cost)"
                          >
                            Supplier cost
                          </th>
                          <th className="min-w-[6.5rem] whitespace-nowrap px-4 py-3.5 text-right" title="Margin % and dollar markup on catalog lines">
                            Markup
                          </th>
                          <th className="whitespace-nowrap px-4 py-3.5 text-right" title="Settlement paid to supplier / merchant">
                            Supplier payout
                          </th>
                          <th className="whitespace-nowrap px-4 py-3.5 text-right" title="Payment Provider Fee — Stripe + Bridge (detail on hover)">
                            Payment provider fee
                          </th>
                          <th className="whitespace-nowrap px-4 py-3.5 text-right" title="BIU / platform fee on the order (not platform share)">
                            Platform fee
                          </th>
                          <th className="whitespace-nowrap px-4 py-3.5 text-right" title="Platform share / payout retained">
                            Platform payout
                          </th>
                          <th className="whitespace-nowrap px-4 py-3.5 text-right" title="Organization / nonprofit payout">
                            Org payout
                          </th>
                          <th className="whitespace-nowrap px-4 py-3.5 text-right" title="Instructor / supporter share when applicable">
                            Supporter payout
                          </th>
                          <th className="whitespace-nowrap px-4 py-3.5 text-right">Net</th>
                          <th className="whitespace-nowrap px-4 py-3.5">Provider</th>
                          <th className="min-w-[8.5rem] whitespace-nowrap px-4 py-4">Payment</th>
                          <th className="min-w-[8rem] px-4 py-3.5">Related</th>
                          <th className="sticky right-0 z-[1] whitespace-nowrap bg-muted px-4 py-3.5 pr-4 text-right shadow-[-4px_0_12px_-4px_rgba(0,0,0,0.08)] dark:shadow-[-4px_0_12px_-4px_rgba(0,0,0,0.35)]">
                            Actions
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        {transactions.data.map((row, idx) => {
                          const rep = row.ledger_report
                          const cur = row.currency || "USD"
                          const typeDisplay = ledgerRowTypeDisplay(row)
                          const u = row.unified_ledger
                          const grossDisplayPlain = u != null ? u.gross_amount : rep?.gross_amount
                          const netDisplayPlain = u != null ? u.net_amount : rep?.net_to_organization ?? null
                          const stripeFeeAmt = u != null ? u.stripe_fee_amount : rep?.stripe_fee ?? 0
                          const bridgeFeeAmt = u != null ? u.bridge_fee_amount : rep?.bridge_fee ?? 0
                          const supplierPayout = u != null ? u.supplier_payout_amount : rep?.supplier_payout ?? null
                          const orgPayout = u != null ? u.organization_payout_amount : rep?.organization_payout ?? null
                          const platformPayout = u != null ? u.platform_payout_amount : rep?.platform_payout ?? null
                          const supporterPayout = u != null ? u.supporter_payout_amount : rep?.supporter_payout ?? null
                          const pointsPay = isLedgerRowPaidWithBelievePoints(u, row.payment_method, row)
                          const ledgerKind = resolveLedgerKind(u, cur, pointsPay)
                          const walletAmt =
                            ledgerKind === "brp"
                              ? u?.wallet_amount != null && !Number.isNaN(Number(u.wallet_amount))
                                ? Number(u.wallet_amount)
                                : Number(row.amount)
                              : pointsPay && u?.wallet_amount != null && !Number.isNaN(Number(u.wallet_amount))
                                ? Number(u.wallet_amount)
                                : row.amount
                          const netDisplayAmt =
                            ledgerKind === "brp"
                              ? netDisplayPlain != null && !Number.isNaN(Number(netDisplayPlain))
                                ? Number(netDisplayPlain)
                                : walletAmt
                              : ledgerKind === "bp"
                                ? netDisplayPlain != null && !Number.isNaN(Number(netDisplayPlain))
                                  ? Number(netDisplayPlain)
                                  : walletAmt
                                : netDisplayPlain
                          const processorTotal = ledgerKind === "money"
                            ? u != null
                              ? Number(u.processor_fee_amount)
                              : Number(rep?.stripe_fee ?? 0) + Number(rep?.bridge_fee ?? 0)
                            : 0
                          const processingTitle = ledgerKind === "money"
                            ? `Payment Provider Fee — Stripe ${formatMoney(stripeFeeAmt, cur)} · Bridge ${formatMoney(bridgeFeeAmt, cur)}`
                            : undefined
                          const supplierNameCell = ledgerSupplierName(u, rep)
                          const supplierTypeCell = ledgerSupplierType(u, rep)

                          return (
                            <tr
                              key={row.id}
                              className={cn(
                                "border-b border-border/40 transition-colors hover:bg-muted/30 [&>td]:align-middle",
                                idx % 2 === 1 && "bg-muted",
                              )}
                            >
                              <td
                                className={cn(
                                  "sticky left-0 z-[2] w-64 min-w-[16rem] max-w-[20rem] border-r border-border/30 px-3 py-3 pl-3 align-top shadow-[4px_0_12px_-4px_rgba(0,0,0,0.06)] dark:shadow-[4px_0_12px_-4px_rgba(0,0,0,0.3)] sm:px-4 sm:pl-4",
                                  idx % 2 === 1 ? "bg-muted" : "bg-card",
                                )}
                              >
                                <Tooltip delayDuration={350}>
                                  <TooltipTrigger asChild>
                                    <span
                                      className={cn(
                                        "inline-flex w-fit max-w-full min-w-0 cursor-pointer items-center gap-1.5 rounded-full border px-2 py-1.5 text-left text-xs font-semibold uppercase leading-tight tracking-wide sm:px-2.5 sm:text-sm",
                                        typeDisplay.className,
                                      )}
                                    >
                                      {typeDisplay.icon === "heart" ? (
                                        <Heart className="h-3.5 w-3.5 shrink-0 opacity-90 sm:h-4 sm:w-4" aria-hidden />
                                      ) : (
                                        <ArrowRightLeft className="h-3.5 w-3.5 shrink-0 opacity-80 sm:h-4 sm:w-4" aria-hidden />
                                      )}
                                      <span className="min-w-0 max-w-[11rem] truncate sm:max-w-[14rem]">{typeDisplay.label}</span>
                                    </span>
                                  </TooltipTrigger>
                                  <TooltipContent
                                    side="top"
                                    sideOffset={8}
                                    className="max-w-xs px-3 py-2 text-left text-xs font-medium normal-case leading-snug tracking-normal text-popover-foreground"
                                  >
                                    {row.donation_badge && row.donation_badge_label ? (
                                      <>
                                        <span className="font-semibold uppercase tracking-wide">{typeDisplay.label}</span>
                                        <span className="mt-1 block text-[11px] font-normal text-muted-foreground">
                                          {row.donation_badge_label}
                                        </span>
                                      </>
                                    ) : (
                                      typeDisplay.label
                                    )}
                                  </TooltipContent>
                                </Tooltip>
                              </td>
                              <td
                                className={cn(
                                  "sticky left-64 z-[2] w-[4.75rem] min-w-[4.25rem] border-r border-border/30 py-3 pl-2 pr-1 text-left font-mono text-sm font-semibold tabular-nums text-foreground shadow-[4px_0_12px_-4px_rgba(0,0,0,0.05)] dark:shadow-[4px_0_12px_-4px_rgba(0,0,0,0.28)]",
                                  idx % 2 === 1 ? "bg-muted" : "bg-card",
                                )}
                                title={`Transaction id ${row.id}`}
                              >
                                #{row.id}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-sm text-muted-foreground">
                                {rep ? formatLedgerDate(rep.date) : new Date(row.created_at).toLocaleString()}
                              </td>
                              <td className="max-w-[11rem] truncate px-4 py-3 font-mono text-sm text-foreground" title={row.transaction_id}>
                                {row.transaction_id}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3">
                                <span
                                  className={cn(
                                    "inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-semibold capitalize leading-none",
                                    statusClass(row.status),
                                  )}
                                >
                                  {statusIcon(row.status)}
                                  <span className="leading-none">{row.status}</span>
                                </span>
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-sm font-medium text-foreground">
                                {u?.ledger_type_label ?? "Money (USD)"}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-sm text-muted-foreground">
                                {ledgerKind === "brp" ? (
                                  <span title="BP Status applies to Believe Points rows only">—</span>
                                ) : (
                                  u?.bp_status_label ?? "N/A"
                                )}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-sm text-muted-foreground">
                                {u?.available_at
                                  ? new Date(u.available_at).toLocaleString(undefined, {
                                      dateStyle: "medium",
                                      timeStyle: "short",
                                    })
                                  : ledgerKind === "bp" && u?.bp_status_label === "Processing"
                                    ? "Pending"
                                    : "—"}
                              </td>
                              <td className="max-w-[10rem] truncate px-4 py-3 text-sm text-foreground" title={u?.current_owner ?? undefined}>
                                {u?.current_owner ?? "—"}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-sm text-muted-foreground">
                                {ledgerKind === "brp" ? (
                                  u?.brp_activity_label && u.brp_activity_label !== "N/A"
                                    ? u.brp_activity_label
                                    : "—"
                                ) : (
                                  <span title="BRP Activity applies to Believe Reward Points rows only">—</span>
                                )}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-sm">
                                {u ? (
                                  <span className="font-medium text-foreground">{moduleCellLabel(u)}</span>
                                ) : (
                                  <span className="text-muted-foreground">—</span>
                                )}
                              </td>
                              <td className="max-w-[10rem] truncate px-4 py-3 text-sm text-foreground" title={ledgerEventLabel(u)}>
                                {ledgerEventLabel(u)}
                              </td>
                              <td className="max-w-[16rem] px-4 py-3 text-sm leading-snug text-foreground" title={partiesSummary(u)}>
                                {partiesSummary(u)}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-right text-base font-semibold tabular-nums text-foreground">
                                {formatAmountForLedger(ledgerKind, walletAmt, cur)}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-right text-sm tabular-nums text-muted-foreground">
                                {formatMoneyColumnForLedger(ledgerKind, grossDisplayPlain, cur, "text-muted-foreground")}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-right text-sm tabular-nums text-muted-foreground">
                                {ledgerReportLineAmount(ledgerKind, u, rep, "subtotal_amount", cur)}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-right text-sm tabular-nums text-muted-foreground">
                                {ledgerReportLineAmount(ledgerKind, u, rep, "shipping_amount", cur)}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-right text-sm tabular-nums text-muted-foreground">
                                {ledgerReportLineAmount(ledgerKind, u, rep, "sales_tax_amount", cur)}
                              </td>
                              <td
                                className="max-w-[11rem] truncate px-4 py-3 text-sm text-foreground"
                                title={supplierNameCell !== "—" ? supplierNameCell : undefined}
                              >
                                {supplierNameCell}
                              </td>
                              <td className="px-4 py-3">
                                {supplierTypeCell === "—" ? (
                                  <span className="text-sm text-muted-foreground">—</span>
                                ) : (
                                  <Badge
                                    variant="outline"
                                    title={supplierTypeCell}
                                    className={cn(
                                      "h-auto max-w-[min(100%,10.5rem)] justify-start gap-1.5 px-2 py-1.5 font-mono text-[10px] font-semibold uppercase leading-none tracking-wide",
                                      supplierTypeBadgeClassTable(supplierTypeCell),
                                    )}
                                  >
                                    {supplierTypeIconTable(supplierTypeCell)}
                                    <span className="min-w-0 truncate">{ledgerSupplierTypeDisplayLabel(supplierTypeCell)}</span>
                                  </Badge>
                                )}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-right text-sm tabular-nums text-muted-foreground">
                                {ledgerSupplierCostCell(ledgerKind, u, cur)}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-right text-sm tabular-nums text-muted-foreground">
                                {ledgerMarkupCell(ledgerKind, u, cur)}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-right text-sm tabular-nums text-muted-foreground">
                                {formatMoneyColumnForLedger(ledgerKind, supplierPayout, cur, "text-muted-foreground")}
                              </td>
                              <td
                                className="whitespace-nowrap px-4 py-3 text-right text-sm tabular-nums"
                                title={processingTitle}
                              >
                                {ledgerKind !== "money" ? (
                                  <Badge
                                    variant="outline"
                                    className={cn(
                                      "inline-flex items-center justify-start gap-1 tabular-nums text-[10px] font-medium leading-none",
                                      ledgerKind === "brp"
                                        ? "border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-200"
                                        : providerBadgeClassTable("points"),
                                    )}
                                  >
                                    {ledgerKind === "brp" ? (
                                      <Gift className="h-3 w-3 shrink-0" aria-hidden />
                                    ) : (
                                      <Coins className="h-3 w-3 shrink-0" aria-hidden />
                                    )}
                                    <span className="leading-none">No Fee</span>
                                  </Badge>
                                ) : (
                                  <span className="text-muted-foreground">{formatMoney(processorTotal, cur)}</span>
                                )}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-right text-sm tabular-nums text-muted-foreground">
                                {ledgerPlatformFeeCell(ledgerKind, u, rep, cur)}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-right text-sm tabular-nums text-muted-foreground">
                                {formatMoneyColumnForLedger(ledgerKind, platformPayout, cur, "text-muted-foreground")}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-right text-sm tabular-nums text-muted-foreground">
                                {formatMoneyColumnForLedger(ledgerKind, orgPayout, cur, "text-muted-foreground")}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-right text-sm tabular-nums text-muted-foreground">
                                {formatMoneyColumnForLedger(ledgerKind, supporterPayout, cur, "text-muted-foreground")}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold tabular-nums text-foreground">
                                {formatNetColumnForLedger(ledgerKind, netDisplayAmt, cur, walletAmt)}
                              </td>
                              <td className="whitespace-nowrap px-4 py-3">
                                {ledgerKind !== "money" ? (
                                  <span
                                    className={cn(
                                      "inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium",
                                      ledgerKind === "brp"
                                        ? "border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-200"
                                        : providerBadgeClassTable("points"),
                                    )}
                                  >
                                    {ledgerKind === "brp" ? (
                                      <Gift className="h-4 w-4 shrink-0" aria-hidden />
                                    ) : (
                                      <Coins className="h-4 w-4 shrink-0" aria-hidden />
                                    )}
                                    {ledgerProviderDisplayLabel(u, row)}
                                  </span>
                                ) : u ? (
                                  <span
                                    className={cn(
                                      "inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-medium",
                                      providerBadgeClassTable(u.provider),
                                      u.provider !== "points" && "capitalize",
                                    )}
                                  >
                                    {u.provider === "points" && <Coins className="h-4 w-4 shrink-0" aria-hidden />}
                                    {ledgerProviderDisplayLabel(u, row)}
                                  </span>
                                ) : (
                                  <span className="text-sm text-muted-foreground">—</span>
                                )}
                              </td>
                              <td className="whitespace-nowrap px-4 py-4">
                                <span
                                  className={cn(
                                    "inline-flex min-h-[1.5rem] items-center gap-2 text-sm leading-normal",
                                    ledgerKind !== "money" ? "text-foreground" : "text-muted-foreground",
                                  )}
                                >
                                  {ledgerPaymentMethodIcon(
                                    (row.payment_method || "").toLowerCase(),
                                    u?.provider,
                                    ledgerKind,
                                  )}
                                  <span
                                    className={cn(
                                      "whitespace-nowrap",
                                      ledgerKind === "money" && row.payment_method && "capitalize",
                                    )}
                                  >
                                    {ledgerKind !== "money"
                                      ? ledgerPointsPaymentLabel(u, row)
                                      : row.payment_method
                                        ? row.payment_method.replace(/_/g, " ")
                                        : "—"}
                                  </span>
                                </span>
                              </td>
                              <td className="max-w-[11rem] px-4 py-3">
                                <div className="flex items-start gap-1.5">
                                  <div className="min-w-0 flex-1 truncate text-sm text-foreground" title={row.related_display_name}>
                                    {row.related_display_name && row.related_display_name !== "—"
                                      ? row.related_display_name
                                      : row.related_kind && row.related_kind !== "—"
                                        ? row.related_kind
                                        : "—"}
                                  </div>
                                  {(row.related_source !== "none" || (row.meta && Object.keys(row.meta).length > 0)) && (
                                    <Tooltip delayDuration={200}>
                                      <TooltipTrigger asChild>
                                        <button
                                          type="button"
                                          className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-border/60 bg-background text-muted-foreground hover:bg-muted/50"
                                          aria-label="Related details"
                                        >
                                          <Info className="h-4 w-4" />
                                        </button>
                                      </TooltipTrigger>
                                      <TooltipContent className="max-w-md px-3 py-2.5 text-sm leading-relaxed text-popover-foreground">
                                        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                                          {row.related_source === "meta"
                                            ? "From metadata"
                                            : row.related_source === "polymorphic"
                                              ? "Database link"
                                              : "—"}
                                        </p>
                                        <p className="mt-1.5 text-popover-foreground">{row.related_purpose}</p>
                                      </TooltipContent>
                                    </Tooltip>
                                  )}
                                </div>
                              </td>
                              <td
                                className={cn(
                                  "sticky right-0 z-[1] whitespace-nowrap border-l border-border/30 px-4 py-3 pr-4 text-right shadow-[-4px_0_12px_-4px_rgba(0,0,0,0.06)] dark:shadow-[-4px_0_12px_-4px_rgba(0,0,0,0.3)]",
                                  idx % 2 === 1 ? "bg-muted" : "bg-card",
                                )}
                              >
                                <div className="inline-flex flex-col items-end gap-1.5 sm:flex-row sm:items-center sm:justify-end sm:gap-2">
                                  <Link href={route("admin.transactions.show", row.id)}>
                                    <Button type="button" size="default" variant="secondary" className="h-9 gap-1.5 px-3 text-sm">
                                      <Eye className="h-4 w-4" />
                                      View
                                    </Button>
                                  </Link>
                                  <Button
                                    type="button"
                                    variant="outline"
                                    size="default"
                                    className="h-9 border-destructive/30 px-3 text-sm text-destructive hover:bg-destructive/10"
                                    onClick={() => setDeleteModal({ open: true, id: row.id, ref: row.transaction_id })}
                                  >
                                    <Trash2 className="h-4 w-4" />
                                  </Button>
                                </div>
                              </td>
                            </tr>
                          )
                        })}
                      </tbody>
                    </table>
                  </div>
                </div>
              )}

              {transactions.total > 0 && (
                <div className="flex flex-col items-center justify-between gap-3 border-t border-border/60 px-0 py-4 sm:flex-row">
                  <p className="text-sm text-muted-foreground">
                    Showing <span className="font-semibold text-foreground">{transactions.from ?? 0}</span> to{" "}
                    <span className="font-semibold text-foreground">{transactions.to ?? 0}</span> of{" "}
                    <span className="font-semibold text-foreground">{transactions.total}</span>
                  </p>
                  {transactions.last_page > 1 && (
                    <div className="flex flex-wrap items-center justify-center gap-2">
                      {transactions.prev_page_url && (
                        <Link href={transactions.prev_page_url} preserveScroll preserveState>
                          <Button variant="outline" size="sm" className="gap-1 rounded-full border-border/60">
                            <ChevronLeft className="h-4 w-4" />
                            Prev
                          </Button>
                        </Link>
                      )}
                      <div className="flex items-center gap-1 rounded-full border border-border/50 bg-background/80 p-1 px-1">
                        {getNumericLinks(transactions.links).map((link, i) => (
                          <Link key={i} href={link.url || "#"} preserveScroll preserveState>
                            <Button
                              variant={link.active ? "default" : "ghost"}
                              size="sm"
                              className={cn(
                                "min-h-9 min-w-9 rounded-full px-3",
                                link.active && "bg-primary text-primary-foreground shadow-sm",
                              )}
                              disabled={!link.url}
                            >
                              {link.label.replace(/&laquo;|&raquo;/g, "").trim()}
                            </Button>
                          </Link>
                        ))}
                      </div>
                      {transactions.next_page_url && (
                        <Link href={transactions.next_page_url} preserveScroll preserveState>
                          <Button variant="outline" size="sm" className="gap-1 rounded-full border-border/60">
                            Next
                            <ChevronRight className="h-4 w-4" />
                          </Button>
                        </Link>
                      )}
                    </div>
                  )}
                </div>
              )}
          </div>
        </motion.div>
      </div>

      <ConfirmationModal
        isOpen={deleteModal.open}
        onChange={(open) => !open && setDeleteModal({ open: false, id: null, ref: "" })}
        title="Delete this transaction?"
        description={`This will permanently remove ${deleteModal.ref || "this record"} from the ledger. This does not reverse charges in Stripe or PayPal.`}
        confirmLabel={isDeleting ? "Deleting…" : "Delete"}
        onConfirm={confirmDelete}
        isLoading={isDeleting}
      />
    </AppLayout>
  )
}
