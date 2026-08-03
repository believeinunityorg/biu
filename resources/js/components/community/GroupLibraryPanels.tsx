"use client"

import { FormEvent, useRef, useState, type ChangeEvent } from "react"
import { router, useForm } from "@inertiajs/react"
import { Button } from "@/components/frontend/ui/button"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import {
  Calendar,
  Download,
  ExternalLink,
  FileText,
  Image as ImageIcon,
  MapPin,
  Plus,
  Trash2,
  Upload,
  Video,
  X,
} from "lucide-react"

export type GroupLibraryItem = {
  id: number
  type: "photo" | "video" | "document" | string
  title: string | null
  caption: string | null
  original_name: string
  mime: string | null
  size: number
  url: string
  created_at: string | null
  can_manage: boolean
  uploader: { id: number; name: string } | null
}

export type GroupEventItem = {
  id: number
  title: string
  description: string | null
  starts_at: string | null
  ends_at: string | null
  location: string | null
  location_url: string | null
  cover_image: string | null
  status: string
  is_cancelled: boolean
  created_at: string | null
  can_manage: boolean
  creator: { id: number; name: string } | null
}

function formatBytes(bytes: number) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function formatWhen(iso: string | null) {
  if (!iso) return ""
  const d = new Date(iso)
  return d.toLocaleString(undefined, {
    weekday: "short",
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  })
}

/** Group photos uploaded together (same uploader, within a short window). */
function groupPhotosByUploadBatch(items: GroupLibraryItem[], windowMs = 3 * 60 * 1000): GroupLibraryItem[][] {
  const sorted = [...items].sort((a, b) => {
    const ta = a.created_at ? new Date(a.created_at).getTime() : 0
    const tb = b.created_at ? new Date(b.created_at).getTime() : 0
    return tb - ta
  })

  const batches: GroupLibraryItem[][] = []
  for (const item of sorted) {
    const t = item.created_at ? new Date(item.created_at).getTime() : 0
    const uploaderId = item.uploader?.id ?? 0
    const last = batches[batches.length - 1]
    if (last && last.length > 0) {
      const head = last[0]
      const headT = head.created_at ? new Date(head.created_at).getTime() : 0
      const sameUploader = (head.uploader?.id ?? 0) === uploaderId
      if (sameUploader && Math.abs(headT - t) <= windowMs) {
        last.push(item)
        continue
      }
    }
    batches.push([item])
  }
  return batches
}

function PhotoTile({
  item,
  className = "",
  onRemove,
  overlay,
}: {
  item: GroupLibraryItem
  className?: string
  onRemove?: (item: GroupLibraryItem) => void
  overlay?: string | null
}) {
  return (
    <div className={`group/tile relative min-h-0 min-w-0 overflow-hidden bg-slate-900 ${className}`}>
      <a href={item.url} target="_blank" rel="noreferrer" className="absolute inset-0 block">
        <img
          src={item.url}
          alt={item.title || item.original_name}
          className="h-full w-full object-cover transition duration-200 group-hover/tile:scale-[1.02]"
          loading="lazy"
        />
        {overlay ? (
          <div className="absolute inset-0 flex items-center justify-center bg-black/55">
            <span className="text-3xl font-bold tracking-tight text-white sm:text-4xl">{overlay}</span>
          </div>
        ) : (
          <div className="pointer-events-none absolute inset-0 bg-black/0 transition group-hover/tile:bg-black/10" />
        )}
      </a>
      {item.can_manage && onRemove && (
        <button
          type="button"
          title="Remove"
          onClick={(e) => {
            e.preventDefault()
            e.stopPropagation()
            onRemove(item)
          }}
          className="absolute right-2 top-2 z-10 inline-flex h-8 w-8 cursor-pointer items-center justify-center rounded-full bg-black/65 text-white opacity-0 transition group-hover/tile:opacity-100 hover:bg-red-600"
        >
          <Trash2 className="h-3.5 w-3.5" />
        </button>
      )}
    </div>
  )
}

