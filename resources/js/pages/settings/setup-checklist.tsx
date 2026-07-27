"use client"

import { useCallback, useEffect, useRef, useState } from "react"
import { Head, Link } from "@inertiajs/react"
import SettingsLayout from "@/layouts/settings/layout"
import { Button } from "@/components/ui/button"
import { cn } from "@/lib/utils"
import {
  AlertCircle,
  ArrowLeft,
  ArrowRight,
  Bot,
  Building2,
  CheckCircle2,
  ChevronRight,
  Circle,
  ClipboardList,
  Cloud,
  Facebook,
  Landmark,
  Layers,
  Link2,
  Mail,
  Megaphone,
  MessageSquare,
  Radio,
  ShieldCheck,
  Sparkles,
  Star,
  Users,
  Video,
  Wallet,
  Youtube,
} from "lucide-react"
import type { LucideIcon } from "lucide-react"

type ItemStatus = "completed" | "in_progress" | "not_started"
type StatusColor = "green" | "yellow" | "red"

type ChecklistItem = {
  id: string
  module: string
  label: string
  description: string
  route: string
  anchor: string | null
  href: string
  route_label: string
  status: ItemStatus
  weight: number
  completed_at?: string | null
}

type ReadinessModule = {
  id: string
  label: string
  description: string
  sort: number
  completed: number
  total: number
  percent: number
  status_color: StatusColor
  items: ChecklistItem[]
  first_incomplete: ChecklistItem | null
  next_module: string | null
  prev_module: string | null
}

type ChecklistPayload = {
  percent: number
  completed: number
  total: number
  remaining: number
  modules: ReadinessModule[]
}

type Props = {
  checklist: ChecklistPayload
  organizationName: string
  activeModuleId?: string | null
  activeModule?: ReadinessModule | null
}

const MODULE_ICONS: Record<string, LucideIcon> = {
  organization: Building2,
  governance: ShieldCheck,
  integrations: Link2,
  platform_services: Bot,
}

const ITEM_ICONS: Record<string, LucideIcon> = {
  profile_information: Building2,
  team_members: Users,
  email_invites: Mail,
  organization_verification: ShieldCheck,
  payout_settings: Wallet,
  gift_card_agreement: Landmark,
  marketplace_agreement: Layers,
  integrations: Link2,
  social_media: Facebook,
  youtube: Youtube,
  stripe_payouts: Landmark,
  dropbox: Cloud,
  paypal_payouts: Wallet,
  ai_chat: Bot,
  pay_as_you_go: Wallet,
  overlay_studio: Layers,
  livestream: Video,
  unity_live: Radio,
  unity_meet: MessageSquare,
  ai_video_studio: Sparkles,
  engagement: Mail,
  auto_drip_campaign: Megaphone,
}

function statusColorClasses(color: StatusColor): { bar: string; text: string; border: string } {
  if (color === "green") {
    return {
      bar: "bg-emerald-500",
      text: "text-emerald-600 dark:text-emerald-400",
      border: "border-emerald-200/80 dark:border-emerald-900/50",
    }
  }
  if (color === "yellow") {
    return {
      bar: "bg-amber-500",
      text: "text-amber-600 dark:text-amber-400",
      border: "border-amber-200/80 dark:border-amber-900/50",
    }
  }
  return {
    bar: "bg-red-500",
    text: "text-red-600 dark:text-red-400",
    border: "border-red-200/80 dark:border-red-900/50",
  }
}

function ModuleProgressBar({ percent, color }: { percent: number; color: StatusColor }) {
  const colors = statusColorClasses(color)
  return (
    <div className="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-800">
      <div
        className={cn("h-full rounded-full transition-all duration-500 ease-out", colors.bar)}
        style={{ width: `${Math.max(percent, percent > 0 ? 4 : 0)}%` }}
      />
    </div>
  )
}

function StatusBadge({ status }: { status: ItemStatus }) {
  if (status === "completed") {
    return (
      <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
        <CheckCircle2 className="h-3.5 w-3.5" />
        Completed
      </span>
    )
  }
  if (status === "in_progress") {
    return (
      <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-950/60 dark:text-amber-300">
        <AlertCircle className="h-3.5 w-3.5" />
        In progress
      </span>
    )
  }
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-semibold text-gray-600 dark:bg-gray-800 dark:text-gray-400">
      <Circle className="h-3.5 w-3.5" />
      Not started
    </span>
  )
}

