import { CalendarClock, Clock, Coins, Gift, Info, Plus, RefreshCw, Wallet } from "lucide-react"
import { motion } from "framer-motion"
import { cn } from "@/lib/utils"

type BpBalanceHeroProps = {
  balance: number
  processingBalance: number
  processingReleaseHint?: string | null
  /** Open processing lots grouped by Available On date */
  processingBatches?: Array<{ amount: number; available_on: string | null }>
  giftedBalance?: number
  holdingBalance?: number
  formatPoints: (value: number | string) => string
  onRefunds: () => void
  onAddPoints: () => void
  showWalletAction?: boolean
  onMoveToWallet?: () => void
}

type QuickAction = {
  id: string
  label: string
  icon: typeof Plus
  onClick: () => void
}

function formatBatchDateParts(iso: string | null | undefined): { date: string; time: string } | null {
  if (!iso) return null
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return null
  return {
    date: d.toLocaleDateString(undefined, {
      month: "short",
      day: "numeric",
      year: "numeric",
    }),
    time: d.toLocaleTimeString(undefined, {
      hour: "numeric",
      minute: "2-digit",
    }),
  }
}

function formatBatchWhen(iso: string | null | undefined): string {
  const parts = formatBatchDateParts(iso)
  if (!parts) return "TBD"
  // Compact single-line label for narrow Processing column
  const shortDate = parts.date.replace(/,\s*\d{4}$/, "")
  return `${shortDate} · ${parts.time}`
}

function ProcessingBatchesList({
  batches,
  formatPoints,
}: {
  batches: Array<{ amount: number; available_on: string | null }>
  formatPoints: (value: number | string) => string
}) {
  return (
    <ul className="mt-2 flex w-full flex-col gap-1">
      {batches.map((batch, i) => (
        <li
          key={`${batch.available_on ?? "tbd"}-${i}`}
          className="grid h-7 w-full grid-cols-[1fr_auto_1fr] items-center gap-x-1 rounded-full border border-amber-200/80 bg-gradient-to-r from-amber-50/90 via-amber-50/50 to-amber-50/90 px-2 text-[10px] leading-none shadow-sm dark:border-amber-800/60 dark:from-amber-950/50 dark:via-amber-950/30 dark:to-amber-950/50"
        >
          <span className="flex items-center gap-1 justify-self-start whitespace-nowrap font-bold tabular-nums text-amber-950 dark:text-amber-50">
            <Coins className="h-3 w-3 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />
            <span>
              {formatPoints(batch.amount)}
              <span className="ml-0.5 font-semibold text-amber-700/80 dark:text-amber-300/80">BP</span>
            </span>
          </span>
          <span className="flex items-center justify-center gap-1 justify-self-center whitespace-nowrap text-[9px] font-medium uppercase tracking-wide text-muted-foreground">
            <CalendarClock className="h-3 w-3 shrink-0 text-amber-700/80 dark:text-amber-300/80" aria-hidden />
            <span>Available On</span>
          </span>
          <span className="justify-self-end whitespace-nowrap text-right font-semibold tabular-nums text-amber-900 dark:text-amber-100">
            {formatBatchWhen(batch.available_on)}
          </span>
        </li>
      ))}
    </ul>
  )
}

