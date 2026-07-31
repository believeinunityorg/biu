"use client"

import { useState, useEffect, useMemo } from "react"
import { Head, router } from "@inertiajs/react"
import { Button } from "@/components/ui/button"
import { Badge } from "@/components/ui/badge"
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Alert, AlertDescription } from "@/components/ui/alert"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import AppLayout from "@/layouts/app-layout"
import GoLiveConfirmDialog from "@/components/livestreams/GoLiveConfirmDialog"
import {
  Video,
  Copy,
  ExternalLink,
  Play,
  Square,
  Youtube,
  Users,
  Key,
  Info,
  CheckCircle2,
  AlertCircle,
  Code,
  FileText,
  ArrowLeft,
  Layers,
  Clapperboard,
} from "lucide-react"
import { Link } from "@inertiajs/react"
import { isStreamRelayInProgress } from "@/lib/streamingDisplayStatus"
import { useAutoStopLivestreamOnLeave } from "@/hooks/useAutoStopLivestreamOnLeave"
import { useUnityMeetHostRealtime } from "@/hooks/useUnityMeetHostRealtime"

interface Livestream {
  id: number
  title: string | null
  description: string | null
  roomName: string
  roomPassword: string
  requiresPasscode?: boolean
  directorUrl: string
  hostPushUrl: string
  participantUrl: string
  scenePushUrl?: string | null
  /** Participant-canvas mixer page URL. Loaded in a hidden iframe when canvasMode
   * is on so all participants reach YouTube, not just the host. */
  canvasUrl?: string | null
  /** When true, the meeting runs in multi-participant canvas mode. */
  canvasMode?: boolean
  browserMediaMtxPush?: boolean
  status: "draft" | "scheduled" | "live" | "meeting_live" | "starting" | "ended" | "cancelled"
  scheduledAt: string | null
  startedAt: string | null
  endedAt: string | null
  hasStreamKey: boolean
  youtubeBroadcastId: string | null
  streamingQueueStatus?: {
    status?: string | null
    livestreamStatus?: string
    streamStopRequested?: boolean
    updatedAt?: string | null
    failureReason?: string | null
  } | null
}

interface Organization {
  id: number
  name: string
  youtubeChannelUrl: string | null
}

interface RecordingConsentDecline {
  id: number
  guestLabel: string | null
  createdAt: string | null
}

interface Props {
  livestream: Livestream
  organization: Organization
  recordingConsentDeclines: RecordingConsentDecline[]
  broadcastChannel?: string
}

function formatDeclineTime(iso: string | null): string {
  if (!iso) return "—"
  const d = new Date(iso)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleString()
}

