"use client"

import { FormEvent, useMemo, useState } from "react"
import { Head, Link, router, useForm } from "@inertiajs/react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import ReportDialog from "@/components/community/ReportDialog"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Textarea } from "@/components/frontend/ui/textarea"
import { Badge } from "@/components/ui/badge"
import {
  ArrowLeft,
  Heart,
  History,
  Lock,
  Megaphone,
  MessageSquare,
  Paperclip,
  Pin,
} from "lucide-react"

type Reply = {
  id: number
  body: string
  parent_reply_id: number | null
  created_at: string | null
  author: { id: number; name: string } | null
  likes_count: number
  liked_by_me: boolean
  attachment_url?: string | null
  attachment_name?: string | null
}

type Version = {
  id: number
  title: string
  body: string
  created_at: string | null
  editor: { id: number; name: string } | null
}

type Props = {
  content: {
    id: number
    slug: string
    type: string
    title: string
    body: string
    category: string | null
    is_pinned: boolean
    is_locked: boolean
    comments_enabled: boolean
    visibility_status: string
    published_at: string | null
    expires_at: string | null
    archived_at: string | null
    author: { id: number; name: string } | null
    likes_count: number
    liked_by_me: boolean
    cover_image: string | null
    attachment_url: string | null
    attachment_name: string | null
    parent_name: string | null
    replies_count: number
  }
  replies: Reply[]
  versions: Version[]
  following: boolean
  muted: boolean
  canModerate: boolean
  canUpdate: boolean
  canReply: boolean
  backUrl: string
  reportReasons: Record<string, string>
}

const fieldClass =
  "border-gray-300 bg-white text-gray-900 placeholder:text-gray-500 focus-visible:border-purple-500 focus-visible:ring-purple-500/30 dark:border-white/15 dark:bg-[#0a0f1a] dark:text-white dark:placeholder:text-gray-500"

function initials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join("")
}

function formatWhen(iso: string | null) {
  if (!iso) return ""
  try {
    return new Date(iso).toLocaleString(undefined, {
      month: "short",
      day: "numeric",
      year: "numeric",
      hour: "numeric",
      minute: "2-digit",
    })
  } catch {
    return iso
  }
}

function renderBodyWithMentions(body: string) {
  const parts = body.split(/(@[A-Za-z][A-Za-z0-9._ -]{0,60})/g)
  return parts.map((part, i) =>
    part.startsWith("@") ? (
      <span key={i} className="font-medium text-purple-600 dark:text-purple-300">
        {part}
      </span>
    ) : (
      <span key={i}>{part}</span>
    ),
  )
}

