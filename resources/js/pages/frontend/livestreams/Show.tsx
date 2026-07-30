"use client"

import { useState, useEffect, useMemo, useCallback } from "react"
import { Head, router, usePage } from "@inertiajs/react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog"
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@/components/ui/sheet"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import { Switch } from "@/components/admin/ui/switch"
import UnityMeetLayout from "@/layouts/UnityMeetLayout"
import VdoMeetingIframe from "@/components/meeting/VdoMeetingIframe"
import { useAutoStopLivestreamOnLeave } from "@/hooks/useAutoStopLivestreamOnLeave"
import { useUnityMeetHostRealtime } from "@/hooks/useUnityMeetHostRealtime"
import GoLiveConfirmDialog from "@/components/livestreams/GoLiveConfirmDialog"
import { PageHead } from "@/components/frontend/PageHead"
import {
  Copy,
  ExternalLink,
  Share2,
  Square,
  Key,
  Info,
  CheckCircle2,
  ArrowLeft,
  MoreVertical,
  Radio,
  Globe,
  Youtube,
  HelpCircle,
  Download,
  HardDrive,
  Cloud,
  Calendar,
  Mail,
  Users,
  X,
  Send,
  Play,
  PhoneOff,
} from "lucide-react"
import { Link } from "@inertiajs/react"
import { toast } from "react-hot-toast"
import { useEmailCreditsState } from "@/hooks/use-email-credits-state"
import BuyEmailCreditsDialog, { type EmailPackageOption } from "@/components/meeting/BuyEmailCreditsDialog"
import EmailCreditsMeetingActions from "@/components/meeting/EmailCreditsMeetingActions"
import UnityMeetInviteChannelPicker, {
  type UnityMeetInviteChannel,
} from "@/components/meeting/UnityMeetInviteChannelPicker"
import UnityMeetInviteRecipientField from "@/components/meeting/UnityMeetInviteRecipientField"
import UnityMeetParticipantPanel, {
  type UnityMeetParticipant,
} from "@/components/meeting/UnityMeetParticipantPanel"
import UnityMeetGiftDialog, {
  type GiftOccasionOption,
} from "@/components/meeting/UnityMeetGiftDialog"
import UnityMeetGiftCelebrationLayer from "@/components/meeting/UnityMeetGiftCelebrationLayer"
import { applyVdoMinimalHostUi, applyVdoMeetingSession } from "@/lib/vdoMeeting"
import {
  canEndYoutubeLive,
  isStreamRelayInProgress,
  isUnityLivePublished,
  isYoutubeStreamSessionActive,
  resolveStreamingDisplayStatus,
  resolveUnityLiveDisplayStatus,
  streamingDisplayBadgeClass,
} from "@/lib/streamingDisplayStatus"

interface Livestream {
  id: number
  title: string | null
  description: string | null
  roomName: string
  roomPassword: string
  requiresPasscode?: boolean
  directorUrl: string
  participantUrl: string
  hostPushUrl: string
  scenePushUrl?: string | null
  /** Participant-canvas mixer page URL — hidden iframe when canvasMode is on so
   * all participants reach YouTube, not just the host. */
  canvasUrl?: string | null
  /** When true, the meeting runs in multi-participant canvas mode. */
  canvasMode?: boolean
  /** When true, host/guest VDO URLs include &mediamtx (WHIP to bridge). Off on local by default. */
  browserMediaMtxPush?: boolean
  watchUrl: string | null
  unityLiveUrl?: string
  liveViewerUrl?: string
  joinUrl?: string
  status: "draft" | "scheduled" | "meeting_live" | "live" | "ended" | "cancelled"
  scheduledAt: string | null
  participantEmails?: string[]
  meetingSessionKey?: number
  startedAt: string | null
  endedAt: string | null
  isPublic?: boolean
  canStartMeeting?: boolean
  /** When false, queueing a cloud stream is blocked server-side (e.g. already live or cancelled). */
  canGoLive?: boolean
  wantsYoutubeLive?: boolean
  wantsUnityLive?: boolean
  canSetUnityLive?: boolean
  canQueueYoutubeLive?: boolean
  latestInviteUrl?: string | null
  dropboxRecordingAvailable?: boolean
  directorUrlDropbox?: string | null
  hostPushUrlDropbox?: string | null
  hasStreamKey?: boolean
  youtubeGoLiveEnabled?: boolean
  viewLink?: string | null
  streamKeyDisplay?: string | null
  rtmpUrl?: string | null
  youtubeConnected?: boolean
  youtubeChannelUrl?: string | null
  streamingQueueStatus?: StreamingQueueStatus | null
  hasActiveStreamingJob?: boolean
  youtubeBroadcastId?: string | null
  youtubeWatchUrl?: string | null
}

interface StreamingQueueStatus {
  status?: string | null
  livestreamStatus?: string
  streamStopRequested?: boolean
  updatedAt?: string | null
  failureReason?: string | null
}

interface RecordingConsentDecline {
  id: number
  guestLabel: string | null
  createdAt: string | null
}

interface EmailCredits {
  emails_included: number
  emails_used: number
  emails_left: number
}

interface Props {
  livestream: Livestream
  recordingConsentDeclines: RecordingConsentDecline[]
  participantRoster?: UnityMeetParticipant[]
  authUserId?: number
  broadcastChannel?: string
  giftOccasions?: GiftOccasionOption[]
  senderGiftBalances?: { purchased_believe_points: number }
  emailCredits?: EmailCredits
  emailPackages?: EmailPackageOption[]
  stripeMinCheckoutUsd?: number
}

type SidebarTab = "meeting-info" | "participants" | "share"

function formatDeclineTime(iso: string | null): string {
  if (!iso) return "—"
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString()
}

