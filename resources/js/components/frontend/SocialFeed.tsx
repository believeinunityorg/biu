"use client"

import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react'
import { usePage } from '@inertiajs/react'
import { Button } from '@/components/frontend/ui/button'
import { Textarea } from '@/components/frontend/ui/textarea'
import { Card, CardContent } from '@/components/frontend/ui/card'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/frontend/ui/avatar'
import {
  Image as ImageIcon,
  ThumbsUp,
  MessageCircle,
  Send,
  MoreVertical,
  Edit,
  Trash2,
  X,
  Loader2,
  Globe,
  Smile,
  Heart,
  Laugh,
  Angry,
  ChevronLeft,
  ChevronRight,
  Youtube,
  Plus,
  Share2,
} from 'lucide-react'
import { Link } from '@inertiajs/react'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/frontend/ui/dropdown-menu'
import { Input } from '@/components/frontend/ui/input'
import { motion, AnimatePresence, useReducedMotion } from 'framer-motion'
import EmojiPicker from '@/components/meeting/EmojiPicker'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
  DialogFooter,
} from '@/components/frontend/ui/dialog'
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/frontend/ui/alert-dialog'
import toast from 'react-hot-toast'
import { resolveStorageUrl } from '@/lib/storage-url'

interface User {
  id: number
  name: string
  email: string
  image?: string
}

interface Reaction {
  id: number
  user_id: number
  type: 'like' | 'love' | 'care' | 'angry' | 'haha'
  user?: User
}

interface Comment {
  id: number
  user_id: number
  user: User
  content: string
  created_at: string
}

interface Post {
  id: number
  user_id: number
  user: User
  post_type?: string | null
  content: string
  caption?: string | null
  youtube_url?: string | null
  youtube_video_id?: string | null
  youtube_embed_url?: string | null
  thumbnail_url?: string | null
  images?: string[]
  reactions: Reaction[]
  comments: Comment[]
  comments_count: number
  reactions_count: number
  created_at: string
  updated_at: string
  is_edited: boolean
  user_reaction?: Reaction
  comments_loaded?: number
  has_more_comments?: boolean
  attach_context?: { label: string; href: string | null } | null
  creator?: {
    id: number
    name: string
    slug: string
    image?: string
  }
  creator_type?: 'user' | 'organization' | 'care_alliance'
  creator_name?: string
  creator_slug?: string
  creator_image?: string
  organization_id?: number | null
  campaign_id?: number | null
  fundme_id?: number | null
}

/** Build iframe src with autoplay for modal player; mirrors server embed defaults. */
function youtubeShortEmbedSrcAutoplay(post: Post): string | null {
  let base = post.youtube_embed_url ?? null
  if (!base && post.youtube_video_id) {
    const id = encodeURIComponent(post.youtube_video_id)
    base = `https://www.youtube.com/embed/${id}?playsinline=1&modestbranding=1&rel=0`
  }
  if (!base) return null
  try {
    const u = new URL(base)
    u.searchParams.set('autoplay', '1')
    return u.toString()
  } catch {
    const sep = base.includes('?') ? '&' : '?'
    return `${base}${sep}autoplay=1`
  }
}

const stripShortPlayerSlideVariants = {
  initial: (dir: number) => ({
    y: dir >= 1 ? '100%' : '-100%',
  }),
  animate: { y: 0 },
  exit: (dir: number) => ({
    y: dir >= 1 ? '-100%' : '100%',
  }),
}

interface SocialFeedProps {
  posts?: Post[]
  next_page_url?: string | null
  has_more?: boolean
  youtubeShortAttachOptions?: {
    organizations: { id: number; name: string }[]
    campaigns: { id: number; name: string }[]
    fundmes: { id: number; title: string; slug: string | null }[]
  }
  feedReels?: Post[]
  /** Server / parent hints for auto-attaching imports (URL query params override). */
  shortImportContext?: {
    organization_id?: number | null
    campaign_id?: number | null
    fundme_id?: number | null
  } | null
}

const reactionConfig = {
  like: { emoji: '👍', icon: ThumbsUp, color: 'text-blue-600', bgColor: 'bg-blue-50 dark:bg-blue-950/20' },
  love: { emoji: '❤️', icon: Heart, color: 'text-red-500', bgColor: 'bg-red-50 dark:bg-red-950/20' },
  care: { emoji: '🤗', icon: Heart, color: 'text-yellow-500', bgColor: 'bg-yellow-50 dark:bg-yellow-950/20' },
  angry: { emoji: '😠', icon: Angry, color: 'text-orange-500', bgColor: 'bg-orange-50 dark:bg-orange-950/20' },
  haha: { emoji: '😂', icon: Laugh, color: 'text-yellow-500', bgColor: 'bg-yellow-50 dark:bg-yellow-950/20' },
}

