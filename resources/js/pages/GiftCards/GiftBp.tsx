"use client"

import { Head, router, useForm, usePage, Link } from "@inertiajs/react"
import { useCallback, useEffect, useMemo, useState } from "react"
import ProfileLayout from "@/components/frontend/layout/user-profile-layout"
import AppSidebarLayout from "@/layouts/app/app-sidebar-layout"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { Button } from "@/components/frontend/ui/button"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Textarea } from "@/components/frontend/ui/textarea"
import { Badge } from "@/components/frontend/ui/badge"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/frontend/ui/select"
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/frontend/ui/alert-dialog"
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/frontend/ui/dialog"
import {
  Gift,
  Loader2,
  Search,
  ArrowLeft,
  Clock,
  Mail,
  UserRound,
  Sparkles,
  RefreshCw,
  Pencil,
  XCircle,
} from "lucide-react"
import toast from "react-hot-toast"

interface GiftOccasion {
  id: number
  occasion: string
  icon: string | null
  category: string | null
}

interface SearchResult {
  id: number
  name: string
  email: string
  slug: string | null
  image: string | null
  display_name: string
}

interface SentGift {
  id: string
  invite_id: number | null
  legacy_gift_id: number | null
  recipient_name: string | null
  recipient_email: string | null
  recipient_label: string
  amount: number
  status: string
  occasion: string | null
  claimed_at: string | null
  expires_at: string | null
  created_at: string | null
  is_registered_recipient: boolean
  can_cancel: boolean
  can_resend: boolean
  can_change_email: boolean
}

interface GiftToCollect {
  id: number
  amount: number
  occasion: string | null
  message: string | null
  expires_at: string | null
  created_at: string | null
  sender: {
    id: number
    name: string
    email: string
    image: string | null
  } | null
}

interface GiftBpPageProps {
  senderBalances: {
    purchased_believe_points: number
    gifted_believe_points: number
    holding_believe_points: number
  }
  giftOccasions: GiftOccasion[]
  holdDays: number
  sentGifts: SentGift[]
  giftsToCollect: GiftToCollect[]
  statusFilter: string
  preselectedRecipient?: SearchResult | null
  viewerRole?: string | null
  flash?: { success?: string }
  errors?: Record<string, string>
}

const PRESETS = [10, 25, 50] as const

