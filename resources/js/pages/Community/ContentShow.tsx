"use client"

import { FormEvent, useMemo, useRef, useState, type DragEvent, type ReactNode } from "react"
import { Head, Link, router, useForm } from "@inertiajs/react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import ReportDialog from "@/components/community/ReportDialog"
import MentionTextarea from "@/components/community/MentionTextarea"
import { Button } from "@/components/frontend/ui/button"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Textarea } from "@/components/frontend/ui/textarea"
import {
  ArrowLeft,
  Bell,
  BellOff,
  Heart,
  History,
  Lock,
  Megaphone,
  MessageSquare,
  Paperclip,
  Pin,
  Pencil,
  EyeOff,
  Unlock,
  Upload,
  X,
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
    parent_type: string
    parent_id: number
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
  "border-purple-100 bg-white text-slate-900 placeholder:text-slate-400 focus-visible:border-purple-500 focus-visible:ring-purple-500/20 dark:border-purple-500/20 dark:bg-[#0b1220] dark:text-white dark:placeholder:text-slate-500"

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
      <span key={i} className="font-semibold text-purple-600 dark:text-purple-300">
        {part}
      </span>
    ) : (
      <span key={i}>{part}</span>
    ),
  )
}

function Avatar({ name, size = "md" }: { name: string; size?: "sm" | "md" | "lg" }) {
  const dims = size === "lg" ? "h-12 w-12 text-sm" : size === "sm" ? "h-8 w-8 text-[10px]" : "h-10 w-10 text-xs"
  return (
    <div
      className={`flex shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-blue-600 font-bold text-white shadow-sm ring-2 ring-white dark:ring-[#111827] ${dims}`}
    >
      {initials(name) || "?"}
    </div>
  )
}

