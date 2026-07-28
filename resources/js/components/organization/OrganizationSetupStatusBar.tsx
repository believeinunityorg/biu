"use client"

import { useCallback, useEffect, useState } from "react"
import { Link } from "@inertiajs/react"
import { AnimatePresence, motion } from "framer-motion"
import {
  ArrowRight,
  Bot,
  Building2,
  ChevronRight,
  ClipboardList,
  Link2,
  ShieldCheck,
  Sparkles,
} from "lucide-react"
import { cn } from "@/lib/utils"
import { Button } from "@/components/frontend/ui/button"
import type { LucideIcon } from "lucide-react"

type StatusColor = "green" | "yellow" | "red"

type ReadinessModule = {
  id: string
  label: string
  percent: number
  status_color: StatusColor
}

type ChecklistPayload = {
  percent: number
  completed: number
  total: number
  remaining: number
  modules: ReadinessModule[]
}

const MODULE_ICONS: Record<string, LucideIcon> = {
  organization: Building2,
  governance: ShieldCheck,
  integrations: Link2,
  platform_services: Bot,
}

const MODULE_SHORT_LABELS: Record<string, string> = {
  platform_services: "Services",
}

function moduleLabel(module: ReadinessModule): string {
  return MODULE_SHORT_LABELS[module.id] ?? module.label
}

function statusStyles(color: StatusColor) {
  if (color === "green") {
    return {
      dot: "bg-emerald-400 shadow-[0_0_8px_rgba(52,211,153,0.6)]",
      text: "text-emerald-400",
      bar: "bg-emerald-500",
      chipBorder: "border-emerald-500/30 hover:border-emerald-400/50",
      chipBg: "bg-emerald-500/10 hover:bg-emerald-500/15",
    }
  }
  if (color === "yellow") {
    return {
      dot: "bg-amber-400 shadow-[0_0_8px_rgba(251,191,36,0.6)]",
      text: "text-amber-400",
      bar: "bg-amber-500",
      chipBorder: "border-amber-500/30 hover:border-amber-400/50",
      chipBg: "bg-amber-500/10 hover:bg-amber-500/15",
    }
  }
  return {
    dot: "bg-red-400 shadow-[0_0_8px_rgba(248,113,113,0.6)]",
    text: "text-red-400",
    bar: "bg-red-500",
    chipBorder: "border-red-500/30 hover:border-red-400/50",
    chipBg: "bg-red-500/10 hover:bg-red-500/15",
  }
}

function overallProgressColor(percent: number): string {
  if (percent >= 100) return "#34d399"
  if (percent > 0) return "#a855f7"
  return "#f87171"
}

function CircularProgress({ percent, size = 44 }: { percent: number; size?: number }) {
  const stroke = 3.5
  const radius = (size - stroke) / 2
  const circumference = 2 * Math.PI * radius
  const offset = circumference - (percent / 100) * circumference
  const accent = overallProgressColor(percent)

  return (
    <div className="relative shrink-0" style={{ width: size, height: size }}>
      <svg width={size} height={size} className="-rotate-90" aria-hidden>
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke="currentColor"
          strokeWidth={stroke}
          className="text-white/15"
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={radius}
          fill="none"
          stroke={accent}
          strokeWidth={stroke}
          strokeDasharray={circumference}
          strokeDashoffset={offset}
          strokeLinecap="round"
          className="transition-all duration-700 ease-out"
          style={{ filter: `drop-shadow(0 0 4px ${accent}80)` }}
        />
      </svg>
      <span className="absolute inset-0 flex items-center justify-center text-[11px] font-bold tabular-nums text-white">
        {percent}%
      </span>
    </div>
  )
}

function ModuleChip({ module }: { module: ReadinessModule }) {
  const styles = statusStyles(module.status_color)
  const Icon = MODULE_ICONS[module.id] ?? Building2

  return (
    <Link
      href={route("setup-checklist.index", { module: module.id })}
      className={cn(
        "group inline-flex min-w-[7.5rem] flex-col gap-1 rounded-lg border px-2.5 py-1.5 transition-all duration-200",
        styles.chipBorder,
        styles.chipBg
      )}
    >
      <div className="flex items-center gap-1.5">
        <span className={cn("h-2 w-2 shrink-0 rounded-full", styles.dot)} aria-hidden />
        <Icon className="h-3 w-3 shrink-0 text-gray-400 group-hover:text-gray-300" aria-hidden />
        <span className="truncate text-[11px] font-medium text-gray-200">{moduleLabel(module)}</span>
        <span className={cn("ml-auto text-[11px] font-bold tabular-nums", styles.text)}>{module.percent}%</span>
      </div>
      <div className="h-1 overflow-hidden rounded-full bg-white/10">
        <div
          className={cn("h-full rounded-full transition-all duration-500", styles.bar)}
          style={{ width: `${Math.max(module.percent, module.percent > 0 ? 6 : 0)}%` }}
        />
      </div>
    </Link>
  )
}

