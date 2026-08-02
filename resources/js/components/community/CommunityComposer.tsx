"use client"

import { FormEvent, useState } from "react"
import { useForm } from "@inertiajs/react"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Textarea } from "@/components/frontend/ui/textarea"
import { Megaphone, MessageSquare } from "lucide-react"

export type CommunityComposerProps = {
  parentType: string
  parentId: number
  type: "announcement" | "discussion"
  canCreate: boolean
  categories?: string[]
}

const fieldClass =
  "border-gray-300 bg-white text-gray-900 placeholder:text-gray-500 focus-visible:border-purple-500 focus-visible:ring-purple-500/30 dark:border-white/15 dark:bg-[#0a0f1a] dark:text-white dark:placeholder:text-gray-500"

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
        setOpen(false)
      },
    })
  }

  return (
    <Card className="border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-[#111827]">
      <CardHeader className="pb-3">
        <CardTitle className="flex items-center gap-2 text-lg">
          {isAnnouncement ? (
            <Megaphone className="h-5 w-5 text-purple-600" />
          ) : (
            <MessageSquare className="h-5 w-5 text-purple-600" />
          )}
          {isAnnouncement ? "Official announcement" : "Start a discussion"}
        </CardTitle>
        <CardDescription>
          {isAnnouncement
            ? "Share an official update. You can schedule, pin, attach media, and control comments."
            : "Start a community conversation. Add a category, media, or pin if needed."}
        </CardDescription>
      </CardHeader>
      <CardContent>
        {!open ? (
          <Button
            className="cursor-pointer bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-700 hover:to-blue-700"
            onClick={() => setOpen(true)}
          >
            {isAnnouncement ? "New announcement" : "New discussion"}
          </Button>
        ) : (
          <form onSubmit={submit} className="space-y-4">
            <div>
              <Label className="text-gray-800 dark:text-gray-200">Title</Label>
              <Input
                className={`mt-1.5 ${fieldClass}`}
                value={form.data.title}
                onChange={(e) => form.setData("title", e.target.value)}
                placeholder="What’s this about?"
                required
              />
            </div>
            <div>
              <Label className="text-gray-800 dark:text-gray-200">Message</Label>
              <Textarea
                className={`mt-1.5 ${fieldClass}`}
                rows={4}
                value={form.data.body}
                onChange={(e) => form.setData("body", e.target.value)}
                placeholder="Write your message… Use @Name to mention someone."
                required
              />
              <p className="mt-1 text-xs text-muted-foreground">
                Tip: type @ followed by a name to mention someone (e.g. @Jane Doe).
              </p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <Label className="text-gray-800 dark:text-gray-200">Category</Label>
                <select
                  className={`mt-1.5 w-full rounded-md px-3 py-2 text-sm ${fieldClass}`}
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
                  <Label className="text-gray-800 dark:text-gray-200">Publish</Label>
                  <select
                    className={`mt-1.5 w-full rounded-md px-3 py-2 text-sm ${fieldClass}`}
                    value={publishMode}
                    onChange={(e) => setPublishMode(e.target.value as "now" | "schedule")}
                  >
                    <option value="now">Publish immediately</option>
                    <option value="schedule">Schedule for later</option>
                  </select>
                </div>
              )}
            </div>

            {isAnnouncement && publishMode === "schedule" && (
              <div>
                <Label className="text-gray-800 dark:text-gray-200">Scheduled at</Label>
                <Input
                  type="datetime-local"
                  className={`mt-1.5 ${fieldClass}`}
                  value={form.data.scheduled_at}
                  onChange={(e) => form.setData("scheduled_at", e.target.value)}
                  required={publishMode === "schedule"}
                />
              </div>
            )}

            {isAnnouncement && (
              <div>
                <Label className="text-gray-800 dark:text-gray-200">Expiration (optional)</Label>
                <Input
                  type="datetime-local"
                  className={`mt-1.5 ${fieldClass}`}
                  value={form.data.expires_at}
                  onChange={(e) => form.setData("expires_at", e.target.value)}
                />
              </div>
            )}

            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <Label className="text-gray-800 dark:text-gray-200">Cover image (optional)</Label>
                <Input
                  type="file"
                  accept="image/*"
                  className={`mt-1.5 cursor-pointer ${fieldClass}`}
                  onChange={(e) => form.setData("cover_image", e.target.files?.[0] ?? null)}
                />
              </div>
              <div>
                <Label className="text-gray-800 dark:text-gray-200">Attachment (optional)</Label>
                <Input
                  type="file"
                  className={`mt-1.5 cursor-pointer ${fieldClass}`}
                  onChange={(e) => form.setData("attachment", e.target.files?.[0] ?? null)}
                />
              </div>
            </div>

            <div className="flex flex-wrap gap-4 text-sm">
              <label className="inline-flex cursor-pointer items-center gap-2 text-gray-700 dark:text-gray-200">
                <input
                  type="checkbox"
                  className="cursor-pointer accent-purple-600"
                  checked={form.data.is_pinned}
                  onChange={(e) => form.setData("is_pinned", e.target.checked)}
                />
                Pin to top
              </label>
              <label className="inline-flex cursor-pointer items-center gap-2 text-gray-700 dark:text-gray-200">
                <input
                  type="checkbox"
                  className="cursor-pointer accent-purple-600"
                  checked={form.data.comments_enabled}
                  onChange={(e) => form.setData("comments_enabled", e.target.checked)}
                />
                Comments enabled
              </label>
            </div>

            <div className="flex flex-wrap gap-2">
              <Button
                type="submit"
                disabled={form.processing}
                className="cursor-pointer bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-700 hover:to-blue-700"
              >
                {publishMode === "schedule" && isAnnouncement ? "Schedule" : "Publish"}
              </Button>
              <Button
                type="button"
                variant="outline"
                className="cursor-pointer border-gray-300 dark:border-white/20 dark:bg-white/5"
                onClick={() => setOpen(false)}
              >
                Cancel
              </Button>
            </div>
          </form>
        )}
      </CardContent>
    </Card>
  )
}
