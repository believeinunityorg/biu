"use client"

import { useState } from "react"
import { Link, router } from "@inertiajs/react"
import toast from "react-hot-toast"
import { Badge } from "@/components/frontend/ui/badge"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { BadgeCheck, CheckCircle2, Clock3, CreditCard, Loader2, Mail, UsersRound } from "lucide-react"
import { cn } from "@/lib/utils"
import {
  acceptMembershipInvitation,
  membershipCheckoutUrl,
  submitMembershipRequest,
} from "@/lib/membership-actions"

export type MembershipJoinPayload = {
  account_type: string
  account_id: number
  account_name: string
  plan: {
    membership_name: string
    membership_type: string
    membership_fee: string | null
    billing_frequency: string | null
    join_method: string
    join_method_label: string
  }
  invitation_only: boolean
  requires_login: boolean
  is_supporter: boolean
  can_request: boolean
  can_accept_invitation?: boolean
  pending_invitation?: {
    id: number
    email: string
    expires_at: string | null
  } | null
  requires_payment?: boolean
  stripe_ready?: boolean
  stripe_setup_url?: string | null
  stripe_setup_label?: string | null
  payment_amount?: string | null
  payment_label?: string | null
  supporter_membership: {
    id: number
    status: string
    status_label: string
    requested_at: string | null
    payment_status?: string | null
    payment_status_label?: string | null
  } | null
}

interface MembershipJoinCardProps {
  membershipJoin: MembershipJoinPayload
  compact?: boolean
}

interface MembershipJoinButtonProps {
  membershipJoin: MembershipJoinPayload
  onOpenMembership?: () => void
  size?: "sm" | "default"
  className?: string
}

function feeLabel(plan: MembershipJoinPayload["plan"]) {
  if (plan.membership_type === "paid" && plan.membership_fee) {
    return `$${plan.membership_fee}${plan.billing_frequency ? ` / ${plan.billing_frequency}` : ""}`
  }
  return "Free"
}

function statusBadgeVariant(status: string): "default" | "secondary" | "outline" | "destructive" {
  if (status === "verified") return "default"
  if (status === "pending") return "secondary"
  if (status === "declined") return "destructive"
  return "outline"
}

async function handleMembershipFlow(membershipJoin: MembershipJoinPayload, acceptInvite = false) {
  const data = acceptInvite && membershipJoin.pending_invitation
    ? await acceptMembershipInvitation(membershipJoin.pending_invitation.id)
    : await submitMembershipRequest(membershipJoin.account_type, membershipJoin.account_id)

  if (data.checkout_url) {
    window.location.href = data.checkout_url
    return
  }

  toast.success(data.message || "Membership updated.")
  router.reload({ only: ["membershipJoin"] })
}

export function membershipRequestLabel(membershipJoin: MembershipJoinPayload): string {
  if (membershipJoin.requires_payment) {
    return membershipJoin.plan.join_method === "open_enrollment" ? "Join & pay" : "Apply & pay"
  }

  return membershipJoin.plan.join_method === "open_enrollment" ? "Join Membership" : "Apply for Membership"
}

export function membershipPrimaryActionLabel(membershipJoin: MembershipJoinPayload): string {
  const existing = membershipJoin.supporter_membership

  if (existing?.status === "verified") {
    return "Member"
  }

  if (existing?.status === "pending" && existing.payment_status === "unpaid") {
    return "Complete payment"
  }

  if (existing?.status === "pending") {
    return "Application pending"
  }

  if (membershipJoin.can_accept_invitation) {
    return membershipJoin.requires_payment ? "Accept invite & pay" : "Accept invitation"
  }

  if (membershipJoin.requires_login) {
    return membershipJoin.plan.join_method === "open_enrollment" ? "Log in to join" : "Log in to apply"
  }

  if (membershipJoin.invitation_only && !membershipJoin.can_accept_invitation) {
    return "Invitation only"
  }

  return membershipRequestLabel(membershipJoin)
}