function useLiveOrganizationReadiness(enabled: boolean) {
  const [checklist, setChecklist] = useState<ChecklistPayload | null>(null)
  const [loaded, setLoaded] = useState(false)

  const refresh = useCallback(async () => {
    if (!enabled) return
    try {
      const response = await fetch(route("setup-checklist.status"), {
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
      })
      if (!response.ok) return
      const data = (await response.json()) as { checklist: ChecklistPayload | null }
      if (data.checklist) {
        setChecklist(data.checklist)
      }
    } catch {
      // ignore fetch errors
    } finally {
      setLoaded(true)
    }
  }, [enabled])

  useEffect(() => {
    refresh()
  }, [refresh])

  useEffect(() => {
    if (!enabled) return

    const onFocus = () => refresh()
    window.addEventListener("focus", onFocus)
    const interval = window.setInterval(refresh, 20000)

    return () => {
      window.removeEventListener("focus", onFocus)
      window.clearInterval(interval)
    }
  }, [enabled, refresh])

  return { checklist, loaded }
}

function continueSetupHref(checklist: ChecklistPayload): string {
  const firstIncomplete = checklist.modules.find((module) => module.percent < 100)
  if (firstIncomplete) {
    return route("setup-checklist.index", { module: firstIncomplete.id })
  }

  return route("setup-checklist.index")
}

