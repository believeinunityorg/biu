"use client"

import { useState } from "react"
import { Head, Link, router } from "@inertiajs/react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import CommunityComposer from "@/components/community/CommunityComposer"
import CommunityFeedList from "@/components/community/CommunityFeedList"
import GroupInviteRecipientField from "@/components/community/GroupInviteRecipientField"
import ReportDialog from "@/components/community/ReportDialog"
import MembershipJoinCard, {
  type MembershipJoinPayload,
  MembershipJoinButton,
} from "@/components/membership/MembershipJoinCard"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Badge } from "@/components/ui/badge"
import {
  Building2,
  Calendar,
  FileText,
  Image as ImageIcon,
  Megaphone,
  MessageSquare,
  Settings,
  Users,
  UsersRound,
  Video,
} from "lucide-react"

type ContentItem = {
  id: number
  slug?: string
  title: string
  body: string
  category: string | null
  is_pinned: boolean
  is_locked: boolean
  published_at: string | null
  replies_count: number
  author: { id: number; name: string } | null
}

type Member = {
  id: number
  role: string
  joined_at: string | null
  posting_suspended: boolean
  user: { id: number; name: string; email: string } | null
}

type Props = {
  group: {
    id: number
    name: string
    slug: string
    description: string | null
    cover_image: string | null
    category: string | null
    memberships_enabled: boolean
    is_featured: boolean
    is_pinned: boolean
    parent: { type: string; id: number; name: string | null } | null
    members_count: number
  }
  tab: string
  membership: { role: string; posting_suspended: boolean } | null
  canManage: boolean
  canModerate: boolean
  canJoin: boolean
  canLeave: boolean
  canCreateDiscussion: boolean
  canCreateAnnouncement: boolean
  canFeatureOnParent: boolean
  discussions: ContentItem[]
  announcements: ContentItem[]
  members: Member[]
  membershipJoin?: MembershipJoinPayload | null
  membershipRoute: string | null
  reportReasons: Record<string, string>
}

const tabs = [
  { key: "discussion", label: "Discussion", icon: MessageSquare },
  { key: "announcements", label: "Announcements", icon: Megaphone },
  { key: "about", label: "About", icon: FileText },
  { key: "members", label: "Members", icon: Users },
  { key: "events", label: "Events", icon: Calendar },
  { key: "photos", label: "Photos", icon: ImageIcon },
  { key: "videos", label: "Videos", icon: Video },
  { key: "files", label: "Files", icon: FileText },
] as const

const contentCategories = [
  "General",
  "Prayer",
  "Bible Study",
  "Events",
  "Volunteering",
  "Support",
  "Other",
]

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("")
}

function ComingSoonTab({ title, description }: { title: string; description: string }) {
  return (
    <Card className="border-dashed border-gray-300 bg-white shadow-none dark:border-white/15 dark:bg-[#111827]">
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
      <CardContent className="pb-8 text-sm text-muted-foreground">
        Coming soon â€” this tab is reserved for a later release.
      </CardContent>
    </Card>
  )
}

