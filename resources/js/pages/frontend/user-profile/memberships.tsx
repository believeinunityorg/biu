"use client"

import { useState } from "react"
import ProfileLayout from "@/components/frontend/layout/user-profile-layout"
import { Badge } from "@/components/frontend/ui/badge"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent } from "@/components/frontend/ui/card"
import { Link, router, usePage } from "@inertiajs/react"
import toast from "react-hot-toast"
import { BadgeCheck, Building2, Calendar, ExternalLink, Loader2, UsersRound } from "lucide-react"
import { cancelSupporterMembership, membershipCheckoutUrl } from "@/lib/membership-actions"

interface MembershipRow {
  id: number
  status: string
  status_label: string
  requested_at: string | null
  approved_at: string | null
  account_type: string | null
  account_id: number
  account_name: string
  membership_name: string | null
  profile_url: string | null
  can_cancel?: boolean
  requires_payment?: boolean
  payment_status?: string | null
  payment_status_label?: string | null
  cancel_label?: string | null
}

interface PageProps {
  memberships: MembershipRow[]
}

function statusVariant(status: string): "default" | "secondary" | "outline" | "destructive" {
  if (status === "verified") return "default"
  if (status === "pending") return "secondary"
  if (status === "declined") return "destructive"
  return "outline"
}

function accountTypeLabel(accountType: string | null): string {
  if (accountType === "UnityImpactAlliance") return "Unity Impact Alliance"
  if (accountType === "Group") return "Group"
  return "Organization"
}

export default function ProfileMemberships() {
  const { memberships } = usePage<PageProps>().props
  const [busyId, setBusyId] = useState<number | null>(null)

  const handleCancel = async (membership: MembershipRow) => {
    setBusyId(membership.id)
    try {
      const result = await cancelSupporterMembership(membership.id)
      toast.success(result.message || "Membership updated.")
      router.reload({ only: ["memberships"] })
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Could not update membership.")
    } finally {
      setBusyId(null)
    }
  }

  return (
    <ProfileLayout
      title="My Memberships"
      description="Membership requests and verified memberships across organizations, alliances, and groups."
    >
      <div className="space-y-6">
        {memberships.length === 0 ? (
          <Card className="border-dashed">
            <CardContent className="flex flex-col items-center justify-center px-6 py-16 text-center">
              <BadgeCheck className="mb-4 h-12 w-12 text-muted-foreground" />
              <h2 className="text-lg font-semibold text-gray-900 dark:text-white">No memberships yet</h2>
              <p className="mt-2 max-w-md text-sm text-muted-foreground">
                Visit an organization, Unity Impact Alliance, or group profile and open the Membership tab to request
                or join a membership program.
              </p>
            </CardContent>
          </Card>
        ) : (
          <div className="space-y-4">
            {memberships.map((membership) => (
              <Card key={membership.id}>
                <CardContent className="flex flex-col gap-4 p-6 sm:flex-row sm:items-start sm:justify-between">
                  <div className="space-y-2">
                    <div className="flex flex-wrap items-center gap-2">
                      <Badge variant="outline" className="gap-1">
                        {membership.account_type === "Group" ? (
                          <UsersRound className="h-3 w-3" />
                        ) : (
                          <Building2 className="h-3 w-3" />
                        )}
                        {accountTypeLabel(membership.account_type)}
                      </Badge>
                      <Badge variant={statusVariant(membership.status)}>{membership.status_label}</Badge>
                    </div>
                    <h3 className="text-lg font-semibold text-gray-900 dark:text-white">{membership.account_name}</h3>
                    {membership.membership_name && (
                      <p className="text-sm text-muted-foreground">Plan: {membership.membership_name}</p>
                    )}
                    {membership.requested_at && (
                      <p className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Calendar className="h-4 w-4" />
                        Requested {new Date(membership.requested_at).toLocaleDateString()}
                      </p>
                    )}
                  </div>

                  <div className="flex flex-wrap gap-2 shrink-0">
                    {membership.requires_payment && (
                      <Button variant="outline" asChild>
                        <Link href={membershipCheckoutUrl(membership.id)}>Complete payment</Link>
                      </Button>
                    )}
                    {membership.profile_url && (
                      <Button variant="outline" asChild>
                        <Link href={membership.profile_url}>
                          View profile
                          <ExternalLink className="ml-2 h-4 w-4" />
                        </Link>
                      </Button>
                    )}
                    {membership.can_cancel && membership.cancel_label && (
                      <Button
                        variant="destructive"
                        disabled={busyId === membership.id}
                        onClick={() => handleCancel(membership)}
                      >
                        {busyId === membership.id ? (
                          <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        ) : null}
                        {membership.cancel_label}
                      </Button>
                    )}
                  </div>
                </CardContent>
              </Card>
            ))}
          </div>
        )}
      </div>
    </ProfileLayout>
  )
}
