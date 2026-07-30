import { useEcho } from "@laravel/echo-react"
import { useCallback, useEffect, useRef, useState } from "react"
import type { UnityMeetParticipant } from "@/components/meeting/UnityMeetParticipantPanel"

export type RecordingConsentDeclineRow = {
  id: number
  guestLabel: string | null
  createdAt: string | null
}

type StreamingQueueStatus = {
  status?: string | null
  livestreamStatus?: string
  streamStopRequested?: boolean
  updatedAt?: string | null
  failureReason?: string | null
}

type LivestreamRealtimeSlice = {
  id?: number
  status?: string
  isPublic?: boolean
  wantsUnityLive?: boolean
  startedAt?: string | null
  endedAt?: string | null
  meetingSessionKey?: number
  canStartMeeting?: boolean
  canSetUnityLive?: boolean
  canQueueYoutubeLive?: boolean
  canGoLive?: boolean
  streamingQueueStatus?: StreamingQueueStatus | null
  hasActiveStreamingJob?: boolean
}

export type UnityMeetHostDashboardPayload = {
  reason: string
  livestream?: LivestreamRealtimeSlice
  recordingConsentDeclines?: RecordingConsentDeclineRow[]
  participantRoster?: UnityMeetParticipant[]
}

type Options<TLivestream extends LivestreamRealtimeSlice> = {
  broadcastChannel: string | null | undefined
  livestream: TLivestream
  recordingConsentDeclines: RecordingConsentDeclineRow[]
  participantRoster?: UnityMeetParticipant[]
}

function rosterSignature(roster: UnityMeetParticipant[]): string {
  return roster
    .map((p) => `${p.sessionId ?? ""}:${p.email}:${p.role}:${p.id ?? ""}`)
    .sort()
    .join("|")
}

function livestreamSyncKey(livestream: LivestreamRealtimeSlice): string {
  return [
    livestream.id ?? "",
    livestream.status ?? "",
    livestream.isPublic ? "1" : "0",
    livestream.startedAt ?? "",
    livestream.endedAt ?? "",
    livestream.meetingSessionKey ?? "",
    livestream.canStartMeeting ? "1" : "0",
    livestream.canSetUnityLive ? "1" : "0",
    livestream.canQueueYoutubeLive ? "1" : "0",
    livestream.canGoLive ? "1" : "0",
    livestream.hasActiveStreamingJob ? "1" : "0",
    livestream.streamingQueueStatus?.status ?? "",
    livestream.streamingQueueStatus?.updatedAt ?? "",
  ].join("|")
}

/**
 * Push host dashboard updates over Reverb — roster, status, and queue sync (no polling).
 */
export function useUnityMeetHostRealtime<TLivestream extends LivestreamRealtimeSlice>({
  broadcastChannel,
  livestream,
  recordingConsentDeclines,
  participantRoster = [],
}: Options<TLivestream>) {
  const [liveLivestream, setLiveLivestream] = useState(livestream)
  const [liveDeclines, setLiveDeclines] = useState(recordingConsentDeclines)
  const [liveRoster, setLiveRoster] = useState(participantRoster)
  const rosterSigRef = useRef(rosterSignature(participantRoster))
  const livestreamIdRef = useRef(livestream.id)
  const syncKeyRef = useRef(livestreamSyncKey(livestream))

  useEffect(() => {
    const nextKey = livestreamSyncKey(livestream)
    if (nextKey === syncKeyRef.current) {
      return
    }
    syncKeyRef.current = nextKey
    setLiveLivestream(livestream)
  }, [livestream])

  useEffect(() => {
    setLiveDeclines(recordingConsentDeclines)
  }, [recordingConsentDeclines])

  useEffect(() => {
    if (livestream.id !== livestreamIdRef.current) {
      livestreamIdRef.current = livestream.id
      rosterSigRef.current = rosterSignature(participantRoster)
      setLiveRoster(participantRoster)
    }
  }, [livestream.id, participantRoster])

  const applyRoster = useCallback((roster: UnityMeetParticipant[]) => {
    const sig = rosterSignature(roster)
    if (sig === rosterSigRef.current) {
      return
    }
    rosterSigRef.current = sig
    setLiveRoster(roster)
  }, [])

  const patchLivestream = useCallback((patch: Partial<TLivestream>) => {
    setLiveLivestream((prev) => {
      const next = { ...prev, ...patch }
      syncKeyRef.current = livestreamSyncKey(next)
      return next
    })
  }, [])

  const applyDashboard = useCallback(
    (payload: UnityMeetHostDashboardPayload) => {
      if (payload.livestream) {
        setLiveLivestream((prev) => {
          const next = { ...prev, ...payload.livestream }
          syncKeyRef.current = livestreamSyncKey(next)
          return next
        })
      }
      if (payload.recordingConsentDeclines) {
        setLiveDeclines(payload.recordingConsentDeclines)
      }
      if (payload.participantRoster) {
        applyRoster(payload.participantRoster)
      }
    },
    [applyRoster],
  )

  const applyViewerStatus = useCallback((payload: { status?: string; isPublic?: boolean; reason?: string }) => {
    setLiveLivestream((prev) => {
      const status = payload.status ?? prev.status
      const isPublic = payload.isPublic ?? prev.isPublic
      const published = status === "live"
      const next = {
        ...prev,
        ...(payload.status !== undefined ? { status: payload.status } : {}),
        ...(payload.isPublic !== undefined ? { isPublic: payload.isPublic } : {}),
        ...(payload.isPublic !== undefined ? { wantsUnityLive: Boolean(payload.isPublic) } : {}),
        canSetUnityLive: published ? false : prev.canSetUnityLive,
        canStartMeeting: ["meeting_live", "live", "starting"].includes(String(status))
          ? false
          : prev.canStartMeeting,
      }
      syncKeyRef.current = livestreamSyncKey(next)
      return next
    })
  }, [])

  const channel = broadcastChannel ?? "unity-live.disabled"

  useEcho<UnityMeetHostDashboardPayload>(
    channel,
    ".host.dashboard",
    applyDashboard,
    [channel, applyDashboard],
    "public",
  )

  useEcho<{ status?: string; isPublic?: boolean; reason?: string }>(
    channel,
    ".viewer.status",
    applyViewerStatus,
    [channel, applyViewerStatus],
    "public",
  )

  return {
    livestream: liveLivestream,
    recordingConsentDeclines: liveDeclines,
    participantRoster: liveRoster,
    patchLivestream,
  }
}
