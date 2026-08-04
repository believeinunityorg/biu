import { Head, useForm } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { FamilyReunionShell } from '@/components/family-reunion/FamilyReunionShell'
import { fr } from '@/pages/Organization/FamilyReunion/theme'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { FormEvent } from 'react'

type Founders = {
  grandfather_name: string
  grandmother_name: string
  grandfather_photo_url?: string | null
  grandmother_photo_url?: string | null
  grandfather_birth_year?: number | null
  grandfather_death_year?: number | null
  grandmother_birth_year?: number | null
  grandmother_death_year?: number | null
} | null

type Props = {
  organization: { id: number; name: string }
  founders: Founders
}

export default function FoundingCouple({ organization, founders }: Props) {
  const { data, setData, post, processing, errors } = useForm<{
    grandfather_name: string
    grandmother_name: string
    grandfather_birth_year: string
    grandfather_death_year: string
    grandmother_birth_year: string
    grandmother_death_year: string
    grandfather_photo: File | null
    grandmother_photo: File | null
  }>({
    grandfather_name: founders?.grandfather_name ?? '',
    grandmother_name: founders?.grandmother_name ?? '',
    grandfather_birth_year: founders?.grandfather_birth_year?.toString() ?? '',
    grandfather_death_year: founders?.grandfather_death_year?.toString() ?? '',
    grandmother_birth_year: founders?.grandmother_birth_year?.toString() ?? '',
    grandmother_death_year: founders?.grandmother_death_year?.toString() ?? '',
    grandfather_photo: null,
    grandmother_photo: null,
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    post('/organization/family-reunion/founders', { forceFormData: true })
  }

  return (
    <AppLayout
      breadcrumbs={[
        { title: 'Family Reunion', href: '/organization/family-reunion' },
        { title: 'Founding Couple', href: '/organization/family-reunion/founders' },
      ]}
    >
      <Head title="Founding Couple" />
      <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <FamilyReunionShell organizationName={organization.name}>
          <Card className="border-border shadow-sm">
            <CardHeader>
              <CardTitle>Founding Couple</CardTitle>
              <p className="text-sm text-muted-foreground">
                Add the founding grandfather and grandmother. They become the top level of the family tree.
              </p>
            </CardHeader>
            <CardContent>
              <form onSubmit={submit} className="grid gap-6 md:grid-cols-2">
                <div className="space-y-4 rounded-xl border border-border p-4">
                  <h3 className="font-medium text-foreground">Founding Grandfather</h3>
                  <div className="space-y-2">
                    <Label htmlFor="grandfather_name">Full name</Label>
                    <Input
                      id="grandfather_name"
                      value={data.grandfather_name}
                      onChange={(e) => setData('grandfather_name', e.target.value)}
                    />
                    {errors.grandfather_name && <p className="text-sm text-red-600">{errors.grandfather_name}</p>}
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-2">
                      <Label htmlFor="grandfather_birth_year">Birth year</Label>
                      <Input
                        id="grandfather_birth_year"
                        type="number"
                        value={data.grandfather_birth_year}
                        onChange={(e) => setData('grandfather_birth_year', e.target.value)}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="grandfather_death_year">Death year</Label>
                      <Input
                        id="grandfather_death_year"
                        type="number"
                        value={data.grandfather_death_year}
                        onChange={(e) => setData('grandfather_death_year', e.target.value)}
                      />
                    </div>
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="grandfather_photo">Photo (optional)</Label>
                    {founders?.grandfather_photo_url && (
                      <img src={founders.grandfather_photo_url} alt="" className="h-16 w-16 rounded-full object-cover" />
                    )}
                    <Input
                      id="grandfather_photo"
                      type="file"
                      accept="image/*"
                      onChange={(e) => setData('grandfather_photo', e.target.files?.[0] ?? null)}
                    />
                  </div>
                </div>

                <div className="space-y-4 rounded-xl border border-border p-4">
                  <h3 className="font-medium text-foreground">Founding Grandmother</h3>
                  <div className="space-y-2">
                    <Label htmlFor="grandmother_name">Full name</Label>
                    <Input
                      id="grandmother_name"
                      value={data.grandmother_name}
                      onChange={(e) => setData('grandmother_name', e.target.value)}
                    />
                    {errors.grandmother_name && <p className="text-sm text-red-600">{errors.grandmother_name}</p>}
                  </div>
                  <div className="grid grid-cols-2 gap-3">
                    <div className="space-y-2">
                      <Label htmlFor="grandmother_birth_year">Birth year</Label>
                      <Input
                        id="grandmother_birth_year"
                        type="number"
                        value={data.grandmother_birth_year}
                        onChange={(e) => setData('grandmother_birth_year', e.target.value)}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="grandmother_death_year">Death year</Label>
                      <Input
                        id="grandmother_death_year"
                        type="number"
                        value={data.grandmother_death_year}
                        onChange={(e) => setData('grandmother_death_year', e.target.value)}
                      />
                    </div>
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="grandmother_photo">Photo (optional)</Label>
                    {founders?.grandmother_photo_url && (
                      <img src={founders.grandmother_photo_url} alt="" className="h-16 w-16 rounded-full object-cover" />
                    )}
                    <Input
                      id="grandmother_photo"
                      type="file"
                      accept="image/*"
                      onChange={(e) => setData('grandmother_photo', e.target.files?.[0] ?? null)}
                    />
                  </div>
                </div>

                <div className="md:col-span-2">
                  <Button type="submit" disabled={processing} className={fr.btn}>
                    {processing ? 'Saving…' : 'Save Founding Couple'}
                  </Button>
                </div>
              </form>
            </CardContent>
          </Card>
        </FamilyReunionShell>
      </div>
    </AppLayout>
  )
}