function formatCompletedDate(iso: string | null | undefined): string | null {
  if (!iso) return null
  try {
    return new Date(iso).toLocaleDateString(undefined, { month: "short", day: "numeric", year: "numeric" })
  } catch {
    return null
  }
}

function ModuleOverviewCard({ module, index }: { module: ReadinessModule; index: number }) {
  const Icon = MODULE_ICONS[module.id] ?? ClipboardList
  const colors = statusColorClasses(module.status_color)

  return (
    <Link
      href={route("setup-checklist.index", { module: module.id })}
      className={cn(
        "group flex items-center gap-4 rounded-xl border bg-white p-4 shadow-sm transition-all hover:shadow-md dark:bg-gray-950",
        colors.border
      )}
    >
      <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-purple-100 text-purple-600 dark:bg-purple-950/60 dark:text-purple-400">
        <Icon className="h-6 w-6" />
      </div>
      <div className="min-w-0 flex-1">
        <div className="flex items-center justify-between gap-2">
          <h3 className="font-semibold text-gray-900 dark:text-white">
            {index + 1}. {module.label}
          </h3>
          <span className={cn("text-lg font-bold tabular-nums", colors.text)}>{module.percent}%</span>
        </div>
        <p className="mt-0.5 text-sm text-muted-foreground">{module.description}</p>
        <p className="mt-2 text-xs text-muted-foreground">
          {module.completed} of {module.total} completed
        </p>
        <div className="mt-2">
          <ModuleProgressBar percent={module.percent} color={module.status_color} />
        </div>
      </div>
      <ChevronRight className="h-5 w-5 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
    </Link>
  )
}

function ChecklistItemRow({
  item,
  itemRef,
}: {
  item: ChecklistItem
  itemRef?: (el: HTMLDivElement | null) => void
}) {
  const Icon = ITEM_ICONS[item.id] ?? Circle
  const completedDate = formatCompletedDate(item.completed_at)

  return (
    <div
      ref={itemRef}
      id={`readiness-item-${item.id}`}
      className={cn(
        "scroll-mt-24 rounded-xl border px-4 py-4 sm:px-5",
        item.status === "completed"
          ? "border-emerald-200/70 bg-emerald-50/30 dark:border-emerald-900/40 dark:bg-emerald-950/20"
          : item.status === "in_progress"
            ? "border-amber-200/70 bg-amber-50/30 dark:border-amber-900/40 dark:bg-amber-950/20"
            : "border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-950"
      )}
    >
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="flex min-w-0 flex-1 items-start gap-3">
          <div
            className={cn(
              "mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg",
              item.status === "completed"
                ? "bg-emerald-100 text-emerald-600 dark:bg-emerald-950/60 dark:text-emerald-400"
                : item.status === "in_progress"
                  ? "bg-amber-100 text-amber-600 dark:bg-amber-950/60 dark:text-amber-400"
                  : "bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400"
            )}
          >
            {item.status === "completed" ? (
              <CheckCircle2 className="h-4 w-4" />
            ) : (
              <Icon className="h-4 w-4" />
            )}
          </div>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <h3 className="font-semibold text-gray-900 dark:text-white">{item.label}</h3>
              <StatusBadge status={item.status} />
            </div>
            <p className="mt-1 text-sm text-gray-600 dark:text-gray-400">{item.description}</p>
            {completedDate && item.status === "completed" ? (
              <p className="mt-1 text-xs text-emerald-600 dark:text-emerald-400">Completed on {completedDate}</p>
            ) : null}
          </div>
        </div>
        <Button
          size="sm"
          variant={item.status === "completed" ? "outline" : "default"}
          className={cn(
            "shrink-0",
            item.status !== "completed" && "bg-purple-600 text-white hover:bg-purple-700 dark:bg-purple-600 dark:hover:bg-purple-700"
          )}
          asChild
        >
          <Link href={item.href}>{item.route_label}</Link>
        </Button>
      </div>
    </div>
  )
}

