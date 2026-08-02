"use client"

import { useEffect, useId, useRef, useState, type KeyboardEvent, type TextareaHTMLAttributes } from "react"
import { Textarea } from "@/components/frontend/ui/textarea"
import { Loader2, UserRound } from "lucide-react"
import { cn } from "@/lib/utils"

export type MentionOption = {
  id: number
  name: string
  email?: string | null
  image?: string | null
}

type Props = {
  value: string
  onChange: (value: string, mentionedUserIds: number[]) => void
  mentionedUserIds?: number[]
  parentType: string
  parentId: number
  className?: string
  rows?: number
  placeholder?: string
  required?: boolean
  disabled?: boolean
  id?: string
} & Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, "value" | "onChange" | "id">

function mentionQueryAt(text: string, cursor: number): { start: number; query: string } | null {
  const before = text.slice(0, cursor)
  const at = before.lastIndexOf("@")
  if (at === -1) {
    return null
  }
  if (at > 0) {
    const prev = before[at - 1]
    if (prev && !/\s|\(/.test(prev)) {
      return null
    }
  }
  const query = before.slice(at + 1)
  if (query.includes("\n") || query.length > 60) {
    return null
  }
  return { start: at, query }
}

export default function MentionTextarea({
  value,
  onChange,
  mentionedUserIds = [],
  parentType,
  parentId,
  className,
  rows = 4,
  placeholder,
  required,
  disabled,
  id,
  ...rest
}: Props) {
  const autoId = useId()
  const fieldId = id ?? `mention-${autoId}`
  const listId = `${fieldId}-list`
  const wrapRef = useRef<HTMLDivElement>(null)
  const areaRef = useRef<HTMLTextAreaElement>(null)
  const [open, setOpen] = useState(false)
  const [searching, setSearching] = useState(false)
  const [results, setResults] = useState<MentionOption[]>([])
  const [activeIndex, setActiveIndex] = useState(0)
  const [mention, setMention] = useState<{ start: number; query: string } | null>(null)
  const mentionedRef = useRef<number[]>(mentionedUserIds)

  useEffect(() => {
    mentionedRef.current = mentionedUserIds
  }, [mentionedUserIds])

  useEffect(() => {
    if (!mention) {
      setResults([])
      setOpen(false)
      setSearching(false)
      return
    }

    const controller = new AbortController()
    const handle = window.setTimeout(() => {
      setSearching(true)
      const params = new URLSearchParams({
        parent_type: parentType,
        parent_id: String(parentId),
        q: mention.query,
      })
      fetch(`${route("community.mentionables")}?${params.toString()}`, {
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
        signal: controller.signal,
      })
        .then(async (res) => {
          if (!res.ok) {
            setResults([])
            return
          }
          const json = (await res.json()) as { results?: MentionOption[] }
          const list = Array.isArray(json.results) ? json.results : []
          setResults(list)
          setActiveIndex(0)
          setOpen(list.length > 0)
        })
        .catch((err: unknown) => {
          if (err instanceof DOMException && err.name === "AbortError") {
            return
          }
          setResults([])
          setOpen(false)
        })
        .finally(() => setSearching(false))
    }, 200)

    return () => {
      window.clearTimeout(handle)
      controller.abort()
    }
  }, [mention?.start, mention?.query, parentType, parentId])

  useEffect(() => {
    const onPointerDown = (event: MouseEvent) => {
      if (!wrapRef.current?.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener("mousedown", onPointerDown)
    return () => document.removeEventListener("mousedown", onPointerDown)
  }, [])

  const updateFromArea = (nextValue: string, cursor: number) => {
    onChange(nextValue, mentionedRef.current)
    setMention(mentionQueryAt(nextValue, cursor))
  }

  const pick = (option: MentionOption) => {
    const area = areaRef.current
    const cursor = area?.selectionStart ?? value.length
    const active = mentionQueryAt(value, cursor) ?? mention
    if (!active) {
      return
    }
    const before = value.slice(0, active.start)
    const after = value.slice(cursor)
    const insertion = `@${option.name} `
    const next = `${before}${insertion}${after}`
    const nextIds = mentionedRef.current.includes(option.id)
      ? mentionedRef.current
      : [...mentionedRef.current, option.id]
    mentionedRef.current = nextIds
    onChange(next, nextIds)
    setOpen(false)
    setMention(null)
    setResults([])
    requestAnimationFrame(() => {
      const el = areaRef.current
      if (!el) return
      const pos = before.length + insertion.length
      el.focus()
      el.setSelectionRange(pos, pos)
    })
  }

  const onKeyDown = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (!open || results.length === 0) {
      return
    }
    if (e.key === "ArrowDown") {
      e.preventDefault()
      setActiveIndex((i) => (i + 1) % results.length)
    } else if (e.key === "ArrowUp") {
      e.preventDefault()
      setActiveIndex((i) => (i - 1 + results.length) % results.length)
    } else if (e.key === "Enter" || e.key === "Tab") {
      e.preventDefault()
      pick(results[activeIndex] ?? results[0])
    } else if (e.key === "Escape") {
      e.preventDefault()
      setOpen(false)
    }
  }

  return (
    <div ref={wrapRef} className="relative">
      <Textarea
        {...rest}
        ref={areaRef}
        id={fieldId}
        rows={rows}
        className={className}
        value={value}
        placeholder={placeholder}
        required={required}
        disabled={disabled}
        role="combobox"
        aria-expanded={open}
        aria-controls={listId}
        aria-autocomplete="list"
        onChange={(e) => updateFromArea(e.target.value, e.target.selectionStart ?? e.target.value.length)}
        onClick={(e) => {
          const el = e.currentTarget
          setMention(mentionQueryAt(el.value, el.selectionStart ?? el.value.length))
        }}
        onKeyUp={(e) => {
          if (["ArrowDown", "ArrowUp", "Enter", "Tab", "Escape"].includes(e.key)) {
            return
          }
          const el = e.currentTarget
          setMention(mentionQueryAt(el.value, el.selectionStart ?? el.value.length))
        }}
        onKeyDown={onKeyDown}
      />
      {searching && (
        <Loader2 className="pointer-events-none absolute right-3 top-3 h-4 w-4 animate-spin text-muted-foreground" />
      )}
      {open && results.length > 0 ? (
        <ul
          id={listId}
          role="listbox"
          className="absolute z-40 mt-1 max-h-56 w-full overflow-auto rounded-md border border-border bg-popover p-1 shadow-md"
        >
          {results.map((option, index) => (
            <li key={option.id} role="option" aria-selected={index === activeIndex}>
              <button
                type="button"
                className={cn(
                  "flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-muted",
                  index === activeIndex && "bg-muted",
                )}
                onMouseDown={(e) => e.preventDefault()}
                onClick={() => pick(option)}
              >
                {option.image ? (
                  <img src={option.image} alt="" className="h-7 w-7 rounded-full object-cover" />
                ) : (
                  <span className="flex h-7 w-7 items-center justify-center rounded-full bg-muted text-muted-foreground">
                    <UserRound className="h-3.5 w-3.5" />
                  </span>
                )}
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-medium text-foreground">{option.name}</span>
                  {option.email ? (
                    <span className="block truncate text-xs text-muted-foreground">{option.email}</span>
                  ) : null}
                </span>
              </button>
            </li>
          ))}
        </ul>
      ) : null}
      {mention && !searching && results.length === 0 && mention.query.length >= 0 ? (
        <p className="mt-1 text-[11px] text-muted-foreground">
          {mention.query.length === 0
            ? "Type a name to mention a group member…"
            : "No matching group members."}
        </p>
      ) : null}
    </div>
  )
}
