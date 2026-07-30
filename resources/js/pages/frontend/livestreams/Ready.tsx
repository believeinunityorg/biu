"use client"

import { useEffect, useState } from "react"
import { Head, Link, router, usePage } from "@inertiajs/react"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Copy, Check, Play, ArrowLeft, Calendar, Mail, Users, X, Send } from "lucide-react"
import UnityMeetLayout from "@/layouts/UnityMeetLayout"
import { PageHead } from "@/components/frontend/PageHead"
import { useEmailCreditsState } from "@/hooks/use-email-credits-state"
import BuyEmailCreditsDialog, { type EmailPackageOption } from "@/components/meeting/BuyEmailCreditsDialog"
import EmailCreditsMeetingActions from "@/components/meeting/EmailCreditsMeetingActions"
import UnityMeetInviteChannelPicker, {
  type UnityMeetInviteChannel,
} from "@/components/meeting/UnityMeetInviteChannelPicker"
import UnityMeetInviteRecipientField from "@/components/meeting/UnityMeetInviteRecipientField"

const BRAND = {
  from: "#9333ea",
  to: "#2563eb",
}

interface Livestream {
  id: number
  title: string | null
  roomName: string
  roomPassword: string
  requiresPasscode?: boolean
  joinUrl: string
  status?: string
  scheduledAt?: string | null
  participantEmails?: string[]
  canStartMeeting?: boolean
  isMeetingActive?: boolean
}

interface Props {
  livestream: Livestream
  emailCredits?: {
    emails_included: number
    emails_used: number
    emails_left: number
  }
  emailPackages?: EmailPackageOption[]
  stripeMinCheckoutUsd?: number
}

