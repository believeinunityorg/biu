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
} from 'lucide-react'
import type { ReactNode } from 'react'

const tabs = [
  { title: 'Family Overview', href: '/organization/family-reunion', icon: LayoutDashboard },
  { title: 'Family Tree', href: '/organization/family-reunion/tree', icon: Network },
  { title: 'Branches', href: '/organization/family-reunion/branches', icon: GitBranch },
  { title: 'Members', href: '/organization/family-reunion/members', icon: Users },
  { title: 'Directory', href: '/organization/family-reunion/directory', icon: BookUser },
  { title: 'Relationships', href: '/organization/family-reunion/admin', icon: HeartHandshake },
  { title: 'Settings', href: '/organization/family-reunion/founders', icon: Settings },
]

export function FamilyReunionTabs({ className }: { className?: string }) {
  const { url } = usePage()
  const path = url.split('?')[0]

  return (
    <div className={cn('overflow-x-auto border-b border-border bg-card', className)}>
      <nav className="flex min-w-max gap-1 px-2 py-2 sm:px-4">
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
                'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-colors',
                active
                  ? fr.tabActive
                  : 'text-muted-foreground hover:bg-muted hover:text-foreground',
              )}
            >
              <Icon className="h-4 w-4" />
              <span className="whitespace-nowrap">{tab.title}</span>
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
    <div className="space-y-6">
      <FamilyReunionTabs />

      <div className="rounded-2xl border border-border bg-card p-5 shadow-sm sm:p-6">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div className="flex items-start gap-4">
            <div className={cn('flex h-14 w-14 items-center justify-center rounded-xl', fr.iconBg)}>
              <FolderTree className="h-7 w-7" />
            </div>
            <div>
              <h1 className="text-2xl font-semibold tracking-tight text-foreground">
                {title || organizationName}
              </h1>
              {subtitle ? (
                <p className="mt-1 max-w-2xl text-sm text-muted-foreground">{subtitle}</p>
              ) : (
                <p className="mt-1 text-sm text-muted-foreground">
                  Family Reunion hub for connecting branches, members, and shared history.
                </p>
              )}
            </div>
          </div>
        </div>

        {stats && (
          <div className="mt-5 flex flex-wrap gap-6 border-t border-border pt-4 text-sm text-foreground">
            <div className="inline-flex items-center gap-2">
              <Users className={cn('h-4 w-4', fr.text)} />
              <span className="font-medium">{stats.total_members ?? 0}</span> Family Members
            </div>
            <div className="inline-flex items-center gap-2">
              <GitBranch className={cn('h-4 w-4', fr.text)} />
              <span className="font-medium">{stats.family_branches ?? 0}</span> Branches
            </div>
            {stats.active_since && (
              <div className="inline-flex items-center gap-2">
                <span className="h-2 w-2 rounded-full bg-emerald-500" />
                Active Since {stats.active_since}
              </div>
            )}
          </div>
        )}
      </div>

      {children}
    </div>
  )
}
