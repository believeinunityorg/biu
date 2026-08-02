"use client"

import { useState } from "react"
import { Head, Link, router } from "@inertiajs/react"
import toast from "react-hot-toast"
import AppLayout from "@/layouts/app-layout"
import type { BreadcrumbItem } from "@/types"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar"
import { Input } from "@/components/ui/input"
import { CheckCircle2, Clock3, ExternalLink, Loader2, Mail, Settings2, UserMinus, UsersRound, XCircle } from "lucide-react"
import MembershipSettingsSection, {
  type MembershipSettingsPayload,
} from "@/components/membership/MembershipSettingsSection"
import {
  approveMembershipRequest,
  cancelMembershipInvitation,
  declineMembershipRequest,
  revokeMembershipMember,
  sendMembershipInvitation,
} from "@/lib/membership-actions"

type MembershipMemberRow = {
  id: number
  status: string
  status_label: string
  requested_at: string | null
  approved_at: string | null
  payment_status?: string | null
  payment_status_label?: string | null
  payment_amount?: string | null
  requires_payment?: boolean
  supporter: {
    id: number | null
    name: string
    email: string | null
    slug: string | null
    profile_url: string | null
  }
}

type MembershipInvitationRow = {
  id: number
  email: string
  status: string
  status_label: string
  expires_at: string | null
  accepted_at: string | null
  created_at: string | null
}

type MembershipManagementPayload = {
  account: {
    id: number
    name: string
    type: string
    memberships_enabled: boolean
  }
  counts: {
    pending: number
    verified: number
  }
  pending_members: MembershipMemberRow[]
  verified_members: MembershipMemberRow[]
  invitations?: MembershipInvitationRow[]
  stripe_ready?: boolean
  stripe_setup_url?: string | null
  stripe_setup_label?: string | null
  plan: MembershipSettingsPayload["plan"]
}

interface Props {
  membership: MembershipManagementPayload
  settingsBranding?: "default" | "alliance"
  membershipRoute: string
}

function planSummary(plan: MembershipManagementPayload["plan"]) {
  if (!plan) return "No membership plan configured yet."
  const fee =
    plan.membership_type === "paid" && plan.membership_fee
      ? `$${plan.membership_fee}${plan.billing_frequency ? ` / ${plan.billing_frequency}` : ""}`
      : "Free"
  return `${plan.membership_name} · ${fee} · ${plan.join_method.replace(/_/g, " ")}`
}

function formatDate(value: string | null) {
  if (!value) return "—"
  return new Date(value).toLocaleString(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  })
}

function supporterInitials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("")
}