/** Facebook feed-style multi-photo collage inside a full card. */
function FacebookPhotoCollageCard({
  photos,
  onRemove,
}: {
  photos: GroupLibraryItem[]
  onRemove: (item: GroupLibraryItem) => void
}) {
  if (photos.length === 0) return null

  const head = photos[0]
  const fifthOverlay = photos.length > 5 ? `+${photos.length - 5}` : null

  return (
    <article className="overflow-hidden rounded-xl border border-purple-100 bg-white shadow-sm dark:border-purple-500/20 dark:bg-[#111827]">
      <div className="flex items-center gap-3 border-b border-purple-50 px-4 py-3 dark:border-purple-500/15">
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-blue-600 text-sm font-bold text-white">
          {(head.uploader?.name || "M").charAt(0).toUpperCase()}
        </div>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-bold text-slate-900 dark:text-white">
            {head.uploader?.name || "Member"}
          </p>
          <p className="text-[12px] text-slate-500">
            {photos.length === 1 ? "added a photo" : `added ${photos.length} photos`}
            {head.created_at ? ` · ${new Date(head.created_at).toLocaleString()}` : ""}
          </p>
        </div>
      </div>

      {photos.length === 1 && (
        <div className="relative max-h-[min(70vh,560px)] w-full bg-black">
          <a href={photos[0].url} target="_blank" rel="noreferrer" className="block">
            <img
              src={photos[0].url}
              alt={photos[0].title || photos[0].original_name}
              className="mx-auto max-h-[min(70vh,560px)] w-full object-contain"
              loading="lazy"
            />
          </a>
          {photos[0].can_manage && (
            <button
              type="button"
              title="Remove"
              onClick={() => onRemove(photos[0])}
              className="absolute right-3 top-3 inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-full bg-black/65 text-white hover:bg-red-600"
            >
              <Trash2 className="h-4 w-4" />
            </button>
          )}
        </div>
      )}

      {photos.length === 2 && (
        <div className="grid h-[min(52vw,360px)] grid-cols-2 gap-[3px] bg-slate-200 dark:bg-slate-800">
          {photos.map((item) => (
            <PhotoTile key={item.id} item={item} onRemove={onRemove} />
          ))}
        </div>
      )}

      {photos.length === 3 && (
        <div className="grid h-[min(58vw,420px)] grid-cols-2 grid-rows-2 gap-[3px] bg-slate-200 dark:bg-slate-800">
          <PhotoTile item={photos[0]} onRemove={onRemove} className="row-span-2" />
          <PhotoTile item={photos[1]} onRemove={onRemove} />
          <PhotoTile item={photos[2]} onRemove={onRemove} />
        </div>
      )}

      {photos.length === 4 && (
        <div className="grid h-[min(64vw,460px)] grid-cols-2 grid-rows-2 gap-[3px] bg-slate-200 dark:bg-slate-800">
          {photos.map((item) => (
            <PhotoTile key={item.id} item={item} onRemove={onRemove} />
          ))}
        </div>
      )}

      {photos.length >= 5 && (
        <div className="grid h-[min(68vw,500px)] grid-cols-2 grid-rows-2 gap-[3px] bg-slate-200 dark:bg-slate-800">
          <PhotoTile item={photos[0]} onRemove={onRemove} />
          <PhotoTile item={photos[1]} onRemove={onRemove} />
          <div className="col-span-2 grid grid-cols-3 gap-[3px]">
            <PhotoTile item={photos[2]} onRemove={onRemove} />
            <PhotoTile item={photos[3]} onRemove={onRemove} />
            <PhotoTile
              item={photos[4]}
              onRemove={onRemove}
              overlay={fifthOverlay}
            />
          </div>
        </div>
      )}

      {/* Hidden extras still reachable when +N shown — list under card for manage */}
      {photos.length > 5 && (
        <div className="flex flex-wrap gap-2 border-t border-purple-50 px-3 py-2 dark:border-purple-500/15">
          {photos.slice(5).map((item) => (
            <a
              key={item.id}
              href={item.url}
              target="_blank"
              rel="noreferrer"
              className="relative h-14 w-14 overflow-hidden rounded-md bg-slate-200 dark:bg-slate-700"
            >
              <img src={item.url} alt="" className="h-full w-full object-cover" loading="lazy" />
            </a>
          ))}
        </div>
      )}
    </article>
  )
}

type LibraryProps = {
  groupSlug: string
  type: "photo" | "video" | "document"
  items: GroupLibraryItem[]
  canUpload: boolean
}

