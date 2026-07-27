"use client"

import { router } from "@inertiajs/react"
import {
  ArrowDownLeft,
  ArrowUpRight,
  ChevronLeft,
  ChevronRight,
  Gift,
  History,
  Minus,
  Plus,
  RefreshCw,
  Scale,
  ShoppingCart,
  Wallet,
} from "lucide-react"
import type { LucideIcon } from "lucide-react"

import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"

export interface BpWalletLedgerRow {
  id: string
  date: string
  transaction_number: string
  description: string
  entry_type: string
  debit: number | null
  credit: number | null
  bp_change?: number | null
  processing_balance: number
  available_balance: number
  gifted_balance: number
  running_balance: number
  /** When this BP is expected to (or did) become Available — ISO datetime */
  available_on?: string | null
}

export interface BpWalletLedgerPagination {
  data: BpWalletLedgerRow[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  from: number | null
  to: number | null
}

const fmt = (value: number | null | undefined) =>
  value == null
    ? "—"
    : Number(value).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const fmtDateParts = (iso: string) => {
  const d = new Date(iso)
  return {
    date: d.toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" }),
    time: d.toLocaleTimeString(undefined, { hour: "2-digit", minute: "2-digit" }),
  }
}

function formatAvailableOn(iso: string | null | undefined): { label: string; sub?: string } | null {
  if (!iso) return null
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return null

  const dateLabel = d.toLocaleString(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  })
  const ms = d.getTime() - Date.now()

  if (ms <= 0) {
    return { label: dateLabel, sub: "Available" }
  }

  const hours = Math.ceil(ms / (1000 * 60 * 60))
  if (hours < 24) {
    return { label: dateLabel, sub: hours <= 1 ? "Soon" : `In ~${hours}h` }
  }

  return { label: dateLabel }
}

function AvailableOnBesideBadge({ row }: { row: BpWalletLedgerRow }) {
  const formatted = formatAvailableOn(row.available_on)
  if (!formatted) return null

  const isPending =
    row.entry_type === "purchase_processing" &&
    row.available_on != null &&
    new Date(row.available_on).getTime() > Date.now()

  return (
    <span
      className={cn(
        "inline-flex items-center gap-1 text-[11px] font-medium tabular-nums",
        isPending
          ? "text-amber-700 dark:text-amber-300"
          : "text-muted-foreground",
      )}
    >
      <span className="text-muted-foreground/70">Available on</span>
      <span>{formatted.label}</span>
      {formatted.sub && isPending && (
        <span className="font-normal opacity-80">({formatted.sub})</span>
      )}
    </span>
  )
}

function friendlyTransactionNumber(raw: string, entryType: string): string {
  const value = (raw || "").trim()
  if (!value) return "—"
  // Hide Stripe / Bridge style refs from supporters
  if (/^(pi_|ch_|py_|txn_|po_|btxn_)/i.test(value) || value.length > 36) {
    if (entryType === "purchase_processing" || entryType === "purchase") return "Purchase"
    if (entryType === "settlement") return "Available"
    if (entryType === "adjustment" || entryType === "admin_adjustment") return "Adjustment"
    return "Reference"
  }
  return value
}

function resolveBpChange(row: BpWalletLedgerRow): number {
  if (row.bp_change != null && !Number.isNaN(Number(row.bp_change))) {
    return Number(row.bp_change)
  }
  if (row.entry_type === "wallet_transfer_refund" || (row.credit != null && row.credit > 0)) {
    return Number(row.credit ?? 0)
  }
  if (row.entry_type === "wallet_transfer" || (row.debit != null && row.debit > 0)) {
    return -Number(row.debit ?? 0)
  }
  if (row.credit != null && row.credit > 0) return Number(row.credit)
  if (row.debit != null && row.debit > 0) return -Number(row.debit)
  return 0
}

type EntryMeta = {
  label: string
  icon: LucideIcon
  badgeClass: string
  iconWrapClass: string
}

