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
}

export default function DiscussionEditor({
  action,
  method = 'post',
  categories,
  initial = {},
  submitLabel = 'Post Discussion',
  onCancel,
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
        <Label>Category</Label>
        <CategorySelect
          categories={categories}
          value={form.data.category_id || 'all'}
          includeAll={false}
          onChange={(v) => form.setData('category_id', v === 'all' ? '' : v)}
        />
      </div>

      <div className="space-y-2">
        <Label htmlFor="body">Discussion</Label>
        <textarea
          id="body"
          value={form.data.body}
          onChange={(e) => form.setData('body', e.target.value)}
          rows={8}
          className={cn('w-full px-3 py-2 text-sm', ch.input, ch.focus)}
          required
        />
        {form.errors.body && <p className="text-sm text-destructive">{form.errors.body}</p>}
      </div>

      <AttachmentUploader files={files} onChange={setFiles} />

      <div className="flex flex-wrap justify-end gap-2 pt-2">
        {onCancel && (
          <Button type="button" variant="outline" onClick={onCancel}>
            Cancel
          </Button>
        )}
        <Button type="submit" className={ch.btn} disabled={form.processing}>
          {form.processing ? 'Posting…' : submitLabel}
        </Button>
      </div>
    </form>
  )
}
