/** Client-facing cloud stream labels (YouTube readiness card). */
export type StreamingDisplayStatus = {
  label: string
  description: string
  tone: "neutral" | "progress" | "success" | "warning" | "error"
}

const STATUS_COPY: Record<string, { label: string; description: string }> = {
  pending: {
    label: "Pending",
    description: "You clicked Go Live but the process has not started yet.",
  },
  preparing: {
    label: "Preparing Stream",
    description: "Your stream setup is starting.",
  },
  connecting: {
    label: "Connecting Sources",
    description: "Connecting your meeting to YouTube.",
  },
  starting_broadcast: {
    label: "Starting Broadcast",
    description: "Sending video to YouTube.",
  },
  waiting_youtube: {
    label: "Waiting for YouTube",
    description: "YouTube ingest is processing the stream.",
  },
  live: {
    label: "Live",
    description: "Stream is visible publicly on YouTube.",
  },
  reconnecting: {
    label: "Reconnecting",
    description: "Temporary disconnect; recovery in progress.",
  },
  ending: {
    label: "Ending Stream",
    description: "Cleanup process is running.",
  },
  ended: {
    label: "Ended",
    description: "Stream finished successfully.",
  },
  failed: {
    label: "Failed",
    description: "Stream startup or broadcast failed.",
  },
}

function copy(key: keyof typeof STATUS_COPY, tone: StreamingDisplayStatus["tone"]): StreamingDisplayStatus {
  const row = STATUS_COPY[key]
  return { ...row, tone }
}

/** True while cloud relay is queued, starting, or waiting for YouTube (survives page reload). */
export function isStreamRelayInProgress(input: {
  jobStatus?: string | null
  livestreamStatus: string
  streamStopRequested?: boolean
}): boolean {
  if (input.streamStopRequested) {
    return false
  }
  const job = (input.jobStatus ?? "").trim().toLowerCase() || null
  const ls = (input.livestreamStatus ?? "").trim().toLowerCase()
  if (job === "queued" || job === "starting") {
    return true
  }
  if (job === "live" && ls !== "live") {
    return true
  }
  return false
}

/**
 * Map worker queue status + meeting row state to the client status table.
 */
export function resolveStreamingDisplayStatus(input: {
  jobStatus?: string | null
  livestreamStatus: string
  isEndingStreamPending?: boolean
  streamStopRequested?: boolean
  failureReason?: string | null
  jobUpdatedAt?: string | null
}): StreamingDisplayStatus | null {
  const job = (input.jobStatus ?? "").trim().toLowerCase() || null
  const ls = (input.livestreamStatus ?? "").trim().toLowerCase()
  const reason = (input.failureReason ?? "").toLowerCase()

  if (
    (input.isEndingStreamPending || input.streamStopRequested) &&
    hasActiveYoutubeStreamingJob(job) &&
    isYoutubeMeetingEndable(ls)
  ) {
    return copy("ending", "progress")
  }

  if (job === "live" && reason && /reconnect|retry|ffmpeg_retry/.test(reason)) {
    return copy("reconnecting", "warning")
  }

  if (job === "queued") {
    return copy("pending", "progress")
  }

  if (job === "starting") {
    if (ls === "live") {
      return copy("starting_broadcast", "progress")
    }
    if (ls === "meeting_live") {
      const updated = input.jobUpdatedAt ? Date.parse(input.jobUpdatedAt) : NaN
      const ageMs = Number.isNaN(updated) ? 0 : Date.now() - updated
      if (ageMs >= 0 && ageMs < 20_000) {
        return copy("preparing", "progress")
      }
      return copy("connecting", "progress")
    }
    return copy("preparing", "progress")
  }

  if (job === "live") {
    if (ls === "live") {
      return copy("live", "success")
    }
    return copy("waiting_youtube", "progress")
  }

  if (job === "completed" || job === "stopped") {
    return copy("ended", "neutral")
  }

  if (job === "failed") {
    return copy("failed", "error")
  }

  return null
}

const YOUTUBE_ENDABLE_MEETING_STATUSES = ["live", "meeting_live", "starting"] as const

export function hasActiveYoutubeStreamingJob(jobStatus?: string | null): boolean {
  const job = (jobStatus ?? "").trim().toLowerCase()
  return job === "queued" || job === "starting" || job === "live"
}