/** Matches Believe Points page badges / activity list (Index.tsx, BpBalanceHero). */
function entryMeta(entryType: string): EntryMeta {
  const iconDefault = "bg-gradient-to-br from-purple-500/15 to-blue-500/15 text-purple-600 dark:text-purple-400"

  switch (entryType) {
    case "purchase_processing":
    case "purchase":
      return {
        label: "Purchase",
        icon: ShoppingCart,
        badgeClass:
          "border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200",
        iconWrapClass: iconDefault,
      }
    case "settlement":
      return {
        label: "Available",
        icon: RefreshCw,
        badgeClass: "border-0 bg-emerald-600 text-white hover:bg-emerald-600/90",
        iconWrapClass: iconDefault,
      }
    case "wallet_transfer":
      return {
        label: "To wallet",
        icon: Wallet,
        badgeClass: "border-border bg-muted/60 text-muted-foreground",
        iconWrapClass: "bg-purple-500/10 text-purple-600 dark:bg-purple-950/30 dark:text-purple-400",
      }
    case "wallet_transfer_refund":
      return {
        label: "Returned",
        icon: ArrowDownLeft,
        badgeClass: "border-border bg-card text-foreground",
        iconWrapClass: iconDefault,
      }
    case "gift_sent":
    case "gift_received":
      return {
        label: entryType === "gift_sent" ? "Gift sent" : "Gift received",
        icon: Gift,
        badgeClass:
          "border-violet-200/80 bg-violet-50/80 text-violet-800 dark:border-violet-800/50 dark:bg-violet-950/25 dark:text-violet-200",
        iconWrapClass: iconDefault,
      }
    case "refund":
      return {
        label: "Refund",
        icon: RefreshCw,
        badgeClass: "border-border bg-card text-foreground",
        iconWrapClass: iconDefault,
      }
    case "adjustment":
    case "admin_adjustment":
      return {
        label: "Adjustment",
        icon: Scale,
        badgeClass:
          "border-slate-200 bg-slate-100 text-slate-800 dark:border-slate-700 dark:bg-slate-900/50 dark:text-slate-200",
        iconWrapClass: iconDefault,
      }
    default:
      return {
        label: "Activity",
        icon: History,
        badgeClass: "border-border bg-muted/60 text-muted-foreground",
        iconWrapClass: iconDefault,
      }
  }
}

function ChangeCell({ row }: { row: BpWalletLedgerRow }) {
  const change = resolveBpChange(row)
  if (change === 0) {
    return <span className="text-sm tabular-nums text-muted-foreground">—</span>
  }

  const isCredit =
    row.entry_type === "wallet_transfer_refund" ||
    change > 0 ||
    (row.credit != null && row.credit > 0 && !(row.debit != null && row.debit > 0))

  return (
    <div className="flex items-center justify-end gap-1.5">
      <span
        className={cn(
          "inline-flex h-6 w-6 items-center justify-center rounded-full",
          isCredit
            ? "bg-emerald-100 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"
            : "bg-muted text-muted-foreground",
        )}
      >
        {isCredit ? <Plus className="h-3.5 w-3.5" /> : <Minus className="h-3.5 w-3.5" />}
      </span>
      <span
        className={cn(
          "text-sm font-semibold tabular-nums",
          isCredit ? "text-emerald-700 dark:text-emerald-300" : "text-foreground",
        )}
      >
        {isCredit ? "+" : "−"}
        {fmt(Math.abs(change))}
      </span>
    </div>
  )
}

