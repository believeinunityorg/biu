import { Head, useForm } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { FamilyReunionShell } from '@/components/family-reunion/FamilyReunionShell'
import { fr } from '@/pages/Organization/FamilyReunion/theme'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { FormEvent } from 'react'

type Audit = {
  id: number
  member?: string | null
  field: string
  old_value?: string | null
  new_value?: string | null
  changed_by?: string | null
  created_at?: string | null
}

type Props = {
  organization: { id: number; name: string }
  audits: Audit[]
  stats: { members: number; unclaimed: number; merged: number }
}

export default function Admin({ organization, audits, stats }: Props) {
  const { data, setData, post, processing, errors, reset } = useForm({
    keep_member_id: '',
    duplicate_member_id: '',
  })

  const submitMerge = (e: FormEvent) => {
    e.preventDefault()
    post('/organization/family-reunion/admin/merge', {
      onSuccess: () => reset(),
    })
  }

  return (
    <AppLayout
      breadcrumbs={[
        { title: 'Family Reunion', href: '/organization/family-reunion' },
        { title: 'Administration', href: '/organization/family-reunion/admin' },
      ]}
    >
      <Head title="Family Administration" />
      <div className="flex h-full min-w-0 flex-1 flex-col gap-4 p-3 sm:gap-6 sm:p-4 md:p-6">
        <FamilyReunionShell organizationName={organization.name}>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            {[
              { label: 'Visible members', value: stats.members },
              { label: 'Unclaimed records', value: stats.unclaimed },
              { label: 'Merged records', value: stats.merged },
            ].map((item) => (
              <Card key={item.label} className="border-border shadow-sm">
                <CardContent className="p-4">
                  <div className="text-2xl font-semibold text-foreground">{item.value}</div>
                  <div className="text-sm text-muted-foreground">{item.label}</div>
                </CardContent>
              </Card>
            ))}
          </div>

          <div className="grid gap-6 lg:grid-cols-2">
            <Card className="border-border shadow-sm">
              <CardHeader>
                <CardTitle>Merge Duplicate Records</CardTitle>
                <p className="text-sm text-muted-foreground">
                  Family records are never permanently deleted. Duplicates are marked merged and relationships are re-pointed.
                </p>
              </CardHeader>
              <CardContent>
                <form onSubmit={submitMerge} className="space-y-4">
                  <div className="space-y-2">
                    <Label>Keep member ID</Label>
                    <Input
                      value={data.keep_member_id}
                      onChange={(e) => setData('keep_member_id', e.target.value)}
                      placeholder="Canonical record ID"
                    />
                    {errors.keep_member_id && <p className="text-sm text-red-600">{errors.keep_member_id}</p>}
                  </div>
                  <div className="space-y-2">
                    <Label>Duplicate member ID</Label>
                    <Input
                      value={data.duplicate_member_id}
                      onChange={(e) => setData('duplicate_member_id', e.target.value)}
                      placeholder="Record to merge away"
                    />
                    {errors.duplicate_member_id && (
                      <p className="text-sm text-red-600">{errors.duplicate_member_id}</p>
                    )}
                  </div>
                  <Button type="submit" disabled={processing} className={`w-full sm:w-auto ${fr.btn}`}>
                    Merge Records
                  </Button>
                </form>
              </CardContent>
            </Card>

            <Card className="border-border shadow-sm">
              <CardHeader>
                <CardTitle>Relationship Change Log</CardTitle>
                <p className="text-sm text-muted-foreground">Recent father, mother, spouse, and branch corrections.</p>
              </CardHeader>
              <CardContent className="max-h-[420px] space-y-3 overflow-y-auto">
                {audits.length === 0 ? (
                  <p className="text-sm text-muted-foreground">No relationship changes recorded yet.</p>
                ) : (
                  audits.map((audit) => (
                    <div key={audit.id} className="rounded-lg border border-border p-3 text-sm">
                      <div className="font-medium text-foreground">{audit.member || 'Member'}</div>
                      <div className="mt-1 text-muted-foreground">
                        <span className="font-medium">{audit.field}</span>: {audit.old_value || '—'} →{' '}
                        {audit.new_value || '—'}
                      </div>
                      <div className="mt-1 text-xs text-muted-foreground">
                        {audit.changed_by || 'System'}
                        {audit.created_at ? ` · ${new Date(audit.created_at).toLocaleString()}` : ''}
                      </div>
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