export default function ContentShow({
  content,
  replies,
  versions,
  following,
  muted,
  canModerate,
  canUpdate,
  canReply,
  backUrl,
  reportReasons,
}: Props) {
  const [replyTo, setReplyTo] = useState<number | null>(null)
  const [editing, setEditing] = useState(false)
  const [showHistory, setShowHistory] = useState(false)
  const contentKey = content.slug
  const form = useForm({
    body: "",
    parent_reply_id: null as number | null,
    attachment: null as File | null,
  })
  const editForm = useForm({
    title: content.title,
    body: content.body,
    category: content.category ?? "",
    is_pinned: content.is_pinned,
    comments_enabled: content.comments_enabled,
    expires_at: content.expires_at ? content.expires_at.slice(0, 16) : "",
    archive: false,
    cover_image: null as File | null,
    attachment: null as File | null,
  })

  const submitReply = (e: FormEvent) => {
    e.preventDefault()
    form.transform((data) => ({
      ...data,
      parent_reply_id: replyTo,
    }))
    form.post(route("community.contents.reply", contentKey), {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        form.reset("body", "attachment")
        setReplyTo(null)
      },
    })
  }

  const submitEdit = (e: FormEvent) => {
    e.preventDefault()
    editForm.transform((data) => ({
      ...data,
      expires_at: data.expires_at || null,
      category: data.category || null,
    }))
    editForm.post(route("community.contents.update", contentKey), {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => setEditing(false),
    })
  }

  const topLevel = useMemo(() => replies.filter((r) => !r.parent_reply_id), [replies])
  const childrenOf = (id: number) => replies.filter((r) => r.parent_reply_id === id)
  const TypeIcon = content.type === "announcement" ? Megaphone : MessageSquare
  const replyTarget = replyTo ? replies.find((r) => r.id === replyTo) : null

  return (
    <FrontendLayout>
      <Head title={content.title} />
      <div className="min-h-screen bg-gray-50 text-gray-900 dark:bg-[#0a0f1a] dark:text-white">
        <div className="relative">
          <div className="relative h-40 w-full sm:h-52 md:h-60">
            <div className="absolute inset-0 overflow-hidden">
              {content.cover_image ? (
                <img src={content.cover_image} alt="" className="h-full w-full object-cover" />
              ) : (
                <div className="h-full w-full bg-gradient-to-r from-purple-900 via-blue-800 to-purple-900" />
              )}
              <div className="absolute inset-0 bg-gradient-to-t from-[#0a0f1a]/75 via-transparent to-transparent dark:from-[#0a0f1a]/90" />
            </div>
          </div>

          <div className="relative z-20 border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-[#0a0f1a]">
            <div className="container mx-auto px-4">
              <div className="flex flex-col gap-4 pb-5 pt-0">
                <Link
                  href={backUrl}
                  className="mt-4 inline-flex w-fit cursor-pointer items-center text-sm text-purple-600 hover:underline dark:text-purple-300"
                >
                  <ArrowLeft className="mr-2 h-4 w-4" />
                  Back{content.parent_name ? ` to ${content.parent_name}` : ""}
                </Link>

                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                  <div className="flex min-w-0 items-start gap-3 sm:gap-4">
                    <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-purple-600 to-blue-600 text-white shadow-lg ring-2 ring-purple-500/20 sm:h-16 sm:w-16">
                      <TypeIcon className="h-7 w-7" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <div className="mb-2 flex flex-wrap items-center gap-2">
                        <Badge className="gap-1 border-0 bg-gradient-to-r from-purple-600 to-blue-600 capitalize text-white hover:from-purple-600 hover:to-blue-600">
                          <TypeIcon className="h-3 w-3" />
                          {content.type}
                        </Badge>
                        {content.category && (
                          <Badge className="border border-purple-500/30 bg-purple-500/10 text-purple-700 dark:text-purple-200">
                            {content.category}
                          </Badge>
                        )}
                        {content.is_pinned && (
                          <Badge className="gap-1 border-0 bg-white text-purple-700 shadow-sm dark:bg-[#111827] dark:text-purple-200">
                            <Pin className="h-3 w-3" /> Pinned
                          </Badge>
                        )}
                        {content.is_locked && (
                          <Badge variant="outline" className="gap-1">
                            <Lock className="h-3 w-3" /> Locked
                          </Badge>
                        )}
                        {content.archived_at && <Badge variant="secondary">Archived</Badge>}
                        {content.visibility_status !== "visible" && (
                          <Badge variant="destructive">{content.visibility_status}</Badge>
                        )}
                      </div>
                      <h1 className="text-2xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-3xl">
                        {content.title}
                      </h1>
                      <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-gray-600 dark:text-gray-400">
                        <span className="inline-flex items-center gap-2">
                          <span className="flex h-7 w-7 items-center justify-center rounded-full bg-gradient-to-br from-purple-600/15 to-blue-600/15 text-[10px] font-semibold text-purple-700 dark:text-purple-200">
                            {initials(content.author?.name || "?") || "?"}
                          </span>
                          <span className="font-medium text-gray-900 dark:text-white">
                            {content.author?.name ?? "Member"}
                          </span>
                        </span>
                        {content.published_at && <span>{formatWhen(content.published_at)}</span>}
                        {content.parent_name && <span>in {content.parent_name}</span>}
                        <span>
                          {content.replies_count} {content.replies_count === 1 ? "reply" : "replies"}
                        </span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div className="container mx-auto px-4 py-6 pb-16">
          <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
            <div className="space-y-5 lg:col-span-8">
              <Card className="overflow-hidden border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#111827]">
                <CardContent className="space-y-5 p-5 sm:p-6">
                  {editing ? (
                    <form onSubmit={submitEdit} className="space-y-3">
                      <div>
                        <Label>Title</Label>
                        <Input
                          className={`mt-1.5 ${fieldClass}`}
                          value={editForm.data.title}
                          onChange={(e) => editForm.setData("title", e.target.value)}
                          required
                        />
                      </div>
                      <div>
                        <Label>Message</Label>
                        <Textarea
                          className={`mt-1.5 ${fieldClass}`}
                          rows={6}
                          value={editForm.data.body}
                          onChange={(e) => editForm.setData("body", e.target.value)}
                          required
                        />
                      </div>
                      <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                          <Label>Category</Label>
                          <Input
                            className={`mt-1.5 ${fieldClass}`}
                            value={editForm.data.category}
                            onChange={(e) => editForm.setData("category", e.target.value)}
                          />
                        </div>
                        <div>
                          <Label>Expires at</Label>
                          <Input
                            type="datetime-local"
                            className={`mt-1.5 ${fieldClass}`}
                            value={editForm.data.expires_at}
                            onChange={(e) => editForm.setData("expires_at", e.target.value)}
                          />
                        </div>
                      </div>
                      <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                          <Label>New cover image</Label>
                          <Input
                            type="file"
                            accept="image/*"
                            className={`mt-1.5 cursor-pointer ${fieldClass}`}
                            onChange={(e) => editForm.setData("cover_image", e.target.files?.[0] ?? null)}
                          />
                        </div>
                        <div>
                          <Label>New attachment</Label>
                          <Input
                            type="file"
                            className={`mt-1.5 cursor-pointer ${fieldClass}`}
                            onChange={(e) => editForm.setData("attachment", e.target.files?.[0] ?? null)}
                          />
                        </div>
                      </div>
                      <div className="flex flex-wrap gap-4 text-sm">
                        <label className="inline-flex cursor-pointer items-center gap-2">
                          <input
                            type="checkbox"
                            className="cursor-pointer accent-purple-600"
                            checked={editForm.data.is_pinned}
                            onChange={(e) => editForm.setData("is_pinned", e.target.checked)}
                          />
                          Pin to top
                        </label>
                        <label className="inline-flex cursor-pointer items-center gap-2">
                          <input
                            type="checkbox"
                            className="cursor-pointer accent-purple-600"
                            checked={editForm.data.comments_enabled}
                            onChange={(e) => editForm.setData("comments_enabled", e.target.checked)}
                          />
                          Comments enabled
                        </label>
                        <label className="inline-flex cursor-pointer items-center gap-2">
                          <input
                            type="checkbox"
                            className="cursor-pointer accent-purple-600"
                            checked={editForm.data.archive}
                            onChange={(e) => editForm.setData("archive", e.target.checked)}
                          />
                          Archive
                        </label>
                      </div>
                      <div className="flex flex-wrap gap-2">
                        <Button
                          type="submit"
                          disabled={editForm.processing}
                          className="cursor-pointer bg-gradient-to-r from-purple-600 to-blue-600 text-white"
                        >
                          Save changes
                        </Button>
                        <Button
                          type="button"
                          variant="outline"
                          className="cursor-pointer"
                          onClick={() => setEditing(false)}
                        >
                          Cancel
                        </Button>
                      </div>
                    </form>
                  ) : (
                    <>
                      <p className="whitespace-pre-wrap text-base leading-relaxed text-gray-800 dark:text-gray-100">
                        {renderBodyWithMentions(content.body)}
                      </p>
                      {content.attachment_url && (
                        <a
                          href={content.attachment_url}
                          className="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-purple-700 hover:bg-purple-50 dark:border-white/10 dark:bg-white/5 dark:text-purple-300 dark:hover:bg-white/10"
                          target="_blank"
                          rel="noreferrer"
                        >
                          <Paperclip className="h-4 w-4" />
                          {content.attachment_name || "Attachment"}
                        </a>
                      )}
                    </>
                  )}

                  <div className="flex flex-wrap gap-2 border-t border-gray-100 pt-4 dark:border-white/10">
                    <Button
                      size="sm"
                      variant={content.liked_by_me ? "default" : "outline"}
                      className={`cursor-pointer gap-1.5 ${
                        content.liked_by_me
                          ? "bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-700 hover:to-blue-700"
                          : ""
                      }`}
                      onClick={() => router.post(route("community.contents.react", contentKey))}
                    >
                      <Heart className="h-4 w-4" /> {content.likes_count}
                    </Button>
                    <Button
                      size="sm"
                      variant="outline"
                      className="cursor-pointer"
                      onClick={() =>
                        router.post(route("community.contents.follow", contentKey), {
                          unfollow: following && !muted,
                          mute: following && !muted,
                          unmute: muted,
                        })
                      }
                    >
                      {muted ? "Unmute" : following ? "Mute topic" : "Follow topic"}
                    </Button>
                    {canUpdate && !editing && (
                      <Button
                        size="sm"
                        variant="outline"
                        className="cursor-pointer"
                        onClick={() => setEditing(true)}
                      >
                        Edit
                      </Button>
                    )}
                    {versions.length > 0 && (
                      <Button
                        size="sm"
                        variant="outline"
                        className="cursor-pointer gap-1"
                        onClick={() => setShowHistory((v) => !v)}
                      >
                        <History className="h-4 w-4" />
                        History ({versions.length})
                      </Button>
                    )}
                    <ReportDialog
                      reportableType="CommunityContent"
                      reportableId={content.id}
                      reportReasons={reportReasons}
                    />
                    {canModerate && (
                      <>
                        <Button
                          size="sm"
                          variant="ghost"
                          className="cursor-pointer"
                          onClick={() =>
                            router.post(route("community.contents.moderate", contentKey), {
                              action: content.is_locked ? "unlock" : "lock",
                            })
                          }
                        >
                          {content.is_locked ? "Unlock" : "Lock"}
                        </Button>
                        <Button
                          size="sm"
                          variant="ghost"
                          className="cursor-pointer"
                          onClick={() =>
                            router.post(route("community.contents.moderate", contentKey), {
                              action: content.visibility_status === "visible" ? "hide" : "restore",
                            })
                          }
                        >
                          {content.visibility_status === "visible" ? "Hide" : "Restore"}
                        </Button>
                      </>
                    )}
                  </div>

                  {showHistory && (
                    <div className="space-y-3 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                      <p className="text-sm font-medium">Change history</p>
                      {versions.map((v) => (
                        <div key={v.id} className="rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                          <p className="font-medium">{v.title}</p>
                          <p className="mt-1 line-clamp-3 text-muted-foreground">{v.body}</p>
                          <p className="mt-2 text-xs text-muted-foreground">
                            {v.editor?.name ?? "Editor"} · {formatWhen(v.created_at)}
                          </p>
                        </div>
                      ))}
                    </div>
                  )}
                </CardContent>
              </Card>

              <Card className="border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#111827]">
                <CardHeader className="pb-3">
                  <CardTitle className="flex items-center gap-2 text-lg">
                    <MessageSquare className="h-5 w-5 text-purple-600" />
                    Replies
                  </CardTitle>
                  <CardDescription>
                    {topLevel.length === 0
                      ? "No replies yet — start the conversation."
                      : `${content.replies_count} ${content.replies_count === 1 ? "reply" : "replies"}`}
                  </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                  {topLevel.map((reply) => (
                    <div
                      key={reply.id}
                      className="rounded-xl border border-gray-200 bg-gray-50/70 p-4 dark:border-white/10 dark:bg-white/5"
                    >
                      <div className="flex items-start gap-3">
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-purple-600/15 to-blue-600/15 text-xs font-semibold text-purple-700 dark:text-purple-200">
                          {initials(reply.author?.name || "?") || "?"}
                        </div>
                        <div className="min-w-0 flex-1">
                          <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            <p className="text-sm font-semibold text-gray-900 dark:text-white">
                              {reply.author?.name ?? "Member"}
                            </p>
                            <p className="text-xs text-muted-foreground">{formatWhen(reply.created_at)}</p>
                          </div>
                          <p className="mt-1.5 whitespace-pre-wrap text-sm leading-relaxed text-gray-800 dark:text-gray-100">
                            {renderBodyWithMentions(reply.body)}
                          </p>
                          {reply.attachment_url && (
                            <a
                              href={reply.attachment_url}
                              className="mt-2 inline-flex items-center gap-1.5 text-xs text-purple-600 underline dark:text-purple-300"
                              target="_blank"
                              rel="noreferrer"
                            >
                              <Paperclip className="h-3.5 w-3.5" />
                              {reply.attachment_name || "Attachment"}
                            </a>
                          )}
                          <div className="mt-2 flex flex-wrap gap-2">
                            {canReply && (
                              <Button
                                size="sm"
                                variant="ghost"
                                className="cursor-pointer"
                                onClick={() => setReplyTo(reply.id)}
                              >
                                Reply
                              </Button>
                            )}
                            <ReportDialog
                              reportableType="CommunityReply"
                              reportableId={reply.id}
                              reportReasons={reportReasons}
                            />
                            {canModerate && (
                              <Button
                                size="sm"
                                variant="ghost"
                                className="cursor-pointer"
                                onClick={() =>
                                  router.post(route("community.replies.moderate", reply.id), {
                                    action: "hide",
                                  })
                                }
                              >
                                Hide
                              </Button>
                            )}
                          </div>
                          {childrenOf(reply.id).map((child) => (
                            <div
                              key={child.id}
                              className="mt-3 rounded-lg border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-[#0a0f1a]"
                            >
                              <div className="flex items-start gap-2.5">
                                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-purple-600/10 to-blue-600/10 text-[10px] font-semibold text-purple-700 dark:text-purple-200">
                                  {initials(child.author?.name || "?") || "?"}
                                </div>
                                <div className="min-w-0 flex-1">
                                  <div className="flex flex-wrap items-center gap-x-2">
                                    <p className="text-sm font-medium">{child.author?.name ?? "Member"}</p>
                                    <p className="text-xs text-muted-foreground">
                                      {formatWhen(child.created_at)}
                                    </p>
                                  </div>
                                  <p className="mt-1 whitespace-pre-wrap text-sm">
                                    {renderBodyWithMentions(child.body)}
                                  </p>
                                  <div className="mt-1.5">
                                    <ReportDialog
                                      reportableType="CommunityReply"
                                      reportableId={child.id}
                                      reportReasons={reportReasons}
                                    />
                                  </div>
                                </div>
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>
                    </div>
                  ))}

                  {canReply ? (
                    <form
                      onSubmit={submitReply}
                      className="space-y-3 rounded-xl border border-gray-200 bg-gray-50/80 p-4 dark:border-white/10 dark:bg-white/5"
                    >
                      {replyTarget && (
                        <p className="text-xs text-muted-foreground">
                          Replying to <strong>{replyTarget.author?.name ?? "comment"}</strong>{" "}
                          <button
                            type="button"
                            className="cursor-pointer underline"
                            onClick={() => setReplyTo(null)}
                          >
                            cancel
                          </button>
                        </p>
                      )}
                      <Textarea
                        rows={3}
                        className={fieldClass}
                        value={form.data.body}
                        onChange={(e) => form.setData("body", e.target.value)}
                        placeholder="Write a reply… Use @Name to mention someone."
                        required
                      />
                      <div>
                        <Label className="text-xs text-muted-foreground">Attachment (optional)</Label>
                        <Input
                          type="file"
                          className={`mt-1 cursor-pointer ${fieldClass}`}
                          onChange={(e) => form.setData("attachment", e.target.files?.[0] ?? null)}
                        />
                      </div>
                      <Button
                        type="submit"
                        disabled={form.processing}
                        className="cursor-pointer bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-700 hover:to-blue-700"
                      >
                        Post reply
                      </Button>
                    </form>
                  ) : (
                    <p className="rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center text-sm text-muted-foreground dark:border-white/15">
                      {content.is_locked || !content.comments_enabled
                        ? "Replies are closed for this topic."
                        : "Join the group or sign in to reply."}
                    </p>
                  )}
                </CardContent>
              </Card>
            </div>

            <aside className="space-y-4 lg:col-span-4">
              <Card className="border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#111827]">
                <CardHeader className="pb-3">
                  <CardTitle className="text-base">About this post</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3 text-sm">
                  <dl className="space-y-2">
                    <div className="flex justify-between gap-3">
                      <dt className="text-muted-foreground">Type</dt>
                      <dd className="capitalize font-medium">{content.type}</dd>
                    </div>
                    {content.parent_name && (
                      <div className="flex justify-between gap-3">
                        <dt className="text-muted-foreground">Posted in</dt>
                        <dd className="text-right font-medium">{content.parent_name}</dd>
                      </div>
                    )}
                    <div className="flex justify-between gap-3">
                      <dt className="text-muted-foreground">Author</dt>
                      <dd className="font-medium">{content.author?.name ?? "Member"}</dd>
                    </div>
                    {content.published_at && (
                      <div className="flex justify-between gap-3">
                        <dt className="text-muted-foreground">Published</dt>
                        <dd className="text-right font-medium">{formatWhen(content.published_at)}</dd>
                      </div>
                    )}
                    <div className="flex justify-between gap-3">
                      <dt className="text-muted-foreground">Replies</dt>
                      <dd className="font-medium">{content.replies_count}</dd>
                    </div>
                    <div className="flex justify-between gap-3">
                      <dt className="text-muted-foreground">Likes</dt>
                      <dd className="font-medium">{content.likes_count}</dd>
                    </div>
                  </dl>
                  <Button asChild variant="outline" className="mt-2 w-full cursor-pointer">
                    <Link href={backUrl}>Back to feed</Link>
                  </Button>
                </CardContent>
              </Card>
            </aside>
          </div>
        </div>
      </div>
    </FrontendLayout>
  )
}
