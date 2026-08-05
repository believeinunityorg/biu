import { Link } from '@inertiajs/react'
import { Calendar, User } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import ModerationMenu, { type ModerationAction } from './ModerationMenu'
import { type HubAnnouncement, formatHubDate } from './types'
import { ch } from '@/pages/Organization/CommunicationHub/theme'
import { cn } from '@/lib/utils'

type Props = {
  announcement: HubAnnouncement
  href: string
  actions?: ModerationAction[]
  showMetaIcons?: boolean
  className?: string
}

export default function AnnouncementCard({
  announcement,
  href,
  actions = [],
  showMetaIcons = true,
  className,
}: Props) {
  return (
    <article className={cn(ch.card, ch.cardHover, 'overflow-hidden', className)}>
      <div className="flex flex-col gap-3 p-3 sm:flex-row sm:gap-4 sm:p-4">
        <Link href={href} className="block shrink-0">
          <div className="h-32 w-full overflow-hidden rounded-xl bg-gradient-to-br from-purple-600 to-blue-600 sm:h-24 sm:w-32">
            {announcement.cover_image_url ? (
              <img
                src={announcement.cover_image_url}
                alt=""
                className="h-full w-full object-cover"
                loading="lazy"
              />
            ) : (
              <div className="flex h-full w-full items-center justify-center px-2 text-center text-xs font-semibold uppercase tracking-wide text-white/90">
                {announcement.category || 'Announcement'}
              </div>
            )}
          </div>
        </Link>

        <div className="min-w-0 flex-1">
          <div className="flex items-start justify-between gap-2">
            <Link href={href} className="min-w-0">
              <h3 className="line-clamp-2 text-base font-semibold text-foreground transition-colors hover:text-purple-600 dark:hover:text-purple-400">
                {announcement.title}
              </h3>
            </Link>
            {actions.length > 0 && <ModerationMenu actions={actions} />}
          </div>

          {showMetaIcons && (
            <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
              {announcement.published_at && (
                <span className="inline-flex items-center gap-1">
                  <Calendar className="h-3.5 w-3.5" />
                  {formatHubDate(announcement.published_at)}
                </span>
              )}
              {announcement.author?.name && (
                <span className="inline-flex items-center gap-1">
                  <User className="h-3.5 w-3.5" />
                  {announcement.author.name}
                </span>
              )}
            </div>
          )}

          <p className="mt-2 line-clamp-2 text-sm text-muted-foreground">{announcement.excerpt}</p>

          <div className="mt-3 flex flex-wrap items-center gap-2">
            {announcement.category && (
              <Badge className={ch.badge}>{announcement.category}</Badge>
            )}
            {announcement.is_pinned && (
              <Badge className={ch.badgePinned}>Pinned</Badge>
            )}
            {announcement.status && announcement.status !== 'published' && (
              <Badge variant="outline" className="rounded-full text-xs capitalize">
                {announcement.status_label || announcement.status}
              </Badge>
            )}
          </div>
        </div>
      </div>
    </article>
  )
}
