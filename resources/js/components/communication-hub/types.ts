export type HubAuthor = {
  id: number
  name: string
  avatar_url?: string | null
} | null

export type HubAnnouncement = {
  id: number
  slug: string
  title: string
  message?: string
  excerpt: string
  category?: string | null
  cover_image_url?: string | null
  attachments?: Array<{ path: string; name: string; url?: string }>
  status?: string | null
  status_label?: string | null
  is_pinned: boolean
  allow_comments: boolean
  published_at?: string | null
  scheduled_at?: string | null
  expires_at?: string | null
  comments_count?: number
  author?: HubAuthor
  badges?: string[]
  created_at?: string | null
}

export type HubCategory = {
  id: number
  name: string
  slug: string
  color?: string | null
  discussions_count?: number
}

export type HubDiscussion = {
  id: number
  slug: string
  title: string
  body?: string
  excerpt: string
  attachments?: Array<{ path: string; name: string; url?: string }>
  category?: HubCategory | null
  author?: HubAuthor
  is_pinned: boolean
  is_locked: boolean
  is_hidden?: boolean
  is_archived?: boolean
  posting_suspended?: boolean
  replies_count: number
  views_count: number
  reactions_count: number
  last_activity_at?: string | null
  approved_at?: string | null
  is_approved?: boolean
  badges?: string[]
  created_at?: string | null
  is_unread?: boolean
}

export type HubPermissions = {
  can_view_announcements?: boolean
  can_view_discussions?: boolean
  can_manage_announcements: boolean
  can_create_announcement: boolean
  can_moderate_discussions: boolean
  can_create_discussion: boolean
  can_manage_settings?: boolean
}

export type HubContext = {
  mode?: 'manage' | 'community'
  org_slug?: string | null
}

export function formatHubDate(iso?: string | null): string {
  if (!iso) return ''
  try {
    return new Date(iso).toLocaleDateString(undefined, {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    })
  } catch {
    return iso
  }
}

export function formatHubRelative(iso?: string | null): string {
  if (!iso) return ''
  try {
    const d = new Date(iso)
    const diff = Date.now() - d.getTime()
    const mins = Math.floor(diff / 60000)
    if (mins < 1) return 'just now'
    if (mins < 60) return `${mins}m ago`
    const hrs = Math.floor(mins / 60)
    if (hrs < 24) return `${hrs}h ago`
    const days = Math.floor(hrs / 24)
    if (days < 7) return `${days}d ago`
    return formatHubDate(iso)
  } catch {
    return iso
  }
}

export function authorInitials(name?: string | null): string {
  if (!name) return '?'
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase() ?? '')
    .join('')
}
