import { useEffect, useState } from 'react'
import { router } from '@inertiajs/react'
import SearchBar from './SearchBar'
import { cn } from '@/lib/utils'
import { ch } from '@/pages/Organization/CommunicationHub/theme'

type FilterOption = { value: string; label: string }

type Props = {
  filters: FilterOption[]
  active: string
  search: string
  routeName: string
  queryKey?: string
  searchKey?: string
  extraQuery?: Record<string, string | number | undefined | null>
  className?: string
}

export default function AnnouncementFilters({
  filters,
  active,
  search: initialSearch,
  routeName,
  queryKey = 'filter',
  searchKey = 'search',
  extraQuery = {},
  className,
}: Props) {
  const [search, setSearch] = useState(initialSearch)

  useEffect(() => {
    setSearch(initialSearch)
  }, [initialSearch])

  useEffect(() => {
    const t = setTimeout(() => {
      if (search === initialSearch) return
      router.get(
        route(routeName),
        { ...extraQuery, [queryKey]: active, [searchKey]: search || undefined },
        { preserveState: true, replace: true },
      )
    }, 350)
    return () => clearTimeout(t)
  }, [search])

  const setFilter = (value: string) => {
    router.get(
      route(routeName),
      { ...extraQuery, [queryKey]: value, [searchKey]: search || undefined },
      { preserveState: true, replace: true },
    )
  }

  return (
    <div className={cn('flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between', className)}>
      <div className="flex flex-wrap gap-1.5">
        {filters.map((f) => (
          <button
            key={f.value}
            type="button"
            onClick={() => setFilter(f.value)}
            className={cn(
              'rounded-full px-3 py-1.5 text-sm transition',
              active === f.value ? ch.tabActive : ch.tabInactive,
            )}
          >
            {f.label}
          </button>
        ))}
      </div>
      <SearchBar value={search} onChange={setSearch} placeholder="Search announcements…" className="sm:w-64" />
    </div>
  )
}
