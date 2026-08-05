import { useRef } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Paperclip, X } from 'lucide-react'
import { ch } from '@/pages/Organization/CommunicationHub/theme'
import { cn } from '@/lib/utils'

type Props = {
  files: File[]
  onChange: (files: File[]) => void
  accept?: string
  maxFiles?: number
  label?: string
  className?: string
}

export default function AttachmentUploader({
  files,
  onChange,
  accept = '.pdf,.doc,.docx,.png,.jpg,.jpeg,.gif,.webp',
  maxFiles = 5,
  label = 'Attachments',
  className,
}: Props) {
  const inputRef = useRef<HTMLInputElement>(null)

  const addFiles = (list: FileList | null) => {
    if (!list) return
    const next = [...files, ...Array.from(list)].slice(0, maxFiles)
    onChange(next)
  }

  const removeAt = (index: number) => {
    onChange(files.filter((_, i) => i !== index))
  }

  return (
    <div className={cn('space-y-2', className)}>
      <Label>{label}</Label>
      <div className="flex flex-wrap items-center gap-2">
        <Button
          type="button"
          variant="outline"
          className={cn(ch.btnOutline, 'gap-2')}
          onClick={() => inputRef.current?.click()}
        >
          <Paperclip className="h-4 w-4" />
          Add files
        </Button>
        <Input
          ref={inputRef}
          type="file"
          className="hidden"
          accept={accept}
          multiple
          onChange={(e) => {
            addFiles(e.target.files)
            e.target.value = ''
          }}
        />
        <span className="text-xs text-muted-foreground">Up to {maxFiles} files</span>
      </div>
      {files.length > 0 && (
        <ul className="space-y-1">
          {files.map((file, i) => (
            <li
              key={`${file.name}-${i}`}
              className="flex items-center justify-between rounded-xl border border-border bg-muted/40 px-3 py-2 text-sm"
            >
              <span className="truncate">{file.name}</span>
              <button
                type="button"
                className="ml-2 text-muted-foreground hover:text-destructive"
                onClick={() => removeAt(i)}
              >
                <X className="h-4 w-4" />
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
