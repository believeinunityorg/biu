import { Link } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import { ArrowRight, Megaphone, MessageSquare, Plus, Shield } from 'lucide-react'
import { Button } from '@/components/ui/button'
import AnnouncementCard from '@/components/communication-hub/AnnouncementCard'
import DiscussionCard from '@/components/communication-hub/DiscussionCard'
import SearchBar from '@/components/communication-hub/SearchBar'
import CategorySelect from '@/components/communication-hub/CategorySelect'
import HubSegmentedTabs from '@/components/communication-hub/HubSegmentedTabs'
import HubEmptyState from '@/components/communication-hub/HubEmptyState'
import StartDiscussionModal from '@/components/communication-hub/StartDiscussionModal'
import {
  type HubAnnouncement,
  type HubCategory,
  type HubDiscussion,
  type HubPermissions,
} from '@/components/communication-hub/types'
import { hubRoute, type HubContext } from '@/lib/communication-hub-routes'
import { ch } from '@/pages/Organization/CommunicationHub/theme'
import { cn } from '@/lib/utils'

export type CommunicationHubPanelProps = {
  organization: { id: number; name: string; slug?: string }
  announcements: HubAnnouncement[]
  announcementsTotal?: number
  discussions: HubDiscussion[]
  discussionsTotal?: number
  categories?: HubCategory[]
  permissions?: Partial<HubPermissions>
  /** Prefer dedicated org dashboard routes when the viewer can manage */
  manageMode?: boolean
}

const TAB_KEY = 'biu.org-profile.communication-hub.tab'

export default function CommunicationHubPanel({
  organization,
  announcements,
  announcementsTotal = 0,
  discussions,
  discussionsTotal = 0,
  categories = [],
  permissions = {},
  manageMode = false,
}: CommunicationHubPanelProps) {
  const [tab, setTab] = useState<'announcements' | 'discussions'>(() => {
    if (typeof window === 'undefined') return 'announcements'
    const saved = localStorage.getItem(TAB_KEY)
    return saved === 'discussions' ? 'discussions' : 'announcements'
  })
  const [discussionSearch, setDiscussionSearch] = useState('')
  const [categoryId, setCategoryId] = useState('all')
  const [discussionFilter, setDiscussionFilter] = useState('all')
  const [startDiscussionOpen, setStartDiscussionOpen] = useState(false)

  const selectTab = (next: 'announcements' | 'discussions') => {
    setTab(next)
    localStorage.setItem(TAB_KEY, next)
  }

  const hubContext: HubContext = manageMode
    ? { mode: 'manage', org_slug: null }
    : { mode: 'community', org_slug: organization.slug ?? null }

  const announcementHref = (slug: string) => hubRoute('announcements.show', hubContext, slug)
  const discussionHref = (slug: string) => hubRoute('discussions.show', hubContext, slug)

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
      items = [...items].sort(
        (a, b) => b.replies_count + b.reactions_count - (a.replies_count + a.reactions_count),
      )
    }
    return items
  }, [discussions, categoryId, discussionSearch, discussionFilter])

  return (
    <div className="space-y-5 animate-in fade-in slide-in-from-bottom-4 duration-500 sm:space-y-6">
      <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="min-w-0">
          <p className="text-xs font-semibold uppercase tracking-wider text-purple-600 dark:text-purple-400">
            A&amp;D Board
          </p>
          <h2 className={ch.heading}>
            <span className={ch.titleGradient}>Announcements &amp; Discussion</span>
          </h2>
          <p className={ch.subheading}>
            Official announcements and supporter Discussion Board for {organization.name}
          </p>
        </div>
        <HubSegmentedTabs value={tab} onChange={selectTab} />
      </header>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:gap-6">
        <section className={cn(ch.card, 'p-3 sm:p-5', tab === 'discussions' && 'hidden lg:block')}>
          <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-2.5">
              <div className={ch.iconWrap}>
                <Megaphone className="h-4 w-4" />
              </div>
              <h3 className={ch.sectionTitle}>Announcements</h3>
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
              <HubEmptyState icon={Megaphone} title="No announcements yet" className="py-10" />
            ) : (
              announcements.map((item) => (
                <AnnouncementCard
                  key={item.id}
                  announcement={item}
                  href={announcementHref(item.slug)}
                />
              ))
            )}
          </div>
          {announcementsTotal > announcements.length && (
            <div className="mt-4 text-center">
              <Link
                href={
                  manageMode
                    ? route('org.communication-hub.announcements.index')
                    : hubRoute('index', hubContext)
                }
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
              <h3 className={ch.sectionTitle}>Discussion Board</h3>
            </div>
            {permissions.can_create_discussion && (
              manageMode ? (
                <Button asChild size="sm" className={cn(ch.btn, 'gap-1.5 rounded-xl')}>
                  <Link href={hubRoute('discussions.create', hubContext)}>
                    <Plus className="h-4 w-4" />
                    New
                  </Link>
                </Button>
              ) : (
                <Button
                  type="button"
                  size="sm"
                  className={cn(ch.btn, 'gap-1.5 rounded-xl')}
                  onClick={() => setStartDiscussionOpen(true)}
                >
                  <Plus className="h-4 w-4" />
                  New
                </Button>
              )
            )}
          </div>

          <div className="mb-3 flex flex-col gap-2 sm:flex-row">
            <SearchBar value={discussionSearch} onChange={setDiscussionSearch} placeholder="Search discussions…" className="flex-1" />
            <CategorySelect categories={categories} value={categoryId} onChange={setCategoryId} />
          </div>

          <div className="mb-3 -mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1">
            {[
              { value: 'all', label: 'All' },
              { value: 'recent', label: 'Recent' },
              { value: 'popular', label: 'Popular' },
              { value: 'unanswered', label: 'Unanswered' },
            ].map((f) => (
              <button
                key={f.value}
                type="button"
                onClick={() => setDiscussionFilter(f.value)}
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
              <HubEmptyState icon={MessageSquare} title="No discussions yet" className="py-10" />
            ) : (
              filteredDiscussions.map((item) => (
                <DiscussionCard
                  key={item.id}
                  discussion={item}
                  href={discussionHref(item.slug)}
                />
              ))
            )}
          </div>

          {discussionsTotal > discussions.length && (
            <div className="mt-4 text-center">
              <Link
                href={
                  manageMode
                    ? route('org.communication-hub.discussions.index')
                    : hubRoute('discussions.index', hubContext)
                }
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
        {[
          {
            icon: Megaphone,
            title: 'Announcements',
            body: 'Official updates, events, and resources from organization admins and staff.',
          },
          {
            icon: MessageSquare,
            title: 'Discussion Board',
            body: 'Conversations where followers and members can ask questions and share ideas.',
          },
          {
            icon: Shield,
            title: 'Respect & Support',
            body: 'Keep discussions kind and constructive. Report anything that violates guidelines.',
          },
        ].map((block) => (
          <div key={block.title} className={cn(ch.card, 'flex gap-3 p-4')}>
            <div className={ch.iconWrapLg}>
              <block.icon className="h-5 w-5" />
            </div>
            <div>
              <h4 className="font-semibold text-foreground">{block.title}</h4>
              <p className="mt-1 text-sm text-muted-foreground">{block.body}</p>
            </div>
          </div>
        ))}
      </div>

      {!manageMode && (
        <StartDiscussionModal
          open={startDiscussionOpen}
          onOpenChange={setStartDiscussionOpen}
          organizationName={organization.name}
          categories={categories}
          hubContext={hubContext}
        />
      )}
    </div>
  )
}