export default function SupporterReady({
  livestream,
  emailCredits,
  emailPackages = [],
  stripeMinCheckoutUsd = 0.5,
}: Props) {
  const { props: inertiaProps } = usePage<{ errors?: Record<string, string | string[]>; success?: string }>()
  const participantInviteError = inertiaProps.errors?.email
  const participantInviteErrorText = Array.isArray(participantInviteError)
    ? participantInviteError[0]
    : participantInviteError

  const [copied, setCopied] = useState<string | null>(null)
  const [removingParticipantEmail, setRemovingParticipantEmail] = useState<string | null>(null)
  const [inviteEmailInput, setInviteEmailInput] = useState("")
  const [invitingParticipant, setInvitingParticipant] = useState(false)
  const [resendingEmail, setResendingEmail] = useState<string | null>(null)
  const [buyCreditsOpen, setBuyCreditsOpen] = useState(false)
  const [startingMeeting, setStartingMeeting] = useState(false)
  const [inviteNotifyVia, setInviteNotifyVia] = useState<UnityMeetInviteChannel>("both")
  const isScheduled = livestream.status === "scheduled"
  const invitedCount = livestream.participantEmails?.length ?? 0
  const canInviteParticipants = !["ended", "cancelled"].includes(livestream.status ?? "")
  const { credits: emailCreditsLive, canSend: canSendEmailInvites, syncFromServer, applyDelta } =
    useEmailCreditsState(emailCredits)

  useEffect(() => {
    if (inertiaProps.success && emailCredits) {
      syncFromServer(emailCredits)
    }
  }, [inertiaProps.success, emailCredits, syncFromServer])

  const copy = (text: string, key: string) => {
    navigator.clipboard.writeText(text)
    setCopied(key)
    setTimeout(() => setCopied(null), 2000)
  }

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

  return (
    <UnityMeetLayout
      breadcrumbs={[
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Unity Meet Communications', href: '/livestreams/supporter' },
        { title: 'Ready', href: `/livestreams/supporter/ready/${livestream.id}` },
      ]}
    >
      <PageHead
        title={isScheduled ? "Meeting scheduled" : "Meeting ready"}
        description={isScheduled ? "Invitations sent. Start the meeting when it's time." : "Invite others and start the meeting when ready."}
      />
      <Head title={isScheduled ? "Meeting scheduled" : "Meeting ready"} />
      <div className="min-h-screen bg-background">
        <div
          className="relative overflow-hidden border-b border-purple-200 dark:border-purple-500/20"
          style={{
            background: `linear-gradient(135deg, rgba(147,51,234,0.12) 0%, rgba(37,99,235,0.1) 100%)`,
          }}
        >
          <div className="relative w-full px-4 py-10 md:px-6 lg:px-8 text-center">
            <Link href="/livestreams/supporter" className="absolute left-4 top-10 inline-flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground">
              <ArrowLeft className="h-4 w-4" />
              Back
            </Link>
            <div className="inline-flex h-14 w-14 items-center justify-center rounded-full text-white shadow-lg mb-4" style={{ background: `linear-gradient(135deg, ${BRAND.from}, ${BRAND.to})` }}>
              <Check className="h-7 w-7" />
            </div>
            <h1 className="text-2xl font-bold tracking-tight text-foreground sm:text-3xl">
              {isScheduled ? "Meeting scheduled" : "Meeting ready"}
            </h1>
            <p className="mt-2 text-muted-foreground">
              {isScheduled
                ? invitedCount > 0
                  ? `Invitation emails were sent to ${invitedCount} participant${invitedCount === 1 ? "" : "s"}.`
                  : "Your meeting is on the calendar. Invite guests below anytime."
                : "Invite guests by email or BIU notification (app + push), then start when ready."}
            </p>
          </div>
        </div>

        <div className="w-full max-w-lg mx-auto px-4 py-8 space-y-6">
          {isScheduled && livestream.scheduledAt ? (
            <Card className="border-blue-500/30 bg-blue-500/5">
              <CardHeader>
                <CardTitle className="text-base flex items-center gap-2">
                  <Calendar className="h-4 w-4 text-blue-600" />
                  Scheduled for
                </CardTitle>
              </CardHeader>
              <CardContent>
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
              </CardContent>
            </Card>
          ) : null}

          {canInviteParticipants ? (
            <Card>
              <CardHeader className="space-y-3">
                <CardTitle className="text-base">Send invitation</CardTitle>
                {emailCreditsLive && inviteUsesEmailCredits ? (
                  <EmailCreditsMeetingActions
                    emailsLeft={emailCreditsLive.emails_left}
                    onBuy={() => setBuyCreditsOpen(true)}
                  />
                ) : null}
              </CardHeader>
              <CardContent className="space-y-3">
                <UnityMeetInviteChannelPicker value={inviteNotifyVia} onChange={setInviteNotifyVia} />
                {inviteUsesEmailCredits && !canSendEmailInvites ? (
                  <p className="text-sm text-amber-700 dark:text-amber-300">
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
                    inputId="ready-participant-invite-email"
                    value={inviteEmailInput}
                    onChange={setInviteEmailInput}
                    onSubmit={addAndSendInvitation}
                    disabled={invitingParticipant}
                    submitting={invitingParticipant}
                    error={participantInviteErrorText}
                  />
                ) : null}
              </CardContent>
            </Card>
          ) : null}

          {invitedCount > 0 ? (
            <Card>
              <CardHeader>
                <CardTitle className="text-base flex items-center gap-2">
                  <Users className="h-4 w-4" />
                  Invited ({invitedCount})
                </CardTitle>
              </CardHeader>
              <CardContent>
                <ul className="space-y-2">
                  {livestream.participantEmails!.map((email) => (
                    <li
                      key={email}
                      className="flex items-center gap-2 rounded-md border border-border/60 bg-muted/30 px-2.5 py-2 text-sm text-foreground"
                    >
                      <Mail className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      <span className="min-w-0 flex-1 truncate">{email}</span>
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
              </CardContent>
            </Card>
          ) : null}

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Meeting ID</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex gap-2">
                <Input value={livestream.roomName} readOnly className="font-mono" />
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  onClick={() => copy(livestream.roomName, "room")}
                >
                  {copied === "room" ? <Check className="h-4 w-4 text-green-600" /> : <Copy className="h-4 w-4" />}
                </Button>
              </div>
              {livestream.requiresPasscode ? (
                <div>
                <p className="text-sm font-medium text-muted-foreground mb-1">Passcode</p>
                <div className="flex gap-2">
                  <Input value={livestream.roomPassword} readOnly className="font-mono" />
                  <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    onClick={() => copy(livestream.roomPassword, "pass")}
                  >
                    {copied === "pass" ? <Check className="h-4 w-4 text-green-600" /> : <Copy className="h-4 w-4" />}
                  </Button>
                </div>
                </div>
              ) : null}
            </CardContent>
          </Card>

          <div className="flex flex-col gap-3">
            <Button
              type="button"
              size="lg"
              className="h-12 w-full text-base font-semibold text-white"
              style={{ background: `linear-gradient(135deg, ${BRAND.from}, ${BRAND.to})` }}
              disabled={startingMeeting}
              onClick={() => {
                if (livestream.isMeetingActive) {
                  router.visit(route("livestreams.supporter.show", livestream.id))
                  return
                }
                setStartingMeeting(true)
                router.post(route("livestreams.supporter.start-meeting", livestream.id), {}, {
                  onFinish: () => setStartingMeeting(false),
                })
              }}
            >
              <Play className="mr-2 h-5 w-5" />
              {startingMeeting
                ? "Starting…"
                : livestream.isMeetingActive
                  ? "Open meeting"
                  : "Start meeting"}
            </Button>
            <Button
              type="button"
              variant="outline"
              size="lg"
              className="h-12 w-full"
              onClick={() => copy(livestream.joinUrl, "invite")}
            >
              {copied === "invite" ? <Check className="mr-2 h-5 w-5 text-green-600" /> : <Copy className="mr-2 h-5 w-5" />}
              Copy invite link
            </Button>
          </div>

          <Card className="bg-muted/30">
            <CardHeader>
              <CardTitle className="text-sm">Invite link</CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-sm font-mono text-muted-foreground break-all">
                {livestream.joinUrl}
              </p>
            </CardContent>
          </Card>
        </div>
      </div>

      <BuyEmailCreditsDialog
        open={buyCreditsOpen}
        onOpenChange={setBuyCreditsOpen}
        emailPackages={emailPackages}
        stripeMinCheckoutUsd={stripeMinCheckoutUsd}
        returnRoute="livestreams.supporter.ready"
        returnId={livestream.id}
      />
    </UnityMeetLayout>
  )
}
