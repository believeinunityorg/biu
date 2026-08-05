import { Head } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import DiscussionEditor from '@/components/communication-hub/DiscussionEditor'
import { type HubCategory } from '@/components/communication-hub/types'
import { ch } from '../theme'
import type { BreadcrumbItem } from '@/types'

type Props = {
  organization: { id: number; name: string }
  categories: HubCategory[]
}

export default function DiscussionsCreate({ organization, categories }: Props) {
  const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Communication Hub', href: route('org.communication-hub.index') },
    { title: 'Discussions', href: route('org.communication-hub.discussions.index') },
    { title: 'New Discussion', href: route('org.communication-hub.discussions.create') },
  ]

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={`New Discussion — ${organization.name}`} />

      <div className={ch.pageNarrow}>
        <div>
          <h1 className={ch.heading}>Start a Discussion</h1>
          <p className={ch.subheading}>Ask a question or share something with {organization.name}</p>
        </div>

        <DiscussionEditor
          action={route('org.communication-hub.discussions.store')}
          categories={categories}
          submitLabel="Post Discussion"
        />
      </div>
    </AppLayout>
  )
}
