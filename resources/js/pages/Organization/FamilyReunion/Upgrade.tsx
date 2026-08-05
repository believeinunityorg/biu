import { Head, useForm } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { fr } from '@/pages/Organization/FamilyReunion/theme'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { FolderTree, GitBranch, Network, Users } from 'lucide-react'
import { FormEvent } from 'react'

type Props = {
  organization: {
    id: number
    name: string
    community_organization_type_id?: number | null
    community_organization_type_slug?: string | null
  }
}

const features = [
  { title: 'Founding Couple', desc: 'Set the grandparents at the top of your tree', icon: Users },
  { title: 'Family Branches', desc: 'Each child of the founders becomes a branch', icon: GitBranch },
  { title: 'Family Tree', desc: 'Expand, filter, and explore generations', icon: Network },
  { title: 'Directory & Claims', desc: 'Search members and claim unclaimed records by email', icon: FolderTree },
]

export default function Upgrade({ organization }: Props) {
  const { post, processing } = useForm({})

  const submit = (e: FormEvent) => {
    e.preventDefault()
    post('/organization/family-reunion/upgrade')
  }

  return (
    <AppLayout breadcrumbs={[{ title: 'Family Reunion Upgrade', href: '/organization/family-reunion/upgrade' }]}>
      <Head title="Family Reunion Upgrade" />
      <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 md:p-8">
        <Card className="overflow-hidden border-border shadow-sm">
          <div className={`${fr.banner} px-4 py-6 sm:px-6 sm:py-8`}>
            <h1 className="text-xl font-semibold sm:text-2xl">Family Reunion Upgrade</h1>
            <p className="mt-2 text-sm text-white/85">
              Enable Family Reunion tools for <span className="font-medium">{organization.name}</span>. You keep all
              existing BIU features — announcements, events, media, donations, and more.
            </p>
          </div>
          <CardHeader>
            <CardTitle className="text-lg">What you get</CardTitle>
          </CardHeader>
          <CardContent className="space-y-6">
            <div className="grid gap-3 sm:grid-cols-2">
              {features.map((feature) => {
                const Icon = feature.icon
                return (
                  <div key={feature.title} className="flex gap-3 rounded-xl border border-border p-4">
                    <div className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${fr.iconBg}`}>
                      <Icon className="h-5 w-5" />
                    </div>
                    <div>
                      <div className="text-sm font-medium text-foreground">{feature.title}</div>
                      <div className="text-xs text-muted-foreground">{feature.desc}</div>
                    </div>
                  </div>
                )
              })}
            </div>

            <form onSubmit={submit}>
              <Button type="submit" disabled={processing} className={`w-full sm:w-auto ${fr.btn}`}>
                {processing ? 'Enabling…' : 'Enable Family Reunion'}
              </Button>
            </form>
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  )
}
