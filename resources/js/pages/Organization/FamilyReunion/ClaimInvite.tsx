import { Head, useForm } from '@inertiajs/react'
import FrontendLayout from '@/layouts/frontend/frontend-layout'
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
  token: string
  organization: { id: number; name: string }
  member: {
    id: number
    full_name: string
    email?: string | null
    branch_id?: number | null
    relationship_label?: string | null
    father_id?: number | null
    mother_id?: number | null
  }
  branches: { id: number; name: string }[]
  parents: { id: number; label: string }[]
}

export default function ClaimInvite({ token, organization, member, branches, parents }: Props) {
  const { data, setData, post, processing, errors } = useForm({
    full_name: member.full_name || '',
    branch_id: member.branch_id ? String(member.branch_id) : '',
    father_id: member.father_id ? String(member.father_id) : '',
    mother_id: member.mother_id ? String(member.mother_id) : '',
    relationship_label: member.relationship_label || '',
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    post(`/family/claim/${token}`)
  }

  return (
    <FrontendLayout>
      <Head title={`Join ${organization.name}`} />
      <div className="mx-auto max-w-lg px-4 py-10">
        <Card className="border-border shadow-sm">
          <CardHeader>
            <CardTitle>Join {organization.name}</CardTitle>
            <p className="text-sm text-muted-foreground">
              Confirm your name, family branch, and how you are related. You were invited as{' '}
              <strong>{member.email}</strong>.
            </p>
          </CardHeader>
          <CardContent>
            <form onSubmit={submit} className="space-y-4">
              <div className="space-y-2">
                <Label>Your full name</Label>
                <Input value={data.full_name} onChange={(e) => setData('full_name', e.target.value)} required />
                {errors.full_name && <p className="text-sm text-red-600">{errors.full_name}</p>}
              </div>
              <div className="space-y-2">
                <Label>Family branch</Label>
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
                <Label>Relationship to this family</Label>
                <Input
                  value={data.relationship_label}
                  onChange={(e) => setData('relationship_label', e.target.value)}
                  placeholder="Uncle, Sister, Son, Cousin…"
                />
              </div>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label>Father (optional)</Label>
                  <Select value={data.father_id || undefined} onValueChange={(v) => setData('father_id', v)}>
                    <SelectTrigger className="w-full">
                      <SelectValue placeholder="Select" />
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
                  <Label>Mother (optional)</Label>
                  <Select value={data.mother_id || undefined} onValueChange={(v) => setData('mother_id', v)}>
                    <SelectTrigger className="w-full">
                      <SelectValue placeholder="Select" />
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
              <Button type="submit" disabled={processing} className="w-full bg-blue-600 hover:bg-blue-700">
                {processing ? 'Saving…' : 'Accept & join family'}
              </Button>
              <p className="text-center text-xs text-muted-foreground">
                Use the email this invite was sent to when signing in.
              </p>
            </form>
          </CardContent>
        </Card>
      </div>
    </FrontendLayout>
  )
}
