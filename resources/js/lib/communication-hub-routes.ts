export type HubContext = {
  mode?: 'manage' | 'community'
  org_slug?: string | null
}

/**
 * Resolve Communication Hub / A&D routes for org-manage vs community (supporter) contexts.
 *
 * @param action e.g. "discussions.show", "announcements.comments.store"
 * @param itemSlug announcement or discussion slug when needed
 * @param replyId reply id when needed
 */
export function hubRoute(
  action: string,
  hubContext?: HubContext | null,
  itemSlug?: string | number | null,
  replyId?: string | number | null,
): string {
  const community = hubContext?.mode === 'community' && !!hubContext.org_slug
  const orgSlug = hubContext?.org_slug ?? undefined

  if (!community) {
    if (action === 'index') {
      return route('org.communication-hub.index')
    }
    if (replyId != null && itemSlug != null) {
      return route(`org.communication-hub.${action}`, [itemSlug, replyId])
    }
    if (itemSlug != null) {
      return route(`org.communication-hub.${action}`, itemSlug)
    }
    return route(`org.communication-hub.${action}`)
  }

  // Community / supporter routes under /organizations/{slug}/communication-hub
  if (action === 'index' || action === 'announcements.index') {
    return route('organizations.communication-hub', orgSlug)
  }

  const name = `organizations.communication-hub.${action}`

  if (
    action === 'discussions.index' ||
    action === 'discussions.create' ||
    action === 'discussions.store'
  ) {
    return route(name, orgSlug)
  }

  if (replyId != null && itemSlug != null) {
    return route(name, {
      slug: orgSlug,
      discussion: itemSlug,
      reply: replyId,
    })
  }

  if (action.startsWith('announcements.') && itemSlug != null) {
    return route(name, { slug: orgSlug, announcement: itemSlug })
  }

  if (action.startsWith('discussions.') && itemSlug != null) {
    return route(name, { slug: orgSlug, discussion: itemSlug })
  }

  return route(name, orgSlug)
}