function BalanceColumn({
  label,
  value,
  availableOnLabel,
  availableOnDate,
  batches,
  formatPoints,
  badge,
  valueClassName,
  contentAlign = "start",
}: {
  label: string
  value: string
  availableOnLabel?: string | null
  availableOnDate?: string | null
  batches?: Array<{ amount: number; available_on: string | null }>
  formatPoints?: (value: number | string) => string
  badge?: { text: string; className: string }
  valueClassName: string
  contentAlign?: "start" | "center"
}) {
  const centered = contentAlign === "center"

  return (
    <div
      className={cn(
        "min-w-0 flex-1",
        centered && "flex flex-col items-center justify-center self-stretch text-center",
      )}
    >
      <div
        className={cn(
          "flex items-center gap-1 text-muted-foreground",
          centered && "justify-center",
        )}
      >
        <span className="text-xs font-medium">{label}</span>
        <Info className="h-3 w-3 shrink-0 opacity-50" aria-hidden />
      </div>
      <div
        className={cn(
          "mt-1 flex flex-wrap items-baseline gap-2",
          centered && "justify-center",
        )}
      >
        <span className={cn("text-3xl font-bold tracking-tight tabular-nums sm:text-4xl", valueClassName)}>
          {value}
        </span>
        <span className="text-base font-semibold text-muted-foreground">BP</span>
        {badge && (
          <span
            className={cn(
              "rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide",
              badge.className,
            )}
          >
            {badge.text}
          </span>
        )}
      </div>
      {availableOnDate && (
        <p
          className={cn(
            "mt-1 inline-flex max-w-full items-center gap-1 text-[10px] leading-snug text-amber-800 dark:text-amber-200",
            centered && "justify-center",
          )}
        >
          <CalendarClock className="h-3 w-3 shrink-0 opacity-80" aria-hidden />
          <span className="truncate">
            <span className="text-muted-foreground">{availableOnLabel ?? "Available On"}</span>{" "}
            <span className="font-semibold tabular-nums">{availableOnDate}</span>
          </span>
        </p>
      )}
      {batches && formatPoints && batches.length > 1 ? (
        <ProcessingBatchesList batches={batches} formatPoints={formatPoints} />
      ) : null}
    </div>
  )
}

function SecondaryBalanceTile({
  label,
  note,
  value,
  icon: Icon,
  tone = "purple",
}: {
  label: string
  note?: string
  value: string
  icon: typeof Gift
  tone?: "purple" | "blue"
}) {
  const isPurple = tone === "purple"

  return (
    <div
      className={cn(
        "relative flex min-w-0 items-center gap-2.5 overflow-hidden rounded-xl border px-2.5 py-2.5 shadow-sm",
        isPurple
          ? "border-purple-200/80 bg-gradient-to-br from-purple-50/90 via-white to-blue-50/40 dark:border-purple-800/50 dark:from-purple-950/40 dark:via-gray-950 dark:to-blue-950/20"
          : "border-blue-200/80 bg-gradient-to-br from-blue-50/90 via-white to-purple-50/40 dark:border-blue-800/50 dark:from-blue-950/40 dark:via-gray-950 dark:to-purple-950/20",
      )}
    >
      <div
        aria-hidden
        className={cn(
          "pointer-events-none absolute -right-4 -top-4 h-14 w-14 rounded-full blur-xl",
          isPurple ? "bg-purple-500/15" : "bg-blue-500/15",
        )}
      />
      <div
        className={cn(
          "relative flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-white shadow-sm",
          isPurple
            ? "bg-gradient-to-br from-purple-600 to-purple-500 shadow-purple-600/20"
            : "bg-gradient-to-br from-blue-600 to-blue-500 shadow-blue-600/20",
        )}
      >
        <Icon className="h-3.5 w-3.5" aria-hidden />
      </div>
      <div className="relative min-w-0 flex-1">
        <p className="truncate text-[11px] font-semibold leading-tight text-foreground">{label}</p>
        {note ? (
          <p className="mt-0.5 truncate text-[10px] leading-tight text-muted-foreground">{note}</p>
        ) : null}
      </div>
      <p className="relative shrink-0 text-right">
        <span className="bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-sm font-bold tabular-nums leading-none text-transparent">
          {value}
        </span>
        <span className="ml-1 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">
          BP
        </span>
      </p>
    </div>
  )
}

/** Parse “Available On Jul 29, 2026” → date portion for the labeled row */
function parseAvailableOnDate(hint: string | null | undefined): string | null {
  if (!hint) return null
  const match = hint.match(/^Available On\s+(.+)$/i)
  return match?.[1]?.trim() || hint
}

