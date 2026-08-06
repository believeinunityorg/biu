import { Head, Link } from '@inertiajs/react'
import { useState } from 'react'
import { ArrowRight, Building2, Megaphone, MessageSquare, Plus, Shield } from 'lucide-react'
import FrontendLayout from '@/layouts/frontend/frontend-layout'
import { Button } from '@/components/ui/button'
import AnnouncementCard from '@/components/communication-hub/AnnouncementCard'
import DiscussionCard from '@/components/communication-hub/DiscussionCard'
import HubEmptyState from '@/components/communication-hub/HubEmptyState'
import StartDiscussionModal from '@/components/communication-hub/StartDiscussionModal'
import { type HubAnnouncement, type HubCategory, type HubDiscussion } from '@/components/communication-hub/types'
import { ch } from '@/pages/Organization/CommunicationHub/theme'
import { cn } from '@/lib/utils'

type Board = {
  id: number
  name: string
  slug: string
  image?: string | null
  hub_url: string | null
  can_view_announcements: boolean
  can_view_discussions: boolean
  can_create_discussion: boolean
  announcement_visibility: string
  discussion_visibility: string
  allow_followers_to_post: boolean
  allow_members_to_post: boolean
  categories: HubCategory[]
  recent_announcements: HubAnnouncement[]
  recent_discussions: HubDiscussion[]
}

type Props = {
  boards: Board[]
  visibilityAudiences: Record<string, string>
}

function audienceLabel(value: string, audiences: Record<string, string>): string {
  return audiences[value] ?? value
}

