import { Head, router } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { FamilyReunionShell } from '@/components/family-reunion/FamilyReunionShell'
import { fr } from '@/pages/Organization/FamilyReunion/theme'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Search } from 'lucide-react'

type MemberRow = {
  id: number
  full_name: string
  photo_url?: string | null
  email?: string | null
  branch?: string | null
  branch_id?: number | null
  generation?: number | null
  father?: string | null
  mother?: string | null
  status: string
  is_claimed: boolean
}

type Paginated<T> = {
  data: T[]
  links: { url: string | null; label: string; active: boolean }[]
}

type Props = {
  organization: { id: number; name: string }
  members: Paginated<MemberRow>
  branches: { id: number; name: string }[]
  filters: {
    q?: string
    branch_id?: number | null
    generation?: string | number | null
    status?: string | null
  }
}

export default function Directory({ organization, members, branches, filters }: Props) {
  const applyFilters = (patch: Record<string, string | number | null | undefined>) => {
    const next: Record<string, string | number> = {}
    const merged = {
      q: filters.q || undefined,
      branch_id: filters.branch_id || undefined,
      generation: filters.generation || undefined,
      status: filters.status || undefined,
      ...patch,
    }
    Object.entries(merged).forEach(([k, v]) => {
      if (v !== '' && v !== null && v !== undefined && v !== 'all') {
        next[k] = v as string | number
      }
    })
    router.get('/organization/family-reunion/directory', next, { preserveState: true, replace: true })
  }

  return (
    <AppLayout
      breadcrumbs={[
        { title: 'Family Reunion', href: '/organization/family-reunion' },
        { title: 'Directory', href: '/organization/family-reunion/directory' },
      ]}
    >
      <Head title="Family Directory" />
      <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <FamilyReunionShell organizationName={organization.name}>
          <Card className="border-border shadow-sm">
            <CardHeader>
              <CardTitle>Family Directory</CardTitle>
              <p className="text-sm text-muted-foreground">
                Searchable directory with member name, family branch, generation, and claimed status.
              </p>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid gap-3 md:grid-cols-4">
                <div className="relative md:col-span-2">
                  <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    className="pl-9"
                    defaultValue={filters.q || ''}
                    placeholder="Search members..."
                    onKeyDown={(e) => {
                      if (e.key === 'Enter') {
                        applyFilters({ q: (e.target as HTMLInputElement).value })
                      }
                    }}
                  />
                </div>
                <Select
                  value={filters.branch_id ? String(filters.branch_id) : 'all'}
                  onValueChange={(v) => applyFilters({ branch_id: v === 'all' ? null : Number(v) })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="All Branches" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Branches</SelectItem>
                    {branches.map((b) => (
                      <SelectItem key={b.id} value={String(b.id)}>
                        {b.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <Select
                  value={filters.status || 'all'}
                  onValueChange={(v) => applyFilters({ status: v === 'all' ? null : v })}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="All Statuses" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Statuses</SelectItem>
                    <SelectItem value="active">Active / Claimed</SelectItem>
                    <SelectItem value="unclaimed">Unclaimed</SelectItem>
                    <SelectItem value="deceased">Deceased</SelectItem>
                    <SelectItem value="archived">Archived</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="overflow-x-auto">
                <table className="w-full min-w-[720px] text-left text-sm">
                  <thead>
                    <tr className="border-b border-border text-xs uppercase tracking-wide text-muted-foreground">
                      <th className="px-2 py-2 font-medium">Member Name</th>
                      <th className="px-2 py-2 font-medium">Branch</th>
                      <th className="px-2 py-2 font-medium">Generation</th>
                      <th className="px-2 py-2 font-medium">Parents</th>
                      <th className="px-2 py-2 font-medium">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {members.data.length === 0 ? (
                      <tr>
                        <td colSpan={5} className="px-2 py-10 text-center text-muted-foreground">
                          No members match these filters.
                        </td>
                      </tr>
                    ) : (
                      members.data.map((member) => (
                        <tr key={member.id} className="border-b border-border">
                          <td className="px-2 py-3">
                            <div className="flex items-center gap-3">
                              <div className="h-8 w-8 overflow-hidden rounded-full bg-slate-100">
                                {member.photo_url ? (
                                  <img src={member.photo_url} alt="" className="h-full w-full object-cover" />
                                ) : (
                                  <div className="flex h-full w-full items-center justify-center text-xs font-medium text-muted-foreground">
                                    {member.full_name.slice(0, 1)}
                                  </div>
                                )}
                              </div>
                              <div>
                                <div className="font-medium text-foreground">{member.full_name}</div>
                                {member.email && <div className="text-xs text-muted-foreground">{member.email}</div>}
                              </div>
                            </div>
                          </td>
                          <td className={`px-2 py-3 ${fr.text}`}>{member.branch || '—'}</td>
                          <td className="px-2 py-3">{member.generation ?? '—'}</td>
                          <td className="px-2 py-3 text-muted-foreground">
                            {[member.father, member.mother].filter(Boolean).join(' / ') || '—'}
                          </td>
                          <td className="px-2 py-3">
                            <Badge
                              variant="secondary"
                              className={
                                member.is_claimed || member.status === 'active'
                                  ? 'bg-emerald-50 text-emerald-700'
                                  : member.status === 'unclaimed'
                                    ? 'bg-amber-50 text-amber-700'
                                    : 'bg-slate-100 text-muted-foreground'
                              }
                            >
                              {member.is_claimed ? 'Claimed' : member.status}
                            </Badge>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

              {members.links?.length > 3 && (
                <div className="flex flex-wrap gap-2">
                  {members.links.map((link, idx) => (
                    <button
                      key={idx}
                      type="button"
                      disabled={!link.url}
                      onClick={() => link.url && router.get(link.url)}
                      className={`rounded-md px-3 py-1 text-sm ${
                        link.active
                          ? `${fr.btn}`
                          : 'border border-border text-foreground hover:bg-muted'
                      } disabled:opacity-40`}
                      dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </FamilyReunionShell>
      </div>
    </AppLayout>
  )
}
