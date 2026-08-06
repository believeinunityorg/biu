import { useState } from 'react'
import { Head, router, useForm, usePage } from '@inertiajs/react'
import { Bell, BellOff, Calendar, Eye, Flag, Lock, MessageSquare, Paperclip, Pin, User as UserIcon } from 'lucide-react'
import AppLayout from '@/layouts/app-layout'
import FrontendLayout from '@/layouts/frontend/frontend-layout'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import ModerationMenu, { type ModerationAction } from '@/components/communication-hub/ModerationMenu'
import ReactionPicker from '@/components/communication-hub/ReactionPicker'
import DiscussionReply from '@/components/communication-hub/DiscussionReply'
import { type HubAuthor, type HubContext, type HubDiscussion, authorInitials, formatHubDate, formatHubRelative } from '@/components/communication-hub/types'
import { hubRoute } from '@/lib/communication-hub-routes'
import { ch } from '../theme'
import { cn } from '@/lib/utils'
import type { BreadcrumbItem } from '@/types'

type HubReply = {
  id: number
  discussion_id: number
  parent_id: number | null
  body: string
  attachments?: Array<{ path: string; name: string; url?: string }>
  is_hidden: boolean
  reactions_count: number
  user: HubAuthor
  replies: HubReply[]
  created_at: string | null
}

type Props = {
  organization: { id: number; name: string; slug?: string }
  discussion: HubDiscussion
  replies: HubReply[]
  isFollowing: boolean
  isMuted: boolean
  can: { update: boolean; delete: boolean; reply: boolean; react: boolean; moderate: boolean; report: boolean }
  reportReasons: Record<string, string>
  reactionEmojis?: string[]
  hubContext?: HubContext
}

type LocalPageProps = { auth?: { user?: { id: number } } } & Record<string, unknown>

type ReportTarget = { type: 'discussion' } | { type: 'reply'; replyId: number }

