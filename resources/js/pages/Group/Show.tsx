"use client"

import { Head, Link } from "@inertiajs/react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Badge } from "@/components/ui/badge"
import { Building2, Settings, UsersRound } from "lucide-react"

interface Props {
  group: {
    id: number
    name: string
    slug: string
    description: string | null
    memberships_enabled: boolean
    parent: { type: string; name: string | null } | null
  }
  canManage: boolean
  membershipRoute: string | null
}

export default function GroupShow({ group, canManage, membershipRoute }: Props) {
  const profileTabs = [
    { name: "About", active: true },
    ...(group.memberships_enabled ? [{ name: "Membership", active: false }] : []),
  ]

  return (
    <FrontendLayout>
      <Head title={group.name} />

      <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6">
        <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <div className="mb-2 flex flex-wrap items-center gap-2">
              <Badge variant="outline" className="gap-1">
                <UsersRound className="h-3 w-3" />
                Group
              </Badge>
              {group.parent?.name && (
                <Badge variant="secondary" className="gap-1">
                  <Building2 className="h-3 w-3" />
                  {group.parent.name}
                </Badge>
              )}
            </div>
            <h1 className="text-3xl font-bold text-gray-900 dark:text-white">{group.name}</h1>
            {group.description && (
              <p className="mt-3 max-w-3xl text-gray-600 dark:text-gray-300">{group.description}</p>
            )}
          </div>

          {canManage && (
            <div className="flex flex-wrap gap-2">
              <Button variant="outline" asChild>
                <Link href={route("groups.profile.edit", group.slug)}>
                  <Settings className="mr-2 h-4 w-4" />
                  Group profile
                </Link>
              </Button>
              {membershipRoute && (
                <Button asChild>
                  <Link href={membershipRoute}>Membership</Link>
                </Button>
              )}
            </div>
          )}
        </div>

        <div className="mb-6 flex flex-wrap gap-2 border-b border-gray-200 pb-2 dark:border-gray-700">
          {profileTabs.map((tab) => (
            <button
              key={tab.name}
              type="button"
              className={`rounded-md px-3 py-2 text-sm font-medium ${
                tab.active
                  ? "bg-primary/10 text-primary"
                  : "text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800"
              }`}
            >
              {tab.name}
            </button>
          ))}
        </div>

        <Card>
          <CardHeader>
            <CardTitle>About this group</CardTitle>
            <CardDescription>
              Groups under organizations and Unity Impact Alliances can offer supporter memberships when enabled.
            </CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-sm text-muted-foreground">
              {group.description || "No description has been added for this group yet."}
            </p>
          </CardContent>
        </Card>
      </div>
    </FrontendLayout>
  )
}
