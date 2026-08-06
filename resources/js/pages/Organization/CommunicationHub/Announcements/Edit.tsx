import { Head } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import AnnouncementEditor from '@/components/communication-hub/AnnouncementEditor'
import { type HubAnnouncement } from '@/components/communication-hub/types'
import { ch } from '../theme'
import type { BreadcrumbItem } from '@/types'

type Props = {
  organization: { id: number; name: string }
  announcement: HubAnnouncement
  categories: string[]
}

function toDatetimeLocal(iso?: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

export default function AnnouncementsEdit({ organization, announcement, categories }: Props) {
  const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Communication Hub', href: route('org.communication-hub.index') },
    { title: 'Announcements', href: route('org.communication-hub.announcements.index') },
    { title: announcement.title, href: route('org.communication-hub.announcements.show', announcement.slug) },
    { title: 'Edit', href: route('org.communication-hub.announcements.edit', announcement.slug) },
  ]

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={`Edit ${announcement.title} — ${organization.name}`} />

      <div className={ch.pageNarrow}>
        <div>
          <h1 className={ch.heading}>Edit Announcement</h1>
          <p className={ch.subheading}>Update this announcement for {organization.name}</p>
        </div>

        <AnnouncementEditor
          action={route('org.communication-hub.announcements.update', announcement.slug)}
          method="put"
          categories={categories}
          initial={{
            title: announcement.title,
            message: announcement.message ?? '',
            category: announcement.category ?? '',
            is_pinned: announcement.is_pinned,
            allow_comments: announcement.allow_comments,
            publish_now: true,
            expires_at: toDatetimeLocal(announcement.expires_at),
          }}
          submitLabel="Save Changes"
        />
      </div>
    </AppLayout>
  )
}
