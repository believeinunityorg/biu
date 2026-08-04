import { Head, useForm } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { FamilyReunionShell } from '@/components/family-reunion/FamilyReunionShell'
import { fr } from '@/pages/Organization/FamilyReunion/theme'
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
import { FormEvent, useEffect, useState } from 'react'

type Branch = { id: number; name: string }

type ParentOption = { id: number; label: string; full_name: string }

type Props = {
  organization: { id: number; name: string }
  branches: Branch[]
}

export default function Members({ organization, branches }: Props) {
  const childForm = useForm({
    full_name: '',
    email: '',
    branch_id: '',
    father_id: '',
    mother_id: '',
  })

  const profileForm = useForm({
    branch_id: '',
    father_id: '',
    mother_id: '',
    full_name: '',
    parent_not_listed: false as boolean,
  })

  const [parents, setParents] = useState<ParentOption[]>([])

  useEffect(() => {
    fetch('/organization/family-reunion/members/search?q=')
      .then((r) => r.json())
      .then((json) => setParents(json.data ?? []))
      .catch(() => setParents([]))
  }, [])

  const submitChild = (e: FormEvent) => {
    e.preventDefault()
    childForm.post('/organization/family-reunion/members', {
      onSuccess: () => childForm.reset(),
    })
  }

  const submitProfile = (e: FormEvent) => {
    e.preventDefault()
    profileForm.post('/organization/family-reunion/members/setup-profile')
  }

  return (
    <AppLayout
      breadcrumbs={[
        { title: 'Family Reunion', href: '/organization/family-reunion' },
        { title: 'Members', href: '/organization/family-reunion/members' },
      ]}
    >
      <Head title="Family Members" />
      <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <FamilyReunionShell organizationName={organization.name}>
          <div className="grid gap-6 lg:grid-cols-2">
            <Card className="border-border shadow-sm">
              <CardHeader>
                <CardTitle>Add Children</CardTitle>
                <p className="text-sm text-muted-foreground">
                  Full name required. Optional email creates an unclaimed record that can be claimed on registration.
                </p>
              </CardHeader>
              <CardContent>
                <form onSubmit={submitChild} className="space-y-4">
                  <div className="space-y-2">
                    <Label>Full name</Label>
                    <Input
                      value={childForm.data.full_name}
                      onChange={(e) => childForm.setData('full_name', e.target.value)}
                    />
                    {childForm.errors.full_name && <p className="text-sm text-red-600">{childForm.errors.full_name}</p>}
                  </div>
                  <div className="space-y-2">
                    <Label>Email (optional)</Label>
                    <Input
                      type="email"
                      value={childForm.data.email}
                      onChange={(e) => childForm.setData('email', e.target.value)}
                    />
                  </div>
                  <div className="space-y-2">
                    <Label>Family branch</Label>
                    <Select
                      value={childForm.data.branch_id || undefined}
                      onValueChange={(v) => childForm.setData('branch_id', v)}
                    >
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
                  <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-2">
                      <Label>Father</Label>
                      <Select
                        value={childForm.data.father_id || undefined}
                        onValueChange={(v) => childForm.setData('father_id', v)}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Select father" />
                        </SelectTrigger>
                        <SelectContent>
                          {parents.map((p) => (
                            <SelectItem key={p.id} value={String(p.id)}>
                              {p.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                    <div className="space-y-2">
                      <Label>Mother</Label>
                      <Select
                        value={childForm.data.mother_id || undefined}
                        onValueChange={(v) => childForm.setData('mother_id', v)}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Select mother" />
                        </SelectTrigger>
                        <SelectContent>
                          {parents.map((p) => (
                            <SelectItem key={p.id} value={String(p.id)}>
                              {p.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>
                  </div>
                  <Button type="submit" disabled={childForm.processing} className={fr.btn}>
                    Add Child
                  </Button>
                </form>
              </CardContent>
            </Card>

            <Card className="border-border shadow-sm">
              <CardHeader>
                <CardTitle>Family Profile Setup</CardTitle>
                <p className="text-sm text-muted-foreground">
                  When joining, select family branch, father, and mother. Use searchable records from the family tree.
                </p>
              </CardHeader>
              <CardContent>
                <form onSubmit={submitProfile} className="space-y-4">
                  <div className="space-y-2">
                    <Label>Family branch</Label>
                    <Select
                      value={profileForm.data.branch_id || undefined}
                      onValueChange={(v) => profileForm.setData('branch_id', v)}
                    >
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
                    {profileForm.errors.branch_id && (
                      <p className="text-sm text-red-600">{profileForm.errors.branch_id}</p>
                    )}
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-2">
                      <Label>Father</Label>
                      <Select
                        value={profileForm.data.father_id || undefined}
                        onValueChange={(v) => profileForm.setData('father_id', v)}
                        disabled={profileForm.data.parent_not_listed}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Select father" />
                        </SelectTrigger>
                        <SelectContent>
                          {parents.map((p) => (
                            <SelectItem key={p.id} value={String(p.id)}>
                              {p.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      {profileForm.errors.father_id && (
                        <p className="text-sm text-red-600">{profileForm.errors.father_id}</p>
                      )}
                    </div>
                    <div className="space-y-2">
                      <Label>Mother</Label>
                      <Select
                        value={profileForm.data.mother_id || undefined}
                        onValueChange={(v) => profileForm.setData('mother_id', v)}
                        disabled={profileForm.data.parent_not_listed}
                      >
                        <SelectTrigger>
                          <SelectValue placeholder="Select mother" />
                        </SelectTrigger>
                        <SelectContent>
                          {parents.map((p) => (
                            <SelectItem key={p.id} value={String(p.id)}>
                              {p.label}
                            </SelectItem>
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
                    Need to add a missing parent? Use Add Children first, then select them here.
                  </p>
                  <Button type="submit" disabled={profileForm.processing} className={fr.btn}>
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
