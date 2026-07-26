"use client"

import { Head, router, usePage } from "@inertiajs/react"
import { useEffect, useState, type MouseEvent } from "react"
import axios from "axios"
import {
  Activity,
  Bell,
  Brain,
  CalendarDays,
  ChevronLeft,
  ChevronRight,
  Clock3,
  Flame,
  HandHeart,
  Heart,
  Inbox,
  MessageCircle,
  MessageSquare,
  Settings2,
  Sparkles,
  UserRound,
} from "lucide-react"
import toast from "react-hot-toast"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Badge } from "@/components/frontend/ui/badge"
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
import { Input } from "@/components/frontend/ui/input"
import { Switch } from "@/components/frontend/ui/switch"
import { cn } from "@/lib/utils"
import {
  CARE_ALLIANCE_INVITATION_TYPE,
  SUPPORTER_BIRTHDAY_TYPE,
  donationNotificationTarget,
  giftCardNotificationTarget,
  mapDatabaseNotification,
  type DatabaseNotification,
  type Notification,
} from "@/lib/notification-map"

interface PaginatedNotifications {
  data: DatabaseNotification[]
  current_page: number
  last_page: number
  per_page: number
  total: number
  prev_page_url: string | null
  next_page_url: string | null
}

interface InboxProps {
  notifications: PaginatedNotifications
  activeCategory?: NotificationCategory
  filterCounts?: Record<NotificationCategory, number>
  searchQuery?: string
}

type NotificationCategory = "all" | "ai" | "prayer" | "donations" | "events" | "messages" | "system"

type NotificationVisual = {
  icon: typeof Sparkles
  badge: string
  badgeClassName: string
}

const inferCategory = (notification: Notification): NotificationCategory => {
  const text = `${notification.type} ${notification.title} ${notification.body}`.toLowerCase()

  if (text.includes("ai") || text.includes("assistant")) return "ai"
  if (text.includes("pray") || text.includes("worship")) return "prayer"
  if (text.includes("donat") || text.includes("gift") || text.includes("fund")) return "donations"
  if (text.includes("event") || text.includes("live") || text.includes("meeting")) return "events"
  if (text.includes("message") || text.includes("comment") || text.includes("chat")) return "messages"
  return "system"
}

const categoryConfig: Record<
  NotificationCategory,
  {
    label: string
    icon: typeof Sparkles
    chipClass: string
  }
> = {
  all: {
    label: "All",
    icon: Sparkles,
    chipClass: "border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/40 dark:bg-violet-500/20 dark:text-violet-100",
  },
  ai: {
    label: "AI",
    icon: Brain,
    chipClass: "border-fuchsia-200 bg-fuchsia-50 text-fuchsia-700 dark:border-fuchsia-500/40 dark:bg-fuchsia-500/20 dark:text-fuchsia-100",
  },
  prayer: {
    label: "Prayer",
    icon: Sparkles,
    chipClass: "border-purple-200 bg-purple-50 text-purple-700 dark:border-purple-500/40 dark:bg-purple-500/20 dark:text-purple-100",
  },
  donations: {
    label: "Donations",
    icon: HandHeart,
    chipClass: "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/40 dark:bg-emerald-500/20 dark:text-emerald-100",
  },
  events: {
    label: "Events",
    icon: CalendarDays,
    chipClass: "border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/40 dark:bg-sky-500/20 dark:text-sky-100",
  },
  messages: {
    label: "Messages",
    icon: MessageSquare,
    chipClass: "border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/40 dark:bg-amber-500/20 dark:text-amber-100",
  },
  system: {
    label: "System",
    icon: Settings2,
    chipClass: "border-slate-300 bg-slate-100 text-slate-700 dark:border-slate-500/40 dark:bg-slate-500/20 dark:text-slate-100",
  },
}

