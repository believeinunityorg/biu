import { Head, Link, useForm } from '@inertiajs/react'
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
  emailMismatch?: boolean
  signedInEmail?: string | null
}

export default function ClaimInvite({
  token,
  organization,
  member,
  branches,
  parents,
  emailMismatch = false,
  signedInEmail = null,
}: Props) {
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
            {emailMismatch ? (
              <div className="space-y-4">
                <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                  You are signed in as <strong>{signedInEmail}</strong>, but this invite was sent to{' '}
                  <strong>{member.email}</strong>. Sign out and sign in with the invited email to join.
                </div>
                {(errors.email || errors.full_name) && (
                  <p className="text-sm text-red-600">{errors.email || errors.full_name}</p>
                )}
                <div className="flex flex-col gap-2 sm:flex-row">
                  <Button asChild className="w-full sm:w-auto">
                    <Link href="/logout" method="post" as="button">
                      Sign out
                    </Link>
                  </Button>
                  <Button asChild variant="outline" className="w-full sm:w-auto">
                    <Link href={`/login?redirect=${encodeURIComponent(`/family/claim/${token}`)}&email=${encodeURIComponent(member.email || '')}`}>
                      Sign in with invite email
                    </Link>
                  </Button>
                </div>
              </div>
            ) : (
              <form onSubmit={submit} className="space-y-4">
                {errors.email && <p className="text-sm text-red-600">{errors.email}</p>}
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
                  {errors.branch_id && <p className="text-sm text-red-600">{errors.branch_id}</p>}
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
                    {errors.father_id && <p className="text-sm text-red-600">{errors.father_id}</p>}
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
                    {errors.mother_id && <p className="text-sm text-red-600">{errors.mother_id}</p>}
                  </div>
                </div>
                <Button type="submit" disabled={processing} className="w-full bg-blue-600 hover:bg-blue-700">
                  {processing ? 'Saving…' : 'Accept & join family'}
                </Button>
                <p className="text-center text-xs text-muted-foreground">
                  Use the email this invite was sent to when signing in.
                </p>
              </form>
            )}
          </CardContent>
        </Card>
      </div>
    </FrontendLayout>
  )
}
