import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { FamilyReunionShell } from '@/components/family-reunion/FamilyReunionShell'
import { fr } from '@/pages/Organization/FamilyReunion/theme'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import {
  BookUser,
  CalendarPlus,
  GitBranch,
  HeartHandshake,
  Megaphone,
  Network,
  Plus,
  Search,
  Settings,
  Shield,
  UserPlus,
} from 'lucide-react'

type Founders = {
  grandfather_name: string
  grandmother_name: string
  grandfather_photo_url?: string | null
  grandmother_photo_url?: string | null
  grandfather_birth_year?: number | null
  grandfather_death_year?: number | null
  grandmother_birth_year?: number | null
  grandmother_death_year?: number | null
} | null

type Branch = {
  id: number
  name: string
  members_count: number
  head_member?: { id: number; full_name: string; photo_url?: string | null } | null
}

type MemberPreview = {
  id: number
  full_name: string
  photo_url?: string | null
  branch?: string | null
  generation?: number | null
  status: string
  is_claimed: boolean
}

type Props = {
  organization: { id: number; name: string; registered_user_image?: string | null }
  founders: Founders
  branches: Branch[]
  stats: {
    total_members: number
    family_branches: number
    generations: number
    connected_members: number
    active_since?: string | null
  }
  directory_preview: MemberPreview[]
}

const tools = [
  { title: 'Family Tree', desc: 'Explore generations and branches', href: '/organization/family-reunion/tree', icon: Network },
  { title: 'Add Member', desc: 'Add children and relatives', href: '/organization/family-reunion/members', icon: UserPlus },
  { title: 'Family Directory', desc: 'Search all family records', href: '/organization/family-reunion/directory', icon: BookUser },
  { title: 'Family Relationships', desc: 'Correct parent links', href: '/organization/family-reunion/admin', icon: HeartHandshake },
  { title: 'Privacy Settings', desc: 'Founding couple & admins', href: '/organization/family-reunion/founders', icon: Shield },
  { title: 'Reports & Export', desc: 'Admin tools and audits', href: '/organization/family-reunion/admin', icon: Settings },
]

function yearRange(birth?: number | null, death?: number | null) {
  if (!birth && !death) return null
  return `${birth ?? '?'} – ${death ?? 'Present'}`
}

