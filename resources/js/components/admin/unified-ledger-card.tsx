"use client"

import type { ReactNode } from "react"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { cn } from "@/lib/utils"
import {
  ArrowRight,
  Building2,
  Heart,
  Coins,
  CreditCard,
  Gift,
  Landmark,
  Package,
  Receipt,
  Sparkles,
  Store,
  User,
  UserCircle,
  Wallet,
  ShoppingBag,
} from "lucide-react"

export interface UnifiedLedgerRow {
  txn_id: number
  datetime_iso: string
  module: string
  transaction_type: string
  direction: string
  from_type: string
  from_name: string | null
  from_email: string | null
  from_id: number | null
  to_type: string
  to_name: string | null
  to_email: string | null
  to_id: number | null
  related_record: string
  subtotal_amount: number | null
  sales_tax_amount: number | null
  shipping_amount: number | null
  gross_amount: number
  processor_fee_amount: number
  stripe_fee_amount: number
  bridge_fee_amount: number
  biu_fee_amount: number
  split_amount: number
  refund_amount: number
  net_amount: number | null
  /** Selling flows: supplier/merchant, nonprofit share, platform share (from OrderSplit / Service Hub / meta). */
  supplier_payout_amount: number | null
  organization_payout_amount: number | null
  platform_payout_amount: number | null
  /** Instructor / supporter share when present on ledger_report (meta-backed). */
  supporter_payout_amount?: number | null
  currency: string
  status: string
  provider: string
  reference: string
  organization_id: number | null
  organization_name: string | null
  /** Marketplace / Service Hub: fulfillment supplier (workbook columns). */
  supplier_name?: string | null
  supplier_type?: string | null
  /** First order line with a catalog product: `profit_margin_percentage` (selling price vs cost). */
  selling_price_markup_percent?: number | null
  /** Sum of line markups (retail − cost or implied from %); omit for Points-only display. */
  selling_price_markup_amount?: number | null
  /** Sum of catalog supplier base costs (source cost or subtotal ÷ (1 + markup%)); pairs with markup on the same lines. */
  supplier_cost_amount?: number | null
  /** Signed change to supporter total BP (ending − beginning). Null for money-only rows. */
  wallet_amount?: number | null
  /** Unified ledger classification (Money / BP / BRP). */
  ledger_type?: string
  ledger_type_label?: string
  bp_status?: string | null
  bp_status_label?: string
  brp_activity_type?: string | null
  brp_activity_label?: string
  event_name?: string | null
  connection_hub_type?: string | null
  connection_hub_type_label?: string | null
  current_owner?: string | null
  available_at?: string | null
}

/** Believe Points / Reward Points amount display (not USD). */
function ledgerAmountNode(
  usePoints: boolean,
  n: number | null | undefined,
  currency: string,
  zeroAsDash = false,
  emphasis = false,
  pointsKind: "bp" | "brp" | null = null,
): ReactNode {
  if (n === null || n === undefined) return "—"
  if (zeroAsDash && n === 0) return "—"

  const signed = Number(n)
  const sign = signed > 0 ? "+" : signed < 0 ? "−" : ""
  const abs = Math.abs(signed)

  if (pointsKind === "brp") {
    return (
      <span
        className={cn(
          "inline-flex items-center gap-1.5 tabular-nums",
          signed > 0 && "text-blue-700 dark:text-blue-300",
          signed < 0 && "text-foreground",
          !sign && "text-foreground",
          emphasis && "text-lg font-bold",
        )}
      >
        <Gift className={cn("shrink-0 text-blue-600 dark:text-blue-400", emphasis ? "h-5 w-5" : "h-4 w-4")} aria-hidden />
        {sign}
        {abs.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}{" "}
        <span className="text-[11px] font-semibold uppercase tracking-wide text-blue-600/90 dark:text-blue-400/90">BRP</span>
      </span>
    )
  }

  if (usePoints || pointsKind === "bp") {
    return (
      <span
        className={cn(
          "inline-flex items-center gap-1.5 tabular-nums",
          signed > 0 && "text-emerald-700 dark:text-emerald-300",
          signed < 0 && "text-foreground",
          !sign && "text-foreground",
          emphasis && "text-lg font-bold",
        )}
      >
        <Coins className={cn("shrink-0 text-purple-600 dark:text-purple-400", emphasis ? "h-5 w-5" : "h-4 w-4")} aria-hidden />
        {sign}
        {abs.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}{" "}
        <span className="text-[11px] font-semibold uppercase tracking-wide text-purple-600/90 dark:text-purple-400/90">BP</span>
      </span>
    )
  }
  return formatMoney(n, currency, zeroAsDash)
}

