"use client"

import { useMemo, useState, type ReactNode } from "react"
import { Head } from "@inertiajs/react"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { BelieveInUnityBrandMark } from "@/components/site-title"
import VdoMeetingIframe from "@/components/meeting/VdoMeetingIframe"
import { RecordingConsentBarrier } from "@/components/livestreams/RecordingConsentBarrier"
import { applyVdoGroupRoomPresentation, vdoUiAvatarUrl } from "@/lib/vdoMeeting"
import { useLivestreamMeetingPresence } from "@/hooks/useLivestreamMeetingPresence"
import UnityMeetGiftCelebrationLayer from "@/components/meeting/UnityMeetGiftCelebrationLayer"
import { LogOut, Video } from "lucide-react"
import { cn } from "@/lib/utils"

export interface GuestMeetJoinLivestream {
  id: number
  title: string | null
  roomName: string
  participantUrl: string
  recordingEnabled?: boolean
  broadcastChannel?: string
  declineContext?: { kind: "user" | "organization"; id: number }
}

export interface GuestMeetJoinOrganization {
  id: number
  name: string
}

type Props = {
  livestream: GuestMeetJoinLivestream
  organization: GuestMeetJoinOrganization
  recordingDeclineReturnTo: string
  lobbyBeforeJoin?: ReactNode
  canEnterMeeting?: boolean
  defaultDisplayName?: string
  guestEmail?: string | null
  authUserId?: number
  consentAppearance?: "light" | "dark"
  pageClassName?: string
}

function AvatarInitial({ initial, small }: { initial: string; small?: boolean }) {
  return (
    <div
      className={cn(
        "flex shrink-0 items-center justify-center rounded-full bg-muted font-semibold text-muted-foreground",
        small ? "h-9 w-9 text-sm" : "h-14 w-14 text-xl",
      )}
    >
      {initial}
    </div>
  )
}

export function GuestMeetJoinExperience({
  livestream,
  organization,
  recordingDeclineReturnTo,
  lobbyBeforeJoin = null,
  canEnterMeeting = true,
  defaultDisplayName = "",
  guestEmail = null,
  authUserId = 0,
  consentAppearance = "light",
  pageClassName,
}: Props) {
  const [displayName, setDisplayName] = useState(defaultDisplayName)
  const [joined, setJoined] = useState(false)
  const recordingOn = !!(livestream.recordingEnabled ?? false)
  const [recordingConsentOk, setRecordingConsentOk] = useState(!recordingOn)

  const displayLabel = (displayName || "Guest").trim()
  const initial = displayLabel.charAt(0).toUpperCase() || "G"

  const { leaveMeeting } = useLivestreamMeetingPresence({
    roomName: livestream.roomName,
    displayName: displayLabel,
    email: guestEmail,
    active: joined,
  })

  const handleLeaveMeeting = async () => {
    await leaveMeeting()
    setJoined(false)
  }

  const iframeUrl = useMemo(() => {
    if (!joined) return null
    const url = new URL(livestream.participantUrl)
    applyVdoGroupRoomPresentation(url)
    if (displayLabel) {
      url.searchParams.set("label", displayLabel)
      url.searchParams.set("avatar", vdoUiAvatarUrl(displayLabel))
    }
    return url.toString()
  }, [livestream.participantUrl, joined, displayLabel])

  const needBarrier =
    canEnterMeeting &&
    !joined &&
    recordingOn &&
    !!livestream.declineContext &&
    !recordingConsentOk

  const showLobby =
    canEnterMeeting && !joined && (!recordingOn || recordingConsentOk)

  return (
    <div className={cn("flex min-h-screen flex-col bg-background", pageClassName)}>
      <Head title={`Join: ${livestream.title || "Meeting"}`} />

      <UnityMeetGiftCelebrationLayer broadcastChannel={livestream.broadcastChannel} authUserId={authUserId} />

      {needBarrier && livestream.declineContext && (
        <RecordingConsentBarrier
          open
          appearance={consentAppearance}
          meetingTitle={livestream.title}
          organizerLabel={organization.name}
          livestreamKind={livestream.declineContext.kind}
          livestreamId={livestream.declineContext.id}
          guestLabel={displayLabel !== "Guest" ? displayLabel : null}
          onAccepted={() => setRecordingConsentOk(true)}
          returnToAfterDecline={recordingDeclineReturnTo}
        />
      )}

      {showLobby && (
        <div className="flex flex-1 flex-col items-center justify-center p-4 sm:p-6 lg:p-8">
          <div className="w-full max-w-[420px]">
            <LobbyHeader organization={organization} livestream={livestream} recordingOn={recordingOn} />

            {lobbyBeforeJoin}

            <div className="overflow-hidden rounded-2xl border border-border/80 bg-card shadow-xl">
              <JoinCard
                initial={initial}
                displayName={displayName}
                onDisplayNameChange={setDisplayName}
                onJoin={() => setJoined(true)}
              />
            </div>
          </div>
        </div>
      )}

      {joined && iframeUrl && (
        <MeetingView
          iframeUrl={iframeUrl}
          initial={initial}
          displayLabel={displayLabel}
          title={livestream.title}
          organizationName={organization.name}
          onLeave={handleLeaveMeeting}
        />
      )}
    </div>
  )
}