const visualByCategory: Record<Exclude<NotificationCategory, "all">, NotificationVisual> = {
  ai: {
    icon: Brain,
    badge: "AI",
    badgeClassName: "bg-fuchsia-50 text-fuchsia-700 border-fuchsia-200 dark:bg-fuchsia-500/15 dark:text-fuchsia-200 dark:border-fuchsia-400/30",
  },
  prayer: {
    icon: Sparkles,
    badge: "Prayer",
    badgeClassName: "bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-500/15 dark:text-purple-200 dark:border-purple-400/30",
  },
  donations: {
    icon: Heart,
    badge: "Donation",
    badgeClassName: "bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-200 dark:border-emerald-400/30",
  },
  events: {
    icon: CalendarDays,
    badge: "Event",
    badgeClassName: "bg-sky-50 text-sky-700 border-sky-200 dark:bg-sky-500/15 dark:text-sky-200 dark:border-sky-400/30",
  },
  messages: {
    icon: MessageCircle,
    badge: "Message",
    badgeClassName: "bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-500/15 dark:text-amber-200 dark:border-amber-400/30",
  },
  system: {
    icon: Settings2,
    badge: "System",
    badgeClassName: "bg-slate-100 text-slate-700 border-slate-300 dark:bg-slate-500/15 dark:text-slate-200 dark:border-slate-400/30",
  },
}

