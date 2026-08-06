import { Head, router, useForm } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { FamilyReunionShell } from '@/components/family-reunion/FamilyReunionShell'
import { fr } from '@/pages/Organization/FamilyReunion/theme'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { FormEvent, useEffect, useMemo, useState } from 'react'
import { Pencil, Trash2 } from 'lucide-react'

type Branch = { id: number; name: string }
type ParentOption = { id: number; label: string; full_name: string }
type MemberRow = {
  id: number
  full_name: string
  email?: string | null
  photo_url?: string | null
  generation?: number | null
  relationship_label?: string | null
  status: string
  branch_id?: number | null
  branch?: string | null
  father_id?: number | null
  mother_id?: number | null
  spouse_id?: number | null
  father_name?: string | null
  mother_name?: string | null
  spouse_name?: string | null
  is_claimed: boolean
  is_founding: boolean
}

type Props = {
  organization: { id: number; name: string }
  branches: Branch[]
  members: MemberRow[]
  filters: { branch_id: number | null }
}

export default function Members({ organization, branches, members, filters }: Props) {
  const childForm = useForm({
    full_name: '',
    email: '',
    branch_id: '',
    father_id: '',
    mother_id: '',
    relationship_label: 'Child',
  })

  const profileForm = useForm({
    branch_id: '',
    father_id: '',
    mother_id: '',
    full_name: '',
    parent_not_listed: false as boolean,
  })

  const editForm = useForm({
    full_name: '',
    email: '',
    branch_id: '',
    father_id: '',
    mother_id: '',
    relationship_label: '',
  })

  const [parents, setParents] = useState<ParentOption[]>([])
  const [editingId, setEditingId] = useState<number | null>(null)

  useEffect(() => {
    fetch('/organization/family-reunion/members/search?q=')
      .then((r) => r.json())
      .then((json) => setParents(json.data ?? []))
      .catch(() => setParents([]))
  }, [members.length])

  const grouped = useMemo(() => {
    const map = new Map<string, MemberRow[]>()
    for (const m of members) {
      const key = m.branch || 'Unassigned'
      if (!map.has(key)) map.set(key, [])
      map.get(key)!.push(m)
    }
    return Array.from(map.entries())
  }, [members])

  const submitChild = (e: FormEvent) => {
    e.preventDefault()
    childForm.post('/organization/family-reunion/members', {
      preserveScroll: true,
      onSuccess: () => childForm.reset('full_name', 'email', 'father_id', 'mother_id'),
    })
  }

  const submitProfile = (e: FormEvent) => {
    e.preventDefault()
    profileForm.post('/organization/family-reunion/members/setup-profile', { preserveScroll: true })
  }

  const startEdit = (m: MemberRow) => {
    setEditingId(m.id)
    editForm.setData({
      full_name: m.full_name,
      email: m.email || '',
      branch_id: m.branch_id ? String(m.branch_id) : '',
      father_id: m.father_id ? String(m.father_id) : '',
      mother_id: m.mother_id ? String(m.mother_id) : '',
      relationship_label: m.relationship_label || '',
    })
  }

  const submitEdit = (e: FormEvent) => {
    e.preventDefault()
    if (!editingId) return
    editForm.put(`/organization/family-reunion/members/${editingId}`, {
      preserveScroll: true,
      onSuccess: () => setEditingId(null),
    })
  }

  const removeMember = (m: MemberRow) => {
    if (m.is_founding) return
    if (!confirm(`Delete ${m.full_name} from the family list?`)) return
    router.delete(`/organization/family-reunion/members/${m.id}`, { preserveScroll: true })
  }

  return (
    <AppLayout
      breadcrumbs={[
        { title: 'Family Reunion', href: '/organization/family-reunion' },
        { title: 'Members', href: '/organization/family-reunion/members' },
      ]}
    >
      <Head title="Family Members" />
      <div className="flex h-full min-w-0 flex-1 flex-col gap-4 p-3 sm:gap-6 sm:p-4 md:p-6">
        <FamilyReunionShell organizationName={organization.name}>
          <Card className="border-border shadow-sm">
            <CardHeader className="flex flex-col gap-3 space-y-0 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <CardTitle>Family members</CardTitle>
                <p className="mt-1 text-sm text-muted-foreground">
                  View members by branch. Edit names, parents, and relationship labels, or remove a record.
                </p>
              </div>
              <Select
                value={filters.branch_id ? String(filters.branch_id) : 'all'}
                onValueChange={(v) =>
                  router.get(
                    '/organization/family-reunion/members',
                    v === 'all' ? {} : { branch_id: v },
                    { preserveState: true, replace: true },
                  )
                }
              >
                <SelectTrigger className="w-full sm:w-[220px]">
                  <SelectValue placeholder="Filter by branch" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All branches</SelectItem>
                  {branches.map((b) => (
                    <SelectItem key={b.id} value={String(b.id)}>
                      {b.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </CardHeader>
            <CardContent className="space-y-6">
              {members.length === 0 ? (
                <p className="py-8 text-center text-sm text-muted-foreground">No members yet. Add children below.</p>
              ) : (
                grouped.map(([branchName, rows]) => (
                  <div key={branchName} className="space-y-3">
                    <h3 className="text-sm font-semibold uppercase tracking-wide text-muted-foreground">{branchName}</h3>
                    <div className="space-y-2">
                      {rows.map((m) => (
                        <div key={m.id} className="rounded-xl border border-border p-3 sm:p-4">
                          {editingId === m.id ? (
                            <form onSubmit={submitEdit} className="grid gap-3 sm:grid-cols-2">
                              <div className="space-y-1">
                                <Label>Full name</Label>
                                <Input value={editForm.data.full_name} onChange={(e) => editForm.setData('full_name', e.target.value)} />
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
                                  <SelectTrigger><SelectValue placeholder="Branch" /></SelectTrigger>
                                  <SelectContent>
                                    {branches.map((b) => (
                                      <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>
                                    ))}
                                  </SelectContent>
                                </Select>
                              </div>
                              <div className="flex gap-2 sm:col-span-2">
                                <Button type="submit" disabled={editForm.processing} className={fr.btn}>Save</Button>
                                <Button type="button" variant="outline" onClick={() => setEditingId(null)}>Cancel</Button>
                              </div>
                            </form>
                          ) : (
                            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                              <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                  <span className="font-medium text-foreground">{m.full_name}</span>
                                  {m.relationship_label && <Badge variant="secondary">{m.relationship_label}</Badge>}
                                  <Badge variant="outline" className="capitalize">{m.status}</Badge>
                                </div>
                                <div className="mt-1 text-xs text-muted-foreground">
                                  {[
                                    m.generation != null ? `Gen ${m.generation}` : null,
                                    m.father_name ? `Father: ${m.father_name}` : null,
                                    m.mother_name ? `Mother: ${m.mother_name}` : null,
                                    m.email || null,
                                  ]
                                    .filter(Boolean)
                                    .join(' · ')}
                                </div>
                              </div>
                              <div className="flex gap-2">
                                <Button type="button" size="sm" variant="outline" onClick={() => startEdit(m)}>
                                  <Pencil className="mr-1 h-3.5 w-3.5" /> Edit
                                </Button>
                                {!m.is_founding && (
                                  <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="border-red-200 text-red-700 hover:bg-red-50 hover:text-red-800"
                                    onClick={() => removeMember(m)}
                                  >
                                    <Trash2 className="mr-1 h-3.5 w-3.5" /> Delete
                                  </Button>
                                )}
                              </div>
                            </div>
                          )}
                        </div>
                      ))}
                    </div>
                  </div>
                ))
              )}
            </CardContent>
          </Card>

          <div className="grid gap-6 lg:grid-cols-2">
            <Card className="border-border shadow-sm">
              <CardHeader>
                <CardTitle>Add Children</CardTitle>
                <p className="text-sm text-muted-foreground">
                  Adds a child under selected parents. Relationship label defaults to Child.
                </p>
              </CardHeader>
              <CardContent>
                <form onSubmit={submitChild} className="space-y-4">
                  <div className="space-y-2">
                    <Label>Full name</Label>
                    <Input value={childForm.data.full_name} onChange={(e) => childForm.setData('full_name', e.target.value)} />
                    {childForm.errors.full_name && <p className="text-sm text-red-600">{childForm.errors.full_name}</p>}
                  </div>
                  <div className="space-y-2">
                    <Label>Relationship label</Label>
                    <Input
                      value={childForm.data.relationship_label}
                      onChange={(e) => childForm.setData('relationship_label', e.target.value)}
                      placeholder="Child"
                    />
                  </div>
                  <div className="space-y-2">
                    <Label>Email (optional)</Label>
                    <Input type="email" value={childForm.data.email} onChange={(e) => childForm.setData('email', e.target.value)} />
                  </div>
                  <div className="space-y-2">
                    <Label>Family branch</Label>
                    <Select value={childForm.data.branch_id || undefined} onValueChange={(v) => childForm.setData('branch_id', v)}>
                      <SelectTrigger className="w-full"><SelectValue placeholder="Select branch" /></SelectTrigger>
                      <SelectContent>
                        {branches.map((b) => (
                          <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </div>
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div className="space-y-2">
                      <Label>Father</Label>
                      <Select value={childForm.data.father_id || undefined} onValueChange={(v) => childForm.setData('father_id', v)}>
                        <SelectTrigger className="w-full"><SelectValue placeholder="Select father" /></SelectTrigger>
                        <SelectContent>
                          {parents.map((p) => (
                            <SelectItem key={p.id} value={String(p.id)}>{p.label}</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-2">
                      <Label>Mother</Label>
                      <Select value={childForm.data.mother_id || undefined} onValueChange={(v) => childForm.setData('mother_id', v)}>
                        <SelectTrigger className="w-full"><SelectValue placeholder="Select mother" /></SelectTrigger>
                        <SelectContent>
                          {parents.map((p) => (
                            <SelectItem key={p.id} value={String(p.id)}>{p.label}</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                  </div>
                  <Button type="submit" disabled={childForm.processing} className={`w-full sm:w-auto ${fr.btn}`}>
                    Add Child
                  </Button>
                </form>
              </CardContent>
            </Card>

            <Card className="border-border shadow-sm">
              <CardHeader>
                <CardTitle>Family Profile Setup</CardTitle>
                <p className="text-sm text-muted-foreground">
                  Link <strong>your</strong> record to your branch and parents. This does not change the founding couple at the top of the tree — edit that under Settings → Founding Couple.
                </p>
              </CardHeader>
              <CardContent>
                <form onSubmit={submitProfile} className="space-y-4">
                  <div className="space-y-2">
                    <Label>Family branch</Label>
                    <Select value={profileForm.data.branch_id || undefined} onValueChange={(v) => profileForm.setData('branch_id', v)}>
                      <SelectTrigger className="w-full"><SelectValue placeholder="Select branch" /></SelectTrigger>
                      <SelectContent>
                        {branches.map((b) => (
                          <SelectItem key={b.id} value={String(b.id)}>{b.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                    {profileForm.errors.branch_id && <p className="text-sm text-red-600">{profileForm.errors.branch_id}</p>}
                  </div>
                  <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div className="space-y-2">
                      <Label>Your father</Label>
                      <Select
                        value={profileForm.data.father_id || undefined}
                        onValueChange={(v) => profileForm.setData('father_id', v)}
                        disabled={profileForm.data.parent_not_listed}
                      >
                        <SelectTrigger className="w-full"><SelectValue placeholder="Select father" /></SelectTrigger>
                        <SelectContent>
                          {parents.map((p) => (
                            <SelectItem key={p.id} value={String(p.id)}>{p.label}</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      {profileForm.errors.father_id && <p className="text-sm text-red-600">{profileForm.errors.father_id}</p>}
                    </div>
                    <div className="space-y-2">
                      <Label>Your mother</Label>
                      <Select
                        value={profileForm.data.mother_id || undefined}
                        onValueChange={(v) => profileForm.setData('mother_id', v)}
                        disabled={profileForm.data.parent_not_listed}
                      >
                        <SelectTrigger className="w-full"><SelectValue placeholder="Select mother" /></SelectTrigger>
                        <SelectContent>
                          {parents.map((p) => (
                            <SelectItem key={p.id} value={String(p.id)}>{p.label}</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                  </div>
                  <label className="flex items-center gap-2 text-sm text-foreground">
                    <Checkbox
                      checked={profileForm.data.parent_not_listed}
                      onCheckedChange={(checked) => {
                        const on = checked === true
                        profileForm.setData('parent_not_listed', on)
                        if (on) {
                          profileForm.setData('father_id', '')
                          profileForm.setData('mother_id', '')
                        }
                      }}
                    />
                    Parent not listed
                  </label>
                  <p className="text-xs text-muted-foreground">
                    Missing a parent? Add them with Add Children first (or add spouse parents on the Branches page for a mother’s side), then select them here.
                  </p>
                  <Button type="submit" disabled={profileForm.processing} className={`w-full sm:w-auto ${fr.btn}`}>
                    Save Family Profile
                  </Button>
                </form>
              </CardContent>
            </Card>
          </div>
        </FamilyReunionShell>
      </div>
    </AppLayout>
  )
}