export function MembershipJoinButton({
  membershipJoin,
  onOpenMembership,
  size = "sm",
  className,
}: MembershipJoinButtonProps) {
  const [submitting, setSubmitting] = useState(false)
  const label = membershipPrimaryActionLabel(membershipJoin)
  const existing = membershipJoin.supporter_membership

  const handleClick = async () => {
    if (existing?.status === "pending" && existing.payment_status === "unpaid" && existing.id) {
      window.location.href = membershipCheckoutUrl(existing.id)
      return
    }

    if (membershipJoin.can_accept_invitation) {
      setSubmitting(true)
      try {
        await handleMembershipFlow(membershipJoin, true)
      } catch (error) {
        toast.error(error instanceof Error ? error.message : "Could not accept invitation.")
      } finally {
        setSubmitting(false)
      }
      return
    }

    if (membershipJoin.can_request) {
      setSubmitting(true)
      try {
        await handleMembershipFlow(membershipJoin)
      } catch (error) {
        toast.error(error instanceof Error ? error.message : "Could not submit membership request.")
      } finally {
        setSubmitting(false)
      }
      return
    }

    onOpenMembership?.()
  }

  if (membershipJoin.requires_login) {
    return (
      <Button asChild size={size} className={cn("shrink-0", className)}>
        <Link href={route("login")}>
          <BadgeCheck className="mr-2 h-4 w-4" />
          {label}
        </Link>
      </Button>
    )
  }

  if (existing?.status === "verified") {
    return (
      <Button asChild size={size} variant="outline" className={cn("shrink-0", className)}>
        <Link href={route("profile.memberships")}>
          <CheckCircle2 className="mr-2 h-4 w-4" />
          {label}
        </Link>
      </Button>
    )
  }

  if ((existing?.status === "pending" && existing.payment_status !== "unpaid") || (membershipJoin.invitation_only && !membershipJoin.can_accept_invitation && !membershipJoin.can_request)) {
    return (
      <Button size={size} variant="outline" className={cn("shrink-0", className)} onClick={() => onOpenMembership?.()}>
        <Clock3 className="mr-2 h-4 w-4" />
        {label}
      </Button>
    )
  }

  const canAct = membershipJoin.can_request || membershipJoin.can_accept_invitation || (existing?.payment_status === "unpaid")

  return (
    <Button
      size={size}
      className={cn(
        "shrink-0 bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-700 hover:to-blue-700 text-white",
        className,
      )}
      onClick={handleClick}
      disabled={submitting || !canAct}
    >
      {submitting ? (
        <>
          <Loader2 className="mr-2 h-4 w-4 animate-spin" />
          Submitting…
        </>
      ) : (
        <>
          <BadgeCheck className="mr-2 h-4 w-4" />
          {label}
        </>
      )}
    </Button>
  )
}