function SoftBadge({
  children,
  tone = "brand",
}: {
  children: ReactNode
  tone?: "brand" | "muted" | "warn" | "danger"
}) {
  const tones = {
    brand:
      "bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm shadow-purple-500/20",
    muted:
      "border border-purple-100 bg-purple-50 text-purple-800 dark:border-purple-500/25 dark:bg-purple-500/15 dark:text-purple-100",
    warn: "border border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200",
    danger: "border border-red-200 bg-red-50 text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300",
  }
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-bold ${tones[tone]}`}>
      {children}
    </span>
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
  const [replyDropActive, setReplyDropActive] = useState(false)
  const replyFileRef = useRef<HTMLInputElement>(null)
  const contentKey = content.slug
  const form = useForm({
    body: "",
    parent_reply_id: null as number | null,
    attachment: null as File | null,
    mentioned_user_ids: [] as number[],
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

  const setReplyAttachment = (file: File | null) => {
    form.setData("attachment", file)
    if (!file && replyFileRef.current) {
      replyFileRef.current.value = ""
    }
  }

  const onReplyDrop = (e: DragEvent<HTMLDivElement>) => {
    e.preventDefault()
    e.stopPropagation()
    setReplyDropActive(false)
    const file = e.dataTransfer.files?.[0] ?? null
    if (file) setReplyAttachment(file)
  }

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
        form.reset("body", "attachment", "mentioned_user_ids")
        setReplyTo(null)
        if (replyFileRef.current) replyFileRef.current.value = ""
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
  const followLabel = muted ? "Unmute" : following ? "Mute topic" : "Follow topic"
  const FollowIcon = muted || following ? BellOff : Bell

  return (
    <FrontendLayout>
      <Head title={content.title} />
      <div className="min-h-screen bg-gradient-to-b from-purple-50/90 via-slate-50 to-blue-50/50 text-slate-900 dark:from-[#0b1220] dark:via-[#0f172a] dark:to-[#0b1220] dark:text-white">
        <div className="mx-auto max-w-[95rem] px-4 pb-16 pt-6 sm:px-6 lg:px-8">
          <Link
            href={backUrl}
            className="mb-4 inline-flex cursor-pointer items-center gap-2 rounded-full border border-purple-100 bg-white px-3 py-1.5 text-sm font-semibold text-purple-700 shadow-sm hover:bg-purple-50 dark:border-purple-500/20 dark:bg-[#111827] dark:text-purple-200 dark:hover:bg-purple-500/10"
          >
            <ArrowLeft className="h-4 w-4" />
            Back{content.parent_name ? ` to ${content.parent_name}` : ""}
          </Link>

          <div className="grid grid-cols-1 gap-6 lg:grid-cols-12 lg:items-start lg:gap-8">
            {/* Main column */}
            <div className="space-y-5 lg:col-span-8">
              {/* Post card */}
              <article className="overflow-hidden rounded-2xl border border-purple-100/80 bg-white shadow-lg shadow-purple-500/5 dark:border-purple-500/20 dark:bg-[#111827]">
                <div className="px-5 pt-5 sm:px-6 sm:pt-6">
                  <div className="flex items-start gap-3 sm:gap-4">
                    <Avatar name={content.author?.name || "Member"} size="lg" />
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2">
                        <SoftBadge>
                          <TypeIcon className="h-3 w-3" />
                          {content.type}
                        </SoftBadge>
                        {content.category && <SoftBadge tone="muted">{content.category}</SoftBadge>}
                        {content.is_pinned && (
                          <SoftBadge tone="muted">
                            <Pin className="h-3 w-3" /> Pinned
                          </SoftBadge>
                        )}
                        {content.is_locked && (
                          <SoftBadge tone="warn">
                            <Lock className="h-3 w-3" /> Locked
                          </SoftBadge>
                        )}
                        {content.archived_at && <SoftBadge tone="muted">Archived</SoftBadge>}
                        {content.visibility_status !== "visible" && (
                          <SoftBadge tone="danger">{content.visibility_status}</SoftBadge>
                        )}
                      </div>
                      <h1 className="mt-2.5 text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl dark:text-white">
                        {content.title}
                      </h1>
                      <p className="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-slate-500 dark:text-slate-400">
                        <span className="font-semibold text-slate-800 dark:text-slate-200">
                          {content.author?.name ?? "Member"}
                        </span>
                        {content.published_at && (
                          <>
                            <span aria-hidden>·</span>
                            <span>{formatWhen(content.published_at)}</span>
                          </>
                        )}
                        {content.parent_name && (
                          <>
                            <span aria-hidden>·</span>
                            <span>in {content.parent_name}</span>
                          </>
                        )}
                      </p>
                    </div>
                  </div>
                </div>

                {/* Post image — feed style, not a page cover */}
                {!editing && content.cover_image && (
                  <div className="mt-4 bg-slate-950/5 dark:bg-black/40">
                    <a href={content.cover_image} target="_blank" rel="noreferrer" className="block">
                      <img
                        src={content.cover_image}
                        alt=""
                        className="mx-auto max-h-[min(70vh,640px)] w-full object-contain"
                      />
                    </a>
                  </div>
                )}

                <div className="space-y-5 px-5 py-5 sm:px-6">
                  {editing ? (
                    <form onSubmit={submitEdit} className="space-y-4">
                      <div>
                        <Label className="text-slate-700 dark:text-slate-200">Title</Label>
                        <Input
                          className={`mt-1.5 ${fieldClass}`}
                          value={editForm.data.title}
                          onChange={(e) => editForm.setData("title", e.target.value)}
                          required
                        />
                      </div>
                      <div>
                        <Label className="text-slate-700 dark:text-slate-200">Message</Label>
                        <Textarea
                          className={`mt-1.5 ${fieldClass}`}
                          rows={7}
                          value={editForm.data.body}
                          onChange={(e) => editForm.setData("body", e.target.value)}
                          required
                        />
                      </div>
                      <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                          <Label className="text-slate-700 dark:text-slate-200">Category</Label>
                          <Input
                            className={`mt-1.5 ${fieldClass}`}
                            value={editForm.data.category}
                            onChange={(e) => editForm.setData("category", e.target.value)}
                          />
                        </div>
                        <div>
                          <Label className="text-slate-700 dark:text-slate-200">Expires at</Label>
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
                          <Label className="text-slate-700 dark:text-slate-200">New cover image</Label>
                          <Input
                            type="file"
                            accept="image/*"
                            className={`mt-1.5 cursor-pointer ${fieldClass}`}
                            onChange={(e) => editForm.setData("cover_image", e.target.files?.[0] ?? null)}
                          />
                        </div>
                        <div>
                          <Label className="text-slate-700 dark:text-slate-200">New attachment</Label>
                          <Input
                            type="file"
                            className={`mt-1.5 cursor-pointer ${fieldClass}`}
                            onChange={(e) => editForm.setData("attachment", e.target.files?.[0] ?? null)}
                          />
                        </div>
                      </div>
                      <div className="flex flex-wrap gap-4 text-sm text-slate-700 dark:text-slate-300">
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
                          className="cursor-pointer rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 font-semibold text-white"
                        >
                          Save changes
                        </Button>
                        <Button
                          type="button"
                          variant="outline"
                          className="cursor-pointer rounded-xl border-purple-200 dark:border-purple-500/30"
                          onClick={() => setEditing(false)}
                        >
                          Cancel
                        </Button>
                      </div>
                    </form>
                  ) : (
                    <>
                      <p className="whitespace-pre-wrap text-[16px] leading-7 text-slate-800 dark:text-slate-100">
                        {renderBodyWithMentions(content.body)}
                      </p>
                      {content.attachment_url && (
                        <a
                          href={content.attachment_url}
                          className="inline-flex items-center gap-2 rounded-xl border border-purple-100 bg-gradient-to-r from-purple-50 to-blue-50 px-3.5 py-2.5 text-sm font-semibold text-purple-700 transition hover:from-purple-100 hover:to-blue-100 dark:border-purple-500/20 dark:from-purple-500/10 dark:to-blue-500/10 dark:text-purple-200"
                          target="_blank"
                          rel="noreferrer"
                        >
                          <Paperclip className="h-4 w-4" />
                          {content.attachment_name || "Attachment"}
                        </a>
                      )}
                    </>
                  )}

                  {/* Action bar */}
                  <div className="flex flex-wrap items-center gap-2 border-t border-purple-50 pt-4 dark:border-purple-500/15">
                    <button
                      type="button"
                      onClick={() => router.post(route("community.contents.react", contentKey))}
                      className={`inline-flex cursor-pointer items-center gap-1.5 rounded-full px-3.5 py-2 text-sm font-semibold transition ${
                        content.liked_by_me
                          ? "bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm shadow-purple-500/25"
                          : "bg-purple-50 text-purple-800 hover:bg-purple-100 dark:bg-purple-500/15 dark:text-purple-100 dark:hover:bg-purple-500/25"
                      }`}
                    >
                      <Heart className={`h-4 w-4 ${content.liked_by_me ? "fill-current" : ""}`} />
                      {content.likes_count}
                    </button>

                    <button
                      type="button"
                      className="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-slate-100 px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-200 dark:bg-white/5 dark:text-slate-200 dark:hover:bg-white/10"
                      onClick={() =>
                        router.post(route("community.contents.follow", contentKey), {
                          unfollow: following && !muted,
                          mute: following && !muted,
                          unmute: muted,
                        })
                      }
                    >
                      <FollowIcon className="h-4 w-4" />
                      {followLabel}
                    </button>

                    {canUpdate && !editing && (
                      <button
                        type="button"
                        className="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-slate-100 px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-200 dark:bg-white/5 dark:text-slate-200 dark:hover:bg-white/10"
                        onClick={() => setEditing(true)}
                      >
                        <Pencil className="h-3.5 w-3.5" />
                        Edit
                      </button>
                    )}

                    {versions.length > 0 && (
                      <button
                        type="button"
                        className="inline-flex cursor-pointer items-center gap-1.5 rounded-full bg-slate-100 px-3.5 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-200 dark:bg-white/5 dark:text-slate-200 dark:hover:bg-white/10"
                        onClick={() => setShowHistory((v) => !v)}
                      >
                        <History className="h-4 w-4" />
                        History ({versions.length})
                      </button>
                    )}

                    <div className="ml-auto flex flex-wrap items-center gap-1.5">
                      <ReportDialog
                        reportableType="CommunityContent"
                        reportableId={content.id}
                        reportReasons={reportReasons}
                      />
                      {canModerate && (
                        <>
                          <button
                            type="button"
                            className="inline-flex cursor-pointer items-center gap-1 rounded-full px-3 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-white/5 dark:hover:text-slate-200"
                            onClick={() =>
                              router.post(route("community.contents.moderate", contentKey), {
                                action: content.is_locked ? "unlock" : "lock",
                              })
                            }
                          >
                            {content.is_locked ? <Unlock className="h-3.5 w-3.5" /> : <Lock className="h-3.5 w-3.5" />}
                            {content.is_locked ? "Unlock" : "Lock"}
                          </button>
                          <button
                            type="button"
                            className="inline-flex cursor-pointer items-center gap-1 rounded-full px-3 py-2 text-sm font-semibold text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-white/5 dark:hover:text-slate-200"
                            onClick={() =>
                              router.post(route("community.contents.moderate", contentKey), {
                                action: content.visibility_status === "visible" ? "hide" : "restore",
                              })
                            }
                          >
                            <EyeOff className="h-3.5 w-3.5" />
                            {content.visibility_status === "visible" ? "Hide" : "Restore"}
                          </button>
                        </>
                      )}
                    </div>
                  </div>

                  {showHistory && (
                    <div className="space-y-3 rounded-xl border border-purple-100 bg-gradient-to-b from-purple-50/50 to-white p-4 dark:border-purple-500/20 dark:from-purple-500/10 dark:to-transparent">
                      <p className="text-sm font-bold text-slate-900 dark:text-white">Change history</p>
                      {versions.map((v) => (
                        <div
                          key={v.id}
                          className="rounded-xl border border-purple-50 bg-white p-3 text-sm dark:border-purple-500/15 dark:bg-[#0b1220]"
                        >
                          <p className="font-semibold text-slate-900 dark:text-white">{v.title}</p>
                          <p className="mt-1 line-clamp-3 text-slate-500 dark:text-slate-400">{v.body}</p>
                          <p className="mt-2 text-xs text-slate-400">
                            {v.editor?.name ?? "Editor"} · {formatWhen(v.created_at)}
                          </p>
                        </div>
                      ))}
                    </div>
                  )}
                </div>
              </article>

              {/* Replies */}
              <section className="overflow-hidden rounded-2xl border border-purple-100/80 bg-white shadow-lg shadow-purple-500/5 dark:border-purple-500/20 dark:bg-[#111827]">
                <div className="border-b border-purple-50 px-5 py-4 sm:px-6 dark:border-purple-500/15">
                  <div className="flex items-center gap-2">
                    <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-gradient-to-br from-purple-600 to-blue-600 text-white">
                      <MessageSquare className="h-4 w-4" />
                    </div>
                    <div>
                      <h2 className="text-lg font-bold text-slate-900 dark:text-white">Conversation</h2>
                      <p className="text-sm text-slate-500">
                        {topLevel.length === 0
                          ? "No replies yet — start the conversation."
                          : `${content.replies_count} ${content.replies_count === 1 ? "reply" : "replies"}`}
                      </p>
                    </div>
                  </div>
                </div>

                <div className="space-y-0 divide-y divide-purple-50 px-0 dark:divide-purple-500/10">
                  {topLevel.map((reply) => (
                    <div key={reply.id} className="px-5 py-4 sm:px-6">
                      <div className="flex items-start gap-3">
                        <Avatar name={reply.author?.name || "Member"} />
                        <div className="min-w-0 flex-1">
                          <div className="rounded-2xl bg-slate-50 px-3.5 py-2.5 dark:bg-white/5">
                            <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                              <p className="text-sm font-bold text-slate-900 dark:text-white">
                                {reply.author?.name ?? "Member"}
                              </p>
                              <p className="text-xs text-slate-400">{formatWhen(reply.created_at)}</p>
                            </div>
                            <p className="mt-1 whitespace-pre-wrap text-sm leading-relaxed text-slate-800 dark:text-slate-100">
                              {renderBodyWithMentions(reply.body)}
                            </p>
                            {reply.attachment_url && (
                              <a
                                href={reply.attachment_url}
                                className="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold text-purple-600 dark:text-purple-300"
                                target="_blank"
                                rel="noreferrer"
                              >
                                <Paperclip className="h-3.5 w-3.5" />
                                {reply.attachment_name || "Attachment"}
                              </a>
                            )}
                          </div>
                          <div className="mt-1.5 flex flex-wrap items-center gap-1 pl-1">
                            {canReply && (
                              <button
                                type="button"
                                className="cursor-pointer rounded-full px-2.5 py-1 text-xs font-bold text-slate-500 hover:bg-purple-50 hover:text-purple-700 dark:hover:bg-purple-500/10 dark:hover:text-purple-200"
                                onClick={() => setReplyTo(reply.id)}
                              >
                                Reply
                              </button>
                            )}
                            <ReportDialog
                              reportableType="CommunityReply"
                              reportableId={reply.id}
                              reportReasons={reportReasons}
                            />
                            {canModerate && (
                              <button
                                type="button"
                                className="cursor-pointer rounded-full px-2.5 py-1 text-xs font-bold text-slate-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/10"
                                onClick={() =>
                                  router.post(route("community.replies.moderate", reply.id), {
                                    action: "hide",
                                  })
                                }
                              >
                                Hide
                              </button>
                            )}
                          </div>

                          {childrenOf(reply.id).map((child) => (
                            <div key={child.id} className="mt-3 flex items-start gap-2.5 pl-2 sm:pl-4">
                              <Avatar name={child.author?.name || "Member"} size="sm" />
                              <div className="min-w-0 flex-1">
                                <div className="rounded-2xl bg-purple-50/70 px-3 py-2 dark:bg-purple-500/10">
                                  <div className="flex flex-wrap items-baseline gap-x-2">
                                    <p className="text-sm font-bold text-slate-900 dark:text-white">
                                      {child.author?.name ?? "Member"}
                                    </p>
                                    <p className="text-xs text-slate-400">{formatWhen(child.created_at)}</p>
                                  </div>
                                  <p className="mt-1 whitespace-pre-wrap text-sm text-slate-800 dark:text-slate-100">
                                    {renderBodyWithMentions(child.body)}
                                  </p>
                                </div>
                                <div className="mt-1 pl-1">
                                  <ReportDialog
                                    reportableType="CommunityReply"
                                    reportableId={child.id}
                                    reportReasons={reportReasons}
                                  />
                                </div>
                              </div>
                            </div>
                          ))}
                        </div>
                      </div>
                    </div>
                  ))}
                </div>

                <div className="border-t border-purple-50 bg-gradient-to-b from-purple-50/40 to-white p-5 sm:p-6 dark:border-purple-500/15 dark:from-purple-500/5 dark:to-transparent">
                  {canReply ? (
                    <form onSubmit={submitReply} className="space-y-3">
                      {replyTarget && (
                        <div className="flex items-center justify-between rounded-xl border border-purple-100 bg-white px-3 py-2 text-xs dark:border-purple-500/20 dark:bg-[#0b1220]">
                          <p className="text-slate-600 dark:text-slate-300">
                            Replying to{" "}
                            <strong className="text-purple-700 dark:text-purple-200">
                              {replyTarget.author?.name ?? "comment"}
                            </strong>
                          </p>
                          <button
                            type="button"
                            className="cursor-pointer font-semibold text-slate-500 underline"
                            onClick={() => setReplyTo(null)}
                          >
                            Cancel
                          </button>
                        </div>
                      )}
                      <div className="flex items-start gap-3">
                        <Avatar name="You" />
                        <div className="min-w-0 flex-1 space-y-3">
                          <MentionTextarea
                            rows={3}
                            className={fieldClass}
                            value={form.data.body}
                            mentionedUserIds={form.data.mentioned_user_ids}
                            parentType={content.parent_type}
                            parentId={content.parent_id}
                            onChange={(body, mentionedUserIds) => {
                              form.setData("body", body)
                              form.setData("mentioned_user_ids", mentionedUserIds)
                            }}
                            placeholder="Write a reply… Type @ to mention someone."
                            required
                          />
                          <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                            <div className="min-w-0 flex-1">
                              <input
                                ref={replyFileRef}
                                type="file"
                                className="hidden"
                                onChange={(e) => setReplyAttachment(e.target.files?.[0] ?? null)}
                              />
                              <div
                                role="button"
                                tabIndex={0}
                                onClick={() => replyFileRef.current?.click()}
                                onKeyDown={(e) => {
                                  if (e.key === "Enter" || e.key === " ") {
                                    e.preventDefault()
                                    replyFileRef.current?.click()
                                  }
                                }}
                                onDragEnter={(e) => {
                                  e.preventDefault()
                                  setReplyDropActive(true)
                                }}
                                onDragOver={(e) => {
                                  e.preventDefault()
                                  setReplyDropActive(true)
                                }}
                                onDragLeave={(e) => {
                                  e.preventDefault()
                                  setReplyDropActive(false)
                                }}
                                onDrop={onReplyDrop}
                                className={`flex min-h-[44px] cursor-pointer items-center gap-2.5 rounded-xl border border-dashed px-3 py-2 transition ${
                                  replyDropActive
                                    ? "border-purple-500 bg-purple-50 dark:border-purple-400 dark:bg-purple-500/15"
                                    : form.data.attachment
                                      ? "border-purple-300 bg-gradient-to-r from-purple-50 to-blue-50 dark:border-purple-500/40 dark:from-purple-500/10 dark:to-blue-500/10"
                                      : "border-purple-200 bg-white hover:border-purple-400 hover:bg-purple-50/50 dark:border-purple-500/25 dark:bg-[#0b1220] dark:hover:border-purple-400/50 dark:hover:bg-purple-500/10"
                                }`}
                              >
                                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-purple-600 to-blue-600 text-white">
                                  {form.data.attachment ? (
                                    <Paperclip className="h-3.5 w-3.5" />
                                  ) : (
                                    <Upload className="h-3.5 w-3.5" />
                                  )}
                                </div>
                                <div className="min-w-0 flex-1">
                                  {form.data.attachment ? (
                                    <>
                                      <p className="truncate text-xs font-semibold text-slate-800 dark:text-slate-100">
                                        {form.data.attachment.name}
                                      </p>
                                      <p className="text-[11px] text-slate-500">
                                        {(form.data.attachment.size / 1024).toFixed(0)} KB · click to replace
                                      </p>
                                    </>
                                  ) : (
                                    <>
                                      <p className="text-xs font-semibold text-slate-700 dark:text-slate-200">
                                        Drop file or click to attach
                                      </p>
                                      <p className="text-[11px] text-slate-400">Optional · images, docs, etc.</p>
                                    </>
                                  )}
                                </div>
                                {form.data.attachment && (
                                  <button
                                    type="button"
                                    title="Remove"
                                    onClick={(e) => {
                                      e.stopPropagation()
                                      setReplyAttachment(null)
                                    }}
                                    className="inline-flex h-7 w-7 shrink-0 cursor-pointer items-center justify-center rounded-full text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-500/15"
                                  >
                                    <X className="h-3.5 w-3.5" />
                                  </button>
                                )}
                              </div>
                            </div>
                            <Button
                              type="submit"
                              disabled={form.processing}
                              className="h-10 shrink-0 cursor-pointer rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 px-5 font-bold text-white hover:from-purple-700 hover:to-blue-700"
                            >
                              Post reply
                            </Button>
                          </div>
                        </div>
                      </div>
                    </form>
                  ) : (
                    <p className="rounded-xl border border-dashed border-purple-200 px-4 py-8 text-center text-sm text-slate-500 dark:border-purple-500/25 dark:text-slate-400">
                      {content.is_locked || !content.comments_enabled
                        ? "Replies are closed for this topic."
                        : "Join the group or sign in to reply."}
                    </p>
                  )}
                </div>
              </section>
            </div>

            {/* Sidebar */}
            <aside className="lg:col-span-4">
              <div className="sticky top-6 space-y-4">
                <div className="overflow-hidden rounded-2xl border border-purple-100/80 bg-white shadow-lg shadow-purple-500/5 dark:border-purple-500/20 dark:bg-[#111827]">
                  <div className="border-b border-purple-50 bg-gradient-to-r from-purple-600/10 to-blue-600/10 px-5 py-4 dark:border-purple-500/15 dark:from-purple-500/15 dark:to-blue-500/15">
                    <h3 className="font-bold text-slate-900 dark:text-white">About this post</h3>
                    <p className="mt-0.5 text-sm text-slate-500 dark:text-slate-400">
                      Details and engagement
                    </p>
                  </div>
                  <dl className="divide-y divide-purple-50 px-5 dark:divide-purple-500/10">
                    {[
                      { label: "Type", value: content.type, capitalize: true },
                      content.parent_name
                        ? { label: "Posted in", value: content.parent_name }
                        : null,
                      { label: "Author", value: content.author?.name ?? "Member" },
                      content.published_at
                        ? { label: "Published", value: formatWhen(content.published_at) }
                        : null,
                      {
                        label: "Replies",
                        value: String(content.replies_count),
                      },
                      {
                        label: "Likes",
                        value: String(content.likes_count),
                      },
                    ]
                      .filter(Boolean)
                      .map((row) => (
                        <div key={row!.label} className="flex items-start justify-between gap-3 py-3 text-sm">
                          <dt className="text-slate-500">{row!.label}</dt>
                          <dd
                            className={`text-right font-semibold text-slate-900 dark:text-white ${
                              row!.capitalize ? "capitalize" : ""
                            }`}
                          >
                            {row!.value}
                          </dd>
                        </div>
                      ))}
                  </dl>
                  <div className="px-5 pb-5">
                    <Link
                      href={backUrl}
                      className="flex h-10 w-full cursor-pointer items-center justify-center rounded-xl border border-purple-200 bg-white text-sm font-bold text-purple-700 transition hover:bg-purple-50 dark:border-purple-500/30 dark:bg-transparent dark:text-purple-200 dark:hover:bg-purple-500/10"
                    >
                      Back to feed
                    </Link>
                  </div>
                </div>

                <div className="rounded-2xl border border-purple-100/80 bg-gradient-to-br from-purple-600 to-blue-600 p-5 text-white shadow-lg shadow-purple-500/20 dark:border-purple-500/20">
                  <div className="flex items-center gap-2">
                    <TypeIcon className="h-5 w-5 opacity-90" />
                    <p className="font-bold">Keep the conversation going</p>
                  </div>
                  <p className="mt-2 text-sm text-white/85">
                    Like, follow, and reply so members stay in the loop on this topic.
                  </p>
                </div>
              </div>
            </aside>
          </div>
        </div>
      </div>
    </FrontendLayout>
  )
}