export default function OrganizationSetupStatusBar({ visible }: { visible: boolean }) {
  const { checklist, loaded } = useLiveOrganizationReadiness(visible)
  const [mobileExpanded, setMobileExpanded] = useState(false)

  if (!visible || !loaded || !checklist || checklist.percent >= 100) {
    return null
  }

  const setupHref = continueSetupHref(checklist)

  return (
    <div className="w-full">
      {/* Desktop / tablet */}
      <div
        className="relative hidden overflow-hidden rounded-2xl border border-purple-500/25 bg-gradient-to-r from-[#121a2e] via-[#0f1623] to-[#121a2e] shadow-[0_4px_24px_rgba(147,51,234,0.15)] md:block"
        role="region"
        aria-label="Organization setup progress"
      >
        {/* Accent glow */}
        <div className="pointer-events-none absolute inset-y-0 left-0 w-1 bg-gradient-to-b from-purple-500 via-violet-500 to-blue-500" />
        <div className="pointer-events-none absolute -right-8 -top-8 h-24 w-24 rounded-full bg-purple-500/20 blur-2xl" />

        <div className="relative flex min-h-[60px] flex-wrap items-center gap-3 px-4 py-3 lg:gap-4 lg:px-5">
          {/* Left — overall status */}
          <div className="flex shrink-0 items-center gap-3 pr-2 lg:border-r lg:border-white/10 lg:pr-4">
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-purple-500/20 ring-1 ring-purple-500/30">
              <ClipboardList className="h-5 w-5 text-purple-400" aria-hidden />
            </div>
            <CircularProgress percent={checklist.percent} size={44} />
            <div className="min-w-0 leading-tight">
              <p className="text-sm font-semibold text-white">Organization Setup</p>
              <p className="text-xs text-gray-400">
                <span className="font-medium text-purple-300">{checklist.percent}%</span> complete
                <span className="mx-1.5 text-white/20">·</span>
                <span>{checklist.remaining} step{checklist.remaining === 1 ? "" : "s"} left</span>
              </p>
            </div>
          </div>

          {/* Center — module chips */}
          <div className="flex min-w-0 flex-1 flex-wrap items-center justify-center gap-2">
            {checklist.modules.map((module) => (
              <ModuleChip key={module.id} module={module} />
            ))}
          </div>

          {/* Right — CTA */}
          <Button
            asChild
            size="sm"
            className="h-9 shrink-0 bg-gradient-to-r from-purple-600 to-violet-600 px-4 text-xs font-semibold shadow-lg shadow-purple-500/25 hover:from-purple-500 hover:to-violet-500"
          >
            <Link href={setupHref}>
              Continue Setup
              <ArrowRight className="ml-1.5 h-3.5 w-3.5" />
            </Link>
          </Button>
        </div>
      </div>

      {/* Mobile — high-visibility card with always-on CTA */}
      <div className="md:hidden space-y-2">
        <div
          className={cn(
            "relative overflow-hidden rounded-2xl border-2 shadow-xl",
            "border-purple-500/50 bg-gradient-to-br from-[#1a1040] via-[#121a2e] to-[#0f1623]",
            "shadow-[0_8px_32px_rgba(147,51,234,0.35)]"
          )}
        >
          {/* Glow accents */}
          <div className="pointer-events-none absolute inset-y-0 left-0 w-1.5 bg-gradient-to-b from-purple-400 via-violet-500 to-blue-500" />
          <div className="pointer-events-none absolute -right-6 -top-6 h-20 w-20 rounded-full bg-purple-500/30 blur-2xl" />
          <div className="pointer-events-none absolute -bottom-4 left-1/3 h-12 w-24 rounded-full bg-violet-600/20 blur-xl" />

          <div className="relative px-4 pt-4 pb-3">
            {/* Header row */}
            <button
              type="button"
              onClick={() => setMobileExpanded((open) => !open)}
              className="flex w-full items-start justify-between gap-3 text-left"
              aria-expanded={mobileExpanded}
            >
              <div className="flex min-w-0 flex-1 items-center gap-3">
                <CircularProgress percent={checklist.percent} size={52} />
                <div className="min-w-0">
                  <div className="flex items-center gap-1.5">
                    <Sparkles className="h-4 w-4 shrink-0 text-purple-300" aria-hidden />
                    <p className="text-base font-bold text-white">Complete your setup</p>
                  </div>
                  <p className="mt-0.5 text-sm font-semibold text-purple-200">
                    {checklist.percent}% done
                    <span className="mx-1.5 font-normal text-white/30">·</span>
                    <span className="font-normal text-gray-300">
                      {checklist.remaining} step{checklist.remaining === 1 ? "" : "s"} left
                    </span>
                  </p>
                  <p className="mt-1 text-xs text-gray-400">
                    {mobileExpanded ? "Tap to collapse modules" : "Tap modules below for details"}
                  </p>
                </div>
              </div>
              <ChevronRight
                className={cn(
                  "mt-1 h-6 w-6 shrink-0 text-purple-300 transition-transform duration-200",
                  mobileExpanded && "rotate-90"
                )}
              />
            </button>

            {/* Module preview — always visible */}
            <div className="mt-3 flex gap-2 overflow-x-auto pb-1 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
              {checklist.modules.map((module) => {
                const styles = statusStyles(module.status_color)
                const Icon = MODULE_ICONS[module.id] ?? Building2
                return (
                  <Link
                    key={module.id}
                    href={route("setup-checklist.index", { module: module.id })}
                    className={cn(
                      "flex shrink-0 flex-col items-center gap-1 rounded-xl border px-3 py-2 min-w-[4.5rem] transition-transform active:scale-95",
                      styles.chipBorder,
                      styles.chipBg
                    )}
                  >
                    <span className={cn("h-2.5 w-2.5 rounded-full", styles.dot)} aria-hidden />
                    <Icon className="h-4 w-4 text-gray-300" aria-hidden />
                    <span className="max-w-[4.5rem] truncate text-[10px] font-medium text-gray-200">
                      {moduleLabel(module)}
                    </span>
                    <span className={cn("text-xs font-bold tabular-nums", styles.text)}>{module.percent}%</span>
                  </Link>
                )
              })}
            </div>

            {/* Always-visible primary CTA */}
            <Button
              asChild
              className="mt-3 h-11 w-full bg-gradient-to-r from-purple-600 to-violet-600 text-sm font-bold shadow-lg shadow-purple-500/30 hover:from-purple-500 hover:to-violet-500"
            >
              <Link href={setupHref}>
                Continue Setup
                <ArrowRight className="ml-2 h-4 w-4" />
              </Link>
            </Button>
          </div>
        </div>

        {/* Expanded module detail */}
        <AnimatePresence initial={false}>
          {mobileExpanded ? (
            <motion.div
              initial={{ height: 0, opacity: 0 }}
              animate={{ height: "auto", opacity: 1 }}
              exit={{ height: 0, opacity: 0 }}
              transition={{ duration: 0.22, ease: "easeOut" }}
              className="overflow-hidden"
            >
              <div className="space-y-2 rounded-2xl border border-purple-500/25 bg-[#0f1623]/98 p-3 shadow-inner">
                <p className="px-1 text-xs font-semibold uppercase tracking-wide text-purple-300/80">
                  All setup modules
                </p>
                {checklist.modules.map((module) => {
                  const styles = statusStyles(module.status_color)
                  const Icon = MODULE_ICONS[module.id] ?? Building2
                  return (
                    <Link
                      key={module.id}
                      href={route("setup-checklist.index", { module: module.id })}
                      className={cn(
                        "flex flex-col gap-2 rounded-xl border px-3 py-3 transition-colors active:scale-[0.99]",
                        styles.chipBorder,
                        styles.chipBg
                      )}
                    >
                      <div className="flex items-center justify-between gap-2">
                        <span className="flex items-center gap-2.5 text-sm font-semibold text-white">
                          <span className={cn("h-2.5 w-2.5 shrink-0 rounded-full", styles.dot)} aria-hidden />
                          <Icon className="h-4 w-4 text-gray-300" aria-hidden />
                          {moduleLabel(module)}
                        </span>
                        <span className={cn("text-base font-bold tabular-nums", styles.text)}>{module.percent}%</span>
                      </div>
                      <div className="h-2 overflow-hidden rounded-full bg-white/10">
                        <div
                          className={cn("h-full rounded-full transition-all duration-500", styles.bar)}
                          style={{ width: `${Math.max(module.percent, module.percent > 0 ? 6 : 0)}%` }}
                        />
                      </div>
                    </Link>
                  )
                })}
              </div>
            </motion.div>
          ) : null}
        </AnimatePresence>
      </div>
    </div>
  )
}
