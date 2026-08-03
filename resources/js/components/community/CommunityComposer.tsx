"use client"

import { FormEvent, useState } from "react"
import { useForm } from "@inertiajs/react"
import MentionTextarea from "@/components/community/MentionTextarea"
import { Button } from "@/components/frontend/ui/button"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Image as ImageIcon, Megaphone, MessageSquare, Paperclip, X } from "lucide-react"

export type CommunityComposerProps = {
  parentType: string
  parentId: number
  type: "announcement" | "discussion"
  canCreate: boolean
  categories?: string[]
}

const defaultCategories = [
  "General",
  "Prayer",
  "Bible Study",
  "Events",
  "Volunteering",
  "Support",
  "Other",
]

export default function CommunityComposer({
  parentType,
  parentId,
  type,
  canCreate,
  categories = defaultCategories,
}: CommunityComposerProps) {
  const [open, setOpen] = useState(false)
  const [showMore, setShowMore] = useState(false)
  const [publishMode, setPublishMode] = useState<"now" | "schedule">("now")
  const form = useForm({
    parent_type: parentType,
    parent_id: parentId,
    type,
    title: "",
    body: "",
    category: "",
    publish_now: true as boolean,
    scheduled_at: "",
    expires_at: "",
    comments_enabled: true as boolean,
    is_pinned: false as boolean,
    cover_image: null as File | null,
    attachment: null as File | null,
    mentioned_user_ids: [] as number[],
  })

  if (!canCreate) {
    return null
  }

  const isAnnouncement = type === "announcement"
  const prompt = isAnnouncement ? "Write an announcement…" : "Write something…"

  const submit = (e: FormEvent) => {
    e.preventDefault()
    form.transform((data) => ({
      ...data,
      type,
      parent_type: parentType,
      parent_id: parentId,
      publish_now: publishMode === "now",
      scheduled_at: publishMode === "schedule" ? data.scheduled_at || null : null,
      expires_at: data.expires_at || null,
      category: data.category || null,
      // Facebook posts often have body as main content; keep title optional fallback
      title: data.title.trim() || data.body.trim().slice(0, 80) || (isAnnouncement ? "Announcement" : "Discussion"),
    }))
    form.post(route("community.contents.store"), {
      forceFormData: true,
      onSuccess: () => {
        form.reset(
          "title",
          "body",
          "category",
          "scheduled_at",
          "expires_at",
          "cover_image",
          "attachment",
          "mentioned_user_ids",
        )
        form.setData("is_pinned", false)
        form.setData("comments_enabled", true)
        form.setData("publish_now", true)
        setPublishMode("now")
        setShowMore(false)
        setOpen(false)
      },
    })
  }

  return (
    <div className="overflow-hidden rounded-xl border border-purple-100 bg-white shadow-sm shadow-purple-500/5 dark:border-purple-500/20 dark:bg-[#111827]">
      {!open ? (
        <button
          type="button"
          onClick={() => setOpen(true)}
          className="flex w-full cursor-pointer items-center gap-3 px-4 py-3 text-left transition hover:bg-gradient-to-r hover:from-purple-50/80 hover:to-blue-50/80 dark:hover:from-purple-500/10 dark:hover:to-blue-500/10"
        >
          <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-blue-600 text-white">
            {isAnnouncement ? <Megaphone className="h-5 w-5" /> : <MessageSquare className="h-5 w-5" />}
          </div>
          <div className="flex-1 rounded-full bg-gradient-to-r from-purple-50 to-blue-50 px-4 py-2.5 text-[15px] text-slate-500 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-slate-400">
            {prompt}
          </div>
        </button>
      ) : (
        <form onSubmit={submit}>
          <div className="flex items-center justify-between border-b border-purple-50 px-4 py-3 dark:border-purple-500/15">
            <p className="text-[15px] font-bold text-slate-900 dark:text-white">
              {isAnnouncement ? "Create announcement" : "Create post"}
            </p>
            <button
              type="button"
              className="flex h-8 w-8 cursor-pointer items-center justify-center rounded-full bg-purple-50 text-purple-700 hover:bg-purple-100 dark:bg-purple-500/15 dark:text-purple-200"
              onClick={() => setOpen(false)}
              aria-label="Close"
            >
              <X className="h-4 w-4" />
            </button>
          </div>

          <div className="space-y-3 p-4">
            <div className="flex items-start gap-3">
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-blue-600 text-white">
                {isAnnouncement ? <Megaphone className="h-5 w-5" /> : <MessageSquare className="h-5 w-5" />}
              </div>
              <div className="min-w-0 flex-1 space-y-2">
                <Input
                  className="h-11 rounded-xl border-0 border-transparent bg-gradient-to-r from-purple-50 to-blue-50 px-3.5 text-[17px] font-semibold text-slate-900 shadow-none placeholder:font-normal placeholder:text-slate-400 focus-visible:ring-2 focus-visible:ring-purple-200 focus-visible:ring-offset-0 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white dark:placeholder:text-slate-500 dark:focus-visible:ring-purple-500/30"
                  value={form.data.title}
                  onChange={(e) => form.setData("title", e.target.value)}
                  placeholder="Add a headline (optional)"
                />
                <MentionTextarea
                  className="min-h-[120px] w-full resize-none rounded-xl border-0 border-transparent bg-gradient-to-r from-purple-50 to-blue-50 px-3.5 py-3 text-[17px] leading-relaxed text-slate-900 shadow-none placeholder:text-slate-400 focus-visible:ring-2 focus-visible:ring-purple-200 focus-visible:ring-offset-0 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white dark:placeholder:text-slate-500 dark:focus-visible:ring-purple-500/30"
                  rows={4}
                  value={form.data.body}
                  mentionedUserIds={form.data.mentioned_user_ids}
                  parentType={parentType}
                  parentId={parentId}
                  onChange={(body, mentionedUserIds) => {
                    form.setData("body", body)
                    form.setData("mentioned_user_ids", mentionedUserIds)
                  }}
                  placeholder={`${prompt} Type @ to mention someone.`}
                  required
                />
              </div>
            </div>

            {/* Facebook-style “Add to your post” strip */}
            <div className="rounded-xl border border-purple-100 px-3 py-2 dark:border-purple-500/20">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm font-semibold text-slate-700 dark:text-slate-200">Add to your post</p>
                <div className="flex items-center gap-1">
                  <label className="flex h-9 w-9 cursor-pointer items-center justify-center rounded-full text-purple-600 hover:bg-purple-50 dark:text-purple-300 dark:hover:bg-purple-500/15">
                    <ImageIcon className="h-5 w-5" />
                    <input
                      type="file"
                      accept="image/*"
                      className="hidden"
                      onChange={(e) => form.setData("cover_image", e.target.files?.[0] ?? null)}
                    />
                  </label>
                  <label className="flex h-9 w-9 cursor-pointer items-center justify-center rounded-full text-blue-600 hover:bg-blue-50 dark:text-blue-300 dark:hover:bg-blue-500/15">
                    <Paperclip className="h-5 w-5" />
                    <input
                      type="file"
                      className="hidden"
                      onChange={(e) => form.setData("attachment", e.target.files?.[0] ?? null)}
                    />
                  </label>
                  <button
                    type="button"
                    className="cursor-pointer rounded-full px-2.5 py-1.5 text-xs font-semibold text-purple-700 hover:bg-purple-50 dark:text-purple-300 dark:hover:bg-purple-500/15"
                    onClick={() => setShowMore((v) => !v)}
                  >
                    {showMore ? "Less" : "More"}
                  </button>
                </div>
              </div>
              {(form.data.cover_image || form.data.attachment) && (
                <p className="mt-1 truncate text-xs text-slate-500">
                  {form.data.cover_image ? `Photo: ${form.data.cover_image.name}` : ""}
                  {form.data.cover_image && form.data.attachment ? " · " : ""}
                  {form.data.attachment ? `File: ${form.data.attachment.name}` : ""}
                </p>
              )}
            </div>

            {showMore && (
              <div className="space-y-3 rounded-xl bg-gradient-to-r from-purple-50/70 to-blue-50/70 p-3 dark:from-purple-500/10 dark:to-blue-500/10">
                <div className="grid gap-3 sm:grid-cols-2">
                  <div>
                    <Label className="text-xs text-slate-600 dark:text-slate-300">Category</Label>
                    <select
                      className="mt-1 w-full rounded-lg border border-purple-100 bg-white px-3 py-2 text-sm dark:border-purple-500/20 dark:bg-[#0a0f1a]"
                      value={form.data.category}
                      onChange={(e) => form.setData("category", e.target.value)}
                    >
                      <option value="">None</option>
                      {categories.map((c) => (
                        <option key={c} value={c}>
                          {c}
                        </option>
                      ))}
                    </select>
                  </div>
                  {isAnnouncement && (
                    <div>
                      <Label className="text-xs text-slate-600 dark:text-slate-300">Publish</Label>
                      <select
                        className="mt-1 w-full rounded-lg border border-purple-100 bg-white px-3 py-2 text-sm dark:border-purple-500/20 dark:bg-[#0a0f1a]"
                        value={publishMode}
                        onChange={(e) => setPublishMode(e.target.value as "now" | "schedule")}
                      >
                        <option value="now">Publish now</option>
                        <option value="schedule">Schedule</option>
                      </select>
                    </div>
                  )}
                </div>
                {isAnnouncement && publishMode === "schedule" && (
                  <Input
                    type="datetime-local"
                    value={form.data.scheduled_at}
                    onChange={(e) => form.setData("scheduled_at", e.target.value)}
                    required
                  />
                )}
                {isAnnouncement && (
                  <div>
                    <Label className="text-xs text-slate-600 dark:text-slate-300">Expires (optional)</Label>
                    <Input
                      type="datetime-local"
                      className="mt-1"
                      value={form.data.expires_at}
                      onChange={(e) => form.setData("expires_at", e.target.value)}
                    />
                  </div>
                )}
                <div className="flex flex-wrap gap-4 text-sm">
                  <label className="inline-flex cursor-pointer items-center gap-2">
                    <input
                      type="checkbox"
                      className="accent-purple-600"
                      checked={form.data.is_pinned}
                      onChange={(e) => form.setData("is_pinned", e.target.checked)}
                    />
                    Pin to top
                  </label>
                  <label className="inline-flex cursor-pointer items-center gap-2">
                    <input
                      type="checkbox"
                      className="accent-purple-600"
                      checked={form.data.comments_enabled}
                      onChange={(e) => form.setData("comments_enabled", e.target.checked)}
                    />
                    Allow comments
                  </label>
                </div>
              </div>
            )}

            <Button
              type="submit"
              disabled={form.processing || !form.data.body.trim()}
              className="h-10 w-full cursor-pointer rounded-lg bg-gradient-to-r from-purple-600 to-blue-600 text-[15px] font-bold text-white hover:from-purple-700 hover:to-blue-700 disabled:opacity-50"
            >
              {form.processing
                ? "Posting…"
                : publishMode === "schedule" && isAnnouncement
                  ? "Schedule"
                  : "Post"}
            </Button>
          </div>
        </form>
      )}
    </div>
  )
}
