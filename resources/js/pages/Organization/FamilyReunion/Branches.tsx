import { Head, useForm } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { FamilyReunionShell } from '@/components/family-reunion/FamilyReunionShell'
import { fr } from '@/pages/Organization/FamilyReunion/theme'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { FormEvent } from 'react'

type Branch = {
  id: number
  name: string
  status: string
  members_count: number
  head_member?: { id: number; full_name: string; photo_url?: string | null } | null
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

  const submit = (e: FormEvent) => {
    e.preventDefault()
    post('/organization/family-reunion/branches', {
      onSuccess: () => reset(),
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
              </CardHeader>
              <CardContent className="space-y-3">
                {branches.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No branches yet.</p>
                ) : (
                  branches.map((branch) => (
                    <div
                      key={branch.id}
                      className="flex flex-col gap-3 rounded-xl border border-border p-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                    >
                      <div className="min-w-0">
                        <div className="font-medium text-foreground">{branch.name}</div>
                        <div className="mt-1 text-sm text-muted-foreground">
                          {branch.head_member?.full_name ? `Head: ${branch.head_member.full_name} · ` : ''}
                          {branch.members_count} members
                          {branch.admin_user ? ` · Admin: ${branch.admin_user.name}` : ''}
                        </div>
                      </div>
                      <Badge variant="secondary" className="w-fit capitalize">
                        {branch.status}
                      </Badge>
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
