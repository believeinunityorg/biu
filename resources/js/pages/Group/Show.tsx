"use client"

import { useRef, useState, type ButtonHTMLAttributes, type ChangeEvent, type ReactNode } from "react"
import { Head, Link, router } from "@inertiajs/react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import CommunityComposer from "@/components/community/CommunityComposer"
import CommunityFeedList, { type CommunityFeedItem } from "@/components/community/CommunityFeedList"
import GroupDirectoryCard, {
  type GroupDirectoryCardData,
} from "@/components/community/GroupDirectoryCard"
import GroupInviteRecipientField from "@/components/community/GroupInviteRecipientField"
import GroupPollsPanel, { type GroupPollItem } from "@/components/community/GroupPollsPanel"
import {
  GroupEventsPanel,
  GroupLibraryPanel,
  type GroupEventItem,
  type GroupLibraryItem,
} from "@/components/community/GroupLibraryPanels"
import ReportDialog from "@/components/community/ReportDialog"
import MembershipJoinCard, {
  type MembershipJoinPayload,
  MembershipJoinButton,
} from "@/components/membership/MembershipJoinCard"
import { Button } from "@/components/frontend/ui/button"
import { Badge } from "@/components/ui/badge"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu"
import {
  BarChart3,
  Building2,
  Calendar,
  Camera,
  ChevronDown,
  Copy,
  FileText,
  Globe2,
  Image as ImageIcon,
  Lock,
  Loader2,
  Mail,
  Megaphone,
  MessageSquare,
  Send,
  Settings,
  UserPlus,
  Users,
  UsersRound,
  Video,
} from "lucide-react"

type Member = {
  id: number
  role: string
  joined_at: string | null
  posting_suspended: boolean
  user: { id: number; name: string; email: string } | null
}

type PendingInvite = {
  id: number
  email: string | null
  status: string
  created_at: string | null
  accept_url: string
  user: { id: number; name: string; email: string } | null
  inviter: { id: number; name: string } | null
}

type RecentGroup = GroupDirectoryCardData & {
  created_at: string | null
}

type Props = {
  group: {
    id: number
    name: string
    slug: string
    description: string | null
    cover_image: string | null
    icon_image: string | null
    category: string | null
    visibility: string
    join_policy: string
    posting_policy: string
    posting_policies?: string[]
    rules: string[]
    allow_photos: boolean
    allow_videos: boolean
    allow_documents: boolean
    allow_polls: boolean
    allow_events: boolean
    memberships_enabled: boolean
    is_featured: boolean
    is_pinned: boolean
    parent: { type: string; id: number; name: string | null } | null
    members_count: number
  }
  tab: string
  membership: { role: string; posting_suspended: boolean; status?: string } | null
  canManage: boolean
  canModerate: boolean
  canJoin: boolean
  joinBlockedReason?: string | null
  canLeave: boolean
  canViewFeeds?: boolean
  canCreateDiscussion: boolean
  canCreateAnnouncement: boolean
  canFeatureOnParent: boolean
  canCreatePoll?: boolean
  canVotePolls?: boolean
  canUploadPhotos?: boolean
  canUploadVideos?: boolean
  canUploadFiles?: boolean
  canCreateEvent?: boolean
  discussions: CommunityFeedItem[]
  announcements: CommunityFeedItem[]
  polls?: GroupPollItem[]
  libraryPhotos?: GroupLibraryItem[]
  libraryVideos?: GroupLibraryItem[]
  libraryFiles?: GroupLibraryItem[]
  groupEvents?: GroupEventItem[]
  members: Member[]
  pendingMembers?: Member[]
  pendingInvites?: PendingInvite[]
  emailCredits?: { emails_included: number; emails_used: number; emails_left: number } | null
  recentGroups?: RecentGroup[]
  membershipJoin?: MembershipJoinPayload | null
  membershipRoute: string | null
  reportReasons: Record<string, string>
}