/** Matches SupporterLivestreamController::endStream — meeting row must be in an active state. */
export function isYoutubeMeetingEndable(livestreamStatus?: string): boolean {
  const ls = (livestreamStatus ?? "").trim().toLowerCase()
  return (YOUTUBE_ENDABLE_MEETING_STATUSES as readonly string[]).includes(ls)
}

/**
 * Host may end YouTube: active streaming_jobs row (or equivalent job status) and
 * meeting status the backend accepts. Ignores stale stream_stop_requested alone.
 */
export function canEndYoutubeLive(input: {
  wantsYoutubeLive?: boolean
  livestreamStatus: string
  jobStatus?: string | null
  hasActiveStreamingJob?: boolean
}): boolean {
  if (!input.wantsYoutubeLive || !isYoutubeMeetingEndable(input.livestreamStatus)) {
    return false
  }

  if (input.hasActiveStreamingJob === true) {
    return true
  }

  return hasActiveYoutubeStreamingJob(input.jobStatus)
}

/** @deprecated Use canEndYoutubeLive — kept for relay/Unity overlap checks. */
export function isYoutubeStreamSessionActive(input: {
  wantsYoutubeLive?: boolean
  jobStatus?: string | null
  livestreamStatus: string
  hasActiveStreamingJob?: boolean
}): boolean {
  return canEndYoutubeLive({
    wantsYoutubeLive: input.wantsYoutubeLive,
    livestreamStatus: input.livestreamStatus,
    jobStatus: input.jobStatus,
    hasActiveStreamingJob: input.hasActiveStreamingJob,
  })
}

/** Unity Live listing is published (Go Unity Live), not merely YouTube relay using status=live. */
export function isUnityLivePublished(input: {
  wantsUnityLive?: boolean
  livestreamStatus: string
  youtubeStreamActive?: boolean
}): boolean {
  if (!input.wantsUnityLive || input.youtubeStreamActive) {
    return false
  }

  return (input.livestreamStatus ?? "").trim().toLowerCase() === "live"
}

/** Unity Live listing status (host sidebar card). */
export function resolveUnityLiveDisplayStatus(input: {
  livestreamStatus: string
  isPublic?: boolean
  wantsUnityLive?: boolean
  youtubeStreamActive?: boolean
}): StreamingDisplayStatus | null {
  if (!input.wantsUnityLive) {
    return null
  }

  const ls = (input.livestreamStatus ?? "").trim().toLowerCase()
  const isPublic = Boolean(input.isPublic)

  if (ls === "live" && !input.youtubeStreamActive) {
    if (isPublic) {
      return {
        label: "Live",
        description: "Listed on Unity Live — viewers can watch from your share link.",
        tone: "success",
      }
    }

    return {
      label: "Live (private)",
      description: "Meeting is live for viewers with your private link (not on Unity Live directory).",
      tone: "success",
    }
  }

  if (ls === "live" && input.youtubeStreamActive) {
    return {
      label: "Not live",
      description: "Click Go Unity Live when you want this meeting on Unity Live.",
      tone: "neutral",
    }
  }

  if (ls === "meeting_live") {
    return {
      label: "Ready",
      description: "Meeting is running. Click Go Unity Live when you want viewers to watch.",
      tone: "progress",
    }
  }

  if (ls === "ended") {
    return {
      label: "Ended",
      description: "Unity Live listing ended. Go Unity Live again when you are ready.",
      tone: "neutral",
    }
  }

  if (ls === "draft" || ls === "scheduled") {
    return {
      label: "Not live",
      description: "Start the meeting, then click Go Unity Live to publish for viewers.",
      tone: "neutral",
    }
  }

  return {
    label: "Unavailable",
    description: "Cannot publish on Unity Live in the current meeting state.",
    tone: "warning",
  }
}

export function streamingDisplayBadgeClass(tone: StreamingDisplayStatus["tone"]): string {
  switch (tone) {
    case "success":
      return "border-green-500/40 bg-green-500/10 text-green-700 dark:text-green-400"
    case "error":
      return "border-red-500/40 bg-red-500/10 text-red-700 dark:text-red-400"
    case "warning":
      return "border-amber-500/40 bg-amber-500/10 text-amber-800 dark:text-amber-300"
    case "progress":
      return "border-purple-500/40 bg-purple-500/10 text-purple-800 dark:text-purple-300"
    default:
      return "border-border bg-muted/50 text-muted-foreground"
  }
}
