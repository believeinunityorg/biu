import { useState } from 'react'
import { Head, router, useForm, usePage } from '@inertiajs/react'
import {
  Calendar,
  History as HistoryIcon,
  MessageSquare,
  Paperclip,
  Pin,
  User as UserIcon,
} from 'lucide-react'
import AppLayout from '@/layouts/app-layout'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import ModerationMenu, { type ModerationAction } from '@/components/communication-hub/ModerationMenu'
import { type HubAnnouncement, type HubAuthor, authorInitials, formatHubDate, formatHubRelative } from '@/components/communication-hub/types'
import { ch } from '../theme'
import { cn } from '@/lib/utils'
import type { BreadcrumbItem } from '@/types'

type HubComment = {
  id: number
  announcement_id: number
  parent_id: number | null
  body: string
  is_hidden: boolean
  is_locked: boolean
  user: HubAuthor
  replies: HubComment[]
  created_at: string | null
}

type HubHistoryEntry = {
  id: number
  title: string
  message: string
  meta: Record<string, unknown> | null
  editor: { id: number; name: string } | null
  created_at: string | null
}

type Props = {
  organization: { id: number; name: string }
  announcement: HubAnnouncement
  comments: HubComment[]
  history: HubHistoryEntry[]
  can: { manage: boolean; comment: boolean }
}

type LocalPageProps = {
  auth?: { user?: { id: number } }
} & Record<string, unknown>

