import { Head, useForm } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { FamilyReunionShell } from '@/components/family-reunion/FamilyReunionShell'
import { fr } from '@/pages/Organization/FamilyReunion/theme'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { FormEvent, useState } from 'react'

type Branch = {
  id: number
  name: string
  status: string
  members_count: number
  head_member?: { id: number; full_name: string; photo_url?: string | null } | null
  spouse?: {
    id: number
    full_name: string
    father_name?: string | null
    mother_name?: string | null
  } | null
  admin_user?: { id: number; name: string; email: string } | null
}

type Props = {
  organization: { id: number; name: string }
  branches: Branch[]
  has_founders: boolean
}

export default function Branches({ organization, branches, has_founders }: Props) {
  const { data, setData, post, processing, errors, reset } = useForm({
    name: '',
    head_member_full_name: '',
  })

  const spouseForm = useForm({
    spouse_name: '',
    maternal_grandfather_name: '',
    maternal_grandmother_name: '',
  })

  const [spouseBranchId, setSpouseBranchId] = useState<number | null>(null)

  const submit = (e: FormEvent) => {
    e.preventDefault()
    post('/organization/family-reunion/branches', {
      onSuccess: () => reset(),
    })
  }

  const submitSpouse = (e: FormEvent) => {
    e.preventDefault()
    if (!spouseBranchId) return
    spouseForm.post(`/organization/family-reunion/branches/${spouseBranchId}/spouse-parents`, {
      preserveScroll: true,
      onSuccess: () => {
        spouseForm.reset()
        setSpouseBranchId(null)
      },
    })
  }

  return (
    <AppLayout
      breadcrumbs={[
        { title: 'Family Reunion', href: '/organization/family-reunion' },
        { title: 'Branches', href: '/organization/family-reunion/branches' },
      ]}
    >
      <Head title="Family Branches" />
      <div className="flex h-full min-w-0 flex-1 flex-col gap-4 p-3 sm:gap-6 sm:p-4 md:p-6">
        <FamilyReunionShell organizationName={organization.name}>
          <div className="grid gap-6 lg:grid-cols-3">
            <Card className="border-border shadow-sm lg:col-span-1">
              <CardHeader>
                <CardTitle>Add Family Branch</CardTitle>
                <p className="text-sm text-muted-foreground">
                  Each child of the founding couple becomes a selectable family branch.
                </p>
              </CardHeader>
              <CardContent>
                {!has_founders ? (
                  <p className="text-sm text-amber-700">Add the founding couple before creating branches.</p>
                ) : (
                  <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                      <Label htmlFor="name">Branch name</Label>
                      <Input
                        id="name"
                        placeholder="e.g. John Matthews Branch"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                      />
                      {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="head_member_full_name">Child full name (optional)</Label>
                      <Input
                        id="head_member_full_name"
                        placeholder="Defaults to branch name"
                        value={data.head_member_full_name}
                        onChange={(e) => setData('head_member_full_name', e.target.value)}
                      />
                    </div>
                    <Button type="submit" disabled={processing} className={`w-full sm:w-auto ${fr.btn}`}>
                      {processing ? 'Adding…' : 'Add Branch'}
                    </Button>
                  </form>
                )}
              </CardContent>
            </Card>

            <Card className="border-border shadow-sm lg:col-span-2">
              <CardHeader>
                <CardTitle>Branches</CardTitle>
                <p className="text-sm text-muted-foreground">
                  For a mother’s side (spouse), add spouse name and her parents so the tree does not reuse the founding couple.
                </p>
              </CardHeader>
              <CardContent className="space-y-3">
                {branches.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No branches yet.</p>
                ) : (
                  branches.map((branch) => (
                    <div key={branch.id} className="space-y-3 rounded-xl border border-border p-4">
                      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                        <div className="min-w-0">
                          <div className="font-medium text-foreground">{branch.name}</div>
                          <div className="mt-1 text-sm text-muted-foreground">
                            {branch.head_member?.full_name ? `Head: ${branch.head_member.full_name} · ` : ''}
                            {branch.members_count} members
                            {branch.admin_user ? ` · Admin: ${branch.admin_user.name}` : ''}
                          </div>
                          {branch.spouse && (
                            <div className="mt-1 text-sm text-muted-foreground">
                              Spouse: {branch.spouse.full_name}
                              {branch.spouse.father_name || branch.spouse.mother_name
                                ? ` · Parents: ${[branch.spouse.father_name, branch.spouse.mother_name].filter(Boolean).join(' & ')}`
                                : ''}
                            </div>
                          )}
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge variant="secondary" className="w-fit capitalize">
                            {branch.status}
                          </Badge>
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => {
                              setSpouseBranchId(branch.id)
                              spouseForm.setData({
                                spouse_name: branch.spouse?.full_name || '',
                                maternal_grandfather_name: branch.spouse?.father_name || '',
                                maternal_grandmother_name: branch.spouse?.mother_name || '',
                              })
                            }}
                          >
                            Add spouse / parents
                          </Button>
                        </div>
                      </div>

                      {spouseBranchId === branch.id && (
                        <form onSubmit={submitSpouse} className="grid gap-3 border-t border-border pt-3 sm:grid-cols-2">
                          <div className="space-y-1 sm:col-span-2">
                            <Label>Spouse name (e.g. mother / in-law)</Label>
                            <Input
                              value={spouseForm.data.spouse_name}
                              onChange={(e) => spouseForm.setData('spouse_name', e.target.value)}
                              required
                            />
                          </div>
                          <div className="space-y-1">
                            <Label>Spouse’s father</Label>
                            <Input
                              value={spouseForm.data.maternal_grandfather_name}
                              onChange={(e) => spouseForm.setData('maternal_grandfather_name', e.target.value)}
                              placeholder="Maternal grandfather"
                            />
                          </div>
                          <div className="space-y-1">
                            <Label>Spouse’s mother</Label>
                            <Input
                              value={spouseForm.data.maternal_grandmother_name}
                              onChange={(e) => spouseForm.setData('maternal_grandmother_name', e.target.value)}
                              placeholder="Maternal grandmother"
                            />
                          </div>
                          <div className="flex gap-2 sm:col-span-2">
                            <Button type="submit" disabled={spouseForm.processing} className={fr.btn}>
                              Save spouse & parents
                            </Button>
                            <Button type="button" variant="outline" onClick={() => setSpouseBranchId(null)}>
                              Cancel
                            </Button>
                          </div>
                        </form>
                      )}
                    </div>
                  ))
                )}
              </CardContent>
            </Card>
          </div>
        </FamilyReunionShell>
      </div>
    </AppLayout>
  )
}