export default function SupporterCommunicationHubIndex({ boards, visibilityAudiences }: Props) {
  const [composer, setComposer] = useState<{ name: string; slug: string; categories: HubCategory[] } | null>(null)

  return (
    <FrontendLayout>
      <Head title="Announcements & Discussion" />

      <div className={ch.page}>
        <header className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-wider text-purple-600 dark:text-purple-400">
              For supporters
            </p>
            <h1 className={ch.heading}>
              <span className={ch.titleGradient}>Announcements &amp; Discussion</span>
            </h1>
            <p className={ch.subheading}>
              Official updates from organizations you follow, and Discussion Boards you can join.
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="outline" className="rounded-xl">
              <Link href={route('user.profile.favorites')}>Manage following</Link>
            </Button>
            <Button asChild className={cn(ch.btn, 'rounded-xl')}>
              <Link href={route('organizations')}>Find organizations</Link>
            </Button>
          </div>
        </header>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div className={cn(ch.surfaceSoft, 'flex items-start gap-3 p-4')}>
            <div className={ch.iconWrap}>
              <Megaphone className="h-4 w-4" />
            </div>
            <div>
              <p className="text-sm font-semibold text-foreground">Announcements</p>
              <p className="mt-0.5 text-xs text-muted-foreground">Org-posted updates from boards you can view.</p>
            </div>
          </div>
          <div className={cn(ch.surfaceSoft, 'flex items-start gap-3 p-4')}>
            <div className={ch.iconWrap}>
              <MessageSquare className="h-4 w-4" />
            </div>
            <div>
              <p className="text-sm font-semibold text-foreground">Discussion Board</p>
              <p className="mt-0.5 text-xs text-muted-foreground">Start threads and reply when the org allows it.</p>
            </div>
          </div>
          <div className={cn(ch.surfaceSoft, 'flex items-start gap-3 p-4')}>
            <div className={ch.iconWrap}>
              <Shield className="h-4 w-4" />
            </div>
            <div>
              <p className="text-sm font-semibold text-foreground">Org access rules</p>
              <p className="mt-0.5 text-xs text-muted-foreground">Each organization controls who can view and post.</p>
            </div>
          </div>
        </div>

        {boards.length === 0 ? (
          <div className={cn(ch.card, 'p-6')}>
            <HubEmptyState
              icon={Building2}
              title="Follow an organization to get started"
              description="Each organization has its own A&D board. Follow organizations you care about, then come back here to read announcements and join discussions."
              className="py-12"
            />
            <div className="mt-4 flex justify-center">
              <Button asChild className={cn(ch.btn, 'rounded-xl')}>
                <Link href={route('organizations')}>Find organizations</Link>
              </Button>
            </div>
          </div>
        ) : (
          <div className="space-y-5">
            {boards.map((board) => (
              <section key={board.id} className={cn(ch.card, 'overflow-hidden', ch.cardHover)}>
                <div className="flex flex-col gap-4 border-b border-border px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                  <div className="flex min-w-0 items-center gap-3">
                    {board.image ? (
                      <img src={board.image} alt="" className="h-12 w-12 rounded-xl object-cover" />
                    ) : (
                      <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gradient-to-br from-purple-600 to-indigo-600 text-white">
                        <Building2 className="h-5 w-5" />
                      </div>
                    )}
                    <div className="min-w-0">
                      <h2 className="truncate text-lg font-semibold text-foreground">{board.name}</h2>
                      <p className="mt-0.5 text-xs text-muted-foreground">
                        View: Announcements — {audienceLabel(board.announcement_visibility, visibilityAudiences)}; Discussions —{' '}
                        {audienceLabel(board.discussion_visibility, visibilityAudiences)}
                      </p>
                    </div>
                  </div>
                  <div className="flex flex-wrap gap-2">
                    {board.can_create_discussion && (
                      <Button
                        type="button"
                        size="sm"
                        className={cn(ch.btn, 'gap-1.5 rounded-xl')}
                        onClick={() =>
                          setComposer({
                            name: board.name,
                            slug: board.slug,
                            categories: board.categories ?? [],
                          })
                        }
                      >
                        <Plus className="h-4 w-4" />
                        Start discussion
                      </Button>
                    )}
                    {board.hub_url && (
                      <Button asChild size="sm" variant="outline" className="gap-1.5 rounded-xl">
                        <Link href={board.hub_url}>
                          Open board
                          <ArrowRight className="h-4 w-4" />
                        </Link>
                      </Button>
                    )}
                  </div>
                </div>

                <div className="grid gap-4 p-4 sm:grid-cols-2 sm:p-5">
                  <div>
                    <div className="mb-3 flex items-center gap-2">
                      <div className={ch.iconWrap}>
                        <Megaphone className="h-4 w-4" />
                      </div>
                      <h3 className={ch.sectionTitle}>Announcements</h3>
                    </div>
                    {!board.can_view_announcements ? (
                      <p className="flex items-start gap-2 text-sm text-muted-foreground">
                        <Shield className="mt-0.5 h-4 w-4 shrink-0" />
                        Restricted by this organization.
                      </p>
                    ) : board.recent_announcements.length === 0 ? (
                      <p className="text-sm text-muted-foreground">No announcements yet.</p>
                    ) : (
                      <div className="space-y-2">
                        {board.recent_announcements.map((item) => (
                          <AnnouncementCard
                            key={item.id}
                            announcement={item}
                            href={route('organizations.communication-hub.announcements.show', {
                              slug: board.slug,
                              announcement: item.slug,
                            })}
                          />
                        ))}
                      </div>
                    )}
                  </div>

                  <div>
                    <div className="mb-3 flex items-center gap-2">
                      <div className={ch.iconWrap}>
                        <MessageSquare className="h-4 w-4" />
                      </div>
                      <h3 className={ch.sectionTitle}>Discussion Board</h3>
                    </div>
                    {!board.can_view_discussions ? (
                      <p className="flex items-start gap-2 text-sm text-muted-foreground">
                        <Shield className="mt-0.5 h-4 w-4 shrink-0" />
                        Restricted by this organization.
                      </p>
                    ) : board.recent_discussions.length === 0 ? (
                      <p className="text-sm text-muted-foreground">No discussions yet.</p>
                    ) : (
                      <div className="space-y-2">
                        {board.recent_discussions.map((item) => (
                          <DiscussionCard
                            key={item.id}
                            discussion={item}
                            href={route('organizations.communication-hub.discussions.show', {
                              slug: board.slug,
                              discussion: item.slug,
                            })}
                          />
                        ))}
                      </div>
                    )}
                  </div>
                </div>
              </section>
            ))}
          </div>
        )}
      </div>

      <StartDiscussionModal
        open={composer !== null}
        onOpenChange={(open) => {
          if (!open) setComposer(null)
        }}
        organizationName={composer?.name ?? ''}
        categories={composer?.categories ?? []}
        hubContext={{ mode: 'community', org_slug: composer?.slug ?? null }}
      />
    </FrontendLayout>
  )
}
