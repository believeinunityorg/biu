import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { type HubCategory } from './types'
import { ch } from '@/pages/Organization/CommunicationHub/theme'
import { cn } from '@/lib/utils'

type Props = {
  categories: HubCategory[]
  value?: string | number | null
  onChange: (value: string) => void
  includeAll?: boolean
  className?: string
}

export default function CategorySelect({
  categories,
  value,
  onChange,
  includeAll = true,
  className,
}: Props) {
  const current = value != null && value !== '' ? String(value) : 'all'

  return (
    <Select value={current} onValueChange={onChange}>
      <SelectTrigger className={cn('h-10 w-full sm:w-48', ch.input, ch.focus, className)}>
        <SelectValue placeholder="All Categories" />
      </SelectTrigger>
      <SelectContent>
        {includeAll && <SelectItem value="all">All Categories</SelectItem>}
        {categories.map((cat) => (
          <SelectItem key={cat.id} value={String(cat.id)}>
            {cat.name}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