function LobbyHeader({
  organization,
  livestream,
  recordingOn,
}: {
  organization: GuestMeetJoinOrganization
  livestream: GuestMeetJoinLivestream
  recordingOn: boolean
}) {
  return (
    <div className="mb-8 flex flex-col items-center text-center">
      <BelieveInUnityBrandMark className="mb-6 justify-center" />
      <p className="mb-1 text-sm text-muted-foreground">
        {organization.name} is inviting you to a meeting
      </p>
      <h1 className="max-w-md text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">
        {livestream.title || "Meeting"}
      </h1>
      {recordingOn && (
        <p className="mt-3 inline-flex items-center gap-2 rounded-full bg-purple-100 px-3 py-1 text-xs font-semibold text-purple-800 dark:bg-purple-950/80 dark:text-purple-200">
          <Video className="h-3.5 w-3.5" aria-hidden />
          Recording enabled — you’ll confirm before joining
        </p>
      )}
    </div>
  )
}

function JoinCard({
  initial,
  displayName,
  onDisplayNameChange,
  onJoin,
}: {
  initial: string
  displayName: string
  onDisplayNameChange: (value: string) => void
  onJoin: () => void
}) {
  return (
    <>
      <div className="px-6 pt-6 pb-4">
        <p className="mb-3 text-xs font-medium uppercase tracking-wider text-muted-foreground">
          You’ll join as
        </p>
        <div className="flex items-center gap-4">
          <AvatarInitial initial={initial} />
          <Input
            type="text"
            placeholder="Your name"
            value={displayName}
            onChange={(e) => onDisplayNameChange(e.target.value)}
            className="h-12 flex-1 border-border bg-muted/40 text-base"
            autoComplete="name"
          />
        </div>
      </div>
      <div className="border-t border-border/60 bg-muted/20 px-6 py-5">
        <Button
          type="button"
          className="h-12 w-full rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 text-base font-medium text-white shadow-md hover:from-purple-700 hover:to-blue-700"
          onClick={onJoin}
        >
          Join now
        </Button>
        <p className="mt-4 text-center text-xs text-muted-foreground">
          You can use a camera, a microphone, or both — turn either on or off after joining.
        </p>
      </div>
    </>
  )
}

function MeetingView({
  iframeUrl,
  initial,
  displayLabel,
  title,
  organizationName,
  onLeave,
}: {
  iframeUrl: string
  initial: string
  displayLabel: string
  title: string | null
  organizationName: string
  onLeave: () => void | Promise<void>
}) {
  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <div className="flex shrink-0 items-center gap-3 border-b border-border bg-card px-4 py-3">
        <AvatarInitial initial={initial} small />
        <div className="min-w-0 flex-1">
          <span className="block truncate text-sm font-medium text-foreground">
            {title || "Meeting"}
          </span>
          <span className="block truncate text-xs text-muted-foreground">{organizationName}</span>
        </div>
        <span className="hidden shrink-0 text-xs font-medium text-muted-foreground sm:inline">{displayLabel}</span>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-8 shrink-0 gap-1.5 border-red-500/30 text-red-600 hover:bg-red-500/10 hover:text-red-700 dark:text-red-400"
          onClick={() => void onLeave()}
        >
          <LogOut className="h-3.5 w-3.5" />
          Leave
        </Button>
      </div>
      <div className="relative min-h-0 flex-1 bg-black">
        <VdoMeetingIframe src={iframeUrl} title="Meeting" />
      </div>
    </div>
  )
}