function MemberTable({
  members,
  emptyMessage,
  dateLabel,
  dateField,
  mode,
}: {
  members: MembershipMemberRow[]
  emptyMessage: string
  dateLabel: string
  dateField: "requested_at" | "approved_at"
  mode: "pending" | "verified"
}) {
  const [busyId, setBusyId] = useState<number | null>(null)

  const runAction = async (id: number, action: () => Promise<{ message?: string }>) => {
    setBusyId(id)
    try {
      const result = await action()
      toast.success(result.message || "Membership updated.")
      router.reload({ only: ["membership"] })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Could not update membership.")
    } finally {
      setBusyId(null)
    }
  }

  if (members.length === 0) {
    return <p className="text-sm text-muted-foreground">{emptyMessage}</p>
  }

  return (
    <div className="overflow-x-auto rounded-md border">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Supporter</TableHead>
            <TableHead>Email</TableHead>
            <TableHead>{dateLabel}</TableHead>
            <TableHead>Payment</TableHead>
            <TableHead>Status</TableHead>
            <TableHead className="text-right">Actions</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {members.map((member) => {
            const isBusy = busyId === member.id

            return (
              <TableRow key={member.id}>
                <TableCell>
                  <div className="flex items-center gap-3">
                    <Avatar className="h-9 w-9">
                      <AvatarImage src={undefined} alt={member.supporter.name} />
                      <AvatarFallback>{supporterInitials(member.supporter.name)}</AvatarFallback>
                    </Avatar>
                    <span className="font-medium text-gray-900 dark:text-white">{member.supporter.name}</span>
                  </div>
                </TableCell>
                <TableCell className="text-muted-foreground">{member.supporter.email ?? "—"}</TableCell>
                <TableCell className="text-muted-foreground">{formatDate(member[dateField])}</TableCell>
                <TableCell className="text-muted-foreground">
                  {member.payment_status_label ?? "—"}
                  {member.requires_payment && mode === "pending" && (
                    <span className="block text-xs text-amber-600">Awaiting payment</span>
                  )}
                </TableCell>
                <TableCell>
                  <Badge variant={member.status === "verified" ? "default" : "secondary"}>{member.status_label}</Badge>
                </TableCell>
                <TableCell className="text-right">
                  <div className="flex flex-wrap items-center justify-end gap-2">
                    {member.supporter.profile_url && (
                      <Button variant="ghost" size="sm" asChild>
                        <Link href={member.supporter.profile_url}>
                          View
                          <ExternalLink className="ml-2 h-3.5 w-3.5" />
                        </Link>
                      </Button>
                    )}

                    {mode === "pending" && (
                      <>
                        <Button
                          size="sm"
                          disabled={isBusy || member.requires_payment}
                          onClick={() => runAction(member.id, () => approveMembershipRequest(member.id))}
                        >
                          {isBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="mr-1 h-4 w-4" />}
                          Approve
                        </Button>
                        <Button
                          size="sm"
                          variant="outline"
                          disabled={isBusy}
                          onClick={() => runAction(member.id, () => declineMembershipRequest(member.id))}
                        >
                          {isBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : <XCircle className="mr-1 h-4 w-4" />}
                          Decline
                        </Button>
                      </>
                    )}

                    {mode === "verified" && (
                      <Button
                        size="sm"
                        variant="destructive"
                        disabled={isBusy}
                        onClick={() => runAction(member.id, () => revokeMembershipMember(member.id))}
                      >
                        {isBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : <UserMinus className="mr-1 h-4 w-4" />}
                        Remove
                      </Button>
                    )}
                  </div>
                </TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
    </div>
  )
}

export default function MembershipIndex({
  membership,
  settingsBranding = "default",
  membershipRoute,
}: Props) {
  const isAlliance = settingsBranding === "alliance"
  const membershipsEnabled = membership.account.memberships_enabled
  const defaultTab = !membershipsEnabled
    ? "settings"
    : membership.counts.pending > 0
      ? "pending"
      : "verified"
  const breadcrumbs: BreadcrumbItem[] = [
    { title: "Dashboard", href: "/dashboard" },
    { title: "Membership", href: membershipRoute },
  ]

  const membershipSettingsPayload: MembershipSettingsPayload = {
    memberships_enabled: membership.account.memberships_enabled,
    account_type: membership.account.type,
    account_id: membership.account.id,
    plan: membership.plan,
  }

  const isInvitationOnly = membership.plan?.join_method === "invitation_only"
  const isPaidPlan = membership.plan?.membership_type === "paid"
  const pendingInvites = (membership.invitations ?? []).filter((row) => row.status === "pending")

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title="Membership" />

      <div className="space-y-6 p-4 sm:p-6">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Membership</h1>
            <p className="mt-1 text-gray-600 dark:text-gray-400">
              {isAlliance ? "Unity Impact Alliance" : membership.account.name} supporter membership foundation.
            </p>
          </div>
          {membershipsEnabled && (
            <Badge variant="secondary" className="w-fit">
              {planSummary(membership.plan)}
            </Badge>
          )}
        </div>

        {!membershipsEnabled && (
          <Card className="border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/20">
            <CardContent className="p-4 text-sm text-amber-900 dark:text-amber-100">
              Memberships are not enabled yet. Turn them on in the <strong>Membership Settings</strong> tab below, then
              save your plan details.
            </CardContent>
          </Card>
        )}

        <Tabs defaultValue={defaultTab} className="space-y-4">
          <TabsList
            className={
              isInvitationOnly
                ? "grid w-full grid-cols-1 gap-2 sm:grid-cols-4 sm:gap-0"
                : "grid w-full grid-cols-1 gap-2 sm:grid-cols-3 sm:gap-0"
            }
          >
            <TabsTrigger value="verified" className="gap-2" disabled={!membershipsEnabled}>
              <CheckCircle2 className="h-4 w-4" />
              Verified Members
              <Badge variant="outline">{membership.counts.verified}</Badge>
            </TabsTrigger>
            <TabsTrigger value="pending" className="gap-2" disabled={!membershipsEnabled}>
              <Clock3 className="h-4 w-4" />
              Pending Members
              <Badge variant="outline">{membership.counts.pending}</Badge>
            </TabsTrigger>
            {isInvitationOnly && (
              <TabsTrigger value="invitations" className="gap-2" disabled={!membershipsEnabled}>
                <Mail className="h-4 w-4" />
                Invitations
                <Badge variant="outline">{pendingInvites.length}</Badge>
              </TabsTrigger>
            )}
            <TabsTrigger value="settings" className="gap-2">
              <Settings2 className="h-4 w-4" />
              Membership Settings
            </TabsTrigger>
          </TabsList>

          <TabsContent value="verified">
            <Card>
              <CardHeader>
                <CardTitle>Verified Members</CardTitle>
                <CardDescription>Approved supporters. Use Remove to end a membership.</CardDescription>
              </CardHeader>
              <CardContent>
                <MemberTable
                  members={membership.verified_members ?? []}
                  emptyMessage="No verified members yet."
                  dateLabel="Approved"
                  dateField="approved_at"
                  mode="verified"
                />
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="pending">
            <Card>
              <CardHeader>
                <CardTitle>Pending Members</CardTitle>
                <CardDescription>Review supporter requests and approve or decline them.</CardDescription>
              </CardHeader>
              <CardContent>
                <MemberTable
                  members={membership.pending_members ?? []}
                  emptyMessage="No pending membership requests."
                  dateLabel="Requested"
                  dateField="requested_at"
                  mode="pending"
                />
              </CardContent>
            </Card>
          </TabsContent>

          {isInvitationOnly && (
            <TabsContent value="invitations">
              <InvitationsPanel
                accountType={membership.account.type}
                accountId={membership.account.id}
                invitations={membership.invitations ?? []}
              />
            </TabsContent>
          )}

          <TabsContent value="settings">
            {isPaidPlan && membership.stripe_ready === false && (
              <Card className="mb-4 border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/20">
                <CardContent className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                  <p className="text-sm text-amber-900 dark:text-amber-100">
                    Finish Stripe payout setup before supporters can pay for this membership plan. Use the same Stripe Connect account configured under payout settings.
                  </p>
                  <Button variant="outline" asChild>
                    <Link href={membership.stripe_setup_url ?? route("integrations.payout-settings")}>
                      {membership.stripe_setup_label ?? "Stripe payout settings"}
                    </Link>
                  </Button>
                </CardContent>
              </Card>
            )}
            <MembershipSettingsSection
              membership={membershipSettingsPayload}
              membershipRoute={membershipRoute}
              heading="Membership Settings"
              description="Turn memberships on or off, update plan details, and control how supporters join."
            />
          </TabsContent>
        </Tabs>
      </div>
    </AppLayout>
  )
}

function InvitationsPanel({
  accountType,
  accountId,
  invitations,
}: {
  accountType: string
  accountId: number
  invitations: MembershipInvitationRow[]
}) {
  const [email, setEmail] = useState("")
  const [submitting, setSubmitting] = useState(false)
  const [busyId, setBusyId] = useState<number | null>(null)

  const sendInvite = async (e: React.FormEvent) => {
    e.preventDefault()
    setSubmitting(true)
    try {
      const result = await sendMembershipInvitation(accountType, accountId, email.trim())
      toast.success(result.message || "Invitation sent.")
      setEmail("")
      router.reload({ only: ["membership"] })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Could not send invitation.")
    } finally {
      setSubmitting(false)
    }
  }

  const cancelInvite = async (id: number) => {
    setBusyId(id)
    try {
      const result = await cancelMembershipInvitation(id)
      toast.success(result.message || "Invitation cancelled.")
      router.reload({ only: ["membership"] })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Could not cancel invitation.")
    } finally {
      setBusyId(null)
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Membership Invitations</CardTitle>
        <CardDescription>
          Send invites to supporter emails. They must log in with the same email to accept on your public profile.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        <form onSubmit={sendInvite} className="flex flex-col gap-3 sm:flex-row">
          <Input
            type="email"
            placeholder="supporter@example.com"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
          <Button type="submit" disabled={submitting}>
            {submitting ? <Loader2 className="h-4 w-4 animate-spin" /> : "Send invitation"}
          </Button>
        </form>

        {invitations.length === 0 ? (
          <p className="text-sm text-muted-foreground">No invitations sent yet.</p>
        ) : (
          <div className="overflow-x-auto rounded-md border">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Email</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Expires</TableHead>
                  <TableHead className="text-right">Actions</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {invitations.map((invite) => (
                  <TableRow key={invite.id}>
                    <TableCell>{invite.email}</TableCell>
                    <TableCell>
                      <Badge variant={invite.status === "pending" ? "secondary" : "outline"}>{invite.status_label}</Badge>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{formatDate(invite.expires_at)}</TableCell>
                    <TableCell className="text-right">
                      {invite.status === "pending" && (
                        <Button size="sm" variant="outline" disabled={busyId === invite.id} onClick={() => cancelInvite(invite.id)}>
                          Cancel
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </div>
        )}
      </CardContent>
    </Card>
  )
}
