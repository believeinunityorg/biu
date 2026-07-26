import { CalendarClock, Coins } from "lucide-react"
import { cn } from "@/lib/utils"

export type ProcessingBpBatch = {
  amount: number
  available_on: string | null
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

/** Compact single-line Available On for narrow Processing columns / popups */
export function formatProcessingBatchWhen(iso: string | null | undefined): string {
  const parts = formatBatchDateParts(iso)
  if (!parts) return "TBD"
  const shortDate = parts.date.replace(/,\s*\d{4}$/, "")
  return `${shortDate} · ${parts.time}`
}

export function formatProcessingReleaseLabel(iso: string | null | undefined): string | null {
  const parts = formatBatchDateParts(iso)
  if (!parts) return null
  return `${parts.date}, ${parts.time}`
}

export function ProcessingBatchesList({
  batches,
  formatPoints,
  /** `centered` = amount | Available On | date (BP page). `compact` = amount … Available On date (header popup). */
  variant = "centered",
}: {
  batches: ProcessingBpBatch[]
  formatPoints: (value: number | string) => string
  variant?: "centered" | "compact"
}) {
  return (
    <ul className="mt-2 flex w-full min-w-0 flex-col gap-1">
      {batches.map((batch, i) => (
        <li
          key={`${batch.available_on ?? "tbd"}-${i}`}
          className={cn(
            "h-7 w-full min-w-0 items-center overflow-hidden rounded-full border border-amber-200/80 bg-gradient-to-r from-amber-50/90 via-amber-50/50 to-amber-50/90 px-2 text-[10px] leading-none shadow-sm dark:border-amber-800/60 dark:from-amber-950/50 dark:via-amber-950/30 dark:to-amber-950/50",
            variant === "centered"
              ? "grid grid-cols-[1fr_auto_1fr] gap-x-1"
              : "flex justify-between gap-2 px-2.5",
          )}
        >
          <span
            className={cn(
              "flex items-center gap-1 whitespace-nowrap font-bold tabular-nums text-amber-950 dark:text-amber-50",
              variant === "centered" ? "justify-self-start" : "shrink-0",
            )}
          >
            <Coins className="h-3 w-3 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />
            <span>
              {formatPoints(batch.amount)}
              <span className="ml-0.5 font-semibold text-amber-700/80 dark:text-amber-300/80">BP</span>
            </span>
          </span>
          {variant === "centered" ? (
            <>
              <span className="flex items-center justify-center gap-1 justify-self-center whitespace-nowrap text-[9px] font-medium uppercase tracking-wide text-muted-foreground">
                <CalendarClock className="h-3 w-3 shrink-0 text-amber-700/80 dark:text-amber-300/80" aria-hidden />
                <span>Available On</span>
              </span>
              <span className="justify-self-end whitespace-nowrap text-right font-semibold tabular-nums text-amber-900 dark:text-amber-100">
                {formatProcessingBatchWhen(batch.available_on)}
              </span>
            </>
          ) : (
            <span className="flex min-w-0 items-center justify-end gap-1 whitespace-nowrap text-right">
              <CalendarClock className="h-3 w-3 shrink-0 text-amber-700/80 dark:text-amber-300/80" aria-hidden />
              <span className="text-[9px] font-medium uppercase tracking-wide text-muted-foreground">
                Available On
              </span>
              <span className="font-semibold tabular-nums text-amber-900 dark:text-amber-100">
                {formatProcessingBatchWhen(batch.available_on)}
              </span>
            </span>
          )}
        </li>
      ))}
    </ul>
  )
}