export default function AnnouncementShow({ organization, announcement, comments, history, can }: Props) {
  const { auth } = usePage<LocalPageProps>().props
  const currentUserId = auth?.user?.id

  const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Communication Hub', href: route('org.communication-hub.index') },
    { title: 'Announcements', href: route('org.communication-hub.announcements.index') },
    { title: announcement.title, href: route('org.communication-hub.announcements.show', announcement.slug) },
  ]

  const moderate = (action: string) => {
    router.post(route('org.communication-hub.announcements.moderate', announcement.slug), { action }, { preserveScroll: true })
  }

  const destroy = () => {
    if (!confirm('Delete this announcement?')) return
    router.delete(route('org.communication-hub.announcements.destroy', announcement.slug))
  }

  const commentForm = useForm({ body: '', parent_id: null as number | null })

  const submitComment = (e: React.FormEvent) => {
    e.preventDefault()
    commentForm.post(route('org.communication-hub.announcements.comments.store', announcement.slug), {
      preserveScroll: true,
      onSuccess: () => commentForm.reset('body'),
    })
  }

  const manageActions: ModerationAction[] = can.manage
    ? [
        { label: 'Edit', onClick: () => router.visit(route('org.communication-hub.announcements.edit', announcement.slug)) },
        ...(announcement.status !== 'published' ? [{ label: 'Publish now', onClick: () => moderate('publish') }] : []),
        { label: announcement.is_pinned ? 'Unpin' : 'Pin', onClick: () => moderate(announcement.is_pinned ? 'unpin' : 'pin') },
        { label: announcement.allow_comments ? 'Disable comments' : 'Enable comments', onClick: () => moderate('toggle_comments') },
        ...(announcement.status === 'archived' || announcement.status === 'hidden'
          ? [{ label: 'Restore', onClick: () => moderate('restore') }]
          : [{ label: 'Archive', onClick: () => moderate('archive') }]),
        ...(announcement.status !== 'hidden' ? [{ label: 'Hide', onClick: () => moderate('hide') }] : []),
        { label: 'Delete', destructive: true, onClick: destroy },
      ]
    : []

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={`${announcement.title} — ${organization.name}`} />

      <div className={ch.pageNarrow}>
        <article className={cn(ch.card, 'overflow-hidden')}>
          <div className="h-48 w-full overflow-hidden bg-gradient-to-br from-purple-600 to-blue-600 sm:h-64">
            {announcement.cover_image_url ? (
              <img src={announcement.cover_image_url} alt="" className="h-full w-full object-cover" />
            ) : (
              <div className="flex h-full w-full items-center justify-center text-lg font-semibold uppercase tracking-wide text-white/90">
                {announcement.category || 'Announcement'}
              </div>
            )}
          </div>

          <div className="space-y-4 p-5 sm:p-6">
            <div className="flex flex-wrap items-center gap-2">
              {announcement.category && (
                <Badge className={cn('rounded-full border-0 px-2.5 py-0.5 text-xs font-medium', ch.badge)}>
                  {announcement.category}
                </Badge>
              )}
              {announcement.is_pinned && (
                <Badge className="rounded-full border-0 bg-amber-500/15 dark:bg-amber-500/20 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-300">
                  <Pin className="mr-1 h-3 w-3" />
                  Pinned
                </Badge>
              )}
              {announcement.status && announcement.status !== 'published' && (
                <Badge variant="outline" className="rounded-full text-xs capitalize">
                  {announcement.status_label || announcement.status}
                </Badge>
              )}
            </div>

            <div className="flex items-start justify-between gap-3">
              <h1 className={ch.heading}>{announcement.title}</h1>
              {manageActions.length > 0 && <ModerationMenu actions={manageActions} />}
            </div>

            <div className="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
              {announcement.author?.name && (
                <span className="inline-flex items-center gap-1.5">
                  <UserIcon className="h-4 w-4" />
                  {announcement.author.name}
                </span>
              )}
              {announcement.published_at && (
                <span className="inline-flex items-center gap-1.5">
                  <Calendar className="h-4 w-4" />
                  {formatHubDate(announcement.published_at)}
                </span>
              )}
            </div>

            <div className="whitespace-pre-wrap text-[15px] leading-relaxed text-foreground/80">{announcement.message}</div>

            {!!announcement.attachments?.length && (
              <div className="space-y-2 border-t border-border pt-4">
                <p className="text-sm font-medium text-foreground/80">Attachments</p>
                <ul className="space-y-1.5">
                  {announcement.attachments.map((file, i) => (
                    <li key={i}>
                      <a
                        href={file.url ?? '#'}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1.5 text-sm text-purple-600 hover:text-blue-600 dark:hover:text-blue-400"
                      >
                        <Paperclip className="h-3.5 w-3.5" />
                        {file.name}
                      </a>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>
        </article>

        {/* Comments */}
        <section className={cn(ch.card, 'p-5 sm:p-6')}>
          <div className="mb-4 flex items-center gap-2">
            <MessageSquare className={cn('h-5 w-5', ch.text)} />
            <h2 className={ch.sectionTitle}>
              Comments {announcement.comments_count ? `(${announcement.comments_count})` : ''}
            </h2>
          </div>

          {can.comment && announcement.allow_comments ? (
            <form onSubmit={submitComment} className="mb-6 space-y-2">
              <textarea
                value={commentForm.data.body}
                onChange={(e) => commentForm.setData('body', e.target.value)}
                rows={3}
                placeholder="Write a comment…"
                className={cn('w-full px-3 py-2 text-sm', ch.input, ch.focus)}
                required
              />
              {commentForm.errors.body && <p className="text-sm text-destructive">{commentForm.errors.body}</p>}
              <div className="flex justify-end">
                <Button type="submit" className={ch.btnSm} disabled={commentForm.processing}>
                  {commentForm.processing ? 'Posting…' : 'Post Comment'}
                </Button>
              </div>
            </form>
          ) : (
            !announcement.allow_comments && (
              <p className="mb-6 text-sm text-muted-foreground">Comments are disabled for this announcement.</p>
            )
          )}

          {comments.length === 0 ? (
            <p className="text-sm text-muted-foreground">No comments yet.</p>
          ) : (
            <div className="space-y-4">
              {comments.map((comment) => (
                <CommentItem
                  key={comment.id}
                  comment={comment}
                  announcementSlug={announcement.slug}
                  canManage={can.manage}
                  canComment={can.comment && announcement.allow_comments}
                  currentUserId={currentUserId}
                />
              ))}
            </div>
          )}
        </section>

        {/* History */}
        {can.manage && history.length > 0 && (
          <section className={cn(ch.card, 'p-5 sm:p-6')}>
            <div className="mb-4 flex items-center gap-2">
              <HistoryIcon className="h-5 w-5 text-purple-600" />
              <h2 className="text-lg font-semibold text-foreground">Edit History</h2>
            </div>
            <ul className="space-y-3">
              {history.map((entry) => (
                <li key={entry.id} className="rounded-lg border border-border p-3">
                  <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                    <span className="font-medium text-foreground/80">{entry.editor?.name ?? 'Unknown editor'}</span>
                    <span className="text-muted-foreground">{formatHubRelative(entry.created_at)}</span>
                  </div>
                  <p className="mt-1 text-sm font-medium text-foreground">{entry.title}</p>
                  <p className="mt-1 line-clamp-3 text-sm text-muted-foreground">{entry.message}</p>
                </li>
              ))}
            </ul>
          </section>
        )}
      </div>
    </AppLayout>
  )
}

function CommentItem({
  comment,
  announcementSlug,
  canManage,
  canComment,
  currentUserId,
  depth = 0,
}: {
  comment: HubComment
  announcementSlug: string
  canManage: boolean
  canComment: boolean
  currentUserId?: number
  depth?: number
}) {
  const [replying, setReplying] = useState(false)
  const [editing, setEditing] = useState(false)
  const isOwner = currentUserId != null && comment.user?.id === currentUserId

  const replyForm = useForm({ body: '', parent_id: comment.id as number | null })
  const editForm = useForm({ body: comment.body })

  const submitReply = (e: React.FormEvent) => {
    e.preventDefault()
    replyForm.post(route('org.communication-hub.announcements.comments.store', announcementSlug), {
      preserveScroll: true,
      onSuccess: () => {
        replyForm.reset('body')
        setReplying(false)
      },
    })
  }

  const submitEdit = (e: React.FormEvent) => {
    e.preventDefault()
    editForm.transform((data) => ({ ...data, _method: 'put' }))
    editForm.post(route('org.communication-hub.announcements.comments.update', [announcementSlug, comment.id]), {
      preserveScroll: true,
      onSuccess: () => setEditing(false),
    })
  }

  const destroy = () => {
    if (!confirm('Delete this comment?')) return
    router.delete(route('org.communication-hub.announcements.comments.destroy', [announcementSlug, comment.id]), {
      preserveScroll: true,
    })
  }

  const moderate = (action: string) => {
    router.post(
      route('org.communication-hub.announcements.comments.moderate', [announcementSlug, comment.id]),
      { action },
      { preserveScroll: true },
    )
  }

  const actions: ModerationAction[] = [
    ...(isOwner && !comment.is_locked ? [{ label: 'Edit', onClick: () => setEditing(true) }] : []),
    ...(canManage ? [{ label: comment.is_hidden ? 'Unhide' : 'Hide', onClick: () => moderate(comment.is_hidden ? 'restore' : 'hide') }] : []),
    ...(canManage ? [{ label: comment.is_locked ? 'Unlock replies' : 'Lock replies', onClick: () => moderate(comment.is_locked ? 'unlock' : 'lock') }] : []),
    ...(isOwner || canManage ? [{ label: 'Delete', destructive: true, onClick: destroy }] : []),
  ]

  return (
    <div className={cn(depth > 0 && 'ml-6 border-l-2 border-purple-500/20 pl-4 sm:ml-10')}>
      <div className="flex gap-3">
        {comment.user?.avatar_url ? (
          <img src={comment.user.avatar_url} alt="" className="h-9 w-9 shrink-0 rounded-full object-cover" />
        ) : (
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-indigo-600 text-xs font-bold text-white">
            {authorInitials(comment.user?.name)}
          </div>
        )}
        <div className="min-w-0 flex-1">
          <div className="flex items-start justify-between gap-2">
            <div>
              <span className="text-sm font-semibold text-foreground">{comment.user?.name ?? 'Member'}</span>
              <span className="ml-2 text-xs text-muted-foreground">{formatHubRelative(comment.created_at)}</span>
              {comment.is_hidden && (
                <Badge variant="outline" className="ml-2 rounded-full text-[10px]">
                  Hidden
                </Badge>
              )}
            </div>
            {actions.length > 0 && <ModerationMenu actions={actions} />}
          </div>

          {editing ? (
            <form onSubmit={submitEdit} className="mt-1.5 space-y-2">
              <textarea
                value={editForm.data.body}
                onChange={(e) => editForm.setData('body', e.target.value)}
                rows={3}
                className={cn('w-full px-3 py-2 text-sm', ch.input, ch.focus)}
              />
              <div className="flex justify-end gap-2">
                <Button type="button" variant="ghost" size="sm" onClick={() => setEditing(false)}>
                  Cancel
                </Button>
                <Button type="submit" size="sm" className={ch.btnSm} disabled={editForm.processing}>
                  Save
                </Button>
              </div>
            </form>
          ) : (
            <p className="mt-1 whitespace-pre-wrap text-sm text-foreground/80">{comment.body}</p>
          )}

          {canComment && !comment.is_locked && !editing && (
            <button
              type="button"
              onClick={() => setReplying((v) => !v)}
              className="mt-1.5 text-xs font-medium text-purple-600 hover:text-blue-600 dark:hover:text-blue-400"
            >
              Reply
            </button>
          )}

          {replying && (
            <form onSubmit={submitReply} className="mt-2 space-y-2">
              <textarea
                value={replyForm.data.body}
                onChange={(e) => replyForm.setData('body', e.target.value)}
                rows={2}
                placeholder="Write a reply…"
                className={cn('w-full px-3 py-2 text-sm', ch.input, ch.focus)}
                required
              />
              <div className="flex justify-end gap-2">
                <Button type="button" variant="ghost" size="sm" onClick={() => setReplying(false)}>
                  Cancel
                </Button>
                <Button type="submit" size="sm" className={ch.btnSm} disabled={replyForm.processing}>
                  Reply
                </Button>
              </div>
            </form>
          )}

          {comment.replies?.length > 0 && (
            <div className="mt-3 space-y-3">
              {comment.replies.map((child) => (
                <CommentItem
                  key={child.id}
                  comment={child}
                  announcementSlug={announcementSlug}
                  canManage={canManage}
                  canComment={canComment}
                  currentUserId={currentUserId}
                  depth={depth + 1}
                />
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