export default function GroupShow({
  group,
  tab,
  membership,
  canManage,
  canModerate,
  canJoin,
  canLeave,
  canCreateDiscussion,
  canCreateAnnouncement,
  discussions,
  announcements,
  members,
  membershipJoin = null,
  membershipRoute,
  reportReasons,
}: Props) {
  const [inviteSubmitting, setInviteSubmitting] = useState(false)

  const setTab = (key: string) => {
    router.get(route("groups.show", group.slug), { tab: key }, { preserveState: true, preserveScroll: true })
  }

  const feedItems = tab === "discussion" ? discussions : announcements
  const shortDescription =
    group.description?.trim() ||
    (group.parent?.name ? `A community group under ${group.parent.name}.` : "A Believe In Unity community group.")

  return (
    <FrontendLayout>
      <Head title={group.name} />

      <div className="min-h-screen bg-gray-50 text-gray-900 dark:bg-[#0a0f1a] dark:text-white">
        {/* Hero: full-bleed cover + identity (avatar left, details right) */}
        <div className="relative">
          <div className="relative h-36 w-full sm:h-44 md:h-52">
            <div className="absolute inset-0 overflow-hidden">
              {group.cover_image ? (
                <img src={group.cover_image} alt="" className="h-full w-full object-cover" />
              ) : (
                <div className="h-full w-full bg-gradient-to-r from-purple-900 via-blue-800 to-purple-900" />
              )}
              <div className="absolute inset-0 bg-gradient-to-t from-[#0a0f1a]/70 via-transparent to-transparent dark:from-[#0a0f1a]/90" />
            </div>

            {(group.is_featured || group.is_pinned) && (
              <div className="container absolute inset-x-0 top-3 z-10 mx-auto flex justify-end gap-2 px-4">
                {group.is_featured && (
                  <Badge className="border-0 bg-white/95 text-purple-700 shadow-sm dark:bg-[#111827] dark:text-purple-200">
                    Featured
                  </Badge>
                )}
                {group.is_pinned && (
                  <Badge className="border-0 bg-white/95 text-blue-700 shadow-sm dark:bg-[#111827] dark:text-blue-200">
                    Pinned
                  </Badge>
                )}
              </div>
            )}
          </div>

          <div className="relative z-20 border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-[#0a0f1a]">
            <div className="container mx-auto px-4">
              <div className="flex flex-col gap-4 pb-4 sm:gap-5">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                  <div className="flex min-w-0 items-center gap-3 sm:gap-4">
                    {/* Outside cover overflow so it is never clipped; sits left of name */}
                    <div className="relative z-30 -mt-12 flex h-24 w-24 shrink-0 items-center justify-center rounded-2xl border-4 border-gray-50 bg-gradient-to-br from-purple-600 to-blue-600 text-2xl font-semibold tracking-tight text-white shadow-lg ring-2 ring-purple-500/30 dark:border-[#0a0f1a] sm:-mt-14 sm:h-28 sm:w-28 sm:text-3xl">
                      {initials(group.name) || <UsersRound className="h-10 w-10" />}
                    </div>

                    <div className="min-w-0 flex-1 -mt-6 sm:-mt-8">
                      <div className="mb-1.5 flex flex-wrap items-center gap-2">
                        <Badge className="gap-1 border-0 bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-600 hover:to-blue-600">
                          <UsersRound className="h-3 w-3" />
                          Group
                        </Badge>
                        {group.parent?.name && (
                          <Badge className="gap-1 border-0 bg-gray-200 text-gray-800 dark:bg-white/10 dark:text-gray-100">
                            <Building2 className="h-3 w-3" />
                            {group.parent.name}
                          </Badge>
                        )}
                        {group.category && (
                          <Badge className="border border-purple-500/30 bg-purple-500/10 text-purple-700 dark:text-purple-200">
                            {group.category}
                          </Badge>
                        )}
                      </div>
                      <h1 className="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">
                        {group.name}
                      </h1>
                      <p className="mt-1 max-w-2xl text-sm leading-relaxed text-gray-600 dark:text-gray-400 line-clamp-2">
                        {shortDescription}
                      </p>
                    </div>
                  </div>

                  <div className="flex flex-wrap gap-2 sm:justify-end sm:pb-1">
                    {canJoin && (
                      <Button
                        className="cursor-pointer bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-700 hover:to-blue-700"
                        onClick={() => router.post(route("groups.join", group.slug))}
                      >
                        Join Group
                      </Button>
                    )}
                    {canLeave && (
                      <Button
                        variant="outline"
                        className="cursor-pointer border-gray-300 bg-white dark:border-white/20 dark:bg-white/10"
                        onClick={() => router.post(route("groups.leave", group.slug))}
                      >
                        Leave
                      </Button>
                    )}
                    {canManage && (
                      <Button
                        variant="outline"
                        asChild
                        className="cursor-pointer border-gray-300 bg-white dark:border-white/20 dark:bg-white/10"
                      >
                        <Link href={route("groups.settings", group.slug)}>
                          <Settings className="mr-2 h-4 w-4" />
                          Settings
                        </Link>
                      </Button>
                    )}
                    {membershipRoute && canManage && (
                      <Button
                        asChild
                        variant="outline"
                        className="cursor-pointer border-gray-300 bg-white dark:border-white/20 dark:bg-white/10"
                      >
                        <Link href={membershipRoute}>Paid membership</Link>
                      </Button>
                    )}
                    {!canManage && membershipJoin && (
                      <MembershipJoinButton
                        membershipJoin={membershipJoin}
                        onOpenMembership={() => setTab("about")}
                      />
                    )}
                    <ReportDialog
                      reportableType="Group"
                      reportableId={group.id}
                      reportReasons={reportReasons}
                      triggerLabel="Report"
                    />
                  </div>
                </div>

                <div className="flex flex-wrap items-center gap-x-5 gap-y-1 border-t border-gray-200 py-3 text-sm text-gray-600 dark:border-white/10 dark:text-gray-400">
                  <span className="inline-flex items-center gap-1.5">
                    <Users className="h-4 w-4" />
                    <span className="font-medium text-gray-900 dark:text-white">{group.members_count}</span>
                    members
                  </span>
                  {membership && (
                    <span>
                      Your role:{" "}
                      <span className="font-medium capitalize text-gray-900 dark:text-white">
                        {membership.role}
                      </span>
                      {membership.posting_suspended && " Â· posting suspended"}
                    </span>
                  )}
                </div>
              </div>

              <nav className="-mb-px flex gap-1 overflow-x-auto" aria-label="Group sections">
                {tabs.map((t) => {
                  const Icon = t.icon
                  const active = tab === t.key
                  return (
                    <button
                      key={t.key}
                      type="button"
                      onClick={() => setTab(t.key)}
                      className={`relative flex shrink-0 cursor-pointer items-center gap-2 px-3 py-3 text-sm font-medium transition-colors sm:px-4 ${
                        active
                          ? "text-purple-600 dark:text-purple-300"
                          : "text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white"
                      }`}
                    >
                      <Icon className="h-4 w-4" />
                      {t.label}
                      {active && (
                        <span className="absolute inset-x-2 bottom-0 h-0.5 rounded-full bg-gradient-to-r from-purple-600 to-blue-600" />
                      )}
                    </button>
                  )
                })}
              </nav>
            </div>
          </div>
        </div>

        <div className="container mx-auto px-4 py-6 pb-16">
          {/* Content */}
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
            <div className="space-y-4 lg:col-span-8">
              {(tab === "discussion" || tab === "announcements") && (
                <>
                  <CommunityComposer
                    parentType="Group"
                    parentId={group.id}
                    type={tab === "announcements" ? "announcement" : "discussion"}
                    canCreate={
                      tab === "announcements" ? canCreateAnnouncement : canCreateDiscussion
                    }
                    categories={contentCategories}
                  />
                  <CommunityFeedList
                    items={feedItems}
                    emptyLabel={tab === "announcements" ? "announcements" : "discussions"}
                    reportReasons={reportReasons}
                    canModerate={canModerate}
                  />
                </>
              )}

              {tab === "about" && (
                <Card className="border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#111827]">
                  <CardHeader>
                    <CardTitle>About this group</CardTitle>
                    <CardDescription>Purpose, membership, and how to get involved.</CardDescription>
                  </CardHeader>
                  <CardContent className="space-y-5">
                    <p className="whitespace-pre-wrap text-sm leading-relaxed text-gray-600 dark:text-gray-300">
                      {group.description || "No description yet."}
                    </p>
                    {membershipJoin && <MembershipJoinCard membershipJoin={membershipJoin} />}
                    {membership && (
                      <div className="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm dark:border-white/10 dark:bg-white/5">
                        Your role: <strong className="capitalize">{membership.role}</strong>
                        {membership.posting_suspended && " (posting suspended)"}
                      </div>
                    )}
                  </CardContent>
                </Card>
              )}

              {tab === "members" && (
                <Card className="border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#111827]">
                  <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                      <Users className="h-5 w-5 text-purple-600" /> Members
                    </CardTitle>
                    <CardDescription>
                      {group.members_count} {group.members_count === 1 ? "person" : "people"} in this group
                    </CardDescription>
                  </CardHeader>
                  <CardContent className="space-y-4">
                    {canModerate && (
                      <div className="rounded-xl border border-gray-200 bg-gray-50 p-3 dark:border-white/10 dark:bg-white/5">
                        <GroupInviteRecipientField
                          groupSlug={group.slug}
                          submitting={inviteSubmitting}
                          onInvite={(payload) => {
                            setInviteSubmitting(true)
                            router.post(route("groups.invite", group.slug), payload, {
                              preserveScroll: true,
                              onFinish: () => setInviteSubmitting(false),
                            })
                          }}
                        />
                      </div>
                    )}
                    <div className="divide-y divide-gray-100 dark:divide-white/10">
                      {members.map((m) => (
                        <div
                          key={m.id}
                          className="flex flex-col gap-3 py-3.5 sm:flex-row sm:items-center sm:justify-between"
                        >
                          <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-purple-600/15 to-blue-600/15 text-sm font-semibold text-purple-700 dark:from-purple-500/20 dark:to-blue-500/20 dark:text-purple-200">
                              {initials(m.user?.name || "?") || "?"}
                            </div>
                            <div>
                              <p className="font-medium text-gray-900 dark:text-white">{m.user?.name}</p>
                              <p className="text-xs capitalize text-muted-foreground">
                                {m.role}
                                {m.posting_suspended ? " Â· posting suspended" : ""}
                              </p>
                            </div>
                          </div>
                          {(canManage || canModerate) && m.user && (
                            <div className="flex flex-wrap gap-2">
                              {canManage && m.role !== "moderator" && m.role !== "admin" && (
                                <Button
                                  size="sm"
                                  variant="outline"
                                  className="cursor-pointer"
                                  onClick={() =>
                                    router.post(route("groups.members.role", [group.slug, m.id]), {
                                      role: "moderator",
                                    })
                                  }
                                >
                                  Make moderator
                                </Button>
                              )}
                              {canManage && m.role === "moderator" && (
                                <Button
                                  size="sm"
                                  variant="outline"
                                  className="cursor-pointer"
                                  onClick={() =>
                                    router.post(route("groups.members.role", [group.slug, m.id]), {
                                      role: "member",
                                    })
                                  }
                                >
                                  Demote
                                </Button>
                              )}
                              {canModerate && m.role !== "admin" && (
                                <Button
                                  size="sm"
                                  variant="outline"
                                  className="cursor-pointer"
                                  onClick={() =>
                                    router.post(route("groups.members.suspend", [group.slug, m.id]), {
                                      suspend: !m.posting_suspended,
                                      days: 7,
                                      reason: m.posting_suspended
                                        ? "Posting restored by moderator"
                                        : "Posting suspended by moderator",
                                    })
                                  }
                                >
                                  {m.posting_suspended ? "Restore posting" : "Suspend posting"}
                                </Button>
                              )}
                              {canManage && (
                                <Button
                                  size="sm"
                                  variant="destructive"
                                  className="cursor-pointer"
                                  onClick={() => router.delete(route("groups.members.remove", [group.slug, m.id]))}
                                >
                                  Remove
                                </Button>
                              )}
                            </div>
                          )}
                        </div>
                      ))}
                    </div>
                  </CardContent>
                </Card>
              )}

              {tab === "events" && (
                <ComingSoonTab
                  title="Events"
                  description="Group gatherings and activities will live here in a later release."
                />
              )}

              {tab === "photos" && (
                <ComingSoonTab
                  title="Photos"
                  description="A shared photo gallery for this group is coming soon."
                />
              )}

              {tab === "videos" && (
                <ComingSoonTab
                  title="Videos"
                  description="Group video sharing will be available in a later release."
                />
              )}

              {tab === "files" && (
                <ComingSoonTab
                  title="Files"
                  description="Shared documents and resources are planned for a later release."
                />
              )}
            </div>

            {/* Sidebar */}
            <aside className="space-y-4 lg:col-span-4">
              <Card className="border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#111827]">
                <CardHeader className="pb-3">
                  <CardTitle className="text-base">About</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3 text-sm">
                  <p className="leading-relaxed text-muted-foreground line-clamp-5">
                    {group.description || "No description yet."}
                  </p>
                  {tab !== "about" && (
                    <button
                      type="button"
                      onClick={() => setTab("about")}
                      className="cursor-pointer text-sm font-medium text-purple-600 hover:underline dark:text-purple-300"
                    >
                      View full about
                    </button>
                  )}
                  <dl className="space-y-2 border-t border-gray-100 pt-3 dark:border-white/10">
                    {group.parent?.name && (
                      <div className="flex justify-between gap-3">
                        <dt className="text-muted-foreground">Hosted by</dt>
                        <dd className="text-right font-medium text-gray-800 dark:text-gray-100">
                          {group.parent.name}
                        </dd>
                      </div>
                    )}
                    {group.category && (
                      <div className="flex justify-between gap-3">
                        <dt className="text-muted-foreground">Category</dt>
                        <dd className="text-right font-medium text-gray-800 dark:text-gray-100">
                          {group.category}
                        </dd>
                      </div>
                    )}
                    <div className="flex justify-between gap-3">
                      <dt className="text-muted-foreground">Members</dt>
                      <dd className="text-right font-medium text-gray-800 dark:text-gray-100">
                        {group.members_count}
                      </dd>
                    </div>
                  </dl>
                </CardContent>
              </Card>

              {members.length > 0 && (
                <Card className="border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#111827]">
                  <CardHeader className="pb-3">
                    <div className="flex items-center justify-between gap-2">
                      <CardTitle className="text-base">Members</CardTitle>
                      <button
                        type="button"
                        onClick={() => setTab("members")}
                        className="cursor-pointer text-xs font-medium text-purple-600 hover:underline dark:text-purple-300"
                      >
                        See all
                      </button>
                    </div>
                  </CardHeader>
                  <CardContent>
                    <div className="flex -space-x-2">
                      {members.slice(0, 8).map((m) => (
                        <div
                          key={m.id}
                          title={m.user?.name}
                          className="flex h-9 w-9 items-center justify-center rounded-full border-2 border-white bg-gradient-to-br from-purple-100 to-blue-100 text-[10px] font-semibold text-purple-700 dark:border-[#111827] dark:from-purple-500/30 dark:to-blue-500/30 dark:text-purple-100"
                        >
                          {initials(m.user?.name || "?") || "?"}
                        </div>
                      ))}
                      {group.members_count > 8 && (
                        <div className="flex h-9 w-9 items-center justify-center rounded-full border-2 border-white bg-gray-100 text-[10px] font-semibold text-gray-600 dark:border-[#111827] dark:bg-white/10 dark:text-gray-200">
                          +{group.members_count - 8}
                        </div>
                      )}
                    </div>
                  </CardContent>
                </Card>
              )}

              {membershipJoin && tab !== "about" && (
                <MembershipJoinCard membershipJoin={membershipJoin} compact />
              )}
            </aside>
          </div>
        </div>
      </div>
    </FrontendLayout>
  )
}
