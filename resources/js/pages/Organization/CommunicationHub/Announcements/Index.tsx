import { Head, Link, router } from '@inertiajs/react'
import { Megaphone, Plus } from 'lucide-react'
import AppLayout from '@/layouts/app-layout'
import { Button } from '@/components/ui/button'
import AnnouncementCard from '@/components/communication-hub/AnnouncementCard'
import AnnouncementFilters from '@/components/communication-hub/AnnouncementFilters'
import HubEmptyState from '@/components/communication-hub/HubEmptyState'
import { type HubAnnouncement } from '@/components/communication-hub/types'
import { ch } from '../theme'
import { cn } from '@/lib/utils'
import type { BreadcrumbItem } from '@/types'

type PaginationLink = { url: string | null; label: string; active: boolean }

type Paginated<T> = {
  data: T[]
  links: PaginationLink[]
  current_page: number
  last_page: number
  total: number
  from: number | null
  to: number | null
}

type Props = {
  organization: { id: number; name: string }
  announcements: Paginated<HubAnnouncement>
  filters: { filter: string; search: string }
  categories: string[]
  can: { manage: boolean }
}

const FILTERS = [
  { value: 'all', label: 'All' },
  { value: 'pinned', label: 'Pinned' },
  { value: 'scheduled', label: 'Scheduled' },
  { value: 'archived', label: 'Archived' },
]

export default function AnnouncementsIndex({ organization, announcements, filters, can }: Props) {
  const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Communication Hub', href: route('org.communication-hub.index') },
    { title: 'Announcements', href: route('org.communication-hub.announcements.index') },
  ]

  const moderate = (slug: string, action: string) => {
    router.post(route('org.communication-hub.announcements.moderate', slug), { action }, { preserveScroll: true })
  }

  const destroy = (slug: string) => {
    if (!confirm('Delete this announcement?')) return
    router.delete(route('org.communication-hub.announcements.destroy', slug), { preserveScroll: true })
  }

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={`Announcements — ${organization.name}`} />

      <div className={ch.pageMedium}>
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h1 className={ch.heading}>Announcements</h1>
            <p className={ch.subheading}>Official updates for {organization.name}</p>
          </div>
          {can.manage && (
            <Button asChild className={cn(ch.btn, 'gap-1.5 rounded-lg')}>
              <Link href={route('org.communication-hub.announcements.create')}>
                <Plus className="h-4 w-4" />
                New Announcement
              </Link>
            </Button>
          )}
        </div>

        <AnnouncementFilters
          filters={FILTERS}
          active={filters.filter}
          search={filters.search}
          routeName="org.communication-hub.announcements.index"
        />

        <div className="space-y-3">
          {announcements.data.length === 0 ? (
            <HubEmptyState
              icon={Megaphone}
              title="No announcements found"
              description="Try a different filter or search term."
              className="py-16"
            />
          ) : (
            announcements.data.map((item) => (
              <AnnouncementCard
                key={item.id}
                announcement={item}
                href={route('org.communication-hub.announcements.show', item.slug)}
                actions={
                  can.manage
                    ? [
                        {
                          label: 'Edit',
                          onClick: () => router.visit(route('org.communication-hub.announcements.edit', item.slug)),
                        },
                        {
                          label: item.is_pinned ? 'Unpin' : 'Pin',
                          onClick: () => moderate(item.slug, item.is_pinned ? 'unpin' : 'pin'),
                        },
                        item.status === 'archived' || item.status === 'hidden'
                          ? { label: 'Restore', onClick: () => moderate(item.slug, 'restore') }
                          : { label: 'Archive', onClick: () => moderate(item.slug, 'archive') },
                        { label: 'Hide', onClick: () => moderate(item.slug, 'hide') },
                        { label: 'Delete', destructive: true, onClick: () => destroy(item.slug) },
                      ]
                    : []
                }
              />
            ))
          )}
        </div>

        {announcements.last_page > 1 && (
          <div className="flex flex-wrap items-center justify-between gap-3 pt-2">
            <p className="text-sm text-muted-foreground">
              Showing {announcements.from ?? 0}–{announcements.to ?? 0} of {announcements.total}
            </p>
            <div className="flex flex-wrap gap-1">
              {announcements.links.map((link, i) => (
                <Link
                  key={i}
                  href={link.url ?? '#'}
                  preserveScroll
                  preserveState
                  dangerouslySetInnerHTML={{ __html: link.label }}
                  className={cn(
                    'rounded-lg px-3 py-1.5 text-sm transition',
                    link.active ? ch.tabActive : 'text-muted-foreground hover:bg-muted',
                    !link.url && 'pointer-events-none opacity-40',
                  )}
                />
              ))}
            </div>
          </div>
        )}
      </div>
    </AppLayout>
  )
}