export function GroupLibraryPanel({ groupSlug, type, items, canUpload }: LibraryProps) {
  const [showComposer, setShowComposer] = useState(false)
  const [previews, setPreviews] = useState<{ file: File; url: string }[]>([])
  const fileRef = useRef<HTMLInputElement>(null)
  const { data, setData, post, processing, errors, reset } = useForm<{
    type: string
    title: string
    caption: string
    files: File[]
  }>({
    type,
    title: "",
    caption: "",
    files: [],
  })

  const meta =
    type === "photo"
      ? {
          title: "Photos",
          blurb: "Share pictures with the group",
          icon: ImageIcon,
          accept: "image/jpeg,image/png,image/jpg,image/webp,image/gif",
          button: "Add photos",
          hint: "Select multiple · JPEG, PNG, WebP or GIF · up to 5 MB each",
          emptyPick: "Choose photos",
        }
      : type === "video"
        ? {
            title: "Videos",
            blurb: "Upload clips for members to watch",
            icon: Video,
            accept: "video/mp4,video/webm,video/quicktime",
            button: "Add videos",
            hint: "Select multiple · MP4, WebM or MOV · up to 50 MB each",
            emptyPick: "Choose videos",
          }
        : {
            title: "Files",
            blurb: "Shared documents and resources",
            icon: FileText,
            accept: ".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip",
            button: "Add files",
            hint: "Select multiple · PDF, Office, text or ZIP · up to 10 MB each",
            emptyPick: "Choose files",
          }

  const Icon = meta.icon

  const clearPreviews = (next: { file: File; url: string }[] = []) => {
    previews.forEach((p) => URL.revokeObjectURL(p.url))
    setPreviews(next)
  }

  const syncFiles = (files: File[]) => {
    const capped = files.slice(0, 20)
    const next = capped.map((file) => ({
      file,
      url: file.type.startsWith("image/") || file.type.startsWith("video/") ? URL.createObjectURL(file) : "",
    }))
    clearPreviews(next)
    setData("files", capped)
  }

  const onFilePicked = (e: ChangeEvent<HTMLInputElement>) => {
    const picked = Array.from(e.target.files ?? [])
    e.target.value = ""
    if (picked.length === 0) return
    // Append to existing selection so users can keep adding
    const merged = [...data.files, ...picked]
    const unique: File[] = []
    const seen = new Set<string>()
    for (const file of merged) {
      const key = `${file.name}:${file.size}:${file.lastModified}`
      if (seen.has(key)) continue
      seen.add(key)
      unique.push(file)
    }
    syncFiles(unique)
  }

  const removePreview = (index: number) => {
    const nextFiles = data.files.filter((_, i) => i !== index)
    syncFiles(nextFiles)
  }

  const closeComposer = () => {
    setShowComposer(false)
    clearPreviews([])
    reset()
    setData({ type, title: "", caption: "", files: [] })
  }

  const submit = (e: FormEvent) => {
    e.preventDefault()
    if (data.files.length === 0) return
    post(route("groups.library.store", groupSlug), {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        clearPreviews([])
        reset()
        setData({ type, title: "", caption: "", files: [] })
        setShowComposer(false)
        if (fileRef.current) fileRef.current.value = ""
      },
    })
  }

  const removeItem = (item: GroupLibraryItem) => {
    if (!confirm(`Remove “${item.title || item.original_name}”?`)) return
    router.delete(route("groups.library.destroy", { group: groupSlug, item: item.id }), {
      preserveScroll: true,
    })
  }

  return (
    <div className="space-y-4">
      {!showComposer ? (
        <div className="overflow-hidden rounded-xl border border-purple-100 bg-white shadow-sm shadow-purple-500/5 dark:border-purple-500/20 dark:bg-[#111827]">
          <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-blue-600 text-white">
                <Icon className="h-5 w-5" />
              </div>
              <div>
                <p className="text-[15px] font-bold text-slate-900 dark:text-white">{meta.title}</p>
                <p className="text-xs text-slate-500 dark:text-slate-400">{meta.blurb}</p>
              </div>
            </div>
            {canUpload && (
              <Button
                type="button"
                className="cursor-pointer rounded-lg bg-gradient-to-r from-purple-600 to-blue-600 font-semibold text-white"
                onClick={() => setShowComposer(true)}
              >
                <Plus className="mr-1.5 h-4 w-4" />
                {meta.button}
              </Button>
            )}
          </div>
        </div>
      ) : (
        canUpload && (
          <form
            onSubmit={submit}
            className="overflow-hidden rounded-xl border border-purple-100 bg-white shadow-sm shadow-purple-500/5 dark:border-purple-500/20 dark:bg-[#111827]"
          >
            <div className="flex items-center justify-between border-b border-purple-50 px-4 py-3 dark:border-purple-500/15">
              <p className="text-[15px] font-bold text-slate-900 dark:text-white">{meta.button}</p>
              <button
                type="button"
                className="cursor-pointer text-sm font-semibold text-purple-700 hover:underline dark:text-purple-300"
                onClick={closeComposer}
              >
                Cancel
              </button>
            </div>
            <div className="space-y-4 p-4">
              <div>
                <Label className="text-xs text-slate-500">Title (optional — applied to all)</Label>
                <Input
                  className="mt-1 h-10 rounded-xl border-0 bg-gradient-to-r from-purple-50 to-blue-50 px-3.5 text-slate-900 shadow-none focus-visible:ring-2 focus-visible:ring-purple-200 focus-visible:ring-offset-0 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white"
                  value={data.title}
                  onChange={(e) => setData("title", e.target.value)}
                  placeholder="Give them a name…"
                />
              </div>
              <div>
                <Label className="text-xs text-slate-500">Caption (optional — applied to all)</Label>
                <textarea
                  className="mt-1 min-h-[72px] w-full rounded-xl border-0 bg-gradient-to-r from-purple-50 to-blue-50 px-3.5 py-2.5 text-sm text-slate-900 shadow-none outline-none focus:ring-2 focus:ring-purple-200 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white"
                  value={data.caption}
                  onChange={(e) => setData("caption", e.target.value)}
                  placeholder="Add a short note…"
                />
              </div>
              <div>
                <input
                  ref={fileRef}
                  type="file"
                  accept={meta.accept}
                  multiple
                  className="hidden"
                  onChange={onFilePicked}
                />
                <div
                  className={`rounded-xl border border-dashed border-purple-200 bg-gradient-to-r from-purple-50/80 to-blue-50/80 dark:border-purple-500/30 dark:from-purple-500/10 dark:to-blue-500/10 ${
                    previews.length === 0 ? "px-4 py-8" : "p-3"
                  }`}
                >
                  {previews.length === 0 ? (
                    <button
                      type="button"
                      onClick={() => fileRef.current?.click()}
                      className="flex w-full cursor-pointer flex-col items-center justify-center gap-2 text-sm font-semibold text-purple-800 dark:text-purple-100"
                    >
                      <Upload className="h-6 w-6" />
                      {meta.emptyPick}
                      <span className="text-xs font-normal text-slate-500 dark:text-slate-400">
                        {meta.hint}
                      </span>
                    </button>
                  ) : (
                    <div className="space-y-3">
                      <div className="grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-5">
                        {previews.map((preview, index) => {
                          const isImage = preview.file.type.startsWith("image/")
                          const isVideo = preview.file.type.startsWith("video/")
                          return (
                            <div
                              key={`${preview.file.name}-${preview.file.size}-${index}`}
                              className="group relative aspect-square overflow-hidden rounded-lg border border-purple-100 bg-white shadow-sm dark:border-purple-500/25 dark:bg-[#0d1424]"
                            >
                              {isImage && preview.url ? (
                                <img
                                  src={preview.url}
                                  alt={preview.file.name}
                                  className="h-full w-full object-cover"
                                />
                              ) : isVideo && preview.url ? (
                                <video
                                  src={preview.url}
                                  className="h-full w-full object-cover"
                                  muted
                                  playsInline
                                  preload="metadata"
                                />
                              ) : (
                                <div className="flex h-full flex-col items-center justify-center gap-1 p-2 text-center">
                                  <FileText className="h-6 w-6 text-purple-500" />
                                  <span className="line-clamp-2 text-[10px] font-medium text-slate-600 dark:text-slate-300">
                                    {preview.file.name}
                                  </span>
                                </div>
                              )}
                              <button
                                type="button"
                                title="Remove"
                                onClick={() => removePreview(index)}
                                className="absolute right-1 top-1 inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-full bg-black/60 text-white transition hover:bg-black/80"
                              >
                                <X className="h-3.5 w-3.5" />
                              </button>
                              <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-1.5 pb-1.5 pt-4">
                                <p className="truncate text-[10px] font-medium text-white">
                                  {preview.file.name}
                                </p>
                                <p className="text-[9px] text-white/80">{formatBytes(preview.file.size)}</p>
                              </div>
                            </div>
                          )
                        })}
                        <button
                          type="button"
                          onClick={() => fileRef.current?.click()}
                          className="flex aspect-square cursor-pointer flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-purple-300 bg-white/60 text-purple-700 transition hover:bg-white dark:border-purple-500/40 dark:bg-[#111827]/60 dark:text-purple-200 dark:hover:bg-[#111827]"
                        >
                          <Plus className="h-5 w-5" />
                          <span className="text-[11px] font-semibold">Add more</span>
                        </button>
                      </div>
                      <p className="text-center text-xs text-slate-500 dark:text-slate-400">
                        {data.files.length} selected · {meta.hint}
                      </p>
                    </div>
                  )}
                </div>
                {(errors.files || errors["files.0"]) && (
                  <p className="mt-1 text-sm text-red-600">
                    {errors.files || errors["files.0"]}
                  </p>
                )}
              </div>
              <Button
                type="submit"
                disabled={processing || data.files.length === 0}
                className="h-10 w-full cursor-pointer rounded-lg bg-gradient-to-r from-purple-600 to-blue-600 text-[15px] font-bold text-white"
              >
                {processing
                  ? "Uploading…"
                  : data.files.length > 1
                    ? `Upload ${data.files.length} items`
                    : "Upload"}
              </Button>
            </div>
          </form>
        )
      )}

      {items.length === 0 ? (
        <div className="rounded-xl border border-purple-100 bg-white px-4 py-10 text-center shadow-sm dark:border-purple-500/20 dark:bg-[#111827]">
          <Icon className="mx-auto h-10 w-10 text-purple-400" />
          <p className="mt-3 text-sm font-semibold text-slate-800 dark:text-slate-100">Nothing here yet</p>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {canUpload ? `Be the first to ${meta.button.toLowerCase()}.` : "Check back later for new uploads."}
          </p>
        </div>
      ) : type === "photo" ? (
        <div className="space-y-4">
          {groupPhotosByUploadBatch(items).map((batch) => (
            <FacebookPhotoCollageCard
              key={batch.map((p) => p.id).join("-")}
              photos={batch}
              onRemove={removeItem}
            />
          ))}
        </div>
      ) : type === "video" ? (
        <div className="space-y-3">
          {items.map((item) => (
            <div
              key={item.id}
              className="overflow-hidden rounded-xl border border-purple-100 bg-white shadow-sm dark:border-purple-500/20 dark:bg-[#111827]"
            >
              <video src={item.url} controls className="aspect-video w-full bg-black" preload="metadata" />
              <div className="flex items-start justify-between gap-3 p-3">
                <div className="min-w-0">
                  <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                    {item.title || item.original_name}
                  </p>
                  {item.caption && (
                    <p className="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{item.caption}</p>
                  )}
                  <p className="mt-1 text-[11px] text-slate-500">
                    {item.uploader?.name || "Member"}
                    {item.created_at ? ` · ${new Date(item.created_at).toLocaleDateString()}` : ""}
                    {` · ${formatBytes(item.size)}`}
                  </p>
                </div>
                {item.can_manage && (
                  <button
                    type="button"
                    onClick={() => removeItem(item)}
                    className="inline-flex h-9 shrink-0 cursor-pointer items-center gap-1.5 rounded-lg px-3 text-[13px] font-semibold text-red-600/80 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10"
                  >
                    <Trash2 className="h-3.5 w-3.5" />
                    Remove
                  </button>
                )}
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="overflow-hidden rounded-xl border border-purple-100 bg-white shadow-sm dark:border-purple-500/20 dark:bg-[#111827]">
          <div className="divide-y divide-purple-50 dark:divide-purple-500/15">
            {items.map((item) => (
              <div
                key={item.id}
                className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between"
              >
                <div className="flex min-w-0 items-center gap-3">
                  <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-gradient-to-br from-purple-100 to-blue-100 text-purple-700 dark:from-purple-500/30 dark:to-blue-500/30 dark:text-purple-100">
                    <FileText className="h-5 w-5" />
                  </div>
                  <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                      {item.title || item.original_name}
                    </p>
                    <p className="truncate text-xs text-slate-500">
                      {formatBytes(item.size)}
                      {item.uploader?.name ? ` · ${item.uploader.name}` : ""}
                      {item.created_at ? ` · ${new Date(item.created_at).toLocaleDateString()}` : ""}
                    </p>
                  </div>
                </div>
                <div className="flex flex-wrap justify-end gap-1.5">
                  <a
                    href={item.url}
                    download={item.original_name}
                    className="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-lg px-3 text-[13px] font-semibold text-purple-700 hover:bg-purple-50 dark:text-purple-300 dark:hover:bg-purple-500/10"
                  >
                    <Download className="h-3.5 w-3.5" />
                    Download
                  </a>
                  {item.can_manage && (
                    <button
                      type="button"
                      onClick={() => removeItem(item)}
                      className="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-lg px-3 text-[13px] font-semibold text-red-600/80 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10"
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                      Remove
                    </button>
                  )}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

type EventsProps = {
  groupSlug: string
  events: GroupEventItem[]
  canCreate: boolean
}

export function GroupEventsPanel({ groupSlug, events, canCreate }: EventsProps) {
  const [showComposer, setShowComposer] = useState(false)
  const coverRef = useRef<HTMLInputElement>(null)
  const { data, setData, post, processing, errors, reset } = useForm<{
    title: string
    description: string
    starts_at: string
    ends_at: string
    location: string
    location_url: string
    cover_image: File | null
  }>({
    title: "",
    description: "",
    starts_at: "",
    ends_at: "",
    location: "",
    location_url: "",
    cover_image: null,
  })

  const submit = (e: FormEvent) => {
    e.preventDefault()
    post(route("groups.events.store", groupSlug), {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        reset()
        setShowComposer(false)
        if (coverRef.current) coverRef.current.value = ""
      },
    })
  }

  const cancelEvent = (event: GroupEventItem) => {
    if (!confirm(`Cancel “${event.title}”? Members will still see it as cancelled.`)) return
    router.post(
      route("groups.events.cancel", { group: groupSlug, groupEvent: event.id }),
      {},
      { preserveScroll: true },
    )
  }

  const deleteEvent = (event: GroupEventItem) => {
    if (!confirm(`Delete “${event.title}” permanently?`)) return
    router.delete(route("groups.events.destroy", { group: groupSlug, groupEvent: event.id }), {
      preserveScroll: true,
    })
  }

  return (
    <div className="space-y-4">
      {!showComposer ? (
        <div className="overflow-hidden rounded-xl border border-purple-100 bg-white shadow-sm shadow-purple-500/5 dark:border-purple-500/20 dark:bg-[#111827]">
          <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
            <div className="flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-blue-600 text-white">
                <Calendar className="h-5 w-5" />
              </div>
              <div>
                <p className="text-[15px] font-bold text-slate-900 dark:text-white">Events</p>
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  Gatherings and activities for this group
                </p>
              </div>
            </div>
            {canCreate && (
              <Button
                type="button"
                className="cursor-pointer rounded-lg bg-gradient-to-r from-purple-600 to-blue-600 font-semibold text-white"
                onClick={() => setShowComposer(true)}
              >
                <Plus className="mr-1.5 h-4 w-4" />
                Create event
              </Button>
            )}
          </div>
        </div>
      ) : (
        canCreate && (
          <form
            onSubmit={submit}
            className="overflow-hidden rounded-xl border border-purple-100 bg-white shadow-sm shadow-purple-500/5 dark:border-purple-500/20 dark:bg-[#111827]"
          >
            <div className="flex items-center justify-between border-b border-purple-50 px-4 py-3 dark:border-purple-500/15">
              <p className="text-[15px] font-bold text-slate-900 dark:text-white">Create event</p>
              <button
                type="button"
                className="cursor-pointer text-sm font-semibold text-purple-700 hover:underline dark:text-purple-300"
                onClick={() => {
                  setShowComposer(false)
                  reset()
                }}
              >
                Cancel
              </button>
            </div>
            <div className="space-y-4 p-4">
              <Input
                className="h-11 rounded-xl border-0 bg-gradient-to-r from-purple-50 to-blue-50 px-3.5 text-[17px] font-semibold text-slate-900 shadow-none placeholder:font-normal focus-visible:ring-2 focus-visible:ring-purple-200 focus-visible:ring-offset-0 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white"
                value={data.title}
                onChange={(e) => setData("title", e.target.value)}
                placeholder="Event title"
                required
              />
              {errors.title && <p className="text-sm text-red-600">{errors.title}</p>}

              <textarea
                className="min-h-[88px] w-full rounded-xl border-0 bg-gradient-to-r from-purple-50 to-blue-50 px-3.5 py-2.5 text-sm text-slate-900 shadow-none outline-none focus:ring-2 focus:ring-purple-200 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white"
                value={data.description}
                onChange={(e) => setData("description", e.target.value)}
                placeholder="What is this event about?"
              />

              <div className="grid gap-3 sm:grid-cols-2">
                <div>
                  <Label className="text-xs text-slate-500">Starts</Label>
                  <Input
                    type="datetime-local"
                    className="mt-1 rounded-xl border-0 bg-gradient-to-r from-purple-50 to-blue-50 text-slate-900 shadow-none focus-visible:ring-2 focus-visible:ring-purple-200 focus-visible:ring-offset-0 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white"
                    value={data.starts_at}
                    onChange={(e) => setData("starts_at", e.target.value)}
                    required
                  />
                  {errors.starts_at && <p className="mt-1 text-sm text-red-600">{errors.starts_at}</p>}
                </div>
                <div>
                  <Label className="text-xs text-slate-500">Ends (optional)</Label>
                  <Input
                    type="datetime-local"
                    className="mt-1 rounded-xl border-0 bg-gradient-to-r from-purple-50 to-blue-50 text-slate-900 shadow-none focus-visible:ring-2 focus-visible:ring-purple-200 focus-visible:ring-offset-0 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white"
                    value={data.ends_at}
                    onChange={(e) => setData("ends_at", e.target.value)}
                  />
                  {errors.ends_at && <p className="mt-1 text-sm text-red-600">{errors.ends_at}</p>}
                </div>
              </div>

              <div>
                <Label className="text-xs text-slate-500">Location</Label>
                <Input
                  className="mt-1 rounded-xl border-0 bg-gradient-to-r from-purple-50 to-blue-50 text-slate-900 shadow-none focus-visible:ring-2 focus-visible:ring-purple-200 focus-visible:ring-offset-0 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white"
                  value={data.location}
                  onChange={(e) => setData("location", e.target.value)}
                  placeholder="Venue or city"
                />
              </div>
              <div>
                <Label className="text-xs text-slate-500">Meeting link (optional)</Label>
                <Input
                  type="url"
                  className="mt-1 rounded-xl border-0 bg-gradient-to-r from-purple-50 to-blue-50 text-slate-900 shadow-none focus-visible:ring-2 focus-visible:ring-purple-200 focus-visible:ring-offset-0 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white"
                  value={data.location_url}
                  onChange={(e) => setData("location_url", e.target.value)}
                  placeholder="https://"
                />
                {errors.location_url && <p className="mt-1 text-sm text-red-600">{errors.location_url}</p>}
              </div>

              <div>
                <input
                  ref={coverRef}
                  type="file"
                  accept="image/jpeg,image/png,image/jpg,image/webp,image/gif"
                  className="hidden"
                  onChange={(e) => setData("cover_image", e.target.files?.[0] ?? null)}
                />
                <button
                  type="button"
                  onClick={() => coverRef.current?.click()}
                  className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl border border-dashed border-purple-200 px-4 py-3 text-sm font-semibold text-purple-700 hover:bg-purple-50 dark:border-purple-500/30 dark:text-purple-300 dark:hover:bg-purple-500/10"
                >
                  <ImageIcon className="h-4 w-4" />
                  {data.cover_image ? data.cover_image.name : "Optional cover image"}
                </button>
                {errors.cover_image && <p className="mt-1 text-sm text-red-600">{errors.cover_image}</p>}
              </div>

              <Button
                type="submit"
                disabled={processing || !data.title.trim() || !data.starts_at}
                className="h-10 w-full cursor-pointer rounded-lg bg-gradient-to-r from-purple-600 to-blue-600 text-[15px] font-bold text-white"
              >
                {processing ? "Saving…" : "Post event"}
              </Button>
            </div>
          </form>
        )
      )}

      {events.length === 0 ? (
        <div className="rounded-xl border border-purple-100 bg-white px-4 py-10 text-center shadow-sm dark:border-purple-500/20 dark:bg-[#111827]">
          <Calendar className="mx-auto h-10 w-10 text-purple-400" />
          <p className="mt-3 text-sm font-semibold text-slate-800 dark:text-slate-100">No events yet</p>
          <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {canCreate ? "Create the first gathering for this group." : "Check back for upcoming gatherings."}
          </p>
        </div>
      ) : (
        <div className="space-y-3">
          {events.map((event) => (
            <article
              key={event.id}
              className={`overflow-hidden rounded-xl border bg-white shadow-sm dark:bg-[#111827] ${
                event.is_cancelled
                  ? "border-slate-200 opacity-75 dark:border-slate-600"
                  : "border-purple-100 dark:border-purple-500/20"
              }`}
            >
              {event.cover_image && (
                <div className="h-36 w-full overflow-hidden sm:h-44">
                  <img src={event.cover_image} alt="" className="h-full w-full object-cover" />
                </div>
              )}
              <div className="p-4">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <h3 className="text-[17px] font-bold text-slate-900 dark:text-white">{event.title}</h3>
                      {event.is_cancelled && (
                        <span className="rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-bold uppercase text-slate-700 dark:bg-slate-600 dark:text-slate-100">
                          Cancelled
                        </span>
                      )}
                    </div>
                    <p className="mt-1 flex items-center gap-1.5 text-sm font-medium text-purple-700 dark:text-purple-300">
                      <Calendar className="h-3.5 w-3.5 shrink-0" />
                      {formatWhen(event.starts_at)}
                      {event.ends_at ? ` – ${formatWhen(event.ends_at)}` : ""}
                    </p>
                  </div>
                </div>

                {event.description && (
                  <p className="mt-3 whitespace-pre-wrap text-sm text-slate-700 dark:text-slate-200">
                    {event.description}
                  </p>
                )}

                {(event.location || event.location_url) && (
                  <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-slate-600 dark:text-slate-300">
                    {event.location && (
                      <span className="inline-flex items-center gap-1.5">
                        <MapPin className="h-3.5 w-3.5 text-purple-500" />
                        {event.location}
                      </span>
                    )}
                    {event.location_url && (
                      <a
                        href={event.location_url}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1.5 font-semibold text-purple-700 hover:underline dark:text-purple-300"
                      >
                        <ExternalLink className="h-3.5 w-3.5" />
                        Join link
                      </a>
                    )}
                  </div>
                )}

                <p className="mt-3 text-[11px] text-slate-400">
                  Posted by {event.creator?.name || "Member"}
                  {event.created_at ? ` · ${new Date(event.created_at).toLocaleDateString()}` : ""}
                </p>

                {event.can_manage && (
                  <div className="mt-3 flex flex-wrap gap-1.5 border-t border-purple-50 pt-3 dark:border-purple-500/15">
                    {!event.is_cancelled && (
                      <button
                        type="button"
                        onClick={() => cancelEvent(event)}
                        className="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-lg px-3 text-[13px] font-semibold text-slate-600 hover:bg-purple-50 dark:text-slate-300 dark:hover:bg-purple-500/10"
                      >
                        <X className="h-3.5 w-3.5" />
                        Cancel event
                      </button>
                    )}
                    <button
                      type="button"
                      onClick={() => deleteEvent(event)}
                      className="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-lg px-3 text-[13px] font-semibold text-red-600/80 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-500/10"
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                      Delete
                    </button>
                  </div>
                )}
              </div>
            </article>
          ))}
        </div>
      )}
    </div>
  )
}