function LedgerRow({ row, index }: { row: BpWalletLedgerRow; index: number }) {
  const { date, time } = fmtDateParts(row.date)
  const meta = entryMeta(row.entry_type)
  const Icon = meta.icon

  return (
    <tr
      className={cn(
        "group border-b border-border/40 transition-colors last:border-0 hover:bg-muted/40",
        index % 2 === 1 && "bg-muted/10",
      )}
    >
      <td className="whitespace-nowrap px-4 py-3.5 align-top">
        <div className="text-sm font-medium text-foreground">{date}</div>
        <div className="text-xs tabular-nums text-muted-foreground">{time}</div>
      </td>

      <td className="min-w-[220px] px-4 py-3.5 align-top">
        <div className="flex items-start gap-2.5">
          <div
            className={cn(
              "mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg",
              meta.iconWrapClass,
            )}
          >
            <Icon className="h-4 w-4" aria-hidden />
          </div>
          <div className="min-w-0 space-y-1.5">
            <div className="flex flex-wrap items-center gap-2">
              <Badge variant="outline" className={cn("text-[10px] font-semibold uppercase tracking-wide", meta.badgeClass)}>
                {meta.label}
              </Badge>
              <AvailableOnBesideBadge row={row} />
            </div>
            <p className="text-sm font-medium leading-snug text-foreground">{row.description}</p>
            <p
              className="truncate font-mono text-[11px] text-muted-foreground"
              title={friendlyTransactionNumber(row.transaction_number, row.entry_type)}
            >
              {friendlyTransactionNumber(row.transaction_number, row.entry_type)}
            </p>
          </div>
        </div>
      </td>

      <td className="whitespace-nowrap px-4 py-3.5 text-right align-top">
        <ChangeCell row={row} />
      </td>

      <td className="hidden whitespace-nowrap px-4 py-3.5 text-right align-top xl:table-cell">
        <span className="text-sm tabular-nums text-destructive/90">
          {row.entry_type === "wallet_transfer_refund" ? "—" : row.debit != null && row.debit > 0 ? fmt(row.debit) : "—"}
        </span>
      </td>

      <td className="hidden whitespace-nowrap px-4 py-3.5 text-right align-top xl:table-cell">
        <span className="text-sm tabular-nums text-emerald-700 dark:text-emerald-300">
          {row.entry_type === "wallet_transfer" && row.credit == null
            ? "—"
            : row.credit != null && row.credit > 0
              ? fmt(row.credit)
              : "—"}
        </span>
      </td>

      <td className="whitespace-nowrap px-4 py-3.5 text-right align-top">
        <span className="bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-sm font-bold tabular-nums text-transparent">
          {fmt(row.running_balance)}
        </span>
        <span className="ml-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">BP</span>
      </td>

      <td className="whitespace-nowrap px-4 py-3.5 text-right align-top">
        <span className="inline-flex items-center justify-end gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium tabular-nums text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">
          <ArrowUpRight className="h-3 w-3 shrink-0 opacity-70" aria-hidden />
          {fmt(row.processing_balance)}
        </span>
      </td>

      <td className="whitespace-nowrap px-4 py-3.5 text-right align-top">
        <span className="inline-flex items-center justify-end gap-1 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium tabular-nums text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300">
          <ArrowDownLeft className="h-3 w-3 shrink-0 opacity-70" aria-hidden />
          {fmt(row.available_balance)}
        </span>
      </td>
    </tr>
  )
}

const PER_PAGE_OPTIONS = [10, 25, 50, 100] as const

function getVisiblePages(currentPage: number, totalPages: number): (number | "...")[] {
  if (totalPages <= 1) return [1]
  if (totalPages <= 7) {
    return Array.from({ length: totalPages }, (_, i) => i + 1)
  }

  const pages: (number | "...")[] = [1]
  const start = Math.max(2, currentPage - 1)
  const end = Math.min(totalPages - 1, currentPage + 1)

  if (start > 2) pages.push("...")
  for (let page = start; page <= end; page += 1) pages.push(page)
  if (end < totalPages - 1) pages.push("...")
  pages.push(totalPages)

  return pages
}

