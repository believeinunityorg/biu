import { useForm } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import AttachmentUploader from './AttachmentUploader'
import { ch } from '@/pages/Organization/CommunicationHub/theme'
import { cn } from '@/lib/utils'
import { useState } from 'react'

type Props = {
  action: string
  method?: 'post' | 'put'
  categories: string[]
  initial?: {
    title?: string
    message?: string
    category?: string
    is_pinned?: boolean
    allow_comments?: boolean
    publish_now?: boolean
    scheduled_at?: string
    expires_at?: string
  }
  submitLabel?: string
  onCancel?: () => void
}

export default function AnnouncementEditor({
  action,
  method = 'post',
  categories,
  initial = {},
  submitLabel = 'Save Announcement',
  onCancel,
}: Props) {
  const [files, setFiles] = useState<File[]>([])
  const [cover, setCover] = useState<File | null>(null)

  const form = useForm({
    title: initial.title ?? '',
    message: initial.message ?? '',
    category: initial.category ?? '',
    is_pinned: initial.is_pinned ?? false,
    allow_comments: initial.allow_comments ?? true,
    publish_now: initial.publish_now ?? true,
    scheduled_at: initial.scheduled_at ?? '',
    expires_at: initial.expires_at ?? '',
    cover_image: null as File | null,
    attachments: [] as File[],
    _method: method === 'put' ? 'put' : undefined,
  })

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    form.transform((data) => ({
      ...data,
      cover_image: cover,
      attachments: files,
    }))
    form.post(action, { forceFormData: true })
  }

  return (
    <form onSubmit={submit} className={cn(ch.card, 'space-y-5 p-5 sm:p-6')}>
      <div className="space-y-2">
        <Label htmlFor="title">Title</Label>
        <Input
          id="title"
          value={form.data.title}
          onChange={(e) => form.setData('title', e.target.value)}
          className={cn('rounded-lg', ch.focus)}
          required
        />
        {form.errors.title && <p className="text-sm text-destructive">{form.errors.title}</p>}
      </div>

      <div className="space-y-2">
        <Label htmlFor="message">Message</Label>
        <textarea
          id="message"
          value={form.data.message}
          onChange={(e) => form.setData('message', e.target.value)}
          rows={8}
          className={cn('w-full px-3 py-2 text-sm', ch.input, ch.focus)}
          required
        />
        {form.errors.message && <p className="text-sm text-destructive">{form.errors.message}</p>}
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-2">
          <Label htmlFor="category">Category</Label>
          <select
            id="category"
            value={form.data.category}
            onChange={(e) => form.setData('category', e.target.value)}
            className={cn('h-10 w-full px-3 text-sm', ch.input, ch.focus)}
          >
            <option value="">Select category</option>
            {categories.map((c) => (
              <option key={c} value={c}>
                {c}
              </option>
            ))}
          </select>
        </div>
        <div className="space-y-2">
          <Label htmlFor="cover">Cover image</Label>
          <Input
            id="cover"
            type="file"
            accept="image/*"
            onChange={(e) => setCover(e.target.files?.[0] ?? null)}
            className="rounded-lg"
          />
        </div>
      </div>

      <AttachmentUploader files={files} onChange={setFiles} />

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex items-center justify-between rounded-xl border border-border px-3 py-2">
          <Label htmlFor="pin">Pin announcement</Label>
          <Switch
            id="pin"
            checked={form.data.is_pinned}
            onCheckedChange={(v) => form.setData('is_pinned', v)}
          />
        </div>
        <div className="flex items-center justify-between rounded-xl border border-border px-3 py-2">
          <Label htmlFor="comments">Allow comments</Label>
          <Switch
            id="comments"
            checked={form.data.allow_comments}
            onCheckedChange={(v) => form.setData('allow_comments', v)}
          />
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2">
        <div className="flex items-center justify-between rounded-xl border border-border px-3 py-2">
          <Label htmlFor="publish">Publish now</Label>
          <Switch
            id="publish"
            checked={form.data.publish_now}
            onCheckedChange={(v) => form.setData('publish_now', v)}
          />
        </div>
        {!form.data.publish_now && (
          <div className="space-y-2">
            <Label htmlFor="scheduled_at">Schedule for</Label>
            <Input
              id="scheduled_at"
              type="datetime-local"
              value={form.data.scheduled_at}
              onChange={(e) => form.setData('scheduled_at', e.target.value)}
              className="rounded-lg"
            />
          </div>
        )}
        <div className="space-y-2">
          <Label htmlFor="expires_at">Expires at (optional)</Label>
          <Input
            id="expires_at"
            type="datetime-local"
            value={form.data.expires_at}
            onChange={(e) => form.setData('expires_at', e.target.value)}
            className="rounded-lg"
          />
        </div>
      </div>

      <div className="flex flex-wrap justify-end gap-2 pt-2">
        {onCancel && (
          <Button type="button" variant="outline" onClick={onCancel}>
            Cancel
          </Button>
        )}
        <Button type="submit" className={ch.btn} disabled={form.processing}>
          {form.processing ? 'Saving…' : submitLabel}
        </Button>
      </div>
    </form>
  )
}