function OverviewView({ checklist, organizationName }: { checklist: ChecklistPayload; organizationName: string }) {
  const firstIncompleteModule = checklist.modules.find((m) => m.percent < 100)

  return (
    <div className="space-y-6">
      <div className="rounded-xl border border-purple-200/80 bg-gradient-to-br from-purple-50/80 via-white to-white p-5 dark:border-purple-900/40 dark:from-purple-950/30 dark:via-gray-950 dark:to-gray-950">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-start gap-3">
            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-purple-100 text-purple-600 dark:bg-purple-950/60 dark:text-purple-400">
              <ClipboardList className="h-5 w-5" />
            </div>
            <div>
              <h2 className="text-lg font-semibold text-gray-900 dark:text-white">Organization Readiness</h2>
              <p className="text-sm text-gray-600 dark:text-gray-400">
                {checklist.completed} of {checklist.total} steps completed
                {checklist.remaining > 0 ? ` · ${checklist.remaining} remaining` : ""}
              </p>
              <p className="mt-0.5 text-xs text-muted-foreground">Track setup for {organizationName}</p>
            </div>
          </div>
          <div className="text-right">
            <p className="text-3xl font-bold tabular-nums text-purple-700 dark:text-purple-300">{checklist.percent}%</p>
            <p className="text-xs text-muted-foreground">Overall readiness</p>
          </div>
        </div>
        <div className="mt-4">
          <ModuleProgressBar
            percent={checklist.percent}
            color={checklist.percent >= 100 ? "green" : checklist.percent > 0 ? "yellow" : "red"}
          />
        </div>
      </div>

      <div className="space-y-3">
        {checklist.modules.map((module, index) => (
          <ModuleOverviewCard key={module.id} module={module} index={index} />
        ))}
      </div>

      {checklist.percent < 100 && firstIncompleteModule ? (
        <div className="rounded-xl border border-purple-200/60 bg-purple-50/50 p-5 dark:border-purple-900/40 dark:bg-purple-950/20">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex items-start gap-3">
              <Star className="mt-0.5 h-5 w-5 text-purple-600 dark:text-purple-400" />
              <div>
                <p className="font-semibold text-gray-900 dark:text-white">Nice progress! You&apos;re well on your way.</p>
                <p className="text-sm text-muted-foreground">
                  Complete the remaining steps to unlock all platform features.
                </p>
              </div>
            </div>
            <Button asChild className="bg-purple-600 hover:bg-purple-700 dark:bg-purple-600">
              <Link href={route("setup-checklist.index", { module: firstIncompleteModule.id })}>Continue Setup</Link>
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  )
}

function ModuleDetailView({
  module,
  moduleIndex,
  allModules,
}: {
  module: ReadinessModule
  moduleIndex: number
  allModules: ReadinessModule[]
}) {
  const itemRefs = useRef<Record<string, HTMLDivElement | null>>({})
  const colors = statusColorColors(module.status_color)
  const ModuleIcon = MODULE_ICONS[module.id] ?? ClipboardList
  const nextModule = allModules.find((m) => m.id === module.next_module)
  const prevModule = allModules.find((m) => m.id === module.prev_module)

  const scrollToFirstIncomplete = useCallback(() => {
    const target = module.first_incomplete
    if (!target) return
    const el = itemRefs.current[target.id] ?? document.getElementById(`readiness-item-${target.id}`)
    el?.scrollIntoView({ behavior: "smooth", block: "start" })
  }, [module.first_incomplete])

  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    if (params.get("focus") === "first-incomplete" && module.first_incomplete) {
      window.setTimeout(scrollToFirstIncomplete, 150)
    }
  }, [module.first_incomplete, scrollToFirstIncomplete])

  return (
    <div className="space-y-6">
      <Link
        href={route("setup-checklist.index")}
        className="inline-flex items-center gap-1.5 text-sm font-medium text-purple-600 hover:text-purple-700 dark:text-purple-400"
      >
        <ArrowLeft className="h-4 w-4" />
        Back to Overview
      </Link>

      <div className={cn("rounded-xl border bg-white p-5 dark:bg-gray-950", colors.border)}>
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div className="flex items-start gap-3">
            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-purple-100 text-purple-600 dark:bg-purple-950/60 dark:text-purple-400">
              <ModuleIcon className="h-6 w-6" />
            </div>
            <div>
              <h2 className="text-xl font-bold text-gray-900 dark:text-white">
                {moduleIndex + 1}. {module.label}
              </h2>
              <p className="mt-1 text-sm text-muted-foreground">{module.description}</p>
              <p className="mt-2 text-sm text-muted-foreground">
                {module.completed} of {module.total} completed
              </p>
            </div>
          </div>
          <div className="text-right">
            <p className={cn("text-3xl font-bold tabular-nums", colors.text)}>{module.percent}%</p>
          </div>
        </div>
        <div className="mt-4">
          <ModuleProgressBar percent={module.percent} color={module.status_color} />
        </div>
      </div>

      <div className="space-y-3">
        {module.items.map((item) => (
          <ChecklistItemRow
            key={item.id}
            item={item}
            itemRef={(el) => {
              itemRefs.current[item.id] = el
            }}
          />
        ))}
      </div>

      {module.id === "governance" ? (
        <div className="rounded-xl border border-emerald-200/70 bg-emerald-50/40 p-5 dark:border-emerald-900/40 dark:bg-emerald-950/20">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p className="font-semibold text-emerald-900 dark:text-emerald-100">Why these steps are important</p>
              <p className="mt-1 text-sm text-emerald-800/90 dark:text-emerald-200/80">
                Completing Governance steps builds trust with your supporters and ensures your organization can receive
                payments, sell products, and operate securely.
              </p>
            </div>
            <Button variant="outline" size="sm" asChild>
              <Link href={route("governance.onboarding.index")}>Learn More</Link>
            </Button>
          </div>
        </div>
      ) : null}

      {module.first_incomplete ? (
        <div className="flex justify-center">
          <Button onClick={scrollToFirstIncomplete} className="bg-purple-600 hover:bg-purple-700 dark:bg-purple-600">
            Complete Setup
          </Button>
        </div>
      ) : null}

      <div className="flex flex-col gap-3 border-t border-gray-200 pt-4 dark:border-gray-800 sm:flex-row sm:justify-between">
        {prevModule ? (
          <Button variant="outline" asChild>
            <Link href={route("setup-checklist.index", { module: prevModule.id })}>
              <ArrowLeft className="mr-2 h-4 w-4" />
              Previous
            </Link>
          </Button>
        ) : (
          <div />
        )}
        {nextModule ? (
          <Button className="bg-purple-600 hover:bg-purple-700 dark:bg-purple-600" asChild>
            <Link href={route("setup-checklist.index", { module: nextModule.id })}>
              Next: {nextModule.label}
              <ArrowRight className="ml-2 h-4 w-4" />
            </Link>
          </Button>
        ) : (
          <Button variant="outline" asChild>
            <Link href={route("setup-checklist.index")}>Back to Overview</Link>
          </Button>
        )}
      </div>
    </div>
  )
}

