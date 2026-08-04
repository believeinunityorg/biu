import { Head, Link, router } from '@inertiajs/react'
import { ArrowRight, Megaphone, MessageSquare, Plus, Shield } from 'lucide-react'
import AppLayout from '@/layouts/app-layout'
import { Button } from '@/components/ui/button'
import AnnouncementCard from '@/components/communication-hub/AnnouncementCard'
import DiscussionCard from '@/components/communication-hub/DiscussionCard'
import SearchBar from '@/components/communication-hub/SearchBar'
import CategorySelect from '@/components/communication-hub/CategorySelect'
import HubSegmentedTabs from '@/components/communication-hub/HubSegmentedTabs'
import HubEmptyState from '@/components/communication-hub/HubEmptyState'
import {
  type HubAnnouncement,
  type HubCategory,
  type HubDiscussion,
  type HubPermissions,
} from '@/components/communication-hub/types'
import { ch } from './theme'
import { cn } from '@/lib/utils'
import { useEffect, useMemo, useState } from 'react'
import type { BreadcrumbItem } from '@/types'

type Props = {
  organization: { id: number; name: string }
  announcements: HubAnnouncement[]
  announcementsTotal: number
  discussions: HubDiscussion[]
  discussionsTotal: number
  categories: HubCategory[]
  permissions: HubPermissions
  activeTab: 'announcements' | 'discussions'
}

const TAB_STORAGE_KEY = 'biu.communication-hub.tab'

const breadcrumbs: BreadcrumbItem[] = [
  { title: 'Communication Hub', href: '/organization/communication-hub' },
]

const INFO_BLOCKS = [
  {
    icon: Megaphone,
    title: 'Announcements',
    body: 'Official updates, events, and resources shared by organization admins and staff.',
  },
  {
    icon: MessageSquare,
    title: 'Discussion Board',
    body: 'Open conversations where followers and members can ask questions and share ideas.',
  },
  {
    icon: Shield,
    title: 'Respect & Support',
    body: 'Keep discussions kind and constructive. Report anything that violates community guidelines.',
  },
] as const

