"use client"

import { useEffect, useRef, useState } from "react"
import { Head, Link, router, usePage } from "@inertiajs/react"
import AppLayout from "@/layouts/app-layout"
import type { BreadcrumbItem } from "@/types"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Separator } from "@/components/ui/separator"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { cn } from "@/lib/utils"
import toast from "react-hot-toast"
import { route } from "ziggy-js"
import {
  Activity,
  Building2,
  CalendarClock,
  Check,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  HeartHandshake,
  History,
  Inbox,
  Layers,
  Loader2,
  Mail,
  Plus,
  Search,
  Wallet,
} from "lucide-react"
import { dashboardInputClass } from "@/pages/care-alliance/workspace/shared"

export type AllianceMembershipTab = "join" | "outgoing" | "invitations" | "membership" | "activity"

type AllianceRef = { id: number; name: string | null; slug: string | null }

type InvitationRow = {
  id: number
  token: string
  email: string | null
  alliance: AllianceRef
  expires_at: string | null
}

type MembershipRow = {
  id: number
  status: string
  invited_at: string | null
  responded_at: string | null
  alliance: AllianceRef
}

type AllianceSearchHit = {
  id: number
  slug: string
  name: string
  city: string | null
  state: string | null
}

type OrgJoinRequestRow = {
  id: number
  status: string
  message: string | null
  created_at: string | null
  reviewed_at: string | null
  alliance: AllianceRef
}

/** Unity Impact Alliance split credits to this org owner’s wallet (from transactions). */
export type AllianceWalletActivityRow = {
  id: number
  amount: number
  role: string | null
  care_alliance_id: number | null
  care_alliance_name: string | null
  created_at: string | null
}

const MEMBERSHIP_PAGE_KEYS = [
  "activeTab",
  "organization",
  "canRequestAllianceJoin",
  "allianceSearchQuery",
  "allianceSearchResults",
  "pendingInvitationsCount",
  "invitations",
  "memberships",
  "joinRequests",
  "allianceWalletActivity",
] as const

const ALLIANCE_MEMBERSHIP_ROUTE = "organization.alliance-membership"
const SEARCH_DEBOUNCE_MS = 400
const ALLIANCE_SEARCH_PAGE_SIZE = 5

export type AllianceMembershipPageProps = {
  activeTab: AllianceMembershipTab
  pendingInvitationsCount: number
  organization: { id: number; name: string }
  canRequestAllianceJoin: boolean
  allianceSearchQuery: string
  allianceSearchResults: AllianceSearchHit[]
  invitations: InvitationRow[]
  memberships: MembershipRow[]
  joinRequests: OrgJoinRequestRow[]
  allianceWalletActivity?: AllianceWalletActivityRow[]
}

function formatInvitationExpiry(iso: string | null): string {
  if (!iso) {
    return "No expiry date — respond when you are ready."
  }
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) {
    return "Expiry unknown"
  }
  return d.toLocaleString(undefined, {
    weekday: "short",
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  })
}

function formatMembershipTimestamp(iso: string | null): string | null {
  if (!iso) return null
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return null
  return d.toLocaleString(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  })
}

function membershipTimelineSummary(m: MembershipRow): string {
  const invited = formatMembershipTimestamp(m.invited_at)
  const updated = formatMembershipTimestamp(m.responded_at)
  if (invited && updated) {
    return `Invited ${invited} · Last update ${updated}`
  }
  if (updated) {
    return `Last update ${updated}`
  }
  if (invited) {
    return `Invited ${invited}`
  }
  return "No activity dates on file"
}

function membershipStatusLabel(status: string): string {
  switch (status) {
    case "active":
      return "Active member"
    case "pending":
      return "Pending"
    case "declined":
      return "Declined"
    case "removed":
      return "Removed"
    default:
      return status.replace(/_/g, " ")
  }
}

function statusBadgeVariant(status: string): "default" | "secondary" | "destructive" | "outline" {
  switch (status) {
    case "active":
      return "default"
    case "declined":
    case "removed":
      return "destructive"
    default:
      return "secondary"
  }
}

function statusBadgeClassName(status: string): string {
  switch (status) {
    case "active":
      return "border-emerald-200/90 bg-emerald-50 text-emerald-950 dark:border-emerald-900/50 dark:bg-emerald-950/35 dark:text-emerald-50"
    case "pending":
      return "border-sky-200/90 bg-sky-50 text-sky-950 dark:border-sky-900/50 dark:bg-sky-950/35 dark:text-sky-50"
    case "declined":
    case "removed":
      return ""
    default:
      return ""
  }
}

function outgoingJoinRequestLabel(status: string): string {
  switch (status) {
    case "pending":
      return "Awaiting review"
    case "approved":
      return "Approved"
    case "declined":
      return "Declined"
    default:
      return status
  }
}

function outgoingJoinRequestBadgeVariant(status: string): "default" | "secondary" | "destructive" | "outline" {
  if (status === "approved") return "default"
  if (status === "declined") return "destructive"
  return "secondary"
}

