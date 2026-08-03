"use client"

import { FormEvent, useState, type ReactNode } from "react"
import { Head, Link, router, useForm } from "@inertiajs/react"
import AppLayout from "@/layouts/app-layout"
import FrontendLayout from "@/layouts/frontend/frontend-layout"
import { Button } from "@/components/ui/button"
import { Input } from "@/components/ui/input"
import { Textarea } from "@/components/ui/textarea"
import {
  ArrowLeft,
  Building2,
  Camera,
  Check,
  ChevronDown,
  EyeOff,
  Globe2,
  HeartHandshake,
  ImagePlus,
  Lock,
  UsersRound,
} from "lucide-react"

type ParentOption = { type: string; id: number; name: string; label: string }

type Props = {
  parent: ParentOption | null
  parents?: ParentOption[]
  categories: string[]
  visibilityOptions: Record<string, string>
  joinPolicyOptions: Record<string, string>
  postingPolicyOptions: Record<string, string>
  ruleExamples: string[]
  backUrl?: string
  consumer?: boolean
}

const visibilityMeta: Record<
  string,
  { icon: typeof Globe2; hint: string }
> = {
  public: {
    icon: Globe2,
    hint: "Anyone can find the group and see who’s in it and what they post.",
  },
  private: {
    icon: Lock,
    hint: "Anyone can find the group, but only members see posts.",
  },
  hidden: {
    icon: EyeOff,
    hint: "Only members and people invited can find the group.",
  },
}

function FieldBlock({
  title,
  children,
}: {
  title: string
  children: ReactNode
}) {
  return (
    <div className="space-y-2">
      <p className="text-[13px] font-semibold text-slate-800 dark:text-slate-200">{title}</p>
      {children}
    </div>
  )
}