function LedgerPagination({
  ledger,
  onPageChange,
  onPerPageChange,
}: {
  ledger: BpWalletLedgerPagination
  onPageChange: (page: number) => void
  onPerPageChange: (perPage: number) => void
}) {
  const pages = getVisiblePages(ledger.current_page, ledger.last_page)

  return (
    <div className="flex flex-col gap-3 border-t border-border/60 bg-muted/20 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
        <p className="text-sm text-muted-foreground">
          Showing{" "}
          <span className="font-semibold tabular-nums text-foreground">
            {ledger.from ?? 0}–{ledger.to ?? 0}
          </span>{" "}
          of <span className="font-semibold tabular-nums text-foreground">{ledger.total}</span> entries
        </p>
        <label className="flex items-center gap-2 text-sm text-muted-foreground">
          <span className="whitespace-nowrap">Rows per page</span>
          <select
            value={ledger.per_page}
            onChange={(e) => onPerPageChange(Number(e.target.value))}
            className="h-8 rounded-md border border-border/60 bg-background px-2 text-sm text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500/20"
            aria-label="Rows per page"
          >
            {PER_PAGE_OPTIONS.map((option) => (
              <option key={option} value={option}>
                {option}
              </option>
            ))}
          </select>
        </label>
      </div>

      <div className="flex flex-wrap items-center justify-end gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 gap-1"
          disabled={ledger.current_page <= 1}
          onClick={() => onPageChange(ledger.current_page - 1)}
        >
          <ChevronLeft className="h-4 w-4" />
          Previous
        </Button>

        <div className="flex items-center gap-1 rounded-full border border-border/50 bg-background/80 p-1">
          {pages.map((page, index) =>
            page === "..." ? (
              <span key={`ellipsis-${index}`} className="px-2 text-sm text-muted-foreground">
                …
              </span>
            ) : (
              <Button
                key={page}
                type="button"
                variant={ledger.current_page === page ? "default" : "ghost"}
                size="sm"
                className={cn(
                  "h-8 min-w-8 rounded-full px-2.5 tabular-nums",
                  ledger.current_page === page &&
                    "border-0 bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm hover:from-purple-700 hover:to-blue-700",
                )}
                onClick={() => onPageChange(page)}
              >
                {page}
              </Button>
            ),
          )}
        </div>

        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 gap-1"
          disabled={ledger.current_page >= ledger.last_page}
          onClick={() => onPageChange(ledger.current_page + 1)}
        >
          Next
          <ChevronRight className="h-4 w-4" />
        </Button>
      </div>
    </div>
  )
}

export function BpWalletLedger({ ledger }: { ledger: BpWalletLedgerPagination }) {
  const navigate = (page: number, perPage: number = ledger.per_page) => {
    const nextPage = Math.max(1, Math.min(page, ledger.last_page || 1))
    router.get(
      route("believe-points.index"),
      { ledger_page: nextPage, ledger_per_page: perPage },
      { preserveScroll: true, preserveState: true, only: ["walletLedger"] },
    )
  }

  const goToPage = (page: number) => navigate(page)
  const changePerPage = (perPage: number) => navigate(1, perPage)

  if (!ledger.data.length) {
    return (
      <div className="flex flex-col items-center justify-center rounded-xl border border-dashed border-border/80 bg-muted/20 px-6 py-14 text-center">
        <div className="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-gradient-to-br from-purple-500/15 to-blue-500/15">
          <History className="h-8 w-8 text-purple-600 dark:text-purple-400" aria-hidden />
        </div>
        <p className="text-sm font-semibold text-foreground">No wallet activity yet</p>
        <p className="mt-1 max-w-sm text-sm leading-relaxed text-muted-foreground">
          Purchases, Available dates, and wallet transfers will appear here with running Processing and Available
          balances.
        </p>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="overflow-hidden rounded-xl border border-border/60 bg-card shadow-sm">
        <div className="overflow-x-auto">
          <table className="w-full min-w-[960px] border-collapse text-left">
            <thead>
              <tr className="border-b border-border bg-muted/30">
                <th className="px-4 py-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Date
                </th>
                <th className="px-4 py-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Activity
                </th>
                <th className="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Change
                </th>
                <th className="hidden px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground xl:table-cell">
                  Debit
                </th>
                <th className="hidden px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground xl:table-cell">
                  Credit
                </th>
                <th className="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Running balance
                </th>
                <th className="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Processing
                </th>
                <th className="px-4 py-3 text-right text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  Available
                </th>
              </tr>
            </thead>
            <tbody>
              {ledger.data.map((row, index) => (
                <LedgerRow key={row.id} row={row} index={index} />
              ))}
            </tbody>
          </table>
        </div>

        <LedgerPagination ledger={ledger} onPageChange={goToPage} onPerPageChange={changePerPage} />
      </div>
    </div>
  )
}