function formatWalletActivityWhen(iso: string | null): string {
  if (!iso) return "—"
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return "—"
  return d.toLocaleString(undefined, {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  })
}

function walletActivityRoleLabel(role: string | null): string {
  if (role === "member_share") return "Member share"
  if (role === "alliance_fee") return "Alliance fee"
  return "Credit"
}

function AllianceWalletActivityPanel({
  rows,
  variant = "embedded",
}: {
  rows: AllianceWalletActivityRow[]
  variant?: "embedded" | "page"
}) {
  const isPage = variant === "page"
  return (
    <div
      className={cn(
        "bg-gradient-to-br from-teal-500/[0.06] via-violet-500/[0.04] to-transparent dark:from-teal-500/10 dark:via-violet-500/8",
        isPage ? "rounded-xl border border-border/70 p-5 sm:p-6" : "border-b border-border/70 px-5 py-4 sm:px-6",
      )}
    >
      <div className="mb-3 flex items-start gap-2.5">
        <div
          className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-teal-500/15 text-teal-700 ring-1 ring-teal-500/20 dark:bg-teal-500/10 dark:text-teal-300"
          aria-hidden
        >
          <Wallet className="h-4 w-4" strokeWidth={1.75} />
        </div>
        <div className="min-w-0">
          <p className="text-sm font-semibold text-foreground">Alliance split activity</p>
          <p className="text-xs text-muted-foreground leading-relaxed">
            Credits to your organization owner&apos;s wallet when Unity Impact Alliance donations are distributed to members (instant or
            scheduled release). New splits appear after each payout.
          </p>
        </div>
      </div>
      {rows.length === 0 ? (
        <p className="rounded-lg border border-dashed border-border/80 bg-muted/40 px-3 py-3 text-center text-xs text-muted-foreground">
          No Unity Impact Alliance split credits yet. Once your nonprofit receives a share from an alliance, it will show here.
        </p>
      ) : (
        <ul
          className={cn(
            "space-y-1 overflow-y-auto pr-1",
            isPage ? "max-h-[min(520px,60vh)]" : "max-h-[220px]",
          )}
        >
          {rows.map((r) => (
            <li
              key={r.id}
              className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 rounded-md border border-border/60 bg-background/80 px-3 py-2 text-sm shadow-sm dark:bg-background/40"
            >
              <div className="min-w-0 flex-1">
                <span className="font-medium text-foreground">
                  {r.care_alliance_name?.trim() ? r.care_alliance_name : "Unity Impact Alliance"}
                </span>
                <span className="text-muted-foreground"> · </span>
                <span className="text-xs text-muted-foreground">{walletActivityRoleLabel(r.role)}</span>
              </div>
              <div className="flex shrink-0 items-center gap-3">
                <span className="text-xs text-muted-foreground tabular-nums">{formatWalletActivityWhen(r.created_at)}</span>
                <span className="font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">
                  +{new Intl.NumberFormat("en-US", { style: "currency", currency: "USD" }).format(r.amount)}
                </span>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

export default function OrganizationAllianceMembershipIndex() {
  const page = usePage<AllianceMembershipPageProps>()
  const {
    activeTab,
    pendingInvitationsCount = 0,
    organization,
    canRequestAllianceJoin,
    allianceSearchQuery,
    allianceSearchResults,
    invitations,
    memberships,
    joinRequests,
    allianceWalletActivity = [],
  } = page.props
  const { flash } = page.props as { flash?: { success?: string; error?: string } }

  const [actingInvitationId, setActingInvitationId] = useState<number | null>(null)
  const [draftSearchQ, setDraftSearchQ] = useState(allianceSearchQuery)
  const [allianceSearchBusy, setAllianceSearchBusy] = useState(false)
  const [allianceSearchPage, setAllianceSearchPage] = useState(1)
  const [allianceDropdownOpen, setAllianceDropdownOpen] = useState(false)
  const [selectedAlliance, setSelectedAlliance] = useState<AllianceSearchHit | null>(null)
  const [requestMessage, setRequestMessage] = useState("")
  const [submittingJoinRequest, setSubmittingJoinRequest] = useState(false)
  const allianceComboRef = useRef<HTMLDivElement>(null)
  const prevTabRef = useRef<AllianceMembershipTab>(activeTab)

  useEffect(() => {
    if (flash?.success) toast.success(flash.success)
    if (flash?.error) toast.error(flash.error)
  }, [flash?.success, flash?.error])

  useEffect(() => {
    if (prevTabRef.current !== activeTab) {
      prevTabRef.current = activeTab
      setDraftSearchQ(allianceSearchQuery)
      if (activeTab !== "join") {
        setSelectedAlliance(null)
        setAllianceDropdownOpen(false)
      }
    }
  }, [activeTab, allianceSearchQuery])

  useEffect(() => {
    const onDocMouseDown = (e: MouseEvent) => {
      const el = allianceComboRef.current
      if (el && !el.contains(e.target as Node)) {
        setAllianceDropdownOpen(false)
      }
    }
    document.addEventListener("mousedown", onDocMouseDown)
    return () => document.removeEventListener("mousedown", onDocMouseDown)
  }, [])

  useEffect(() => {
    if (draftSearchQ.trim().length >= 2) {
      setAllianceDropdownOpen(true)
    } else {
      setAllianceDropdownOpen(false)
    }
  }, [draftSearchQ])

  useEffect(() => {
    if (activeTab !== "join") {
      return
    }
    const q = draftSearchQ.trim()
    const timer = window.setTimeout(() => {
      router.get(
        route(ALLIANCE_MEMBERSHIP_ROUTE),
        {
          tab: "join",
          ...(q.length >= 2 ? { q } : {}),
        },
        {
          preserveState: true,
          preserveScroll: true,
          replace: true,
          only: [...MEMBERSHIP_PAGE_KEYS],
          onStart: () => setAllianceSearchBusy(true),
          onFinish: () => setAllianceSearchBusy(false),
          onError: () => {
            setAllianceSearchBusy(false)
            toast.error("Search failed")
          },
        },
      )
    }, SEARCH_DEBOUNCE_MS)
    return () => clearTimeout(timer)
  }, [draftSearchQ, activeTab])

  useEffect(() => {
    setAllianceSearchPage(1)
  }, [draftSearchQ])

  const allianceSearchTotalPages = Math.max(1, Math.ceil(allianceSearchResults.length / ALLIANCE_SEARCH_PAGE_SIZE))
  const effectiveAllianceSearchPage = Math.min(Math.max(1, allianceSearchPage), allianceSearchTotalPages)

  useEffect(() => {
    setAllianceSearchPage((p) => Math.min(p, allianceSearchTotalPages))
  }, [allianceSearchTotalPages])

  const pagedAllianceSearchResults = allianceSearchResults.slice(
    (effectiveAllianceSearchPage - 1) * ALLIANCE_SEARCH_PAGE_SIZE,
    effectiveAllianceSearchPage * ALLIANCE_SEARCH_PAGE_SIZE,
  )
  const allianceSearchRangeStart =
    allianceSearchResults.length === 0 ? 0 : (effectiveAllianceSearchPage - 1) * ALLIANCE_SEARCH_PAGE_SIZE + 1
  const allianceSearchRangeEnd =
    allianceSearchResults.length === 0
      ? 0
      : Math.min(effectiveAllianceSearchPage * ALLIANCE_SEARCH_PAGE_SIZE, allianceSearchResults.length)

  const visitTab = (tab: AllianceMembershipTab) => {
    router.get(
      route(ALLIANCE_MEMBERSHIP_ROUTE),
      { tab },
      {
        preserveState: true,
        preserveScroll: true,
        only: [...MEMBERSHIP_PAGE_KEYS],
      },
    )
  }

  const showAllianceDropdown =
    allianceDropdownOpen && draftSearchQ.trim().length >= 2 && canRequestAllianceJoin && activeTab === "join"

  const submitJoinRequest = () => {
    if (!selectedAlliance) {
      toast.error("Select a Unity Impact Alliance first.")
      return
    }
    setSubmittingJoinRequest(true)
    router.post(
      route("organization.care-alliance-join-requests.store"),
      {
        care_alliance_id: selectedAlliance.id,
        message: requestMessage.trim() || null,
      },
      {
        preserveScroll: true,
        onFinish: () => setSubmittingJoinRequest(false),
        onSuccess: () => {
          setSelectedAlliance(null)
          setRequestMessage("")
          setDraftSearchQ("")
        },
        onError: (errors) => {
          const raw = errors.care_alliance_id
          const msg = Array.isArray(raw) ? raw[0] : typeof raw === "string" ? raw : "Could not send request."
          toast.error(msg)
        },
      },
    )
  }

  const respond = (id: number, action: "accept" | "decline") => {
    setActingInvitationId(id)
    const routeName =
      action === "accept" ? "organization.care-alliance.invitations.accept" : "organization.care-alliance.invitations.decline"
    router.post(route(routeName, { invitation: id }), {}, {
      preserveScroll: true,
      onFinish: () => setActingInvitationId(null),
    })
  }

  const breadcrumbs: BreadcrumbItem[] = [
    { title: "Dashboard", href: "/dashboard" },
    { title: "Alliance Membership", href: "/organization/alliance-membership" },
  ]

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title="Alliance Membership" />
      <div className="flex w-full min-w-0 flex-col gap-6 px-3 py-4 md:px-6 md:py-6">
        <div className="flex items-start gap-3">
          <div className="rounded-lg bg-violet-100 dark:bg-violet-950/50 p-2.5 text-violet-700 dark:text-violet-300">
            <HeartHandshake className="h-7 w-7" />
          </div>
          <div>
            <h1 className="text-2xl font-bold tracking-tight">Alliance Membership</h1>
            <p className="text-muted-foreground mt-1 text-sm">
              For <span className="font-medium text-foreground">{organization.name}</span> — each tab loads its own data from the
              server.
            </p>
          </div>
        </div>

        <Tabs value={activeTab} onValueChange={(v) => visitTab(v as AllianceMembershipTab)} className="w-full">
          <TabsList className="mb-2 inline-flex h-auto w-fit max-w-full flex-wrap justify-start gap-1 overflow-visible p-1 pt-2">
            <TabsTrigger value="join" className="shrink-0">
              Request to join
            </TabsTrigger>
            <TabsTrigger value="outgoing" className="shrink-0">
              Your requests
            </TabsTrigger>
            <TabsTrigger
              value="invitations"
              className="relative shrink-0 overflow-visible"
              aria-label={
                pendingInvitationsCount > 0
                  ? `Invitations, ${pendingInvitationsCount} pending`
                  : "Invitations"
              }
            >
              Invitations
              {pendingInvitationsCount > 0 ? (
                <span
                  className="absolute -right-1 -top-1 flex h-[1.125rem] min-w-[1.125rem] items-center justify-center rounded-full bg-primary px-1 text-[10px] font-semibold leading-none text-primary-foreground shadow-sm ring-2 ring-background"
                  aria-hidden
                >
                  {pendingInvitationsCount > 99 ? "99+" : pendingInvitationsCount}
                </span>
              ) : null}
            </TabsTrigger>
            <TabsTrigger value="membership" className="shrink-0">
              Membership
            </TabsTrigger>
            <TabsTrigger value="activity" className="inline-flex shrink-0 items-center gap-1.5">
              <Activity className="h-3.5 w-3.5 opacity-80" aria-hidden />
              Activity
            </TabsTrigger>
          </TabsList>

          <TabsContent value="join" className="mt-4 outline-none">
            <Card className="overflow-visible border-teal-200/70 shadow-sm dark:border-teal-900/45">
              <CardHeader className="space-y-0 border-b bg-muted/30 pb-5 pt-6 dark:bg-muted/20">
                <div className="flex min-w-0 flex-1 gap-3.5">
                  <div
                    className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-teal-500/12 text-teal-700 ring-1 ring-teal-500/20 dark:bg-teal-500/10 dark:text-teal-300 dark:ring-teal-500/25"
                    aria-hidden
                  >
                    <Search className="h-5 w-5" />
                  </div>
                  <div className="min-w-0 space-y-1.5">
                    <CardTitle className="text-xl font-semibold tracking-tight">Request to join a Unity Impact Alliance</CardTitle>
                    <CardDescription className="max-w-2xl text-sm leading-relaxed">
                      Type at least 2 characters to search the server for Unity Impact Alliances. Select one, add an optional message, and send
                      your request.
                    </CardDescription>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-4 p-5 sm:p-6">
                {!canRequestAllianceJoin ? (
                  <p className="rounded-lg border border-amber-200/80 bg-amber-50/80 px-4 py-3 text-sm text-amber-950 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100">
                    Your organization must be <span className="font-medium">approved</span> and linked to a platform user before you
                    can request to join an alliance. Complete registration first, then return here.
                  </p>
                ) : (
                  <>
                    <div ref={allianceComboRef} className="relative z-50">
                      <Label htmlFor="alliance-join-search" className="sr-only">
                        Search Unity Impact Alliances
                      </Label>
                      <div className="relative">
                        <Input
                          id="alliance-join-search"
                          className={`${dashboardInputClass} min-w-0 flex-1 pr-20`}
                          value={draftSearchQ}
                          onChange={(e) => setDraftSearchQ(e.target.value)}
                          onFocus={() => {
                            if (draftSearchQ.trim().length >= 2) setAllianceDropdownOpen(true)
                          }}
                          placeholder="Search Unity Impact Alliances…"
                          autoComplete="off"
                          aria-busy={allianceSearchBusy}
                          aria-expanded={showAllianceDropdown}
                          aria-controls="alliance-join-search-dropdown"
                          aria-autocomplete="list"
                          role="combobox"
                        />
                        <div className="pointer-events-none absolute right-2 top-1/2 flex -translate-y-1/2 items-center gap-1">
                          {allianceSearchBusy ? (
                            <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" aria-hidden />
                          ) : (
                            <ChevronDown
                              className={`h-4 w-4 text-muted-foreground transition-transform ${showAllianceDropdown ? "rotate-180" : ""}`}
                              aria-hidden
                            />
                          )}
                        </div>
                      </div>
                      {showAllianceDropdown && (
                        <div
                          id="alliance-join-search-dropdown"
                          className="absolute left-0 right-0 top-[calc(100%+4px)] z-[100] overflow-hidden rounded-lg border border-border bg-popover text-popover-foreground shadow-lg"
                        >
                          <div className="max-h-72 space-y-1 overflow-y-auto overscroll-contain p-2 [scrollbar-gutter:stable]">
                            {allianceSearchBusy && (
                              <div className="px-3 py-8 text-center text-sm text-muted-foreground" aria-live="polite">
                                Searching…
                              </div>
                            )}
                            {!allianceSearchBusy && allianceSearchResults.length === 0 && (
                              <div className="px-3 py-8 text-center text-sm text-muted-foreground">No alliances match.</div>
                            )}
                            {!allianceSearchBusy &&
                              pagedAllianceSearchResults.map((a) => {
                                const selected = selectedAlliance?.id === a.id
                                return (
                                  <button
                                    key={a.id}
                                    type="button"
                                    disabled={selected}
                                    className={`flex w-full items-center gap-3 rounded-lg border px-3 py-2.5 text-left transition-all outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background ${
                                      selected
                                        ? "cursor-default border-border/60 bg-muted/40 opacity-90"
                                        : "border-transparent bg-muted/20 hover:border-primary/25 hover:bg-muted/50"
                                    }`}
                                    onMouseDown={(e) => e.preventDefault()}
                                    onClick={() => {
                                      if (!selected) {
                                        setSelectedAlliance(a)
                                        setAllianceDropdownOpen(false)
                                      }
                                    }}
                                  >
                                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md border border-border/80 bg-background text-muted-foreground">
                                      {selected ? (
                                        <Check className="h-4 w-4 text-primary" strokeWidth={2.5} aria-hidden />
                                      ) : (
                                        <Plus className="h-4 w-4" aria-hidden />
                                      )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                      <div className="font-medium text-foreground">{a.name}</div>
                                      <div className="mt-0.5 text-xs text-muted-foreground">
                                        /{a.slug}
                                        {a.city || a.state ? ` · ${[a.city, a.state].filter(Boolean).join(", ")}` : ""}
                                      </div>
                                    </div>
                                    {selected ? (
                                      <span className="shrink-0 text-xs font-medium text-primary">Selected</span>
                                    ) : (
                                      <span className="shrink-0 text-xs font-medium text-muted-foreground">Select</span>
                                    )}
                                  </button>
                                )
                              })}
                          </div>
                          {!allianceSearchBusy && allianceSearchTotalPages > 1 && (
                            <div className="flex flex-col gap-2 border-t border-border bg-muted/30 px-2 py-2 sm:flex-row sm:items-center sm:justify-between">
                              <p className="px-1 text-xs text-muted-foreground">
                                {allianceSearchRangeStart}–{allianceSearchRangeEnd} of {allianceSearchResults.length} · Page{" "}
                                {effectiveAllianceSearchPage} of {allianceSearchTotalPages}
                              </p>
                              <div className="flex justify-end gap-1">
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  className="h-8 gap-0.5 px-2"
                                  disabled={effectiveAllianceSearchPage <= 1}
                                  onMouseDown={(e) => e.preventDefault()}
                                  onClick={() => setAllianceSearchPage((p) => Math.max(1, p - 1))}
                                >
                                  <ChevronLeft className="h-4 w-4" aria-hidden />
                                  Prev
                                </Button>
                                <Button
                                  type="button"
                                  variant="ghost"
                                  size="sm"
                                  className="h-8 gap-0.5 px-2"
                                  disabled={effectiveAllianceSearchPage >= allianceSearchTotalPages}
                                  onMouseDown={(e) => e.preventDefault()}
                                  onClick={() => setAllianceSearchPage((p) => Math.min(allianceSearchTotalPages, p + 1))}
                                >
                                  Next
                                  <ChevronRight className="h-4 w-4" aria-hidden />
                                </Button>
                              </div>
                            </div>
                          )}
                        </div>
                      )}
                    </div>
                    <p className="text-xs text-muted-foreground">
                      Alliances you already belong to, already invited you, or that you have a pending request with are hidden from
                      results.
                    </p>
                    {selectedAlliance ? (
                      <div className="space-y-3 rounded-xl border border-border/80 bg-muted/20 p-4">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                          <div>
                            <p className="text-sm font-medium text-muted-foreground">Selected alliance</p>
                            <p className="text-base font-semibold text-foreground">{selectedAlliance.name}</p>
                            <p className="text-xs text-muted-foreground">Slug: {selectedAlliance.slug}</p>
                          </div>
                          <Button type="button" variant="ghost" size="sm" className="shrink-0" onClick={() => setSelectedAlliance(null)}>
                            Clear
                          </Button>
                        </div>
                        <div className="space-y-1.5">
                          <Label htmlFor="join-request-message">Optional message to the alliance</Label>
                          <Textarea
                            id="join-request-message"
                            value={requestMessage}
                            onChange={(e) => setRequestMessage(e.target.value)}
                            placeholder="Introduce your mission or why you would like to collaborate…"
                            rows={4}
                            className="resize-y min-h-[100px]"
                            maxLength={2000}
                          />
                          <p className="text-xs text-muted-foreground">{requestMessage.length} / 2000</p>
                        </div>
                        <div className="flex justify-end">
                          <Button type="button" onClick={() => void submitJoinRequest()} disabled={submittingJoinRequest}>
                            {submittingJoinRequest ? (
                              <>
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden />
                                Sending…
                              </>
                            ) : (
                              "Send membership request"
                            )}
                          </Button>
                        </div>
                      </div>
                    ) : null}
                  </>
                )}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="outgoing" className="mt-4 outline-none">
            <Card className="overflow-hidden border-cyan-200/60 shadow-sm dark:border-cyan-900/40">
              <CardHeader className="space-y-0 border-b bg-muted/30 pb-5 pt-6 dark:bg-muted/20">
                <div className="flex flex-wrap items-center gap-2">
                  <CardTitle className="text-xl font-semibold tracking-tight">Your membership requests</CardTitle>
                  {joinRequests.length > 0 ? (
                    <Badge
                      variant="secondary"
                      className="h-6 border border-border/60 bg-background/80 px-2 font-medium tabular-nums text-muted-foreground"
                    >
                      {joinRequests.length}
                    </Badge>
                  ) : null}
                </div>
                <CardDescription className="pt-1 text-sm leading-relaxed">
                  Outgoing requests you have sent. When an alliance approves, your membership appears under the Membership tab.
                </CardDescription>
              </CardHeader>
              <CardContent className="p-0">
                {joinRequests.length === 0 ? (
                  <div className="flex flex-col items-center justify-center px-6 py-12 text-center">
                    <Layers className="h-10 w-10 text-muted-foreground/40" strokeWidth={1.25} aria-hidden />
                    <p className="mt-3 text-sm font-medium text-foreground">No requests sent yet</p>
                    <p className="mt-1 max-w-md text-sm text-muted-foreground leading-relaxed">
                      Open <span className="font-medium text-foreground/90">Request to join</span> to find an alliance and submit a
                      request.
                    </p>
                  </div>
                ) : (
                  <ul className="divide-y divide-border/70">
                    {joinRequests.map((jr) => (
                      <li key={jr.id} className="px-5 py-4 sm:px-6">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                          <div className="min-w-0 space-y-1">
                            <p className="text-base font-semibold text-foreground">{jr.alliance.name ?? "Unity Impact Alliance"}</p>
                            <p className="text-xs text-muted-foreground">/{jr.alliance.slug}</p>
                            {jr.created_at ? (
                              <p className="text-xs text-muted-foreground">Sent {formatMembershipTimestamp(jr.created_at)}</p>
                            ) : null}
                            {jr.reviewed_at && jr.status !== "pending" ? (
                              <p className="text-xs text-muted-foreground">Reviewed {formatMembershipTimestamp(jr.reviewed_at)}</p>
                            ) : null}
                            {jr.message?.trim() ? (
                              <p className="mt-2 max-w-xl rounded-md border border-border/60 bg-muted/30 px-3 py-2 text-sm text-foreground">
                                {jr.message.trim()}
                              </p>
                            ) : null}
                          </div>
                          <Badge
                            variant={outgoingJoinRequestBadgeVariant(jr.status)}
                            className={cn(
                              "h-7 w-fit shrink-0 capitalize",
                              jr.status === "pending"
                                ? "border-sky-200/90 bg-sky-50 text-sky-950 dark:border-sky-900/50 dark:bg-sky-950/35 dark:text-sky-50"
                                : "",
                            )}
                          >
                            {outgoingJoinRequestLabel(jr.status)}
                          </Badge>
                        </div>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="invitations" className="mt-4 outline-none" id="care-alliance-invitations">
            <Card className="scroll-mt-24 overflow-hidden border-violet-200/70 shadow-sm dark:border-violet-900/50">
              <CardHeader className="space-y-0 border-b bg-muted/30 pb-5 pt-6 dark:bg-muted/20">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                  <div className="flex min-w-0 flex-1 gap-3.5">
                    <div
                      className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-violet-500/15 text-violet-700 ring-1 ring-violet-500/20 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/25"
                      aria-hidden
                    >
                      <Mail className="h-5 w-5" />
                    </div>
                    <div className="min-w-0 space-y-1.5">
                      <div className="flex flex-wrap items-center gap-2">
                        <CardTitle className="text-xl font-semibold tracking-tight">Pending invitations</CardTitle>
                        {invitations.length > 0 ? (
                          <Badge
                            variant="secondary"
                            className="h-6 border border-border/60 bg-background/80 px-2 font-medium tabular-nums text-muted-foreground"
                          >
                            {invitations.length} open
                          </Badge>
                        ) : null}
                      </div>
                      <CardDescription className="max-w-2xl text-sm leading-relaxed">
                        Unity Impact Alliances that want <span className="font-medium text-foreground/90">{organization.name}</span> in their
                        network. Review each request and accept to join shared programs, or decline if it is not a fit.
                      </CardDescription>
                    </div>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="p-0">
                {invitations.length === 0 ? (
                  <div className="flex flex-col items-center justify-center px-6 py-14 text-center">
                    <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-muted ring-1 ring-border/60">
                      <Inbox className="h-7 w-7 text-muted-foreground" strokeWidth={1.5} />
                    </div>
                    <p className="text-base font-medium text-foreground">You&apos;re all caught up</p>
                    <p className="mt-1.5 max-w-sm text-sm text-muted-foreground leading-relaxed">
                      There are no pending Unity Impact Alliance invitations for your organization. New invites will appear here and in your
                      notifications.
                    </p>
                  </div>
                ) : (
                  <ul className="divide-y divide-border/70">
                    {invitations.map((inv) => {
                      const busy = actingInvitationId === inv.id
                      const allianceLabel = inv.alliance.name?.trim() || "Unity Impact Alliance"
                      return (
                        <li key={inv.id}>
                          <div className="flex flex-col gap-5 p-5 sm:flex-row sm:items-stretch sm:justify-between sm:gap-6 sm:p-6">
                            <div className="flex min-w-0 flex-1 gap-4">
                              <div
                                className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500/12 to-fuchsia-500/8 ring-1 ring-border/50 dark:from-violet-500/15 dark:to-fuchsia-500/10"
                                aria-hidden
                              >
                                <HeartHandshake className="h-6 w-6 text-violet-600 dark:text-violet-400" strokeWidth={1.75} />
                              </div>
                              <div className="min-w-0 flex-1 space-y-3">
                                <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:gap-2">
                                  <h3 className="text-base font-semibold leading-tight tracking-tight text-foreground">{allianceLabel}</h3>
                                  <Badge
                                    variant="outline"
                                    className="w-fit border-amber-200/90 bg-amber-50/90 text-xs font-medium text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-100/90"
                                  >
                                    Awaiting your response
                                  </Badge>
                                </div>
                                <div className="flex flex-col gap-2 text-sm text-muted-foreground sm:flex-row sm:items-center sm:gap-x-6 sm:gap-y-1">
                                  <div className="flex items-start gap-2 sm:items-center">
                                    <CalendarClock className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground/80" aria-hidden />
                                    <span className="leading-snug">
                                      <span className="font-medium text-foreground/80">Invitation window — </span>
                                      {formatInvitationExpiry(inv.expires_at)}
                                    </span>
                                  </div>
                                  {inv.email ? (
                                    <>
                                      <Separator orientation="vertical" className="hidden h-4 self-center sm:block" />
                                      <span className="text-xs sm:text-sm">
                                        <span className="font-medium text-foreground/80">Sent to </span>
                                        <span className="break-all">{inv.email}</span>
                                      </span>
                                    </>
                                  ) : null}
                                </div>
                              </div>
                            </div>
                            <Separator className="sm:hidden" />
                            <div className="flex w-full shrink-0 flex-col gap-2 sm:w-auto sm:min-w-[200px] sm:justify-center sm:border-l sm:border-border/60 sm:pl-6">
                              <Button size="sm" className="w-full sm:w-auto" disabled={busy} onClick={() => respond(inv.id, "accept")}>
                                {busy ? (
                                  <>
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    Working…
                                  </>
                                ) : (
                                  "Accept invitation"
                                )}
                              </Button>
                              <Button
                                size="sm"
                                variant="outline"
                                className="w-full border-border/80 sm:w-auto"
                                disabled={busy}
                                onClick={() => respond(inv.id, "decline")}
                              >
                                Decline
                              </Button>
                            </div>
                          </div>
                        </li>
                      )
                    })}
                  </ul>
                )}
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="membership" className="mt-4 outline-none">
            <Card className="overflow-hidden border-slate-200/80 shadow-sm dark:border-slate-800/80">
              <CardHeader className="space-y-0 border-b bg-muted/30 pb-5 pt-6 dark:bg-muted/20">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                  <div className="flex min-w-0 flex-1 gap-3.5">
                    <div
                      className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-500/12 text-slate-700 ring-1 ring-slate-500/15 dark:bg-slate-500/10 dark:text-slate-300 dark:ring-slate-500/25"
                      aria-hidden
                    >
                      <Layers className="h-5 w-5" strokeWidth={1.75} />
                    </div>
                    <div className="min-w-0 space-y-1.5">
                      <div className="flex flex-wrap items-center gap-2">
                        <CardTitle className="text-xl font-semibold tracking-tight">Membership &amp; history</CardTitle>
                        {memberships.length > 0 ? (
                          <Badge
                            variant="secondary"
                            className="h-6 border border-border/60 bg-background/80 px-2 font-medium tabular-nums text-muted-foreground"
                          >
                            {memberships.length} {memberships.length === 1 ? "alliance" : "alliances"}
                          </Badge>
                        ) : null}
                      </div>
                      <CardDescription className="max-w-2xl text-sm leading-relaxed">
                        Every Unity Impact Alliance your organization has joined, is waiting on, or has stepped back from.
                      </CardDescription>
                    </div>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="p-0">
                {memberships.length === 0 ? (
                  <div className="flex flex-col items-center justify-center px-6 py-14 text-center">
                    <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-muted ring-1 ring-border/60">
                      <Building2 className="h-7 w-7 text-muted-foreground" strokeWidth={1.5} />
                    </div>
                    <p className="text-base font-medium text-foreground">No membership history yet</p>
                    <p className="mt-1.5 max-w-md text-sm text-muted-foreground leading-relaxed">
                      When you accept an invitation or a join request is approved, that alliance will show here.
                    </p>
                  </div>
                ) : (
                  <ul className="divide-y divide-border/70">
                    {memberships.map((m) => {
                      const allianceLabel = m.alliance.name?.trim() || "Unity Impact Alliance"
                      const statusExtra = statusBadgeClassName(m.status)
                      return (
                        <li key={m.id}>
                          <div className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center sm:justify-between sm:gap-6 sm:p-6">
                            <div className="flex min-w-0 flex-1 gap-4">
                              <div
                                className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-slate-500/12 to-slate-400/6 ring-1 ring-border/50 dark:from-slate-500/18 dark:to-slate-400/8"
                                aria-hidden
                              >
                                <Building2 className="h-6 w-6 text-slate-600 dark:text-slate-400" strokeWidth={1.75} />
                              </div>
                              <div className="min-w-0 flex-1 space-y-2">
                                <h3 className="text-base font-semibold leading-tight tracking-tight text-foreground">{allianceLabel}</h3>
                                <div className="flex items-start gap-2 text-sm text-muted-foreground">
                                  <History className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground/80" aria-hidden />
                                  <span className="leading-snug">{membershipTimelineSummary(m)}</span>
                                </div>
                              </div>
                            </div>
                            <div className="flex shrink-0 sm:pl-2">
                              <Badge
                                variant={statusBadgeVariant(m.status)}
                                className={cn("w-fit px-2.5 py-0.5 text-xs font-medium capitalize", statusExtra)}
                              >
                                {membershipStatusLabel(m.status)}
                              </Badge>
                            </div>
                          </div>
                        </li>
                      )
                    })}
                  </ul>
                )}
                <div className="border-t border-border/70 bg-muted/20 px-5 py-4 dark:bg-muted/10 sm:px-6">
                  <p className="text-xs text-muted-foreground leading-relaxed">
                    Public campaigns for an alliance you belong to may appear on that alliance&apos;s fundraising pages when they are
                    published.
                  </p>
                  <p className="mt-3 text-xs text-muted-foreground">
                    <button
                      type="button"
                      onClick={() => visitTab("activity")}
                      className="font-medium text-violet-600 underline-offset-4 hover:underline dark:text-violet-400"
                    >
                      View alliance split activity
                    </button>{" "}
                    — wallet credits from shared donations.
                  </p>
                </div>
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="activity" className="mt-4 outline-none">
            <Card className="overflow-hidden border-emerald-200/70 shadow-sm dark:border-emerald-900/40">
              <CardHeader className="space-y-0 border-b bg-muted/30 pb-5 pt-6 dark:bg-muted/20">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                  <div className="flex min-w-0 flex-1 gap-3.5">
                    <div
                      className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-500/12 text-emerald-700 ring-1 ring-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/25"
                      aria-hidden
                    >
                      <Activity className="h-5 w-5" strokeWidth={1.75} />
                    </div>
                    <div className="min-w-0 space-y-1.5">
                      <CardTitle className="text-xl font-semibold tracking-tight">Activity</CardTitle>
                      <CardDescription className="max-w-2xl text-sm leading-relaxed">
                        Transaction-style log of Unity Impact Alliance member share credits to{" "}
                        <span className="font-medium text-foreground/90">{organization.name}</span>&apos;s wallet (up to 50 recent).
                      </CardDescription>
                    </div>
                  </div>
                  {allianceWalletActivity.length > 0 ? (
                    <Badge
                      variant="secondary"
                      className="h-fit shrink-0 border border-border/60 bg-background/80 px-2.5 py-1 text-xs font-medium tabular-nums text-muted-foreground"
                    >
                      {allianceWalletActivity.length} shown
                    </Badge>
                  ) : null}
                </div>
              </CardHeader>
              <CardContent className="p-5 sm:p-6">
                <AllianceWalletActivityPanel rows={allianceWalletActivity} variant="page" />
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>

        <p className="text-sm text-muted-foreground">
          <Link href="/dashboard" className="text-violet-600 hover:underline dark:text-violet-400">
            Back to dashboard
          </Link>
        </p>
      </div>
    </AppLayout>
  )
}
