"use client"

import { Head, Link } from "@inertiajs/react"
import AppLayout from "@/layouts/app-layout"
import type { BreadcrumbItem } from "@/types"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Button } from "@/components/frontend/ui/button"
import MembershipSettingsSection, {
  type MembershipSettingsPayload,
} from "@/components/membership/MembershipSettingsSection"
import { ArrowLeft, UsersRound } from "lucide-react"

interface Props {
  group: {
    id: number
    name: string
    slug: string
    description: string | null
  }
  membership: MembershipSettingsPayload
  membershipRoute: string
}

export default function GroupProfileSettings({ group, membership, membershipRoute }: Props) {
  const breadcrumbs: BreadcrumbItem[] = [
    { title: "Groups", href: route("groups.show", group.slug) },
    { title: "Profile Settings", href: route("groups.profile.edit", group.slug) },
  ]

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={`${group.name} — Profile Settings`} />

      <div className="w-full space-y-6 p-4 sm:p-6">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h1 className="text-3xl font-bold text-gray-900 dark:text-white">Group Profile</h1>
            <p className="mt-1 text-gray-600 dark:text-gray-400">{group.name}</p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button variant="outline" asChild>
              <Link href={route("groups.show", group.slug)}>
                <ArrowLeft className="mr-2 h-4 w-4" />
                Back to group
              </Link>
            </Button>
            {membership.memberships_enabled && (
              <Button asChild>
                <Link href={membershipRoute}>
                  <UsersRound className="mr-2 h-4 w-4" />
                  Membership
                </Link>
              </Button>
            )}
          </div>
        </div>

        <Card>
          <CardHeader>
            <CardTitle>Group details</CardTitle>
            <CardDescription>Basic group information shown on the public profile.</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-sm font-medium text-gray-900 dark:text-white">{group.name}</p>
            <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
              {group.description || "No description provided."}
            </p>
          </CardContent>
        </Card>

        <MembershipSettingsSection
          membership={membership}
          membershipRoute={membershipRoute}
          heading="Group Memberships"
          description="Enable and configure supporter memberships for this group."
        />
      </div>
    </AppLayout>
  )
}
