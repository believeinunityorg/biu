"use client"

import { Plus, Infinity } from "lucide-react"
import { cn } from "@/lib/utils"
import { chatPrimaryButtonClass } from "@/components/chat/chat-brand"

export interface AiChatUsageCardProps {
  userRole: string | undefined
  aiTokensIncluded: number
  aiTokensUsed: number
  percentTokensUsed: number
  hasAiTokensLeft: boolean
  onAddTokens: () => void
  addTokensDisabled?: boolean
  className?: string
}

export function AiChatUsageCard({
  userRole,
  aiTokensIncluded,
  aiTokensUsed,
  percentTokensUsed,
  hasAiTokensLeft,
  onAddTokens,
  addTokensDisabled = false,
  className,
}: AiChatUsageCardProps) {
  const isOrg = userRole === "organization"

  if (isOrg && aiTokensIncluded > 0) {
    const used = Math.min(aiTokensUsed, aiTokensIncluded)
    const percent = Math.min(100, Math.max(0, percentTokensUsed))

    return (
      <div
        className={cn(
          "rounded-xl border border-border bg-card px-3 py-2.5 text-card-foreground shadow-sm",
          "dark:border-zinc-800 dark:bg-[#0a0a0a] dark:text-white dark:shadow-md",
          className
        )}
      >
        <div className="flex flex-col gap-2">
          <p className="text-xs leading-tight">
            <span className="text-muted-foreground dark:text-white/85">AI Usage: </span>
            <span className="font-bold tabular-nums text-foreground dark:text-white">
              {used.toLocaleString()} / {aiTokensIncluded.toLocaleString()}
            </span>
            <span className="text-muted-foreground dark:text-white/85"> tokens</span>
          </p>

          <div className="flex items-center gap-2">
            <div className="h-2 min-h-0 flex-1 overflow-hidden rounded-full bg-muted dark:bg-zinc-800">
              <div
                className="h-full rounded-full bg-gradient-to-r from-purple-600 via-violet-500 to-blue-400 transition-[width] duration-500 ease-out"
                style={{ width: `${percent}%` }}
                role="progressbar"
                aria-valuenow={percent}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-label="AI tokens used"
              />
            </div>
            <span className="shrink-0 text-[11px] font-semibold tabular-nums text-foreground dark:text-white">
              {percent}% used
            </span>
          </div>
        </div>
        <button
          type="button"
          onClick={onAddTokens}
          disabled={addTokensDisabled}
          className={cn(
            chatPrimaryButtonClass,
            "mt-2 flex w-full items-center justify-center gap-1.5 rounded-lg py-1.5 text-[11px] font-semibold disabled:opacity-50"
          )}
        >
          <Plus className="h-3.5 w-3.5 shrink-0" aria-hidden />
          Add more tokens
        </button>
        {!hasAiTokensLeft && (
          <p className="mt-2 border-t border-border pt-2 text-[11px] font-medium text-amber-700 dark:border-white/10 dark:text-amber-400">
            Upgrade plan for more tokens
          </p>
        )}
      </div>
    )
  }

  if (isOrg && aiTokensIncluded === 0) {
    return (
      <div
        className={cn(
          "rounded-xl border border-border bg-card px-3 py-2 text-[11px] leading-snug text-card-foreground shadow-sm",
          "dark:border-zinc-800 dark:bg-[#0a0a0a] dark:text-white/95 dark:shadow-md",
          className
        )}
      >
        <div className="flex items-start gap-2">
          <Infinity
            className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600 dark:text-[#c1ff33]"
            strokeWidth={2}
            aria-hidden
          />
          <span className="text-foreground dark:text-white/95">No token cap — not limited by this pool.</span>
        </div>
        <button
          type="button"
          onClick={onAddTokens}
          disabled={addTokensDisabled}
          className={cn(
            chatPrimaryButtonClass,
            "mt-2 flex w-full items-center justify-center gap-1.5 rounded-lg py-1.5 text-[11px] font-semibold disabled:opacity-50"
          )}
        >
          <Plus className="h-3.5 w-3.5 shrink-0" aria-hidden />
          Add more tokens
        </button>
      </div>
    )
  }

  return (
    <div className={cn("space-y-2", className)}>
      <p className="text-xs text-muted-foreground">Uses your AI token balance</p>
      <button
        type="button"
        onClick={onAddTokens}
        disabled={addTokensDisabled}
        className={cn(
          chatPrimaryButtonClass,
          "flex w-full items-center justify-center gap-1.5 rounded-lg py-1.5 text-[11px] font-semibold disabled:opacity-50"
        )}
      >
        <Plus className="h-3.5 w-3.5 shrink-0" aria-hidden />
        Add more tokens
      </button>
    </div>
  )
}