export default function GroupCreate({
  parent,
  parents = [],
  categories,
  visibilityOptions,
  joinPolicyOptions,
  postingPolicyOptions,
  ruleExamples,
  backUrl = "/groups",
  consumer = false,
}: Props) {
  const { data, setData, post, processing, errors } = useForm<{
    parent_type: string
    parent_id: number
    name: string
    description: string
    category: string
    visibility: string
    join_policy: string
    posting_policy: string
    rules: string[]
    allow_photos: boolean
    allow_videos: boolean
    allow_documents: boolean
    allow_polls: boolean
    allow_events: boolean
    cover_image: File | null
    icon_image: File | null
  }>({
    parent_type: parent?.type ?? "",
    parent_id: parent?.id ?? 0,
    name: "",
    description: "",
    category: "",
    visibility: "public",
    join_policy: "anyone",
    posting_policy: "members",
    rules: [],
    allow_photos: true,
    allow_videos: true,
    allow_documents: true,
    allow_polls: true,
    allow_events: true,
    cover_image: null,
    icon_image: null,
  })
  const [coverPreview, setCoverPreview] = useState<string | null>(null)
  const [iconPreview, setIconPreview] = useState<string | null>(null)
  const [customRule, setCustomRule] = useState("")
  const [categoryOpen, setCategoryOpen] = useState(false)
  const [step, setStep] = useState<1 | 2 | 3>(1)

  const selectParent = (option: ParentOption) => {
    router.get(
      route("groups.create"),
      {
        parent_type: option.type,
        parent_id: option.id,
        ...(consumer ? {} : { org_dashboard: 1 }),
      },
      { preserveScroll: true },
    )
  }

  if (!parent) {
    const picker = (
      <>
        <Head title="Create a Group" />
        <div className="min-h-[calc(100vh-4rem)] bg-gradient-to-b from-purple-50 via-blue-50/40 to-slate-50 dark:from-[#0a0f1a] dark:via-[#0c1222] dark:to-[#0a0f1a]">
          <div className="mx-auto max-w-[95rem] px-4 py-8 sm:px-6 lg:px-8">
            <div className="mb-6 flex items-center gap-3">
              <Link
                href={backUrl}
                className="inline-flex h-10 w-10 items-center justify-center rounded-full border border-purple-100 bg-white text-purple-700 hover:bg-purple-50 dark:border-purple-500/20 dark:bg-[#111827] dark:text-purple-200"
              >
                <ArrowLeft className="h-4 w-4" />
              </Link>
              <div>
                <h1 className="bg-gradient-to-r from-purple-700 to-blue-700 bg-clip-text text-2xl font-bold tracking-tight text-transparent sm:text-3xl dark:from-purple-300 dark:to-blue-300">
                  Create a group
                </h1>
                <p className="mt-1 text-sm text-slate-600 dark:text-slate-400">
                  Choose an organization or Unity Impact Alliance to host your group.
                </p>
              </div>
            </div>

            <div className="space-y-3">
              {parents.map((option) => {
                const Icon = option.label === "Unity Impact Alliance" ? HeartHandshake : Building2
                return (
                  <button
                    key={`${option.type}-${option.id}`}
                    type="button"
                    onClick={() => selectParent(option)}
                    className="flex w-full cursor-pointer items-center gap-4 rounded-2xl border border-purple-100 bg-white p-4 text-left shadow-sm shadow-purple-500/5 transition hover:-translate-y-0.5 hover:border-purple-300 hover:shadow-md hover:shadow-purple-500/10 dark:border-purple-500/20 dark:bg-[#111827] dark:hover:border-purple-500/40"
                  >
                    <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-purple-600 to-blue-600 text-white shadow-md">
                      <Icon className="h-6 w-6" />
                    </div>
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-semibold text-slate-900 dark:text-white">{option.name}</p>
                      <p className="text-sm text-slate-500 dark:text-slate-400">{option.label}</p>
                    </div>
                  </button>
                )
              })}
            </div>
          </div>
        </div>
      </>
    )

    if (consumer) {
      return <FrontendLayout>{picker}</FrontendLayout>
    }

    return (
      <AppLayout breadcrumbs={[{ title: "Groups", href: backUrl }, { title: "Create Group" }]}>
        {picker}
      </AppLayout>
    )
  }

  const setVisibility = (value: string) => {
    setData({
      ...data,
      visibility: value,
      join_policy: value === "hidden" ? "invite_only" : data.join_policy,
    })
  }

  const toggleRule = (rule: string) => {
    setData(
      "rules",
      data.rules.includes(rule) ? data.rules.filter((r) => r !== rule) : [...data.rules, rule],
    )
  }

  const addCustomRule = () => {
    const trimmed = customRule.trim()
    if (!trimmed || data.rules.includes(trimmed)) {
      return
    }
    setData("rules", [...data.rules, trimmed])
    setCustomRule("")
  }

  const submit = (e: FormEvent) => {
    e.preventDefault()
    post(route("groups.store"), { forceFormData: true })
  }

  const mediaOptions = [
    ["allow_photos", "Photos"],
    ["allow_videos", "Videos"],
    ["allow_documents", "Documents"],
    ["allow_polls", "Polls"],
    ["allow_events", "Events"],
  ] as const

  const customRules = data.rules.filter((rule) => !ruleExamples.includes(rule))
  const previewName = data.name.trim() || "Group name"
  const previewVisibility = visibilityOptions[data.visibility] ?? "Public"
  const PreviewVisibilityIcon = visibilityMeta[data.visibility]?.icon ?? Globe2

  const step1Ready = Boolean(data.name.trim() && data.category && data.description.trim())
  const step2Ready = Boolean(data.cover_image)
  const steps = [
    { id: 1 as const, label: "Basics" },
    { id: 2 as const, label: "Look" },
    { id: 3 as const, label: "Options" },
  ]

  const goNext = () => {
    if (step === 1 && step1Ready) setStep(2)
    else if (step === 2 && step2Ready) setStep(3)
  }

  const content = (
    <>
      <Head title="Create a Group" />
      <div className="min-h-[calc(100vh-4rem)] bg-gradient-to-b from-purple-50/80 via-slate-50 to-blue-50/60 dark:from-[#0b1220] dark:via-[#0f172a] dark:to-[#0b1220]">
        <div className="mx-auto grid w-full max-w-[95rem] gap-5 px-4 py-6 sm:px-6 lg:grid-cols-[minmax(0,28rem)_minmax(0,1fr)] lg:gap-8 lg:px-8 lg:py-8">
          <form
            onSubmit={(e) => {
              if (step !== 3) {
                e.preventDefault()
                goNext()
                return
              }
              submit(e)
            }}
            className="flex max-h-[calc(100vh-5.5rem)] flex-col overflow-hidden rounded-2xl border border-purple-100 bg-white shadow-sm shadow-purple-500/5 dark:border-purple-500/20 dark:bg-[#111827]"
          >
            <div className="shrink-0 border-b border-purple-50 px-4 py-3 dark:border-purple-500/15">
              <div className="flex items-center gap-3">
                <Link
                  href={backUrl}
                  className="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-full bg-purple-50 text-purple-700 hover:bg-purple-100 dark:bg-purple-500/15 dark:text-purple-200"
                >
                  <ArrowLeft className="h-4 w-4" />
                </Link>
                <div className="min-w-0 flex-1">
                  <h1 className="text-[20px] font-bold leading-tight text-slate-900 dark:text-white">
                    Create group
                  </h1>
                  <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                    {parent.name} · {parent.label}
                    {parents.length > 1 ? (
                      <>
                        {" · "}
                        <button
                          type="button"
                          onClick={() =>
                            router.get(route("groups.create"), consumer ? {} : { org_dashboard: 1 })
                          }
                          className="cursor-pointer font-semibold text-purple-700 hover:underline dark:text-purple-300"
                        >
                          Change
                        </button>
                      </>
                    ) : null}
                  </p>
                </div>
              </div>

              <div className="mt-3 grid grid-cols-3 gap-1.5">
                {steps.map((s) => {
                  const active = step === s.id
                  const done = step > s.id
                  return (
                    <button
                      key={s.id}
                      type="button"
                      onClick={() => {
                        if (s.id === 1) setStep(1)
                        else if (s.id === 2 && step1Ready) setStep(2)
                        else if (s.id === 3 && step1Ready && step2Ready) setStep(3)
                      }}
                      className={`cursor-pointer rounded-lg px-2 py-1.5 text-center text-[11px] font-bold transition ${
                        active
                          ? "bg-gradient-to-r from-purple-600 to-blue-600 text-white"
                          : done
                            ? "bg-gradient-to-r from-purple-100 to-blue-100 text-purple-800 dark:from-purple-500/25 dark:to-blue-500/25 dark:text-purple-100"
                            : "bg-slate-100 text-slate-500 dark:bg-white/5 dark:text-slate-400"
                      }`}
                    >
                      {s.id}. {s.label}
                    </button>
                  )
                })}
              </div>
            </div>

            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4">
              {step === 1 && (
                <>
                  <FieldBlock title="Group name">
                    <Input
                      className="h-11 rounded-xl border-0 bg-gradient-to-r from-purple-50 to-blue-50 px-3.5 text-[17px] font-semibold text-slate-900 shadow-none placeholder:font-normal placeholder:text-slate-400 focus-visible:ring-2 focus-visible:ring-purple-200 focus-visible:ring-offset-0 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white dark:placeholder:text-slate-500 dark:focus-visible:ring-purple-500/30"
                      value={data.name}
                      onChange={(e) => setData("name", e.target.value)}
                      placeholder="Name your group"
                      required
                    />
                    {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                  </FieldBlock>

                  <FieldBlock title="Category">
                    <div className="relative">
                      <button
                        type="button"
                        onClick={() => setCategoryOpen((open) => !open)}
                        className="flex h-11 w-full cursor-pointer items-center justify-between rounded-xl border-0 bg-gradient-to-r from-purple-50 to-blue-50 px-3.5 text-left text-sm text-slate-900 focus:outline-none focus:ring-2 focus:ring-purple-200 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white dark:focus:ring-purple-500/30"
                      >
                        <span className={data.category ? "font-medium" : "text-slate-400 dark:text-slate-500"}>
                          {data.category || "Select a category"}
                        </span>
                        <ChevronDown
                          className={`h-4 w-4 shrink-0 text-purple-500 transition-transform dark:text-purple-300 ${
                            categoryOpen ? "rotate-180" : ""
                          }`}
                        />
                      </button>
                      {categoryOpen && (
                        <>
                          <div className="fixed inset-0 z-10" onClick={() => setCategoryOpen(false)} />
                          <div className="absolute z-20 mt-1.5 max-h-52 w-full overflow-y-auto rounded-xl border border-purple-100 bg-white py-1.5 shadow-xl dark:border-purple-500/25 dark:bg-[#0d1424]">
                            {categories.map((c) => {
                              const selected = data.category === c
                              return (
                                <button
                                  key={c}
                                  type="button"
                                  onClick={() => {
                                    setData("category", c)
                                    setCategoryOpen(false)
                                  }}
                                  className={`flex w-full cursor-pointer items-center justify-between px-3.5 py-2.5 text-left text-sm transition-colors ${
                                    selected
                                      ? "bg-gradient-to-r from-purple-50 to-blue-50 font-semibold text-purple-800 dark:from-purple-500/20 dark:to-blue-500/20 dark:text-purple-100"
                                      : "text-slate-700 hover:bg-purple-50 dark:text-slate-200 dark:hover:bg-purple-500/10"
                                  }`}
                                >
                                  {c}
                                  {selected && (
                                    <Check className="h-4 w-4 text-purple-600 dark:text-purple-300" />
                                  )}
                                </button>
                              )
                            })}
                          </div>
                        </>
                      )}
                    </div>
                    {errors.category && <p className="text-sm text-red-600">{errors.category}</p>}
                  </FieldBlock>

                  <FieldBlock title="Privacy">
                    <div className="grid grid-cols-3 gap-2">
                      {Object.entries(visibilityOptions).map(([value, label]) => {
                        const meta = visibilityMeta[value]
                        const Icon = meta?.icon ?? Globe2
                        const selected = data.visibility === value
                        return (
                          <button
                            key={value}
                            type="button"
                            title={meta?.hint}
                            onClick={() => setVisibility(value)}
                            className={`flex cursor-pointer flex-col items-center gap-1.5 rounded-xl border px-2 py-2.5 text-center transition ${
                              selected
                                ? "border-purple-500 bg-gradient-to-b from-purple-50 to-blue-50 dark:border-purple-400 dark:from-purple-500/20 dark:to-blue-500/15"
                                : "border-purple-100 hover:bg-purple-50/50 dark:border-purple-500/20 dark:hover:bg-purple-500/10"
                            }`}
                          >
                            <span
                              className={`flex h-8 w-8 items-center justify-center rounded-full ${
                                selected
                                  ? "bg-gradient-to-br from-purple-600 to-blue-600 text-white"
                                  : "bg-slate-100 text-slate-600 dark:bg-white/10 dark:text-slate-300"
                              }`}
                            >
                              <Icon className="h-3.5 w-3.5" />
                            </span>
                            <span className="text-[11px] font-semibold text-slate-900 dark:text-white">
                              {label}
                            </span>
                          </button>
                        )
                      })}
                    </div>
                    {errors.visibility && <p className="text-sm text-red-600">{errors.visibility}</p>}
                  </FieldBlock>

                  <FieldBlock title="Description">
                    <Textarea
                      className="min-h-[84px] rounded-xl border-0 bg-gradient-to-r from-purple-50 to-blue-50 px-3.5 py-3 text-sm leading-relaxed text-slate-900 shadow-none placeholder:text-slate-400 focus-visible:ring-2 focus-visible:ring-purple-200 focus-visible:ring-offset-0 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white dark:placeholder:text-slate-500 dark:focus-visible:ring-purple-500/30"
                      value={data.description}
                      onChange={(e) => setData("description", e.target.value)}
                      placeholder="What’s this group about?"
                      required
                    />
                    {errors.description && <p className="text-sm text-red-600">{errors.description}</p>}
                  </FieldBlock>
                </>
              )}

              {step === 2 && (
                <>
                  <FieldBlock title="Cover photo">
                    <label className="relative flex h-36 cursor-pointer flex-col items-center justify-center overflow-hidden rounded-xl border border-dashed border-purple-200 bg-gradient-to-r from-purple-50/50 to-blue-50/50 text-center hover:from-purple-50 hover:to-blue-50 dark:border-purple-500/30 dark:from-purple-500/10 dark:to-blue-500/10">
                      {coverPreview ? (
                        <>
                          <img src={coverPreview} alt="" className="absolute inset-0 h-full w-full object-cover" />
                          <span className="relative z-10 rounded-full bg-black/55 px-3 py-1 text-xs font-semibold text-white">
                            Change cover
                          </span>
                        </>
                      ) : (
                        <>
                          <span className="mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-purple-600 to-blue-600 text-white">
                            <ImagePlus className="h-5 w-5" />
                          </span>
                          <span className="text-sm font-semibold text-purple-700 dark:text-purple-300">
                            Upload cover photo
                          </span>
                          <span className="mt-1 text-[11px] text-slate-500">Required · 1640×856 looks best</span>
                        </>
                      )}
                      <input
                        type="file"
                        accept="image/*"
                        className="hidden"
                        required={!data.cover_image}
                        onChange={(e) => {
                          const file = e.target.files?.[0] ?? null
                          setData("cover_image", file)
                          setCoverPreview(file ? URL.createObjectURL(file) : null)
                        }}
                      />
                    </label>
                    {errors.cover_image && <p className="text-sm text-red-600">{errors.cover_image}</p>}
                  </FieldBlock>

                  <FieldBlock title="Group icon (optional)">
                    <label className="inline-flex cursor-pointer items-center gap-3">
                      <span className="flex h-14 w-14 items-center justify-center overflow-hidden rounded-2xl border border-purple-100 bg-gradient-to-br from-purple-50 to-blue-50 dark:border-purple-500/20 dark:from-purple-500/15 dark:to-blue-500/15">
                        {iconPreview ? (
                          <img src={iconPreview} alt="" className="h-full w-full object-cover" />
                        ) : (
                          <Camera className="h-5 w-5 text-purple-500" />
                        )}
                      </span>
                      <span className="text-sm font-semibold text-purple-700 dark:text-purple-300">
                        {iconPreview ? "Change icon" : "Add icon"}
                      </span>
                      <input
                        type="file"
                        accept="image/*"
                        className="hidden"
                        onChange={(e) => {
                          const file = e.target.files?.[0] ?? null
                          setData("icon_image", file)
                          setIconPreview(file ? URL.createObjectURL(file) : null)
                        }}
                      />
                    </label>
                    {errors.icon_image && <p className="text-sm text-red-600">{errors.icon_image}</p>}
                  </FieldBlock>

                  <p className="rounded-xl bg-gradient-to-r from-purple-50 to-blue-50 px-3 py-2 text-xs text-slate-600 dark:from-purple-500/10 dark:to-blue-500/10 dark:text-slate-300">
                    Preview updates live on the right as you upload.
                  </p>
                </>
              )}

              {step === 3 && (
                <>
                  <p className="text-xs text-slate-500 dark:text-slate-400">
                    Optional — you can skip and adjust later in group settings.
                  </p>

                  <FieldBlock title="Who can join?">
                    <div className="flex flex-wrap gap-2">
                      {Object.entries(joinPolicyOptions).map(([value, label]) => {
                        const selected = data.join_policy === value
                        return (
                          <button
                            key={value}
                            type="button"
                            onClick={() => setData("join_policy", value)}
                            className={`cursor-pointer rounded-full border px-3 py-1.5 text-xs font-semibold transition ${
                              selected
                                ? "border-purple-500 bg-gradient-to-r from-purple-600 to-blue-600 text-white"
                                : "border-purple-100 bg-gradient-to-r from-purple-50 to-blue-50 text-purple-800 dark:border-purple-500/25 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-purple-100"
                            }`}
                          >
                            {label}
                          </button>
                        )
                      })}
                    </div>
                  </FieldBlock>

                  <FieldBlock title="Who can create posts?">
                    <div className="flex flex-wrap gap-2">
                      {Object.entries(postingPolicyOptions).map(([value, label]) => {
                        const selected = data.posting_policy === value
                        return (
                          <button
                            key={value}
                            type="button"
                            onClick={() => setData("posting_policy", value)}
                            className={`cursor-pointer rounded-full border px-3 py-1.5 text-xs font-semibold transition ${
                              selected
                                ? "border-purple-500 bg-gradient-to-r from-purple-600 to-blue-600 text-white"
                                : "border-purple-100 bg-gradient-to-r from-purple-50 to-blue-50 text-purple-800 dark:border-purple-500/25 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-purple-100"
                            }`}
                          >
                            {label}
                          </button>
                        )
                      })}
                    </div>
                  </FieldBlock>

                  <FieldBlock title="What members can share">
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                      {mediaOptions.map(([key, label]) => (
                        <label
                          key={key}
                          className={`flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-sm ${
                            data[key]
                              ? "border-purple-500/40 bg-purple-50/60 dark:border-purple-400/40 dark:bg-purple-500/10"
                              : "border-purple-100 dark:border-purple-500/20"
                          }`}
                        >
                          <input
                            type="checkbox"
                            className="cursor-pointer accent-purple-600"
                            checked={data[key]}
                            onChange={(e) => setData(key, e.target.checked)}
                          />
                          {label}
                        </label>
                      ))}
                    </div>
                  </FieldBlock>

                  <FieldBlock title="Group rules (optional)">
                    <div className="flex flex-wrap gap-2">
                      {ruleExamples.map((rule) => {
                        const selected = data.rules.includes(rule)
                        return (
                          <button
                            key={rule}
                            type="button"
                            onClick={() => toggleRule(rule)}
                            className={`cursor-pointer rounded-full border px-3 py-1.5 text-left text-[11px] font-medium transition ${
                              selected
                                ? "border-purple-500 bg-gradient-to-r from-purple-50 to-blue-50 text-purple-800 dark:from-purple-500/20 dark:to-blue-500/20 dark:text-purple-100"
                                : "border-purple-100 text-slate-600 hover:bg-purple-50/50 dark:border-purple-500/20 dark:text-slate-300"
                            }`}
                          >
                            {selected ? "✓ " : ""}
                            {rule}
                          </button>
                        )
                      })}
                      {customRules.map((rule) => (
                        <button
                          key={rule}
                          type="button"
                          onClick={() => toggleRule(rule)}
                          className="cursor-pointer rounded-full border border-purple-500 bg-gradient-to-r from-purple-50 to-blue-50 px-3 py-1.5 text-left text-[11px] font-medium text-purple-800 dark:from-purple-500/20 dark:to-blue-500/20 dark:text-purple-100"
                        >
                          ✓ {rule}
                        </button>
                      ))}
                    </div>
                    <div className="mt-2 flex gap-2">
                      <Input
                        value={customRule}
                        onChange={(e) => setCustomRule(e.target.value)}
                        placeholder="Add another rule"
                        className="h-9 rounded-xl border-0 bg-gradient-to-r from-purple-50 to-blue-50 text-sm text-slate-900 shadow-none focus-visible:ring-2 focus-visible:ring-purple-200 focus-visible:ring-offset-0 dark:from-purple-500/15 dark:to-blue-500/15 dark:text-white"
                        onKeyDown={(e) => {
                          if (e.key === "Enter") {
                            e.preventDefault()
                            addCustomRule()
                          }
                        }}
                      />
                      <Button
                        type="button"
                        variant="outline"
                        className="h-9 cursor-pointer rounded-xl border-purple-200"
                        onClick={addCustomRule}
                      >
                        Add
                      </Button>
                    </div>
                  </FieldBlock>
                </>
              )}
            </div>

            <div className="flex shrink-0 gap-2 border-t border-purple-50 p-4 dark:border-purple-500/15">
              {step > 1 && (
                <Button
                  type="button"
                  variant="outline"
                  className="h-11 cursor-pointer rounded-xl border-purple-200 px-4 font-semibold text-purple-800 dark:border-purple-500/30 dark:text-purple-200"
                  onClick={() => setStep((s) => (s === 3 ? 2 : 1))}
                >
                  Back
                </Button>
              )}
              {step < 3 ? (
                <>
                  <Button
                    type="button"
                    disabled={(step === 1 && !step1Ready) || (step === 2 && !step2Ready)}
                    className="h-11 flex-1 cursor-pointer rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 text-[15px] font-bold text-white disabled:opacity-50"
                    onClick={goNext}
                  >
                    {step === 1 ? "Continue" : "More options"}
                  </Button>
                  {step === 2 && (
                    <Button
                      type="button"
                      disabled={processing || !step1Ready || !step2Ready}
                      className="h-11 flex-1 cursor-pointer rounded-xl border border-purple-200 bg-white font-bold text-purple-800 hover:bg-purple-50 dark:border-purple-500/30 dark:bg-transparent dark:text-purple-100 dark:hover:bg-purple-500/10"
                      onClick={(e) => submit(e as unknown as FormEvent)}
                    >
                      {processing ? "Creating…" : "Create now"}
                    </Button>
                  )}
                </>
              ) : (
                <Button
                  type="submit"
                  disabled={processing || !step1Ready || !step2Ready}
                  className="h-11 flex-1 cursor-pointer rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 text-[15px] font-bold text-white shadow-sm disabled:opacity-50"
                >
                  {processing ? "Creating…" : "Create group"}
                </Button>
              )}
            </div>
          </form>

          {/* Right: live Facebook-style group preview */}
          <div className="hidden lg:block">
            <div className="sticky top-6 overflow-hidden rounded-2xl border border-purple-100 bg-white shadow-sm shadow-purple-500/5 dark:border-purple-500/20 dark:bg-[#111827]">
              <div className="border-b border-purple-50 px-4 py-3 dark:border-purple-500/15">
                <p className="text-[15px] font-bold text-slate-900 dark:text-white">Preview</p>
                <p className="text-xs text-slate-500">How your group will look</p>
              </div>

              <div className="relative h-44 bg-gradient-to-r from-purple-200 to-blue-200 dark:from-purple-900/40 dark:to-blue-900/40">
                {coverPreview ? (
                  <img src={coverPreview} alt="" className="h-full w-full object-cover" />
                ) : (
                  <div className="flex h-full items-center justify-center text-sm font-medium text-purple-700/70 dark:text-purple-200/60">
                    Cover photo
                  </div>
                )}
              </div>

              <div className="relative px-5 pb-5 pt-10">
                <div className="absolute -top-10 left-5 h-20 w-20 overflow-hidden rounded-2xl border-4 border-white bg-gradient-to-br from-purple-600 to-blue-600 shadow-md dark:border-[#111827]">
                  {iconPreview ? (
                    <img src={iconPreview} alt="" className="h-full w-full object-cover" />
                  ) : (
                    <div className="flex h-full w-full items-center justify-center text-white">
                      <UsersRound className="h-8 w-8" />
                    </div>
                  )}
                </div>

                <h2 className="truncate text-2xl font-bold text-slate-900 dark:text-white">
                  {previewName}
                </h2>
                <p className="mt-1 flex items-center gap-1.5 text-sm text-slate-500 dark:text-slate-400">
                  <PreviewVisibilityIcon className="h-3.5 w-3.5" />
                  {previewVisibility} group · 1 member
                </p>
                {data.description.trim() ? (
                  <p className="mt-3 line-clamp-3 text-sm text-slate-600 dark:text-slate-300">
                    {data.description}
                  </p>
                ) : (
                  <p className="mt-3 text-sm text-slate-400">Description will show here</p>
                )}

                <div className="mt-4 flex gap-2 border-t border-purple-50 pt-4 dark:border-purple-500/15">
                  <div className="h-9 flex-1 rounded-lg bg-gradient-to-r from-purple-600 to-blue-600 text-center text-sm font-semibold leading-9 text-white">
                    Join group
                  </div>
                  <div className="h-9 flex-1 rounded-lg bg-purple-50 text-center text-sm font-semibold leading-9 text-purple-700 dark:bg-purple-500/15 dark:text-purple-200">
                    Invite
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </>
  )

  if (consumer) {
    return <FrontendLayout>{content}</FrontendLayout>
  }

  return (
    <AppLayout
      breadcrumbs={[
        { title: "Groups", href: backUrl },
        { title: "Create Group", href: route("groups.create") },
      ]}
    >
      {content}
    </AppLayout>
  )
}
