"use client"

import { Head, Link } from "@inertiajs/react"
import AppLayout from "@/layouts/app-layout"
import type { BreadcrumbItem } from "@/types"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs"
import { Badge } from "@/components/ui/badge"
import { Button } from "@/components/ui/button"
import { CheckCircle2, Clock3, Settings2, UsersRound } from "lucide-react"
import MembershipSettingsSection, {
  type MembershipSettingsPayload,
} from "@/components/membership/MembershipSettingsSection"

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
  plan: MembershipSettingsPayload["plan"]
}

interface Props {
  membership: MembershipManagementPayload
  settingsBranding?: "default" | "alliance"
  membershipRoute: string
  profileSettingsUrl: string
}

function planSummary(plan: MembershipManagementPayload["plan"]) {
  if (!plan) return "No membership plan configured yet."
  const fee =
    plan.membership_type === "paid" && plan.membership_fee
      ? `$${plan.membership_fee}${plan.billing_frequency ? ` / ${plan.billing_frequency}` : ""}`
      : "Free"
  return `${plan.membership_name} · ${fee} · ${plan.join_method.replace(/_/g, " ")}`
}

export default function MembershipIndex({
  membership,
  settingsBranding = "default",
  membershipRoute,
  profileSettingsUrl,
}: Props) {
  const isAlliance = settingsBranding === "alliance"
  const breadcrumbs: BreadcrumbItem[] = [
    { title: "Dashboard", href: "/dashboard" },
    { title: "Membership", href: membershipRoute },
  ]

  if (!membership.account.memberships_enabled) {
    return (
      <AppLayout breadcrumbs={breadcrumbs}>
        <Head title="Membership" />
        <div className="mx-auto max-w-3xl space-y-6 p-4 sm:p-6">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <UsersRound className="h-5 w-5" />
                Membership
              </CardTitle>
              <CardDescription>
                Memberships are not enabled for {membership.account.name}. Enable them in profile settings to manage
                members.
              </CardDescription>
            </CardHeader>
            <CardContent>
              <Button asChild>
                <Link href={profileSettingsUrl}>Go to profile settings</Link>
              </Button>
            </CardContent>
          </Card>
        </div>
      </AppLayout>
    )
  }

  const membershipSettingsPayload: MembershipSettingsPayload = {
    memberships_enabled: membership.account.memberships_enabled,
    account_type: membership.account.type,
    account_id: membership.account.id,
    plan: membership.plan,
  }

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
          <Badge variant="secondary" className="w-fit">
            {planSummary(membership.plan)}
          </Badge>
        </div>

        <Tabs defaultValue="verified" className="space-y-4">
          <TabsList className="grid w-full grid-cols-1 gap-2 sm:grid-cols-3 sm:gap-0">
            <TabsTrigger value="verified" className="gap-2">
              <CheckCircle2 className="h-4 w-4" />
              Verified Members
              <Badge variant="outline">{membership.counts.verified}</Badge>
            </TabsTrigger>
            <TabsTrigger value="pending" className="gap-2">
              <Clock3 className="h-4 w-4" />
              Pending Members
              <Badge variant="outline">{membership.counts.pending}</Badge>
            </TabsTrigger>
            <TabsTrigger value="settings" className="gap-2">
              <Settings2 className="h-4 w-4" />
              Membership Settings
            </TabsTrigger>
          </TabsList>

          <TabsContent value="verified">
            <Card>
              <CardHeader>
                <CardTitle>Verified Members</CardTitle>
                <CardDescription>
                  Approved supporters will appear here. Verification workflows will be added in a later phase.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <p className="text-sm text-muted-foreground">
                  {membership.counts.verified === 0
                    ? "No verified members yet."
                    : `${membership.counts.verified} verified member(s) on record.`}
                </p>
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="pending">
            <Card>
              <CardHeader>
                <CardTitle>Pending Members</CardTitle>
                <CardDescription>
                  Supporter requests awaiting review will appear here. Approval workflows will be added in a later
                  phase.
                </CardDescription>
              </CardHeader>
              <CardContent>
                <p className="text-sm text-muted-foreground">
                  {membership.counts.pending === 0
                    ? "No pending membership requests."
                    : `${membership.counts.pending} pending request(s) on record.`}
                </p>
              </CardContent>
            </Card>
          </TabsContent>

          <TabsContent value="settings">
            <MembershipSettingsSection
              membership={membershipSettingsPayload}
              membershipRoute={membershipRoute}
              heading="Membership Settings"
              description="Update membership plan details. Changes apply to new join requests."
            />
          </TabsContent>
        </Tabs>
      </div>
    </AppLayout>
  )
}
