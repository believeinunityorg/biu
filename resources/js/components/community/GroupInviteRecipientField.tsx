"use client"

import { useEffect, useId, useRef, useState } from "react"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import { Loader2, Mail, Search, Send, UserPlus, UserRound } from "lucide-react"
import { cn } from "@/lib/utils"

export type GroupInviteRecipientOption = {
  id: number
  name: string
  email: string
  image: string | null
  already_invited?: boolean
}

type Props = {
  groupSlug: string
  disabled?: boolean
  submitting?: boolean
  error?: string | null
  emailCreditsLeft?: number | null
  onInvite: (payload: { user_id?: number; email?: string }) => void
  className?: string
}

export default function GroupInviteRecipientField({
  groupSlug,
  disabled = false,
  submitting = false,
  error = null,
  emailCreditsLeft = null,
  onInvite,
  className,
}: Props) {
  const autoId = useId()
  const fieldId = `group-invite-${autoId}`
  const listId = `${fieldId}-results`
  const wrapRef = useRef<HTMLDivElement>(null)
  const [query, setQuery] = useState("")
  const [selected, setSelected] = useState<GroupInviteRecipientOption | null>(null)
  const [open, setOpen] = useState(false)
  const [searching, setSearching] = useState(false)
  const [results, setResults] = useState<GroupInviteRecipientOption[]>([])

  useEffect(() => {
    const q = query.trim()
    if (selected && q === selected.email) {
      setResults([])
      setSearching(false)
      return
    }
    if (q.length < 2) {
      setResults([])
      setSearching(false)
      return
    }

    const controller = new AbortController()
    const handle = window.setTimeout(() => {
      setSearching(true)
      const url = route("groups.invite-recipients", groupSlug) + `?q=${encodeURIComponent(q)}`
      fetch(url, {
        headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin",
        signal: controller.signal,
      })
        .then(async (res) => {
          if (!res.ok) {
            setResults([])
            return
          }
          const json = (await res.json()) as { results?: GroupInviteRecipientOption[] }
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
  }, [query, groupSlug, selected])

  useEffect(() => {
    const onPointerDown = (event: MouseEvent) => {
      if (!wrapRef.current?.contains(event.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener("mousedown", onPointerDown)
    return () => document.removeEventListener("mousedown", onPointerDown)
  }, [])

  const pick = (recipient: GroupInviteRecipientOption) => {
    setSelected(recipient)
    setQuery(recipient.email)
    setOpen(false)
    setResults([])
  }

  const clearSelected = () => {
    setSelected(null)
    setQuery("")
  }

  const submit = () => {
    if (selected) {
      onInvite({ user_id: selected.id, email: selected.email })
      setQuery("")
      setSelected(null)
      return
    }
    const email = query.trim()
    if (email.includes("@")) {
      onInvite({ email })
      setQuery("")
      setSelected(null)
    }
  }

  const canSubmit =
    !disabled &&
    !submitting &&
    (selected != null || (query.trim().includes("@") && query.trim().length > 3))

  return (
    <div ref={wrapRef} className={cn("space-y-3", className)}>
      <div className="flex items-start gap-3">
        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-blue-600 text-white shadow-sm shadow-purple-500/20">
          <UserPlus className="h-5 w-5" />
        </div>
        <div className="min-w-0 flex-1">
          <p className="text-[15px] font-bold text-slate-900 dark:text-white">Invite people</p>
          <p className="text-[13px] text-slate-500 dark:text-slate-400">
            Search members on Believe In Unity, or send an email invite link.
          </p>
        </div>
      </div>

      {selected ? (
        <div className="flex items-center gap-3 rounded-xl border border-purple-200 bg-white px-3 py-2.5 dark:border-purple-500/30 dark:bg-[#0a0f1a]">
          {selected.image ? (
            <img src={selected.image} alt="" className="h-10 w-10 rounded-full object-cover" />
          ) : (
            <span className="flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-purple-100 to-blue-100 text-purple-700 dark:from-purple-500/30 dark:to-blue-500/30 dark:text-purple-100">
              <UserRound className="h-4 w-4" />
            </span>
          )}
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{selected.name}</p>
              {selected.already_invited ? (
                <span className="shrink-0 rounded-full bg-gradient-to-r from-purple-600 to-blue-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">
                  Invited
                </span>
              ) : null}
            </div>
            <p className="truncate text-xs text-slate-500 dark:text-slate-400">{selected.email}</p>
          </div>
          <button
            type="button"
            className="cursor-pointer text-[13px] font-semibold text-purple-700 hover:underline dark:text-purple-300"
            onClick={clearSelected}
            disabled={submitting}
          >
            Change
          </button>
        </div>
      ) : (
        <div className="relative">
          <Search className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-purple-400" />
          <Input
            id={fieldId}
            type="text"
            inputMode="email"
            autoComplete="off"
            role="combobox"
            aria-expanded={open && results.length > 0}
            aria-controls={listId}
            placeholder="Search by name or email…"
            value={query}
            onChange={(e) => {
              setSelected(null)
              setQuery(e.target.value)
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
                  submit()
                }
              }
              if (e.key === "Escape") {
                setOpen(false)
              }
            }}
            className="h-11 rounded-xl border-0 border-transparent bg-white pl-10 pr-10 text-[15px] text-slate-900 shadow-none placeholder:text-slate-400 focus-visible:ring-2 focus-visible:ring-purple-200 focus-visible:ring-offset-0 dark:bg-[#0a0f1a] dark:text-white dark:placeholder:text-slate-500 dark:focus-visible:ring-purple-500/30"
            disabled={disabled || submitting}
          />
          {searching ? (
            <Loader2 className="absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-purple-400" />
          ) : query.includes("@") ? (
            <Mail className="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-blue-500" />
          ) : null}

          {open && results.length > 0 ? (
            <ul
              id={listId}
              role="listbox"
              className="absolute left-0 right-0 top-full z-[80] mt-1.5 max-h-60 w-full overflow-auto rounded-xl border border-purple-100 bg-white p-1.5 shadow-lg shadow-purple-500/10 dark:border-purple-500/25 dark:bg-[#0f172a]"
            >
              {results.map((recipient) => (
                <li key={recipient.id} role="option">
                  <button
                    type="button"
                    className="flex w-full cursor-pointer items-center gap-3 rounded-lg px-2.5 py-2 text-left transition-colors hover:bg-gradient-to-r hover:from-purple-50 hover:to-blue-50 dark:hover:from-purple-500/15 dark:hover:to-blue-500/15"
                    onClick={() => pick(recipient)}
                  >
                    {recipient.image ? (
                      <img src={recipient.image} alt="" className="h-9 w-9 rounded-full object-cover" />
                    ) : (
                      <span className="flex h-9 w-9 items-center justify-center rounded-full bg-gradient-to-br from-purple-100 to-blue-100 text-purple-700 dark:from-purple-500/30 dark:to-blue-500/30 dark:text-purple-100">
                        <UserRound className="h-4 w-4" />
                      </span>
                    )}
                    <span className="min-w-0 flex-1">
                      <span className="flex items-center gap-2">
                        <span className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                          {recipient.name}
                        </span>
                        {recipient.already_invited ? (
                          <span className="shrink-0 rounded-full bg-gradient-to-r from-purple-600 to-blue-600 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white">
                            Invited
                          </span>
                        ) : null}
                      </span>
                      <span className="block truncate text-xs text-slate-500 dark:text-slate-400">
                        {recipient.email}
                      </span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      )}

      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-[12px] leading-snug text-slate-500 dark:text-slate-400">
          {emailCreditsLeft !== null ? (
            <>
              Uses 1 email credit ·{" "}
              <span
                className={
                  emailCreditsLeft < 1
                    ? "font-semibold text-red-600 dark:text-red-400"
                    : "font-semibold text-purple-700 dark:text-purple-300"
                }
              >
                {emailCreditsLeft} left
              </span>
              {emailCreditsLeft < 1 ? " — buy credits to send invites." : null}
              {selected?.already_invited ? " · Already invited; send again to resend email." : null}
            </>
          ) : selected ? (
            selected.already_invited
              ? "Already invited — sending again resends email and uses 1 credit."
              : "Ready to send an invite to this supporter."
          ) : (
            "Type at least 2 letters to search, or enter a full email."
          )}
        </p>
        <Button
          type="button"
          className="h-10 cursor-pointer gap-1.5 rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 px-4 text-sm font-bold text-white shadow-sm shadow-purple-500/20 hover:from-purple-700 hover:to-blue-700 disabled:opacity-50"
          onClick={submit}
          disabled={!canSubmit || (emailCreditsLeft !== null && emailCreditsLeft < 1)}
        >
          {submitting ? (
            <>
              <Loader2 className="h-4 w-4 animate-spin" />
              Sending…
            </>
          ) : (
            <>
              <Send className="h-4 w-4" />
              Send invite
            </>
          )}
        </Button>
      </div>

      {error ? <p className="text-sm text-red-600 dark:text-red-400">{error}</p> : null}
    </div>
  )
}