export default function CommunicationHubIndex({
  organization,
  announcements,
  announcementsTotal,
  discussions,
  discussionsTotal,
  categories,
  permissions,
  activeTab: initialTab,
}: Props) {
  const [tab, setTab] = useState<'announcements' | 'discussions'>(initialTab)
  const [discussionSearch, setDiscussionSearch] = useState('')
  const [categoryId, setCategoryId] = useState('all')
  const [discussionFilter, setDiscussionFilter] = useState('all')

  useEffect(() => {
    const saved = localStorage.getItem(TAB_STORAGE_KEY)
    if (saved === 'announcements' || saved === 'discussions') {
      if (saved !== initialTab) {
        setTab(saved)
        router.get(route('org.communication-hub.index'), { tab: saved }, { preserveState: true, replace: true })
      }
    }
  }, [])

  const selectTab = (next: 'announcements' | 'discussions') => {
    setTab(next)
    localStorage.setItem(TAB_STORAGE_KEY, next)
    router.get(route('org.communication-hub.index'), { tab: next }, { preserveState: true, replace: true })
  }

  const filteredDiscussions = useMemo(() => {
    let items = [...discussions]
    if (categoryId !== 'all') {
      items = items.filter((d) => String(d.category?.id) === categoryId)
    }
    if (discussionSearch.trim()) {
      const q = discussionSearch.toLowerCase()
      items = items.filter(
        (d) =>
          d.title.toLowerCase().includes(q) ||
          d.excerpt.toLowerCase().includes(q) ||
          (d.author?.name ?? '').toLowerCase().includes(q),
      )
    }
    if (discussionFilter === 'unanswered') {
      items = items.filter((d) => d.replies_count === 0)
    } else if (discussionFilter === 'popular') {
      items = [...items].sort((a, b) => b.replies_count + b.reactions_count - (a.replies_count + a.reactions_count))
    } else if (discussionFilter === 'recent') {
      items = [...items].sort((a, b) => String(b.created_at).localeCompare(String(a.created_at)))
    }
    return items
  }, [discussions, categoryId, discussionSearch, discussionFilter])

  const moderateAnnouncement = (slug: string, action: string) => {
    router.post(route('org.communication-hub.announcements.moderate', slug), { action }, { preserveScroll: true })
  }

  const moderateDiscussion = (slug: string, action: string) => {
    router.post(route('org.communication-hub.discussions.moderate', slug), { action }, { preserveScroll: true })
  }

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={`Communication Hub — ${organization.name}`} />

      <div className={ch.page}>
        <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-wider text-purple-600 dark:text-purple-400">
              Community
            </p>
            <h1 className={ch.heading}>
              <span className={ch.titleGradient}>Communication Hub</span>
            </h1>
            <p className={ch.subheading}>Announcements and discussions for {organization.name}</p>
          </div>
          <HubSegmentedTabs value={tab} onChange={selectTab} className="sticky top-0 z-10 sm:static" />
        </header>

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:gap-6">
          <section className={cn(ch.card, 'p-3 sm:p-5', tab === 'discussions' && 'hidden lg:block')}>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-center gap-2.5">
                <div className={ch.iconWrap}>
                  <Megaphone className="h-4 w-4" />
                </div>
                <div>
                  <h2 className={ch.sectionTitle}>Announcements</h2>
                  <p className="text-xs text-muted-foreground">{announcementsTotal} total</p>
                </div>
              </div>
              {permissions.can_create_announcement && (
                <Button asChild size="sm" className={cn(ch.btn, 'gap-1.5 rounded-xl')}>
                  <Link href={route('org.communication-hub.announcements.create')}>
                    <Plus className="h-4 w-4" />
                    New
                  </Link>
                </Button>
              )}
            </div>

            <div className="space-y-3">
              {announcements.length === 0 ? (
                <HubEmptyState
                  icon={Megaphone}
                  title="No announcements yet"
                  description="Share updates, events, and resources with your community."
                  className="py-10"
                />
              ) : (
                announcements.map((item) => (
                  <AnnouncementCard
                    key={item.id}
                    announcement={item}
                    href={route('org.communication-hub.announcements.show', item.slug)}
                    actions={
                      permissions.can_manage_announcements
                        ? [
                            {
                              label: 'Edit',
                              onClick: () =>
                                router.visit(route('org.communication-hub.announcements.edit', item.slug)),
                            },
                            {
                              label: item.is_pinned ? 'Unpin' : 'Pin',
                              onClick: () => moderateAnnouncement(item.slug, item.is_pinned ? 'unpin' : 'pin'),
                            },
                            {
                              label: 'Archive',
                              onClick: () => moderateAnnouncement(item.slug, 'archive'),
                            },
                            {
                              label: 'Hide',
                              onClick: () => moderateAnnouncement(item.slug, 'hide'),
                            },
                            {
                              label: 'View History',
                              onClick: () =>
                                router.visit(route('org.communication-hub.announcements.show', item.slug)),
                            },
                          ]
                        : []
                    }
                  />
                ))
              )}
            </div>

            {announcementsTotal > announcements.length && (
              <div className="mt-4 text-center">
                <Link
                  href={route('org.communication-hub.announcements.index')}
                  className={cn(ch.link, 'inline-flex items-center gap-1 text-sm')}
                >
                  View all announcements
                  <ArrowRight className="h-4 w-4" />
                </Link>
              </div>
            )}
          </section>

          <section className={cn(ch.card, 'p-3 sm:p-5', tab === 'announcements' && 'hidden lg:block')}>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-center gap-2.5">
                <div className={ch.iconWrap}>
                  <MessageSquare className="h-4 w-4" />
                </div>
                <div>
                  <h2 className={ch.sectionTitle}>Discussion Board</h2>
                  <p className="text-xs text-muted-foreground">{discussionsTotal} total</p>
                </div>
              </div>
              {permissions.can_create_discussion && (
                <Button asChild size="sm" className={cn(ch.btn, 'gap-1.5 rounded-xl')}>
                  <Link href={route('org.communication-hub.discussions.create')}>
                    <Plus className="h-4 w-4" />
                    New
                  </Link>
                </Button>
              )}
            </div>

            <div className="mb-3 flex flex-col gap-2 sm:flex-row">
              <SearchBar
                value={discussionSearch}
                onChange={setDiscussionSearch}
                placeholder="Search discussions…"
                className="flex-1"
              />
              <CategorySelect categories={categories} value={categoryId} onChange={setCategoryId} />
            </div>

            <div className="mb-3 -mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1 scrollbar-none">
              {[
                { value: 'all', label: 'All' },
                { value: 'recent', label: 'Recent' },
                { value: 'popular', label: 'Popular' },
                { value: 'unanswered', label: 'Unanswered' },
                { value: 'mine', label: 'Mine' },
              ].map((f) => (
                <button
                  key={f.value}
                  type="button"
                  onClick={() => {
                    setDiscussionFilter(f.value)
                    if (f.value === 'mine') {
                      router.get(
                        route('org.communication-hub.discussions.index'),
                        { tab: 'mine' },
                        { preserveState: true },
                      )
                    }
                  }}
                  className={cn(
                    'shrink-0 whitespace-nowrap rounded-full px-3 py-1.5 text-sm transition',
                    discussionFilter === f.value ? ch.tabActive : ch.tabInactive,
                  )}
                >
                  {f.label}
                </button>
              ))}
            </div>

            <div>
              {filteredDiscussions.length === 0 ? (
                <HubEmptyState
                  icon={MessageSquare}
                  title="No discussions yet"
                  description="Start a conversation with your community."
                  className="py-10"
                />
              ) : (
                filteredDiscussions.map((item) => (
                  <DiscussionCard
                    key={item.id}
                    discussion={item}
                    href={route('org.communication-hub.discussions.show', item.slug)}
                    actions={
                      permissions.can_moderate_discussions
                        ? [
                            {
                              label: item.is_pinned ? 'Unpin' : 'Pin',
                              onClick: () => moderateDiscussion(item.slug, item.is_pinned ? 'unpin' : 'pin'),
                            },
                            {
                              label: item.is_locked ? 'Unlock' : 'Lock',
                              onClick: () => moderateDiscussion(item.slug, item.is_locked ? 'unlock' : 'lock'),
                            },
                            {
                              label: 'Hide',
                              onClick: () => moderateDiscussion(item.slug, 'hide'),
                            },
                            {
                              label: 'Archive',
                              onClick: () => moderateDiscussion(item.slug, 'archive'),
                            },
                          ]
                        : []
                    }
                  />
                ))
              )}
            </div>

            {discussionsTotal > discussions.length && (
              <div className="mt-4 text-center">
                <Link
                  href={route('org.communication-hub.discussions.index')}
                  className={cn(ch.link, 'inline-flex items-center gap-1 text-sm')}
                >
                  View all discussions
                  <ArrowRight className="h-4 w-4" />
                </Link>
              </div>
            )}
          </section>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:gap-4 md:grid-cols-3">
          {INFO_BLOCKS.map((block) => (
            <div key={block.title} className={cn(ch.card, 'flex gap-3 p-4')}>
              <div className={ch.iconWrapLg}>
                <block.icon className="h-5 w-5" />
              </div>
              <div className="min-w-0">
                <h3 className="font-semibold text-foreground">{block.title}</h3>
                <p className="mt-1 text-sm text-muted-foreground">{block.body}</p>
              </div>
            </div>
          ))}
        </div>
      </div>
    </AppLayout>
  )
}
