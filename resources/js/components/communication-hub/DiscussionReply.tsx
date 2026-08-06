import { useForm } from '@inertiajs/react'
import { Button } from '@/components/ui/button'
import { ch } from '@/pages/Organization/CommunicationHub/theme'
import { cn } from '@/lib/utils'

type Props = {
  action: string
  method?: 'post' | 'put'
  initialBody?: string
  placeholder?: string
  submitLabel?: string
  nested?: boolean
  onCancel?: () => void
}

export default function DiscussionReply({
  action,
  method = 'post',
  initialBody = '',
  placeholder = 'Write a reply…',
  submitLabel = 'Reply',
  nested = false,
  onCancel,
}: Props) {
  const form = useForm({
    body: initialBody,
    _method: method === 'put' ? 'put' : undefined,
  })

  return (
    <form
      onSubmit={(e) => {
        e.preventDefault()
        form.post(action, {
          onSuccess: () => {
            form.reset('body')
            onCancel?.()
          },
        })
      }}
      className={cn('space-y-2', nested && 'ml-4 border-l-2 border-purple-500/20 pl-3 sm:ml-8 sm:pl-4')}
    >
      <textarea
        value={form.data.body}
        onChange={(e) => form.setData('body', e.target.value)}
        rows={nested ? 3 : 4}
        placeholder={placeholder}
        className={cn('w-full px-3 py-2 text-sm', ch.input, ch.focus)}
        required
      />
      {form.errors.body && <p className="text-sm text-destructive">{form.errors.body}</p>}
      <div className="flex justify-end gap-2">
        {onCancel && (
          <Button type="button" variant="ghost" size="sm" onClick={onCancel}>
            Cancel
          </Button>
        )}
        <Button type="submit" size="sm" className={ch.btnSm} disabled={form.processing}>
          {form.processing ? 'Sending…' : submitLabel}
        </Button>
      </div>
    </form>
  )
}