function statusColorColors(color: StatusColor) {
  return statusColorClasses(color)
}

function useLiveReadiness(initial: ChecklistPayload, enabled: boolean) {
  const [checklist, setChecklist] = useState(initial)

  useEffect(() => {
    setChecklist(initial)
  }, [initial])

  useEffect(() => {
    if (!enabled) return

    let cancelled = false

    const refresh = async () => {
      try {
        const response = await fetch(route("setup-checklist.status"), {
          headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
          credentials: "same-origin",
        })
        if (!response.ok || cancelled) return
        const data = (await response.json()) as { checklist: ChecklistPayload }
        if (!cancelled && data.checklist) {
          setChecklist(data.checklist)
        }
      } catch {
        // ignore polling errors
      }
    }

    const onFocus = () => refresh()
    window.addEventListener("focus", onFocus)
    const interval = window.setInterval(refresh, 20000)

    return () => {
      cancelled = true
      window.removeEventListener("focus", onFocus)
      window.clearInterval(interval)
    }
  }, [enabled])

  return checklist
}

export default function OrganizationSetupChecklistPage({
  checklist: initialChecklist,
  organizationName,
  activeModuleId,
  activeModule: initialActiveModule,
}: Props) {
  const checklist = useLiveReadiness(initialChecklist, true)
  const activeModule =
    activeModuleId != null
      ? (checklist.modules.find((m) => m.id === activeModuleId) ?? initialActiveModule ?? null)
      : null
  const moduleIndex = activeModule ? checklist.modules.findIndex((m) => m.id === activeModule.id) : -1

  return (
    <SettingsLayout
      activeTab="setup-checklist"
      pageTitle="Organization Setup Checklist"
      pageSubtitle="Complete each section to fully configure your organization and unlock all Believe In Unity features."
    >
      <Head title="Organization Setup Checklist" />

      {activeModule && moduleIndex >= 0 ? (
        <ModuleDetailView module={activeModule} moduleIndex={moduleIndex} allModules={checklist.modules} />
      ) : (
        <OverviewView checklist={checklist} organizationName={organizationName} />
      )}
    </SettingsLayout>
  )
}