export default function GiftBpPage() {
  const {
    senderBalances,
    giftOccasions,
    holdDays,
    sentGifts = [],
    giftsToCollect = [],
    statusFilter: initialStatusFilter = "all",
    preselectedRecipient = null,
    viewerRole = null,
    flash,
    errors: pageErrors,
  } = usePage().props as unknown as GiftBpPageProps

  const isOrgViewer =
    viewerRole === "organization" ||
    viewerRole === "organization_pending" ||
    viewerRole === "care_alliance" ||
    viewerRole === "admin"

  const backHref = isOrgViewer ? "/gift-cards" : "/gift-cards/my-cards"
  const backLabel = isOrgViewer ? "Gift Cards" : "My Gift Cards"

  const [query, setQuery] = useState("")
  const [searching, setSearching] = useState(false)
  const [results, setResults] = useState<SearchResult[]>([])
  const [inviteEmail, setInviteEmail] = useState<string | null>(null)
  const [selected, setSelected] = useState<SearchResult | null>(preselectedRecipient)
  const [mode, setMode] = useState<"user" | "invite" | null>(preselectedRecipient ? "user" : null)
  const [preset, setPreset] = useState<number | "custom">(10)
  const [cancelTarget, setCancelTarget] = useState<SentGift | null>(null)
  const [editTarget, setEditTarget] = useState<SentGift | null>(null)
  const [editEmail, setEditEmail] = useState("")
  const [inviteActionId, setInviteActionId] = useState<number | null>(null)
  const [inviteAction, setInviteAction] = useState<"resend" | "cancel" | "email" | "claim" | null>(null)
  const [statusFilter, setStatusFilter] = useState(initialStatusFilter || "all")
  const [claimingId, setClaimingId] = useState<number | null>(null)

  const defaultOccasionId = giftOccasions[0]?.id ?? 0

  const { data, setData, post, processing, errors, reset } = useForm({
    mode: (preselectedRecipient ? "user" : "user") as "user" | "invite",
    recipient_id: preselectedRecipient?.id ?? (null as number | null),
    email: "",
    amount: 10,
    gift_occasion_id: defaultOccasionId,
    message: "",
  })

  useEffect(() => {
    if (!preselectedRecipient) return
    setSelected(preselectedRecipient)
    setMode("user")
    setData("mode", "user")
    setData("recipient_id", preselectedRecipient.id)
    setData("email", "")
  }, [preselectedRecipient?.id])

  useEffect(() => {
    if (flash?.success) toast.success(flash.success)
  }, [flash?.success])

  useEffect(() => {
    const err = pageErrors?.amount || pageErrors?.email || pageErrors?.recipient || pageErrors?.error
    if (err) toast.error(err)
  }, [pageErrors])

  const runSearch = useCallback(async (q: string) => {
    const trimmed = q.trim()
    if (trimmed.length < 2) {
      setResults([])
      setInviteEmail(null)
      return
    }
    setSearching(true)
    try {
      const res = await fetch(`/gift-bp/search?q=${encodeURIComponent(trimmed)}`, {
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
      })
      const json = (await res.json()) as { results: SearchResult[]; invite_email: string | null }
      setResults(json.results ?? [])
      setInviteEmail(json.invite_email ?? null)
    } catch {
      toast.error("Search failed. Try again.")
    } finally {
      setSearching(false)
    }
  }, [])

  useEffect(() => {
    const t = window.setTimeout(() => runSearch(query), 300)
    return () => window.clearTimeout(t)
  }, [query, runSearch])

  const selectUser = (user: SearchResult) => {
    setSelected(user)
    setMode("user")
    setInviteEmail(null)
    setData("mode", "user")
    setData("recipient_id", user.id)
    setData("email", "")
  }

  const selectInvite = (email: string) => {
    setSelected(null)
    setMode("invite")
    setInviteEmail(email)
    setData("mode", "invite")
    setData("recipient_id", null)
    setData("email", email)
  }

  const clearRecipient = () => {
    setSelected(null)
    setMode(null)
    setInviteEmail(null)
    setData("mode", "user")
    setData("recipient_id", null)
    setData("email", "")
  }

  const applyPreset = (v: number | "custom") => {
    setPreset(v)
    if (v !== "custom") setData("amount", v)
  }

  const purchased = senderBalances.purchased_believe_points
  const holding = senderBalances.holding_believe_points
  const canSubmit = mode !== null && data.amount > 0 && purchased >= data.amount && !processing

  const recipientLabel = useMemo(() => {
    if (mode === "user" && selected) return selected.name
    if (mode === "invite" && data.email) return data.email
    return null
  }, [mode, selected, data.email])

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!canSubmit) return
    post("/gift-bp/send", {
      preserveScroll: true,
      onSuccess: () => {
        clearRecipient()
        setQuery("")
        setResults([])
        reset("amount", "message", "email", "recipient_id")
        setData("gift_occasion_id", defaultOccasionId)
        setData("amount", 10)
        setPreset(10)
      },
      onError: (errs) => {
        const msg = errs.amount || errs.email || errs.recipient || "Could not send gift."
        toast.error(msg)
      },
    })
  }

  const runInviteAction = (
    gift: SentGift,
    action: "resend" | "cancel" | "email",
    payload?: Record<string, string>,
  ) => {
    if (!gift.invite_id) return
    const urls = {
      resend: `/gift-bp/invites/${gift.invite_id}/resend`,
      cancel: `/gift-bp/invites/${gift.invite_id}/cancel`,
      email: `/gift-bp/invites/${gift.invite_id}/email`,
    } as const

    setInviteActionId(gift.invite_id)
    setInviteAction(action)

    router.post(urls[action], payload ?? {}, {
      preserveScroll: true,
      onSuccess: () => {
        if (action === "cancel") setCancelTarget(null)
        if (action === "email") {
          setEditTarget(null)
          setEditEmail("")
        }
      },
      onError: (errs) => {
        const msg =
          errs.invite || errs.email || errs.error || "Could not update this gift."
        toast.error(typeof msg === "string" ? msg : "Could not update this gift.")
      },
      onFinish: () => {
        setInviteActionId(null)
        setInviteAction(null)
      },
    })
  }

  const claimGift = (gift: GiftToCollect) => {
    setClaimingId(gift.id)
    setInviteAction("claim")
    router.post(`/gift-bp/gifts/${gift.id}/claim`, {}, {
      preserveScroll: true,
      onError: (errs) => {
        const msg = errs.invite || errs.error || "Could not collect this gift."
        toast.error(typeof msg === "string" ? msg : "Could not collect this gift.")
      },
      onFinish: () => {
        setClaimingId(null)
        setInviteAction(null)
      },
    })
  }

  const applyStatusFilter = (next: string) => {
    setStatusFilter(next)
    router.get(
      "/gift-bp",
      next === "all" ? {} : { status: next },
      { preserveState: true, preserveScroll: true, only: ["sentGifts", "statusFilter", "senderBalances", "giftsToCollect"] },
    )
  }

  const openEditEmail = (gift: SentGift) => {
    if (!gift.can_change_email || !gift.recipient_email) return
    setEditTarget(gift)
    setEditEmail(gift.recipient_email)
  }

  const submitEditEmail = (e: React.FormEvent) => {
    e.preventDefault()
    if (!editTarget?.recipient_email) return
    const next = editEmail.trim().toLowerCase()
    if (!next || next === editTarget.recipient_email.toLowerCase()) {
      toast.error("Enter a different email address.")
      return
    }
    runInviteAction(editTarget, "email", { email: next })
  }

  const statusBadgeClass = (status: string) => {
    switch (status) {
      case "pending":
        return "border-amber-500/40 text-amber-800 dark:text-amber-200"
      case "claimed":
        return "border-emerald-500/40 text-emerald-800 dark:text-emerald-200"
      case "cancelled":
        return "border-rose-500/40 text-rose-800 dark:text-rose-200"
      case "expired":
        return "border-slate-400/40 text-slate-700 dark:text-slate-300"
      default:
        return ""
    }
  }

  const formatDate = (iso: string | null) =>
    iso
      ? new Date(iso).toLocaleDateString(undefined, {
          month: "short",
          day: "numeric",
          year: "numeric",
        })
      : "—"

  const pageBody = (
      <div
        className={
          isOrgViewer
            ? "container mx-auto flex h-full flex-1 flex-col gap-8 px-4 py-4 md:px-10 md:py-6"
            : "space-y-8"
        }
      >
        <div className="flex items-center justify-between gap-3">
          <Link
            href={backHref}
            className="inline-flex items-center text-sm text-muted-foreground transition-colors hover:text-foreground"
          >
            <ArrowLeft className="mr-1.5 h-4 w-4" />
            {backLabel}
          </Link>
        </div>

        {/* Hero */}
        <header className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-purple-600 via-purple-600 to-blue-600 px-5 py-8 text-white shadow-lg sm:px-8 sm:py-10">
          <div
            className="pointer-events-none absolute inset-0 opacity-30"
            style={{
              backgroundImage:
                "radial-gradient(circle at 15% 20%, rgba(255,255,255,0.35), transparent 45%), radial-gradient(circle at 85% 10%, rgba(255,255,255,0.2), transparent 40%), radial-gradient(circle at 70% 90%, rgba(59,130,246,0.5), transparent 50%)",
            }}
          />
          <div className="relative flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div className="max-w-xl">
              <p className="text-sm font-medium text-white/80">Believe Points</p>
              <h1 className="mt-1 text-3xl font-semibold tracking-tight sm:text-4xl">Gift BP</h1>
              <p className="mt-3 text-sm leading-relaxed text-white/85 sm:text-base">
                Send Believe Points like a real gift. They stay in Holding until the recipient Accepts —
                then land in their Gifted BP wallet.
              </p>
            </div>
            <div className="grid grid-cols-3 gap-2 sm:gap-3 lg:min-w-[22rem]">
              <div className="rounded-2xl bg-white/15 px-3 py-3 backdrop-blur-sm sm:px-4">
                <p className="text-[11px] uppercase tracking-wide text-white/70">Available</p>
                <p className="mt-1 text-lg font-semibold tabular-nums sm:text-xl">{purchased.toFixed(0)}</p>
              </div>
              <div className="rounded-2xl bg-amber-400/25 px-3 py-3 backdrop-blur-sm sm:px-4">
                <p className="text-[11px] uppercase tracking-wide text-amber-50/90">Holding</p>
                <p className="mt-1 text-lg font-semibold tabular-nums sm:text-xl">{holding.toFixed(0)}</p>
              </div>
              <div className="rounded-2xl bg-white/15 px-3 py-3 backdrop-blur-sm sm:px-4">
                <p className="text-[11px] uppercase tracking-wide text-white/70">Window</p>
                <p className="mt-1 text-lg font-semibold sm:text-xl">{holdDays}d</p>
              </div>
            </div>
          </div>
        </header>

        {/* Collect inbox — first when waiting */}
        {giftsToCollect.length > 0 && (
          <section className="space-y-4">
            <div className="flex items-end justify-between gap-3">
              <div>
                <h2 className="text-lg font-semibold tracking-tight dark:text-white">Gifts to collect</h2>
                <p className="text-sm text-muted-foreground">
                  Accept to add BP to your gift wallet.
                </p>
              </div>
              <Badge className="bg-gradient-to-r from-purple-600 to-blue-600 text-white border-0">
                {giftsToCollect.length} waiting
              </Badge>
            </div>
            <div className="grid gap-3">
              {giftsToCollect.map((gift) => {
                const busy = claimingId === gift.id
                return (
                  <div
                    key={gift.id}
                    className="flex flex-col gap-4 rounded-2xl border border-purple-500/20 bg-gradient-to-r from-purple-600/[0.06] to-blue-600/[0.06] p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5 dark:from-purple-500/10 dark:to-blue-500/10"
                  >
                    <div className="flex min-w-0 items-start gap-3">
                      {gift.sender?.image ? (
                        <img
                          src={gift.sender.image}
                          alt=""
                          className="h-12 w-12 rounded-full object-cover ring-2 ring-purple-500/20"
                        />
                      ) : (
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-blue-600 text-sm font-semibold text-white">
                          {(gift.sender?.name || "?")[0]}
                        </div>
                      )}
                      <div className="min-w-0">
                        <p className="font-medium dark:text-white">
                          {gift.sender?.name ?? "Someone"}{" "}
                          <span className="text-muted-foreground font-normal">sent you</span>{" "}
                          <span className="bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent tabular-nums">
                            {Number(gift.amount).toFixed(2)} BP
                          </span>
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                          {gift.occasion ? `${gift.occasion} · ` : ""}
                          Expires {formatDate(gift.expires_at)}
                        </p>
                        {gift.message ? (
                          <p className="mt-2 text-sm text-muted-foreground italic">
                            &ldquo;{gift.message}&rdquo;
                          </p>
                        ) : null}
                      </div>
                    </div>
                    <Button
                      type="button"
                      className="h-11 shrink-0 bg-gradient-to-r from-purple-600 to-blue-600 px-5 shadow-md shadow-purple-600/20"
                      disabled={busy}
                      onClick={() => claimGift(gift)}
                    >
                      {busy ? (
                        <>
                          <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                          Collecting…
                        </>
                      ) : (
                        <>
                          <Gift className="mr-2 h-4 w-4" />
                          Accept / Collect
                        </>
                      )}
                    </Button>
                  </div>
                )
              })}
            </div>
          </section>
        )}

        {/* Send composer */}
        <section className="grid gap-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(16rem,0.7fr)] lg:items-start">
          <div className="overflow-hidden rounded-3xl border border-purple-500/15 bg-card shadow-sm dark:bg-slate-900/50">
            <div className="border-b border-purple-500/10 bg-gradient-to-r from-purple-600/[0.07] to-blue-600/[0.07] px-5 py-4 sm:px-6">
              <h2 className="text-lg font-semibold tracking-tight dark:text-white">Send a gift</h2>
              <p className="mt-0.5 text-sm text-muted-foreground">
                Find someone by name or email, then choose amount and occasion.
              </p>
            </div>

            <div className="space-y-6 p-5 sm:p-6">
              {/* Recipient step */}
              <div className="space-y-3">
                <div className="flex items-center gap-2">
                  <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gradient-to-r from-purple-600 to-blue-600 text-[11px] font-semibold text-white">
                    1
                  </span>
                  <Label className="text-sm font-medium">Who is this for?</Label>
                </div>

                {!recipientLabel ? (
                  <div className="space-y-3">
                    <div className="relative">
                      <Search className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                      <Input
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        placeholder="Search by name or email…"
                        className="h-12 rounded-xl border-purple-500/20 pl-10 focus-visible:ring-purple-500/30"
                        autoComplete="off"
                      />
                      {searching && (
                        <Loader2 className="absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-muted-foreground" />
                      )}
                    </div>

                    {results.length > 0 && (
                      <ul className="overflow-hidden rounded-2xl border border-purple-500/15 divide-y dark:divide-white/10">
                        {results.map((user) => (
                          <li key={user.id}>
                            <button
                              type="button"
                              onClick={() => selectUser(user)}
                              className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-purple-500/5"
                            >
                              {user.image ? (
                                <img src={user.image} alt="" className="h-10 w-10 rounded-full object-cover" />
                              ) : (
                                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-purple-600/20 to-blue-600/20 text-sm font-semibold">
                                  {(user.name || "?")[0]}
                                </div>
                              )}
                              <div className="min-w-0">
                                <p className="truncate font-medium dark:text-white">{user.name}</p>
                                <p className="truncate text-xs text-muted-foreground">{user.email}</p>
                              </div>
                              <UserRound className="ml-auto h-4 w-4 shrink-0 text-muted-foreground" />
                            </button>
                          </li>
                        ))}
                      </ul>
                    )}

                    {inviteEmail && (
                      <button
                        type="button"
                        onClick={() => selectInvite(inviteEmail)}
                        className="flex w-full items-start gap-3 rounded-2xl border border-dashed border-purple-500/40 bg-purple-500/[0.04] p-4 text-left transition-colors hover:bg-purple-500/[0.08]"
                      >
                        <Mail className="mt-0.5 h-5 w-5 shrink-0 text-purple-600" />
                        <div className="min-w-0">
                          <p className="font-medium dark:text-white">Invite {inviteEmail}</p>
                          <p className="mt-1 text-sm text-muted-foreground">
                            Not registered yet. We’ll email an invite and hold your BP for {holdDays} days.
                          </p>
                        </div>
                        <Sparkles className="ml-auto h-4 w-4 shrink-0 text-purple-500" />
                      </button>
                    )}

                    {query.trim().length >= 2 && !searching && results.length === 0 && !inviteEmail && (
                      <p className="text-sm text-muted-foreground">
                        No supporters found. Enter a full email address to send an invite gift.
                      </p>
                    )}
                  </div>
                ) : (
                  <div className="flex items-center justify-between gap-3 rounded-2xl border border-purple-500/25 bg-gradient-to-r from-purple-600/[0.06] to-blue-600/[0.06] px-4 py-3.5">
                    <div className="flex min-w-0 items-center gap-3">
                      {mode === "user" && selected?.image ? (
                        <img src={selected.image} alt="" className="h-10 w-10 rounded-full object-cover" />
                      ) : (
                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-blue-600 text-white">
                          {mode === "invite" ? (
                            <Mail className="h-4 w-4" />
                          ) : (
                            <span className="text-sm font-semibold">{(recipientLabel || "?")[0]}</span>
                          )}
                        </div>
                      )}
                      <div className="min-w-0">
                        <p className="text-xs text-muted-foreground">
                          {mode === "invite" ? "Invite & hold" : "Sending to"}
                        </p>
                        <p className="truncate font-semibold dark:text-white">{recipientLabel}</p>
                        {mode === "invite" && (
                          <p className="mt-0.5 text-xs text-amber-700 dark:text-amber-300">
                            BP moves to Holding until they register
                          </p>
                        )}
                      </div>
                    </div>
                    <Button type="button" variant="outline" size="sm" className="rounded-full" onClick={clearRecipient}>
                      Change
                    </Button>
                  </div>
                )}
              </div>

              {/* Details — only after recipient */}
              {mode && (
                <form onSubmit={submit} className="space-y-5 border-t border-purple-500/10 pt-6">
                  <div className="flex items-center gap-2">
                    <span className="flex h-6 w-6 items-center justify-center rounded-full bg-gradient-to-r from-purple-600 to-blue-600 text-[11px] font-semibold text-white">
                      2
                    </span>
                    <Label className="text-sm font-medium">Gift details</Label>
                  </div>

                  <div>
                    <Label className="text-muted-foreground">Occasion</Label>
                    <Select
                      value={String(data.gift_occasion_id)}
                      onValueChange={(value) => setData("gift_occasion_id", Number(value))}
                    >
                      <SelectTrigger className="mt-2 h-11 rounded-xl">
                        <SelectValue placeholder="Choose an occasion" />
                      </SelectTrigger>
                      <SelectContent>
                        {giftOccasions.map((occasion) => (
                          <SelectItem key={occasion.id} value={String(occasion.id)}>
                            {occasion.icon ? `${occasion.icon} ` : ""}
                            {occasion.occasion}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {(errors.gift_occasion_id || pageErrors?.gift_occasion_id) && (
                      <p className="mt-1 text-sm text-destructive">
                        {errors.gift_occasion_id || pageErrors?.gift_occasion_id}
                      </p>
                    )}
                  </div>

                  <div>
                    <Label className="text-muted-foreground">Amount (Believe Points)</Label>
                    <div className="mt-2 grid grid-cols-4 gap-2">
                      {PRESETS.map((n) => (
                        <button
                          key={n}
                          type="button"
                          onClick={() => applyPreset(n)}
                          className={`h-11 rounded-xl text-sm font-semibold tabular-nums transition-all ${
                            preset === n
                              ? "bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-md shadow-purple-600/20"
                              : "border border-input bg-background hover:border-purple-500/40 hover:bg-purple-500/5"
                          }`}
                        >
                          {n}
                        </button>
                      ))}
                      <button
                        type="button"
                        onClick={() => applyPreset("custom")}
                        className={`h-11 rounded-xl text-sm font-medium transition-all ${
                          preset === "custom"
                            ? "bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-md shadow-purple-600/20"
                            : "border border-input bg-background hover:border-purple-500/40 hover:bg-purple-500/5"
                        }`}
                      >
                        Custom
                      </button>
                    </div>
                    {preset === "custom" && (
                      <Input
                        type="number"
                        step="0.01"
                        min={0.01}
                        className="mt-2 h-11 rounded-xl"
                        value={data.amount}
                        onChange={(e) => setData("amount", parseFloat(e.target.value) || 0)}
                      />
                    )}
                    {(errors.amount || pageErrors?.amount) && (
                      <p className="mt-1 text-sm text-destructive">{errors.amount || pageErrors?.amount}</p>
                    )}
                  </div>

                  <div>
                    <Label className="text-muted-foreground">Message (optional)</Label>
                    <Textarea
                      className="mt-2 min-h-[88px] rounded-xl resize-none"
                      placeholder="Add a short personal note…"
                      value={data.message}
                      onChange={(e) => setData("message", e.target.value)}
                      maxLength={500}
                    />
                  </div>

                  <div className="flex items-start gap-2.5 rounded-2xl border border-amber-500/25 bg-amber-500/[0.07] p-3.5 text-sm text-amber-950 dark:text-amber-100">
                    <Clock className="mt-0.5 h-4 w-4 shrink-0 text-amber-600 dark:text-amber-300" />
                    <p className="leading-relaxed">
                      <strong className="font-semibold">{data.amount.toFixed(2)} BP</strong> moves to Holding
                      until {mode === "invite" ? data.email : recipientLabel} accepts
                      {mode === "invite" ? " (after registering)" : ""}. Cancel anytime before accept;
                      unclaimed gifts return after {holdDays} days.
                    </p>
                  </div>

                  <Button
                    type="submit"
                    className="h-12 w-full rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 text-base shadow-lg shadow-purple-600/25"
                    disabled={!canSubmit}
                  >
                    {processing ? (
                      <>
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        Sending…
                      </>
                    ) : mode === "invite" ? (
                      "Send invite & hold BP"
                    ) : (
                      "Send gift & hold BP"
                    )}
                  </Button>
                </form>
              )}
            </div>
          </div>

          {/* Side tips */}
          <aside className="space-y-4 lg:sticky lg:top-6">
            <div className="rounded-3xl border border-purple-500/15 bg-gradient-to-b from-purple-600/[0.06] to-transparent p-5 dark:from-purple-500/10">
              <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-gradient-to-br from-purple-600 to-blue-600 text-white">
                <Gift className="h-5 w-5" />
              </div>
              <h3 className="mt-4 font-semibold dark:text-white">How gifting works</h3>
              <ol className="mt-3 space-y-3 text-sm text-muted-foreground">
                <li className="flex gap-2.5">
                  <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-purple-600/15 text-[10px] font-bold text-purple-700 dark:text-purple-300">
                    1
                  </span>
                  <span>Your Available BP moves to Holding when you send.</span>
                </li>
                <li className="flex gap-2.5">
                  <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-purple-600/15 text-[10px] font-bold text-purple-700 dark:text-purple-300">
                    2
                  </span>
                  <span>They get notified and Accept / Collect when ready.</span>
                </li>
                <li className="flex gap-2.5">
                  <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-purple-600/15 text-[10px] font-bold text-purple-700 dark:text-purple-300">
                    3
                  </span>
                  <span>BP lands in their Gifted wallet — spendable, not re-giftable.</span>
                </li>
              </ol>
            </div>
            <div className="rounded-3xl border border-dashed border-muted-foreground/25 px-5 py-4 text-sm text-muted-foreground">
              Uses <strong className="text-foreground">purchased Available BP</strong> only. Gifted BP you
              received cannot be re-sent.
            </div>
          </aside>
        </section>

        {/* Sent history */}
        <section className="space-y-4">
          <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
              <h2 className="text-lg font-semibold tracking-tight dark:text-white">BP Gifts Sent</h2>
              <p className="text-sm text-muted-foreground">
                Pending gifts can be cancelled or resent until claimed.
              </p>
            </div>
            <div className="flex flex-wrap gap-1.5">
              {(
                [
                  ["all", "All"],
                  ["pending", "Pending"],
                  ["claimed", "Claimed"],
                  ["cancelled", "Cancelled"],
                  ["expired", "Expired"],
                ] as const
              ).map(([value, label]) => (
                <button
                  key={value}
                  type="button"
                  onClick={() => applyStatusFilter(value)}
                  className={`rounded-full px-3.5 py-1.5 text-xs font-medium transition-all ${
                    statusFilter === value
                      ? "bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm"
                      : "bg-muted/60 text-muted-foreground hover:bg-muted hover:text-foreground"
                  }`}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>

          {sentGifts.length === 0 ? (
            <div className="rounded-3xl border border-dashed border-muted-foreground/25 px-6 py-12 text-center">
              <Gift className="mx-auto h-8 w-8 text-muted-foreground/50" />
              <p className="mt-3 text-sm text-muted-foreground">No gifts in this filter yet.</p>
            </div>
          ) : (
            <ul className="space-y-3">
              {sentGifts.map((gift) => {
                const busy = gift.invite_id != null && inviteActionId === gift.invite_id
                return (
                  <li
                    key={gift.id}
                    className="rounded-2xl border border-border/80 bg-card p-4 transition-colors hover:border-purple-500/25 dark:bg-slate-900/40 sm:p-5"
                  >
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                      <div className="min-w-0 space-y-1.5">
                        <div className="flex flex-wrap items-center gap-2">
                          <p className="truncate font-semibold dark:text-white" title={gift.recipient_label}>
                            {gift.recipient_label}
                          </p>
                          <Badge variant="outline" className={`capitalize ${statusBadgeClass(gift.status)}`}>
                            {gift.status}
                          </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground">
                          <span className="font-medium tabular-nums text-foreground">
                            {Number(gift.amount).toFixed(2)} BP
                          </span>
                          <span className="mx-1.5 text-muted-foreground/50">·</span>
                          Sent {formatDate(gift.created_at)}
                          {gift.claimed_at ? (
                            <>
                              <span className="mx-1.5 text-muted-foreground/50">·</span>
                              Claimed {formatDate(gift.claimed_at)}
                            </>
                          ) : gift.expires_at && gift.status === "pending" ? (
                            <>
                              <span className="mx-1.5 text-muted-foreground/50">·</span>
                              Expires {formatDate(gift.expires_at)}
                            </>
                          ) : null}
                        </p>
                        {gift.occasion ? (
                          <p className="text-xs text-muted-foreground">{gift.occasion}</p>
                        ) : null}
                      </div>

                      {(gift.can_resend || gift.can_change_email || gift.can_cancel) && gift.invite_id ? (
                        <div className="flex flex-wrap gap-2 sm:justify-end">
                          {gift.can_resend ? (
                            <Button
                              type="button"
                              variant="outline"
                              size="sm"
                              className="rounded-full"
                              disabled={busy}
                              onClick={() => runInviteAction(gift, "resend")}
                            >
                              {busy && inviteAction === "resend" ? (
                                <Loader2 className="h-3.5 w-3.5 animate-spin" />
                              ) : (
                                <RefreshCw className="h-3.5 w-3.5" />
                              )}
                              <span className="ml-1.5">Resend</span>
                            </Button>
                          ) : null}
                          {gift.can_change_email ? (
                            <Button
                              type="button"
                              variant="outline"
                              size="sm"
                              className="rounded-full"
                              disabled={busy}
                              onClick={() => openEditEmail(gift)}
                            >
                              <Pencil className="h-3.5 w-3.5" />
                              <span className="ml-1.5">Email</span>
                            </Button>
                          ) : null}
                          {gift.can_cancel ? (
                            <Button
                              type="button"
                              variant="outline"
                              size="sm"
                              className="rounded-full border-rose-500/40 text-rose-700 dark:text-rose-300"
                              disabled={busy}
                              onClick={() => setCancelTarget(gift)}
                            >
                              <XCircle className="h-3.5 w-3.5" />
                              <span className="ml-1.5">Cancel</span>
                            </Button>
                          ) : null}
                        </div>
                      ) : null}
                    </div>
                  </li>
                )
              })}
            </ul>
          )}
        </section>

        <AlertDialog
          open={!!cancelTarget}
          onOpenChange={(open) => {
            if (!open && inviteAction !== "cancel") setCancelTarget(null)
          }}
        >
          <AlertDialogContent className="max-w-[calc(100vw-1.5rem)] sm:max-w-lg">
            <AlertDialogHeader>
              <AlertDialogTitle>Cancel BP gift?</AlertDialogTitle>
              <AlertDialogDescription className="text-left">
                This gift has not been claimed yet. Cancelling returns BP to your Available balance and notifies
                the recipient that the gift was rescinded.
                {cancelTarget ? (
                  <span className="mt-2 block text-foreground">
                    {Number(cancelTarget.amount).toFixed(2)} BP · {cancelTarget.recipient_label}
                  </span>
                ) : null}
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter className="gap-2 sm:gap-0">
              <AlertDialogCancel disabled={inviteAction === "cancel"}>Keep gift</AlertDialogCancel>
              <AlertDialogAction
                className="bg-rose-600 hover:bg-rose-700 focus:ring-rose-600"
                disabled={!cancelTarget || inviteAction === "cancel"}
                onClick={(e) => {
                  e.preventDefault()
                  if (cancelTarget) runInviteAction(cancelTarget, "cancel")
                }}
              >
                {inviteAction === "cancel" ? (
                  <>
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                    Cancelling…
                  </>
                ) : (
                  "Cancel & Return BP"
                )}
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>

        <Dialog
          open={!!editTarget}
          onOpenChange={(open) => {
            if (!open && inviteAction !== "email") {
              setEditTarget(null)
              setEditEmail("")
            }
          }}
        >
          <DialogContent className="max-w-[calc(100vw-1.5rem)] sm:max-w-md">
            <DialogHeader>
              <DialogTitle>Change invitation email</DialogTitle>
              <DialogDescription>
                Holding BP stays the same. We’ll notify the previous address and send a fresh invite to the new
                email.
              </DialogDescription>
            </DialogHeader>
            <form onSubmit={submitEditEmail} className="space-y-4">
              <div>
                <Label htmlFor="gift-invite-edit-email">New email</Label>
                <Input
                  id="gift-invite-edit-email"
                  type="email"
                  className="mt-2 h-12"
                  value={editEmail}
                  onChange={(e) => setEditEmail(e.target.value)}
                  autoComplete="email"
                  required
                />
              </div>
              <DialogFooter className="gap-2 sm:gap-0">
                <Button
                  type="button"
                  variant="outline"
                  disabled={inviteAction === "email"}
                  onClick={() => {
                    setEditTarget(null)
                    setEditEmail("")
                  }}
                >
                  Back
                </Button>
                <Button type="submit" disabled={inviteAction === "email" || !editTarget}>
                  {inviteAction === "email" ? (
                    <>
                      <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      Saving…
                    </>
                  ) : (
                    "Update email"
                  )}
                </Button>
              </DialogFooter>
            </form>
          </DialogContent>
        </Dialog>
      </div>
  )

  if (isOrgViewer) {
    return (
      <AppSidebarLayout>
        <Head title="Gift BP" />
        {pageBody}
      </AppSidebarLayout>
    )
  }

  if (viewerRole === "user") {
    return (
      <ProfileLayout title="Gift BP" description="Send Believe Points to supporters — or invite someone new">
        <Head title="Gift BP" />
        {pageBody}
      </ProfileLayout>
    )
  }

  return (
    <FrontendLayout>
      <Head title="Gift BP" />
      <div className="container mx-auto max-w-5xl px-4 py-8">{pageBody}</div>
    </FrontendLayout>
  )
}