const tabs = [
  { key: "discussion", label: "Discussion", icon: MessageSquare },
  { key: "announcements", label: "Announcements", icon: Megaphone },
  { key: "about", label: "About", icon: FileText },
  { key: "members", label: "Members", icon: Users },
  { key: "polls", label: "Polls", icon: BarChart3 },
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

function Panel({
  title,
  action,
  children,
  className = "",
}: {
  title?: string
  action?: ReactNode
  children: ReactNode
  className?: string
}) {
  return (
    <section
      className={`rounded-xl border border-purple-100 bg-white shadow-sm shadow-purple-500/5 dark:border-purple-500/20 dark:bg-[#111827] dark:shadow-none ${className}`}
    >
      {(title || action) && (
        <div className="flex items-center justify-between gap-3 border-b border-purple-50 px-4 py-3 dark:border-purple-500/15">
          {title ? (
            <h2 className="bg-gradient-to-r from-purple-700 to-blue-700 bg-clip-text text-[17px] font-bold text-transparent dark:from-purple-300 dark:to-blue-300">
              {title}
            </h2>
          ) : (
            <span />
          )}
          {action}
        </div>
      )}
      <div className="p-4">{children}</div>
    </section>
  )
}

function SoftButton({
  children,
  className = "",
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { children: ReactNode }) {
  return (
    <button
      type="button"
      className={`inline-flex h-9 cursor-pointer items-center justify-center rounded-lg border border-purple-200 bg-gradient-to-r from-purple-50 to-blue-50 px-3 text-sm font-semibold text-purple-800 transition hover:from-purple-100 hover:to-blue-100 dark:border-purple-500/30 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-purple-100 dark:hover:from-purple-500/25 dark:hover:to-blue-500/25 ${className}`}
      {...props}
    >
      {children}
    </button>
  )
}

function RecentGroupsPanel({ groups }: { groups: RecentGroup[] }) {
  if (groups.length === 0) return null

  return (
    <Panel title="Recent groups">
      <div className="space-y-3">
        {groups.map((g) => (
          <GroupDirectoryCard key={g.id} group={g} />
        ))}
      </div>
    </Panel>
  )
}

export default function GroupShow({
  group,
  tab,
  membership,
  canManage,
  canModerate,
  canJoin,
  joinBlockedReason = null,
  canLeave,
  canViewFeeds = true,
  canCreateDiscussion,
  canCreateAnnouncement,
  canCreatePoll = false,
  canVotePolls = false,
  canUploadPhotos = false,
  canUploadVideos = false,
  canUploadFiles = false,
  canCreateEvent = false,
  discussions,
  announcements,
  polls = [],
  libraryPhotos = [],
  libraryVideos = [],
  libraryFiles = [],
  groupEvents = [],
  members,
  pendingMembers = [],
  pendingInvites = [],
  emailCredits = null,
  recentGroups = [],
  membershipJoin = null,
  membershipRoute,
  reportReasons,
}: Props) {
  const [inviteSubmitting, setInviteSubmitting] = useState(false)
  const [resendingInviteId, setResendingInviteId] = useState<number | null>(null)
  const [uploadingMedia, setUploadingMedia] = useState<"cover" | "icon" | null>(null)
  const coverInputRef = useRef<HTMLInputElement>(null)
  const iconInputRef = useRef<HTMLInputElement>(null)
  const isPendingJoin = membership?.status === "pending"

  const uploadGroupMedia = (field: "cover_image" | "icon_image", file: File | undefined) => {
    if (!file || !canManage) {
      return
    }
    setUploadingMedia(field === "cover_image" ? "cover" : "icon")
    router.post(
      route("groups.media.update", group.slug),
      { [field]: file },
      {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => setUploadingMedia(null),
      },
    )
  }

  const onCoverSelected = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    e.target.value = ""
    uploadGroupMedia("cover_image", file)
  }

  const onIconSelected = (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    e.target.value = ""
    uploadGroupMedia("icon_image", file)
  }
  const isMember = Boolean(membership && membership.status !== "pending")
  const joinLabel = group.join_policy === "approval" ? "Request to join" : "Join group"

  const visibilityLabel =
    group.visibility === "private" ? "Private group" : group.visibility === "hidden" ? "Hidden group" : "Public group"

  const VisibilityIcon = group.visibility === "public" ? Globe2 : Lock

  const parentKind =
    group.parent?.type === "UnityImpactAlliance" || group.parent?.type === "CareAlliance"
      ? "Alliance"
      : "Organization"

  const joinPolicyLabel =
    group.join_policy === "invite_only"
      ? "Invitation only"
      : group.join_policy === "approval"
        ? "Approval required"
        : group.join_policy === "followers"
          ? `${parentKind} followers only`
          : group.join_policy === "members"
            ? `${parentKind} members only`
            : group.join_policy === "followers_and_members"
              ? "Followers & members"
              : "Anyone can join"

  const postingLabels = (() => {
    const options = {
      everyone: "Everyone",
      followers: "Followers",
      members: "Members only",
      moderators: "Moderators only",
      admins: "Admins only",
    } as Record<string, string>
    const policies =
      group.posting_policies && group.posting_policies.length > 0
        ? group.posting_policies
        : [group.posting_policy || "members"]
    return policies.map((p) => options[p] ?? p).join(", ")
  })()

  const postingLabel = postingLabels || "Members only"

  const visibleTabs = tabs.filter((t) => {
    if (t.key === "events") return group.allow_events && canViewFeeds
    if (t.key === "photos") return group.allow_photos && canViewFeeds
    if (t.key === "videos") return group.allow_videos && canViewFeeds
    if (t.key === "files") return group.allow_documents && canViewFeeds
    if (t.key === "polls") return group.allow_polls && canViewFeeds
    if (!canViewFeeds && (t.key === "discussion" || t.key === "announcements" || t.key === "members")) {
      return false
    }
    return true
  })

  const primaryTabs = visibleTabs.slice(0, 5)
  const overflowTabs = visibleTabs.slice(5)

  const setTab = (key: string) => {
    router.get(route("groups.show", group.slug), { tab: key }, { preserveState: true, preserveScroll: true })
  }

  const feedItems = tab === "discussion" ? discussions : announcements
  const aboutText =
    group.description?.trim() ||
    (group.parent?.name ? `A group under ${group.parent.name}.` : "Welcome to this Believe In Unity group.")

  const hasMediaShortcuts =
    canViewFeeds &&
    (group.allow_photos ||
      group.allow_videos ||
      group.allow_events ||
      group.allow_documents ||
      group.allow_polls)

  return (
    <FrontendLayout>
      <Head title={group.name} />

      <div className="min-h-screen bg-gradient-to-b from-purple-50 via-blue-50/50 to-slate-50 text-slate-900 dark:from-[#0a0f1a] dark:via-[#0c1222] dark:to-[#0a0f1a] dark:text-white">
        {/* Facebook-style header */}
        <div className="border-b border-purple-100/80 bg-white/95 shadow-sm shadow-purple-500/5 backdrop-blur dark:border-purple-500/20 dark:bg-[#111827] dark:shadow-none">
          {/* Cover — full viewport width */}
          <div className="group/cover relative h-[220px] w-full overflow-hidden sm:h-[280px] md:h-[350px]">
            {group.cover_image ? (
              <img src={group.cover_image} alt="" className="h-full w-full object-cover" />
            ) : (
              <div className="h-full w-full bg-gradient-to-r from-purple-700 via-blue-600 to-indigo-700" />
            )}
            <div className="absolute inset-0 bg-gradient-to-t from-purple-950/40 via-transparent to-transparent" />
            {(group.is_featured || group.is_pinned) && (
              <div className="absolute right-3 top-3 flex gap-2 sm:right-4 sm:top-4">
                {group.is_featured && (
                  <Badge className="border-0 bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow">
                    Featured
                  </Badge>
                )}
                {group.is_pinned && (
                  <Badge className="border-0 bg-white/95 text-blue-700 shadow dark:bg-[#111827] dark:text-blue-200">
                    Pinned
                  </Badge>
                )}
              </div>
            )}
            {canManage && (
              <>
                <input
                  ref={coverInputRef}
                  type="file"
                  accept="image/jpeg,image/png,image/jpg,image/webp,image/gif"
                  className="hidden"
                  onChange={onCoverSelected}
                />
                <button
                  type="button"
                  disabled={uploadingMedia !== null}
                  onClick={() => coverInputRef.current?.click()}
                  className="absolute bottom-3 right-3 inline-flex cursor-pointer items-center gap-2 rounded-lg bg-white/95 px-3 py-2 text-sm font-semibold text-slate-800 shadow-md transition hover:bg-white disabled:cursor-wait disabled:opacity-70 dark:bg-[#111827]/95 dark:text-white sm:bottom-4 sm:right-4"
                >
                  <Camera className="h-4 w-4" />
                  {uploadingMedia === "cover"
                    ? "Uploading…"
                    : group.cover_image
                      ? "Edit cover photo"
                      : "Add cover photo"}
                </button>
              </>
            )}
          </div>

          <div className="container mx-auto px-4">
            {/* Identity row */}
            <div className="relative px-0 pb-3 pt-3 sm:pb-4">
              <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div className="flex min-w-0 items-end gap-3 sm:gap-4">
                  <div className="relative -mt-16 h-[118px] w-[118px] shrink-0 sm:-mt-20 sm:h-[168px] sm:w-[168px]">
                    <div className="h-full w-full overflow-hidden rounded-xl border-4 border-white bg-gradient-to-br from-purple-600 to-blue-600 shadow-lg ring-2 ring-purple-500/20 dark:border-[#111827] sm:rounded-2xl">
                      {group.icon_image ? (
                        <img src={group.icon_image} alt="" className="h-full w-full object-cover" />
                      ) : (
                        <div className="flex h-full w-full items-center justify-center text-3xl font-bold text-white sm:text-5xl">
                          {initials(group.name) || <UsersRound className="h-12 w-12" />}
                        </div>
                      )}
                    </div>
                    {canManage && (
                      <>
                        <input
                          ref={iconInputRef}
                          type="file"
                          accept="image/jpeg,image/png,image/jpg,image/webp,image/gif"
                          className="hidden"
                          onChange={onIconSelected}
                        />
                        <button
                          type="button"
                          disabled={uploadingMedia !== null}
                          onClick={() => iconInputRef.current?.click()}
                          title={group.icon_image ? "Edit group picture" : "Add group picture"}
                          aria-label={group.icon_image ? "Edit group picture" : "Add group picture"}
                          className="absolute bottom-1.5 right-1.5 flex h-9 w-9 cursor-pointer items-center justify-center rounded-full border-2 border-white bg-gradient-to-br from-purple-600 to-blue-600 text-white shadow-md transition hover:from-purple-700 hover:to-blue-700 disabled:cursor-wait disabled:opacity-70 dark:border-[#111827] sm:bottom-2 sm:right-2 sm:h-10 sm:w-10"
                        >
                          <Camera className="h-4 w-4" />
                        </button>
                      </>
                    )}
                  </div>

                  <div className="min-w-0 flex-1 pb-1">
                    <h1 className="truncate text-[28px] font-bold leading-tight tracking-tight text-slate-900 dark:text-white sm:text-[32px]">
                      {group.name}
                    </h1>
                    <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-600 dark:text-slate-300">
                      <span className="inline-flex items-center gap-1 font-medium text-purple-700 dark:text-purple-300">
                        <VisibilityIcon className="h-3.5 w-3.5" />
                        {visibilityLabel}
                      </span>
                      <span aria-hidden className="text-purple-300">
                        ·
                      </span>
                      <button
                        type="button"
                        className="cursor-pointer font-semibold text-blue-700 hover:underline dark:text-blue-300"
                        onClick={() => setTab("members")}
                      >
                        {group.members_count.toLocaleString()}{" "}
                        {group.members_count === 1 ? "member" : "members"}
                      </button>
                      {group.category && (
                        <>
                          <span aria-hidden className="text-purple-300">
                            ·
                          </span>
                          <span className="rounded-full bg-gradient-to-r from-purple-100 to-blue-100 px-2 py-0.5 text-xs font-semibold text-purple-800 dark:from-purple-500/20 dark:to-blue-500/20 dark:text-purple-200">
                            {group.category}
                          </span>
                        </>
                      )}
                    </div>
                    {members.length > 0 && (
                      <div className="mt-2.5 flex items-center">
                        <div className="flex -space-x-2">
                          {members.slice(0, 8).map((m) => (
                            <div
                              key={m.id}
                              title={m.user?.name}
                              className="flex h-8 w-8 items-center justify-center rounded-full border-2 border-white bg-gradient-to-br from-purple-100 to-blue-100 text-[10px] font-semibold text-purple-700 dark:border-[#111827] dark:from-purple-500/40 dark:to-blue-500/40 dark:text-purple-50"
                            >
                              {initials(m.user?.name || "?") || "?"}
                            </div>
                          ))}
                        </div>
                      </div>
                    )}
                  </div>
                </div>

                {/* Actions */}
                <div className="flex flex-wrap items-center gap-2 lg:pb-2">
                  {canJoin && (
                    <Button
                      className="h-9 cursor-pointer rounded-lg bg-gradient-to-r from-purple-600 to-blue-600 px-4 font-semibold text-white hover:from-purple-700 hover:to-blue-700"
                      onClick={() => router.post(route("groups.join", group.slug))}
                    >
                      <UserPlus className="mr-1.5 h-4 w-4" />
                      {joinLabel}
                    </Button>
                  )}
                  {isPendingJoin && (
                    <Button
                      variant="outline"
                      className="h-9 cursor-pointer rounded-lg border-amber-300 bg-amber-50 font-semibold text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100"
                      onClick={() => router.post(route("groups.leave", group.slug))}
                    >
                      Cancel request
                    </Button>
                  )}
                  {isMember && !isPendingJoin && (
                    <SoftButton onClick={() => setTab("members")}>
                      <Users className="mr-1.5 h-4 w-4" />
                      Joined
                      <ChevronDown className="ml-1 h-4 w-4 opacity-70" />
                    </SoftButton>
                  )}
                  {canLeave && !isPendingJoin && (
                    <Button
                      variant="outline"
                      className="h-9 cursor-pointer rounded-lg border-purple-200 font-semibold text-purple-800 hover:bg-purple-50 dark:border-purple-500/30 dark:text-purple-100 dark:hover:bg-purple-500/10"
                      onClick={() => router.post(route("groups.leave", group.slug))}
                    >
                      Leave
                    </Button>
                  )}
                  {!canJoin && !membership && group.join_policy === "invite_only" && (
                    <Button
                      variant="outline"
                      disabled
                      className="h-9 cursor-default rounded-lg border-purple-200 font-semibold"
                    >
                      Invite only
                    </Button>
                  )}
                  {!canJoin && !membership && joinBlockedReason && group.join_policy !== "invite_only" && (
                    <p className="w-full text-sm text-slate-500 dark:text-slate-400 sm:w-auto sm:max-w-xs">
                      {joinBlockedReason}
                    </p>
                  )}
                  {canModerate && (
                    <SoftButton onClick={() => setTab("members")}>
                      <UserPlus className="mr-1.5 h-4 w-4" />
                      Invite
                    </SoftButton>
                  )}
                  {canManage && (
                    <Link
                      href={route("groups.settings", group.slug)}
                      className="inline-flex h-9 cursor-pointer items-center rounded-lg border border-purple-200 bg-gradient-to-r from-purple-50 to-blue-50 px-3 text-sm font-semibold text-purple-800 transition hover:from-purple-100 hover:to-blue-100 dark:border-purple-500/30 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-purple-100 dark:hover:from-purple-500/25 dark:hover:to-blue-500/25"
                    >
                      <Settings className="mr-1.5 h-4 w-4" />
                      Manage
                    </Link>
                  )}
                  {membershipRoute && canManage && (
                    <Button
                      variant="outline"
                      asChild
                      className="h-9 cursor-pointer rounded-lg border-purple-200 font-semibold text-purple-800 dark:border-purple-500/30 dark:text-purple-100"
                    >
                      <Link href={membershipRoute}>Membership</Link>
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

              {/* Tab bar — More dropdown outside overflow-x-auto nav */}
              <div className="mt-3 border-t border-purple-100 dark:border-purple-500/20">
                <div className="flex items-stretch">
                  <nav className="flex min-w-0 flex-1 gap-0 overflow-x-auto" aria-label="Group sections">
                    {primaryTabs.map((t) => {
                      const active = tab === t.key
                      return (
                        <button
                          key={t.key}
                          type="button"
                          onClick={() => setTab(t.key)}
                          className={`relative shrink-0 cursor-pointer px-3 py-3.5 text-[15px] font-semibold transition-colors sm:px-4 ${
                            active
                              ? "text-purple-700 dark:text-purple-300"
                              : "text-slate-600 hover:bg-gradient-to-r hover:from-purple-50 hover:to-blue-50 hover:text-purple-800 dark:text-slate-300 dark:hover:from-purple-500/10 dark:hover:to-blue-500/10 dark:hover:text-purple-200"
                          }`}
                        >
                          {t.label}
                          {active && (
                            <span className="absolute inset-x-2 bottom-0 h-[3px] rounded-t-full bg-gradient-to-r from-purple-600 to-blue-600" />
                          )}
                        </button>
                      )
                    })}
                  </nav>

                  {overflowTabs.length > 0 && (
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <button
                          type="button"
                          className={`relative flex shrink-0 cursor-pointer items-center gap-1 px-3 py-3.5 text-[15px] font-semibold outline-none sm:px-4 ${
                            overflowTabs.some((t) => t.key === tab)
                              ? "text-purple-700 dark:text-purple-300"
                              : "text-slate-600 hover:bg-gradient-to-r hover:from-purple-50 hover:to-blue-50 dark:text-slate-300 dark:hover:from-purple-500/10 dark:hover:to-blue-500/10"
                          }`}
                        >
                          More
                          <ChevronDown className="h-4 w-4" />
                          {overflowTabs.some((t) => t.key === tab) && (
                            <span className="absolute inset-x-2 bottom-0 h-[3px] rounded-t-full bg-gradient-to-r from-purple-600 to-blue-600" />
                          )}
                        </button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent
                        align="end"
                        sideOffset={4}
                        className="z-[100] min-w-[200px] border border-purple-100 bg-white p-1 shadow-xl shadow-purple-500/10 dark:border-purple-500/30 dark:bg-[#111827]"
                      >
                        {overflowTabs.map((t) => (
                          <DropdownMenuItem
                            key={t.key}
                            className={`cursor-pointer gap-2 rounded-md px-3 py-2.5 text-sm font-medium focus:bg-gradient-to-r focus:from-purple-50 focus:to-blue-50 dark:focus:from-purple-500/15 dark:focus:to-blue-500/15 ${
                              tab === t.key
                                ? "text-purple-700 dark:text-purple-300"
                                : "text-slate-800 dark:text-slate-100"
                            }`}
                            onSelect={() => setTab(t.key)}
                          >
                            <t.icon className="h-4 w-4" />
                            {t.label}
                          </DropdownMenuItem>
                        ))}
                      </DropdownMenuContent>
                    </DropdownMenu>
                  )}
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Body: left sidebar · center feed · right recent groups */}
        <div className="container mx-auto px-4 py-4 sm:py-5">
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-12 lg:items-start xl:grid-cols-12">
            {/* LEFT sidebar — About, members preview, media shortcuts, membership compact */}
            <aside className="order-2 space-y-4 lg:order-1 lg:col-span-12 xl:sticky xl:top-20 xl:order-1 xl:col-span-3">
              <Panel title="About">
                <p className="whitespace-pre-wrap text-[15px] leading-relaxed text-slate-700 dark:text-slate-200">
                  {aboutText.length > 220 && tab !== "about" ? `${aboutText.slice(0, 220)}…` : aboutText}
                </p>
                {tab !== "about" && (
                  <button
                    type="button"
                    onClick={() => setTab("about")}
                    className="mt-2 cursor-pointer text-sm font-semibold text-purple-700 hover:underline dark:text-purple-300"
                  >
                    See more
                  </button>
                )}
                <ul className="mt-4 space-y-3 text-sm text-slate-700 dark:text-slate-200">
                  <li className="flex gap-3">
                    <VisibilityIcon className="mt-0.5 h-5 w-5 shrink-0 text-purple-500" />
                    <div>
                      <p className="font-semibold">{visibilityLabel}</p>
                      <p className="text-slate-500 dark:text-slate-400">{joinPolicyLabel}</p>
                    </div>
                  </li>
                  <li className="flex gap-3">
                    <MessageSquare className="mt-0.5 h-5 w-5 shrink-0 text-blue-500" />
                    <div>
                      <p className="font-semibold">Who can post</p>
                      <p className="text-slate-500 dark:text-slate-400">{postingLabel}</p>
                    </div>
                  </li>
                  {group.parent?.name && (
                    <li className="flex gap-3">
                      <Building2 className="mt-0.5 h-5 w-5 shrink-0 text-purple-500" />
                      <div>
                        <p className="font-semibold">Hosted by</p>
                        <p className="text-slate-500 dark:text-slate-400">{group.parent.name}</p>
                      </div>
                    </li>
                  )}
                  {group.rules.length > 0 && (
                    <li className="flex gap-3">
                      <FileText className="mt-0.5 h-5 w-5 shrink-0 text-blue-500" />
                      <div>
                        <p className="font-semibold">Group rules</p>
                        <p className="text-slate-500 dark:text-slate-400">
                          {group.rules.length} rule{group.rules.length === 1 ? "" : "s"}
                        </p>
                      </div>
                    </li>
                  )}
                </ul>
              </Panel>

              {members.length > 0 && (
                <Panel
                  title={`Members · ${group.members_count}`}
                  action={
                    <button
                      type="button"
                      onClick={() => setTab("members")}
                      className="cursor-pointer text-sm font-semibold text-purple-700 hover:underline dark:text-purple-300"
                    >
                      See all
                    </button>
                  }
                >
                  <div className="grid grid-cols-3 gap-2">
                    {members.slice(0, 9).map((m) => (
                      <div key={m.id} className="min-w-0 text-center">
                        <div className="mx-auto flex h-14 w-14 items-center justify-center overflow-hidden rounded-lg bg-gradient-to-br from-purple-100 to-blue-100 text-sm font-semibold text-purple-700 dark:from-purple-500/30 dark:to-blue-500/30 dark:text-purple-100">
                          {initials(m.user?.name || "?") || "?"}
                        </div>
                        <p className="mt-1 truncate text-xs font-semibold text-slate-800 dark:text-slate-100">
                          {m.user?.name?.split(" ")[0] || "Member"}
                        </p>
                      </div>
                    ))}
                  </div>
                </Panel>
              )}

              {hasMediaShortcuts && (
                <Panel title="Media & more">
                  <div className="grid grid-cols-2 gap-2">
                    {group.allow_photos && (
                      <button
                        type="button"
                        onClick={() => setTab("photos")}
                        className="flex cursor-pointer items-center gap-2 rounded-lg border border-purple-100 bg-gradient-to-r from-purple-50 to-blue-50 px-3 py-2.5 text-sm font-semibold text-purple-800 hover:from-purple-100 hover:to-blue-100 dark:border-purple-500/20 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-purple-100"
                      >
                        <ImageIcon className="h-4 w-4" /> Photos
                      </button>
                    )}
                    {group.allow_videos && (
                      <button
                        type="button"
                        onClick={() => setTab("videos")}
                        className="flex cursor-pointer items-center gap-2 rounded-lg border border-purple-100 bg-gradient-to-r from-purple-50 to-blue-50 px-3 py-2.5 text-sm font-semibold text-purple-800 hover:from-purple-100 hover:to-blue-100 dark:border-purple-500/20 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-purple-100"
                      >
                        <Video className="h-4 w-4" /> Videos
                      </button>
                    )}
                    {group.allow_events && (
                      <button
                        type="button"
                        onClick={() => setTab("events")}
                        className="flex cursor-pointer items-center gap-2 rounded-lg border border-purple-100 bg-gradient-to-r from-purple-50 to-blue-50 px-3 py-2.5 text-sm font-semibold text-purple-800 hover:from-purple-100 hover:to-blue-100 dark:border-purple-500/20 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-purple-100"
                      >
                        <Calendar className="h-4 w-4" /> Events
                      </button>
                    )}
                    {group.allow_documents && (
                      <button
                        type="button"
                        onClick={() => setTab("files")}
                        className="flex cursor-pointer items-center gap-2 rounded-lg border border-purple-100 bg-gradient-to-r from-purple-50 to-blue-50 px-3 py-2.5 text-sm font-semibold text-purple-800 hover:from-purple-100 hover:to-blue-100 dark:border-purple-500/20 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-purple-100"
                      >
                        <FileText className="h-4 w-4" /> Files
                      </button>
                    )}
                    {group.allow_polls && (
                      <button
                        type="button"
                        onClick={() => setTab("polls")}
                        className="flex cursor-pointer items-center gap-2 rounded-lg border border-purple-100 bg-gradient-to-r from-purple-50 to-blue-50 px-3 py-2.5 text-sm font-semibold text-purple-800 hover:from-purple-100 hover:to-blue-100 dark:border-purple-500/20 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-purple-100"
                      >
                        <BarChart3 className="h-4 w-4" /> Polls
                      </button>
                    )}
                  </div>
                </Panel>
              )}

              {membershipJoin && tab !== "about" && <MembershipJoinCard membershipJoin={membershipJoin} compact />}
            </aside>

            {/* CENTER — tab content */}
            <div className="order-1 space-y-4 lg:order-2 lg:col-span-8 xl:order-2 xl:col-span-6">
              {(tab === "discussion" || tab === "announcements") && (
                <>
                  <CommunityComposer
                    parentType="Group"
                    parentId={group.id}
                    type={tab === "announcements" ? "announcement" : "discussion"}
                    canCreate={tab === "announcements" ? canCreateAnnouncement : canCreateDiscussion}
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

              {tab === "polls" && (
                <GroupPollsPanel
                  groupSlug={group.slug}
                  polls={polls}
                  canCreatePoll={canCreatePoll}
                  canVotePolls={canVotePolls}
                />
              )}

              {tab === "about" && (
                <Panel title="About this group">
                  <p className="whitespace-pre-wrap text-[15px] leading-relaxed text-slate-700 dark:text-slate-200">
                    {group.description || "No description yet."}
                  </p>

                  {group.rules.length > 0 && (
                    <div className="mt-5 rounded-xl border border-purple-100 bg-gradient-to-r from-purple-50/80 to-blue-50/80 p-4 dark:border-purple-500/20 dark:from-purple-500/10 dark:to-blue-500/10">
                      <h3 className="text-base font-bold text-purple-900 dark:text-purple-100">Group rules</h3>
                      {!isMember && (
                        <p className="mt-1 text-sm text-purple-700/80 dark:text-purple-200/80">
                          Please review these before joining.
                        </p>
                      )}
                      <ol className="mt-3 list-decimal space-y-2 pl-5 text-sm text-slate-700 dark:text-slate-200">
                        {group.rules.map((rule) => (
                          <li key={rule}>{rule}</li>
                        ))}
                      </ol>
                    </div>
                  )}

                  <div className="mt-5 grid gap-3 sm:grid-cols-2">
                    <div className="rounded-xl border border-purple-100 bg-purple-50/60 px-4 py-3 dark:border-purple-500/20 dark:bg-purple-500/10">
                      <p className="text-xs font-semibold uppercase tracking-wide text-purple-600 dark:text-purple-300">
                        Who can join
                      </p>
                      <p className="mt-1 font-semibold text-slate-900 dark:text-white">{joinPolicyLabel}</p>
                    </div>
                    <div className="rounded-xl border border-blue-100 bg-blue-50/60 px-4 py-3 dark:border-blue-500/20 dark:bg-blue-500/10">
                      <p className="text-xs font-semibold uppercase tracking-wide text-blue-600 dark:text-blue-300">
                        Who can post
                      </p>
                      <p className="mt-1 font-semibold text-slate-900 dark:text-white">{postingLabel}</p>
                    </div>
                  </div>

                  {membershipJoin && (
                    <div className="mt-5">
                      <MembershipJoinCard membershipJoin={membershipJoin} />
                    </div>
                  )}
                  {isMember && (
                    <div className="mt-5 rounded-xl border border-purple-100 bg-purple-50/70 px-4 py-3 text-sm dark:border-purple-500/20 dark:bg-purple-500/10">
                      Your role:{" "}
                      <strong className="capitalize text-purple-800 dark:text-purple-100">{membership?.role}</strong>
                      {membership?.posting_suspended && " (posting suspended)"}
                    </div>
                  )}
                  {isPendingJoin && (
                    <div className="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
                      Your join request is pending admin approval.
                    </div>
                  )}
                </Panel>
              )}

              {tab === "members" && (
                <Panel title={`Members · ${group.members_count}`} className="overflow-visible">
                  {canModerate && (
                    <div className="relative z-20 mb-5 rounded-2xl border border-purple-100 bg-gradient-to-br from-purple-50/90 via-white to-blue-50/90 p-4 shadow-sm shadow-purple-500/5 dark:border-purple-500/20 dark:from-purple-500/10 dark:via-[#111827] dark:to-blue-500/10">
                      <GroupInviteRecipientField
                        groupSlug={group.slug}
                        submitting={inviteSubmitting}
                        emailCreditsLeft={emailCredits?.emails_left ?? null}
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

                  {canModerate && pendingInvites.length > 0 && (
                    <div className="mb-5 overflow-hidden rounded-2xl border border-purple-100 bg-white shadow-sm dark:border-purple-500/20 dark:bg-[#111827]">
                      <div className="flex items-center justify-between gap-2 border-b border-purple-50 bg-gradient-to-r from-purple-50 to-blue-50 px-4 py-3 dark:border-purple-500/15 dark:from-purple-500/15 dark:to-blue-500/10">
                        <div className="flex items-center gap-2.5">
                          <div className="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-blue-600 text-white">
                            <Mail className="h-4 w-4" />
                          </div>
                          <div>
                            <p className="text-[15px] font-bold text-slate-900 dark:text-white">Invited</p>
                            <p className="text-xs text-slate-500 dark:text-slate-400">
                              {pendingInvites.length} invite{pendingInvites.length === 1 ? "" : "s"} waiting to join
                            </p>
                          </div>
                        </div>
                      </div>
                      <div className="divide-y divide-purple-50 dark:divide-purple-500/15">
                        {pendingInvites.map((invite) => {
                          const name = invite.user?.name || invite.email || "Invitee"
                          const email = invite.user?.email || invite.email
                          return (
                            <div
                              key={invite.id}
                              className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                              <div className="flex min-w-0 items-center gap-3">
                                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-purple-100 to-blue-100 text-sm font-bold text-purple-700 dark:from-purple-500/30 dark:to-blue-500/30 dark:text-purple-100">
                                  {initials(name) || "?"}
                                </div>
                                <div className="min-w-0">
                                  <div className="flex flex-wrap items-center gap-2">
                                    <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                                      {name}
                                    </p>
                                    <span className="inline-flex rounded-full bg-gradient-to-r from-purple-600 to-blue-600 px-2 py-0.5 text-[11px] font-bold text-white">
                                      Invited
                                    </span>
                                  </div>
                                  {email && (
                                    <p className="truncate text-xs text-slate-500 dark:text-slate-400">{email}</p>
                                  )}
                                  {invite.inviter?.name && (
                                    <p className="mt-0.5 text-[11px] text-slate-400">
                                      Invited by {invite.inviter.name}
                                      {invite.created_at
                                        ? ` · ${new Date(invite.created_at).toLocaleDateString()}`
                                        : ""}
                                    </p>
                                  )}
                                </div>
                              </div>
                              <div className="flex flex-wrap justify-end gap-1.5">
                                <button
                                  type="button"
                                  title="Resend invite email (uses 1 email credit)"
                                  disabled={resendingInviteId === invite.id}
                                  className="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-lg px-3 text-[13px] font-semibold text-purple-700 transition-colors hover:bg-purple-50 disabled:cursor-not-allowed disabled:opacity-60 dark:text-purple-300 dark:hover:bg-purple-500/10"
                                  onClick={() => {
                                    setResendingInviteId(invite.id)
                                    router.post(
                                      route("groups.invites.resend", {
                                        group: group.slug,
                                        invite: invite.id,
                                      }),
                                      {},
                                      {
                                        preserveScroll: true,
                                        onFinish: () => setResendingInviteId(null),
                                      },
                                    )
                                  }}
                                >
                                  {resendingInviteId === invite.id ? (
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                  ) : (
                                    <Send className="h-3.5 w-3.5" />
                                  )}
                                  Resend
                                </button>
                                <button
                                  type="button"
                                  className="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-lg px-3 text-[13px] font-semibold text-purple-700 transition-colors hover:bg-purple-50 dark:text-purple-300 dark:hover:bg-purple-500/10"
                                  onClick={async () => {
                                    try {
                                      await navigator.clipboard.writeText(invite.accept_url)
                                    } catch {
                                      window.prompt("Copy invite link:", invite.accept_url)
                                    }
                                  }}
                                >
                                  <Copy className="h-3.5 w-3.5" />
                                  Copy link
                                </button>
                              </div>
                            </div>
                          )
                        })}
                      </div>
                    </div>
                  )}

                  {canModerate && pendingMembers.length > 0 && (
                    <div className="mb-5 overflow-hidden rounded-2xl border border-amber-200/80 bg-white shadow-sm dark:border-amber-500/30 dark:bg-[#111827]">
                      <div className="flex items-center justify-between gap-2 border-b border-amber-100 bg-gradient-to-r from-amber-50 to-orange-50 px-4 py-3 dark:border-amber-500/20 dark:from-amber-500/15 dark:to-orange-500/10">
                        <div>
                          <p className="text-[15px] font-bold text-amber-950 dark:text-amber-50">
                            Pending requests
                          </p>
                          <p className="text-xs text-amber-800/80 dark:text-amber-200/80">
                            {pendingMembers.length} waiting for approval
                          </p>
                        </div>
                      </div>
                      <div className="divide-y divide-amber-100/80 dark:divide-amber-500/15">
                        {pendingMembers.map((m) => (
                          <div
                            key={m.id}
                            className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
                          >
                            <div className="flex items-center gap-3">
                              <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-amber-100 to-orange-100 text-sm font-bold text-amber-800 dark:from-amber-500/30 dark:to-orange-500/30 dark:text-amber-50">
                                {initials(m.user?.name || "?") || "?"}
                              </div>
                              <div>
                                <p className="text-sm font-semibold text-slate-900 dark:text-white">
                                  {m.user?.name ?? "Member"}
                                </p>
                                <p className="text-xs text-slate-500 dark:text-slate-400">{m.user?.email}</p>
                              </div>
                            </div>
                            <div className="flex flex-wrap justify-end gap-1.5">
                              <button
                                type="button"
                                className="inline-flex h-9 cursor-pointer items-center rounded-lg px-3 text-[13px] font-semibold text-slate-600 transition-colors hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-white/10"
                                onClick={() => router.post(route("groups.members.reject", [group.slug, m.id]))}
                              >
                                Decline
                              </button>
                              <Button
                                size="sm"
                                className="h-9 cursor-pointer rounded-lg bg-gradient-to-r from-purple-600 to-blue-600 px-3 text-[13px] font-bold text-white"
                                onClick={() => router.post(route("groups.members.approve", [group.slug, m.id]))}
                              >
                                Approve
                              </Button>
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                  )}

                  <div className="overflow-hidden rounded-2xl border border-purple-100 bg-white dark:border-purple-500/20 dark:bg-[#111827]">
                    <div className="border-b border-purple-50 px-4 py-3 dark:border-purple-500/15">
                      <p className="text-[15px] font-bold text-slate-900 dark:text-white">People in this group</p>
                      <p className="text-xs text-slate-500 dark:text-slate-400">
                        {group.members_count.toLocaleString()}{" "}
                        {group.members_count === 1 ? "member" : "members"}
                      </p>
                    </div>

                    {members.length === 0 ? (
                      <div className="px-4 py-10 text-center">
                        <Users className="mx-auto h-8 w-8 text-purple-300" />
                        <p className="mt-2 text-sm text-slate-500">No members to show yet.</p>
                      </div>
                    ) : (
                      <div className="divide-y divide-purple-50 dark:divide-purple-500/15">
                        {members.map((m) => (
                          <div
                            key={m.id}
                            className="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between"
                          >
                            <div className="flex min-w-0 items-center gap-3">
                              <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-blue-600 text-sm font-bold text-white shadow-sm shadow-purple-500/20">
                                {initials(m.user?.name || "?") || "?"}
                              </div>
                              <div className="min-w-0">
                                <p className="truncate font-semibold text-slate-900 dark:text-white">
                                  {m.user?.name}
                                </p>
                                <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                                  {m.user?.email}
                                </p>
                                <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                  <span className="inline-flex rounded-full bg-gradient-to-r from-purple-100 to-blue-100 px-2 py-0.5 text-[11px] font-semibold capitalize text-purple-800 dark:from-purple-500/20 dark:to-blue-500/20 dark:text-purple-200">
                                    {m.role}
                                  </span>
                                  {m.posting_suspended && (
                                    <span className="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800 dark:bg-amber-500/20 dark:text-amber-200">
                                      Posting suspended
                                    </span>
                                  )}
                                </div>
                              </div>
                            </div>
                            {(canManage || canModerate) && m.user && (
                              <div className="flex flex-wrap justify-end gap-1">
                                {canManage && m.role !== "moderator" && m.role !== "admin" && (
                                  <button
                                    type="button"
                                    className="inline-flex h-9 cursor-pointer items-center rounded-lg px-3 text-[13px] font-semibold text-slate-600 transition-colors hover:bg-purple-50 hover:text-purple-800 dark:text-slate-300 dark:hover:bg-purple-500/10 dark:hover:text-purple-200"
                                    onClick={() =>
                                      router.post(route("groups.members.role", [group.slug, m.id]), {
                                        role: "moderator",
                                      })
                                    }
                                  >
                                    Make moderator
                                  </button>
                                )}
                                {canManage && m.role === "moderator" && (
                                  <button
                                    type="button"
                                    className="inline-flex h-9 cursor-pointer items-center rounded-lg px-3 text-[13px] font-semibold text-slate-600 transition-colors hover:bg-purple-50 hover:text-purple-800 dark:text-slate-300 dark:hover:bg-purple-500/10 dark:hover:text-purple-200"
                                    onClick={() =>
                                      router.post(route("groups.members.role", [group.slug, m.id]), {
                                        role: "member",
                                      })
                                    }
                                  >
                                    Demote
                                  </button>
                                )}
                                {canModerate && m.role !== "admin" && (
                                  <button
                                    type="button"
                                    className="inline-flex h-9 cursor-pointer items-center rounded-lg px-3 text-[13px] font-semibold text-slate-600 transition-colors hover:bg-purple-50 hover:text-purple-800 dark:text-slate-300 dark:hover:bg-purple-500/10 dark:hover:text-purple-200"
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
                                  </button>
                                )}
                                {canManage && (
                                  <button
                                    type="button"
                                    className="inline-flex h-9 cursor-pointer items-center rounded-lg px-3 text-[13px] font-semibold text-red-600/80 transition-colors hover:bg-red-50 hover:text-red-600 dark:text-red-400/80 dark:hover:bg-red-500/10 dark:hover:text-red-400"
                                    onClick={() =>
                                      router.delete(route("groups.members.remove", [group.slug, m.id]))
                                    }
                                  >
                                    Remove
                                  </button>
                                )}
                              </div>
                            )}
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                </Panel>
              )}

              {tab === "events" && (
                <GroupEventsPanel
                  groupSlug={group.slug}
                  events={groupEvents}
                  canCreate={canCreateEvent}
                />
              )}
              {tab === "photos" && (
                <GroupLibraryPanel
                  groupSlug={group.slug}
                  type="photo"
                  items={libraryPhotos}
                  canUpload={canUploadPhotos}
                />
              )}
              {tab === "videos" && (
                <GroupLibraryPanel
                  groupSlug={group.slug}
                  type="video"
                  items={libraryVideos}
                  canUpload={canUploadVideos}
                />
              )}
              {tab === "files" && (
                <GroupLibraryPanel
                  groupSlug={group.slug}
                  type="document"
                  items={libraryFiles}
                  canUpload={canUploadFiles}
                />
              )}
            </div>

            {/* RIGHT sidebar — recently created groups only */}
            <aside className="order-3 space-y-4 lg:sticky lg:top-20 lg:col-span-4 xl:col-span-3">
              <RecentGroupsPanel groups={recentGroups} />
            </aside>
          </div>
        </div>
      </div>
    </FrontendLayout>
  )
}
