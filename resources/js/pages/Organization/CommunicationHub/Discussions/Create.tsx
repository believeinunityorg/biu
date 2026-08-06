import { Head } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import FrontendLayout from '@/layouts/frontend/frontend-layout'
import DiscussionEditor from '@/components/communication-hub/DiscussionEditor'
import { type HubCategory, type HubContext } from '@/components/communication-hub/types'
import { hubRoute } from '@/lib/communication-hub-routes'
import { ch } from '../theme'
import type { BreadcrumbItem } from '@/types'

type Props = {
  organization: { id: number; name: string; slug?: string }
  categories: HubCategory[]
  hubContext?: HubContext
}

export default function DiscussionsCreate({ organization, categories, hubContext }: Props) {
  const ctx: HubContext = hubContext ?? { mode: 'manage', org_slug: organization.slug ?? null }
  const isCommunity = ctx.mode === 'community'

  const breadcrumbs: BreadcrumbItem[] = [
    { title: 'A&D Board', href: hubRoute('index', ctx) },
    { title: 'Discussions', href: hubRoute('discussions.index', ctx) },
    { title: 'New Discussion', href: hubRoute('discussions.create', ctx) },
  ]

  const content = (
    <>
      <Head title={`New Discussion — ${organization.name}`} />

      <div className={ch.pageNarrow}>
        <div>
          <h1 className={ch.heading}>Start a Discussion</h1>
          <p className={ch.subheading}>Ask a question or share something with {organization.name}</p>
        </div>

        <DiscussionEditor
          action={hubRoute('discussions.store', ctx)}
          categories={categories}
          submitLabel="Post Discussion"
        />
      </div>
    </>
  )

  if (isCommunity) {
    return <FrontendLayout>{content}</FrontendLayout>
  }

  return <AppLayout breadcrumbs={breadcrumbs}>{content}</AppLayout>
}