export default function SocialFeed({
  posts: initialPosts = [],
  next_page_url,
  has_more: initialHasMore = false,
  feedReels: initialFeedReels,
  shortImportContext: shortImportContextProp,
}: SocialFeedProps) {
  const inertiaPage = usePage<{
    auth: { user: User }
    csrf_token?: string
    shortImportContext?: SocialFeedProps['shortImportContext']
  }>()
  const { auth, csrf_token, shortImportContext: shortImportContextPage } = inertiaPage.props

  const mergedShortImport = useMemo(() => {
    const server = shortImportContextProp ?? shortImportContextPage
    const pick = (v: unknown): number | undefined =>
      typeof v === 'number' && Number.isFinite(v) ? v : undefined
    let organization_id = pick(server?.organization_id)
    let campaign_id = pick(server?.campaign_id)
    let fundme_id = pick(server?.fundme_id)

    const url = inertiaPage.url || ''
    const qIdx = url.indexOf('?')
    if (qIdx >= 0) {
      const params = new URLSearchParams(url.slice(qIdx + 1))
      const gn = (key: string): number | undefined => {
        const raw = params.get(key)
        if (raw == null || raw === '') return undefined
        const n = parseInt(raw, 10)
        return Number.isFinite(n) ? n : undefined
      }
      organization_id = gn('organization_id') ?? gn('attach_organization_id') ?? organization_id
      campaign_id = gn('campaign_id') ?? gn('attach_campaign_id') ?? campaign_id
      fundme_id = gn('fundme_id') ?? gn('attach_fundme_id') ?? fundme_id
    }

    return { organization_id, campaign_id, fundme_id }
  }, [shortImportContextProp, shortImportContextPage, inertiaPage.url])

  const [reelsStrip, setReelsStrip] = useState<Post[]>(() =>
    Array.isArray(initialFeedReels) ? initialFeedReels : [],
  )

  const currentUser = auth?.user

  // Get CSRF token from props or meta tag
  const [csrfToken, setCsrfToken] = useState<string>(() => {
    if (csrf_token) return csrf_token
    const metaToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    return metaToken || ''
  })

  useEffect(() => {
    if (csrf_token && !csrfToken) {
      setCsrfToken(csrf_token)
    } else if (!csrfToken) {
      const metaToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
      if (metaToken) {
        setCsrfToken(metaToken)
      }
    }
  }, [csrf_token, csrfToken])

  useEffect(() => {
    setReelsStrip(Array.isArray(initialFeedReels) ? initialFeedReels : [])
  }, [initialFeedReels])

  const [posts, setPosts] = useState<Post[]>(
    (initialPosts || []).filter((p) => p.post_type !== 'youtube_short'),
  )
  const [loading, setLoading] = useState(false)
  const [hasMore, setHasMore] = useState(initialHasMore)
  const [nextPageUrl, setNextPageUrl] = useState(next_page_url)
  const [isPosting, setIsPosting] = useState(false)
  const [editingPost, setEditingPost] = useState<Post | null>(null)
  const [showReactionPicker, setShowReactionPicker] = useState<number | null>(null)
  const [commentInputs, setCommentInputs] = useState<Record<number, string>>({})
  const [showComments, setShowComments] = useState<Record<number, boolean>>({})
  const [showEmojiPicker, setShowEmojiPicker] = useState<number | null>(null)
  const [showPostForm, setShowPostForm] = useState(false)
  const [postContent, setPostContent] = useState('')
  const [postImages, setPostImages] = useState<File[]>([])
  const [youtubeShortModalOpen, setYoutubeShortModalOpen] = useState(false)
  const [shortYoutubeUrl, setShortYoutubeUrl] = useState('')
  const [isPostingShort, setIsPostingShort] = useState(false)
  const [editingPostImages, setEditingPostImages] = useState<Record<number, { existing: string[], new: File[] }>>({})
  const [loadingComments, setLoadingComments] = useState<Record<number, boolean>>({})
  const [seenPosts, setSeenPosts] = useState<Set<number>>(new Set())
  const [imageViewer, setImageViewer] = useState<{ images: string[], currentIndex: number } | null>(null)
  const [stripPlayingShortPost, setStripPlayingShortPost] = useState<Post | null>(null)
  const [stripTransitionDir, setStripTransitionDir] = useState<1 | -1>(1)
  const prefersReducedMotion = useReducedMotion()
  const [deleteConfirm, setDeleteConfirm] = useState<number | null>(null)
  const observerTarget = useRef<HTMLDivElement>(null)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const fileInputRefExpanded = useRef<HTMLInputElement>(null)
  const editFileInputRefs = useRef<Record<number, HTMLInputElement | null>>({})
  const textareaRef = useRef<HTMLTextAreaElement>(null)
  const postRefs = useRef<Record<number, HTMLDivElement | null>>({})

  const reelsStripRef = useRef(reelsStrip)
  const stripPlayingShortPostRef = useRef(stripPlayingShortPost)
  const stripPlayerShellRef = useRef<HTMLDivElement | null>(null)
  const stripShortOverlayRef = useRef<HTMLDivElement | null>(null)
  const stripWheelNavAtRef = useRef(0)
  const stripDragRef = useRef<{
    active: boolean
    pointerId: number
    startY: number
    startX: number
    startT: number
  } | null>(null)
  const stripSpringTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    reelsStripRef.current = reelsStrip
  }, [reelsStrip])
  useEffect(() => {
    stripPlayingShortPostRef.current = stripPlayingShortPost
  }, [stripPlayingShortPost])
  useEffect(() => {
    if (!stripPlayingShortPost) {
      stripDragRef.current = null
    }
    const el = stripPlayerShellRef.current
    if (!el || !stripPlayingShortPost) return
    el.style.transition = ''
    el.style.transform = ''
  }, [stripPlayingShortPost])

  useEffect(() => {
    if (!stripPlayingShortPost || !youtubeShortEmbedSrcAutoplay(stripPlayingShortPost)) return

    let cleaned = false
    let detachWheel: (() => void) | undefined

    const raf = window.requestAnimationFrame(() => {
      if (cleaned) return
      const el = stripShortOverlayRef.current
      if (!el) return

      const throttleMs = 340
      const minDelta = 22
      const dismissDelta = 72

      const onWheel = (e: WheelEvent) => {
        if (stripDragRef.current?.active) return
        e.preventDefault()

        const now = performance.now()
        if (now - stripWheelNavAtRef.current < throttleMs) return

        const dy = e.deltaY
        if (Math.abs(dy) < minDelta) return

        const list = reelsStripRef.current
        const cur = stripPlayingShortPostRef.current
        const idx = cur ? list.findIndex((p) => p.id === cur.id) : -1
        if (idx < 0) return

        if (dy > 0) {
          if (idx < list.length - 1) {
            stripWheelNavAtRef.current = now
            stripPlayerShellRef.current?.style.setProperty('transform', '')
            setStripTransitionDir(1)
            setStripPlayingShortPost(list[idx + 1])
          }
          return
        }

        if (idx > 0) {
          stripWheelNavAtRef.current = now
          stripPlayerShellRef.current?.style.setProperty('transform', '')
          setStripTransitionDir(-1)
          setStripPlayingShortPost(list[idx - 1])
          return
        }

        if (Math.abs(dy) >= dismissDelta) {
          stripWheelNavAtRef.current = now
          setStripPlayingShortPost(null)
        }
      }

      el.addEventListener('wheel', onWheel, { passive: false })
      detachWheel = () => el.removeEventListener('wheel', onWheel)
    })

    return () => {
      cleaned = true
      window.cancelAnimationFrame(raf)
      detachWheel?.()
    }
  }, [stripPlayingShortPost?.id])

  const clearStripSpringTimeout = () => {
    if (stripSpringTimeoutRef.current !== null) {
      window.clearTimeout(stripSpringTimeoutRef.current)
      stripSpringTimeoutRef.current = null
    }
  }

  const snapStripPlayerShellBack = () => {
    const el = stripPlayerShellRef.current
    clearStripSpringTimeout()
    if (!el) return
    const reduceMotion =
      typeof window !== 'undefined' &&
      window.matchMedia?.('(prefers-reduced-motion: reduce)')?.matches
    if (reduceMotion) {
      el.style.transition = ''
      el.style.transform = ''
      return
    }
    el.style.transition = 'transform 0.22s cubic-bezier(0.22, 1, 0.36, 1)'
    el.style.transform = 'translateY(0)'
    stripSpringTimeoutRef.current = window.setTimeout(() => {
      stripSpringTimeoutRef.current = null
      if (stripPlayerShellRef.current) stripPlayerShellRef.current.style.transition = ''
    }, 230)
  }

  const onStripShortPointerDown = (e: React.PointerEvent<HTMLDivElement>) => {
    if (stripPlayingShortPostRef.current === null) return
    if (e.pointerType === 'mouse' && e.button !== 0) return
    clearStripSpringTimeout()
    stripDragRef.current = {
      active: true,
      pointerId: e.pointerId,
      startY: e.clientY,
      startX: e.clientX,
      startT: performance.now(),
    }
    const shell = stripPlayerShellRef.current
    if (shell) shell.style.transition = 'none'
    e.currentTarget.setPointerCapture(e.pointerId)
  }

  const onStripShortPointerMove = (e: React.PointerEvent<HTMLDivElement>) => {
    const d = stripDragRef.current
    if (!d?.active || e.pointerId !== d.pointerId) return
    const dyRaw = e.clientY - d.startY
    const dxRaw = e.clientX - d.startX
    if (Math.abs(dxRaw) > Math.abs(dyRaw) + 20) return

    const list = reelsStripRef.current
    const cur = stripPlayingShortPostRef.current
    const idx = cur ? list.findIndex((p) => p.id === cur.id) : -1

    let dy = dyRaw
    if (
      (dyRaw < 0 && idx >= 0 && idx >= list.length - 1)
      || (dyRaw > 0 && idx <= 0)
    ) {
      dy = dyRaw * 0.32
    }
    stripPlayerShellRef.current?.style.setProperty('transform', `translateY(${dy}px)`)
  }

  const onStripShortPointerEnd = (e: React.PointerEvent<HTMLDivElement>) => {
    const d = stripDragRef.current
    if (!d?.active || e.pointerId !== d.pointerId) return
    stripDragRef.current = null
    try {
      e.currentTarget.releasePointerCapture(e.pointerId)
    } catch {
      /* already released */
    }

    const dy = e.clientY - d.startY
    const dx = e.clientX - d.startX
    const dt = performance.now() - d.startT

    if (Math.abs(dx) > Math.abs(dy) + 24) {
      snapStripPlayerShellBack()
      return
    }

    const velocity = dy / Math.max(dt, 1)
    const DIST = 56
    const VEL = 0.42
    const DISMISS_DY = 108
    const DISMISS_VEL = 0.62

    const list = reelsStripRef.current
    const cur = stripPlayingShortPostRef.current
    const idx = cur ? list.findIndex((p) => p.id === cur.id) : -1

    const wantNext = dy < -DIST || velocity < -VEL
    const wantPrevOrDismiss = dy > DIST || velocity > VEL

    if (wantNext && idx >= 0 && idx < list.length - 1) {
      stripPlayerShellRef.current?.style.setProperty('transform', '')
      setStripTransitionDir(1)
      setStripPlayingShortPost(list[idx + 1])
      return
    }

    if (wantPrevOrDismiss) {
      if (idx > 0) {
        stripPlayerShellRef.current?.style.setProperty('transform', '')
        setStripTransitionDir(-1)
        setStripPlayingShortPost(list[idx - 1])
        return
      }
      if (dy > DISMISS_DY || velocity > DISMISS_VEL) {
        setStripPlayingShortPost(null)
        return
      }
    }

    snapStripPlayerShellBack()
  }

  const loadMorePosts = useCallback(async () => {
    if (!nextPageUrl || loading) return

    setLoading(true)
    try {
      // Get fresh CSRF token
      const token = csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''

      // Extract path from full URL if needed
      const url = nextPageUrl.startsWith('http') ? new URL(nextPageUrl).pathname + new URL(nextPageUrl).search : nextPageUrl

      // Use fetch instead of router.get to avoid CSRF issues
      const response = await fetch(url, {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': token,
        },
        credentials: 'include',
      })

      if (response.ok) {
        const data = await response.json()
        const newPosts = (data.posts as Post[]).filter((p) => p.post_type !== 'youtube_short')
        const newHasMore = data.has_more as boolean
        const newNextPageUrl = data.next_page_url as string | null

        setPosts(prev => [...prev, ...newPosts])
        setHasMore(newHasMore)
        setNextPageUrl(newNextPageUrl)
      } else {
        const errorData = await response.json().catch(() => ({}))
        console.error('Failed to load more posts:', response.statusText, errorData)
      }
    } catch (error) {
      console.error('Error loading more posts:', error)
    } finally {
      setLoading(false)
    }
  }, [nextPageUrl, loading, csrfToken])

  // Track seen posts when they come into view
  useEffect(() => {
    const observers: IntersectionObserver[] = []

    posts.forEach((post) => {
      const element = postRefs.current[post.id]
      if (element && !seenPosts.has(post.id)) {
        const observer = new IntersectionObserver(
          (entries) => {
            if (entries[0].isIntersecting) {
              const token = csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
              fetch(`/posts/${post.id}/seen`, {
                method: 'POST',
                headers: {
                  'X-CSRF-TOKEN': token,
                  'Accept': 'application/json',
                  'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'include',
              }).catch(() => {})
              setSeenPosts(prev => new Set(prev).add(post.id))
              observer.disconnect()
            }
          },
          { threshold: 0.5 }
        )
        observer.observe(element)
        observers.push(observer)
      }
    })

    return () => {
      observers.forEach(obs => obs.disconnect())
    }
  }, [posts, seenPosts, csrfToken])

  // Infinite scroll for posts
  useEffect(() => {
    const target = observerTarget.current
    if (!target) return

    const observer = new IntersectionObserver(
      (entries) => {
        if (entries[0].isIntersecting && hasMore && !loading && nextPageUrl) {
          loadMorePosts()
        }
      },
      { threshold: 0.1 }
    )

    observer.observe(target)

    return () => {
      observer.unobserve(target)
    }
  }, [hasMore, loading, nextPageUrl, loadMorePosts])

  const handleImageSelect = (e: React.ChangeEvent<HTMLInputElement>, isEdit = false, postId?: number) => {
    const files = Array.from(e.target.files || [])
    if (!files.length) return

    // Validate image size (max 2MB per image to avoid nginx 413 error)
    // Total max: 10 images × 2MB = 20MB (within typical nginx limits)
    const MAX_SIZE = 2 * 1024 * 1024 // 2MB in bytes
    const invalidFiles = files.filter(file => file.size > MAX_SIZE)

    if (invalidFiles.length > 0) {
      const fileSizes = invalidFiles.map(f => `${f.name} (${(f.size / 1024 / 1024).toFixed(2)}MB)`).join(', ')
      toast.error(
        `The following images are too large (max 2MB per image):\n${fileSizes}\n\nPlease compress or resize your images before uploading.`,
        {
          duration: 6000,
        }
      )
      e.target.value = ''
      return
    }

    // Check total size if adding multiple images
    const totalSize = files.reduce((sum, file) => sum + file.size, 0)
    const MAX_TOTAL_SIZE = 20 * 1024 * 1024 // 20MB total for all images
    if (isEdit && postId) {
      const currentImages = editingPostImages[postId] || { existing: [], new: [] }
      const currentTotalSize = currentImages.new.reduce((sum: number, file: File) => sum + file.size, 0)
      if (currentTotalSize + totalSize > MAX_TOTAL_SIZE) {
        toast.error('Total size of all images exceeds 20MB. Please reduce the number or size of images.', {
          duration: 5000,
        })
        e.target.value = ''
        return
      }
    } else {
      const currentTotalSize = postImages.reduce((sum, file) => sum + file.size, 0)
      if (currentTotalSize + totalSize > MAX_TOTAL_SIZE) {
        toast.error('Total size of all images exceeds 20MB. Please reduce the number or size of images.', {
          duration: 5000,
        })
        e.target.value = ''
        return
      }
    }

    // Validate file types (only images)
    const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp']
    const invalidTypes = files.filter(file => !validTypes.includes(file.type))

    if (invalidTypes.length > 0) {
      const fileNames = invalidTypes.map(f => f.name).join(', ')
      toast.error(`The following files are not valid images: ${fileNames}`, {
        duration: 5000,
      })
      e.target.value = ''
      return
    }

    if (isEdit && postId) {
      const currentImages = editingPostImages[postId] || { existing: [], new: [] }
      const totalImages = currentImages.existing.length + currentImages.new.length + files.length

      if (totalImages > 10) {
        toast.error(`Maximum 10 images allowed. You can add ${10 - (currentImages.existing.length + currentImages.new.length)} more.`, {
          duration: 4000,
        })
        return
      }

      setEditingPostImages(prev => ({
        ...prev,
        [postId]: {
          existing: currentImages.existing,
          new: [...currentImages.new, ...files]
        }
      }))
    } else {
      const totalImages = postImages.length + files.length
      if (totalImages > 10) {
        toast.error(`Maximum 10 images allowed. You can add ${10 - postImages.length} more.`, {
          duration: 4000,
        })
        return
      }
      setPostImages(prev => [...prev, ...files])
    }

    // Reset input to allow selecting same file again
    e.target.value = ''
  }

  const removeImage = (index: number, isEdit = false, postId?: number) => {
    if (isEdit && postId) {
      const currentImages = editingPostImages[postId] || { existing: [], new: [] }
      // Check if removing from existing or new
      if (index < currentImages.existing.length) {
        // Remove from existing
        setEditingPostImages(prev => ({
          ...prev,
          [postId]: {
            existing: currentImages.existing.filter((_, i) => i !== index),
            new: currentImages.new
          }
        }))
      } else {
        // Remove from new
        const newIndex = index - currentImages.existing.length
        setEditingPostImages(prev => ({
          ...prev,
          [postId]: {
            existing: currentImages.existing,
            new: currentImages.new.filter((_, i) => i !== newIndex)
          }
        }))
      }
    } else {
      setPostImages(prev => prev.filter((_, i) => i !== index))
    }
  }


  const loadMoreComments = async (postId: number) => {
    const post = posts.find(p => p.id === postId)
    if (!post || loadingComments[postId]) return

    setLoadingComments(prev => ({ ...prev, [postId]: true }))
    const offset = post.comments?.length || 0

    try {
      const token = csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      const response = await fetch(`/posts/${postId}/comments?offset=${offset}&limit=10`, {
        method: 'GET',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-CSRF-TOKEN': token,
        },
        credentials: 'include',
      })

      if (response.ok) {
        const data = await response.json()
        setPosts(prev => prev.map(p => {
          if (p.id === postId) {
            return {
              ...p,
              comments: [...(p.comments || []), ...data.comments],
              has_more_comments: data.has_more,
            }
          }
          return p
        }))
      }
    } catch (error) {
      console.error('Error loading more comments:', error)
    } finally {
      setLoadingComments(prev => ({ ...prev, [postId]: false }))
    }
  }

  const handlePostSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!postContent.trim() && postImages.length === 0) return

    setIsPosting(true)
    const formData = new FormData()
    formData.append('content', postContent)
    postImages.forEach((image) => {
      formData.append('images[]', image)
    })

    try {
      const token = csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      const response = await fetch('/posts', {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': token,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
        body: formData,
      })

      if (response.ok) {
        const data = await response.json()
        setPosts(prev => [data.post, ...prev])
        setPostContent('')
        setPostImages([])
        setShowPostForm(false)
        if (fileInputRef.current) {
          fileInputRef.current.value = ''
        }
        if (fileInputRefExpanded.current) {
          fileInputRefExpanded.current.value = ''
        }
      } else {
        // Handle 413 error specifically
        if (response.status === 413) {
          toast.error('File size too large. Please reduce image sizes (max 2MB per image) or contact your administrator to increase server upload limits.', {
            duration: 6000,
          })
        } else {
          const errorData = await response.json().catch(() => ({}))
          toast.error(errorData.message || 'Failed to create post. Please try again.', {
            duration: 5000,
          })
        }
      }
    } catch (error) {
      console.error('Error creating post:', error)
      toast.error('An error occurred while creating the post. Please check your connection and try again.', {
        duration: 5000,
      })
    } finally {
      setIsPosting(false)
    }
  }

  const handleYoutubeShortSubmit = async () => {
    const trimmed = shortYoutubeUrl.trim()
    if (!trimmed) {
      toast.error('Paste a YouTube Shorts URL (youtube.com/shorts/…).')
      return
    }

    const body: Record<string, unknown> = {
      post_type: 'youtube_short',
      youtube_url: trimmed,
    }
    if (mergedShortImport.fundme_id !== undefined) {
      body.fundme_id = mergedShortImport.fundme_id
    }
    if (mergedShortImport.campaign_id !== undefined) {
      body.campaign_id = mergedShortImport.campaign_id
    }
    if (mergedShortImport.organization_id !== undefined) {
      body.organization_id = mergedShortImport.organization_id
    }

    setIsPostingShort(true)
    try {
      const token = csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      const response = await fetch('/posts', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': token,
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
        body: JSON.stringify(body),
      })

      if (response.ok) {
        const data = await response.json() as { post: Post; message?: string }
        setReelsStrip(prev => {
          const next = [data.post, ...prev.filter((p) => p.id !== data.post.id)]
          return next.slice(0, 32)
        })
        setYoutubeShortModalOpen(false)
        setShortYoutubeUrl('')
        toast.success(data.message || 'Your YouTube Short was added to Shorts.')
      } else {
        const errorData = await response.json().catch(() => ({})) as { message?: string }
        toast.error(errorData.message || 'Could not post short. Try again.', { duration: 5000 })
      }
    } catch (error) {
      console.error('Error posting YouTube short:', error)
      toast.error('Network error. Please try again.', { duration: 5000 })
    } finally {
      setIsPostingShort(false)
    }
  }

  const handleEditSubmit = async (postId: number) => {
    if (!editingPost) return

    const imageData = editingPostImages[postId] || { existing: [], new: [] }
    const hasImages = imageData.existing.length > 0 || imageData.new.length > 0
    const hasContent = editingPost.content?.trim().length > 0

    // Validate: must have either content or images
    if (!hasContent && !hasImages) {
      toast.error('Post must have either text content or at least one image.', {
        duration: 5000,
      })
      return
    }

    const formData = new FormData()
    // Only append content if it's not empty, otherwise send empty string (not null)
    formData.append('content', editingPost.content?.trim() || '')
    formData.append('_method', 'PUT')

    // Add existing images that should be kept
    imageData.existing.forEach((imageUrl) => {
      formData.append('existing_images[]', imageUrl)
    })

    // Add new images
    imageData.new.forEach((file) => {
      formData.append('images[]', file)
    })

    // Add images to remove (images that were in original but not in existing)
    const originalPost = posts.find(p => p.id === postId)
    if (originalPost?.images) {
      const imagesToRemove = originalPost.images.filter(
        img => !imageData.existing.includes(img)
      )
      imagesToRemove.forEach((imageUrl) => {
        formData.append('images_to_remove[]', imageUrl)
      })
    }

    try {
      const token = csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      const response = await fetch(`/posts/${postId}`, {
        method: 'POST',
        headers: {
          'X-CSRF-TOKEN': token,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
        body: formData,
      })

      if (response.ok) {
        const data = await response.json()
        setPosts(prev => prev.map(p => p.id === postId ? data.post : p))
        setEditingPost(null)
        setEditingPostImages(prev => {
          const newState = { ...prev }
          delete newState[postId]
          return newState
        })
        toast.success('Post updated successfully', {
          duration: 3000,
        })
      } else {
        // Handle 413 error specifically
        if (response.status === 413) {
          toast.error('File size too large. Please reduce image sizes (max 2MB per image) or contact your administrator to increase server upload limits.', {
            duration: 6000,
          })
        } else {
          const errorData = await response.json().catch(() => ({}))
          const errorMessage = errorData.message || 'Failed to update post. Please try again.'
          toast.error(errorMessage, {
            duration: 5000,
          })
        }
      }
    } catch (error) {
      console.error('Error updating post:', error)
      toast.error('An error occurred while updating the post. Please try again.', {
        duration: 5000,
      })
    }
  }

  const handleDelete = async (postId: number) => {
    setDeleteConfirm(postId)
  }

  const confirmDelete = async () => {
    if (!deleteConfirm) return

    try {
      const token = csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      const response = await fetch(`/posts/${deleteConfirm}`, {
        method: 'DELETE',
        headers: {
          'X-CSRF-TOKEN': token,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
      })

      if (response.ok) {
        setPosts(prev => prev.filter(p => p.id !== deleteConfirm))
        toast.success('Post deleted successfully', {
          duration: 3000,
        })
      } else {
        const errorData = await response.json().catch(() => ({}))
        toast.error(errorData.message || 'Failed to delete post. Please try again.', {
          duration: 5000,
        })
      }
    } catch (error) {
      console.error('Error deleting post:', error)
      toast.error('An error occurred while deleting the post. Please try again.', {
        duration: 5000,
      })
    } finally {
      setDeleteConfirm(null)
    }
  }

  const handleReaction = async (postId: number, type: 'like' | 'love' | 'care' | 'angry' | 'haha') => {
    const post = posts.find(p => p.id === postId)
    const currentReaction = post?.user_reaction

    if (currentReaction?.type === type) {
      try {
        const token = csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        const response = await fetch(`/posts/${postId}/reaction`, {
          method: 'DELETE',
          headers: {
            'X-CSRF-TOKEN': token,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'include',
        })

        if (response.ok) {
          setPosts(prev => prev.map(p => {
            if (p.id === postId) {
              const updatedReactions = p.reactions.filter(r => r.id !== currentReaction.id)
              return {
                ...p,
                reactions: updatedReactions,
                reactions_count: updatedReactions.length,
                user_reaction: undefined,
              }
            }
            return p
          }))
        }
      } catch (error) {
        console.error('Error removing reaction:', error)
      }
    } else {
      try {
        const token = csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        const response = await fetch(`/posts/${postId}/react`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          credentials: 'include',
          body: JSON.stringify({ type }),
        })

        if (response.ok) {
          const data = await response.json()
          setPosts(prev => prev.map(p => {
            if (p.id === postId) {
              const existingReaction = p.reactions.find(r => r.user_id === currentUser.id)
              const updatedReactions = existingReaction
                ? p.reactions.map(r => r.user_id === currentUser.id ? data.reaction : r)
                : [...p.reactions, data.reaction]
              return {
                ...p,
                reactions: updatedReactions,
                reactions_count: updatedReactions.length,
                user_reaction: data.reaction,
              }
            }
            return p
          }))
        }
      } catch (error) {
        console.error('Error adding reaction:', error)
      }
    }
    setShowReactionPicker(null)
  }

  const handleComment = async (postId: number) => {
    const comment = commentInputs[postId]?.trim()
    if (!comment) return

    try {
      const token = csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
      const response = await fetch(`/posts/${postId}/comment`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': token,
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
        body: JSON.stringify({ content: comment }),
      })

      if (response.ok) {
        const data = await response.json()
        setPosts(prev => prev.map(p => {
          if (p.id === postId) {
            return {
              ...p,
              comments: [...p.comments, data.comment],
              comments_count: p.comments_count + 1,
            }
          }
          return p
        }))
        setCommentInputs(prev => ({ ...prev, [postId]: '' }))
        setShowComments(prev => ({ ...prev, [postId]: true }))
      }
    } catch (error) {
      console.error('Error adding comment:', error)
    }
  }

  const formatTime = (dateString: string) => {
    const date = new Date(dateString)
    const now = new Date()
    const diff = now.getTime() - date.getTime()
    const minutes = Math.floor(diff / 60000)
    const hours = Math.floor(diff / 3600000)
    const days = Math.floor(diff / 86400000)

    if (minutes < 1) return 'Just now'
    if (minutes < 60) return `${minutes}m`
    if (hours < 24) return `${hours}h`
    if (days < 7) return `${days}d`
    return date.toLocaleDateString()
  }

  const onEmojiSelect = (emoji: { native?: string }, postId: number) => {
    const currentComment = commentInputs[postId] || ''
    setCommentInputs(prev => ({ ...prev, [postId]: currentComment + (emoji.native || '') }))
  }

  if (!currentUser) {
    return null
  }

  return (
    <div className="space-y-4 w-full">
      {/* Create Post Card - Facebook Style */}
      <Card className="bg-white dark:bg-[#111827] border-0 shadow-sm rounded-lg overflow-hidden">
        <CardContent className="p-4">
          <div className="flex items-center gap-3 pb-3 border-b border-gray-200 dark:border-gray-700">
            <Avatar className="w-10 h-10 flex-shrink-0">
              <AvatarImage src={currentUser?.image} />
              <AvatarFallback className="bg-blue-500 text-white font-semibold text-sm">
                {currentUser?.name?.charAt(0)?.toUpperCase()}
              </AvatarFallback>
            </Avatar>
            <div className="flex-1 min-w-0">
              <Input
                placeholder="What's on your mind?"
                value={postContent}
                onChange={(e) => setPostContent(e.target.value)}
                onClick={() => setShowPostForm(true)}
                className="rounded-full bg-gray-100 dark:bg-gray-700 border-0 hover:bg-gray-200 dark:hover:bg-gray-600 cursor-pointer transition-colors py-6 text-sm placeholder:text-gray-500 dark:placeholder:text-gray-400"
                readOnly
              />
            </div>
          </div>
          <div className="flex items-center justify-around gap-1 pt-3 border-t border-gray-200 dark:border-gray-700 sm:border-0">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="flex-1 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg px-2 py-2 transition-colors font-medium text-sm"
              onClick={() => {
                setShowPostForm(true)
                fileInputRef.current?.click()
              }}
            >
              <ImageIcon className="w-5 h-5 mr-1.5 text-blue-500 flex-shrink-0" />
              <span className="truncate">Photo/Video</span>
            </Button>
            <span className="text-gray-300 dark:text-gray-600 select-none" aria-hidden>|</span>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="flex-1 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 rounded-lg px-2 py-2 transition-colors font-medium text-sm"
              onClick={() => setYoutubeShortModalOpen(true)}
            >
              <Youtube className="w-5 h-5 mr-1.5 text-blue-600 dark:text-purple-400 flex-shrink-0" />
              <span className="truncate">YouTube Short</span>
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Shorts strip (horizontal tiles) */}
      <div className="w-full">
        <div className="flex gap-2.5 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden sm:gap-3">
          <button
            type="button"
            aria-label="Add your YouTube Short"
            onClick={() => setYoutubeShortModalOpen(true)}
            className="flex-shrink-0 w-[118px] text-left outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 focus-visible:ring-offset-background rounded-2xl"
          >
            <div className="relative aspect-[9/16] w-full overflow-hidden rounded-2xl bg-zinc-800 dark:bg-zinc-900">
              {currentUser?.image ? (
                <img
                  src={currentUser.image}
                  alt=""
                  className="absolute inset-0 h-full w-full object-cover"
                />
              ) : (
                <div className="absolute inset-0 bg-gradient-to-b from-zinc-600 to-zinc-900 dark:from-zinc-700 dark:to-black" />
              )}
              <div className="absolute inset-0 bg-gradient-to-t from-black/85 via-black/25 to-transparent" aria-hidden />
              <div className="absolute bottom-12 left-1/2 flex h-10 w-10 -translate-x-1/2 items-center justify-center rounded-full bg-gradient-to-r from-blue-600 to-purple-600 text-white shadow-lg ring-2 ring-black/10">
                <Plus className="h-5 w-5" strokeWidth={2.5} aria-hidden />
              </div>
              <div className="absolute bottom-2 left-2 right-2 text-center">
                <span className="text-[13px] font-semibold leading-tight text-white drop-shadow-sm">
                  Create Short
                </span>
              </div>
            </div>
          </button>

          {reelsStrip.map((reelPost) => {
            const vid = reelPost.youtube_video_id ?? ''
            const thumb =
              reelPost.thumbnail_url ||
              (vid ? `https://img.youtube.com/vi/${vid}/mqdefault.jpg` : '')
            const creator = reelPost.creator_name || reelPost.user?.name || 'Creator'
            const avatarSrc = resolveStorageUrl(
              reelPost.creator_image || reelPost.creator?.image || reelPost.user?.image,
            )
            return (
              <button
                key={reelPost.id}
                type="button"
                aria-label={`Open Short by ${creator}`}
                onClick={() => {
                  const src = youtubeShortEmbedSrcAutoplay(reelPost)
                  if (!src) {
                    toast.error('This Short cannot be played here.')
                    return
                  }
                  setStripPlayingShortPost(reelPost)
                }}
                className="flex-shrink-0 w-[118px] text-left outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2 focus-visible:ring-offset-background rounded-2xl"
              >
                <div className="relative aspect-[9/16] w-full overflow-hidden rounded-2xl bg-zinc-100 ring-1 ring-blue-600/10 dark:bg-zinc-900 dark:ring-purple-400/15">
                  {thumb ? (
                    <img
                      src={thumb}
                      alt=""
                      className="absolute inset-0 h-full w-full object-cover"
                    />
                  ) : (
                    <div className="absolute inset-0 flex items-center justify-center bg-zinc-200 dark:bg-zinc-800">
                      <Youtube className="h-10 w-10 text-blue-600 dark:text-purple-400" aria-hidden />
                    </div>
                  )}
                  <div
                    className="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/90 via-transparent to-black/35"
                    aria-hidden
                  />
                  <div className="absolute left-2 top-2 z-10">
                    {avatarSrc ? (
                      <img
                        src={avatarSrc}
                        alt=""
                        className="h-8 w-8 rounded-full object-cover shadow-md ring-2 ring-white/50"
                      />
                    ) : (
                      <div className="flex h-8 w-8 items-center justify-center rounded-full bg-gradient-to-r from-blue-600 to-purple-600 text-xs font-bold text-white shadow-md ring-2 ring-white/50">
                        {creator.trim().charAt(0)?.toUpperCase() || '?'}
                      </div>
                    )}
                  </div>
                  <div className="absolute bottom-0 left-0 right-0 px-2 pb-2 pt-10">
                    <p className="whitespace-normal break-words text-left text-[11px] font-semibold leading-snug text-white drop-shadow-[0_1px_2px_rgba(0,0,0,0.8)]">
                      {creator}
                    </p>
                  </div>
                </div>
              </button>
            )
          })}
        </div>
      </div>

      {/* Expanded Post Form */}
      <AnimatePresence>
        {(showPostForm || postContent || postImages.length > 0) && (
          <motion.div
            initial={{ opacity: 0, y: -20, scale: 0.95 }}
            animate={{ opacity: 1, y: 0, scale: 1 }}
            exit={{ opacity: 0, y: -20, scale: 0.95 }}
            transition={{ duration: 0.2, ease: "easeOut" }}
          >
            <Card className="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-lg rounded-lg overflow-hidden">
              <CardContent className="p-4">
                <form onSubmit={handlePostSubmit} className="space-y-4">
                  <div className="flex items-start gap-3">
                    <Avatar className="w-10 h-10 flex-shrink-0">
                      <AvatarImage src={currentUser?.image} />
                      <AvatarFallback className="bg-blue-500 text-white font-semibold text-sm">
                        {currentUser?.name?.charAt(0)?.toUpperCase()}
                      </AvatarFallback>
                    </Avatar>
                    <div className="flex-1 min-w-0">
                      <Textarea
                        ref={textareaRef}
                        placeholder="What's on your mind?"
                        value={postContent}
                        onChange={(e) => setPostContent(e.target.value)}
                        className="min-h-[120px] resize-none border-0 bg-transparent text-base rounded-lg focus-visible:ring-0 placeholder:text-gray-500 dark:placeholder:text-gray-400 text-gray-900 dark:text-gray-100"
                        autoFocus
                      />
                      {postImages.length > 0 && (
                        <motion.div
                          initial={{ opacity: 0, height: 0 }}
                          animate={{ opacity: 1, height: 'auto' }}
                          className="mt-4"
                        >
                          <div className={`grid gap-3 ${
                            postImages.length === 1 ? 'grid-cols-1' :
                            postImages.length === 2 ? 'grid-cols-2' :
                            postImages.length >= 3 ? 'grid-cols-2' : 'grid-cols-2'
                          }`}>
                            {postImages.map((file, idx) => (
                              <motion.div
                                key={idx}
                                initial={{ opacity: 0, scale: 0.8 }}
                                animate={{ opacity: 1, scale: 1 }}
                                transition={{ delay: idx * 0.05 }}
                                className="relative group rounded-xl overflow-hidden border-2 border-gray-200 dark:border-gray-700 shadow-md hover:shadow-lg transition-all duration-200"
                              >
                                <div className="aspect-video overflow-hidden bg-gray-100 dark:bg-gray-800">
                                  <img
                                    src={URL.createObjectURL(file)}
                                    alt={`Preview ${idx + 1}`}
                                    className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                                  />
                                </div>
                                <button
                                  type="button"
                                  onClick={() => removeImage(idx)}
                                  className="absolute top-2 right-2 bg-red-500/90 hover:bg-red-600 text-white rounded-full p-2 shadow-lg opacity-0 group-hover:opacity-100 transition-all duration-200 z-10 hover:scale-110"
                                >
                                  <X className="w-4 h-4" />
                                </button>
                                <div className="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/60 to-transparent p-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                  <p className="text-white text-xs font-medium truncate">{file.name}</p>
                                </div>
                              </motion.div>
                            ))}
                          </div>
                          <div className="mt-3 flex items-center justify-between px-1">
                            <p className="text-xs font-medium text-gray-600 dark:text-gray-400">
                              <span className="text-primary font-semibold">{postImages.length}</span> photo{postImages.length > 1 ? 's' : ''} selected
                            </p>
                            {postImages.length < 10 && (
                              <p className="text-xs text-gray-500 dark:text-gray-500">
                                {10 - postImages.length} more can be added
                              </p>
                            )}
                            {postImages.length >= 10 && (
                              <p className="text-xs text-red-500 font-medium">
                                Maximum reached
                              </p>
                            )}
                          </div>
                        </motion.div>
                      )}
                    </div>
                  </div>
                  <div className="flex items-center justify-between pt-4 border-t border-gray-200 dark:border-gray-700">
                    <div className="flex items-center gap-2">
                      <input
                        ref={fileInputRefExpanded}
                        type="file"
                        accept="image/*"
                        onChange={(e) => handleImageSelect(e, false)}
                        className="hidden"
                      />
                       <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-gray-600 dark:text-gray-400 hover:text-primary hover:bg-primary/10 dark:hover:bg-primary/20 rounded-xl px-4 py-2 transition-all duration-200 font-medium"
                        onClick={() => {
                          if (postImages.length < 1) {
                            fileInputRefExpanded.current?.click()
                          } else {
                            toast.error('Maximum 1 images allowed', {
                              duration: 4000,
                            })
                          }
                        }}
                        disabled={postImages.length >= 1}
                      >
                        <ImageIcon className="w-5 h-5 mr-2" />
                        {postImages.length > 0 ? 'Add More' : 'Add Photo'}
                        {postImages.length > 0 && postImages.length < 1 && (
                          <span className="ml-1 text-xs">({1 - postImages.length})</span>
                        )}
                      </Button>
                    </div>
                    <div className="flex items-center gap-3">
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                          setPostContent('')
                          setPostImages([])
                          setShowPostForm(false)
                          if (fileInputRef.current) {
                            fileInputRef.current.value = ''
                          }
                          if (fileInputRefExpanded.current) {
                            fileInputRefExpanded.current.value = ''
                          }
                        }}
                        className="rounded-xl px-4 hover:bg-gray-100 dark:hover:bg-gray-700"
                      >
                        Cancel
                      </Button>
                      <Button
                        type="submit"
                        disabled={isPosting || (!postContent.trim() && postImages.length === 0)}
                        className="bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 text-white rounded-xl px-6 py-2 shadow-lg hover:shadow-xl disabled:opacity-50 disabled:cursor-not-allowed transition-all duration-200 font-semibold"
                      >
                        {isPosting ? (
                          <>
                            <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                            Posting...
                          </>
                        ) : (
                          <>
                            <Send className="w-4 h-4 mr-2" />
                            Post
                          </>
                        )}
                      </Button>
                    </div>
                  </div>
                </form>
              </CardContent>
            </Card>
          </motion.div>
        )}
      </AnimatePresence>

      {/* Posts Feed */}
      {posts.length === 0 && !loading && (
        <Card className="bg-white dark:bg-[#111827] border-0 shadow-sm rounded-lg">
          <CardContent className="p-12 text-center">
            <p className="text-gray-500 dark:text-gray-400">No posts yet. Be the first to share something!</p>
          </CardContent>
        </Card>
      )}

      {posts.map((post) => (
        <motion.div
          key={post.id}
          id={`feed-post-${post.id}`}
          ref={(el) => { postRefs.current[post.id] = el }}
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.3 }}
        >
          <Card className="bg-white dark:bg-[#111827] border-0 shadow-sm rounded-lg overflow-hidden">
            <CardContent className="p-4">
              {/* Post Header */}
              <div className="flex items-start justify-between mb-3">
                <div className="flex items-start gap-3 flex-1 min-w-0">
                  <Avatar className="w-10 h-10 flex-shrink-0">
                    <AvatarImage src={post.creator_image || post.user?.image} />
                    <AvatarFallback className="bg-blue-500 text-white font-semibold text-sm">
                      {(post.creator_name || post.user?.name)?.charAt(0)?.toUpperCase()}
                    </AvatarFallback>
                  </Avatar>
                  <div className="flex-1 min-w-0">
                    {post.creator_slug ? (
                      <Link
                        href={
                          post.creator_type === 'care_alliance'
                            ? route('alliances.show', post.creator_slug)
                            : post.creator_type === 'organization'
                              ? route('organizations.show', post.creator_slug)
                              : route('users.show', post.creator_slug)
                        }
                        className="font-semibold text-sm hover:underline text-gray-900 dark:text-white mb-0.5 block"
                      >
                        {post.creator_name || post.user?.name}
                      </Link>
                    ) : (
                      <h3 className="font-semibold text-sm text-gray-900 dark:text-white mb-0.5">
                        {post.creator_name || post.user?.name}
                      </h3>
                    )}
                    {post.post_type === 'youtube_short' ? (
                      <p className="text-xs text-gray-600 dark:text-gray-400 mt-0.5">
                        shared a YouTube Short
                      </p>
                    ) : post.post_type === 'youtube_video' ? (
                      <p className="text-xs text-gray-600 dark:text-gray-400 mt-0.5">
                        shared a video
                      </p>
                    ) : null}
                    <div className="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                      <span>{formatTime(post.created_at)}</span>
                      {post.is_edited && (
                        <>
                          <span>·</span>
                          <span className="text-gray-400">Edited</span>
                        </>
                      )}
                      <span>·</span>
                      <Globe className="w-3 h-3" />
                    </div>
                  </div>
                </div>
                {post.user_id === currentUser?.id && (
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="ghost" size="sm" className="h-8 w-8 p-0 flex-shrink-0 hover:bg-gray-100 dark:hover:bg-[#3a3b3c] rounded-full">
                        <MoreVertical className="w-4 h-4 text-gray-500 dark:text-gray-400" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
                      <DropdownMenuItem
                        onClick={() => {
                          if (post.post_type === 'youtube_short' || post.post_type === 'youtube_video') {
                            return
                          }
                          setEditingPost({ ...post, content: post.content })
                          setEditingPostImages(prev => ({
                            ...prev,
                            [post.id]: {
                              existing: post.images || [],
                              new: []
                            }
                          }))
                        }}
                        disabled={post.post_type === 'youtube_short' || post.post_type === 'youtube_video'}
                        className="hover:bg-gray-100 dark:hover:bg-gray-700 disabled:pointer-events-none disabled:opacity-40"
                      >
                        <Edit className="w-4 h-4 mr-2" />
                        Edit
                      </DropdownMenuItem>
                      <DropdownMenuItem
                        onClick={() => handleDelete(post.id)}
                        className="text-red-500 focus:text-red-500 hover:bg-red-50 dark:hover:bg-red-950/20"
                      >
                        <Trash2 className="w-4 h-4 mr-2" />
                        Delete
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                )}
              </div>

              {/* Post Content */}
              {editingPost?.id === post.id ? (
                <div className="space-y-4 mb-4">
                  <Textarea
                    value={editingPost.content}
                    onChange={(e) => setEditingPost({ ...editingPost, content: e.target.value })}
                    className="min-h-[100px] resize-none"
                  />

                  {/* Existing Images */}
                  {(() => {
                    const imageData = editingPostImages[post.id] || {
                      existing: post.images || [],
                      new: []
                    }
                    const allImages = [...imageData.existing, ...imageData.new.map(f => URL.createObjectURL(f))]
                    const totalImages = imageData.existing.length + imageData.new.length

                    return (
                      <>
                        {allImages.length > 0 && (
                          <div className="grid grid-cols-2 gap-2">
                            {imageData.existing.map((imageUrl, idx) => (
                              <div key={`existing-${idx}`} className="relative group rounded-lg overflow-hidden">
                                <img
                                  src={imageUrl}
                                  alt={`Existing ${idx + 1}`}
                                  className="w-full h-32 object-cover rounded-lg"
                                />
                                <button
                                  type="button"
                                  onClick={() => removeImage(idx, true, post.id)}
                                  className="absolute top-2 right-2 bg-black/70 hover:bg-black/90 text-white rounded-full p-1.5 opacity-100 z-10"
                                >
                                  <X className="w-4 h-4" />
                                </button>
                              </div>
                            ))}
                            {imageData.new.map((file, idx) => (
                              <div key={`new-${idx}`} className="relative group rounded-lg overflow-hidden">
                                <img
                                  src={URL.createObjectURL(file)}
                                  alt={`New ${idx + 1}`}
                                  className="w-full h-32 object-cover rounded-lg"
                                />
                                <button
                                  type="button"
                                  onClick={() => removeImage(imageData.existing.length + idx, true, post.id)}
                                  className="absolute top-2 right-2 bg-black/70 hover:bg-black/90 text-white rounded-full p-1.5 opacity-100 z-10"
                                >
                                  <X className="w-4 h-4" />
                                </button>
                              </div>
                            ))}
                          </div>
                        )}
                        {totalImages < 1 && (
                          <div className="flex items-center gap-2">
                            <input
                              ref={(el) => { editFileInputRefs.current[post.id] = el }}
                              type="file"
                              accept="image/*"
                              onChange={(e) => {
                                handleImageSelect(e, true, post.id)
                                e.target.value = '' // Reset to allow selecting same files again
                              }}
                              className="hidden"
                            />
                            <Button
                              type="button"
                              variant="outline"
                              size="sm"
                              onClick={() => {
                                if (totalImages < 1) {
                                  editFileInputRefs.current[post.id]?.click()
                                } else {
                                  toast.error('Maximum 1 images allowed', {
                              duration: 4000,
                            })
                                }
                              }}
                              className="text-gray-600 dark:text-gray-400 hover:text-green-600 dark:hover:text-green-400"
                            >
                              <ImageIcon className="w-4 h-4 mr-2" />
                              {allImages.length > 0 ? 'Add More Photos' : 'Add Photos'} ({1 - totalImages} remaining)
                            </Button>
                          </div>
                        )}
                        {totalImages >= 1 && (
                          <p className="text-xs text-gray-500 dark:text-gray-400">
                            Maximum 1 photos reached. Remove some to add more.
                          </p>
                        )}
                      </>
                    )
                  })()}

                  <div className="flex items-center gap-2">
                    <Button
                      onClick={() => handleEditSubmit(post.id)}
                      size="sm"
                      className="bg-blue-600 hover:bg-blue-700"
                    >
                      Save
                    </Button>
                    <Button
                      onClick={() => {
                        setEditingPost(null)
                        setEditingPostImages(prev => {
                          const newState = { ...prev }
                          delete newState[post.id]
                          return newState
                        })
                      }}
                      variant="outline"
                      size="sm"
                    >
                      Cancel
                    </Button>
                  </div>
                </div>
              ) : (
                <>
                  {(post.post_type === 'youtube_short' || post.post_type === 'youtube_video' ? (post.content || post.caption) : post.content) ? (
                    <p className="text-sm mb-3 whitespace-pre-wrap break-words text-gray-900 dark:text-gray-100 leading-relaxed">
                      {post.post_type === 'youtube_short' || post.post_type === 'youtube_video' ? (post.content || post.caption || '') : post.content}
                    </p>
                  ) : null}

                  {post.attach_context?.label ? (
                    <div className="mb-3">
                      {post.attach_context.href ? (
                        <Link
                          href={post.attach_context.href}
                          className="inline-flex rounded-full bg-violet-100 px-3 py-1 text-xs font-medium text-violet-800 hover:bg-violet-200 dark:bg-violet-950/60 dark:text-violet-200 dark:hover:bg-violet-900/80"
                        >
                          {post.attach_context.label}
                        </Link>
                      ) : (
                        <span className="inline-flex rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                          {post.attach_context.label}
                        </span>
                      )}
                    </div>
                  ) : null}

                  {(post.post_type === 'youtube_short' || post.post_type === 'youtube_video') && post.youtube_embed_url ? (
                    post.post_type === 'youtube_short' ? (
                      <div className="mb-3 flex w-full justify-center">
                        <div className="short-player relative aspect-[9/16] w-full max-w-[420px] overflow-hidden rounded-[18px] bg-black shadow-lg ring-1 ring-black/10 dark:ring-white/15">
                          <iframe
                            title="YouTube Short"
                            src={post.youtube_embed_url}
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            referrerPolicy="strict-origin-when-cross-origin"
                            allowFullScreen
                            loading="lazy"
                            className="absolute inset-0 h-full w-full rounded-[18px]"
                          />
                        </div>
                      </div>
                    ) : (
                      <div className="mb-3 flex w-full justify-center">
                        <div className="video-player relative aspect-video w-full max-w-4xl overflow-hidden rounded-xl bg-black shadow-lg ring-1 ring-black/10 dark:ring-white/15">
                          <iframe
                            title="YouTube video"
                            src={post.youtube_embed_url}
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                            referrerPolicy="strict-origin-when-cross-origin"
                            allowFullScreen
                            loading="lazy"
                            className="absolute inset-0 h-full w-full rounded-xl"
                          />
                        </div>
                      </div>
                    )
                  ) : null}

                  {(!post.post_type || (post.post_type !== 'youtube_short' && post.post_type !== 'youtube_video')) && post.images && post.images.length > 0 && (
                    <div className={`grid gap-2 mb-3 rounded-lg overflow-hidden ${
                      post.images.length === 1 ? 'grid-cols-1' :
                      post.images.length === 2 ? 'grid-cols-2' :
                      'grid-cols-2'
                    }`}>
                      {post.images.map((image, idx) => (
                        <div
                          key={idx}
                          className="relative group rounded-lg overflow-hidden cursor-pointer bg-gray-100 dark:bg-gray-700"
                          onClick={() => setImageViewer({ images: post.images || [], currentIndex: idx })}
                        >
                          <img
                            src={image}
                            alt={`Post image ${idx + 1}`}
                            className="w-full h-full object-cover"
                            style={{
                              maxHeight: (post.images?.length || 0) === 1 ? '500px' : '300px',
                              minHeight: (post.images?.length || 0) === 1 ? '300px' : '200px'
                            }}
                          />
                        </div>
                      ))}
                    </div>
                  )}
                </>
              )}

              {/* Reactions Summary */}
              {(post.reactions_count > 0 || post.comments_count > 0) && (
                <div className="flex items-center justify-between mb-2 text-xs text-gray-500 dark:text-gray-400 pt-2 border-t border-gray-200 dark:border-gray-700">
                  {post.reactions_count > 0 && (
                    <div className="flex items-center gap-1.5 cursor-pointer hover:underline">
                      <div className="flex items-center -space-x-1">
                        {Object.entries(
                          post.reactions.reduce((acc, r) => {
                            acc[r.type] = (acc[r.type] || 0) + 1
                            return acc
                          }, {} as Record<string, number>)
                        )
                          .slice(0, 3)
                          .map(([type]) => (
                            <span key={type} className="text-sm bg-white dark:bg-[#242526] rounded-full border border-gray-200 dark:border-gray-700 p-0.5">
                              {reactionConfig[type as keyof typeof reactionConfig].emoji}
                            </span>
                          ))}
                      </div>
                      <span>{post.reactions_count}</span>
                    </div>
                  )}
                  {post.comments_count > 0 && (
                    <button
                      onClick={() => setShowComments(prev => ({ ...prev, [post.id]: true }))}
                      className="hover:underline"
                    >
                      {post.comments_count} {post.comments_count === 1 ? 'comment' : 'comments'}
                    </button>
                  )}
                </div>
              )}

              {/* Actions */}
              <div className="flex items-center justify-between pt-2 border-t border-gray-200 dark:border-gray-700">
                <div
                  className="relative flex-1"
                  onMouseEnter={() => setShowReactionPicker(post.id)}
                  onMouseLeave={() => setShowReactionPicker(null)}
                >
                  <Button
                    variant="ghost"
                    size="sm"
                    className={`w-full justify-center text-gray-600 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-[#3a3b3c] rounded-lg transition-colors font-medium text-sm ${
                      post.user_reaction ? reactionConfig[post.user_reaction.type].color : ''
                    }`}
                    onClick={() => {
                      if (post.user_reaction) {
                        handleReaction(post.id, post.user_reaction.type)
                      } else {
                        handleReaction(post.id, 'like')
                      }
                    }}
                  >
                    {post.user_reaction ? (
                      <>
                        <span className="text-base mr-1.5">{reactionConfig[post.user_reaction.type].emoji}</span>
                        <span className="capitalize font-semibold">{post.user_reaction.type}</span>
                      </>
                    ) : (
                      <>
                        <ThumbsUp className="w-5 h-5 mr-1.5" />
                        <span>Like</span>
                      </>
                    )}
                  </Button>
                  <AnimatePresence>
                    {showReactionPicker === post.id && (
                      <motion.div
                        initial={{ opacity: 0, y: 10, scale: 0.8 }}
                        animate={{ opacity: 1, y: 0, scale: 1 }}
                        exit={{ opacity: 0, y: 10, scale: 0.8 }}
                        transition={{ type: "spring", stiffness: 500, damping: 30 }}
                        className="absolute bottom-full left-0 mb-3 flex gap-0.5 bg-white dark:bg-gray-800 p-1.5 rounded-full shadow-2xl border border-gray-200 dark:border-gray-700 z-50"
                      >
                        {(['like', 'love', 'care', 'angry', 'haha'] as const).map((type, index) => {
                          const config = reactionConfig[type]
                          return (
                            <motion.button
                              key={type}
                              initial={{ scale: 0, rotate: -180 }}
                              animate={{ scale: 1, rotate: 0 }}
                              transition={{ delay: index * 0.05, type: "spring", stiffness: 500, damping: 30 }}
                              whileHover={{ scale: 1.3, y: -5 }}
                              whileTap={{ scale: 0.9 }}
                              onClick={() => handleReaction(post.id, type)}
                              className={`text-3xl transition-all p-2 rounded-full hover:bg-gray-100 dark:hover:bg-gray-700 ${config.color} cursor-pointer`}
                              title={type.charAt(0).toUpperCase() + type.slice(1)}
                            >
                              {config.emoji}
                            </motion.button>
                          )
                        })}
                      </motion.div>
                    )}
                  </AnimatePresence>
                </div>
                <Button
                  variant="ghost"
                  size="sm"
                  className="flex-1 justify-center text-gray-600 dark:text-gray-400 hover:bg-primary/10 dark:hover:bg-primary/20 rounded-xl transition-all duration-200 font-medium"
                  onClick={() => setShowComments(prev => ({ ...prev, [post.id]: !prev[post.id] }))}
                >
                  <MessageCircle className="w-5 h-5 mr-2" />
                  Comment
                </Button>
                {(post.post_type === 'youtube_short' || post.post_type === 'youtube_video') && (
                  <Button
                    variant="ghost"
                    size="sm"
                    type="button"
                    className="flex-1 justify-center text-gray-600 dark:text-gray-400 hover:bg-primary/10 dark:hover:bg-primary/20 rounded-xl transition-all duration-200 font-medium"
                    onClick={() => {
                      const url = `${typeof window !== 'undefined' ? window.location.origin : ''}/social-feed#feed-post-${post.id}`
                      if (typeof navigator !== 'undefined' && navigator.share) {
                        navigator.share({ title: document.title, url }).catch(() => {
                          void navigator.clipboard.writeText(url)
                          toast.success('Link copied')
                        })
                      } else {
                        void navigator.clipboard.writeText(url)
                        toast.success('Link copied')
                      }
                    }}
                  >
                    <Share2 className="w-5 h-5 mr-2" />
                    Share
                  </Button>
                )}
              </div>

              {(post.post_type === 'youtube_short' || post.post_type === 'youtube_video') && post.attach_context?.href ? (
                <div className="flex flex-wrap gap-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                  <Link
                    href={post.attach_context.href}
                    className={`inline-flex flex-1 min-w-[140px] justify-center rounded-lg border px-3 py-2 text-xs font-semibold transition-colors ${
                      post.fundme_id
                        ? 'border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200 hover:bg-emerald-500/20'
                        : 'border-violet-500/40 bg-violet-500/10 text-violet-800 dark:text-violet-200 hover:bg-violet-500/20'
                    }`}
                  >
                    {post.fundme_id ? 'Donate' : 'Support organization'}
                  </Link>
                </div>
              ) : null}

              {/* Comments Section */}
              <AnimatePresence>
                {showComments[post.id] && (
                  <motion.div
                    initial={{ opacity: 0, height: 0 }}
                    animate={{ opacity: 1, height: 'auto' }}
                    exit={{ opacity: 0, height: 0 }}
                    className="mt-3 space-y-2 pt-3 border-t border-gray-200 dark:border-gray-700"
                  >
                    {post.comments && post.comments.length > 0 && (
                      <div className="space-y-2">
                        <div className="space-y-2 max-h-96 overflow-y-auto">
                          {post.comments.map((comment) => (
                            <div key={comment.id} className="flex items-start gap-2">
                              <Avatar className="w-8 h-8 flex-shrink-0">
                                <AvatarImage src={comment.user.image} />
                                <AvatarFallback className="text-xs bg-blue-500 text-white">
                                  {comment.user.name?.charAt(0)?.toUpperCase()}
                                </AvatarFallback>
                              </Avatar>
                              <div className="flex-1 min-w-0">
                                <div className="bg-gray-100 dark:bg-[#3a3b3c] rounded-2xl rounded-tl-sm px-3 py-2">
                                  <div className="flex items-center gap-2 mb-0.5">
                                    <span className="font-semibold text-sm hover:underline cursor-pointer text-gray-900 dark:text-white">
                                      {comment.user.name}
                                    </span>
                                  </div>
                                  <p className="text-sm whitespace-pre-wrap break-words text-gray-800 dark:text-gray-200">{comment.content}</p>
                                  <span className="text-xs text-gray-500 dark:text-gray-400 mt-1 block">
                                    {formatTime(comment.created_at)}
                                  </span>
                                </div>
                              </div>
                            </div>
                          ))}
                        </div>
                        {post.has_more_comments && (
                          <Button
                            variant="ghost"
                            size="sm"
                            className="w-full text-blue-600 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-950/20"
                            onClick={() => loadMoreComments(post.id)}
                            disabled={loadingComments[post.id]}
                          >
                            {loadingComments[post.id] ? (
                              <>
                                <Loader2 className="w-4 h-4 mr-2 animate-spin" />
                                Loading...
                              </>
                            ) : (
                              `Load more comments (${post.comments_count - (post.comments?.length || 0)} more)`
                            )}
                          </Button>
                        )}
                      </div>
                    )}
                    <div className="flex items-start gap-2 pt-2 relative">
                      <Avatar className="w-8 h-8 flex-shrink-0">
                        <AvatarImage src={currentUser?.image} />
                        <AvatarFallback className="text-xs bg-blue-500 text-white">
                          {currentUser?.name?.charAt(0)?.toUpperCase()}
                        </AvatarFallback>
                      </Avatar>
                      <div className="flex-1 flex gap-2 relative">
                        <Input
                          placeholder="Write a comment..."
                          value={commentInputs[post.id] || ''}
                          onChange={(e) => setCommentInputs(prev => ({ ...prev, [post.id]: e.target.value }))}
                          onKeyPress={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey && commentInputs[post.id]?.trim()) {
                              e.preventDefault()
                              handleComment(post.id)
                            }
                          }}
                          className="pr-20 rounded-full bg-gray-100 dark:bg-gray-700 border-0 focus-visible:ring-0 text-sm placeholder:text-gray-500 dark:placeholder:text-gray-400"
                        />
                        <div className="absolute right-2 top-1/2 -translate-y-1/2 flex items-center gap-1">
                          <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 w-7 p-0 rounded-full hover:bg-gray-200 dark:hover:bg-gray-600"
                            onClick={() => setShowEmojiPicker(prev => prev === post.id ? null : post.id)}
                          >
                            <Smile className="w-4 h-4 text-gray-500 dark:text-gray-400" />
                          </Button>
                          {commentInputs[post.id]?.trim() && (
                            <Button
                              onClick={() => handleComment(post.id)}
                              size="sm"
                              className="h-7 w-7 p-0 rounded-full bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700"
                            >
                              <Send className="w-3.5 h-3.5 text-white" />
                            </Button>
                          )}
                        </div>
                        {showEmojiPicker === post.id && (
                          <div className="absolute bottom-full right-0 mb-2 z-50">
                            <EmojiPicker
                              onEmojiSelect={(emoji) => onEmojiSelect(emoji, post.id)}
                              theme={document.documentElement.classList.contains('dark') ? 'dark' : 'light'}
                            />
                          </div>
                        )}
                      </div>
                    </div>
                  </motion.div>
                )}
              </AnimatePresence>
            </CardContent>
          </Card>
        </motion.div>
      ))}

      {/* Loading */}
      {loading && (
        <Card className="bg-white dark:bg-[#111827] border-0 shadow-sm rounded-lg">
          <CardContent className="p-4">
            <div className="flex items-start gap-3">
              <div className="w-10 h-10 rounded-full bg-gray-200 dark:bg-gray-700 animate-pulse" />
              <div className="flex-1 space-y-2">
                <div className="h-3 bg-gray-200 dark:bg-gray-700 rounded w-1/4 animate-pulse" />
                <div className="h-3 bg-gray-200 dark:bg-gray-700 rounded w-full animate-pulse" />
                <div className="h-3 bg-gray-200 dark:bg-gray-700 rounded w-3/4 animate-pulse" />
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      <div ref={observerTarget} className="h-10" />

      {!hasMore && posts.length > 0 && (
        <Card className="bg-white dark:bg-[#111827] border-0 shadow-sm rounded-lg">
          <CardContent className="p-6 text-center">
            <p className="text-gray-500 dark:text-gray-400 text-sm">No more posts to show</p>
          </CardContent>
        </Card>
      )}

      <Dialog
        open={youtubeShortModalOpen}
        onOpenChange={(open) => {
          setYoutubeShortModalOpen(open)
          if (!open) {
            setShortYoutubeUrl('')
          }
        }}
      >
        <DialogContent className="max-w-md max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Import YouTube Short</DialogTitle>
            <DialogDescription>
              Paste a Shorts link and import. We&apos;ll pull the title and thumbnail, add your Short to Shorts and Media library, and attach it to your profile or page context automatically when available.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 pt-2">
            <div>
              <label htmlFor="yt-short-url" className="text-sm font-medium text-gray-900 dark:text-gray-100">
                Paste URL
              </label>
              <Input
                id="yt-short-url"
                type="url"
                placeholder="https://www.youtube.com/shorts/xxxxxxxxxxx"
                value={shortYoutubeUrl}
                onChange={(e) => setShortYoutubeUrl(e.target.value)}
                className="mt-1.5"
              />
            </div>
          </div>
          <DialogFooter className="gap-2 sm:gap-0">
            <Button type="button" variant="outline" onClick={() => setYoutubeShortModalOpen(false)}>
              Cancel
            </Button>
            <Button
              type="button"
              disabled={isPostingShort}
              onClick={() => void handleYoutubeShortSubmit()}
              className="bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 text-white"
            >
              {isPostingShort ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  Importing…
                </>
              ) : (
                'Import Short'
              )}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Shorts strip → same custom embed player as feed, in overlay */}
      <Dialog
        open={stripPlayingShortPost !== null}
        onOpenChange={(open) => {
          if (!open) {
            setStripPlayingShortPost(null)
            setStripTransitionDir(1)
          }
        }}
      >
        <DialogContent className="flex w-[min(100vw,420px,calc(85dvh*9/16))] max-w-none translate-x-[-50%] translate-y-[-50%] flex-col items-stretch gap-0 border-0 bg-transparent p-0 shadow-none [&>button:last-child]:hidden rounded-none sm:rounded-xl">
          {stripPlayingShortPost ? (
            <>
              <DialogHeader className="sr-only">
                <DialogTitle>
                  YouTube Short
                  {(stripPlayingShortPost.creator_name || stripPlayingShortPost.user?.name)
                    ? ` — ${stripPlayingShortPost.creator_name || stripPlayingShortPost.user?.name}`
                    : ''}
                </DialogTitle>
              </DialogHeader>
              {(() => {
                const src = youtubeShortEmbedSrcAutoplay(stripPlayingShortPost)
                if (!src) {
                  return (
                    <p className="px-[max(1rem,env(safe-area-inset-left))] py-8 pb-[max(2rem,env(safe-area-inset-bottom))] text-center text-sm text-white drop-shadow">
                      This Short could not be loaded.
                    </p>
                  )
                }
                return (
                  <AnimatePresence initial={false} custom={stripTransitionDir} mode="sync">
                    <motion.div
                      key={stripPlayingShortPost.id}
                      layout={false}
                      custom={stripTransitionDir}
                      variants={stripShortPlayerSlideVariants}
                      initial="initial"
                      animate="animate"
                      exit="exit"
                      transition={
                        prefersReducedMotion
                          ? { duration: 0 }
                          : { duration: 0.38, ease: [0.22, 1, 0.36, 1] }
                      }
                      className="relative aspect-[9/16] w-full max-h-[85dvh] overflow-hidden rounded-none sm:rounded-xl touch-none"
                    >
                      <div ref={stripPlayerShellRef} className="absolute inset-0">
                        <iframe
                          key={stripPlayingShortPost.id}
                          title="YouTube Short"
                          src={src}
                          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                          referrerPolicy="strict-origin-when-cross-origin"
                          allowFullScreen
                          className="pointer-events-none absolute inset-0 h-full w-full rounded-none sm:rounded-xl"
                        />
                        <div
                          role="presentation"
                          aria-label="Swipe vertically, or scroll with the mouse wheel: scroll down for next short, scroll up for previous short or to close"
                          className="absolute inset-0 z-10 cursor-grab bg-transparent active:cursor-grabbing"
                          style={{ touchAction: 'none' }}
                          ref={stripShortOverlayRef}
                          onPointerDown={onStripShortPointerDown}
                          onPointerMove={onStripShortPointerMove}
                          onPointerUp={onStripShortPointerEnd}
                          onPointerCancel={onStripShortPointerEnd}
                        />
                      </div>
                    </motion.div>
                  </AnimatePresence>
                )
              })()}
            </>
          ) : null}
        </DialogContent>
      </Dialog>

      {/* Image Viewer Modal */}
      <Dialog open={!!imageViewer} onOpenChange={() => setImageViewer(null)}>
        <DialogContent className="max-w-6xl w-full p-0 bg-black/95 border-none">
          {imageViewer && (
            <div className="relative w-full h-[90vh] flex items-center justify-center">
              {/* Close Button */}
              <button
                onClick={() => setImageViewer(null)}
                className="absolute top-4 right-4 z-50 bg-black/50 hover:bg-black/70 text-white rounded-full p-2 transition-colors"
              >
                <X className="w-6 h-6" />
              </button>

              {/* Previous Button */}
              {imageViewer.images.length > 1 && imageViewer.currentIndex > 0 && (
                <button
                  onClick={() => setImageViewer({
                    ...imageViewer,
                    currentIndex: imageViewer.currentIndex - 1
                  })}
                  className="absolute left-4 top-1/2 -translate-y-1/2 z-50 bg-black/50 hover:bg-black/70 text-white rounded-full p-3 transition-colors"
                >
                  <ChevronLeft className="w-6 h-6" />
                </button>
              )}

              {/* Image */}
              <img
                src={imageViewer.images[imageViewer.currentIndex]}
                alt={`Image ${imageViewer.currentIndex + 1}`}
                className="max-w-full max-h-full object-contain"
              />

              {/* Next Button */}
              {imageViewer.images.length > 1 && imageViewer.currentIndex < imageViewer.images.length - 1 && (
                <button
                  onClick={() => setImageViewer({
                    ...imageViewer,
                    currentIndex: imageViewer.currentIndex + 1
                  })}
                  className="absolute right-4 top-1/2 -translate-y-1/2 z-50 bg-black/50 hover:bg-black/70 text-white rounded-full p-3 transition-colors"
                >
                  <ChevronRight className="w-6 h-6" />
                </button>
              )}

              {/* Image Counter */}
              {imageViewer.images.length > 1 && (
                <div className="absolute bottom-4 left-1/2 -translate-x-1/2 bg-black/50 text-white px-4 py-2 rounded-full text-sm">
                  {imageViewer.currentIndex + 1} / {imageViewer.images.length}
                </div>
              )}

              {/* Keyboard Navigation */}
              {imageViewer.images.length > 1 && (
                <div
                  className="absolute inset-0"
                  onKeyDown={(e) => {
                    if (e.key === 'ArrowLeft' && imageViewer.currentIndex > 0) {
                      setImageViewer({
                        ...imageViewer,
                        currentIndex: imageViewer.currentIndex - 1
                      })
                    } else if (e.key === 'ArrowRight' && imageViewer.currentIndex < imageViewer.images.length - 1) {
                      setImageViewer({
                        ...imageViewer,
                        currentIndex: imageViewer.currentIndex + 1
                      })
                    } else if (e.key === 'Escape') {
                      setImageViewer(null)
                    }
                  }}
                  tabIndex={0}
                />
              )}
            </div>
          )}
        </DialogContent>
      </Dialog>

      {/* Delete Confirmation Dialog */}
      <AlertDialog open={!!deleteConfirm} onOpenChange={(open) => !open && setDeleteConfirm(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Post</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete this post? This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel onClick={() => setDeleteConfirm(null)}>Cancel</AlertDialogCancel>
            <AlertDialogAction
              onClick={confirmDelete}
              className="bg-red-600 hover:bg-red-700 text-white"
            >
              Delete
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  )
}
