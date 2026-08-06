import { useForm } from '@inertiajs/react'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import AttachmentUploader from './AttachmentUploader'
import CategorySelect from './CategorySelect'
import { type HubCategory } from './types'
import { ch } from '@/pages/Organization/CommunicationHub/theme'
import { cn } from '@/lib/utils'

type Props = {
  action: string
  method?: 'post' | 'put'
  categories: HubCategory[]
  initial?: {
    title?: string
    body?: string
    category_id?: number | null
  }
  submitLabel?: string
  onCancel?: () => void
  onSuccess?: () => void
  /** Compact styling for dialogs */
  variant?: 'page' | 'modal'
  className?: string
}

export default function DiscussionEditor({
  action,
  method = 'post',
  categories,
  initial = {},
  submitLabel = 'Post Discussion',
  onCancel,
  onSuccess,
  variant = 'page',
  className,
}: Props) {
  const [files, setFiles] = useState<File[]>([])
  const form = useForm({
    title: initial.title ?? '',
    body: initial.body ?? '',
    category_id: initial.category_id ? String(initial.category_id) : '',
    attachments: [] as File[],
    _method: method === 'put' ? 'put' : undefined,
  })

  const submit = (e: React.FormEvent) => {
    e.preventDefault()
    form.transform((data) => ({
      ...data,
      category_id: data.category_id || null,
      attachments: files,
    }))
    form.post(action, {
      forceFormData: true,
      preserveScroll: variant === 'modal',
      onSuccess: () => onSuccess?.(),
    })
  }

  const isModal = variant === 'modal'

  return (
    <form
      onSubmit={submit}
      className={cn(
        isModal ? 'space-y-4' : cn(ch.card, 'space-y-5 p-5 sm:p-6'),
        className,
      )}
    >
      <div className="space-y-2">
        <Label htmlFor="discussion-title">Title</Label>
        <Input
          id="discussion-title"
          value={form.data.title}
          onChange={(e) => form.setData('title', e.target.value)}
          className={cn('rounded-lg', ch.focus)}
          placeholder="What do you want to discuss?"
          required
        />
        {form.errors.title && <p className="text-sm text-destructive">{form.errors.title}</p>}
      </div>

      {categories.length > 0 && (
        <div className="space-y-2">
          <Label>Category</Label>
          <CategorySelect
            categories={categories}
            value={form.data.category_id || 'all'}
            includeAll={false}
            onChange={(v) => form.setData('category_id', v === 'all' ? '' : v)}
          />
        </div>
      )}

      <div className="space-y-2">
        <Label htmlFor="discussion-body">Discussion</Label>
        <textarea
          id="discussion-body"
          value={form.data.body}
          onChange={(e) => form.setData('body', e.target.value)}
          rows={isModal ? 6 : 8}
          className={cn('w-full px-3 py-2 text-sm', ch.input, ch.focus)}
          placeholder="Share your question or thoughts…"
          required
        />
        {form.errors.body && <p className="text-sm text-destructive">{form.errors.body}</p>}
      </div>

      <AttachmentUploader files={files} onChange={setFiles} />

      <div className="flex flex-wrap justify-end gap-2 pt-1">
        {onCancel && (
          <Button type="button" variant="outline" className="rounded-xl" onClick={onCancel}>
            Cancel
          </Button>
        )}
        <Button type="submit" className={cn(ch.btn, 'rounded-xl')} disabled={form.processing}>
          {form.processing ? 'Posting…' : submitLabel}
        </Button>
      </div>
    </form>
  )
}