export default function SupporterShowLivestream({
  livestream: initialLivestream,
  recordingConsentDeclines: initialRecordingConsentDeclines,
  participantRoster: initialParticipantRoster = [],
  authUserId = 0,
  broadcastChannel,
  giftOccasions = [],
  senderGiftBalances = { purchased_believe_points: 0 },
  emailCredits,
  emailPackages = [],
  stripeMinCheckoutUsd = 0.5,
}: Props) {
  const {
    livestream,
    recordingConsentDeclines,
    participantRoster,
    patchLivestream,
  } = useUnityMeetHostRealtime({
    broadcastChannel,
    livestream: initialLivestream,
    recordingConsentDeclines: initialRecordingConsentDeclines,
    participantRoster: initialParticipantRoster,
  })

  const { props: inertiaProps } = usePage<{ errors?: Record<string, string | string[]>; success?: string }>()
  const prepareYoutubeError = inertiaProps.errors?.youtube
  const prepareYoutubeErrorText = Array.isArray(prepareYoutubeError)
    ? prepareYoutubeError[0]
    : prepareYoutubeError

  const queueStreamError = inertiaProps.errors?.go_live
  const queueStreamErrorText = Array.isArray(queueStreamError) ? queueStreamError[0] : queueStreamError

  const participantInviteError = inertiaProps.errors?.email
  const participantInviteErrorText = Array.isArray(participantInviteError)
    ? participantInviteError[0]
    : participantInviteError

  const [copied, setCopied] = useState<string | null>(null)
  const [isUpdatingStatus, setIsUpdatingStatus] = useState(false)
  const [isGoLivePending, setIsGoLivePending] = useState(false)
  const [infoOpen, setInfoOpen] = useState(false)
  const [isUpdatingVisibility, setIsUpdatingVisibility] = useState(false)
  const [isEndingStreamPending, setIsEndingStreamPending] = useState(false)
  const [isStartingMeeting, setIsStartingMeeting] = useState(false)
  const [isEndingMeeting, setIsEndingMeeting] = useState(false)
  const [participantsPanelOpen, setParticipantsPanelOpen] = useState(true)
  const [participantsMobileOpen, setParticipantsMobileOpen] = useState(false)
  const [giftRecipient, setGiftRecipient] = useState<UnityMeetParticipant | null>(null)
  const [giftDialogOpen, setGiftDialogOpen] = useState(false)
  const [vdoVideoActive, setVdoVideoActive] = useState(true)
  const [sidebarTab, setSidebarTab] = useState<SidebarTab>("meeting-info")

  const isMeetingActive = ["meeting_live", "live", "starting"].includes(livestream.status)
  const canStartMeeting = Boolean(livestream.canStartMeeting) && !isMeetingActive

  useEffect(() => {
    if (isMeetingActive) {
      setVdoVideoActive(true)
    }
  }, [isMeetingActive, livestream.meetingSessionKey])

  const openSharePanel = () => {
    setSidebarTab("share")
    setInfoOpen(true)
  }

  const openGiftDialog = useCallback((participant: UnityMeetParticipant) => {
    setGiftRecipient(participant)
    setGiftDialogOpen(true)
  }, [])
  const [goLiveOpen, setGoLiveOpen] = useState(false)
  const [goLivePrecheckOpen, setGoLivePrecheckOpen] = useState(false)
  const [goLiveConfirmOpen, setGoLiveConfirmOpen] = useState(false)
  const [isPrepareYoutubeLive, setIsPrepareYoutubeLive] = useState(false)
  const [goLiveTab, setGoLiveTab] = useState("streaming")
  const [streamKey, setStreamKey] = useState("")
  const [isUpdatingStreamKey, setIsUpdatingStreamKey] = useState(false)
  const [recordingDestination, setRecordingDestination] = useState<"local" | "dropbox">(
    () => (livestream.dropboxRecordingAvailable ? "dropbox" : "local")
  )
  const [removingParticipantEmail, setRemovingParticipantEmail] = useState<string | null>(null)
  const [inviteEmailInput, setInviteEmailInput] = useState("")
  const [invitingParticipant, setInvitingParticipant] = useState(false)
  const [resendingEmail, setResendingEmail] = useState<string | null>(null)
  const [buyCreditsOpen, setBuyCreditsOpen] = useState(false)
  const [inviteNotifyVia, setInviteNotifyVia] = useState<UnityMeetInviteChannel>("both")

  useAutoStopLivestreamOnLeave({
    livestreamId: livestream.id,
    status: livestream.status,
    stopUrl: route("livestreams.supporter.abandon-host-session", livestream.id),
  })

  const canInviteParticipants = !["ended", "cancelled"].includes(livestream.status)
  const invitedCount = livestream.participantEmails?.length ?? 0
  /** Instant and scheduled meetings both allow creator invites (email / BIU / FCM). */
  const showInviteComposer = canInviteParticipants
  const { credits: emailCreditsLive, canSend: canSendEmailInvites, syncFromServer, applyDelta } =
    useEmailCreditsState(emailCredits)

  useEffect(() => {
    if (inertiaProps.success && emailCredits) {
      syncFromServer(emailCredits)
    }
  }, [inertiaProps.success, emailCredits, syncFromServer])

  useEffect(() => {
    if (!showInviteComposer && sidebarTab === "participants") {
      setSidebarTab("meeting-info")
    }
  }, [showInviteComposer, sidebarTab])

  const effectiveHostUrl =
    recordingDestination === "dropbox" && livestream.hostPushUrlDropbox
      ? livestream.hostPushUrlDropbox
      : (livestream.hostPushUrl || livestream.directorUrl)

  /** Host push URLs: ensure Meet-style labels + single-row grid (matches participant embeds). */
  const vdoHostIframeSrc = useMemo(() => {
    const raw = effectiveHostUrl
    if (!raw?.includes("vdo.ninja")) return raw
    try {
      const u = new URL(raw)
      if (u.searchParams.has("push") && u.searchParams.has("room")) {
        applyVdoMinimalHostUi(u)
      }
      applyVdoMeetingSession(u, livestream.meetingSessionKey ?? 0)
      return u.toString()
    } catch {
      return raw
    }
  }, [effectiveHostUrl, livestream.meetingSessionKey])

  const showVdoVideo = isMeetingActive && vdoVideoActive && !isEndingMeeting && Boolean(vdoHostIframeSrc)

  const joinUrl = livestream.latestInviteUrl ?? livestream.joinUrl ?? (typeof window !== "undefined" ? `${window.location.origin}/livestreams/join/${livestream.roomName}` : "")
  const unityLiveUrl = livestream.unityLiveUrl ?? (typeof window !== "undefined" ? `${window.location.origin}/unity-live/${livestream.roomName}` : "")
  const liveViewerUrl = livestream.liveViewerUrl ?? (typeof window !== "undefined" ? `${window.location.origin}/live/${livestream.roomName}` : "")

  const streamRelayInProgress = useMemo(
    () =>
      isStreamRelayInProgress({
        jobStatus: livestream.streamingQueueStatus?.status,
        livestreamStatus: livestream.streamingQueueStatus?.livestreamStatus ?? livestream.status,
        streamStopRequested: livestream.streamingQueueStatus?.streamStopRequested,
      }),
    [livestream.streamingQueueStatus, livestream.status],
  )

  const isGoLiveBusy = isGoLivePending || streamRelayInProgress

  const showYoutubeLiveButton =
    Boolean(livestream.wantsYoutubeLive) &&
    Boolean(livestream.canQueueYoutubeLive) &&
    !streamRelayInProgress &&
    !isGoLivePending

  /** Top bar, Stream options, sidebar YouTube card — logic kept; toggle true to show again. */
  const showYoutubeLiveMeetingChrome = true
  const showYoutubeLiveButtonInTopBar = showYoutubeLiveMeetingChrome
  const showYoutubeLiveSidebarCard = showYoutubeLiveMeetingChrome
  const showStreamOptionsButton = showYoutubeLiveMeetingChrome

  const showUnityLiveButton =
    Boolean(livestream.wantsUnityLive) && Boolean(livestream.canSetUnityLive)

  const liveActionDisabled = isUpdatingStatus || isEndingStreamPending || isStartingMeeting || isEndingMeeting

  const endStreamError = inertiaProps.errors?.error
  const endStreamErrorText = Array.isArray(endStreamError) ? endStreamError[0] : endStreamError

  const canEndYoutubeLiveNow = useMemo(
    () =>
      canEndYoutubeLive({
        wantsYoutubeLive: livestream.wantsYoutubeLive,
        livestreamStatus: livestream.status,
        jobStatus: livestream.streamingQueueStatus?.status,
        hasActiveStreamingJob: livestream.hasActiveStreamingJob,
      }),
    [
      livestream.wantsYoutubeLive,
      livestream.status,
      livestream.streamingQueueStatus?.status,
      livestream.hasActiveStreamingJob,
    ],
  )

  useEffect(() => {
    if (isEndingStreamPending && !canEndYoutubeLiveNow) {
      setIsEndingStreamPending(false)
    }
  }, [isEndingStreamPending, canEndYoutubeLiveNow])

  useEffect(() => {
    if (endStreamErrorText) {
      setIsEndingStreamPending(false)
    }
  }, [endStreamErrorText])

  const copyToClipboard = (text: string, key: string) => {
    navigator.clipboard.writeText(text)
    setCopied(key)
    setTimeout(() => setCopied(null), 2000)
  }

  const getStatusBadge = () => {
    const statusConfig = {
      draft: { label: "Draft", className: "bg-gray-100 text-gray-600 border-gray-200 dark:bg-gray-500/20 dark:text-gray-400 dark:border-gray-500/30" },
      scheduled: { label: "Scheduled", className: "bg-blue-100 text-blue-700 border-blue-200 dark:bg-blue-500/20 dark:text-blue-400 dark:border-blue-500/30" },
      meeting_live: { label: "Meeting Live", className: "bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-500/20 dark:text-amber-400 dark:border-amber-500/30" },
      live: { label: "Stream Live", className: "bg-red-100 text-red-600 border-red-200 dark:bg-red-500/20 dark:text-red-400 dark:border-red-500/40 animate-pulse" },
      ended: { label: "Ended", className: "bg-gray-100 text-gray-600 border-gray-200 dark:bg-gray-500/20 dark:text-gray-400 dark:border-gray-500/30" },
      cancelled: { label: "Cancelled", className: "bg-red-50 text-red-600 border-red-200 dark:bg-red-500/20 dark:text-red-400 dark:border-red-500/30" },
    }
    const config = statusConfig[livestream.status] || statusConfig.draft
    return <Badge variant="outline" className={config.className}>{config.label}</Badge>
  }

  const queueCloudStream = () => {
    if (isGoLiveBusy) {
      return
    }
    if (!livestream.hasStreamKey) {
      setGoLivePrecheckOpen(true)
      return
    }
    setIsGoLivePending(true)
    router.post(`/livestreams/supporter/${livestream.id}/queue-stream-relay`, {}, {
      preserveScroll: true,
      onFinish: () => setIsGoLivePending(false),
    })
  }

  const requestGoLive = () => {
    if (isGoLiveBusy) {
      return
    }
    if (!livestream.hasStreamKey) {
      setGoLivePrecheckOpen(true)
      return
    }
    setGoLiveConfirmOpen(true)
  }

  const handleGoLiveClick = () => {
    requestGoLive()
  }

  const handleGoLiveConfirm = () => {
    queueCloudStream()
  }

  const goUnityLive = () => {
    if (!livestream.wantsUnityLive) {
      toast.error('Turn on “Show on Unity Live” first.')
      return
    }
    if (!livestream.canSetUnityLive) {
      toast.error(`Cannot publish on Unity Live while status is “${livestream.status}”.`)
      return
    }
    if (liveActionDisabled) {
      return
    }
    setIsUpdatingStatus(true)
    // Optimistic UI — Inertia props / Reverb can lag; badge + buttons must flip immediately.
    patchLivestream({
      status: "live",
      startedAt: new Date().toISOString(),
      canSetUnityLive: false,
      canStartMeeting: false,
      canGoLive: Boolean(livestream.wantsYoutubeLive && livestream.canQueueYoutubeLive),
      wantsUnityLive: true,
      isPublic: true,
    } as Partial<typeof livestream>)
    router.post(`/livestreams/supporter/${livestream.id}/set-live`, {}, {
      preserveScroll: true,
      onSuccess: (page) => {
        const err = (page.props as { errors?: { error?: string | string[] } }).errors?.error
        if (err) {
          toast.error(Array.isArray(err) ? err[0] : err)
          return
        }
        const next = (page.props as { livestream?: typeof livestream }).livestream
        if (next) {
          patchLivestream({
            status: next.status,
            startedAt: next.startedAt,
            canSetUnityLive: next.canSetUnityLive,
            canStartMeeting: next.canStartMeeting,
            canGoLive: next.canGoLive,
            canQueueYoutubeLive: next.canQueueYoutubeLive,
            wantsUnityLive: next.wantsUnityLive,
            isPublic: next.isPublic,
            streamingQueueStatus: next.streamingQueueStatus,
            hasActiveStreamingJob: next.hasActiveStreamingJob,
          } as Partial<typeof livestream>)
        }
        toast.success("You're live on Unity Live.")
      },
      onError: (errors) => {
        patchLivestream({
          status: "meeting_live",
          canSetUnityLive: true,
          canStartMeeting: false,
        } as Partial<typeof livestream>)
        const message = typeof errors.error === "string"
          ? errors.error
          : Array.isArray(errors.error)
            ? errors.error[0]
            : "Could not go live on Unity Live. Try again."
        toast.error(message)
      },
      onFinish: () => setIsUpdatingStatus(false),
    })
  }

  const youtubeStreamActive = useMemo(
    () =>
      isYoutubeStreamSessionActive({
        wantsYoutubeLive: livestream.wantsYoutubeLive,
        livestreamStatus: livestream.status,
        jobStatus: livestream.streamingQueueStatus?.status,
        hasActiveStreamingJob: livestream.hasActiveStreamingJob,
      }),
    [
      livestream.wantsYoutubeLive,
      livestream.status,
      livestream.streamingQueueStatus?.status,
      livestream.hasActiveStreamingJob,
    ],
  )

  const showEndMeetingButton = isMeetingActive && !isEndingStreamPending

  const showEndUnityLiveButton =
    isUnityLivePublished({
      wantsUnityLive: livestream.wantsUnityLive,
      livestreamStatus: livestream.status,
      youtubeStreamActive,
    }) && !isEndingStreamPending

  const showEndYoutubeLiveButton = canEndYoutubeLiveNow

  const handleEndUnityLive = () => {
    if (!showEndUnityLiveButton || liveActionDisabled) {
      return
    }
    setIsUpdatingStatus(true)
    patchLivestream({
      status: "meeting_live",
      canSetUnityLive: true,
      canGoLive: true,
    } as Partial<typeof livestream>)
    router.post(route("livestreams.supporter.end-unity-live", livestream.id), {}, {
      preserveScroll: true,
      onSuccess: (page) => {
        const err = (page.props as { errors?: { error?: string | string[] } }).errors?.error
        if (err) {
          patchLivestream({
            status: "live",
            canSetUnityLive: false,
          } as Partial<typeof livestream>)
          toast.error(Array.isArray(err) ? err[0] : err)
          return
        }
        const next = (page.props as { livestream?: typeof livestream }).livestream
        if (next) {
          patchLivestream({
            status: next.status,
            canSetUnityLive: next.canSetUnityLive,
            canGoLive: next.canGoLive,
            canQueueYoutubeLive: next.canQueueYoutubeLive,
            startedAt: next.startedAt,
            streamingQueueStatus: next.streamingQueueStatus,
            hasActiveStreamingJob: next.hasActiveStreamingJob,
          } as Partial<typeof livestream>)
        }
        toast.success("Removed from Unity Live. Meeting is still open.")
      },
      onError: (errors) => {
        patchLivestream({
          status: "live",
          canSetUnityLive: false,
        } as Partial<typeof livestream>)
        const message = typeof errors.error === "string"
          ? errors.error
          : Array.isArray(errors.error)
            ? errors.error[0]
            : "Could not end Unity Live."
        toast.error(message)
      },
      onFinish: () => setIsUpdatingStatus(false),
    })
  }

  const updateStreamKey = (e: React.FormEvent) => {
    e.preventDefault()
    setIsUpdatingStreamKey(true)
    router.patch(
      `/livestreams/supporter/${livestream.id}/stream-key`,
      { youtube_stream_key: streamKey },
      {
        preserveScroll: true,
        onFinish: () => {
          setIsUpdatingStreamKey(false)
          setStreamKey("")
        },
      }
    )
  }

  const handleEndStream = () => {
    if (!canEndYoutubeLiveNow || liveActionDisabled) {
      return
    }
    setIsUpdatingStatus(true)
    setIsEndingStreamPending(true)
    router.post(`/livestreams/supporter/${livestream.id}/end-stream`, {}, {
      preserveScroll: true,
      onFinish: () => setIsUpdatingStatus(false),
    })
  }

  const handleStartMeeting = () => {
    if (!canStartMeeting || isStartingMeeting) {
      return
    }
    setIsStartingMeeting(true)
    router.post(route("livestreams.supporter.start-meeting", livestream.id), {}, {
      preserveScroll: true,
      onFinish: () => setIsStartingMeeting(false),
    })
  }

  const handleEndMeeting = () => {
    if (!isMeetingActive || isEndingMeeting || liveActionDisabled) {
      return
    }
    setVdoVideoActive(false)
    setIsEndingMeeting(true)
    router.post(route("livestreams.supporter.end-meeting", livestream.id), {}, {
      preserveScroll: true,
      onFinish: () => setIsEndingMeeting(false),
    })
  }

  const openParticipantsInvite = () => {
    setSidebarTab("participants")
    setInfoOpen(true)
  }

  const streamDisplayStatus = useMemo(
    () =>
      resolveStreamingDisplayStatus({
        jobStatus: livestream.streamingQueueStatus?.status,
        livestreamStatus: livestream.streamingQueueStatus?.livestreamStatus ?? livestream.status,
        isEndingStreamPending,
        streamStopRequested: livestream.streamingQueueStatus?.streamStopRequested,
        failureReason: livestream.streamingQueueStatus?.failureReason,
        jobUpdatedAt: livestream.streamingQueueStatus?.updatedAt ?? null,
      }),
    [
      livestream.streamingQueueStatus,
      livestream.status,
      isEndingStreamPending,
    ],
  )

  const unityLiveDisplayStatus = useMemo(
    () =>
      resolveUnityLiveDisplayStatus({
        livestreamStatus: livestream.status,
        isPublic: livestream.isPublic,
        wantsUnityLive: livestream.wantsUnityLive,
        youtubeStreamActive,
      }),
    [livestream.status, livestream.isPublic, livestream.wantsUnityLive, youtubeStreamActive],
  )

  const toggleVisibility = (publicVal: boolean) => {
    setIsUpdatingVisibility(true)
    router.patch(
      `/livestreams/supporter/${livestream.id}/visibility`,
      { is_public: publicVal },
      { preserveScroll: true, onFinish: () => setIsUpdatingVisibility(false) }
    )
  }

  const meetingInfoContent = (
    <div className="w-full min-w-0 space-y-4">
      {livestream.scheduledAt ? (
        <div className="rounded-lg border border-blue-500/35 bg-gradient-to-br from-blue-500/10 to-purple-500/5 p-3.5 space-y-1">
          <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-blue-800 dark:text-blue-300">
            <Calendar className="h-3.5 w-3.5 text-blue-600 dark:text-blue-400" />
            Scheduled
          </div>
          <p className="text-sm text-foreground">
            {new Date(livestream.scheduledAt).toLocaleString(undefined, {
              weekday: "long",
              month: "long",
              day: "numeric",
              year: "numeric",
              hour: "numeric",
              minute: "2-digit",
            })}
          </p>
          {showInviteComposer && invitedCount > 0 ? (
            <p className="text-[11px] text-muted-foreground">
              See the{" "}
              <button type="button" className="font-medium text-primary hover:underline" onClick={() => setSidebarTab("participants")}>
                Participants
              </button>{" "}
              tab for invited guests.
            </p>
          ) : null}
        </div>
      ) : null}
      {livestream.wantsUnityLive ? (
        <div className="rounded-lg border border-purple-500/35 bg-gradient-to-br from-purple-500/10 to-blue-500/5 p-3.5 space-y-2">
          <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-purple-800 dark:text-purple-300">
            <Globe className="h-3.5 w-3.5 text-purple-600 dark:text-purple-400" />
            Unity Live readiness
          </div>
          {unityLiveDisplayStatus ? (
            <div className="flex items-center justify-between gap-2 text-xs">
              <span className="text-muted-foreground">Status</span>
              <Badge
                variant="outline"
                className={`h-6 max-w-[58%] truncate px-2 text-[10px] font-semibold ${streamingDisplayBadgeClass(unityLiveDisplayStatus.tone)}`}
                title={unityLiveDisplayStatus.description}
              >
                {unityLiveDisplayStatus.label}
              </Badge>
            </div>
          ) : null}
          {showEndUnityLiveButton ? (
            <Button
              variant="destructive"
              size="sm"
              className="mt-2 h-9 w-full"
              onClick={handleEndUnityLive}
              disabled={liveActionDisabled}
            >
              <Square className="h-4 w-4 mr-1.5" />
              {isUpdatingStatus ? "Ending…" : "End Unity Live"}
            </Button>
          ) : null}
        </div>
      ) : null}
      {livestream.wantsYoutubeLive && showYoutubeLiveSidebarCard ? (
      <div className="rounded-lg border border-red-500/35 bg-red-500/5 p-3.5 space-y-2">
        <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-red-800 dark:text-red-300">
          <Youtube className="h-3.5 w-3.5 text-red-600 dark:text-red-400" />
          YouTube readiness
        </div>
        <div className="space-y-1.5 text-xs">
          <div className="flex items-center justify-between gap-2">
            <span className="text-muted-foreground">Channel connected</span>
            <span className={livestream.youtubeConnected ? "text-green-600 dark:text-green-400 font-medium" : "text-amber-600 dark:text-amber-400 font-medium"}>
              {livestream.youtubeConnected ? "Yes" : "No"}
            </span>
          </div>
          <div className="flex items-center justify-between gap-2">
            <span className="text-muted-foreground">YouTube live prepared</span>
            <span className={livestream.hasStreamKey ? "text-green-600 dark:text-green-400 font-medium" : "text-amber-600 dark:text-amber-400 font-medium"}>
              {livestream.hasStreamKey ? "Yes" : "No"}
            </span>
          </div>
          {streamDisplayStatus ? (
            <div className="space-y-1">
              <div className="flex items-center justify-between gap-2">
                <span className="text-muted-foreground">Stream status</span>
                <Badge
                  variant="outline"
                  className={`h-6 max-w-[58%] truncate px-2 text-[10px] font-semibold ${streamingDisplayBadgeClass(streamDisplayStatus.tone)}`}
                  title={streamDisplayStatus.description}
                >
                  {streamDisplayStatus.label}
                </Badge>
              </div>
              <p className="text-[10px] leading-snug text-muted-foreground">{streamDisplayStatus.description}</p>
            </div>
          ) : null}
        </div>
        {(livestream.youtubeChannelUrl ?? "").trim().length > 0 && (
          <p className="text-[11px] text-muted-foreground truncate">
            Channel: {livestream.youtubeChannelUrl}
          </p>
        )}
        {livestream.streamingQueueStatus?.status === "failed" &&
          livestream.streamingQueueStatus?.failureReason && (
          <p className="text-[11px] text-red-600 dark:text-red-400">
            Last failure: {livestream.streamingQueueStatus.failureReason}
          </p>
        )}
        {showEndYoutubeLiveButton ? (
          <Button
            variant="destructive"
            size="sm"
            className="mt-2 h-9 w-full"
            onClick={handleEndStream}
            disabled={liveActionDisabled}
          >
            <Square className="h-4 w-4 mr-1.5" />
            {isEndingStreamPending ? "Ending…" : "End YouTube Live"}
          </Button>
        ) : null}
      </div>
      ) : null}
      {recordingConsentDeclines.length > 0 && (
        <div className="rounded-lg border border-amber-500/35 bg-amber-500/10 p-3.5 space-y-2">
          <p className="text-xs font-semibold uppercase tracking-wider text-amber-800 dark:text-amber-200">
            Recording consent declined
          </p>
          <p className="text-[11px] text-muted-foreground">
            These people chose not to join while recording is on. They were not admitted to the room.
          </p>
          <ul className="text-xs space-y-1.5 text-foreground max-h-40 overflow-y-auto">
            {recordingConsentDeclines.map((row) => (
              <li key={row.id} className="flex justify-between gap-2 border-b border-amber-500/15 pb-1.5 last:border-0">
                <span className="font-medium truncate">{(row.guestLabel ?? "").trim() || "Guest"}</span>
                <span className="shrink-0 text-muted-foreground tabular-nums">{formatDeclineTime(row.createdAt)}</span>
              </li>
            ))}
          </ul>
        </div>
      )}
      {/* Recording saved to: Local / Dropbox — always show so user can see options and connect Dropbox */}
      <div className="rounded-lg border border-border bg-muted/30 p-3.5 space-y-2">
        <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
          <Download className="h-3.5 w-3.5 text-primary" />
          Recording saved to
        </div>
        <p className="text-xs text-muted-foreground">
          Choose where recordings are stored when you start recording in the meeting.
        </p>
        <div className="flex gap-2">
          <Button
            variant={recordingDestination === "local" ? "default" : "outline"}
            size="sm"
            className="flex-1 h-9 gap-1.5 text-xs"
            onClick={() => setRecordingDestination("local")}
          >
            <HardDrive className="h-3.5 w-3.5" />
            Local
          </Button>
          <Button
            variant={recordingDestination === "dropbox" ? "default" : "outline"}
            size="sm"
            className="flex-1 h-9 gap-1.5 text-xs"
            onClick={() => livestream.dropboxRecordingAvailable && setRecordingDestination("dropbox")}
            disabled={!livestream.dropboxRecordingAvailable}
            title={!livestream.dropboxRecordingAvailable ? "Connect Dropbox to save recordings to the cloud" : undefined}
          >
            <Cloud className="h-3.5 w-3.5" />
            Dropbox
          </Button>
        </div>
        <p className="text-[10px] text-muted-foreground">
          {recordingDestination === "local"
            ? "Recording will download to this device when you stop."
            : livestream.dropboxRecordingAvailable
              ? "Start recording in the meeting — the file is saved to your Dropbox folder (VDO may also keep a copy in your browser downloads)."
              : "Connect Dropbox in settings to save recordings to the cloud."}
        </p>
        {!livestream.dropboxRecordingAvailable && (
          <Link
            href="/livestreams/supporter/recordings"
            className="inline-flex items-center gap-1.5 text-xs font-medium text-primary hover:underline"
          >
            <Cloud className="h-3.5 w-3.5" />
            Connect Dropbox
          </Link>
        )}
      </div>
      <div className="rounded-lg border border-border bg-muted/30 p-3.5 space-y-2">
        <div className="flex items-center justify-between gap-2">
          <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
            <Radio className="h-3.5 w-3.5 text-primary" />
            Visibility
          </div>
          <Switch
            checked={livestream.isPublic ?? true}
            onCheckedChange={toggleVisibility}
            disabled={isUpdatingVisibility}
          />
        </div>
        <p className="text-xs text-muted-foreground">
          {livestream.isPublic
            ? "Public — listed on Unity Live when live."
            : "Private — only people with the viewer link can watch."}
        </p>
      </div>
      <p className="text-[11px] text-muted-foreground px-0.5">
        Guest and viewer links are in the <button type="button" className="font-medium text-primary hover:underline" onClick={openSharePanel}>Share</button> tab.
      </p>
      <div className="w-full min-w-0 rounded-lg border border-border bg-muted/30 p-3.5 space-y-2">
        <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
          <Key className="h-3.5 w-3.5 text-primary" />
          Meeting ID
        </div>
        <Input value={livestream.roomName} readOnly className="font-mono text-sm h-9 w-full bg-background/80" />
        <Button variant="outline" size="sm" className="w-full h-9 gap-2" onClick={() => copyToClipboard(livestream.roomName, "room")}>
          {copied === "room" ? <CheckCircle2 className="h-4 w-4 text-green-600" /> : <Copy className="h-4 w-4" />}
          Copy
        </Button>
      </div>
      {livestream.requiresPasscode ? (
        <div className="rounded-lg border border-border bg-muted/30 p-3.5 space-y-2">
          <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
            <Key className="h-3.5 w-3.5 text-primary" />
            Passcode
          </div>
          <Input value={livestream.roomPassword} readOnly className="font-mono text-sm h-9 w-full bg-background/80" />
          <Button variant="outline" size="sm" className="w-full h-9 gap-2" onClick={() => copyToClipboard(livestream.roomPassword, "password")}>
            {copied === "password" ? <CheckCircle2 className="h-4 w-4 text-green-600" /> : <Copy className="h-4 w-4" />}
            Copy
          </Button>
        </div>
      ) : null}
    </div>
  )

  const unityWatchUrl =
    livestream.isPublic && unityLiveUrl
      ? unityLiveUrl
      : liveViewerUrl || unityLiveUrl || ""

  const renderShareLinkCard = (
    copyKey: string,
    title: string,
    description: string,
    url: string,
    pendingHint?: string,
  ) => (
    <div className="rounded-lg border border-border bg-muted/30 p-3.5 space-y-2">
      <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
        <ExternalLink className="h-3.5 w-3.5 text-primary" />
        {title}
      </div>
      <p className="text-xs text-muted-foreground">{description}</p>
      {pendingHint ? <p className="text-[11px] text-amber-700 dark:text-amber-300">{pendingHint}</p> : null}
      <div className="flex gap-2">
        <Input readOnly value={url} className="font-mono text-xs h-9 bg-muted/50 min-w-0" />
        <Button
          variant="outline"
          size="icon"
          className="h-9 w-9 shrink-0"
          onClick={() => copyToClipboard(url, copyKey)}
          aria-label={`Copy ${title}`}
        >
          {copied === copyKey ? <CheckCircle2 className="h-4 w-4 text-green-600" /> : <Copy className="h-4 w-4" />}
        </Button>
      </div>
    </div>
  )

  const removeParticipant = (email: string) => {
    setRemovingParticipantEmail(email)
    router.delete(route("livestreams.supporter.participants.remove", livestream.id), {
      data: { email },
      preserveScroll: true,
      onFinish: () => setRemovingParticipantEmail(null),
    })
  }

  const inviteUsesEmailCredits = inviteNotifyVia === "email" || inviteNotifyVia === "both"
  const canSendInvite = inviteNotifyVia === "biu" || canSendEmailInvites

  const sendInvitation = (email: string, resend = false) => {
    if (resend) {
      setResendingEmail(email)
    } else {
      setInvitingParticipant(true)
    }

    router.post(
      route("livestreams.supporter.participants.invite", livestream.id),
      { email, resend: resend || undefined, notify_via: inviteNotifyVia },
      {
        preserveScroll: true,
        onStart: () => {
          if (inviteUsesEmailCredits) {
            applyDelta(1)
          }
        },
        onSuccess: (page) => {
          const inviteError = (page.props as { errors?: { email?: string | string[] } }).errors?.email
          if (inviteError) {
            applyDelta(-1)
            return
          }
          syncFromServer((page.props as Props).emailCredits)
        },
        onError: () => {
          if (inviteUsesEmailCredits) {
            applyDelta(-1)
          }
        },
        onFinish: () => {
          setInvitingParticipant(false)
          setResendingEmail(null)
          if (!resend) {
            setInviteEmailInput("")
          }
        },
      }
    )
  }

  const addAndSendInvitation = () => {
    const raw = inviteEmailInput.trim()
    if (!raw) {
      return
    }
    const email = raw.split(/[,\s]+/).map((part) => part.trim()).find(Boolean)
    if (!email) {
      return
    }
    sendInvitation(email)
  }

  const participantsContent = (
    <div className="w-full min-w-0 space-y-4">
      <p className="text-xs text-muted-foreground">
        Invite guests by searching supporters, typing any email, or BIU notification (app + push).
      </p>

      {showInviteComposer ? (
        <div className="rounded-lg border border-border bg-muted/30 p-3.5 space-y-3">
          {emailCreditsLive && inviteUsesEmailCredits ? (
            <EmailCreditsMeetingActions
              emailsLeft={emailCreditsLive.emails_left}
              onBuy={() => setBuyCreditsOpen(true)}
            />
          ) : null}
          <UnityMeetInviteChannelPicker value={inviteNotifyVia} onChange={setInviteNotifyVia} />
          {inviteUsesEmailCredits && !canSendEmailInvites ? (
            <p className="text-xs text-amber-700 dark:text-amber-300">
              No email credits remaining.{" "}
              <button
                type="button"
                className="font-medium underline hover:no-underline"
                onClick={() => setBuyCreditsOpen(true)}
              >
                Buy email credits
              </button>{" "}
              or choose BIU notification only.
            </p>
          ) : null}
          {canSendInvite ? (
            <UnityMeetInviteRecipientField
              inputId="participant-invite-email"
              value={inviteEmailInput}
              onChange={setInviteEmailInput}
              onSubmit={addAndSendInvitation}
              disabled={invitingParticipant}
              submitting={invitingParticipant}
              error={participantInviteErrorText}
              stackActions
            />
          ) : null}
        </div>
      ) : null}

      {livestream.scheduledAt ? (
        <div className="rounded-lg border border-border bg-muted/30 p-3.5 space-y-1">
          <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
            <Calendar className="h-3.5 w-3.5 text-primary" />
            Scheduled for
          </div>
          <p className="text-sm text-foreground">
            {new Date(livestream.scheduledAt).toLocaleString(undefined, {
              weekday: "long",
              month: "long",
              day: "numeric",
              year: "numeric",
              hour: "numeric",
              minute: "2-digit",
            })}
          </p>
        </div>
      ) : null}

      {invitedCount > 0 ? (
        <div className="rounded-lg border border-border bg-muted/30 p-3.5 space-y-2">
          <div className="flex items-center justify-between gap-2">
            <div className="flex items-center gap-2 text-xs font-medium uppercase tracking-wider text-muted-foreground">
              <Users className="h-3.5 w-3.5 text-primary" />
              Invited ({invitedCount})
            </div>
          </div>
          <ul className="space-y-2 max-h-64 overflow-y-auto">
            {livestream.participantEmails!.map((email) => (
              <li
                key={email}
                className="flex items-center gap-2 rounded-md border border-border/60 bg-background/80 px-2 py-1.5 text-foreground"
              >
                <Mail className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                <span className="min-w-0 flex-1 truncate text-sm">{email}</span>
                <div className="flex shrink-0 items-center gap-0.5">
                  {canInviteParticipants && canSendInvite ? (
                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="h-7 w-7 text-muted-foreground hover:text-primary"
                      onClick={() => sendInvitation(email, true)}
                      disabled={resendingEmail === email}
                      aria-label={`Resend invitation to ${email}`}
                      title="Resend invitation"
                    >
                      {resendingEmail === email ? (
                        <span className="text-[10px] font-medium">…</span>
                      ) : (
                        <Send className="h-3.5 w-3.5" />
                      )}
                    </Button>
                  ) : null}
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-7 w-7 text-muted-foreground hover:text-destructive"
                    onClick={() => removeParticipant(email)}
                    disabled={removingParticipantEmail === email}
                    aria-label={`Remove ${email}`}
                    title="Remove participant"
                  >
                    {removingParticipantEmail === email ? (
                      <span className="text-[10px] font-medium">…</span>
                    ) : (
                      <X className="h-3.5 w-3.5" />
                    )}
                  </Button>
                </div>
              </li>
            ))}
          </ul>
          <p className="text-[10px] text-muted-foreground">
            Removing a guest takes them off the invite list. They can still join with the meeting link if they have it.
          </p>
        </div>
      ) : (
        <div className="rounded-lg border border-dashed border-border bg-muted/20 p-6 text-center space-y-2">
          <Users className="h-8 w-8 mx-auto text-muted-foreground/60" />
          <p className="text-sm font-medium text-foreground">No invited participants</p>
          <p className="text-xs text-muted-foreground">
            Search a supporter or type an email above, or share the invite link from the Share tab.
          </p>
        </div>
      )}
    </div>
  )

  const shareContent = (
    <div className="w-full min-w-0 space-y-4">
      <p className="text-xs text-muted-foreground">
        Share links with guests and viewers. Guests join the meeting; viewers watch on Unity Live or YouTube after you go live.
      </p>

      {joinUrl
        ? renderShareLinkCard(
            "invite",
            "Invite guests",
            "Guests join the meeting room — no meeting ID or passcode needed.",
            joinUrl,
          )
        : null}

      {livestream.wantsUnityLive && unityWatchUrl
        ? renderShareLinkCard(
            "unity-watch",
            livestream.isPublic ? "Watch on Unity Live" : "Private viewer link",
            livestream.isPublic
              ? "Anyone with this link can watch on the Unity Live page (listed when you go live)."
              : "Share with people who should watch — not listed publicly.",
            unityWatchUrl,
            livestream.status !== "live"
              ? "Available to viewers after you click Go Unity Live."
              : undefined,
          )
        : null}

      {livestream.wantsYoutubeLive && (livestream.youtubeWatchUrl ?? "").trim()
        ? renderShareLinkCard(
            "youtube-watch",
            "Watch on YouTube",
            "Share so viewers can watch on your YouTube channel.",
            (livestream.youtubeWatchUrl ?? "").trim(),
            livestream.status !== "live" && !streamRelayInProgress
              ? "The stream appears on YouTube after you click Go YouTube Live."
              : undefined,
          )
        : null}

      {livestream.wantsYoutubeLive && !(livestream.youtubeWatchUrl ?? "").trim() && livestream.youtubeChannelUrl
        ? renderShareLinkCard(
            "youtube-channel",
            "YouTube channel",
            "Prepare YouTube live first, or share your channel until the watch link is ready.",
            livestream.youtubeChannelUrl,
          )
        : null}
    </div>
  )

  return (
    <UnityMeetLayout
      breadcrumbs={[
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Unity Meet Communications', href: '/livestreams/supporter' },
        { title: livestream.title || 'Meeting', href: `/livestreams/supporter/${livestream.id}` },
      ]}
    >
      <PageHead title={livestream.title || "Meeting"} description="Host your Unity Meet" />
      <Head title={livestream.title || "Meeting"} />
      <div className="flex min-h-0 w-full min-w-0 max-w-full flex-col overflow-hidden">
        <div className="flex h-[calc(100dvh-4rem)] sm:h-[calc(100vh-4rem)] flex-col w-full min-w-0 overflow-hidden">
          <div className="flex shrink-0 items-center justify-between gap-2 border-b border-border px-3 py-2 sm:px-4 sm:py-3">
            <div className="flex min-w-0 flex-1 items-center gap-1.5 sm:gap-4">
              <Link href="/livestreams/supporter" className="shrink-0 text-muted-foreground hover:text-foreground p-1.5 -m-1.5 rounded touch-manipulation" aria-label="Back">
                <ArrowLeft className="h-4 w-4 sm:h-5 sm:w-5" />
              </Link>
              <h1 className="truncate text-sm font-semibold text-foreground sm:text-lg">{livestream.title || "Meeting"}</h1>
              <span className="shrink-0">{getStatusBadge()}</span>
            </div>
            <div className="flex shrink-0 items-center gap-1 sm:gap-2">
              <div className="flex items-center gap-0.5 md:hidden">
                <Sheet open={infoOpen} onOpenChange={setInfoOpen}>
                  <SheetTrigger asChild>
                    <Button variant="ghost" size="icon" className="h-8 w-8 touch-manipulation" aria-label="Meeting info">
                      <Info className="h-4 w-4" />
                    </Button>
                  </SheetTrigger>
                  <SheetContent side="left" className="w-[90vw] max-w-sm overflow-y-auto">
                    <SheetHeader className="pb-4 border-b border-border">
                      <SheetTitle className="text-lg">Meeting</SheetTitle>
                    </SheetHeader>
                    <Tabs value={sidebarTab} onValueChange={(v) => setSidebarTab(v as SidebarTab)} className="w-full mt-4">
                      <TabsList className={`grid w-full h-8 p-0.5 gap-0.5 ${showInviteComposer ? "grid-cols-3" : "grid-cols-2"}`}>
                        <TabsTrigger value="meeting-info" className="h-7 px-1 py-0" aria-label="Meeting info" title="Meeting info">
                          <Info className="h-3.5 w-3.5 shrink-0" />
                        </TabsTrigger>
                        {showInviteComposer ? (
                          <TabsTrigger value="participants" className="h-7 px-1 py-0" aria-label="Participants" title="Participants">
                            <Users className="h-3.5 w-3.5 shrink-0" />
                          </TabsTrigger>
                        ) : null}
                        <TabsTrigger value="share" className="h-7 px-1 py-0" aria-label="Share" title="Share">
                          <Share2 className="h-3.5 w-3.5 shrink-0" />
                        </TabsTrigger>
                      </TabsList>
                      <TabsContent value="meeting-info" className="mt-4">
                        {meetingInfoContent}
                      </TabsContent>
                      {showInviteComposer ? (
                        <TabsContent value="participants" className="mt-4">
                          {participantsContent}
                        </TabsContent>
                      ) : null}
                      <TabsContent value="share" className="mt-4">
                        {shareContent}
                      </TabsContent>
                    </Tabs>
                  </SheetContent>
                </Sheet>
                {isMeetingActive ? (
                  <Sheet open={participantsMobileOpen} onOpenChange={setParticipantsMobileOpen}>
                    <SheetTrigger asChild>
                      <Button variant="ghost" size="icon" className="h-8 w-8 touch-manipulation lg:hidden" aria-label="Participants">
                        <Users className="h-4 w-4" />
                      </Button>
                    </SheetTrigger>
                    <SheetContent side="right" className="flex w-[90vw] max-w-sm flex-col overflow-hidden p-0">
                      <UnityMeetParticipantPanel
                        className="min-h-0 flex-1 border-0"
                        participants={participantRoster}
                        authUserId={authUserId}
                        onGiveGift={openGiftDialog}
                        onInvite={showInviteComposer ? openParticipantsInvite : undefined}
                        onCopyViewerLink={() => copyToClipboard(liveViewerUrl, "viewer")}
                        onClose={() => setParticipantsMobileOpen(false)}
                      />
                    </SheetContent>
                  </Sheet>
                ) : null}
                {showUnityLiveButton && (
                  <Button
                    variant="default"
                    size="icon"
                    className="h-8 w-8 rounded-md bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 touch-manipulation"
                    onClick={goUnityLive}
                    disabled={liveActionDisabled}
                    aria-label="Go Unity Live"
                  >
                    <Globe className="h-4 w-4" />
                  </Button>
                )}
                {showYoutubeLiveButton && showYoutubeLiveButtonInTopBar && (
                  <Button
                    variant="default"
                    size="icon"
                    className="h-8 w-8 rounded-md bg-red-600 hover:bg-red-700 touch-manipulation"
                    onClick={handleGoLiveClick}
                    disabled={liveActionDisabled}
                    aria-label="Go YouTube Live"
                  >
                    <Youtube className="h-4 w-4" />
                  </Button>
                )}
                {showStreamOptionsButton ? (
                  <Button variant="outline" size="sm" className="h-8 px-2.5 rounded-md md:hidden" onClick={() => setGoLiveOpen(true)}>
                    Stream options
                  </Button>
                ) : null}
                {showEndMeetingButton && (
                  <Button
                    variant="destructive"
                    size="sm"
                    className="h-8 shrink-0 px-2.5 touch-manipulation md:hidden"
                    onClick={handleEndMeeting}
                    disabled={liveActionDisabled}
                    aria-label="End meeting"
                  >
                    <PhoneOff className="h-4 w-4 shrink-0" />
                    <span className="ml-1.5">{isEndingMeeting ? "Ending…" : "End"}</span>
                  </Button>
                )}
                <DropdownMenu>
                  <DropdownMenuTrigger asChild>
                    <Button variant="ghost" size="icon" className="h-8 w-8 touch-manipulation" aria-label="More actions">
                      <MoreVertical className="h-4 w-4" />
                    </Button>
                  </DropdownMenuTrigger>
                  <DropdownMenuContent align="end" side="bottom" className="w-48">
                    {showUnityLiveButton && (
                      <DropdownMenuItem onClick={goUnityLive} disabled={liveActionDisabled}>
                        <Globe className="h-4 w-4 mr-2" />
                        Go Unity Live
                      </DropdownMenuItem>
                    )}
                    {showYoutubeLiveButton && (
                      <DropdownMenuItem onClick={handleGoLiveClick} disabled={liveActionDisabled}>
                        <Youtube className="h-4 w-4 mr-2" />
                        Go YouTube Live
                      </DropdownMenuItem>
                    )}
                    {showStreamOptionsButton ? (
                      <DropdownMenuItem onClick={() => setGoLiveOpen(true)}>
                        Stream options
                      </DropdownMenuItem>
                    ) : null}
                    {showEndYoutubeLiveButton && (
                      <DropdownMenuItem variant="destructive" onClick={handleEndStream} disabled={liveActionDisabled}>
                        <Square className="h-4 w-4 mr-2" />
                        {isEndingStreamPending ? "Ending…" : "End YouTube Live"}
                      </DropdownMenuItem>
                    )}
                    {showEndUnityLiveButton && (
                      <DropdownMenuItem variant="destructive" onClick={handleEndUnityLive} disabled={liveActionDisabled}>
                        <Square className="h-4 w-4 mr-2" />
                        {isUpdatingStatus ? "Ending…" : "End Unity Live"}
                      </DropdownMenuItem>
                    )}
                  </DropdownMenuContent>
                </DropdownMenu>
              </div>
              <div className="hidden md:flex items-center gap-1.5">
                {showUnityLiveButton && (
                  <Button
                    size="sm"
                    className="h-8 min-w-[8.5rem] bg-gradient-to-r from-purple-600 to-blue-600 px-3 text-white hover:from-purple-700 hover:to-blue-700"
                    onClick={goUnityLive}
                    disabled={liveActionDisabled}
                  >
                    <Globe className="h-4 w-4 mr-1.5" />
                    Go Unity Live
                  </Button>
                )}
                {showYoutubeLiveButton && showYoutubeLiveButtonInTopBar && (
                  <Button
                    size="sm"
                    className="h-8 min-w-[9.5rem] bg-red-600 px-3 hover:bg-red-700"
                    onClick={handleGoLiveClick}
                    disabled={liveActionDisabled}
                  >
                    <Youtube className="h-4 w-4 mr-1.5" />
                    Go YouTube Live
                  </Button>
                )}
                {showStreamOptionsButton ? (
                  <Button variant="outline" size="sm" className="h-8 px-3" onClick={() => setGoLiveOpen(true)}>
                    Stream options
                  </Button>
                ) : null}
                {canStartMeeting && (
                  <Button
                    size="sm"
                    className="h-8 min-w-[8.5rem] bg-gradient-to-r from-purple-600 to-blue-600 px-3 text-white hover:from-purple-700 hover:to-blue-700"
                    onClick={handleStartMeeting}
                    disabled={liveActionDisabled}
                  >
                    <Play className="h-4 w-4 mr-1.5" />
                    {isStartingMeeting ? "Starting…" : "Start meeting"}
                  </Button>
                )}
                {showEndMeetingButton && (
                  <Button variant="destructive" size="sm" className="h-8 px-3" onClick={handleEndMeeting} disabled={liveActionDisabled}>
                    <PhoneOff className="h-4 w-4 mr-1.5" />
                    {isEndingMeeting ? "Ending…" : "End meeting"}
                  </Button>
                )}
                {showEndYoutubeLiveButton && (
                  <Button variant="destructive" size="sm" className="h-8 px-3" onClick={handleEndStream} disabled={liveActionDisabled}>
                    <Square className="h-4 w-4 mr-1.5" />
                    {isEndingStreamPending ? "Ending…" : "End YouTube Live"}
                  </Button>
                )}
                {showEndUnityLiveButton && (
                  <Button variant="destructive" size="sm" className="h-8 px-3" onClick={handleEndUnityLive} disabled={liveActionDisabled}>
                    <Square className="h-4 w-4 mr-1.5" />
                    {isUpdatingStatus ? "Ending…" : "End Unity Live"}
                  </Button>
                )}
              </div>
            </div>
          </div>

          {isEndingStreamPending && canEndYoutubeLiveNow && (
            <div className="shrink-0 border-b border-blue-500/30 bg-gradient-to-r from-purple-500/10 to-blue-500/10 px-3 py-2 sm:px-4">
              <p className="text-xs font-medium text-foreground">Ending YouTube live</p>
              <p className="text-[11px] text-muted-foreground">
                YouTube was told to stop. This page refreshes until the stream has fully ended, then you can go live again.
              </p>
            </div>
          )}

          {recordingConsentDeclines.length > 0 && (
            <div className="shrink-0 border-b border-amber-500/35 bg-amber-500/10 px-3 py-2 sm:px-4">
              <p className="text-xs font-semibold text-amber-900 dark:text-amber-100">Someone declined recording consent</p>
              <p className="text-[11px] text-muted-foreground truncate">
                {recordingConsentDeclines
                  .slice(0, 4)
                  .map((r) => (r.guestLabel ?? "").trim() || "Guest")
                  .join(" · ")}
                {recordingConsentDeclines.length > 4 ? ` · +${recordingConsentDeclines.length - 4} more` : ""}
              </p>
              <p className="text-[10px] text-muted-foreground mt-0.5">Full list refreshes periodically under Meeting info.</p>
            </div>
          )}

          <div className="flex flex-1 min-h-0 overflow-hidden">
            <aside className="hidden md:flex w-64 lg:w-72 shrink-0 min-h-0 flex-col overflow-hidden border-r border-border bg-linear-to-b from-muted/30 to-muted/10 p-0">
              <Tabs value={sidebarTab} onValueChange={(v) => setSidebarTab(v as SidebarTab)} className="w-full flex flex-col min-h-0">
                <Card className="flex min-h-0 flex-1 flex-col rounded-none border-0 border-b border-border bg-transparent p-0 shadow-none">
                  <CardHeader className="p-0 py-2 px-3">
                    <TabsList className={`grid w-full h-8 p-0.5 gap-0.5 ${showInviteComposer ? "grid-cols-3" : "grid-cols-2"}`}>
                      <TabsTrigger value="meeting-info" className="h-7 px-1 py-0" aria-label="Meeting info" title="Meeting info">
                        <Info className="h-3.5 w-3.5 shrink-0" />
                      </TabsTrigger>
                      {showInviteComposer ? (
                        <TabsTrigger value="participants" className="h-7 px-1 py-0" aria-label="Participants" title="Participants">
                          <Users className="h-3.5 w-3.5 shrink-0" />
                        </TabsTrigger>
                      ) : null}
                      <TabsTrigger value="share" className="h-7 px-1 py-0" aria-label="Share" title="Share">
                        <Share2 className="h-3.5 w-3.5 shrink-0" />
                      </TabsTrigger>
                    </TabsList>
                  </CardHeader>
                  <CardContent className="min-h-0 flex-1 overflow-y-auto p-0 px-3 pb-3 [scrollbar-width:thin] [scrollbar-color:rgb(147_51_234_/_0.45)_transparent] [&::-webkit-scrollbar]:w-2 [&::-webkit-scrollbar-track]:bg-transparent [&::-webkit-scrollbar-thumb]:rounded-full [&::-webkit-scrollbar-thumb]:bg-gradient-to-b [&::-webkit-scrollbar-thumb]:from-purple-400/70 [&::-webkit-scrollbar-thumb]:to-blue-500/70 dark:[&::-webkit-scrollbar-thumb]:from-purple-500/70 dark:[&::-webkit-scrollbar-thumb]:to-blue-600/70 [&::-webkit-scrollbar-thumb:hover]:from-purple-500/90 [&::-webkit-scrollbar-thumb:hover]:to-blue-600/90">
                    <TabsContent value="meeting-info" className="mt-3 mb-0">
                      {meetingInfoContent}
                    </TabsContent>
                    {showInviteComposer ? (
                      <TabsContent value="participants" className="mt-3 mb-0">
                        {participantsContent}
                      </TabsContent>
                    ) : null}
                    <TabsContent value="share" className="mt-3 mb-0">
                      {shareContent}
                    </TabsContent>
                  </CardContent>
                </Card>
              </Tabs>
            </aside>

            <div className="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
              <div className="relative isolate flex min-h-0 min-w-0 flex-1 overflow-hidden bg-black">
                {showVdoVideo ? (
                  <VdoMeetingIframe
                    key={`host-${livestream.meetingSessionKey ?? 0}`}
                    src={vdoHostIframeSrc!}
                    title="Host"
                    active={showVdoVideo}
                  />
                ) : (
                  <div className="absolute inset-0 z-[1] flex flex-col items-center justify-center gap-4 bg-gradient-to-b from-zinc-950 to-black px-6 text-center">
                    <div className="space-y-2 max-w-md">
                      <p className="text-lg font-semibold text-white">
                        {isEndingMeeting
                          ? "Ending meeting…"
                          : canStartMeeting
                            ? "Ready to meet"
                            : "Meeting closed"}
                      </p>
                      <p className="text-sm text-white/70">
                        {isEndingMeeting
                          ? "Closing the video room and saving your session."
                          : canStartMeeting
                            ? "Start the meeting to connect your camera and microphone. The video room stays closed until you begin."
                            : "This session has ended. Start a new meeting when you are ready to go live again."}
                      </p>
                    </div>
                    {canStartMeeting ? (
                      <Button
                        type="button"
                        size="lg"
                        className="bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-700 hover:to-blue-700"
                        onClick={handleStartMeeting}
                        disabled={isStartingMeeting}
                      >
                        <Play className="mr-2 h-5 w-5" />
                        {isStartingMeeting ? "Starting…" : "Start meeting"}
                      </Button>
                    ) : null}
                  </div>
                )}
                {livestream.status === "live" && (
                  <div className="pointer-events-none absolute top-2 left-2 z-[2] sm:top-3 sm:left-3 rounded bg-red-600 px-2 py-0.5 text-xs font-semibold text-white animate-pulse sm:px-3 sm:py-1 sm:text-sm">
                    ● LIVE
                  </div>
                )}
                {showVdoVideo && livestream.canvasMode && livestream.browserMediaMtxPush && livestream.canvasUrl && (
                  <iframe
                    src={livestream.canvasUrl}
                    title="canvas-mixer"
                    tabIndex={-1}
                    aria-hidden="true"
                    className="pointer-events-none absolute h-px w-px overflow-hidden border-0 opacity-0"
                    style={{ left: "-9999px", top: "-9999px" }}
                    allow="autoplay; clipboard-write"
                  />
                )}
              </div>
            </div>

            {isMeetingActive && participantsPanelOpen ? (
              <UnityMeetParticipantPanel
                className="hidden lg:flex w-72 xl:w-80 shrink-0 min-h-0 border-l"
                participants={participantRoster}
                authUserId={authUserId}
                onGiveGift={openGiftDialog}
                onInvite={showInviteComposer ? openParticipantsInvite : undefined}
                onCopyViewerLink={() => copyToClipboard(liveViewerUrl, "viewer")}
                onClose={() => setParticipantsPanelOpen(false)}
              />
            ) : null}
            {isMeetingActive && !participantsPanelOpen ? (
              <div className="hidden lg:flex w-10 shrink-0 items-start justify-center border-l border-border bg-muted/20 pt-3">
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8"
                  onClick={() => setParticipantsPanelOpen(true)}
                  aria-label="Show participants"
                  title="Show participants"
                >
                  <Users className="h-4 w-4" />
                </Button>
              </div>
            ) : null}
          </div>
        </div>
      </div>

      <Dialog open={goLiveOpen} onOpenChange={setGoLiveOpen}>
        <DialogContent className="max-w-[calc(100vw-2rem)] sm:max-w-lg max-h-[90dvh] sm:max-h-[90vh] overflow-y-auto p-4 sm:p-6">
          <DialogHeader>
            <DialogTitle>Go Live</DialogTitle>
          </DialogHeader>
          <Tabs value={goLiveTab} onValueChange={setGoLiveTab} className="w-full">
            <TabsList className="grid w-full grid-cols-3 gap-1">
              <TabsTrigger value="streaming" className="text-xs sm:text-sm">Streaming</TabsTrigger>
              <TabsTrigger value="settings" className="text-xs sm:text-sm">Settings</TabsTrigger>
              <TabsTrigger value="help" className="text-xs sm:text-sm">Help</TabsTrigger>
            </TabsList>
            <TabsContent value="streaming" className="space-y-4 mt-4">
              {(showUnityLiveButton || showYoutubeLiveButton) && (
                <p className="text-sm text-muted-foreground">
                  Use the buttons below for the destinations you chose when starting this meeting.
                </p>
              )}
              {queueStreamErrorText && (
                <Alert variant="destructive"><AlertDescription>{queueStreamErrorText}</AlertDescription></Alert>
              )}
              {endStreamErrorText && (
                <Alert variant="destructive"><AlertDescription>{endStreamErrorText}</AlertDescription></Alert>
              )}
              {showUnityLiveButton && (
                <Button
                  className="w-full bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-700 hover:to-blue-700"
                  onClick={goUnityLive}
                  disabled={liveActionDisabled}
                >
                  <Globe className="h-4 w-4 mr-2" />
                  Go Unity Live
                </Button>
              )}
              {showYoutubeLiveButton && livestream.hasStreamKey && (
                <Button
                  className="w-full bg-red-600 hover:bg-red-700"
                  onClick={handleGoLiveClick}
                  disabled={liveActionDisabled}
                >
                  <Youtube className="h-4 w-4 mr-2" />
                  Go YouTube Live
                </Button>
              )}
              {showYoutubeLiveButton && !livestream.hasStreamKey && (
                <p className="text-sm text-muted-foreground">
                  Add a YouTube stream key in Settings, or use Create live on YouTube, then try Go YouTube Live.
                </p>
              )}
              {streamRelayInProgress && (
                <p className="text-sm text-muted-foreground">
                  Your YouTube stream is starting. Watch <strong className="text-foreground">Stream status</strong> in Meeting info.
                </p>
              )}
              {!showUnityLiveButton && !showYoutubeLiveButton && !streamRelayInProgress && (
                <p className="text-sm text-muted-foreground">
                  This meeting was not set up for live streaming. Start a new instant meeting and turn on Show on Unity Live and/or Go live (optional).
                </p>
              )}
              {showEndYoutubeLiveButton && (
                <Button variant="destructive" className="w-full" onClick={handleEndStream} disabled={liveActionDisabled}>
                  <Square className="h-4 w-4 mr-2" /> {isEndingStreamPending ? "Ending…" : "End YouTube Live"}
                </Button>
              )}
              {showEndUnityLiveButton && (
                <Button variant="destructive" className="w-full" onClick={handleEndUnityLive} disabled={liveActionDisabled}>
                  <Square className="h-4 w-4 mr-2" /> {isUpdatingStatus ? "Ending…" : "End Unity Live"}
                </Button>
              )}
            </TabsContent>
            <TabsContent value="settings" className="mt-4 space-y-4">
              <CardTitle className="text-sm flex items-center gap-2"><Key className="h-4 w-4" /> YouTube Stream Key</CardTitle>
              <p className="text-sm text-muted-foreground">Paste your stream key from YouTube Studio (Create → Go live → Stream key).</p>
              <form onSubmit={updateStreamKey} className="space-y-3">
                <Input
                  type="password"
                  value={streamKey}
                  onChange={(e) => setStreamKey(e.target.value)}
                  placeholder="Paste your YouTube stream key"
                  className="font-mono text-sm"
                />
                <Button type="submit" disabled={isUpdatingStreamKey || !streamKey}>
                  {isUpdatingStreamKey ? "Saving…" : livestream.hasStreamKey ? "Update Stream Key" : "Add Stream Key"}
                </Button>
              </form>
            </TabsContent>
            <TabsContent value="help" className="mt-4 space-y-4">
              <div className="flex items-center gap-2 text-foreground font-semibold">
                <HelpCircle className="h-5 w-5 text-primary" />
                How streaming works
              </div>
              <p className="text-sm text-muted-foreground">
                Unity Meet sends your meeting to YouTube when you click Go Live.
              </p>
              <div className="rounded-lg border border-border bg-muted/30 p-4 space-y-3">
                <div className="flex items-start gap-3">
                  <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                    <Youtube className="h-4 w-4 text-red-600" />
                  </div>
                  <div>
                    <h4 className="text-sm font-semibold text-foreground">1. YouTube stream key</h4>
                    <ul className="mt-1.5 list-inside list-disc space-y-1 text-sm text-muted-foreground">
                      <li>Connect your channel in Integrations, then use <strong className="text-foreground">Create live on YouTube</strong> on this page, or paste a key in <strong className="text-foreground">Settings</strong>.</li>
                    </ul>
                  </div>
                </div>
              </div>
              <div className="rounded-lg border border-border bg-muted/30 p-4 space-y-3">
                <div className="flex items-start gap-3">
                  <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10">
                    <Cloud className="h-4 w-4 text-primary" />
                  </div>
                  <div>
                    <h4 className="text-sm font-semibold text-foreground">2. Host here, then Go Live</h4>
                    <ul className="mt-1.5 list-inside list-disc space-y-1 text-sm text-muted-foreground">
                      <li>Open this meeting as host (iframe above).</li>
                      <li>Click <strong className="text-foreground">Go Live</strong> to start streaming to YouTube.</li>
                      <li>Watch <strong className="text-foreground">Stream status</strong> in Meeting info; it moves from Pending to Live.</li>
                    </ul>
                  </div>
                </div>
              </div>
            </TabsContent>
          </Tabs>
        </DialogContent>
      </Dialog>

      <GoLiveConfirmDialog
        open={goLiveConfirmOpen}
        onOpenChange={setGoLiveConfirmOpen}
        onConfirm={handleGoLiveConfirm}
        isConfirming={isGoLivePending}
      />

      <Dialog open={goLivePrecheckOpen} onOpenChange={setGoLivePrecheckOpen}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle>
              {livestream.youtubeConnected ? "Prepare YouTube Live" : "Add a stream key"}
            </DialogTitle>
          </DialogHeader>
          <div className="space-y-4 text-sm">
            {prepareYoutubeErrorText && (
              <p className="text-sm text-destructive">{prepareYoutubeErrorText}</p>
            )}
            {livestream.youtubeConnected ? (
              <>
                <p className="text-muted-foreground">
                  Your YouTube channel is connected to Believe. Create the live broadcast on that channel — we&apos;ll save the stream key automatically.
                </p>
                <div className="flex flex-col sm:flex-row gap-2 sm:justify-end">
                  <Button
                    variant="outline"
                    disabled={isPrepareYoutubeLive}
                    onClick={() => {
                      setGoLivePrecheckOpen(false)
                      setGoLiveTab("settings")
                      setGoLiveOpen(true)
                    }}
                  >
                    Paste key manually
                  </Button>
                  <Button
                    disabled={isPrepareYoutubeLive}
                    onClick={() => {
                      setIsPrepareYoutubeLive(true)
                      router.post(
                        `/livestreams/supporter/${livestream.id}/prepare-youtube-live`,
                        {},
                        {
                          preserveScroll: true,
                          onSuccess: () => setGoLivePrecheckOpen(false),
                          onFinish: () => setIsPrepareYoutubeLive(false),
                        }
                      )
                    }}
                  >
                    {isPrepareYoutubeLive ? "Creating…" : "Create live on YouTube"}
                  </Button>
                </div>
              </>
            ) : (
              <>
                <p className="text-muted-foreground">
                  Connect YouTube under Integrations, or create a stream in Studio and paste the stream key below.
                </p>
                <ol className="list-decimal list-inside space-y-1 text-muted-foreground">
                  <li>(Optional) Open YouTube Studio and create a live stream.</li>
                  <li>Copy the stream key.</li>
                  <li>Paste it in Settings on this page, then try Go Live again.</li>
                </ol>
                <div className="flex flex-col sm:flex-row gap-2 sm:justify-end">
                  <Button
                    variant="outline"
                    onClick={() => window.open("https://studio.youtube.com", "_blank", "noopener,noreferrer")}
                  >
                    Open YouTube Studio
                  </Button>
                  <Button
                    onClick={() => {
                      setGoLivePrecheckOpen(false)
                      setGoLiveTab("settings")
                      setGoLiveOpen(true)
                    }}
                  >
                    Add Stream Key
                  </Button>
                </div>
              </>
            )}
          </div>
        </DialogContent>
      </Dialog>

      <BuyEmailCreditsDialog
        open={buyCreditsOpen}
        onOpenChange={setBuyCreditsOpen}
        emailPackages={emailPackages}
        stripeMinCheckoutUsd={stripeMinCheckoutUsd}
        returnRoute="livestreams.supporter.show"
        returnId={livestream.id}
      />

      <UnityMeetGiftDialog
        open={giftDialogOpen}
        onOpenChange={setGiftDialogOpen}
        recipient={giftRecipient}
        giftOccasions={giftOccasions}
        senderBalances={senderGiftBalances}
        livestreamKind="user"
        livestreamId={livestream.id}
        onSent={() => {
          setGiftRecipient(null)
          router.reload({ only: ["senderGiftBalances"], preserveScroll: true })
        }}
      />

      <UnityMeetGiftCelebrationLayer broadcastChannel={broadcastChannel} authUserId={authUserId} />
    </UnityMeetLayout>
  )
}
