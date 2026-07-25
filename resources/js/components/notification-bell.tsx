"use client"

import { useState, useEffect, useRef, type MouseEvent } from "react"
import { Bell, CheckCheck, Gift, RefreshCw, Sparkles, Trash2 } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Popover, PopoverContent, PopoverTrigger } from "@/components/chat/ui/popover"
import { Badge } from "@/components/ui/badge"
import { cn } from "@/lib/utils"
import {
  CARE_ALLIANCE_INVITATION_TYPE,
  DONATION_CONFIRMED_TYPE,
  DONATION_RECEIVED_TYPE,
  SUPPORTER_BIRTHDAY_TYPE,
  UNITY_MEET_INVITATION_TYPE,
  donationNotificationTarget,
  giftCardNotificationTarget,
  mapDatabaseNotification,
  notificationSenderLabel,
  parseNotificationPayload,
  type DatabaseNotification,
  type Notification,
} from "@/lib/notification-map"
import { router } from "@inertiajs/react"
import axios from "axios"
import toast from "react-hot-toast"
import { showNativePushNotification } from "@/lib/firebase-push-toast"

interface NotificationBellProps {
  userId: number
  emailVerified?: boolean
  onNotificationClick?: (notification: Notification) => void
}

export function NotificationBell({ userId, emailVerified = true, onNotificationClick }: NotificationBellProps) {
  const [notifications, setNotifications] = useState<Notification[]>([])
  const [unreadCount, setUnreadCount] = useState(0)
  const [isOpen, setIsOpen] = useState(false)
  const [isLoading, setIsLoading] = useState(false)
  const [careAllianceActionLoadingId, setCareAllianceActionLoadingId] = useState<string | null>(null)
  const audioRef = useRef<HTMLAudioElement | null>(null)

  useEffect(() => {
    audioRef.current = new Audio("/notification-sound.mp3")
    audioRef.current.volume = 0.5
  }, [])

  const playNotificationSound = () => {
    if (audioRef.current) {
      audioRef.current.currentTime = 0
      audioRef.current.play().catch((err) => console.log("Audio play failed:", err))
    }
  }

  const fetchNotifications = async () => {
    if (!userId) return

    // Don't fetch notifications if email is not verified
    if (!emailVerified) {
      return
    }

    setIsLoading(true)

    try {
      const response = await axios.get("/notifications/api")
      const payload = response.data as Record<string, unknown>
      const raw = payload.notifications
      const data: unknown[] = Array.isArray(raw)
        ? raw
        : raw && typeof raw === "object" && Array.isArray((raw as { data?: unknown[] }).data)
          ? ((raw as { data: unknown[] }).data ?? [])
          : []

      const formattedNotifications = data.map((dbNotif: unknown) =>
        mapDatabaseNotification(dbNotif as DatabaseNotification),
      )

      setNotifications(formattedNotifications)
      setUnreadCount(formattedNotifications.filter((n: Notification) => !n.read).length)
    } catch (error) {
      const bridgeBlocked =
        axios.isAxiosError(error) &&
        error.response?.status === 403 &&
        (error.response?.data as { requires_bridge_verification?: boolean } | undefined)
          ?.requires_bridge_verification === true

      if (!bridgeBlocked) {
        console.error("Error fetching notifications:", error)
      }
    } finally {
      setIsLoading(false)
    }
  }

  const markAsReadSilent = async (notification: Notification) => {
    try {
      await axios.post(`/notifications/${notification.id}/read`)
      setNotifications((prev) => prev.map((n) => (n.id === notification.id ? { ...n, read: true } : n)))
      setUnreadCount((prev) => Math.max(0, prev - (notification.read ? 0 : 1)))
    } catch (error) {
      console.error("Error marking notification as read:", error)
    }
  }

  const markAsRead = async (notification: Notification): Promise<boolean> => {
    try {
      const response = await axios.post(`/notifications/${notification.id}/read`)

      // Update local state
      setNotifications((prev) => prev.map((n) => (n.id === notification.id ? { ...n, read: true } : n)))
      setUnreadCount((prev) => Math.max(0, prev - 1))

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
      } else if (notification.type === UNITY_MEET_INVITATION_TYPE) {
        const joinUrl =
          typeof notification.meta?.join_url === "string" ? notification.meta.join_url : null
        if (joinUrl) {
          router.visit(joinUrl)
        }
      } else {
        const donationTarget = donationNotificationTarget(notification)
        if (donationTarget) {
          router.visit(donationTarget)
        } else {
          const giftCardTarget = giftCardNotificationTarget(notification)
          if (giftCardTarget) {
            router.visit(giftCardTarget)
          }
        }
      }

      return true
    } catch (error) {
      console.error("Error marking notification as read:", error)
      return false
    }
  }

  const markAllAsRead = async (): Promise<boolean> => {
    try {
      await axios.post("/notifications/mark-all-read")

      setNotifications((prev) => prev.map((n) => ({ ...n, read: true })))
      setUnreadCount(0)

      return true
    } catch (error) {
      console.error("Error marking all notifications as read:", error)
      return false
    }
  }

  const clearAllNotifications = async (): Promise<boolean> => {
    try {
      await axios.post("/notifications/clear-all")

      setNotifications([])
      setUnreadCount(0)

      return true
    } catch (error) {
      console.error("Error clearing all notifications:", error)
      return false
    }
  }

  useEffect(() => {
    // Fetch initial notifications only if email is verified
    if (emailVerified) {
      fetchNotifications()
    }

    if (typeof window !== "undefined" && (window as any).Echo) {
      const echo = (window as any).Echo
      const userChannel = echo.private(`user.${userId}`)

      userChannel.listen(".campaign.notification", (data: any) => {
          console.log("[v0] Received campaign notification:", data)

          const meta = {
            ...(data.meta || {}),
            ...(data.organization_id != null ? { organization_id: data.organization_id } : {}),
            ...(typeof data.organization_name === "string" && data.organization_name.trim()
              ? { organization_name: data.organization_name.trim() }
              : {}),
            ...(typeof data.creator_name === "string" && data.creator_name.trim()
              ? { creator_name: data.creator_name.trim() }
              : {}),
          }
          const newNotification: Notification = {
            id: data.id || `real-time-${Date.now()}`,
            title: data.title || "New Notification",
            body: data.body || "",
            content_item_id: data.content_item_id || 0,
            type: data.content_type || data.type || "campaign",
            channel: data.channel || "app",
            meta,
            timestamp: data.sent_at || data.timestamp || new Date().toISOString(),
            read: false,
          }
          newNotification.senderLabel = notificationSenderLabel(newNotification, data)


          setNotifications((prev) => [newNotification, ...prev])
          setUnreadCount((prev) => prev + 1)

          playNotificationSound()
          void showNativePushNotification({
            title: newNotification.title,
            body: newNotification.body,
            data:
              newNotification.type === UNITY_MEET_INVITATION_TYPE
                ? {
                    type: UNITY_MEET_INVITATION_TYPE,
                    join_url:
                      typeof newNotification.meta?.join_url === "string"
                        ? newNotification.meta.join_url
                        : undefined,
                    click_action:
                      typeof newNotification.meta?.join_url === "string"
                        ? newNotification.meta.join_url
                        : undefined,
                    livestream_id:
                      newNotification.meta?.livestream_id != null
                        ? String(newNotification.meta.livestream_id)
                        : undefined,
                  }
                : newNotification.type === DONATION_CONFIRMED_TYPE || newNotification.type === DONATION_RECEIVED_TYPE
                  ? {
                      type: newNotification.type,
                      click_action: donationNotificationTarget(newNotification) ?? undefined,
                    }
                  : giftCardNotificationTarget(newNotification)
                    ? {
                        type: newNotification.type,
                        click_action: giftCardNotificationTarget(newNotification) ?? undefined,
                      }
                    : {
                        click_action: newNotification.content_item_id
                          ? `/notifications/content/${newNotification.content_item_id}`
                          : undefined,
                      },
          })
        })

      userChannel.listen(".Illuminate\\Notifications\\Events\\BroadcastNotificationCreated", (data: any) => {
          console.log("[v0] Received Laravel notification:", data)

          const notificationData = parseNotificationPayload(data.data)
          const invitationId = notificationData.invitation_id ?? notificationData.meta?.invitation_id

          const newNotification: Notification = mapDatabaseNotification({
            id: data.id || `laravel-${Date.now()}`,
            type: notificationData.type || data.type || "notification",
            data: notificationData,
            created_at: data.created_at || new Date().toISOString(),
            read_at: null,
          })

          setNotifications((prev) => [newNotification, ...prev])
          setUnreadCount((prev) => prev + 1)

          playNotificationSound()
          const toastType = notificationData.type || data.type || ""
          void showNativePushNotification({
            title: newNotification.title,
            body: newNotification.body,
            data:
              toastType === UNITY_MEET_INVITATION_TYPE
                ? {
                    type: UNITY_MEET_INVITATION_TYPE,
                    join_url:
                      typeof newNotification.meta?.join_url === "string"
                        ? newNotification.meta.join_url
                        : typeof notificationData.join_url === "string"
                          ? notificationData.join_url
                          : undefined,
                    click_action:
                      typeof newNotification.meta?.join_url === "string"
                        ? newNotification.meta.join_url
                        : typeof notificationData.url === "string"
                          ? notificationData.url
                          : undefined,
                    livestream_id:
                      notificationData.livestream_id != null
                        ? String(notificationData.livestream_id)
                        : undefined,
                  }
                : newNotification.type === DONATION_CONFIRMED_TYPE || newNotification.type === DONATION_RECEIVED_TYPE
                  ? {
                      type: newNotification.type,
                      click_action: donationNotificationTarget(newNotification) ?? undefined,
                    }
                  : giftCardNotificationTarget(newNotification)
                    ? {
                        type: newNotification.type,
                        click_action: giftCardNotificationTarget(newNotification) ?? undefined,
                      }
                    : {
                        click_action: newNotification.content_item_id
                          ? `/notifications/content/${newNotification.content_item_id}`
                          : undefined,
                      },
          })
        })

      // Do NOT auto-request notification permission here — it runs on every mount and causes
      // repeated "Allow notifications?" prompts (e.g. in Edge). Permission is requested once
      // via PushNotificationManager / app layout after login.

      return () => {
        userChannel.stopListening(".campaign.notification")
        userChannel.stopListening(".Illuminate\\Notifications\\Events\\BroadcastNotificationCreated")
      }
    }
  }, [userId, emailVerified])

  const handleNotificationClick = async (notification: Notification) => {
    const success = await markAsRead(notification)
    if (!success) {
      console.error("Failed to mark notification as read")
    }

    if (onNotificationClick) {
      onNotificationClick(notification)
    }

    setIsOpen(false)
  }

  const handleMarkAllRead = async () => {
    const success = await markAllAsRead()
    if (!success) {
      toast.error("Could not mark all as read. Try again.")
    }
  }

  const handleClearAll = async () => {
    const confirmed = window.confirm("Are you sure you want to clear all notifications?")
    if (confirmed) {
      const success = await clearAllNotifications()
      if (!success) {
        toast.error("Could not clear notifications. Try again.")
      }
    }
  }

  const handleRefresh = () => {
    fetchNotifications()
  }

  /** Pending server-side invites show actions; legacy rows without the flag still get buttons if we have an id (API returns 422 if already handled). */
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
      await axios.post(`/organization/care-alliance-invitations/${invitationId}/${action}`)
      toast.success(action === "accept" ? "You joined the Unity Impact Alliance." : "Invitation declined.")
      await axios.post(`/notifications/${notification.id}/read`)
      setNotifications((prev) =>
        prev.map((n) =>
          n.id === notification.id
            ? {
                ...n,
                read: true,
                meta: { ...n.meta, show_care_alliance_actions: false },
              }
            : n,
        ),
      )
      setUnreadCount((prev) => Math.max(0, prev - (notification.read ? 0 : 1)))
      if (action === "accept") {
        router.visit("/organization/alliance-membership?tab=invitations#care-alliance-invitations")
      }
    } catch (err: any) {
      const msg = err.response?.data?.message
      toast.error(typeof msg === "string" ? msg : "Something went wrong. Try again.")
    } finally {
      setCareAllianceActionLoadingId(null)
    }
  }

  const celebrantInitials = (name?: string) => {
    if (!name?.trim()) return "?"
    const parts = name.trim().split(/\s+/)
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase()
    return name.slice(0, 2).toUpperCase()
  }

  return (
    <Popover open={isOpen} onOpenChange={setIsOpen}>
      <PopoverTrigger asChild>
        <Button
          variant="ghost"
          size="icon"
          className="relative shrink-0 rounded-full hover:bg-muted/80 focus-visible:ring-2 focus-visible:ring-primary/40"
          aria-label="Open notifications"
        >
          <Bell className="h-5 w-5" />
          {unreadCount > 0 && (
            <Badge
              variant="destructive"
              className="absolute -right-0.5 -top-0.5 flex h-5 min-w-5 items-center justify-center rounded-full px-1 text-[10px] font-semibold shadow-sm"
            >
              {unreadCount > 9 ? "9+" : unreadCount}
            </Badge>
          )}
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align="end"
        sideOffset={8}
        collisionPadding={12}
        className={cn(
          "z-50 flex max-h-[min(85dvh,32rem)] min-h-0 w-[min(calc(100vw-1rem),22rem)] flex-col overflow-hidden p-0 sm:w-[min(calc(100vw-2rem),26rem)]",
          "rounded-2xl border border-border/60 bg-popover/95 text-popover-foreground shadow-2xl backdrop-blur-md",
          "data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0",
          "data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2",
        )}
      >
        {/* Header */}
        <div className="relative shrink-0 border-b border-border/50 bg-gradient-to-br from-primary/10 via-violet-500/5 to-transparent px-4 py-3 dark:from-primary/15 dark:via-violet-500/10">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0 flex-1">
              <h2 className="text-sm font-semibold tracking-tight text-foreground sm:text-base">Notifications</h2>
              <p className="mt-0.5 text-xs text-muted-foreground">
                {unreadCount > 0 ? (
                  <span className="font-medium text-primary">{unreadCount} unread</span>
                ) : (
                  "You're all caught up"
                )}
              </p>
            </div>
            <div className="flex shrink-0 items-center gap-0.5">
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-8 w-8 rounded-full text-muted-foreground hover:text-foreground"
                onClick={handleRefresh}
                disabled={isLoading}
                aria-label="Refresh notifications"
              >
                <RefreshCw className={cn("h-4 w-4", isLoading && "animate-spin")} />
              </Button>
              {notifications.length > 0 && (
                <>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8 rounded-full text-muted-foreground hover:text-foreground disabled:opacity-40"
                    onClick={handleMarkAllRead}
                    disabled={unreadCount === 0}
                    aria-label="Mark all as read"
                  >
                    <CheckCheck className="h-4 w-4" />
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8 rounded-full text-muted-foreground hover:text-destructive"
                    onClick={handleClearAll}
                    aria-label="Clear all notifications"
                  >
                    <Trash2 className="h-4 w-4" />
                  </Button>
                </>
              )}
            </div>
          </div>
        </div>

        {/* List: explicit max-height (not flex-1) so the panel never collapses to 0 when the popover only has max-h */}
        <div
          className={cn(
            "notification-bell-popover-scroll max-h-[calc(min(85dvh,32rem)-7.5rem)] overflow-y-auto overflow-x-hidden overscroll-y-contain",
            "px-0.5",
          )}
        >
          <div>
            {isLoading ? (
              <div className="flex flex-col items-center justify-center gap-3 py-14">
                <div className="h-9 w-9 animate-spin rounded-full border-2 border-primary/30 border-t-primary" />
                <p className="text-xs text-muted-foreground">Loading…</p>
              </div>
            ) : notifications.length === 0 ? (
              <div className="flex flex-col items-center justify-center px-6 py-12 text-center">
                <div className="mb-3 flex h-16 w-16 items-center justify-center rounded-2xl bg-muted/60 ring-1 ring-border/60 dark:bg-muted/30">
                  <Bell className="h-7 w-7 text-muted-foreground/70" strokeWidth={1.25} />
                </div>
                <p className="text-sm font-medium text-foreground">No notifications yet</p>
                <p className="mt-1 max-w-[16rem] text-xs leading-relaxed text-muted-foreground">
                  Birthday alerts and updates from nonprofits you follow will show up here.
                </p>
              </div>
            ) : (
              <ul className="divide-y divide-border/50">
                {notifications.map((notification) => {
                  const isBirthday = showSupporterBirthdayActions(notification)
                  const avatarUrl =
                    typeof notification.meta?.celebrant_avatar === "string"
                      ? notification.meta.celebrant_avatar
                      : null
                  const celebrantName =
                    typeof notification.meta?.celebrant_name === "string"
                      ? notification.meta.celebrant_name
                      : undefined

                  if (isBirthday) {
                    return (
                      <li key={notification.id} className="p-3 sm:p-3.5">
                        <div
                          className={cn(
                            "overflow-hidden rounded-xl border transition-shadow",
                            "border-violet-500/25 bg-gradient-to-br from-violet-500/10 via-blue-500/5 to-transparent",
                            "dark:border-violet-400/20 dark:from-violet-500/15 dark:via-blue-500/10",
                            !notification.read && "ring-1 ring-primary/25 shadow-md dark:ring-primary/20",
                          )}
                        >
                          <div className="flex gap-3 p-3">
                            <div className="relative shrink-0">
                              {avatarUrl ? (
                                <img
                                  src={avatarUrl}
                                  alt=""
                                  className="h-11 w-11 rounded-full border-2 border-white/20 object-cover shadow-sm dark:border-white/10 sm:h-12 sm:w-12"
                                  onError={(e) => {
                                    const el = e.currentTarget
                                    el.classList.add("hidden")
                                    el.nextElementSibling?.classList.remove("hidden")
                                  }}
                                />
                              ) : null}
                              <div
                                className={cn(
                                  "flex h-11 w-11 items-center justify-center rounded-full bg-gradient-to-br from-violet-600 to-blue-600 text-xs font-bold text-white shadow-inner sm:h-12 sm:w-12 sm:text-sm",
                                  avatarUrl ? "hidden" : "",
                                )}
                                aria-hidden
                              >
                                {celebrantInitials(celebrantName)}
                              </div>
                              <span className="absolute -bottom-0.5 -right-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-amber-500 text-[10px] shadow dark:bg-amber-400">
                                <Gift className="h-2.5 w-2.5 text-white" strokeWidth={2.5} />
                              </span>
                            </div>
                            <div className="min-w-0 flex-1 space-y-1">
                              <p className="text-sm font-semibold leading-snug text-foreground">{notification.title}</p>
                              <p className="text-xs leading-relaxed text-muted-foreground line-clamp-3">
                                {notification.body}
                              </p>
                              <p className="text-[10px] font-medium uppercase tracking-wide text-violet-600/90 dark:text-violet-300/90">
                                Believe Points gift
                              </p>
                            </div>
                          </div>
                          <div className="flex flex-col gap-2 border-t border-border/40 bg-black/[0.02] px-3 py-2.5 dark:bg-white/[0.02] sm:flex-row sm:items-center">
                            <Button
                              type="button"
                              size="sm"
                              className="h-9 w-full rounded-lg bg-gradient-to-r from-blue-600 to-violet-600 text-xs font-semibold text-white shadow-sm hover:from-blue-500 hover:to-violet-500 sm:flex-1"
                              onClick={async (e) => {
                                e.preventDefault()
                                e.stopPropagation()
                                const id = notification.meta?.celebrant_id
                                if (id == null) return
                                await markAsRead(notification)
                                router.visit(`/gift-bp?recipient=${id}`)
                              }}
                            >
                              <Gift className="mr-1.5 h-3.5 w-3.5" />
                              Send Believe Points
                            </Button>
                            <Button
                              type="button"
                              variant="outline"
                              size="sm"
                              className="h-9 w-full rounded-lg text-xs sm:w-auto sm:shrink-0"
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
                              Profile
                            </Button>
                          </div>
                          <button
                            type="button"
                            className="flex w-full items-center justify-center gap-1 border-t border-border/30 py-2 text-[10px] text-muted-foreground transition hover:bg-muted/40 hover:text-foreground"
                            onClick={() => markAsReadSilent(notification)}
                          >
                            <Sparkles className="h-3 w-3" />
                            Mark as read
                          </button>
                        </div>
                      </li>
                    )
                  }

                  return (
                    <li key={notification.id}>
                      <button
                        type="button"
                        className={cn(
                          "flex w-full gap-3 px-4 py-3.5 text-left transition-colors hover:bg-muted/50 sm:px-4",
                          !notification.read && "border-l-[3px] border-l-primary bg-primary/[0.04] dark:bg-primary/10",
                        )}
                        onClick={() => handleNotificationClick(notification)}
                      >
                        <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-muted/80 text-muted-foreground dark:bg-muted/50">
                          <Bell className="h-4 w-4" />
                        </div>
                        <div className="min-w-0 flex-1 space-y-1">
                          <p className="text-sm font-medium leading-snug text-foreground">{notification.title}</p>
                          <p
                            className={cn(
                              "text-xs leading-relaxed text-muted-foreground",
                              notification.type === CARE_ALLIANCE_INVITATION_TYPE ? "line-clamp-6" : "line-clamp-2",
                            )}
                          >
                            {notification.body}
                          </p>
                          {showCareAllianceActions(notification) && (
                            <div className="flex flex-wrap gap-2 pt-2" onClick={(e) => e.stopPropagation()}>
                              <Button
                                type="button"
                                size="sm"
                                className="h-8 rounded-lg text-xs"
                                disabled={careAllianceActionLoadingId === notification.id}
                                onClick={(e) => handleCareAllianceInvitationAction(e, notification, "accept")}
                              >
                                {careAllianceActionLoadingId === notification.id ? "…" : "Accept"}
                              </Button>
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8 rounded-lg text-xs"
                                disabled={careAllianceActionLoadingId === notification.id}
                                onClick={(e) => handleCareAllianceInvitationAction(e, notification, "decline")}
                              >
                                Decline
                              </Button>
                            </div>
                          )}
                          {notification.senderLabel ? (
                            <p className="text-[11px] font-medium text-foreground/80">{notification.senderLabel}</p>
                          ) : null}
                          <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5 pt-0.5">
                            <span className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground/90">
                              {String(notification.type).replace(/_/g, " ")}
                            </span>
                            <span className="text-[10px] text-muted-foreground">
                              {new Date(notification.timestamp).toLocaleString(undefined, {
                                month: "short",
                                day: "numeric",
                                hour: "numeric",
                                minute: "2-digit",
                              })}
                            </span>
                          </div>
                        </div>
                        {!notification.read && (
                          <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary shadow-sm shadow-primary/40" />
                        )}
                      </button>
                    </li>
                  )
                })}
              </ul>
            )}
          </div>
        </div>

        {/* Footer */}
        <div className="shrink-0 border-t border-border/50 bg-muted/20 p-2 dark:bg-muted/10">
          <Button
            type="button"
            variant="secondary"
            className="h-9 w-full rounded-xl text-xs font-medium shadow-none sm:text-sm"
            onClick={() => {
              setIsOpen(false)
              router.visit(route("notifications.index"))
            }}
          >
            View all in inbox
          </Button>
        </div>
      </PopoverContent>
    </Popover>
  )
}