export default function MembershipJoinCard({ membershipJoin, compact = false }: MembershipJoinCardProps) {
  const [submitting, setSubmitting] = useState(false)
  const existing = membershipJoin.supporter_membership
  const actionLabel = membershipJoin.can_accept_invitation
    ? membershipJoin.requires_payment
      ? "Accept invitation & pay"
      : "Accept invitation"
    : membershipRequestLabel(membershipJoin)

  const runPrimaryAction = async () => {
    if (existing?.status === "pending" && existing.payment_status === "unpaid" && existing.id) {
      window.location.href = membershipCheckoutUrl(existing.id)
      return
    }

    setSubmitting(true)
    try {
      await handleMembershipFlow(membershipJoin, Boolean(membershipJoin.can_accept_invitation))
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Could not update membership.")
    } finally {
      setSubmitting(false)
    }
  }

  const body = (
    <div className="space-y-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-lg font-semibold text-gray-900 dark:text-white">{membershipJoin.plan.membership_name}</p>
          <p className="mt-1 text-sm text-muted-foreground">
            {membershipJoin.payment_label ?? feeLabel(membershipJoin.plan)} · {membershipJoin.plan.join_method_label}
          </p>
        </div>
        {existing && (
          <div className="flex flex-wrap gap-2">
            <Badge variant={statusBadgeVariant(existing.status)} className="gap-1">
              {existing.status === "verified" ? <CheckCircle2 className="h-3.5 w-3.5" /> : <Clock3 className="h-3.5 w-3.5" />}
              {existing.status_label}
            </Badge>
            {existing.payment_status_label && (
              <Badge variant={existing.payment_status === "paid" ? "default" : "outline"}>{existing.payment_status_label}</Badge>
            )}
          </div>
        )}
      </div>

      {membershipJoin.requires_payment && membershipJoin.stripe_ready === false && (
        <p className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
          Paid memberships are not available yet because Stripe payout is not fully configured for {membershipJoin.account_name}.
          {membershipJoin.stripe_setup_url && (
            <>
              {" "}
              <Link href={membershipJoin.stripe_setup_url} className="font-medium underline">
                {membershipJoin.stripe_setup_label ?? "Open Stripe payout settings"}
              </Link>
            </>
          )}
        </p>
      )}

      {membershipJoin.invitation_only && !membershipJoin.can_accept_invitation && (
        <p className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
          <Mail className="mr-2 inline h-4 w-4" />
          This membership is invitation only. Ask {membershipJoin.account_name} to invite the email on your supporter account.
        </p>
      )}

      {membershipJoin.can_accept_invitation && membershipJoin.pending_invitation && (
        <p className="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-900 dark:border-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-100">
          <Mail className="mr-2 inline h-4 w-4" />
          You have a pending invitation for {membershipJoin.pending_invitation.email}.
        </p>
      )}

      {membershipJoin.requires_login && (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between rounded-lg border border-gray-200 dark:border-gray-700 px-4 py-3">
          <p className="text-sm text-gray-600 dark:text-gray-300">Log in with your supporter account to continue.</p>
          <Button asChild size="sm" className="shrink-0">
            <Link href={route("login")}>
              <BadgeCheck className="mr-2 h-4 w-4" />
              Log in
            </Link>
          </Button>
        </div>
      )}

      {!membershipJoin.requires_login && !membershipJoin.is_supporter && (
        <p className="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300">
          Membership is available for supporter accounts only.
        </p>
      )}

      {(membershipJoin.can_request || membershipJoin.can_accept_invitation || (existing?.payment_status === "unpaid" && existing?.id)) && (
        <Button onClick={runPrimaryAction} disabled={submitting} className="w-full sm:w-auto">
          {submitting ? (
            <>
              <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              Submitting…
            </>
          ) : (
            <>
              {membershipJoin.requires_payment || existing?.payment_status === "unpaid" ? (
                <CreditCard className="mr-2 h-4 w-4" />
              ) : (
                <BadgeCheck className="mr-2 h-4 w-4" />
              )}
              {existing?.payment_status === "unpaid" ? "Complete payment" : actionLabel}
            </>
          )}
        </Button>
      )}

      {existing?.status === "pending" && existing.payment_status === "paid" && (
        <p className="text-sm text-muted-foreground">Payment received. Your request is awaiting review.</p>
      )}

      {existing?.status === "verified" && (
        <p className="text-sm text-emerald-700 dark:text-emerald-300">You are a verified member of {membershipJoin.account_name}.</p>
      )}
    </div>
  )

  if (compact) {
    return body
  }

  return (
    <Card className="border-gray-200 dark:border-gray-800">
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-xl">
          <UsersRound className="h-5 w-5 text-primary" />
          Become a Member
        </CardTitle>
        <CardDescription>Join the membership program for {membershipJoin.account_name}.</CardDescription>
      </CardHeader>
      <CardContent>{body}</CardContent>
    </Card>
  )
}