export default function NotificationsInbox() {
  const { notifications, activeCategory: activeCategoryProp = "all", filterCounts, searchQuery = "" } = usePage().props as unknown as InboxProps
  const [careAllianceActionLoadingId, setCareAllianceActionLoadingId] = useState<string | null>(null)
  const [pushNotificationsEnabled, setPushNotificationsEnabled] = useState(true)
  const [emailNotificationsEnabled, setEmailNotificationsEnabled] = useState(true)
  const [smsNotificationsEnabled, setSmsNotificationsEnabled] = useState(false)
  const [dailySummaryEnabled, setDailySummaryEnabled] = useState(false)
  const [searchTerm, setSearchTerm] = useState(searchQuery)
  const [clearAllDialogOpen, setClearAllDialogOpen] = useState(false)
  const [clearAllLoading, setClearAllLoading] = useState(false)

  const rows: Notification[] = (notifications?.data || []).map((row) => mapDatabaseNotification(row))
  const total = notifications?.total ?? 0
  const unreadOnPage = rows.filter((n) => !n.read).length
  const priorityRows = rows.filter((n) => !n.read).slice(0, 4)

  const categoryCounts: Record<NotificationCategory, number> = {
    all: filterCounts?.all ?? rows.length,
    ai: filterCounts?.ai ?? rows.filter((n) => inferCategory(n) === "ai").length,
    prayer: filterCounts?.prayer ?? rows.filter((n) => inferCategory(n) === "prayer").length,
    donations: filterCounts?.donations ?? rows.filter((n) => inferCategory(n) === "donations").length,
    events: filterCounts?.events ?? rows.filter((n) => inferCategory(n) === "events").length,
    messages: filterCounts?.messages ?? rows.filter((n) => inferCategory(n) === "messages").length,
    system: filterCounts?.system ?? rows.filter((n) => inferCategory(n) === "system").length,
  }
  const readCount = Math.max(0, total - unreadOnPage)

  const postAction = async (url: string) => {
    await axios.post(url)
  }

  useEffect(() => {
    setSearchTerm(searchQuery)
  }, [searchQuery])

  const buildInboxQuery = (category: NotificationCategory = activeCategoryProp, q: string = searchTerm) => {
    const trimmed = q.trim()
    return {
      ...(category !== "all" ? { category } : {}),
      ...(trimmed !== "" ? { q: trimmed } : {}),
    }
  }

  useEffect(() => {
    const currentFromServer = searchQuery.trim()
    const currentFromInput = searchTerm.trim()
    if (currentFromServer === currentFromInput) return

    const timer = window.setTimeout(() => {
      router.get(route("notifications.index"), buildInboxQuery(activeCategoryProp, searchTerm), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        only: ["notifications", "activeCategory", "filterCounts", "searchQuery"],
      })
    }, 350)

    return () => window.clearTimeout(timer)
  }, [searchTerm, activeCategoryProp, searchQuery])

  useEffect(() => {
    if (typeof window === "undefined") return
    const readFlag = (key: string, fallback: boolean) => {
      const raw = window.localStorage.getItem(key)
      if (raw == null) return fallback
      return raw === "1"
    }
    setPushNotificationsEnabled(readFlag("notif_pref_push", true))
    setEmailNotificationsEnabled(readFlag("notif_pref_email", true))
    setSmsNotificationsEnabled(readFlag("notif_pref_sms", false))
    setDailySummaryEnabled(readFlag("notif_pref_daily_summary", false))
  }, [])

  const updateLocalPreference = (key: string, value: boolean, setter: (v: boolean) => void) => {
    setter(value)
    if (typeof window !== "undefined") {
      window.localStorage.setItem(key, value ? "1" : "0")
    }
    toast.success("Preference updated")
  }

  const markAsReadAndNavigate = async (notification: Notification) => {
    try {
      await postAction(`/notifications/${notification.id}/read`)
      if (notification.content_item_id) {
        router.visit(`/notifications/content/${notification.content_item_id}`)
      } else if (notification.type === CARE_ALLIANCE_INVITATION_TYPE) {
        router.visit("/organization/alliance-membership?tab=invitations#care-alliance-invitations")
      } else if (notification.type === SUPPORTER_BIRTHDAY_TYPE && notification.meta?.celebrant_id != null) {
        router.visit(`/gift-bp?recipient=${notification.meta.celebrant_id}`)
      } else if (
        notification.type === "gift_invite_pending" ||
        notification.type === "gift_invite_claimed" ||
        notification.type === "gift_invite_expired" ||
        notification.type === "gift_invite_cancelled" ||
        notification.type === "gift_invite_email_changed" ||
        notification.type === "gift_invite_resent" ||
        notification.type === "gift_awaiting_claim" ||
        notification.type === "gift_sent"
      ) {
        router.visit("/gift-bp")
      } else if (notification.type === "gift_invite_cancellation_brp") {
        router.visit("/profile/reward-points-ledger")
      } else if (notification.type === "gift_received") {
        router.visit("/believe-points")
      } else {
        const donationTarget = donationNotificationTarget(notification)
        if (donationTarget) {
          router.visit(donationTarget)
        } else {
          const giftCardTarget = giftCardNotificationTarget(notification)
          if (giftCardTarget) {
            router.visit(giftCardTarget)
          } else {
            router.reload({ only: ["notifications", "activeCategory", "filterCounts", "searchQuery"] })
          }
        }
      }
    } catch {
      toast.error("Could not update notification.")
    }
  }

  const handleMarkAllRead = async () => {
    try {
      await postAction("/notifications/mark-all-read")
      toast.success("All marked read")
      router.reload({ only: ["notifications", "activeCategory", "filterCounts", "searchQuery"] })
    } catch {
      toast.error("Something went wrong.")
    }
  }

  const handleClearAll = async () => {
    try {
      setClearAllLoading(true)
      await postAction("/notifications/clear-all")
      toast.success("Notifications cleared")
      router.reload({ only: ["notifications", "activeCategory", "filterCounts", "searchQuery"] })
      setClearAllDialogOpen(false)
    } catch {
      toast.error("Something went wrong.")
    } finally {
      setClearAllLoading(false)
    }
  }

  const showCareAllianceActions = (notification: Notification) =>
    notification.type === CARE_ALLIANCE_INVITATION_TYPE &&
    typeof notification.meta?.invitation_id === "number" &&
    notification.meta?.show_care_alliance_actions !== false

  const showSupporterBirthdayActions = (notification: Notification) =>
    notification.type === SUPPORTER_BIRTHDAY_TYPE && notification.meta?.celebrant_id != null

  const handleCareAllianceInvitationAction = async (
    e: MouseEvent<HTMLButtonElement>,
    notification: Notification,
    action: "accept" | "decline",
  ) => {
    e.preventDefault()
    e.stopPropagation()
    const invitationId = notification.meta?.invitation_id as number
    if (invitationId == null) return

    setCareAllianceActionLoadingId(notification.id)
    try {
      await postAction(`/organization/care-alliance-invitations/${invitationId}/${action}`)
      toast.success(action === "accept" ? "You joined the Unity Impact Alliance." : "Invitation declined.")
      await postAction(`/notifications/${notification.id}/read`)
      if (action === "accept") {
        router.visit("/organization/alliance-membership?tab=invitations#care-alliance-invitations")
      } else {
        router.reload({ only: ["notifications", "activeCategory", "filterCounts", "searchQuery"] })
      }
    } catch (err: unknown) {
      const msg = (err as { response?: { data?: { message?: string } } })?.response?.data?.message
      toast.error(typeof msg === "string" ? msg : "Something went wrong.")
    } finally {
      setCareAllianceActionLoadingId(null)
    }
  }

  return (
    <FrontendLayout>
      <Head title="Notifications" />
      <div className="mx-auto max-w-7xl px-4 py-8 md:py-10">
        <div className="grid items-start gap-5 lg:grid-cols-[1.6fr_0.8fr]">
          <Card className="flex flex-col overflow-hidden border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-[#071225]">
            <CardHeader className="border-b border-slate-200 bg-white px-5 py-4 dark:border-slate-800 dark:bg-[#071225]">
              <div className="mb-4 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div>
                  <CardTitle className="flex items-center gap-2 text-2xl font-semibold text-slate-900 dark:text-white">
                    <Inbox className="h-6 w-6 text-violet-600 dark:text-violet-300" />
                    Notifications
                  </CardTitle>
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    <Badge variant="secondary" className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700 dark:bg-white/10 dark:text-slate-200">
                      {total} total
                    </Badge>
                    <Badge variant="secondary" className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-700 dark:bg-white/10 dark:text-slate-200">
                      {unreadOnPage} unread
                    </Badge>
                  </div>
                </div>
                <div className="flex flex-wrap gap-2">
                  <div className="mr-2 flex items-center gap-2">
                    <Input
                      value={searchTerm}
                      onChange={(e) => setSearchTerm(e.target.value)}
                      placeholder="Search notifications..."
                      className="h-8 w-52 border-slate-300 bg-white text-xs text-slate-900 placeholder:text-slate-400 dark:border-white/20 dark:bg-white/5 dark:text-white dark:placeholder:text-slate-400"
                    />
                  </div>
                  <Button
                    variant="outline"
                    size="sm"
                    className="border-slate-300 bg-white text-slate-700 hover:bg-slate-100 dark:border-white/20 dark:bg-white/5 dark:text-white dark:hover:bg-white/10"
                    onClick={handleMarkAllRead}
                    disabled={total === 0}
                  >
                    Mark all as read
                  </Button>
                  <Button
                    variant="outline"
                    size="sm"
                    className="border-red-400/40 bg-red-500/10 text-red-200 hover:bg-red-500/20"
                    onClick={() => setClearAllDialogOpen(true)}
                    disabled={total === 0}
                  >
                    Clear all
                  </Button>
                </div>
              </div>

              <div className="flex flex-wrap gap-2">
                {(Object.keys(categoryConfig) as NotificationCategory[]).map((key) => {
                  const cfg = categoryConfig[key]
                  const Icon = cfg.icon
                  const active = activeCategoryProp === key
                  return (
                    <button
                      key={key}
                      type="button"
                      onClick={() =>
                        router.get(
                          route("notifications.index"),
                          buildInboxQuery(key),
                          {
                            preserveScroll: true,
                            preserveState: true,
                            replace: true,
                            only: ["notifications", "activeCategory", "filterCounts", "searchQuery"],
                          },
                        )
                      }
                      className={cn(
                        "inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition",
                        cfg.chipClass,
                        active && "ring-2 ring-violet-500/40",
                      )}
                    >
                      <Icon className="h-3.5 w-3.5" />
                      <span>{cfg.label}</span>
                      <span className="opacity-80">{categoryCounts[key]}</span>
                    </button>
                  )
                })}
              </div>
            </CardHeader>

            <CardContent className="bg-slate-50 p-0 dark:bg-[#050d1d]">
              {rows.length === 0 ? (
                <div className="flex h-full min-h-[460px] flex-col items-center justify-center px-6 py-20 text-center text-slate-500 dark:text-slate-300">
                  <Bell className="mb-4 h-14 w-14 opacity-20" />
                  <p className="font-medium">No notifications in this category</p>
                  <p className="mt-1 text-sm">Try another category or check back later.</p>
                </div>
              ) : (
                <div className="space-y-3 bg-slate-50 p-3 dark:bg-[#050d1d] sm:p-4">
                  {rows.map((notification) => {
                    const category = inferCategory(notification)
                    const visual = visualByCategory[category === "all" ? "system" : category]
                    const Icon = visual.icon

                    return (
                      <div
                        key={notification.id}
                        className={cn(
                          "rounded-xl border p-4 transition-all",
                          notification.read
                            ? "border-slate-200 bg-white hover:border-slate-300 dark:border-slate-700/70 dark:bg-[#0b162c] dark:hover:border-slate-600"
                            : "border-violet-200 bg-violet-50/60 hover:border-violet-300 dark:border-violet-600/40 dark:bg-[#0c1a33] dark:hover:border-violet-400/60",
                        )}
                      >
                        <div className="flex items-start gap-3">
                          <div className={cn("mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border", visual.badgeClassName)}>
                            <Icon className="h-4 w-4" />
                          </div>

                          <div className="min-w-0 flex-1">
                            <div
                              className="cursor-pointer"
                              role="button"
                              tabIndex={0}
                              onClick={() => markAsReadAndNavigate(notification)}
                              onKeyDown={(e) => {
                                if (e.key === "Enter" || e.key === " ") {
                                  e.preventDefault()
                                  void markAsReadAndNavigate(notification)
                                }
                              }}
                            >
                              <div className="flex flex-wrap items-start gap-2">
                                <p className="font-semibold leading-5 text-slate-900 dark:text-white">{notification.title}</p>
                                {!notification.read ? <Badge className="rounded-full bg-blue-600 px-2 py-0.5 text-[10px] text-white">New</Badge> : null}
                              </div>
                              <p className={cn("mt-1 text-sm text-slate-600 dark:text-slate-300", notification.type === CARE_ALLIANCE_INVITATION_TYPE ? "" : "line-clamp-2")}>
                                {notification.body}
                              </p>
                              {notification.senderLabel ? (
                                <p className="mt-1.5 text-xs font-medium text-slate-700 dark:text-slate-200">
                                  {notification.senderLabel}
                                </p>
                              ) : null}
                              <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                                <Badge className={cn("border px-2 py-0.5 font-normal", visual.badgeClassName)}>{visual.badge}</Badge>
                                <span className="inline-flex items-center gap-1">
                                  <Clock3 className="h-3 w-3" />
                                  {new Date(notification.timestamp).toLocaleString()}
                                </span>
                                <span className="inline-flex items-center gap-1">
                                  <MessageCircle className="h-3 w-3" />
                                  {notification.read ? "viewed" : "unread"}
                                </span>
                              </div>
                            </div>

                            {showCareAllianceActions(notification) ? (
                              <div className="mt-4 flex flex-wrap gap-2">
                                <Button
                                  type="button"
                                  size="sm"
                                  disabled={careAllianceActionLoadingId === notification.id}
                                  onClick={(e) => handleCareAllianceInvitationAction(e, notification, "accept")}
                                >
                                  {careAllianceActionLoadingId === notification.id ? "..." : "Accept"}
                                </Button>
                                <Button
                                  type="button"
                                  variant="outline"
                                  size="sm"
                                  disabled={careAllianceActionLoadingId === notification.id}
                                  onClick={(e) => handleCareAllianceInvitationAction(e, notification, "decline")}
                                >
                                  Decline
                                </Button>
                              </div>
                            ) : null}

                            {showSupporterBirthdayActions(notification) ? (
                              <div className="mt-4 flex flex-wrap gap-2">
                                <Button
                                  type="button"
                                  size="sm"
                                  className="border-0 bg-gradient-to-r from-blue-600 to-violet-600 text-white"
                                  onClick={async (e) => {
                                    e.preventDefault()
                                    e.stopPropagation()
                                    const id = notification.meta?.celebrant_id
                                    if (id == null) return
                                    try {
                                      await postAction(`/notifications/${notification.id}/read`)
                                    } catch {
                                      toast.error("Could not update notification.")
                                      return
                                    }
                                    router.visit(`/gift-bp?recipient=${id}`)
                                  }}
                                >
                                  Send Gift
                                </Button>
                                <Button
                                  type="button"
                                  variant="outline"
                                  size="sm"
                                  onClick={(e) => {
                                    e.preventDefault()
                                    e.stopPropagation()
                                    const slug = notification.meta?.celebrant_slug
                                    if (slug) {
                                      router.visit(`/users/${slug}`)
                                    } else if (notification.meta?.celebrant_id != null) {
                                      router.visit(`/users/${notification.meta.celebrant_id}`)
                                    }
                                  }}
                                >
                                  View Profile
                                </Button>
                              </div>
                            ) : null}
                          </div>

                        </div>
                      </div>
                    )
                  })}
                </div>
              )}

              {notifications && notifications.last_page > 1 ? (
                <div className="flex items-center justify-between border-t border-slate-200 bg-white px-4 py-3 dark:border-slate-800 dark:bg-[#071225]">
                  <Button
                    variant="outline"
                    size="sm"
                    className="border-slate-300 bg-white text-slate-700 hover:bg-slate-100 dark:border-white/15 dark:bg-white/5 dark:text-slate-100 dark:hover:bg-white/10"
                    disabled={!notifications.prev_page_url}
                    onClick={() => notifications.prev_page_url && router.visit(notifications.prev_page_url)}
                  >
                    <ChevronLeft className="mr-1 h-4 w-4" />
                    Previous
                  </Button>
                  <span className="text-sm text-slate-600 dark:text-slate-300">
                    Page {notifications.current_page} of {notifications.last_page}
                  </span>
                  <Button
                    variant="outline"
                    size="sm"
                    className="border-slate-300 bg-white text-slate-700 hover:bg-slate-100 dark:border-white/15 dark:bg-white/5 dark:text-slate-100 dark:hover:bg-white/10"
                    disabled={!notifications.next_page_url}
                    onClick={() => notifications.next_page_url && router.visit(notifications.next_page_url)}
                  >
                    Next
                    <ChevronRight className="ml-1 h-4 w-4" />
                  </Button>
                </div>
              ) : null}
            </CardContent>
          </Card>

          <div className="space-y-4">
            <Card className="border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-[#071225]">
              <CardHeader className="pb-3">
                <CardTitle className="flex items-center justify-between text-sm font-semibold text-slate-900 dark:text-white">
                  <span className="flex items-center gap-2">
                    <Flame className="h-4 w-4 text-orange-500" />
                    Priority
                  </span>
                  <button
                    type="button"
                    className="text-xs text-sky-600 hover:text-sky-500 dark:text-sky-300 dark:hover:text-sky-200"
                    onClick={() =>
                      router.get(route("notifications.index"), buildInboxQuery("all"), {
                        preserveScroll: true,
                        preserveState: true,
                        replace: true,
                        only: ["notifications", "activeCategory", "filterCounts", "searchQuery"],
                      })
                    }
                  >
                    View all
                  </button>
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3 pt-0">
                {priorityRows.length === 0 ? (
                  <p className="text-sm text-slate-600 dark:text-slate-300">You are all caught up.</p>
                ) : (
                  priorityRows.map((n) => (
                    <button
                      key={n.id}
                      type="button"
                      onClick={() => markAsReadAndNavigate(n)}
                      className="w-full rounded-lg border border-slate-200 bg-slate-50 p-3 text-left transition hover:border-slate-300 dark:border-white/10 dark:bg-white/5 dark:hover:border-white/20"
                    >
                      <div className="min-w-0">
                        <p className="line-clamp-1 text-sm font-semibold text-slate-900 dark:text-white">{n.title}</p>
                        <p className="mt-1 line-clamp-2 text-xs text-slate-600 dark:text-slate-300">{n.body || "Open notification"}</p>
                        <div className="mt-2 inline-flex rounded bg-violet-500/25 px-2 py-0.5 text-[10px] font-semibold text-violet-100">
                          Open
                        </div>
                      </div>
                    </button>
                  ))
                )}
              </CardContent>
            </Card>

            <Card className="border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-[#071225]">
              <CardHeader className="pb-3">
                <CardTitle className="flex items-center justify-between text-sm font-semibold text-slate-900 dark:text-white">
                  <span>Impact Summary</span>
                  <span className="rounded bg-slate-100 px-2 py-1 text-[10px] font-medium text-slate-600 dark:bg-white/5 dark:text-slate-300">This Week</span>
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-2 pt-0 text-sm text-slate-900 dark:text-white">
                <div className="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-white/10 dark:bg-white/5">
                  <span className="inline-flex items-center gap-2 text-slate-700 dark:text-slate-200"><Sparkles className="h-4 w-4 text-purple-500 dark:text-purple-300" />Prayer updates</span>
                  <span className="font-semibold">{categoryCounts.prayer}</span>
                </div>
                <div className="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-white/10 dark:bg-white/5">
                  <span className="inline-flex items-center gap-2 text-slate-700 dark:text-slate-200"><Heart className="h-4 w-4 text-emerald-500 dark:text-emerald-300" />Donation updates</span>
                  <span className="font-semibold">{categoryCounts.donations}</span>
                </div>
                <div className="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-white/10 dark:bg-white/5">
                  <span className="inline-flex items-center gap-2 text-slate-700 dark:text-slate-200"><UserRound className="h-4 w-4 text-sky-500 dark:text-sky-300" />Unread notifications</span>
                  <span className="font-semibold">{unreadOnPage}</span>
                </div>
                <div className="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 dark:border-white/10 dark:bg-white/5">
                  <span className="inline-flex items-center gap-2 text-slate-700 dark:text-slate-200"><Activity className="h-4 w-4 text-amber-500 dark:text-amber-300" />Read notifications</span>
                  <span className="font-semibold">{readCount}</span>
                </div>
              </CardContent>
            </Card>

            <Card className="border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-[#071225]">
              <CardHeader className="pb-3">
                <CardTitle className="text-sm font-semibold text-slate-900 dark:text-white">Notification Settings</CardTitle>
              </CardHeader>
              <CardContent className="space-y-3 pt-0">
                <div className="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-2.5 dark:border-white/10 dark:bg-white/5">
                  <span className="text-sm text-slate-700 dark:text-slate-100">Push notifications</span>
                  <Switch
                    checked={pushNotificationsEnabled}
                    onCheckedChange={(checked) => updateLocalPreference("notif_pref_push", checked, setPushNotificationsEnabled)}
                  />
                </div>
                <div className="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-2.5 dark:border-white/10 dark:bg-white/5">
                  <span className="text-sm text-slate-700 dark:text-slate-100">Email notifications</span>
                  <Switch
                    checked={emailNotificationsEnabled}
                    onCheckedChange={(checked) => updateLocalPreference("notif_pref_email", checked, setEmailNotificationsEnabled)}
                  />
                </div>
                <div className="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-2.5 dark:border-white/10 dark:bg-white/5">
                  <span className="text-sm text-slate-700 dark:text-slate-100">SMS notifications</span>
                  <Switch
                    checked={smsNotificationsEnabled}
                    onCheckedChange={(checked) => updateLocalPreference("notif_pref_sms", checked, setSmsNotificationsEnabled)}
                  />
                </div>
                <div className="flex items-center justify-between rounded-lg border border-slate-200 bg-slate-50 p-2.5 dark:border-white/10 dark:bg-white/5">
                  <span className="text-sm text-slate-700 dark:text-slate-100">Daily summary</span>
                  <Switch
                    checked={dailySummaryEnabled}
                    onCheckedChange={(checked) => updateLocalPreference("notif_pref_daily_summary", checked, setDailySummaryEnabled)}
                  />
                </div>
              </CardContent>
            </Card>
          </div>
        </div>
      </div>

      <AlertDialog open={clearAllDialogOpen} onOpenChange={setClearAllDialogOpen}>
        <AlertDialogContent className="border-slate-200 bg-white text-slate-900 dark:border-white/10 dark:bg-zinc-950 dark:text-zinc-100">
          <AlertDialogHeader>
            <AlertDialogTitle>Clear all notifications?</AlertDialogTitle>
            <AlertDialogDescription className="text-slate-600 dark:text-zinc-400">
              This action cannot be undone. All notifications will be permanently removed.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel className="border-slate-300 bg-white text-slate-700 hover:bg-slate-100 dark:border-white/15 dark:bg-transparent dark:text-zinc-300 dark:hover:bg-white/5">
              Cancel
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={(e) => {
                e.preventDefault()
                void handleClearAll()
              }}
              disabled={clearAllLoading}
              className="bg-red-600 text-white hover:bg-red-700 focus:ring-red-500 dark:bg-red-600 dark:hover:bg-red-500"
            >
              {clearAllLoading ? "Clearing..." : "Clear all"}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </FrontendLayout>
  )
}