export default function DiscussionShow({
  organization,
  discussion,
  replies,
  isFollowing,
  isMuted,
  can,
  reportReasons,
  reactionEmojis,
  hubContext,
}: Props) {
  const { auth } = usePage<LocalPageProps>().props
  const currentUserId = auth?.user?.id
  const [reportTarget, setReportTarget] = useState<ReportTarget | null>(null)
  const ctx: HubContext = hubContext ?? { mode: 'manage', org_slug: organization.slug ?? null }
  const isCommunity = ctx.mode === 'community'

  const breadcrumbs: BreadcrumbItem[] = [
    { title: 'A&D Board', href: hubRoute('index', ctx) },
    { title: 'Discussions', href: hubRoute('discussions.index', ctx) },
    { title: discussion.title, href: hubRoute('discussions.show', ctx, discussion.slug) },
  ]

  const moderate = (action: string) => {
    if (isCommunity) return
    router.post(route('org.communication-hub.discussions.moderate', discussion.slug), { action }, { preserveScroll: true })
  }

  const destroy = () => {
    if (!confirm('Delete this discussion?')) return
    router.delete(hubRoute('discussions.destroy', ctx, discussion.slug))
  }

  const react = (emoji: string) => {
    router.post(hubRoute('discussions.react', ctx, discussion.slug), { emoji }, { preserveScroll: true })
  }

  const toggleFollow = () => {
    if (isFollowing) {
      router.delete(hubRoute('discussions.unfollow', ctx, discussion.slug), { preserveScroll: true })
    } else {
      router.post(hubRoute('discussions.follow', ctx, discussion.slug), {}, { preserveScroll: true })
    }
  }

  const toggleMute = () => {
    if (isMuted) {
      router.delete(hubRoute('discussions.unmute', ctx, discussion.slug), { preserveScroll: true })
    } else {
      router.post(hubRoute('discussions.mute', ctx, discussion.slug), {}, { preserveScroll: true })
    }
  }

  const canPostReply = can.reply && !discussion.is_locked && !discussion.is_archived

  const actions: ModerationAction[] = [
    ...(can.update && !discussion.is_locked && !discussion.is_archived && !isCommunity
      ? [{ label: 'Edit', onClick: () => router.visit(route('org.communication-hub.discussions.edit', discussion.slug)) }]
      : []),
    ...(can.moderate
      ? [
          { label: discussion.is_pinned ? 'Unpin' : 'Pin', onClick: () => moderate(discussion.is_pinned ? 'unpin' : 'pin') },
          { label: discussion.is_locked ? 'Unlock' : 'Lock', onClick: () => moderate(discussion.is_locked ? 'unlock' : 'lock') },
          { label: discussion.is_hidden ? 'Unhide' : 'Hide', onClick: () => moderate(discussion.is_hidden ? 'restore' : 'hide') },
          { label: discussion.is_archived ? 'Unarchive' : 'Archive', onClick: () => moderate(discussion.is_archived ? 'restore' : 'archive') },
          {
            label: discussion.posting_suspended ? 'Resume posting' : 'Suspend posting',
            onClick: () => moderate(discussion.posting_suspended ? 'unsuspend_posting' : 'suspend_posting'),
          },
          ...(!discussion.is_approved ? [{ label: 'Approve', onClick: () => moderate('approve') }] : []),
        ]
      : []),
    ...(can.delete ? [{ label: 'Delete', destructive: true, onClick: destroy }] : []),
  ]

  const content = (
    <>
      <Head title={`${discussion.title} — ${organization.name}`} />

      <div className={ch.pageNarrow}>
        <article className={cn(ch.card, 'p-5 sm:p-6')}>
          <div className="mb-3 flex flex-wrap items-center gap-2">
            {discussion.category && (
              <Badge className={cn('rounded-full border-0 px-2.5 py-0.5 text-xs font-medium', ch.badge)}>{discussion.category.name}</Badge>
            )}
            {discussion.is_pinned && (
              <Badge className="rounded-full border-0 bg-amber-500/15 dark:bg-amber-500/20 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-300">
                <Pin className="mr-1 h-3 w-3" />
                Pinned
              </Badge>
            )}
            {discussion.is_locked && (
              <Badge className="rounded-full border-0 bg-muted px-2.5 py-0.5 text-xs font-medium text-foreground/80">
                <Lock className="mr-1 h-3 w-3" />
                Locked
              </Badge>
            )}
            {discussion.is_archived && (
              <Badge variant="outline" className="rounded-full text-xs">
                Archived
              </Badge>
            )}
            {!discussion.is_approved && (
              <Badge variant="outline" className="rounded-full text-xs text-amber-700 dark:text-amber-300">
                Pending approval
              </Badge>
            )}
          </div>

          <div className="flex items-start justify-between gap-3">
            <h1 className={ch.heading}>{discussion.title}</h1>
            {actions.length > 0 && <ModerationMenu actions={actions} />}
          </div>

          <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
            <span className="inline-flex items-center gap-1.5">
              <UserIcon className="h-4 w-4" />
              {discussion.author?.name ?? 'Member'}
            </span>
            {discussion.created_at && (
              <span className="inline-flex items-center gap-1.5">
                <Calendar className="h-4 w-4" />
                {formatHubDate(discussion.created_at)}
              </span>
            )}
            <span className="inline-flex items-center gap-1.5">
              <Eye className="h-4 w-4" />
              {discussion.views_count} views
            </span>
            <span className="inline-flex items-center gap-1.5">
              <MessageSquare className="h-4 w-4" />
              {discussion.replies_count} replies
            </span>
          </div>

          <div className="mt-4 whitespace-pre-wrap text-[15px] leading-relaxed text-foreground/80">{discussion.body}</div>

          {!!discussion.attachments?.length && (
            <div className="mt-4 space-y-2 border-t border-border pt-4">
              <p className="text-sm font-medium text-foreground/80">Attachments</p>
              <ul className="space-y-1.5">
                {discussion.attachments.map((file, i) => (
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

          <div className="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
            <div className="flex items-center gap-2">
              {can.react && <ReactionPicker emojis={reactionEmojis} onSelect={react} />}
              {discussion.reactions_count > 0 && (
                <span className="text-sm text-muted-foreground">
                  {discussion.reactions_count} reaction{discussion.reactions_count === 1 ? '' : 's'}
                </span>
              )}
            </div>
            <div className="flex items-center gap-2">
              {can.report && (
                <Button variant="ghost" size="sm" className="gap-1.5 text-muted-foreground" onClick={() => setReportTarget({ type: 'discussion' })}>
                  <Flag className="h-3.5 w-3.5" />
                  Report
                </Button>
              )}
              <Button
                variant="outline"
                size="sm"
                className={cn(isFollowing ? ch.btnOutline : 'border-border')}
                onClick={toggleFollow}
              >
                {isFollowing ? 'Following' : 'Follow'}
              </Button>
              {isFollowing && (
                <Button variant="ghost" size="sm" className="gap-1.5 text-muted-foreground" onClick={toggleMute}>
                  {isMuted ? <BellOff className="h-3.5 w-3.5" /> : <Bell className="h-3.5 w-3.5" />}
                  {isMuted ? 'Muted' : 'Notify'}
                </Button>
              )}
            </div>
          </div>
        </article>

        <section className={cn(ch.card, 'p-5 sm:p-6')}>
          <h2 className="mb-4 text-lg font-semibold text-foreground">
            Replies {discussion.replies_count ? `(${discussion.replies_count})` : ''}
          </h2>

          {canPostReply ? (
            <div className="mb-6">
              <DiscussionReply action={hubRoute('discussions.reply', ctx, discussion.slug)} />
            </div>
          ) : discussion.is_locked ? (
            <p className="mb-6 text-sm text-muted-foreground">This discussion is locked and no longer accepting replies.</p>
          ) : discussion.is_archived ? (
            <p className="mb-6 text-sm text-muted-foreground">This discussion has been archived.</p>
          ) : null}

          {replies.length === 0 ? (
            <p className="text-sm text-muted-foreground">No replies yet. Be the first to respond.</p>
          ) : (
            <div className="space-y-4">
              {replies.map((reply) => (
                <ReplyItem
                  key={reply.id}
                  reply={reply}
                  discussionSlug={discussion.slug}
                  hubContext={ctx}
                  canModerate={can.moderate}
                  canReply={canPostReply}
                  canReact={can.react}
                  canReport={can.report}
                  currentUserId={currentUserId}
                  reactionEmojis={reactionEmojis}
                  onReport={(replyId) => setReportTarget({ type: 'reply', replyId })}
                />
              ))}
            </div>
          )}
        </section>
      </div>

      <ReportDialog
        target={reportTarget}
        discussionSlug={discussion.slug}
        hubContext={ctx}
        reportReasons={reportReasons}
        onClose={() => setReportTarget(null)}
      />
    </>
  )

  if (isCommunity) {
    return <FrontendLayout>{content}</FrontendLayout>
  }

  return <AppLayout breadcrumbs={breadcrumbs}>{content}</AppLayout>
}

function ReplyItem({
  reply,
  discussionSlug,
  hubContext,
  canModerate,
  canReply,
  canReact,
  canReport,
  currentUserId,
  reactionEmojis,
  onReport,
  depth = 0,
}: {
  reply: HubReply
  discussionSlug: string
  hubContext: HubContext
  canModerate: boolean
  canReply: boolean
  canReact: boolean
  canReport: boolean
  currentUserId?: number
  reactionEmojis?: string[]
  onReport: (replyId: number) => void
  depth?: number
}) {
  const [replying, setReplying] = useState(false)
  const [editing, setEditing] = useState(false)
  const isOwner = currentUserId != null && reply.user?.id === currentUserId

  const editForm = useForm({ body: reply.body })

  const submitEdit = (e: React.FormEvent) => {
    e.preventDefault()
    editForm.transform((data) => ({ ...data, _method: 'put' }))
    editForm.post(hubRoute('discussions.replies.update', hubContext, discussionSlug, reply.id), {
      preserveScroll: true,
      onSuccess: () => setEditing(false),
    })
  }

  const destroy = () => {
    if (!confirm('Delete this reply?')) return
    router.delete(hubRoute('discussions.replies.destroy', hubContext, discussionSlug, reply.id), { preserveScroll: true })
  }

  const react = (emoji: string) => {
    router.post(hubRoute('discussions.replies.react', hubContext, discussionSlug, reply.id), { emoji }, { preserveScroll: true })
  }

  const actions: ModerationAction[] = [
    ...(isOwner ? [{ label: 'Edit', onClick: () => setEditing(true) }] : []),
    ...(isOwner || canModerate ? [{ label: 'Delete', destructive: true, onClick: destroy }] : []),
  ]

  return (
    <div className={cn(depth > 0 && 'ml-6 border-l-2 border-purple-500/20 pl-4 sm:ml-10')}>
      <div className="flex gap-3">
        {reply.user?.avatar_url ? (
          <img src={reply.user.avatar_url} alt="" className="h-9 w-9 shrink-0 rounded-full object-cover" />
        ) : (
          <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-indigo-600 text-xs font-bold text-white">
            {authorInitials(reply.user?.name)}
          </div>
        )}
        <div className="min-w-0 flex-1">
          <div className="flex items-start justify-between gap-2">
            <div>
              <span className="text-sm font-semibold text-foreground">{reply.user?.name ?? 'Member'}</span>
              <span className="ml-2 text-xs text-muted-foreground">{formatHubRelative(reply.created_at)}</span>
              {reply.is_hidden && (
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
            <p className="mt-1 whitespace-pre-wrap text-sm text-foreground/80">{reply.body}</p>
          )}

          {!!reply.attachments?.length && (
            <ul className="mt-2 space-y-1">
              {reply.attachments.map((file, i) => (
                <li key={i}>
                  <a
                    href={file.url ?? '#'}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1.5 text-xs text-purple-600 hover:text-blue-600 dark:hover:text-blue-400"
                  >
                    <Paperclip className="h-3 w-3" />
                    {file.name}
                  </a>
                </li>
              ))}
            </ul>
          )}

          <div className="mt-1.5 flex flex-wrap items-center gap-3">
            {canReact && <ReactionPicker emojis={reactionEmojis} onSelect={react} className="gap-0" />}
            {reply.reactions_count > 0 && <span className="text-xs text-muted-foreground">{reply.reactions_count}</span>}
            {canReply && !editing && (
              <button type="button" onClick={() => setReplying((v) => !v)} className="text-xs font-medium text-purple-600 hover:text-blue-600 dark:hover:text-blue-400">
                Reply
              </button>
            )}
            {canReport && (
              <button type="button" onClick={() => onReport(reply.id)} className="text-xs font-medium text-muted-foreground hover:text-muted-foreground">
                Report
              </button>
            )}
          </div>

          {replying && (
            <div className="mt-2">
              <NestedReplyForm
                discussionSlug={discussionSlug}
                hubContext={hubContext}
                parentId={reply.id}
                onDone={() => setReplying(false)}
              />
            </div>
          )}

          {reply.replies?.length > 0 && (
            <div className="mt-3 space-y-3">
              {reply.replies.map((child) => (
                <ReplyItem
                  key={child.id}
                  reply={child}
                  discussionSlug={discussionSlug}
                  hubContext={hubContext}
                  canModerate={canModerate}
                  canReply={canReply}
                  canReact={canReact}
                  canReport={canReport}
                  currentUserId={currentUserId}
                  reactionEmojis={reactionEmojis}
                  onReport={onReport}
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

function NestedReplyForm({
  discussionSlug,
  hubContext,
  parentId,
  onDone,
}: {
  discussionSlug: string
  hubContext: HubContext
  parentId: number
  onDone: () => void
}) {
  const form = useForm({ body: '', parent_id: parentId as number | null })

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    form.post(hubRoute('discussions.reply', hubContext, discussionSlug), {
      preserveScroll: true,
      onSuccess: () => {
        form.reset('body')
        onDone()
      },
    })
  }

  return (
    <form onSubmit={submit} className="space-y-2">
      <textarea
        value={form.data.body}
        onChange={(e) => form.setData('body', e.target.value)}
        rows={2}
        placeholder="Write a reply…"
        className={cn('w-full px-3 py-2 text-sm', ch.input, ch.focus)}
        required
      />
      {form.errors.body && <p className="text-sm text-destructive">{form.errors.body}</p>}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="ghost" size="sm" onClick={onDone}>
          Cancel
        </Button>
        <Button type="submit" size="sm" className={ch.btnSm} disabled={form.processing}>
          Reply
        </Button>
      </div>
    </form>
  )
}

function ReportDialog({
  target,
  discussionSlug,
  hubContext,
  reportReasons,
  onClose,
}: {
  target: ReportTarget | null
  discussionSlug: string
  hubContext: HubContext
  reportReasons: Record<string, string>
  onClose: () => void
}) {
  const form = useForm({ reason: '', details: '' })

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!target) return

    const action =
      target.type === 'discussion'
        ? hubRoute('discussions.report', hubContext, discussionSlug)
        : hubRoute('discussions.replies.report', hubContext, discussionSlug, target.replyId)

    form.post(action, {
      preserveScroll: true,
      onSuccess: () => {
        form.reset()
        onClose()
      },
    })
  }

  return (
    <Dialog open={target !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Report {target?.type === 'reply' ? 'reply' : 'discussion'}</DialogTitle>
        </DialogHeader>
        <form onSubmit={submit} className="space-y-4">
          <div className="space-y-2">
            <label className="text-sm font-medium text-foreground/80">Reason</label>
            <select
              value={form.data.reason}
              onChange={(e) => form.setData('reason', e.target.value)}
              className={cn('h-10 w-full px-3 text-sm', ch.input, ch.focus)}
              required
            >
              <option value="">Select a reason</option>
              {Object.entries(reportReasons).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
            {form.errors.reason && <p className="text-sm text-destructive">{form.errors.reason}</p>}
          </div>
          <div className="space-y-2">
            <label className="text-sm font-medium text-foreground/80">Additional details (optional)</label>
            <textarea
              value={form.data.details}
              onChange={(e) => form.setData('details', e.target.value)}
              rows={3}
              className={cn('w-full px-3 py-2 text-sm', ch.input, ch.focus)}
            />
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={onClose}>
              Cancel
            </Button>
            <Button type="submit" className={ch.btn} disabled={form.processing}>
              {form.processing ? 'Submitting…' : 'Submit Report'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
