import { useEffect, useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import { MessageSquare, Plus } from 'lucide-react'
import AppLayout from '@/layouts/app-layout'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import DiscussionCard from '@/components/communication-hub/DiscussionCard'
import SearchBar from '@/components/communication-hub/SearchBar'
import CategorySelect from '@/components/communication-hub/CategorySelect'
import HubEmptyState from '@/components/communication-hub/HubEmptyState'
import { type HubCategory, type HubDiscussion } from '@/components/communication-hub/types'
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
  discussions: Paginated<HubDiscussion>
  categories: HubCategory[]
  filters: { tab: string; search: string; sort: string; category_id?: string | number | null }
  can: { create: boolean; moderate: boolean }
}

const TABS = [
  { key: 'all', label: 'All Discussions', query: { tab: 'all', sort: undefined as string | undefined } },
  { key: 'recent', label: 'Recent', query: { tab: 'recent', sort: undefined as string | undefined } },
  { key: 'popular', label: 'Popular', query: { tab: 'all', sort: 'most_reactions' } },
  { key: 'unanswered', label: 'Unanswered', query: { tab: 'unanswered', sort: undefined as string | undefined } },
  { key: 'mine', label: 'My Discussions', query: { tab: 'mine', sort: undefined as string | undefined } },
]

const SORTS = [
  { value: 'newest', label: 'Newest activity' },
  { value: 'oldest', label: 'Oldest first' },
  { value: 'most_replies', label: 'Most replies' },
  { value: 'most_reactions', label: 'Most reactions' },
]

function activeTabKey(filters: Props['filters']): string {
  if (filters.tab === 'mine') return 'mine'
  if (filters.tab === 'unanswered') return 'unanswered'
  if (filters.tab === 'recent') return 'recent'
  if (filters.sort === 'most_reactions') return 'popular'
  return 'all'
}

export default function DiscussionsIndex({ organization, discussions, categories, filters, can }: Props) {
  const [search, setSearch] = useState(filters.search)

  useEffect(() => {
    setSearch(filters.search)
  }, [filters.search])

  useEffect(() => {
    const t = setTimeout(() => {
      if (search === filters.search) return
      updateQuery({ search: search || undefined })
    }, 350)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search])

  const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Communication Hub', href: route('org.communication-hub.index') },
    { title: 'Discussions', href: route('org.communication-hub.discussions.index') },
  ]

  const updateQuery = (patch: Record<string, string | number | undefined | null>) => {
    router.get(
      route('org.communication-hub.discussions.index'),
      {
        tab: filters.tab,
        search: filters.search || undefined,
        sort: filters.sort,
        category_id: filters.category_id || undefined,
        ...patch,
      },
      { preserveState: true, replace: true },
    )
  }

  const moderate = (slug: string, action: string) => {
    router.post(route('org.communication-hub.discussions.moderate', slug), { action }, { preserveScroll: true })
  }

  const active = activeTabKey(filters)

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={`Discussions — ${organization.name}`} />

      <div className={ch.pageMedium}>
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <h1 className={ch.heading}>Discussion Board</h1>
            <p className={ch.subheading}>Conversations for {organization.name}</p>
          </div>
          {can.create && (
            <Button asChild className={cn(ch.btn, 'gap-1.5 rounded-lg')}>
              <Link href={route('org.communication-hub.discussions.create')}>
                <Plus className="h-4 w-4" />
                New Discussion
              </Link>
            </Button>
          )}
        </div>

        <div className={cn(ch.card, 'p-4 sm:p-5')}>
          <div className="mb-3 flex flex-wrap gap-1.5">
            {TABS.map((tab) => (
              <button
                key={tab.key}
                type="button"
                onClick={() => updateQuery(tab.query)}
                className={cn(
                  'whitespace-nowrap rounded-full px-3 py-1.5 text-sm transition',
                  active === tab.key ? ch.tabActive : ch.tabInactive,
                )}
              >
                {tab.label}
              </button>
            ))}
          </div>

          <div className="flex flex-col gap-2 sm:flex-row">
            <SearchBar value={search} onChange={setSearch} placeholder="Search discussions…" className="flex-1" />
            <CategorySelect
              categories={categories}
              value={filters.category_id ?? 'all'}
              onChange={(v) => updateQuery({ category_id: v === 'all' ? undefined : v })}
            />
            <Select value={filters.sort || 'newest'} onValueChange={(v) => updateQuery({ sort: v })}>
              <SelectTrigger className={cn('h-10 w-full sm:w-48', ch.input, ch.focus)}>
                <SelectValue placeholder="Sort by" />
              </SelectTrigger>
              <SelectContent>
                {SORTS.map((s) => (
                  <SelectItem key={s.value} value={s.value}>
                    {s.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="mt-2">
            {discussions.data.length === 0 ? (
              <HubEmptyState
                icon={MessageSquare}
                title="No discussions found"
                description="Try a different filter, category, or search term."
                className="py-16"
              />
            ) : (
              discussions.data.map((item) => (
                <DiscussionCard
                  key={item.id}
                  discussion={item}
                  href={route('org.communication-hub.discussions.show', item.slug)}
                  actions={
                    can.moderate
                      ? [
                          { label: item.is_pinned ? 'Unpin' : 'Pin', onClick: () => moderate(item.slug, item.is_pinned ? 'unpin' : 'pin') },
                          { label: item.is_locked ? 'Unlock' : 'Lock', onClick: () => moderate(item.slug, item.is_locked ? 'unlock' : 'lock') },
                          { label: item.is_hidden ? 'Unhide' : 'Hide', onClick: () => moderate(item.slug, item.is_hidden ? 'restore' : 'hide') },
                          { label: item.is_archived ? 'Unarchive' : 'Archive', onClick: () => moderate(item.slug, item.is_archived ? 'restore' : 'archive') },
                          { label: 'Delete', destructive: true, onClick: () => moderate(item.slug, 'delete') },
                        ]
                      : []
                  }
                />
              ))
            )}
          </div>
        </div>

        {discussions.last_page > 1 && (
          <div className="flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm text-muted-foreground">
              Showing {discussions.from ?? 0}–{discussions.to ?? 0} of {discussions.total}
            </p>
            <div className="flex flex-wrap gap-1">
              {discussions.links.map((link, i) => (
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