export default function ShowLivestream({
  livestream: initialLivestream,
  organization,
  recordingConsentDeclines: initialRecordingConsentDeclines,
  broadcastChannel,
}: Props) {
  const { livestream, recordingConsentDeclines } = useUnityMeetHostRealtime({
    broadcastChannel,
    livestream: initialLivestream,
    recordingConsentDeclines: initialRecordingConsentDeclines,
  })

  const [copied, setCopied] = useState<string | null>(null)
  const [isUpdatingStatus, setIsUpdatingStatus] = useState(false)
  const [isGoLivePending, setIsGoLivePending] = useState(false)
  const [goLiveConfirmOpen, setGoLiveConfirmOpen] = useState(false)
  const [isEndingStreamPending, setIsEndingStreamPending] = useState(false)
  const [streamKey, setStreamKey] = useState("")
  const [isUpdatingStreamKey, setIsUpdatingStreamKey] = useState(false)

  useAutoStopLivestreamOnLeave({
    livestreamId: livestream.id,
    status: livestream.status,
    stopUrl: route("organization.livestreams.abandon-host-session", livestream.id),
  })

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

  const showGoLiveButton =
    !["live", "meeting_live", "starting"].includes(livestream.status) &&
    !streamRelayInProgress &&
    !isGoLivePending

  useEffect(() => {
    if (isEndingStreamPending && livestream.status !== "live") {
      setIsEndingStreamPending(false)
    }
  }, [livestream.status, isEndingStreamPending])

  const copyToClipboard = (text: string, key: string) => {
    navigator.clipboard.writeText(text)
    setCopied(key)
    setTimeout(() => setCopied(null), 2000)
  }

  const updateStatus = (status: string) => {
    setIsUpdatingStatus(true)
    router.patch(
      `/livestreams/${livestream.id}/status`,
      { status },
      {
        preserveScroll: true,
        onFinish: () => setIsUpdatingStatus(false),
      }
    )
  }

  const queueGoLiveCloud = () => {
    if (isGoLiveBusy) {
      return
    }
    setIsGoLivePending(true)
    router.post(
      `/livestreams/${livestream.id}/queue-stream-relay`,
      {},
      {
        preserveScroll: true,
        onFinish: () => setIsGoLivePending(false),
      }
    )
  }

  const goLiveCloud = () => {
    if (isGoLiveBusy) {
      return
    }
    setGoLiveConfirmOpen(true)
  }

  const endStreamCloud = () => {
    setIsUpdatingStatus(true)
    setIsEndingStreamPending(true)
    router.post(
      `/livestreams/${livestream.id}/end-stream`,
      {},
      {
        preserveScroll: true,
        onFinish: () => setIsUpdatingStatus(false),
      }
    )
  }

  const updateStreamKey = (e: React.FormEvent) => {
    e.preventDefault()
    setIsUpdatingStreamKey(true)
    router.patch(
      `/livestreams/${livestream.id}/stream-key`,
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

  const getStatusBadge = () => {
    const statusConfig = {
      draft: { label: "Draft", className: "bg-gray-500/20 text-gray-400" },
      scheduled: { label: "Scheduled", className: "bg-blue-500/20 text-blue-400" },
      live: { label: "Live", className: "bg-red-500/20 text-red-400 animate-pulse" },
      ended: { label: "Ended", className: "bg-gray-500/20 text-gray-400" },
      cancelled: { label: "Cancelled", className: "bg-red-500/20 text-red-400" },
    }
    const config = statusConfig[livestream.status] || statusConfig.draft
    return <Badge className={config.className}>{config.label}</Badge>
  }

  return (
    <AppLayout>
      <Head title={`Livestream: ${livestream.title || "Untitled"}`} />
      {/* Hidden participant-canvas mixer iframe. WHEP-subscribes the 6 seats,
          composites the 3x2 grid, mixes audio, and WHIP-publishes the combined
          stream to the path the worker pulls. Auto-runs whenever canvas mode is
          on and the meeting is active — no manual tab. Pre-warms on
          scheduled/starting so the composite is publishing before the worker
          fires. Stays open as long as this Show page is open. */}
      {livestream.canvasMode && livestream.browserMediaMtxPush && livestream.canvasUrl && ["scheduled", "starting", "meeting_live", "live"].includes(livestream.status) && (
        <iframe
          src={livestream.canvasUrl}
          title="canvas-mixer"
          tabIndex={-1}
          aria-hidden="true"
          className="pointer-events-none fixed h-px w-px overflow-hidden border-0 opacity-0"
          style={{ left: "-9999px", top: "-9999px" }}
          allow="autoplay; clipboard-write"
        />
      )}
      <div className="container mx-auto py-8 px-4 max-w-6xl">
        <Link href="/livestreams" className="inline-flex items-center text-gray-400 hover:text-white mb-4">
          <ArrowLeft className="w-4 h-4 mr-2" />
          Back to Livestreams
        </Link>
        <div className="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h1 className="text-3xl font-bold mb-2">
              {livestream.title || "Untitled Livestream"}
            </h1>
            <p className="text-gray-400">{organization.name}</p>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Link href="/livestreams/overlay-studio">
              <Button variant="outline" size="sm" className="gap-2">
                <Layers className="h-4 w-4" />
                Overlay Studio
              </Button>
            </Link>
            <Link href="/livestreams/supporter/recordings">
              <Button variant="outline" size="sm" className="gap-2">
                <Clapperboard className="h-4 w-4" />
                Recordings
              </Button>
            </Link>
            {getStatusBadge()}
          </div>
        </div>

        {recordingConsentDeclines.length > 0 && (
          <Alert className="mb-6 border-amber-500/40 bg-amber-500/10">
            <AlertCircle className="h-4 w-4 text-amber-600 dark:text-amber-400" />
            <AlertDescription className="text-foreground">
              <p className="font-medium text-sm mb-2">Guests who declined recording consent</p>
              <p className="text-xs text-muted-foreground mb-3">
                They were not admitted to the meeting. This list updates every few seconds while you keep this page open.
              </p>
              <ul className="text-sm space-y-1 max-h-48 overflow-y-auto">
                {recordingConsentDeclines.map((row) => (
                  <li key={row.id} className="flex justify-between gap-3 py-1 border-b border-white/10 last:border-0">
                    <span className="truncate">{(row.guestLabel ?? "").trim() || "Guest"}</span>
                    <span className="shrink-0 text-muted-foreground text-xs tabular-nums">
                      {formatDeclineTime(row.createdAt)}
                    </span>
                  </li>
                ))}
              </ul>
            </AlertDescription>
          </Alert>
        )}

        {isEndingStreamPending && livestream.status === "live" && (
          <Alert className="border-blue-500/30 bg-gradient-to-r from-purple-500/10 to-blue-500/10">
            <Info className="h-4 w-4" />
            <AlertDescription>
              <p className="font-medium text-foreground">Ending YouTube live</p>
              <p className="text-sm text-muted-foreground mt-1">
                YouTube was told to stop. This page refreshes until the stream has fully ended, then you can go live again.
              </p>
            </AlertDescription>
          </Alert>
        )}

        <Tabs defaultValue="dashboard" className="space-y-6">
          <TabsList>
            <TabsTrigger value="dashboard">Host Dashboard</TabsTrigger>
            <TabsTrigger value="settings">Settings</TabsTrigger>
          </TabsList>

          <TabsContent value="dashboard" className="space-y-6">
            {/* Quick Actions */}
            <Card>
              <CardHeader>
                <CardTitle>Quick Actions</CardTitle>
              </CardHeader>
              <CardContent className="flex gap-4">
                {showGoLiveButton && (
                  <Button
                    onClick={goLiveCloud}
                    disabled={isUpdatingStatus || isEndingStreamPending}
                    className="bg-gradient-to-r from-red-600 to-red-700 hover:from-red-700 hover:to-red-800 min-w-[10.5rem]"
                  >
                    <Play className="w-4 h-4 mr-2" />
                    Go Live (Cloud)
                  </Button>
                )}
                {["live", "meeting_live", "starting"].includes(livestream.status) && (
                  <Button
                    onClick={endStreamCloud}
                    disabled={isUpdatingStatus || isEndingStreamPending}
                    variant="destructive"
                  >
                    <Square className="w-4 h-4 mr-2" />
                    {isEndingStreamPending ? "Stopping…" : "End Stream"}
                  </Button>
                )}
                <Button
                  variant="default"
                  onClick={() => window.open(livestream.hostPushUrl, "_blank")}
                >
                  <ExternalLink className="w-4 h-4 mr-2" />
                  Open Host Push (start camera)
                </Button>
                <Button
                  variant="outline"
                  onClick={() => window.open(livestream.directorUrl, "_blank")}
                >
                  <ExternalLink className="w-4 h-4 mr-2" />
                  Director Mode
                </Button>
              </CardContent>
            </Card>

            {/* Director Link */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Video className="w-5 h-5" />
                  Director Link (For You)
                </CardTitle>
                <CardDescription>
                  Use this link to control the stream and appear on screen
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="flex gap-2">
                  <Input
                    value={livestream.directorUrl}
                    readOnly
                    className="font-mono text-sm"
                  />
                  <Button
                    variant="outline"
                    size="icon"
                    onClick={() => copyToClipboard(livestream.directorUrl, "director")}
                  >
                    <Copy className={`w-4 h-4 ${copied === "director" ? "text-green-400" : ""}`} />
                  </Button>
                </div>
                <Alert>
                  <Info className="w-4 h-4" />
                  <AlertDescription>
                    Click "Enter the room's Control Center in the director's role" when the page opens.
                    This allows you to control the stream and appear on screen with guests.
                  </AlertDescription>
                </Alert>
              </CardContent>
            </Card>

            {/* Guest Link */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Users className="w-5 h-5" />
                  Guest Join Link
                </CardTitle>
                <CardDescription>
                  Share this link with your guests - no accounts needed!
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="flex gap-2">
                  <Input
                    value={livestream.participantUrl}
                    readOnly
                    className="font-mono text-sm"
                  />
                  <Button
                    variant="outline"
                    size="icon"
                    onClick={() => copyToClipboard(livestream.participantUrl, "participant")}
                  >
                    <Copy className={`w-4 h-4 ${copied === "participant" ? "text-green-400" : ""}`} />
                  </Button>
                </div>
                <p className="text-sm text-gray-400">
                  Or use the public join page:{" "}
                  <a
                    href={`/livestreams/join/${livestream.roomName}`}
                    target="_blank"
                    className="text-[#FF1493] hover:underline"
                  >
                    /livestreams/join/{livestream.roomName}
                  </a>
                </p>
              </CardContent>
            </Card>

            {/* Room Details */}
            <Card>
              <CardHeader>
                <CardTitle>Room Details</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div>
                  <Label>Room Name</Label>
                  <div className="flex gap-2 mt-1">
                    <Input value={livestream.roomName} readOnly className="font-mono" />
                    <Button
                      variant="outline"
                      size="icon"
                      onClick={() => copyToClipboard(livestream.roomName, "room")}
                    >
                      <Copy className={`w-4 h-4 ${copied === "room" ? "text-green-400" : ""}`} />
                    </Button>
                  </div>
                </div>
                {livestream.requiresPasscode ? (
                  <div>
                    <Label>Room Password</Label>
                    <div className="flex gap-2 mt-1">
                      <Input value={livestream.roomPassword} readOnly className="font-mono" />
                      <Button
                        variant="outline"
                        size="icon"
                        onClick={() => copyToClipboard(livestream.roomPassword, "password")}
                      >
                        <Copy className={`w-4 h-4 ${copied === "password" ? "text-green-400" : ""}`} />
                      </Button>
                    </div>
                  </div>
                ) : null}
              </CardContent>
            </Card>

            {/* YouTube Status */}
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Youtube className="w-5 h-5" />
                  YouTube Integration
                </CardTitle>
              </CardHeader>
              <CardContent>
                {livestream.hasStreamKey ? (
                  <div className="flex items-center gap-2 text-green-400">
                    <CheckCircle2 className="w-5 h-5" />
                    <span>Stream key configured - Ready to broadcast</span>
                  </div>
                ) : (
                  <div className="space-y-4">
                    <Alert>
                      <AlertCircle className="w-4 h-4" />
                      <AlertDescription>
                        No YouTube stream key configured. Add one in the Settings tab to enable cloud streaming to YouTube.
                      </AlertDescription>
                    </Alert>
                    {organization.youtubeChannelUrl && (
                      <p className="text-sm text-gray-400">
                        Your YouTube Channel:{" "}
                        <a
                          href={organization.youtubeChannelUrl}
                          target="_blank"
                          className="text-[#FF1493] hover:underline"
                        >
                          {organization.youtubeChannelUrl}
                        </a>
                      </p>
                    )}
                  </div>
                )}
              </CardContent>
            </Card>
          </TabsContent>


          <TabsContent value="settings" className="space-y-6">
            <Card>
              <CardHeader>
                <CardTitle className="flex items-center gap-2">
                  <Key className="w-5 h-5" />
                  YouTube Stream Key
                </CardTitle>
                <CardDescription>
                  Add or update your YouTube stream key for cloud broadcasting
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-4">
                <Alert>
                  <Info className="w-4 h-4" />
                  <AlertDescription>
                    <strong>How to get your Stream Key:</strong>
                    <ol className="list-decimal list-inside mt-2 space-y-1 text-sm">
                      <li>Go to <a href="https://studio.youtube.com" target="_blank" rel="noopener noreferrer" className="text-[#FF1493] hover:underline">YouTube Studio</a> → Go Live</li>
                      <li>Create a new stream or select an existing one</li>
                      <li>Copy the "Stream Key" (usually starts with characters like "rtmp://" or a long alphanumeric string)</li>
                      <li>Paste it below</li>
                    </ol>
                  </AlertDescription>
                </Alert>

                <form onSubmit={updateStreamKey}>
                  <div className="space-y-4">
                    <div>
                      <Label htmlFor="stream_key">Stream Key</Label>
                      <Input
                        id="stream_key"
                        type="password"
                        value={streamKey}
                        onChange={(e) => setStreamKey(e.target.value)}
                        placeholder="Paste your YouTube stream key here"
                        className="mt-1 font-mono text-sm"
                        required
                      />
                    </div>
                    <Button
                      type="submit"
                      disabled={isUpdatingStreamKey || !streamKey}
                      className="bg-gradient-to-r from-[#FF1493] to-[#DC143C]"
                    >
                      {isUpdatingStreamKey ? "Updating..." : livestream.hasStreamKey ? "Update Stream Key" : "Add Stream Key"}
                    </Button>
                  </div>
                </form>
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      </div>

      <GoLiveConfirmDialog
        open={goLiveConfirmOpen}
        onOpenChange={setGoLiveConfirmOpen}
        onConfirm={queueGoLiveCloud}
        isConfirming={isGoLivePending}
      />
    </AppLayout>
  )
}

