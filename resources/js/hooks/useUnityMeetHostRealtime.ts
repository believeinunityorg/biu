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

type ViewerStatusPayload = {
  status?: string
  isPublic?: boolean
  reason?: string
  meetingSessionKey?: number
}

type Options<TLivestream extends LivestreamRealtimeSlice> = {
  broadcastChannel: string | null | undefined
  livestream: TLivestream
  recordingConsentDeclines: RecordingConsentDeclineRow[]
  participantRoster?: UnityMeetParticipant[]
}

const ACTIVE_STATUSES = new Set(["meeting_live", "live", "starting"])

function rosterSignature(roster: UnityMeetParticipant[]): string {
  return roster
    .map((p) => `${p.sessionId ?? ""}:${p.email}:${p.role}:${p.id ?? ""}`)
    .sort()
    .join("|")
}

function sessionKeyOf(slice: { meetingSessionKey?: number } | null | undefined): number {
  return typeof slice?.meetingSessionKey === "number" ? slice.meetingSessionKey : 0
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
  /** Highest meeting session seen from Inertia props or accepted Echo events. */
  const sessionKeyRef = useRef(sessionKeyOf(livestream))

  useEffect(() => {
    const key = sessionKeyOf(livestream)
    sessionKeyRef.current = Math.max(sessionKeyRef.current, key)
    setLiveLivestream(livestream)
  }, [livestream])

  useEffect(() => {
    setLiveDeclines(recordingConsentDeclines)
  }, [recordingConsentDeclines])

  useEffect(() => {
    if (livestream.id !== livestreamIdRef.current) {
      livestreamIdRef.current = livestream.id
      sessionKeyRef.current = sessionKeyOf(livestream)
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

  const applyDashboard = useCallback(
    (payload: UnityMeetHostDashboardPayload) => {
      if (payload.livestream) {
        setLiveLivestream((prev) => {
          const next = payload.livestream!
          const nextSession = sessionKeyOf(next)

          // Late abandon/worker echo from an older meeting session.
          if (nextSession < sessionKeyRef.current) {
            return prev
          }

          sessionKeyRef.current = Math.max(sessionKeyRef.current, nextSession)
          return { ...prev, ...next }
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

  const applyViewerStatus = useCallback((payload: ViewerStatusPayload) => {
    setLiveLivestream((prev) => {
      const nextSession =
        typeof payload.meetingSessionKey === "number"
          ? payload.meetingSessionKey
          : sessionKeyOf(prev)

      // Stale stream_ended after Start Meeting bumped the session via Inertia.
      if (nextSession < sessionKeyRef.current) {
        return prev
      }

      // Echo without session key: don't downgrade an active meeting on end reasons
      // when Inertia already advanced the session beyond what we had at mount.
      if (
        typeof payload.meetingSessionKey !== "number" &&
        ACTIVE_STATUSES.has(prev.status ?? "") &&
        payload.status &&
        !ACTIVE_STATUSES.has(payload.status) &&
        (payload.reason === "stream_ended" ||
          payload.reason === "meeting_ended" ||
          payload.reason === "unity_live_ended") &&
        sessionKeyOf(prev) < sessionKeyRef.current
      ) {
        return prev
      }

      if (typeof payload.meetingSessionKey === "number") {
        sessionKeyRef.current = Math.max(sessionKeyRef.current, payload.meetingSessionKey)
      }

      return {
        ...prev,
        ...(payload.status !== undefined ? { status: payload.status } : {}),
        ...(payload.isPublic !== undefined ? { isPublic: payload.isPublic } : {}),
        ...(typeof payload.meetingSessionKey === "number"
          ? { meetingSessionKey: payload.meetingSessionKey }
          : {}),
        ...(payload.status === "draft" || payload.status === "scheduled"
          ? { canStartMeeting: true }
          : payload.status && ACTIVE_STATUSES.has(payload.status)
            ? { canStartMeeting: false }
            : {}),
      }
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

  useEcho<ViewerStatusPayload>(
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
  }
}
