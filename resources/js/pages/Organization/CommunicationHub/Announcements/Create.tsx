import { Head } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import AnnouncementEditor from '@/components/communication-hub/AnnouncementEditor'
import { ch } from '../theme'
import type { BreadcrumbItem } from '@/types'

type Props = {
  organization: { id: number; name: string }
  categories: string[]
}

export default function AnnouncementsCreate({ organization, categories }: Props) {
  const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Communication Hub', href: route('org.communication-hub.index') },
    { title: 'Announcements', href: route('org.communication-hub.announcements.index') },
    { title: 'New Announcement', href: route('org.communication-hub.announcements.create') },
  ]

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={`New Announcement — ${organization.name}`} />

      <div className={ch.pageNarrow}>
        <div>
          <h1 className={ch.heading}>New Announcement</h1>
          <p className={ch.subheading}>Share an update with {organization.name}</p>
        </div>

        <AnnouncementEditor
          action={route('org.communication-hub.announcements.store')}
          categories={categories}
          submitLabel="Publish Announcement"
        />
      </div>
    </AppLayout>
  )
}
