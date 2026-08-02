"use client"

import { FormEvent, useMemo, useState } from "react"
import { Head, Link, router, useForm } from "@inertiajs/react"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import ReportDialog from "@/components/community/ReportDialog"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Textarea } from "@/components/frontend/ui/textarea"
import { Badge } from "@/components/ui/badge"
import { ArrowLeft, Heart, History, Lock, Pin } from "lucide-react"

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
    type: string
    title: string
    body: string
    category: string | null
    is_pinned: boolean
    is_locked: boolean
    comments_enabled: boolean
    visibility_status: string
    expires_at: string | null
    archived_at: string | null
    author: { id: number; name: string } | null
    likes_count: number
    liked_by_me: boolean
    cover_image: string | null
    attachment_url: string | null
    attachment_name: string | null
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
  "border-gray-300 bg-white text-gray-900 dark:border-white/15 dark:bg-[#0a0f1a] dark:text-white"

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
    form.post(route("community.contents.reply", content.id), {
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
    editForm.post(route("community.contents.update", content.id), {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => setEditing(false),
    })
  }

  const topLevel = useMemo(() => replies.filter((r) => !r.parent_reply_id), [replies])
  const childrenOf = (id: number) => replies.filter((r) => r.parent_reply_id === id)

  return (
    <FrontendLayout>
      <Head title={content.title} />
      <div className="min-h-screen bg-gray-50 dark:bg-[#0a0f1a]">
        <div className="container mx-auto space-y-6 px-4 py-10">
          <Link
            href={backUrl}
            className="inline-flex cursor-pointer items-center text-sm text-purple-600 hover:underline dark:text-purple-300"
          >
            <ArrowLeft className="mr-2 h-4 w-4" />
            Back
          </Link>

          <Card className="border-gray-200 bg-white dark:border-white/10 dark:bg-[#111827]">
            <CardHeader>
              <div className="mb-2 flex flex-wrap gap-2">
                <Badge variant="outline" className="capitalize">
                  {content.type}
                </Badge>
                {content.category && <Badge variant="outline">{content.category}</Badge>}
                {content.is_pinned && (
                  <Badge variant="secondary" className="gap-1">
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
              <CardTitle className="text-2xl text-gray-900 dark:text-white">{content.title}</CardTitle>
              <p className="text-sm text-muted-foreground">By {content.author?.name ?? "Member"}</p>
            </CardHeader>
            <CardContent className="space-y-4">
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
                      rows={5}
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
                    <Button type="button" variant="outline" className="cursor-pointer" onClick={() => setEditing(false)}>
                      Cancel
                    </Button>
                  </div>
                </form>
              ) : (
                <>
                  {content.cover_image && (
                    <img src={content.cover_image} alt="" className="max-h-80 w-full rounded-lg object-cover" />
                  )}
                  <p className="whitespace-pre-wrap text-gray-800 dark:text-gray-100">
                    {renderBodyWithMentions(content.body)}
                  </p>
                  {content.attachment_url && (
                    <a
                      href={content.attachment_url}
                      className="text-sm text-purple-600 underline"
                      target="_blank"
                      rel="noreferrer"
                    >
                      {content.attachment_name || "Attachment"}
                    </a>
                  )}
                </>
              )}

              <div className="flex flex-wrap gap-2">
                <Button
                  size="sm"
                  variant={content.liked_by_me ? "default" : "outline"}
                  className="cursor-pointer gap-1"
                  onClick={() => router.post(route("community.contents.react", content.id))}
                >
                  <Heart className="h-4 w-4" /> {content.likes_count}
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  className="cursor-pointer"
                  onClick={() =>
                    router.post(route("community.contents.follow", content.id), {
                      unfollow: following && !muted,
                      mute: following && !muted,
                      unmute: muted,
                    })
                  }
                >
                  {muted ? "Unmute" : following ? "Mute topic" : "Follow topic"}
                </Button>
                {canUpdate && !editing && (
                  <Button size="sm" variant="outline" className="cursor-pointer" onClick={() => setEditing(true)}>
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
                        router.post(route("community.contents.moderate", content.id), {
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
                        router.post(route("community.contents.moderate", content.id), {
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
                        {v.editor?.name ?? "Editor"} · {v.created_at}
                      </p>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>

          <Card className="border-gray-200 bg-white dark:border-white/10 dark:bg-[#111827]">
            <CardHeader>
              <CardTitle className="text-lg">Replies</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              {topLevel.map((reply) => (
                <div key={reply.id} className="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                  <p className="text-sm font-medium">{reply.author?.name}</p>
                  <p className="mt-1 whitespace-pre-wrap text-sm">{renderBodyWithMentions(reply.body)}</p>
                  {reply.attachment_url && (
                    <a href={reply.attachment_url} className="mt-2 inline-block text-xs text-purple-600 underline" target="_blank" rel="noreferrer">
                      {reply.attachment_name || "Attachment"}
                    </a>
                  )}
                  <div className="mt-2 flex flex-wrap gap-2">
                    <Button size="sm" variant="ghost" className="cursor-pointer" onClick={() => setReplyTo(reply.id)}>
                      Reply
                    </Button>
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
                          router.post(route("community.replies.moderate", reply.id), { action: "hide" })
                        }
                      >
                        Hide
                      </Button>
                    )}
                  </div>
                  {childrenOf(reply.id).map((child) => (
                    <div key={child.id} className="mt-3 ml-4 rounded-lg border bg-muted/40 p-3 dark:border-white/10">
                      <p className="text-sm font-medium">{child.author?.name}</p>
                      <p className="mt-1 whitespace-pre-wrap text-sm">{renderBodyWithMentions(child.body)}</p>
                      <ReportDialog
                        reportableType="CommunityReply"
                        reportableId={child.id}
                        reportReasons={reportReasons}
                      />
                    </div>
                  ))}
                </div>
              ))}

              {canReply ? (
                <form onSubmit={submitReply} className="space-y-3 border-t border-gray-200 pt-4 dark:border-white/10">
                  {replyTo && (
                    <p className="text-xs text-muted-foreground">
                      Replying to comment #{replyTo}{" "}
                      <button type="button" className="cursor-pointer underline" onClick={() => setReplyTo(null)}>
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
                    className="cursor-pointer bg-gradient-to-r from-purple-600 to-blue-600 text-white"
                  >
                    Post reply
                  </Button>
                </form>
              ) : (
                <p className="text-sm text-muted-foreground">
                  {content.is_locked || !content.comments_enabled
                    ? "Replies are closed for this topic."
                    : "Join the group or sign in to reply."}
                </p>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </FrontendLayout>
  )
}
