import { Head, Link } from '@inertiajs/react'
import { ArrowRight, Megaphone, MessageSquare, Plus, Shield } from 'lucide-react'
import { useMemo, useState } from 'react'
import FrontendLayout from '@/layouts/frontend/frontend-layout'
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
  type HubContext,
  type HubDiscussion,
  type HubPermissions,
} from '@/components/communication-hub/types'
import { hubRoute } from '@/lib/communication-hub-routes'
import { ch } from './theme'
import { cn } from '@/lib/utils'

type Props = {
  organization: { id: number; name: string; slug?: string }
  announcements: HubAnnouncement[]
  announcementsTotal: number
  discussions: HubDiscussion[]
  discussionsTotal: number
  categories: HubCategory[]
  permissions: HubPermissions
  hubContext?: HubContext
  activeTab: 'announcements' | 'discussions'
}

export default function CommunicationHubPublicIndex({
  organization,
  announcements,
  announcementsTotal,
  discussions,
  discussionsTotal,
  categories,
  permissions,
  hubContext: hubContextProp,
  activeTab: initialTab,
}: Props) {
  const hubContext: HubContext = hubContextProp ?? {
    mode: 'community',
    org_slug: organization.slug ?? null,
  }

  const [tab, setTab] = useState<'announcements' | 'discussions'>(initialTab)
  const [discussionSearch, setDiscussionSearch] = useState('')
  const [categoryId, setCategoryId] = useState('all')
  const [startDiscussionOpen, setStartDiscussionOpen] = useState(false)

  const canViewAnnouncements = permissions.can_view_announcements !== false
  const canViewDiscussions = permissions.can_view_discussions !== false

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
    return items
  }, [discussions, categoryId, discussionSearch])

  return (
    <FrontendLayout>
      <Head title={`Announcements & Discussion — ${organization.name}`} />

      <div className={ch.page}>
        <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-wider text-purple-600 dark:text-purple-400">
              A&amp;D Board
            </p>
            <h1 className={ch.heading}>
              <span className={ch.titleGradient}>Announcements &amp; Discussion</span>
            </h1>
            <p className={ch.subheading}>
              Official updates from {organization.name} and a community Discussion Board for supporters.
            </p>
          </div>
          <HubSegmentedTabs value={tab} onChange={setTab} />
        </header>

        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2 lg:gap-6">
          <section className={cn(ch.card, 'p-3 sm:p-5', tab === 'discussions' && 'hidden lg:block')}>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-center gap-2.5">
                <div className={ch.iconWrap}>
                  <Megaphone className="h-4 w-4" />
                </div>
                <h2 className={ch.sectionTitle}>Announcements</h2>
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
              {!canViewAnnouncements ? (
                <HubEmptyState
                  icon={Shield}
                  title="Announcements are restricted"
                  description="This organization limits who can view announcements. Follow or become a member to request access."
                  className="py-10"
                />
              ) : announcements.length === 0 ? (
                <HubEmptyState
                  icon={Megaphone}
                  title="No announcements yet"
                  description={`Check back soon for updates from ${organization.name}.`}
                  className="py-10"
                />
              ) : (
                announcements.map((item) => (
                  <AnnouncementCard
                    key={item.id}
                    announcement={item}
                    href={hubRoute('announcements.show', hubContext, item.slug)}
                  />
                ))
              )}
            </div>

            {canViewAnnouncements && announcementsTotal > announcements.length && (
              <div className="mt-4 text-center">
                <Link
                  href={hubRoute('index', hubContext) + '?tab=announcements'}
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
                <h2 className={ch.sectionTitle}>Discussion Board</h2>
              </div>
              {permissions.can_create_discussion && (
                <Button
                  type="button"
                  size="sm"
                  className={cn(ch.btn, 'gap-1.5 rounded-xl')}
                  onClick={() => setStartDiscussionOpen(true)}
                >
                  <Plus className="h-4 w-4" />
                  New
                </Button>
              )}
            </div>

            {canViewDiscussions && (
              <div className="mb-3 flex flex-col gap-2 sm:flex-row">
                <SearchBar
                  value={discussionSearch}
                  onChange={setDiscussionSearch}
                  placeholder="Search discussions…"
                  className="flex-1"
                />
                <CategorySelect categories={categories} value={categoryId} onChange={setCategoryId} />
              </div>
            )}

            <div>
              {!canViewDiscussions ? (
                <HubEmptyState
                  icon={Shield}
                  title="Discussion Board is restricted"
                  description="This organization limits who can view discussions. Follow or become a member to join the conversation."
                  className="py-10"
                />
              ) : filteredDiscussions.length === 0 ? (
                <HubEmptyState
                  icon={MessageSquare}
                  title="No discussions yet"
                  description="Be the first to start a conversation."
                  className="py-10"
                />
              ) : (
                filteredDiscussions.map((item) => (
                  <DiscussionCard
                    key={item.id}
                    discussion={item}
                    href={hubRoute('discussions.show', hubContext, item.slug)}
                  />
                ))
              )}
            </div>

            {canViewDiscussions && discussionsTotal > discussions.length && (
              <div className="mt-4 text-center">
                <Link
                  href={hubRoute('discussions.index', hubContext)}
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
              body: 'Official updates posted by the organization. Supporters can comment when enabled.',
            },
            {
              icon: MessageSquare,
              title: 'Discussion Board',
              body: 'Supporters start threads and reply when the organization allows posting.',
            },
            {
              icon: Shield,
              title: 'Audience controls',
              body: 'Each organization chooses who can view and who can post on their A&D board.',
            },
          ].map((block) => (
            <div key={block.title} className={cn(ch.card, 'flex gap-3 p-4')}>
              <div className={ch.iconWrapLg}>
                <block.icon className="h-5 w-5" />
              </div>
              <div>
                <h3 className="font-semibold text-foreground">{block.title}</h3>
                <p className="mt-1 text-sm text-muted-foreground">{block.body}</p>
              </div>
            </div>
          ))}
        </div>
      </div>

      <StartDiscussionModal
        open={startDiscussionOpen}
        onOpenChange={setStartDiscussionOpen}
        organizationName={organization.name}
        categories={categories}
        hubContext={hubContext}
      />
    </FrontendLayout>
  )
}
