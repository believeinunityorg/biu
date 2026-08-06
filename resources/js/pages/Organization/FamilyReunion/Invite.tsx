import { Head, useForm } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { FamilyReunionShell } from '@/components/family-reunion/FamilyReunionShell'
import { fr } from '@/pages/Organization/FamilyReunion/theme'
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
import { FormEvent } from 'react'

type Props = {
  organization: { id: number; name: string }
  branches: { id: number; name: string }[]
}

export default function Invite({ organization, branches }: Props) {
  const { data, setData, post, processing, errors, reset } = useForm({
    email: '',
    full_name: '',
    branch_id: '',
    relationship_label: '',
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    post('/organization/family-reunion/invite', {
      onSuccess: () => reset(),
    })
  }

  return (
    <AppLayout
      breadcrumbs={[
        { title: 'Family Reunion', href: '/organization/family-reunion' },
        { title: 'Invite', href: '/organization/family-reunion/invite' },
      ]}
    >
      <Head title="Invite Family Member" />
      <div className="flex h-full min-w-0 flex-1 flex-col gap-4 p-3 sm:gap-6 sm:p-4 md:p-6">
        <FamilyReunionShell organizationName={organization.name}>
          <Card className="mx-auto w-full max-w-xl border-border shadow-sm">
            <CardHeader>
              <CardTitle>Invite family member</CardTitle>
              <p className="text-sm text-muted-foreground">
                Enter one email. We create an unclaimed family record and send a simple invite. When they accept,
                they confirm branch and relationship (uncle, sister, son, etc.).
              </p>
            </CardHeader>
            <CardContent>
              <form onSubmit={submit} className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="email">Email *</Label>
                  <Input
                    id="email"
                    type="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    placeholder="cousin@example.com"
                    required
                  />
                  {errors.email && <p className="text-sm text-red-600">{errors.email}</p>}
                </div>
                <div className="space-y-2">
                  <Label htmlFor="full_name">Full name (optional)</Label>
                  <Input
                    id="full_name"
                    value={data.full_name}
                    onChange={(e) => setData('full_name', e.target.value)}
                  />
                </div>
                <div className="space-y-2">
                  <Label>Family branch (optional)</Label>
                  <Select value={data.branch_id || undefined} onValueChange={(v) => setData('branch_id', v)}>
                    <SelectTrigger className="w-full">
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
                <div className="space-y-2">
                  <Label htmlFor="relationship_label">Relationship (optional)</Label>
                  <Input
                    id="relationship_label"
                    value={data.relationship_label}
                    onChange={(e) => setData('relationship_label', e.target.value)}
                    placeholder="Uncle, Sister, Son, Cousin…"
                  />
                </div>
                <Button type="submit" disabled={processing} className={`w-full sm:w-auto ${fr.btn}`}>
                  {processing ? 'Sending…' : 'Send invite'}
                </Button>
              </form>
            </CardContent>
          </Card>
        </FamilyReunionShell>
      </div>
    </AppLayout>
  )
}