export default function Overview({ organization, founders, branches, stats, directory_preview }: Props) {
  return (
    <AppLayout breadcrumbs={[{ title: 'Family Reunion', href: '/organization/family-reunion' }]}>
      <Head title={`${organization.name} · Family Reunion`} />

      <div className="flex h-full min-w-0 flex-1 flex-col gap-4 p-3 sm:gap-6 sm:p-4 md:p-6">
        <FamilyReunionShell
          organizationName={organization.name}
          subtitle="Connect your family branches, share history, and keep everyone in one place."
          stats={stats}
        >
          <div className="grid gap-6 xl:grid-cols-3">
            <Card className="xl:col-span-2 overflow-hidden border-border shadow-sm">
              <CardHeader className="flex flex-col gap-3 space-y-0 pb-3 sm:flex-row sm:items-center sm:justify-between">
                <CardTitle className="text-lg">Family Tree Overview</CardTitle>
                <Button asChild variant="outline" size="sm" className="w-full sm:w-auto">
                  <Link href="/organization/family-reunion/tree">View Full Tree</Link>
                </Button>
              </CardHeader>
              <CardContent className="space-y-6">
                {!founders ? (
                  <div className={`rounded-xl border border-dashed ${fr.surface} p-6 text-center`}>
                    <p className="text-sm text-foreground">Start by adding your founding couple — they become the top of the family tree.</p>
                    <Button asChild className={`mt-4 ${fr.btn}`}>
                      <Link href="/organization/family-reunion/founders">Add Founding Couple</Link>
                    </Button>
                  </div>
                ) : (
                  <>
                    <div className="flex flex-wrap items-center justify-center gap-4">
                      {[
                        {
                          name: founders.grandfather_name,
                          photo: founders.grandfather_photo_url,
                          years: yearRange(founders.grandfather_birth_year, founders.grandfather_death_year),
                        },
                        {
                          name: founders.grandmother_name,
                          photo: founders.grandmother_photo_url,
                          years: yearRange(founders.grandmother_birth_year, founders.grandmother_death_year),
                        },
                      ].map((person) => (
                        <div key={person.name} className="flex flex-col items-center gap-2 rounded-xl border border-border bg-muted px-5 py-4">
                          <div className={`h-14 w-14 overflow-hidden rounded-full ${fr.avatar}`}>
                            {person.photo ? (
                              <img src={person.photo} alt={person.name} className="h-full w-full object-cover" />
                            ) : (
                              <div className={`flex h-full w-full items-center justify-center text-sm font-semibold ${fr.text}`}>
                                {person.name.slice(0, 1)}
                              </div>
                            )}
                          </div>
                          <div className="text-center">
                            <div className="text-sm font-semibold text-foreground">{person.name}</div>
                            {person.years && <div className="text-xs text-muted-foreground">{person.years}</div>}
                          </div>
                        </div>
                      ))}
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">
                      {branches.length === 0 ? (
                        <div className="sm:col-span-2 rounded-xl border border-dashed border-border p-5 text-center text-sm text-muted-foreground">
                          No branches yet.{' '}
                          <Link href="/organization/family-reunion/branches" className={`font-medium ${fr.text} hover:underline`}>
                            Add the children of the founding couple
                          </Link>
                        </div>
                      ) : (
                        branches.map((branch) => (
                          <div key={branch.id} className="rounded-xl border border-border p-4">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                              <div className="min-w-0">
                                <div className="font-medium text-foreground">{branch.name}</div>
                                <div className="mt-1 text-xs text-muted-foreground">{branch.members_count} members</div>
                              </div>
                              <Link
                                href={`/organization/family-reunion/tree?branch_id=${branch.id}`}
                                className={`text-xs font-medium ${fr.text} hover:underline`}
                              >
                                View Branch
                              </Link>
                            </div>
                          </div>
                        ))
                      )}
                    </div>
                  </>
                )}

                <div className="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-4">
                  {[
                    { label: 'Total Members', value: stats.total_members },
                    { label: 'Family Branches', value: stats.family_branches },
                    { label: 'Generations', value: stats.generations },
                    { label: 'Connected Members', value: stats.connected_members },
                  ].map((item) => (
                    <div key={item.label} className="rounded-xl bg-muted px-2 py-3 text-center sm:px-3">
                      <div className="text-lg font-semibold text-foreground sm:text-xl">{item.value}</div>
                      <div className="mt-1 text-[11px] leading-tight text-muted-foreground sm:text-xs">{item.label}</div>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>

            <div className="space-y-6">
              <Card className="border-border shadow-sm">
                <CardHeader className="pb-3">
                  <CardTitle className="text-lg">Quick Actions</CardTitle>
                </CardHeader>
                <CardContent className="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3 xl:grid-cols-2">
                  {[
                    { label: 'Add Branch', href: '/organization/family-reunion/branches', icon: GitBranch },
                    { label: 'Add Member', href: '/organization/family-reunion/members', icon: UserPlus },
                    { label: 'Invite Family', href: '/email-invite', icon: Plus },
                    { label: 'Add Announcement', href: '/campaigns/create', icon: Megaphone },
                    { label: 'Create Event', href: '/events', icon: CalendarPlus },
                  ].map((action) => {
                    const Icon = action.icon
                    return (
                      <Link
                        key={action.label}
                        href={action.href}
                        className={`flex flex-col items-center gap-2 rounded-xl border border-border bg-card px-2 py-3 text-center transition sm:px-3 sm:py-4 ${fr.hoverCard}`}
                      >
                        <div className={`flex h-9 w-9 items-center justify-center rounded-lg sm:h-10 sm:w-10 ${fr.iconBg}`}>
                          <Icon className="h-4 w-4 sm:h-5 sm:w-5" />
                        </div>
                        <span className="text-[11px] font-medium leading-tight text-foreground sm:text-xs">{action.label}</span>
                      </Link>
                    )
                  })}
                </CardContent>
              </Card>
            </div>
          </div>

          <div className="grid gap-6 xl:grid-cols-3">
            <Card className="xl:col-span-2 border-border shadow-sm">
              <CardHeader className="flex flex-col gap-3 space-y-0 pb-3 sm:flex-row sm:items-center sm:justify-between">
                <CardTitle className="text-lg">Member Directory</CardTitle>
                <Button asChild variant="outline" size="sm" className="w-full sm:w-auto">
                  <Link href="/organization/family-reunion/directory">View All</Link>
                </Button>
              </CardHeader>
              <CardContent>
                <div className="mb-4 flex flex-col gap-2 sm:flex-row">
                  <div className="relative min-w-0 flex-1">
                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                      className="pl-9"
                      placeholder="Search members..."
                      onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                          const value = (e.target as HTMLInputElement).value
                          router.get('/organization/family-reunion/directory', { q: value }, { preserveState: true })
                        }
                      }}
                    />
                  </div>
                </div>

                {directory_preview.length === 0 ? (
                  <p className="px-2 py-8 text-center text-sm text-muted-foreground">
                    No members yet. Add branches and children to populate the directory.
                  </p>
                ) : (
                  <>
                    {/* Desktop/Tablet table — same pattern as Supporters / Followers */}
                    <div className="hidden md:block overflow-x-auto rounded-lg border border-border">
                      <table className="w-full text-left text-sm">
                        <thead>
                          <tr className="border-b border-border bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                            <th className="px-4 py-3 font-medium">Member Name</th>
                            <th className="px-4 py-3 font-medium">Branch</th>
                            <th className="px-4 py-3 font-medium">Generation</th>
                            <th className="px-4 py-3 font-medium">Status</th>
                          </tr>
                        </thead>
                        <tbody>
                          {directory_preview.map((member) => (
                            <tr key={member.id} className="border-b border-border last:border-0">
                              <td className="px-4 py-3">
                                <div className="flex items-center gap-3">
                                  <div className="h-8 w-8 overflow-hidden rounded-full bg-muted">
                                    {member.photo_url ? (
                                      <img src={member.photo_url} alt="" className="h-full w-full object-cover" />
                                    ) : (
                                      <div className="flex h-full w-full items-center justify-center text-xs font-medium text-muted-foreground">
                                        {member.full_name.slice(0, 1)}
                                      </div>
                                    )}
                                  </div>
                                  <span className="font-medium text-foreground">{member.full_name}</span>
                                </div>
                              </td>
                              <td className={`px-4 py-3 ${fr.text}`}>{member.branch || '—'}</td>
                              <td className="px-4 py-3 text-muted-foreground">{member.generation ?? '—'}</td>
                              <td className="px-4 py-3">
                                <Badge
                                  variant="secondary"
                                  className={
                                    member.status === 'active'
                                      ? 'bg-emerald-50 text-emerald-700'
                                      : member.status === 'unclaimed'
                                        ? 'bg-amber-50 text-amber-700'
                                        : 'bg-muted text-muted-foreground'
                                  }
                                >
                                  {member.is_claimed ? 'Active' : member.status}
                                </Badge>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>

                    {/* Mobile cards */}
                    <div className="space-y-3 md:hidden">
                      {directory_preview.map((member) => (
                        <div key={member.id} className="rounded-xl border border-border p-3">
                          <div className="flex items-start justify-between gap-3">
                            <div className="flex min-w-0 items-center gap-3">
                              <div className="h-10 w-10 shrink-0 overflow-hidden rounded-full bg-muted">
                                {member.photo_url ? (
                                  <img src={member.photo_url} alt="" className="h-full w-full object-cover" />
                                ) : (
                                  <div className="flex h-full w-full items-center justify-center text-sm font-medium text-muted-foreground">
                                    {member.full_name.slice(0, 1)}
                                  </div>
                                )}
                              </div>
                              <div className="min-w-0">
                                <div className="truncate font-medium text-foreground">{member.full_name}</div>
                                <div className={`truncate text-xs ${fr.text}`}>{member.branch || 'No branch'}</div>
                              </div>
                            </div>
                            <Badge
                              variant="secondary"
                              className={
                                member.status === 'active'
                                  ? 'shrink-0 bg-emerald-50 text-emerald-700'
                                  : member.status === 'unclaimed'
                                    ? 'shrink-0 bg-amber-50 text-amber-700'
                                    : 'shrink-0 bg-muted text-muted-foreground'
                              }
                            >
                              {member.is_claimed ? 'Active' : member.status}
                            </Badge>
                          </div>
                          <div className="mt-3 grid grid-cols-2 gap-2 text-xs text-muted-foreground">
                            <div>
                              <span className="block text-[11px] uppercase tracking-wide">Generation</span>
                              <span className="font-medium text-foreground">{member.generation ?? '—'}</span>
                            </div>
                          </div>
                        </div>
                      ))}
                    </div>
                  </>
                )}
              </CardContent>
            </Card>

            <Card className="border-border shadow-sm">
              <CardHeader className="pb-3">
                <CardTitle className="text-lg">Family Tools & Features</CardTitle>
              </CardHeader>
              <CardContent className="grid gap-3">
                {tools.map((tool) => {
                  const Icon = tool.icon
                  return (
                    <Link
                      key={tool.title}
                      href={tool.href}
                      className={`flex items-start gap-3 rounded-xl border border-border p-3 transition ${fr.hoverCard}`}
                    >
                      <div className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${fr.iconBg}`}>
                        <Icon className="h-4 w-4" />
                      </div>
                      <div>
                        <div className="text-sm font-medium text-foreground">{tool.title}</div>
                        <div className="text-xs text-muted-foreground">{tool.desc}</div>
                      </div>
                    </Link>
                  )
                })}
              </CardContent>
            </Card>
          </div>

          <div className={`rounded-2xl ${fr.banner} px-4 py-4 shadow-sm sm:px-6 sm:py-5`}>
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div className="min-w-0">
                <h2 className="text-base font-semibold sm:text-lg">Welcome to Your Family Reunion Hub</h2>
                <p className="mt-1 max-w-2xl text-sm text-white/85">
                  Use founding couple setup, branches, the tree, and directory to organize your family — while continuing to use BIU announcements, events, media, and donations as usual.
                </p>
              </div>
              <Button asChild variant="secondary" className="w-full shrink-0 sm:w-auto">
                <Link href="/organization/family-reunion/founders">Getting Started Guide</Link>
              </Button>
            </div>
          </div>
        </FamilyReunionShell>
      </div>
    </AppLayout>
  )
}