export function BpBalanceHero({
  balance,
  processingBalance,
  processingReleaseHint,
  processingBatches = [],
  giftedBalance = 0,
  holdingBalance = 0,
  formatPoints,
  onRefunds,
  onAddPoints,
  showWalletAction,
  onMoveToWallet,
}: BpBalanceHeroProps) {
  const totalBalance = balance + processingBalance
  const showBatchList = processingBalance > 0 && processingBatches.length > 0
  const availableOnDate =
    processingBalance > 0 && processingBatches.length <= 1
      ? parseAvailableOnDate(processingReleaseHint)
      : null

  const actions: QuickAction[] = [
    { id: "add", label: "Add BP", icon: Plus, onClick: onAddPoints },
    ...(showWalletAction && onMoveToWallet && balance > 0
      ? [{ id: "wallet", label: "To Wallet", icon: Wallet, onClick: onMoveToWallet }]
      : []),
    { id: "refunds", label: "Refunds", icon: RefreshCw, onClick: onRefunds },
  ]

  const colCount = actions.length

  return (
    <div className="relative overflow-hidden rounded-xl border border-border bg-card shadow-sm">
      <div className="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-purple-600 to-blue-600" />
      <div className="pointer-events-none absolute -right-8 -top-8 h-24 w-24 rounded-full bg-gradient-to-br from-purple-600/10 to-blue-600/5 blur-2xl" />

      <div className="relative p-5 sm:p-6">
        <div className="flex items-start justify-between gap-3">
          <div className="flex min-w-0 items-center gap-2.5">
            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-purple-600/15 to-blue-600/10">
              <Coins className="h-5 w-5 text-purple-600 dark:text-purple-400" />
            </div>
            <div className="min-w-0">
              <p className="text-sm font-semibold text-foreground">Believe Points</p>
              <p className="text-xs text-muted-foreground">
                Total BP:{" "}
                <motion.span
                  key={totalBalance}
                  initial={{ opacity: 0.6 }}
                  animate={{ opacity: 1 }}
                  transition={{ duration: 0.2 }}
                  className="font-semibold tabular-nums text-foreground"
                >
                  {formatPoints(totalBalance)}
                </motion.span>
              </p>
            </div>
          </div>
        </div>

        <div className="mt-5 flex items-stretch gap-4 border-t border-border pt-5">
          <BalanceColumn
            label="Processing"
            value={formatPoints(processingBalance)}
            availableOnLabel={availableOnDate ? "Available On" : null}
            availableOnDate={availableOnDate}
            batches={showBatchList ? processingBatches : undefined}
            formatPoints={formatPoints}
            contentAlign={showBatchList && processingBatches.length > 1 ? "start" : "center"}
            valueClassName="text-amber-700 dark:text-amber-300"
            badge={
              processingBalance > 0
                ? {
                    text: "Processing",
                    className: "bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300",
                  }
                : undefined
            }
          />
          <div className="w-px self-stretch bg-border" />
          <BalanceColumn
            label="Available"
            value={formatPoints(balance)}
            contentAlign="center"
            valueClassName="bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent"
            badge={
              balance > 0
                ? {
                    text: "Available",
                    className: "bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300",
                  }
                : undefined
            }
          />
        </div>

        <div className="mt-4 grid grid-cols-2 gap-2.5 border-t border-border/60 pt-3">
          <SecondaryBalanceTile
            label="On Hold"
            note="Pending invites"
            value={formatPoints(holdingBalance)}
            icon={Clock}
            tone="purple"
          />
          <SecondaryBalanceTile
            label="Gifted"
            note="Received gifts"
            value={formatPoints(giftedBalance)}
            icon={Gift}
            tone="blue"
          />
        </div>

        <div className="mt-4 space-y-1 text-[11px] leading-snug text-muted-foreground">
          <p>
            <span className="font-semibold text-amber-700 dark:text-amber-300">Processing:</span>{" "}
            Funding in progress — see Available On for when it becomes spendable.
          </p>
          <p>
            <span className="font-semibold text-emerald-600 dark:text-emerald-400">Available:</span>{" "}
            Settled funds for all eligible transactions.
          </p>
        </div>
      </div>

      <div className="border-t border-border bg-muted/20 px-3 py-3 sm:px-4">
        <div className={cn("grid gap-1", colCount === 3 ? "grid-cols-3" : "grid-cols-2")}>
          {actions.map((action, index) => {
            const Icon = action.icon
            return (
              <motion.button
                key={action.id}
                type="button"
                initial={{ opacity: 0, y: 4 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.2, delay: index * 0.04 }}
                onClick={action.onClick}
                className="group flex flex-col items-center gap-2 rounded-xl px-2 py-2.5 transition-colors hover:bg-muted/60"
              >
                <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm transition-transform group-hover:scale-105 group-active:scale-95">
                  <Icon className="h-5 w-5" />
                </div>
                <span className="text-[11px] font-medium text-muted-foreground group-hover:text-foreground">
                  {action.label}
                </span>
              </motion.button>
            )
          })}
        </div>
      </div>
    </div>
  )
}
