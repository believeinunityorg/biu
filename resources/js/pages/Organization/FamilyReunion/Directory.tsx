import { Head, router, useForm } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { FamilyReunionShell } from '@/components/family-reunion/FamilyReunionShell'
import { fr } from '@/pages/Organization/FamilyReunion/theme'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Pencil, Search, Trash2 } from 'lucide-react'
import { FormEvent, useState } from 'react'

type MemberRow = {
  id: number
  full_name: string
  photo_url?: string | null
  email?: string | null
  branch?: string | null
  branch_id?: number | null
  generation?: number | null
  relationship_label?: string | null
  father?: string | null
  father_id?: number | null
  mother?: string | null
  mother_id?: number | null
  status: string
  is_claimed: boolean
  is_founding?: boolean
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
  const [editingId, setEditingId] = useState<number | null>(null)
  const editForm = useForm({
    full_name: '',
    email: '',
    branch_id: '',
    relationship_label: '',
  })

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

  const startEdit = (member: MemberRow) => {
    setEditingId(member.id)
    editForm.setData({
      full_name: member.full_name,
      email: member.email || '',
      branch_id: member.branch_id ? String(member.branch_id) : '',
      relationship_label: member.relationship_label || '',
    })
    editForm.clearErrors()
  }

  const submitEdit = (e: FormEvent) => {
    e.preventDefault()
    if (!editingId) return
    editForm.put(`/organization/family-reunion/members/${editingId}`, {
      preserveScroll: true,
      onSuccess: () => setEditingId(null),
    })
  }

  const deleteMember = (member: MemberRow) => {
    if (member.is_founding) {
      alert('Founding couple members cannot be deleted here. Edit them under Founding Couple settings.')
      return
    }
    if (!confirm(`Delete ${member.full_name} from the family list?`)) return
    router.delete(`/organization/family-reunion/members/${member.id}`, { preserveScroll: true })
  }

  const actionButtons = (member: MemberRow) => (
    <div className="flex shrink-0 gap-2">
      <Button type="button" size="sm" variant="outline" onClick={() => startEdit(member)}>
        <Pencil className="mr-1 h-3.5 w-3.5" />
        Edit
      </Button>
      {!member.is_founding && (
        <Button
          type="button"
          size="sm"
          variant="outline"
          className="border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800"
          onClick={() => deleteMember(member)}
        >
          <Trash2 className="mr-1 h-3.5 w-3.5" />
          Delete
        </Button>
      )}
    </div>
  )

  const editFormBlock = (
    <form onSubmit={submitEdit} className="grid gap-3 rounded-lg border border-border bg-muted/30 p-3 sm:grid-cols-2">
      <div className="space-y-1">
        <Label>Full name</Label>
        <Input value={editForm.data.full_name} onChange={(e) => editForm.setData('full_name', e.target.value)} required />
        {editForm.errors.full_name && <p className="text-sm text-red-600">{editForm.errors.full_name}</p>}
      </div>
      <div className="space-y-1">
        <Label>Relationship label</Label>
        <Input
          value={editForm.data.relationship_label}
          onChange={(e) => editForm.setData('relationship_label', e.target.value)}
          placeholder="Child, Sister, Uncle…"
        />
      </div>
      <div className="space-y-1">
        <Label>Email</Label>
        <Input type="email" value={editForm.data.email} onChange={(e) => editForm.setData('email', e.target.value)} />
      </div>
      <div className="space-y-1">
        <Label>Branch</Label>
        <Select value={editForm.data.branch_id || undefined} onValueChange={(v) => editForm.setData('branch_id', v)}>
          <SelectTrigger>
            <SelectValue placeholder="Select branch" />
          </SelectTrigger>
          <SelectContent>
            {branches.map((b) => (
              <SelectItem key={b.id} value={String(b.id)}>
                {b.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="flex gap-2 sm:col-span-2">
        <Button type="submit" disabled={editForm.processing} className={fr.btn}>
          Save
        </Button>
        <Button type="button" variant="outline" onClick={() => setEditingId(null)}>
          Cancel
        </Button>
      </div>
    </form>
  )

  return (
    <AppLayout
      breadcrumbs={[
        { title: 'Family Reunion', href: '/organization/family-reunion' },
        { title: 'Directory', href: '/organization/family-reunion/directory' },
      ]}
    >
      <Head title="Family Directory" />
      <div className="flex h-full min-w-0 flex-1 flex-col gap-4 p-3 sm:gap-6 sm:p-4 md:p-6">
        <FamilyReunionShell organizationName={organization.name}>
          <Card className="border-border shadow-sm">
            <CardHeader>
              <CardTitle>Family Directory</CardTitle>
              <p className="text-sm text-muted-foreground">
                Search members and use Edit or Delete on any row.
              </p>
            </CardHeader>
            <CardContent className="space-y-4 px-4 sm:px-6">
              <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                <div className="relative sm:col-span-2">
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
                  <SelectTrigger className="w-full">
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
                  <SelectTrigger className="w-full">
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

              {members.data.length === 0 ? (
                <p className="py-10 text-center text-sm text-muted-foreground">No members match these filters.</p>
              ) : (
                <>
                  <div className="hidden md:block overflow-x-auto rounded-lg border border-border">
                    <table className="w-full text-left text-sm">
                      <thead>
                        <tr className="border-b border-border bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                          <th className="px-4 py-3 font-medium">Member Name</th>
                          <th className="px-4 py-3 font-medium">Branch</th>
                          <th className="px-4 py-3 font-medium">Generation</th>
                          <th className="px-4 py-3 font-medium">Parents</th>
                          <th className="px-4 py-3 font-medium">Status</th>
                          <th className="px-4 py-3 font-medium">Actions</th>
                        </tr>
                      </thead>
                      <tbody>
                        {members.data.map((member) => (
                          <tr key={member.id} className="border-b border-border last:border-0 align-top">
                            <td className="px-4 py-3" colSpan={editingId === member.id ? 6 : 1}>
                              {editingId === member.id ? (
                                editFormBlock
                              ) : (
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
                                    <div className="flex flex-wrap items-center gap-2">
                                      <span className="font-medium text-foreground">{member.full_name}</span>
                                      {member.relationship_label && (
                                        <Badge variant="secondary">{member.relationship_label}</Badge>
                                      )}
                                    </div>
                                    {member.email && <div className="text-xs text-muted-foreground">{member.email}</div>}
                                  </div>
                                </div>
                              )}
                            </td>
                            {editingId !== member.id && (
                              <>
                                <td className={`px-4 py-3 ${fr.text}`}>{member.branch || '—'}</td>
                                <td className="px-4 py-3">{member.generation ?? '—'}</td>
                                <td className="px-4 py-3 text-muted-foreground">
                                  {[member.father, member.mother].filter(Boolean).join(' / ') || '—'}
                                </td>
                                <td className="px-4 py-3">
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
                                <td className="px-4 py-3">{actionButtons(member)}</td>
                              </>
                            )}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>

                  <div className="space-y-3 md:hidden">
                    {members.data.map((member) => (
                      <Card key={member.id} className="border-border/60">
                        <CardContent className="space-y-3 p-4">
                          {editingId === member.id ? (
                            editFormBlock
                          ) : (
                            <>
                              <div className="flex items-start justify-between gap-3">
                                <div className="flex min-w-0 items-center gap-3">
                                  <div className="h-10 w-10 shrink-0 overflow-hidden rounded-full bg-slate-100">
                                    {member.photo_url ? (
                                      <img src={member.photo_url} alt="" className="h-full w-full object-cover" />
                                    ) : (
                                      <div className="flex h-full w-full items-center justify-center text-sm font-medium text-muted-foreground">
                                        {member.full_name.slice(0, 1)}
                                      </div>
                                    )}
                                  </div>
                                  <div className="min-w-0">
                                    <div className="truncate font-medium text-foreground">{member.full_name}</div>
                                    {member.relationship_label && (
                                      <Badge variant="secondary" className="mt-1">
                                        {member.relationship_label}
                                      </Badge>
                                    )}
                                    {member.email && (
                                      <div className="truncate text-xs text-muted-foreground">{member.email}</div>
                                    )}
                                  </div>
                                </div>
                                <Badge
                                  variant="secondary"
                                  className={
                                    member.is_claimed || member.status === 'active'
                                      ? 'shrink-0 bg-emerald-50 text-emerald-700'
                                      : member.status === 'unclaimed'
                                        ? 'shrink-0 bg-amber-50 text-amber-700'
                                        : 'shrink-0 bg-slate-100 text-muted-foreground'
                                  }
                                >
                                  {member.is_claimed ? 'Claimed' : member.status}
                                </Badge>
                              </div>
                              <div className="grid grid-cols-2 gap-3 text-sm">
                                <div>
                                  <p className="text-muted-foreground">Branch</p>
                                  <p className={`font-medium ${fr.text}`}>{member.branch || '—'}</p>
                                </div>
                                <div>
                                  <p className="text-muted-foreground">Generation</p>
                                  <p className="font-medium">{member.generation ?? '—'}</p>
                                </div>
                                <div className="col-span-2">
                                  <p className="text-muted-foreground">Parents</p>
                                  <p className="font-medium">
                                    {[member.father, member.mother].filter(Boolean).join(' / ') || '—'}
                                  </p>
                                </div>
                              </div>
                              {actionButtons(member)}
                            </>
                          )}
                        </CardContent>
                      </Card>
                    ))}
                  </div>
                </>
              )}

              {members.links?.length > 3 && (
                <div className="flex flex-wrap justify-center gap-2 sm:justify-start">
                  {members.links.map((link, idx) => (
                    <button
                      key={idx}
                      type="button"
                      disabled={!link.url}
                      onClick={() => link.url && router.get(link.url)}
                      className={`rounded-md px-3 py-1.5 text-sm ${
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
