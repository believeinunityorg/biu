import { Link, usePage } from '@inertiajs/react'
import { cn } from '@/lib/utils'
import { fr } from '@/pages/Organization/FamilyReunion/theme'
import {
  FolderTree,
  GitBranch,
  LayoutDashboard,
  Network,
  Settings,
  Users,
  BookUser,
  HeartHandshake,
  UserPlus,
} from 'lucide-react'
import type { ReactNode } from 'react'

const tabs = [
  { title: 'Family Overview', short: 'Overview', href: '/organization/family-reunion', icon: LayoutDashboard },
  { title: 'Family Tree', short: 'Tree', href: '/organization/family-reunion/tree', icon: Network },
  { title: 'Branches', short: 'Branches', href: '/organization/family-reunion/branches', icon: GitBranch },
  { title: 'Invite', short: 'Invite', href: '/organization/family-reunion/invite', icon: UserPlus },
  { title: 'Members', short: 'Members', href: '/organization/family-reunion/members', icon: Users },
  { title: 'Directory', short: 'Directory', href: '/organization/family-reunion/directory', icon: BookUser },
  { title: 'Relationships', short: 'Relations', href: '/organization/family-reunion/admin', icon: HeartHandshake },
  { title: 'Settings', short: 'Settings', href: '/organization/family-reunion/founders', icon: Settings },
]

export function FamilyReunionTabs({ className }: { className?: string }) {
  const { url } = usePage()
  const path = url.split('?')[0]

  return (
    <div className={cn('-mx-4 border-b border-border bg-card md:mx-0 md:rounded-xl md:border', className)}>
      <nav
        className="flex gap-1 overflow-x-auto px-3 py-2 [scrollbar-width:none] sm:px-4 [&::-webkit-scrollbar]:hidden"
        aria-label="Family Reunion sections"
      >
        {tabs.map((tab) => {
          const Icon = tab.icon
          const active =
            tab.href === '/organization/family-reunion'
              ? path === tab.href
              : path === tab.href || path.startsWith(tab.href + '/')

          return (
            <Link
              key={tab.href}
              href={tab.href}
              className={cn(
                'inline-flex shrink-0 items-center gap-1.5 rounded-lg px-2.5 py-2 text-sm font-medium transition-colors sm:gap-2 sm:px-3',
                active
                  ? fr.tabActive
                  : 'text-muted-foreground hover:bg-muted hover:text-foreground',
              )}
            >
              <Icon className="h-4 w-4 shrink-0" />
              <span className="sm:hidden">{tab.short}</span>
              <span className="hidden whitespace-nowrap sm:inline">{tab.title}</span>
            </Link>
          )
        })}
      </nav>
    </div>
  )
}

export function FamilyReunionShell({
  children,
  title,
  subtitle,
  stats,
  organizationName,
}: {
  children: ReactNode
  title?: string
  subtitle?: string
  organizationName: string
  stats?: {
    total_members?: number
    family_branches?: number
    active_since?: string | null
  }
}) {
  return (
    <div className="min-w-0 space-y-4 sm:space-y-6">
      <FamilyReunionTabs />

      <div className="rounded-2xl border border-border bg-card p-4 shadow-sm sm:p-6">
        <div className="flex min-w-0 items-start gap-3 sm:gap-4">
          <div className={cn('flex h-11 w-11 shrink-0 items-center justify-center rounded-xl sm:h-14 sm:w-14', fr.iconBg)}>
            <FolderTree className="h-5 w-5 sm:h-7 sm:w-7" />
          </div>
          <div className="min-w-0 flex-1">
            <h1 className="text-xl font-semibold tracking-tight text-foreground sm:text-2xl">
              {title || organizationName}
            </h1>
            {subtitle ? (
              <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>
            ) : (
              <p className="mt-1 text-sm text-muted-foreground">
                Family Reunion hub for connecting branches, members, and shared history.
              </p>
            )}
          </div>
        </div>

        {stats && (
          <div className="mt-4 grid grid-cols-2 gap-3 border-t border-border pt-4 text-sm text-foreground sm:mt-5 sm:flex sm:flex-wrap sm:gap-6">
            <div className="inline-flex items-center gap-2">
              <Users className={cn('h-4 w-4 shrink-0', fr.text)} />
              <span>
                <span className="font-medium">{stats.total_members ?? 0}</span> Members
              </span>
            </div>
            <div className="inline-flex items-center gap-2">
              <GitBranch className={cn('h-4 w-4 shrink-0', fr.text)} />
              <span>
                <span className="font-medium">{stats.family_branches ?? 0}</span> Branches
              </span>
            </div>
            {stats.active_since && (
              <div className="col-span-2 inline-flex items-center gap-2 sm:col-span-1">
                <span className="h-2 w-2 shrink-0 rounded-full bg-emerald-500" />
                Active Since {stats.active_since}
              </div>
            )}
          </div>
        )}
      </div>

      <div className="min-w-0 space-y-4 sm:space-y-6">{children}</div>
    </div>
  )
}
