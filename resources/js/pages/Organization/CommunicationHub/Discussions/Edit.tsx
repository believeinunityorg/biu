import { Head } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import DiscussionEditor from '@/components/communication-hub/DiscussionEditor'
import { type HubCategory, type HubDiscussion } from '@/components/communication-hub/types'
import { ch } from '../theme'
import type { BreadcrumbItem } from '@/types'

type Props = {
  organization: { id: number; name: string }
  discussion: HubDiscussion
  categories: HubCategory[]
}

export default function DiscussionsEdit({ organization, discussion, categories }: Props) {
  const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Communication Hub', href: route('org.communication-hub.index') },
    { title: 'Discussions', href: route('org.communication-hub.discussions.index') },
    { title: discussion.title, href: route('org.communication-hub.discussions.show', discussion.slug) },
    { title: 'Edit', href: route('org.communication-hub.discussions.edit', discussion.slug) },
  ]

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={`Edit ${discussion.title} — ${organization.name}`} />

      <div className={ch.pageNarrow}>
        <div>
          <h1 className={ch.heading}>Edit Discussion</h1>
          <p className={ch.subheading}>Update this discussion for {organization.name}</p>
        </div>

        <DiscussionEditor
          action={route('org.communication-hub.discussions.update', discussion.slug)}
          method="put"
          categories={categories}
          initial={{
            title: discussion.title,
            body: discussion.body ?? '',
            category_id: discussion.category?.id ?? null,
          }}
          submitLabel="Save Changes"
        />
      </div>
    </AppLayout>
  )
}
