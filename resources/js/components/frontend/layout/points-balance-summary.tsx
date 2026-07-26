import { Link } from "@inertiajs/react"
import { CalendarClock, ChevronRight, Gift, Info, Sparkles } from "lucide-react"

import {
  formatProcessingReleaseLabel,
  ProcessingBatchesList,
  type ProcessingBpBatch,
} from "@/components/believe-points/ProcessingBatchesList"
import { cn } from "@/lib/utils"

interface PointsUser {
  /** Settled / spendable reward points. */
  reward_points?: number
  available_reward_points?: number
  /** Reward points still processing. */
  processing_reward_points?: number
  /** Total reward points (available + processing). */
  reward_points_total?: number
  believe_points?: number
  processing_believe_points?: number
  processing_believe_points_batches?: ProcessingBpBatch[]
  processing_believe_points_release_at?: string | null
  believe_points_total?: number
  gifted_believe_points?: number
  holding_believe_points?: number
}

const fmt = (value: unknown) =>
  Number(value || 0).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })

function MetricColumn({
  label,
  value,
  badge,
  valueClassName,
  availableOnDate,
  contentAlign = "start",
}: {
  label: string
  value: string
  badge?: { text: string; className: string }
  valueClassName: string
  availableOnDate?: string | null
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
      <div className={cn("flex items-center gap-1 text-muted-foreground", centered && "justify-center")}>
        <span className="text-sm">{label}</span>
        <Info className="h-3.5 w-3.5 shrink-0 opacity-60" aria-hidden />
      </div>
      <div className={cn("mt-1 flex flex-wrap items-center gap-2", centered ? "justify-center" : "justify-start")}>
        <span className={cn("text-xl font-bold tabular-nums", valueClassName)}>{value}</span>
        {badge && (
          <span className={cn("rounded-full px-2 py-0.5 text-xs font-medium leading-none", badge.className)}>
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
          <span className="whitespace-nowrap">
            <span className="text-muted-foreground">Available On</span>{" "}
            <span className="font-semibold tabular-nums">{availableOnDate}</span>
          </span>
        </p>
      )}
    </div>
  )
}

