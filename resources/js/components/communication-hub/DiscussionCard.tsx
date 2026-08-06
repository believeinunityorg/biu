import { Link } from '@inertiajs/react'
import { Eye, Lock, MessageSquare, Pin } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import ModerationMenu, { type ModerationAction } from './ModerationMenu'
import { type HubDiscussion, authorInitials, formatHubRelative } from './types'
import { ch } from '@/pages/Organization/CommunicationHub/theme'
import { cn } from '@/lib/utils'

type Props = {
  discussion: HubDiscussion
  href: string
  actions?: ModerationAction[]
  className?: string
}

export default function DiscussionCard({ discussion, href, actions = [], className }: Props) {
  const authorName = discussion.author?.name ?? 'Member'

  return (
    <article
      className={cn(
        'flex gap-3 border-b border-border py-4 last:border-0',
        discussion.is_unread && cn(ch.unread, '-mx-2 rounded-xl px-2'),
        className,
      )}
    >
      <div className="relative shrink-0">
        {discussion.author?.avatar_url ? (
          <img
            src={discussion.author.avatar_url}
            alt=""
            className="h-10 w-10 rounded-full object-cover ring-2 ring-background"
            loading="lazy"
          />
        ) : (
          <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-blue-600 text-xs font-bold text-white ring-2 ring-background">
            {authorInitials(authorName)}
          </div>
        )}
        {discussion.is_unread && (
          <span className="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-purple-600 ring-2 ring-background" />
        )}
      </div>

      <div className="min-w-0 flex-1">
        <div className="flex items-start gap-2">
          <div className="min-w-0 flex-1">
            <Link href={href} className="block">
              <div className="flex flex-wrap items-center gap-2">
                <h3 className="text-[15px] font-semibold text-foreground transition-colors hover:text-purple-600 dark:hover:text-purple-400">
                  {discussion.title}
                </h3>
                {discussion.is_pinned && (
                  <Badge className={cn(ch.badgePinned, 'px-2 py-0 text-[10px]')}>
                    <Pin className="mr-0.5 h-2.5 w-2.5" />
                    Pinned
                  </Badge>
                )}
                {discussion.is_locked && (
                  <Badge className={cn(ch.badgeLocked, 'px-2 py-0 text-[10px]')}>
                    <Lock className="mr-0.5 h-2.5 w-2.5" />
                    Locked
                  </Badge>
                )}
              </div>
            </Link>
            <p className="mt-0.5 text-xs text-muted-foreground">
              Started by <span className="font-medium text-foreground/80">{authorName}</span>
              {' · '}
              {formatHubRelative(discussion.created_at || discussion.last_activity_at)}
              {discussion.category && (
                <>
                  {' in '}
                  <span className={ch.text}>{discussion.category.name}</span>
                </>
              )}
            </p>
            <p className="mt-1.5 line-clamp-2 text-sm text-muted-foreground">{discussion.excerpt}</p>

            {/* Mobile stats under content */}
            <div className="mt-2 flex items-center gap-3 text-xs text-muted-foreground sm:hidden">
              <span className="inline-flex items-center gap-1">
                <MessageSquare className="h-3.5 w-3.5" />
                {discussion.replies_count}
              </span>
              <span className="inline-flex items-center gap-1">
                <Eye className="h-3.5 w-3.5" />
                {discussion.views_count}
              </span>
            </div>
          </div>

          <div className="hidden shrink-0 items-center gap-3 text-xs text-muted-foreground sm:flex">
            <span className="inline-flex items-center gap-1" title="Replies">
              <MessageSquare className="h-3.5 w-3.5" />
              {discussion.replies_count}
            </span>
            <span className="inline-flex items-center gap-1" title="Views">
              <Eye className="h-3.5 w-3.5" />
              {discussion.views_count}
            </span>
            {actions.length > 0 && <ModerationMenu actions={actions} vertical />}
          </div>

          {actions.length > 0 && (
            <div className="sm:hidden">
              <ModerationMenu actions={actions} vertical />
            </div>
          )}
        </div>
      </div>
    </article>
  )
}
