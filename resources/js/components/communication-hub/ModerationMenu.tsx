import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Button } from '@/components/ui/button'
import { MoreHorizontal, MoreVertical } from 'lucide-react'

export type ModerationAction = {
  label: string
  onClick: () => void
  destructive?: boolean
  disabled?: boolean
}

type Props = {
  actions: ModerationAction[]
  vertical?: boolean
  align?: 'start' | 'end'
}

export default function ModerationMenu({ actions, vertical = false, align = 'end' }: Props) {
  if (!actions.length) return null

  const Icon = vertical ? MoreVertical : MoreHorizontal

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="h-8 w-8 shrink-0 text-muted-foreground hover:text-foreground"
          onClick={(e) => e.preventDefault()}
        >
          <Icon className="h-4 w-4" />
          <span className="sr-only">Actions</span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align={align} className="w-44">
        {actions.map((action) => (
          <DropdownMenuItem
            key={action.label}
            disabled={action.disabled}
            className={action.destructive ? 'text-destructive focus:text-destructive' : ''}
            onSelect={(e) => {
              e.preventDefault()
              action.onClick()
            }}
          >
            {action.label}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
