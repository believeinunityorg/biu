"use client"

import {
  BadgeCheck,
  Building2,
  Mail,
  ShieldCheck,
  UserRound,
  Users,
  UsersRound,
  type LucideIcon,
} from "lucide-react"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"

type Props = {
  value: string
  onChange: (next: string) => void
  options?: Record<string, string>
  /** "Organization" | "Unity Impact Alliance" — drives follower/member card copy */
  parentLabel?: string
  /** Unused — always single column for a stable create/settings layout */
  compact?: boolean
  /** When false, omit the section heading (host page already has one) */
  showHeading?: boolean
}

const ORDER = [
  "anyone",
  "followers",
  "members",
  "followers_and_members",
  "approval",
  "invite_only",
] as const

function hostWord(parentLabel?: string) {
  if (parentLabel === "Unity Impact Alliance") {
    return { short: "Alliance", long: "Unity Impact Alliance" }
  }
  return { short: "Organization", long: "organization" }
}

function cardMeta(
  value: string,
  parentLabel?: string,
): { icon: LucideIcon; title: string; description: string } | null {
  const host = hostWord(parentLabel)

  switch (value) {
    case "anyone":
      return {
        icon: UsersRound,
        title: "Anyone",
        description: "Anyone can join this group.",
      }
    case "followers":
      return {
        icon: UserRound,
        title: `${host.short} followers only`,
        description: `Only people who follow this ${host.long} can join.`,
      }
    case "members":
      return {
        icon: Users,
        title: `${host.short} members only`,
        description: `Only ${host.long} members can join.`,
      }
    case "followers_and_members":
      return {
        icon: Building2,
        title: "Followers & members",
        description: `Both followers and members of this ${host.long} can join.`,
      }
    case "approval":
      return {
        icon: ShieldCheck,
        title: "Approval required",
        description: "Anyone can request; a group admin must approve.",
      }
    case "invite_only":
      return {
        icon: Mail,
        title: "Invitation only",
        description: "Only people who are invited can join.",
      }
    default:
      return {
        icon: BadgeCheck,
        title: value,
        description: "",
      }
  }
}

export default function GroupJoinPolicyCards({
  value,
  onChange,
  options,
  parentLabel,
  showHeading = true,
}: Props) {
  const keys = (options ? Object.keys(options) : [...ORDER]).sort((a, b) => {
    const ia = ORDER.indexOf(a as (typeof ORDER)[number])
    const ib = ORDER.indexOf(b as (typeof ORDER)[number])
    return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib)
  })

  return (
    <div className="w-full min-w-0">
      {showHeading && (
        <div className="mb-2.5">
          <p className="text-[13px] font-semibold text-slate-800 dark:text-slate-200">
            Who is eligible to join this group?
          </p>
          <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
            Hover an option for more detail.
          </p>
        </div>
      )}

      <div className="grid w-full min-w-0 grid-cols-1 gap-1.5 sm:grid-cols-2">
          {keys.map((key) => {
            const meta = cardMeta(key, parentLabel)
            if (!meta) return null
            const Icon = meta.icon
            const selected = value === key
            const label = options?.[key] ?? meta.title

            return (
              <Tooltip key={key} delayDuration={200}>
                <TooltipTrigger asChild>
                  <button
                    type="button"
                    onClick={() => onChange(key)}
                    aria-pressed={selected}
                    aria-label={`${meta.title || label}. ${meta.description}`}
                    className={`flex h-full w-full min-w-0 items-center gap-2 rounded-xl border px-2.5 py-2 text-left transition ${
                      selected
                        ? "border-purple-500 bg-gradient-to-r from-purple-50 to-blue-50 dark:border-purple-400 dark:from-purple-500/20 dark:to-blue-500/10"
                        : "border-slate-200 bg-white hover:border-purple-300 hover:bg-slate-50 dark:border-white/10 dark:bg-[#0b1220] dark:hover:border-purple-500/30 dark:hover:bg-white/5"
                    }`}
                  >
                    <span
                      className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-lg ${
                        selected
                          ? "bg-gradient-to-br from-purple-600 to-blue-600 text-white"
                          : "bg-purple-100 text-purple-700 dark:bg-purple-500/20 dark:text-purple-200"
                      }`}
                    >
                      <Icon className="h-3.5 w-3.5" />
                    </span>

                    <span
                      className={`min-w-0 flex-1 text-[12.5px] font-semibold leading-snug ${
                        selected ? "text-purple-900 dark:text-purple-50" : "text-slate-900 dark:text-white"
                      }`}
                    >
                      {meta.title || label}
                    </span>

                    <span
                      className={`ml-auto flex h-[14px] w-[14px] shrink-0 items-center justify-center rounded-full border-2 ${
                        selected
                          ? "border-purple-600 bg-purple-600 dark:border-purple-400 dark:bg-purple-400"
                          : "border-slate-300 dark:border-slate-500"
                      }`}
                      aria-hidden
                    >
                      {selected ? <span className="h-1.5 w-1.5 rounded-full bg-white" /> : null}
                    </span>
                  </button>
                </TooltipTrigger>

                {meta.description ? (
                  <TooltipContent
                    side="top"
                    align="start"
                    sideOffset={6}
                    className="z-[80] max-w-[240px] border-purple-500/20 bg-slate-900 px-3 py-2 text-left text-white dark:bg-[#020617]"
                  >
                    <p className="text-[11px] font-semibold text-purple-200">{meta.title || label}</p>
                    <p className="mt-1 text-xs leading-relaxed text-slate-200">{meta.description}</p>
                  </TooltipContent>
                ) : null}
              </Tooltip>
            )
          })}
        </div>
    </div>
  )
}
