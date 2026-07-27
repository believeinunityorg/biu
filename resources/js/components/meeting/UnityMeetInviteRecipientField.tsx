"use client"

import { useEffect, useId, useRef, useState } from "react"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import { Label } from "@/components/ui/label"
import { Loader2, Search, Send, UserRound } from "lucide-react"
import { cn } from "@/lib/utils"

export type UnityMeetInviteRecipientOption = {
  id: number
  name: string
  email: string
  image: string | null
}

type Props = {
  value: string
  onChange: (email: string) => void
  onSubmit: () => void
  disabled?: boolean
  submitting?: boolean
  error?: string | null
  inputId?: string
  className?: string
  /** Stack send button below input (Show sidebar) vs side-by-side (Ready). */
  stackActions?: boolean
}

export default function UnityMeetInviteRecipientField({
  value,
  onChange,
  onSubmit,
  disabled = false,
  submitting = false,
  error = null,
  inputId,
  className,
  stackActions = false,
}: Props) {
  const autoId = useId()
  const fieldId = inputId ?? `unity-meet-invite-${autoId}`
  const listId = `${fieldId}-results`
  const wrapRef = useRef<HTMLDivElement>(null)
  const [open, setOpen] = useState(false)
  const [searching, setSearching] = useState(false)
  const [results, setResults] = useState<UnityMeetInviteRecipientOption[]>([])

  useEffect(() => {
    const q = value.trim()
    if (q.length < 2) {
      setResults([])
      setSearching(false)
      return
    }

    const controller = new AbortController()
    const handle = window.setTimeout(() => {
      setSearching(true)
      fetch(`/livestreams/supporter/invite-recipients?q=${encodeURIComponent(q)}`, {
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
        signal: controller.signal,
      })
        .then(async (res) => {
          if (!res.ok) {
            setResults([])
            return
          }
          const json = (await res.json()) as { results?: UnityMeetInviteRecipientOption[] }
          setResults(Array.isArray(json.results) ? json.results : [])
          setOpen(true)
        })
        .catch((err: unknown) => {
          if (err instanceof DOMException && err.name === "AbortError") {
            return
          }
          setResults([])
        })
        .finally(() => setSearching(false))
    }, 280)

    return () => {
      window.clearTimeout(handle)
      controller.abort()
    }
  }, [value])

  useEffect(() => {
    const onPointerDown = (event: MouseEvent) => {
      if (!wrapRef.current?.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener("mousedown", onPointerDown)
    return () => document.removeEventListener("mousedown", onPointerDown)
  }, [])

  const pick = (recipient: UnityMeetInviteRecipientOption) => {
    onChange(recipient.email)
    setOpen(false)
    setResults([])
  }

  const canSubmit = value.trim() !== "" && !disabled && !submitting

  return (
    <div ref={wrapRef} className={cn("space-y-2", className)}>
      <Label htmlFor={fieldId} className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
        Guest email or supporter
      </Label>
      <div className={cn("flex gap-2", stackActions && "flex-col")}>
        <div className="relative min-w-0 flex-1">
          <Search className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
          <Input
            id={fieldId}
            type="text"
            inputMode="email"
            autoComplete="off"
            role="combobox"
            aria-expanded={open && results.length > 0}
            aria-controls={listId}
            placeholder="Search supporters or type an email"
            value={value}
            onChange={(e) => {
              onChange(e.target.value)
              setOpen(true)
            }}
            onFocus={() => {
              if (results.length > 0) {
                setOpen(true)
              }
            }}
            onKeyDown={(e) => {
              if (e.key === "Enter") {
                e.preventDefault()
                if (canSubmit) {
                  onSubmit()
                }
              }
              if (e.key === "Escape") {
                setOpen(false)
              }
            }}
            className={cn("h-9 pl-9 pr-9 text-sm", stackActions && "w-full")}
            disabled={disabled || submitting}
          />
          {searching ? (
            <Loader2 className="absolute right-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 animate-spin text-muted-foreground" />
          ) : null}
          {open && results.length > 0 ? (
            <ul
              id={listId}
              role="listbox"
              className="absolute z-30 mt-1 max-h-56 w-full overflow-auto rounded-md border border-border bg-popover p-1 shadow-md"
            >
              {results.map((recipient) => (
                <li key={recipient.id} role="option">
                  <button
                    type="button"
                    className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-muted"
                    onClick={() => pick(recipient)}
                  >
                    {recipient.image ? (
                      <img src={recipient.image} alt="" className="h-7 w-7 rounded-full object-cover" />
                    ) : (
                      <span className="flex h-7 w-7 items-center justify-center rounded-full bg-muted text-muted-foreground">
                        <UserRound className="h-3.5 w-3.5" />
                      </span>
                    )}
                    <span className="min-w-0 flex-1">
                      <span className="block truncate font-medium text-foreground">{recipient.name}</span>
                      <span className="block truncate text-xs text-muted-foreground">{recipient.email}</span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          ) : null}
        </div>
        <Button
          type="button"
          size="sm"
          className={cn(
            "h-9 shrink-0 gap-1.5 bg-gradient-to-r from-purple-600 to-blue-600 text-white hover:from-purple-700 hover:to-blue-700",
            stackActions && "w-full"
          )}
          onClick={onSubmit}
          disabled={!canSubmit}
        >
          {submitting ? (
            <span className="text-xs">Sending…</span>
          ) : (
            <>
              <Send className="h-3.5 w-3.5" />
              Send
            </>
          )}
        </Button>
      </div>
      <p className="text-[11px] text-muted-foreground leading-snug">
        Pick a Believe In Unity supporter from the list, or type any email to send the join link.
      </p>
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </div>
  )
}
