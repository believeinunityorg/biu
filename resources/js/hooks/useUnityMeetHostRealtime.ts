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

  useEffect(() => {
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

  const applyDashboard = useCallback(
    (payload: UnityMeetHostDashboardPayload) => {
      if (payload.livestream) {
        setLiveLivestream((prev) => ({ ...prev, ...payload.livestream }))
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

  const applyViewerStatus = useCallback((payload: { status?: string; isPublic?: boolean }) => {
    setLiveLivestream((prev) => ({
      ...prev,
      ...(payload.status !== undefined ? { status: payload.status } : {}),
      ...(payload.isPublic !== undefined ? { isPublic: payload.isPublic } : {}),
    }))
  }, [])

  const channel = broadcastChannel ?? "unity-live.disabled"

  useEcho<UnityMeetHostDashboardPayload>(
    channel,
    ".host.dashboard",
    applyDashboard,
    [channel, applyDashboard],
    "public",
  )

  useEcho<{ status?: string; isPublic?: boolean }>(
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