function formatMarkupPercent(n: number): string {
  const x = Number(n)
  if (!Number.isFinite(x)) return "—"
  if (Number.isInteger(x)) return `${x}%`
  return `${x.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })}%`
}

function markupCompactLabel(
  percent: number,
  amount: number | null | undefined,
  currency: string,
  showAmount: boolean,
): string {
  const p = formatMarkupPercent(percent)
  if (!showAmount || amount === null || amount === undefined || !Number.isFinite(Number(amount))) {
    return p
  }
  return `${p} (${formatMoney(Number(amount), currency)})`
}

function formatMoney(n: number | null | undefined, currency: string, zeroAsDash = false): string {
  if (n === null || n === undefined) return "—"
  if (zeroAsDash && n === 0) return "—"
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

function formatDateTime(iso: string) {
  try {
    return new Date(iso).toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" })
  } catch {
    return iso
  }
}

function sellingPayoutsVisible(data: UnifiedLedgerRow): boolean {
  return (
    data.supplier_payout_amount != null ||
    data.organization_payout_amount != null ||
    data.platform_payout_amount != null ||
    (data.supporter_payout_amount != null && data.supporter_payout_amount !== undefined)
  )
}

function moduleLabel(m: string) {
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
    course: "Course",
    merchant_hub: "Merchant Hub",
    organization_subscription: "Org subscription",
    supporter_subscription: "Supporter subscription",
    merchant_subscription: "Merchant subscription",
    payout: "Payout",
    refund: "Refund",
    adjustment: "Adjustment",
  }
  return map[m] ?? m.replace(/_/g, " ")
}

function providerBadgeClass(p: string) {
  switch (p) {
    case "stripe":
      return "border-violet-500/40 bg-violet-500/10 text-violet-900 dark:text-violet-100"
    case "bridge":
      return "border-sky-500/40 bg-sky-500/10 text-sky-900 dark:text-sky-100"
    case "points":
      return "border-amber-500/40 bg-amber-500/10 text-amber-950 dark:text-amber-100"
    default:
      return "border-border/60 bg-muted/50 text-foreground"
  }
}

