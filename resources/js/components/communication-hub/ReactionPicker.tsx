import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

const DEFAULT_EMOJIS = ['👍', '❤️', '🙏', '🎉', '😮', '😢', '🔥']

type Props = {
  emojis?: string[]
  onSelect: (emoji: string) => void
  className?: string
}

export default function ReactionPicker({ emojis = DEFAULT_EMOJIS, onSelect, className }: Props) {
  return (
    <div className={cn('flex flex-wrap gap-1', className)}>
      {emojis.map((emoji) => (
        <Button
          key={emoji}
          type="button"
          variant="ghost"
          size="sm"
          className="h-8 w-8 p-0 text-base hover:bg-purple-500/10 dark:hover:bg-purple-500/20"
          onClick={() => onSelect(emoji)}
        >
          {emoji}
        </Button>
      ))}
    </div>
  )
}
