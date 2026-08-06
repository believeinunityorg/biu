import type { ReactNode } from 'react'
import type { LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'
import { ch } from '@/pages/Organization/CommunicationHub/theme'

type Props = {
  icon: LucideIcon
  title: string
  description?: string
  className?: string
  action?: ReactNode
}

export default function HubEmptyState({ icon: Icon, title, description, className, action }: Props) {
  return (
    <div className={cn(ch.empty, className)}>
      <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-purple-500/15 dark:bg-purple-500/20">
        <Icon className="h-6 w-6 text-purple-500/80 dark:text-purple-400" />
      </div>
      <p className="font-medium text-foreground">{title}</p>
      {description && <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  )
}