function supplierTypeDisplayLabel(supplierType: string): string {
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

function supplierTypeBadgeClass(supplierType: string) {
  switch (supplierType.toUpperCase()) {
    case "PRINTIFY":
      return "border-orange-500/45 bg-orange-500/10 text-orange-950 dark:text-orange-100"
    case "MERCHANT_HUB":
      return "border-amber-500/45 bg-amber-500/10 text-amber-950 dark:text-amber-100"
    case "ORG_STOREFRONT":
    case "MERCHANT":
      return "border-violet-500/45 bg-violet-500/10 text-violet-900 dark:text-violet-100"
    case "SUPPORTER":
    case "SELLER":
      return "border-sky-500/45 bg-sky-500/10 text-sky-900 dark:text-sky-100"
    default:
      return "border-border/60 bg-muted/50 text-foreground"
  }
}

function supplierTypeIcon(supplierType: string): ReactNode {
  const cls = "size-3.5 shrink-0 opacity-90"
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

function ProviderRailLabel({ provider }: { provider: string }) {
  if (provider === "points") {
    return (
      <span className="inline-flex items-center gap-1.5">
        <Coins className="h-3.5 w-3.5 shrink-0 opacity-90" aria-hidden />
        Believe Points
      </span>
    )
  }
  return <span className="capitalize">{provider}</span>
}

type Variant = "full" | "compact"

export function UnifiedLedgerCard({ data, variant = "full", className }: { data: UnifiedLedgerRow; variant?: Variant; className?: string }) {
  const cur = data.currency || "USD"
  const usePoints = data.provider === "points"
  const isBpLedger = data.ledger_type === "bp"
  const isBrpLedger = data.ledger_type === "brp"
  const showMarkupDollars = !isBrpLedger
  const brpPointsAmount =
    isBrpLedger && (data.net_amount != null || data.wallet_amount != null)
      ? Number(data.net_amount ?? data.wallet_amount)
      : null

  if (variant === "compact") {
    return (
      <div
        className={cn(
          "rounded-lg border border-primary/20 bg-gradient-to-r from-primary/[0.06] to-transparent px-3 py-2.5 text-xs sm:px-4 sm:py-3 sm:text-sm",
          className,
        )}
      >
        <div className="flex min-w-0 flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-x-2 sm:gap-y-1">
          <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
            <Badge variant="outline" className="shrink-0 font-mono text-[10px] uppercase tracking-wide">
              {moduleLabel(data.module)}
            </Badge>
            <span className="text-muted-foreground max-sm:hidden">·</span>
            <span className="min-w-0 font-medium text-foreground">{data.transaction_type.replace(/_/g, " ")}</span>
            {data.module === "believe_points" ? (
              <>
                <span className="text-muted-foreground">·</span>
                <span className="min-w-0 max-w-full truncate sm:max-w-[min(100%,220px)]" title={data.from_name ?? ""}>
                  <span className="text-muted-foreground">Purchaser:</span>{" "}
                  <span className="text-foreground">{data.from_name ?? "—"}</span>
                </span>
              </>
            ) : (
              <>
                <span className="text-muted-foreground max-sm:hidden">·</span>
                <span className="flex min-w-0 max-w-full flex-wrap items-center gap-x-1 gap-y-0.5 sm:max-w-none">
                  <span className="truncate sm:max-w-[min(100%,11rem)]" title={data.from_name ?? ""}>
                    {data.from_name ?? data.from_type}
                  </span>
                  <ArrowRight className="h-3 w-3 shrink-0 text-muted-foreground max-sm:rotate-90 sm:rotate-0" aria-hidden />
                  <span className="truncate font-medium text-foreground sm:max-w-[min(100%,12rem)]" title={data.to_name ?? ""}>
                    {data.to_name ?? data.to_type}
                  </span>
                </span>
              </>
            )}
          </div>
          <div className="flex min-w-0 w-full flex-col gap-2 border-t border-primary/15 pt-2.5 sm:w-auto sm:flex-1 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end sm:border-t-0 sm:pt-0">
            <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 tabular-nums">
              {usePoints && !isBrpLedger && data.wallet_amount != null && Number.isFinite(Number(data.wallet_amount)) ? (
                <span className="inline-flex flex-wrap items-center gap-x-1.5 font-semibold text-foreground">
                  Wallet {ledgerAmountNode(true, data.wallet_amount, cur, true, false, "bp")}
                </span>
              ) : isBrpLedger && data.wallet_amount != null && Number.isFinite(Number(data.wallet_amount)) ? (
                <span className="inline-flex flex-wrap items-center gap-x-1.5 font-semibold text-foreground">
                  Wallet {ledgerAmountNode(false, data.wallet_amount, cur, true, false, "brp")}
                </span>
              ) : (
                <>
              <span className="inline-flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-muted-foreground">
                Gross {ledgerAmountNode(isBpLedger, isBrpLedger ? null : data.gross_amount, cur)}
              </span>
              <span className="text-muted-foreground">·</span>
              <span className="inline-flex flex-wrap items-center gap-x-1.5 font-semibold text-foreground">
                Net {ledgerAmountNode(isBpLedger, isBrpLedger ? brpPointsAmount : data.net_amount, cur, false, false, isBrpLedger ? "brp" : isBpLedger ? "bp" : null)}
              </span>
                </>
              )}
              {showMarkupDollars &&
                data.supplier_cost_amount != null &&
                data.supplier_cost_amount !== undefined &&
                Number.isFinite(Number(data.supplier_cost_amount)) && (
                  <>
                    <span className="text-muted-foreground max-sm:hidden">·</span>
                    <span className="text-[11px] text-muted-foreground">
                      Cost{" "}
                      <span className="font-semibold tabular-nums text-foreground">{formatMoney(Number(data.supplier_cost_amount), cur)}</span>
                    </span>
                  </>
                )}
              {data.selling_price_markup_percent != null && data.selling_price_markup_percent !== undefined && (
                <>
                  <span className="text-muted-foreground max-sm:hidden">·</span>
                  <span className="text-[11px] text-muted-foreground">
                    Markup{" "}
                    <span className="font-semibold tabular-nums text-foreground">
                      {markupCompactLabel(data.selling_price_markup_percent, data.selling_price_markup_amount, cur, showMarkupDollars)}
                    </span>
                  </span>
                </>
              )}
              {sellingPayoutsVisible(data) && (
                <>
                  <span className="text-muted-foreground max-sm:hidden">·</span>
                  <span className="w-full text-[11px] leading-snug text-muted-foreground sm:w-auto sm:max-w-[min(100%,28rem)] sm:leading-tight">
                    Supplier {formatMoney(data.supplier_payout_amount, cur)} · Platform {formatMoney(data.platform_payout_amount, cur)} · Org{" "}
                    {formatMoney(data.organization_payout_amount, cur)}
                  </span>
                </>
              )}
            </div>
            <div className="flex min-w-0 flex-wrap items-center gap-1.5">
              <Badge variant="outline" className={cn("text-[10px] capitalize", providerBadgeClass(data.provider))}>
                <ProviderRailLabel provider={data.provider} />
              </Badge>
              {data.provider !== "points" && (
                <>
                  <Badge variant="outline" className={cn("text-[10px] font-semibold", providerBadgeClass("stripe"))}>
                    Stripe {formatMoney(data.stripe_fee_amount, cur)}
                  </Badge>
                  <Badge variant="outline" className={cn("text-[10px] font-semibold", providerBadgeClass("bridge"))}>
                    Bridge {formatMoney(data.bridge_fee_amount, cur)}
                  </Badge>
                </>
              )}
            </div>
          </div>
        </div>
      </div>
    )
  }

  return (
    <Card className={cn("overflow-hidden border-primary/25 bg-card/60 shadow-lg ring-1 ring-primary/10", className)}>
      <CardHeader className="border-b border-border/60 bg-gradient-to-br from-primary/[0.08] via-transparent to-transparent pb-4">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
          <div className="space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <Sparkles className="h-5 w-5 text-primary" aria-hidden />
              <CardTitle className="text-lg sm:text-xl">Unified finance row</CardTitle>
              <Badge variant="secondary" className="text-[10px] font-normal uppercase tracking-wider">
                BIU ledger spec
              </Badge>
            </div>
            <CardDescription className="text-sm">
              Module, transaction type, parties, amounts, fees, and settlement.
            </CardDescription>
          </div>
          <div className="flex flex-wrap gap-2">
            <Badge variant="outline" className="capitalize">
              {data.direction}
            </Badge>
            <Badge variant="outline" className={cn("gap-1.5 capitalize", providerBadgeClass(data.provider))}>
              <ProviderRailLabel provider={data.provider} />
            </Badge>
            <Badge variant="outline" className="capitalize">
              {data.status}
            </Badge>
          </div>
        </div>
      </CardHeader>
      <CardContent className="grid gap-5 pt-4 sm:gap-6 sm:pt-6">
        <div className="grid min-w-0 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-3">
          <div className="min-w-0 space-y-1 rounded-xl border border-border/50 bg-muted/20 p-3 sm:p-4">
            <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Txn</p>
            <p className="font-mono text-xl font-bold text-foreground">{data.txn_id}</p>
            <p className="text-xs text-muted-foreground">Unique internal ID</p>
            <p className="text-xs text-muted-foreground">{formatDateTime(data.datetime_iso)}</p>
          </div>
          <div className="min-w-0 space-y-1 rounded-xl border border-border/50 bg-muted/20 p-3 sm:p-4">
            <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Module</p>
            <p className="text-lg font-semibold capitalize text-foreground">{moduleLabel(data.module)}</p>
            <p className="mt-2 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Transaction</p>
            <p className="font-mono text-sm text-primary">{data.transaction_type}</p>
            <p className="text-xs text-muted-foreground">Type of activity (purchase, donation, etc.)</p>
          </div>
          <div className="min-w-0 space-y-1 rounded-xl border border-border/50 bg-muted/20 p-3 sm:col-span-2 sm:p-4 lg:col-span-1">
            <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Related record</p>
            <p className="flex items-start gap-2 text-base font-semibold text-foreground sm:text-lg">
              <Receipt className="mt-0.5 h-4 w-4 shrink-0 text-primary/80" aria-hidden />
              <span className="min-w-0 break-words">{data.related_record}</span>
            </p>
          </div>
        </div>

        <div className={cn("grid min-w-0 gap-3 sm:gap-4", data.module === "believe_points" ? "" : "lg:grid-cols-2")}>
          <div className="min-w-0 rounded-xl border border-border/50 bg-background/80 p-3 shadow-sm sm:p-4">
            <div className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
              <User className="h-3.5 w-3.5 shrink-0" aria-hidden />
              {data.module === "believe_points" ? "Purchaser" : `From (${data.from_type})`}
            </div>
            {data.module !== "believe_points" && <p className="mb-2 text-[10px] text-muted-foreground">Payer</p>}
            <p className="break-words text-base font-semibold text-foreground">{data.from_name ?? "—"}</p>
            <p className="break-all text-sm text-muted-foreground">{data.from_email ?? "—"}</p>
            {data.from_id != null && <p className="mt-1 font-mono text-[11px] text-muted-foreground">User ID {data.from_id}</p>}
          </div>
          {data.module !== "believe_points" && (
            <div className="min-w-0 rounded-xl border border-border/50 bg-background/80 p-3 shadow-sm sm:p-4">
              <div className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                <Building2 className="h-3.5 w-3.5 shrink-0" aria-hidden />
                To ({data.to_type})
              </div>
              <p className="mb-2 text-[10px] text-muted-foreground">Recipient</p>
              <p className="break-words text-base font-semibold text-foreground">{data.to_name ?? "—"}</p>
              <p className="break-all text-sm text-muted-foreground">{data.to_email ?? "—"}</p>
              {data.to_id != null && <p className="mt-1 font-mono text-[11px] text-muted-foreground">ID {data.to_id}</p>}
            </div>
          )}
        </div>

        <div className="min-w-0 rounded-xl border border-border/50 bg-muted/15 p-3 sm:p-4">
          <div className="mb-3 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground sm:mb-4">
            <Wallet className="h-3.5 w-3.5 shrink-0" aria-hidden />
            Amounts
          </div>
          <div className="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
            <Amount
              label="Subtotal"
              value={ledgerAmountNode(isBpLedger, isBrpLedger ? null : data.subtotal_amount, cur, true)}
              hint="Product/service price. On catalog lines: Subtotal ≈ Supplier cost + Markup."
            />
            <Amount
              label="Sales tax"
              value={ledgerAmountNode(isBpLedger, isBrpLedger ? null : data.sales_tax_amount, cur, true)}
              hint="Sales tax (pass-through; not platform revenue)."
            />
            <Amount
              label="Shipping"
              value={ledgerAmountNode(isBpLedger, isBrpLedger ? null : data.shipping_amount, cur, true)}
              hint="Delivery charge (pass-through or margin, depending on setup)."
            />
            {showMarkupDollars &&
            data.supplier_cost_amount != null &&
            data.supplier_cost_amount !== undefined &&
            Number.isFinite(Number(data.supplier_cost_amount)) ? (
              <Amount
                label="Supplier cost"
                value={<span className="text-sm font-semibold tabular-nums text-foreground">{formatMoney(Number(data.supplier_cost_amount), cur)}</span>}
                hint="Base cost on catalog lines: stored source cost, or line ÷ (1 + markup%÷100). Workbook: Supplier cost = Subtotal ÷ (1 + markup%) on a uniform line."
              />
            ) : null}
            {data.selling_price_markup_percent != null && data.selling_price_markup_percent !== undefined ? (
              <Amount
                label="Markup"
                value={
                  <span className="text-sm font-semibold tabular-nums text-foreground">
                    {formatMarkupPercent(data.selling_price_markup_percent)}
                    {showMarkupDollars &&
                    data.selling_price_markup_amount != null &&
                    data.selling_price_markup_amount !== undefined &&
                    Number.isFinite(Number(data.selling_price_markup_amount)) ? (
                      <>
                        {" "}
                        <span className="font-normal text-muted-foreground">·</span>{" "}
                        {formatMoney(Number(data.selling_price_markup_amount), cur)}
                      </>
                    ) : null}
                  </span>
                }
                hint="Profit on catalog lines (margin % from first product row; dollars = Σ line retail − cost). Workbook: Markup = Subtotal − Supplier cost."
              />
            ) : null}
            {(data.supplier_name != null && data.supplier_name !== "") ||
            (data.supplier_type != null && data.supplier_type !== "") ? (
              <>
                <Amount
                  label="Supplier name"
                  value={<span className="text-sm font-semibold text-foreground">{data.supplier_name ?? "—"}</span>}
                />
                <Amount
                  label="Supplier type"
                  value={
                    data.supplier_type != null && data.supplier_type !== "" ? (
                      <Badge
                        variant="outline"
                        title={data.supplier_type}
                        className={cn(
                          "h-auto w-fit min-w-0 justify-start gap-1.5 px-2 py-1.5 font-mono text-[10px] font-semibold uppercase leading-none tracking-wide",
                          supplierTypeBadgeClass(data.supplier_type),
                        )}
                      >
                        {supplierTypeIcon(data.supplier_type)}
                        <span className="min-w-0 truncate">{supplierTypeDisplayLabel(data.supplier_type)}</span>
                      </Badge>
                    ) : (
                      <span className="text-sm text-muted-foreground">—</span>
                    )
                  }
                />
              </>
            ) : null}
            {usePoints && !isBrpLedger && data.wallet_amount != null && Number.isFinite(Number(data.wallet_amount)) ? (
              <Amount
                label="Wallet amt"
                value={ledgerAmountNode(true, data.wallet_amount, cur, false, true, "bp")}
                emphasis
                hint="Signed change to supporter BP balance (ending − beginning). Not the same as Gross/Subtotal."
              />
            ) : isBrpLedger && data.wallet_amount != null && Number.isFinite(Number(data.wallet_amount)) ? (
              <Amount
                label="Wallet amt"
                value={ledgerAmountNode(false, data.wallet_amount, cur, false, true, "brp")}
                emphasis
                hint="Signed change to supporter BRP balance for this row."
              />
            ) : null}
            <Amount
              label="Gross"
              value={ledgerAmountNode(isBpLedger, isBrpLedger ? null : data.gross_amount, cur, false, !isBpLedger || data.wallet_amount == null)}
              emphasis={!isBpLedger || data.wallet_amount == null}
              hint="Total paid. Gross = Subtotal + Shipping + Tax."
            />
            <div className="min-w-0 space-y-2 sm:col-span-2 lg:col-span-2">
              <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">Payment Provider Fee (Stripe + Bridge)</p>
              {data.provider === "points" ? (
                <>
                  <p className="tabular-nums text-sm font-semibold text-muted-foreground">—</p>
                  <Badge
                    variant="outline"
                    className={cn("items-center justify-start gap-1 text-[10px] font-medium leading-none", providerBadgeClass("points"))}
                  >
                    <Coins className="h-3 w-3 shrink-0" aria-hidden />
                    <span className="leading-none">No Fee</span>
                  </Badge>
                </>
              ) : (
                <>
                  <p className="tabular-nums text-sm font-semibold text-foreground">{formatMoney(data.processor_fee_amount, cur)}</p>
                  <p className="text-pretty text-[10px] leading-snug text-muted-foreground">
                    Workbook example: Payment provider fee = Subtotal × processor % — here you see actual Stripe + Bridge from the transaction.
                  </p>
                  <div className="flex flex-wrap gap-2">
                    <Badge variant="outline" className={cn("tabular-nums text-xs font-semibold", providerBadgeClass("stripe"))}>
                      Stripe {formatMoney(data.stripe_fee_amount, cur)}
                    </Badge>
                    <Badge variant="outline" className={cn("tabular-nums text-xs font-semibold", providerBadgeClass("bridge"))}>
                      Bridge {formatMoney(data.bridge_fee_amount, cur)}
                    </Badge>
                  </div>
                </>
              )}
            </div>
            <Amount
              label="Platform fee (BIU)"
              value={ledgerAmountNode(isBpLedger, isBrpLedger ? null : data.biu_fee_amount, cur)}
              hint="Workbook example: Platform fee = Subtotal × platform % — this row shows recorded BIU / platform fee from the ledger."
            />
            <Amount label="Split" value={ledgerAmountNode(isBpLedger, isBrpLedger ? null : data.split_amount, cur, true)} />
            <Amount label="Refund" value={ledgerAmountNode(isBpLedger, isBrpLedger ? null : data.refund_amount, cur, true)} />
            <Amount
              label="Net"
              value={ledgerAmountNode(isBpLedger, isBrpLedger ? brpPointsAmount : data.net_amount, cur, false, true, isBrpLedger ? "brp" : isBpLedger ? "bp" : null)}
              emphasis
              className="sm:col-span-2 md:col-span-2 lg:col-span-1"
              hint="Settled total for this row (flow-specific; not always equal to Org payout alone — see settlement rows below)."
            />
            {sellingPayoutsVisible(data) ? (
              <div className="col-span-full mt-1 grid min-w-0 grid-cols-1 gap-3 border-t border-border/40 pt-4 sm:grid-cols-2 lg:grid-cols-4">
                <Amount
                  label="Supplier payout"
                  value={ledgerAmountNode(isBpLedger, isBrpLedger ? null : data.supplier_payout_amount, cur, true)}
                  hint="Merchant / cost slice (full split; fees do not reduce this when a nonprofit share exists)"
                />
                <Amount
                  label="Platform payout"
                  value={ledgerAmountNode(isBpLedger, isBrpLedger ? null : data.platform_payout_amount, cur, true)}
                  hint="BIU platform fee + split share retained"
                />
                <Amount
                  label="Organization payout"
                  value={ledgerAmountNode(isBpLedger, isBrpLedger ? null : data.organization_payout_amount, cur, true)}
                  hint="What the org receives after fees in this split. Workbook shorthand: Org payout ≈ Subtotal − payment provider fee − platform fee (fees come from payout, not charged again to the buyer)."
                />
                <Amount
                  label="Supporter payout"
                  value={ledgerAmountNode(isBpLedger, isBrpLedger ? null : data.supporter_payout_amount ?? null, cur, true)}
                  hint="Instructor / supporter share when recorded on the transaction (meta)"
                />
              </div>
            ) : null}
          </div>
          {data.module === "marketplace" && (
            <p className="mt-3 text-xs leading-relaxed text-muted-foreground">
              <span className="font-medium text-foreground">Gross</span> is the buyer&apos;s checkout total (subtotal + tax + shipping).
              <span className="mx-1">·</span>
              <span className="font-medium text-foreground">Split</span> is the nonprofit&apos;s <span className="font-medium">gross</span> markup
              share (before fees). <span className="font-medium text-foreground">Supplier payout</span> matches the merchant/cost slice in full —
              payment provider fees do not reduce it. <span className="font-medium text-foreground">Platform payout</span> includes order platform fee plus
              any BIU line split. <span className="font-medium text-foreground">Organization payout</span> is that markup minus payment provider fee
              and the order platform fee (fees are not added on top for the buyer). <span className="font-medium text-foreground">Net</span> is
              supplier + organization payout. BIU fee in the grid is BIU split + order platform fee.
            </p>
          )}
          {data.module === "servicehub" && sellingPayoutsVisible(data) && (
            <p className="mt-3 text-xs leading-relaxed text-muted-foreground">
              <span className="font-medium text-foreground">Payment provider fee</span> is deducted from seller earnings (buyer is not charged extra
              for it). <span className="font-medium text-foreground">Supplier payout</span> is that net to the seller;{" "}
              <span className="font-medium text-foreground">platform payout</span> is platform plus transaction fees;{" "}
              <span className="font-medium text-foreground">Net</span> matches seller settlement for this row.
            </p>
          )}
        </div>

        <div className="flex min-w-0 flex-col gap-3 rounded-xl border border-border/50 bg-background/90 p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4">
          <div className="flex min-w-0 flex-1 items-start gap-3">
            <CreditCard className="mt-0.5 h-5 w-5 shrink-0 text-primary/80" aria-hidden />
            <div className="min-w-0 flex-1">
              <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Reference</p>
              <p className="break-all font-mono text-sm text-foreground">{data.reference}</p>
            </div>
          </div>
          <div className="flex min-w-0 shrink-0 items-start gap-3 sm:text-right">
            {data.provider === "points" ? (
              <Coins className="mt-0.5 h-5 w-5 text-amber-600 dark:text-amber-400 sm:order-2" aria-hidden />
            ) : (
              <Landmark className="mt-0.5 h-5 w-5 text-primary/80 sm:order-2" />
            )}
            <div className="min-w-0 sm:order-1">
              <p className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Provider rail</p>
              <p className="text-sm font-medium text-foreground">
                <ProviderRailLabel provider={data.provider} />
              </p>
              {(data.bridge_fee_amount > 0 || data.provider === "bridge") && data.provider !== "points" && (
                <p className="mt-1 text-xs text-muted-foreground">Bridge fees or virtual-account flows appear in Payment Provider Fee / provider rail.</p>
              )}
              {data.provider === "points" && (
                <p className="mt-1 text-xs text-muted-foreground">Paid from the donor&apos;s Believe Points balance (not card or bank).</p>
              )}
            </div>
          </div>
        </div>
      </CardContent>
    </Card>
  )
}

function Amount({
  label,
  value,
  emphasis,
  hint,
  className,
}: {
  label: string
  value: ReactNode
  emphasis?: boolean
  hint?: string
  className?: string
}) {
  return (
    <div className={cn("min-w-0 space-y-0.5", className)}>
      <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
      <div className={cn("min-w-0 break-words tabular-nums text-foreground", emphasis ? "text-lg font-bold" : "text-sm font-semibold")}>
        {value}
      </div>
      {hint ? <p className="text-pretty text-[10px] leading-snug text-muted-foreground">{hint}</p> : null}
    </div>
  )
}