export function PointsBalanceSummary({ user }: { user: PointsUser }) {
  const hasReward =
    user?.reward_points !== undefined ||
    user?.available_reward_points !== undefined ||
    user?.processing_reward_points !== undefined ||
    user?.reward_points_total !== undefined
  const hasBelieve = user?.believe_points !== undefined

  const rewardAvailable = Number(user?.available_reward_points ?? user?.reward_points) || 0
  const rewardProcessing = Number(user?.processing_reward_points) || 0
  // Dashboard total = processing + available (same as Believe Points).
  const rewardTotal =
    user?.reward_points_total !== undefined ? Number(user.reward_points_total) : rewardAvailable + rewardProcessing

  const believeAvailable = Number(user?.believe_points) || 0
  const believeProcessing = Number(user?.processing_believe_points) || 0
  // Dashboard total = processing + available (gifted is shown separately below).
  const believeTotal = believeAvailable + believeProcessing
  const giftedBelieve = Number(user?.gifted_believe_points) || 0
  const holdingBelieve = Number(user?.holding_believe_points) || 0
  const processingBatches = user?.processing_believe_points_batches ?? []
  const hasMultipleBatches = believeProcessing > 0 && processingBatches.length > 1
  const singleAvailableOn =
    believeProcessing > 0 && processingBatches.length <= 1
      ? formatProcessingReleaseLabel(user?.processing_believe_points_release_at) ||
        formatProcessingReleaseLabel(processingBatches[0]?.available_on)
      : null

  return (
    <div className="space-y-3">
      {/* Reward Points */}
      {hasReward && (
        <div className="rounded-xl border border-blue-200 bg-gradient-to-br from-blue-50 to-indigo-50 p-3 dark:border-blue-800 dark:from-blue-950/30 dark:to-indigo-950/20">
          <div className="flex items-center justify-between gap-2">
            <div className="flex min-w-0 items-center gap-3">
              <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-blue-500">
                <Gift className="h-6 w-6 text-white" />
              </div>
              <div className="min-w-0">
                <p className="text-sm text-muted-foreground">Reward Points (BRP)</p>
                <p className="text-2xl font-bold leading-tight text-blue-700 dark:text-blue-300">{fmt(rewardTotal)}</p>
              </div>
            </div>
            <span className="shrink-0 text-base font-semibold text-blue-600 dark:text-blue-400">Earned</span>
          </div>
          <div className="mt-2.5 flex items-stretch gap-3 border-t border-blue-200/70 pt-2.5 dark:border-blue-800/70">
            <MetricColumn
              label="Processing"
              value={fmt(rewardProcessing)}
              valueClassName="text-blue-700 dark:text-blue-300"
              badge={
                rewardProcessing > 0
                  ? {
                      text: "Processing",
                      className: "bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300",
                    }
                  : undefined
              }
            />
            <div className="w-px self-stretch bg-blue-200/70 dark:bg-blue-800/70" />
            <MetricColumn
              label="Available"
              value={fmt(rewardAvailable)}
              valueClassName="text-blue-700 dark:text-blue-300"
              badge={
                rewardAvailable > 0
                  ? {
                      text: "Available",
                      className: "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300",
                    }
                  : undefined
              }
            />
          </div>
        </div>
      )}

      {/* Believe Points — tap to buy / manage */}
      {hasBelieve && (
        <div className="rounded-xl border border-purple-200 bg-gradient-to-br from-purple-50 to-pink-50 p-3 transition-all hover:border-purple-400 dark:border-purple-800 dark:from-purple-950/30 dark:to-pink-950/20 dark:hover:border-purple-600">
          <Link href={route("believe-points.index")} className="flex items-center justify-between gap-2">
            <div className="flex min-w-0 items-center gap-3">
              <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-purple-500">
                <Sparkles className="h-6 w-6 text-white" />
              </div>
              <div className="min-w-0">
                <p className="text-sm text-muted-foreground">Believe Points (BP)</p>
                <p className="text-2xl font-bold leading-tight text-purple-700 dark:text-purple-300">{fmt(believeTotal)}</p>
              </div>
            </div>
            <span className="flex shrink-0 items-center gap-0.5 text-base font-semibold text-purple-600 dark:text-purple-400">
              Buy
              <ChevronRight className="h-5 w-5" />
            </span>
          </Link>
          <div className="mt-2.5 border-t border-purple-200/70 pt-2.5 dark:border-purple-800/70">
            <div className="flex items-stretch gap-3">
              <MetricColumn
                label="Processing"
                value={fmt(believeProcessing)}
                valueClassName="text-amber-700 dark:text-amber-300"
                availableOnDate={singleAvailableOn}
                contentAlign="center"
                badge={
                  believeProcessing > 0
                    ? {
                        text: "Processing",
                        className: "bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300",
                      }
                    : undefined
                }
              />
              <div className="w-px self-stretch bg-purple-200/70 dark:bg-purple-800/70" />
              <MetricColumn
                label="Available"
                value={fmt(believeAvailable)}
                valueClassName="bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent"
                contentAlign="center"
                badge={
                  believeAvailable > 0
                    ? {
                        text: "Available",
                        className: "bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300",
                      }
                    : undefined
                }
              />
            </div>
            {hasMultipleBatches ? (
              <div className="min-w-0">
                <ProcessingBatchesList
                  batches={processingBatches}
                  formatPoints={fmt}
                  variant="compact"
                />
              </div>
            ) : null}
          </div>
          <Link
            href={route("believe-points.index")}
            className="mt-2.5 flex items-center justify-between gap-1 border-t border-purple-200/70 pt-2.5 text-base font-semibold text-amber-600 dark:border-purple-800/70 dark:text-amber-400"
          >
            <span className="flex items-center gap-2">
              <Gift className="h-5 w-5 shrink-0" aria-hidden />
              {fmt(giftedBelieve)} Gifted
            </span>
            <ChevronRight className="h-5 w-5" />
          </Link>
          {holdingBelieve > 0 && (
            <Link
              href="/gift-bp"
              className="mt-2 flex items-center justify-between gap-1 border-t border-purple-200/70 pt-2 text-sm font-semibold text-amber-700 dark:border-purple-800/70 dark:text-amber-300"
            >
              <span>{fmt(holdingBelieve)} Holding (pending invites)</span>
              <ChevronRight className="h-4 w-4" />
            </Link>
          )}
        </div>
      )}

      {/* Legend */}
      <div className="flex gap-2 rounded-lg bg-muted/50 p-2.5 text-[11px] leading-snug">
        <Info className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" aria-hidden />
        <div className="space-y-1">
          <p>
            <span className="font-semibold text-amber-700 dark:text-amber-300">Processing:</span>{" "}
            <span className="text-muted-foreground">
              Funding in progress — see Available On for when it becomes spendable.
            </span>
          </p>
          <p>
            <span className="font-semibold text-emerald-600 dark:text-emerald-400">Available:</span>{" "}
            <span className="text-muted-foreground">Settled funds for all eligible transactions.</span>
          </p>
        </div>
      </div>
    </div>
  )
}
