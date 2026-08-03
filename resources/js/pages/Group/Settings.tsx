"use client"

import { FormEvent, useState, type ReactNode } from "react"
import { Head, Link, router, useForm } from "@inertiajs/react"
import AppLayout from "@/layouts/app-layout"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { ArrowLeft, Trash2 } from "lucide-react"
import GroupJoinPolicyCards from "@/components/community/GroupJoinPolicyCards"

type Props = {
  group: {
    id: number
    name: string
    slug: string
    description: string | null
    category: string | null
    cover_image: string | null
    icon_image: string | null
    visibility: string
    join_policy: string
    posting_policy: string
    posting_policies?: string[]
    rules: string[]
    allow_photos: boolean
    allow_videos: boolean
    allow_documents: boolean
    allow_polls: boolean
    allow_events: boolean
    parent: { type: string; id: number; name: string | null } | null
  }
  categories: string[]
  visibilityOptions: Record<string, string>
  joinPolicyOptions: Record<string, string>
  postingPolicyOptions: Record<string, string>
  ruleExamples: string[]
  canDelete: boolean
}

function Section({
  title,
  description,
  children,
  danger = false,
}: {
  title: string
  description?: string
  children: ReactNode
  danger?: boolean
}) {
  return (
    <section
      className={`rounded-xl border p-4 sm:p-5 ${
        danger ? "border-red-200 bg-red-50/40 dark:border-red-500/30 dark:bg-red-500/5" : "border-border bg-card"
      }`}
    >
      <div className="mb-4">
        <h2 className={`text-sm font-semibold ${danger ? "text-red-700 dark:text-red-300" : "text-foreground"}`}>
          {title}
        </h2>
        {description ? (
          <p className={`mt-0.5 text-xs ${danger ? "text-red-600/80 dark:text-red-300/80" : "text-muted-foreground"}`}>
            {description}
          </p>
        ) : null}
      </div>
      {children}
    </section>
  )
}

function ChoiceGrid({
  name,
  options,
  value,
  onChange,
  columns = "sm:grid-cols-3",
}: {
  name: string
  options: Record<string, string>
  value: string
  onChange: (next: string) => void
  columns?: string
}) {
  return (
    <div className={`grid grid-cols-1 gap-2 ${columns}`}>
      {Object.entries(options).map(([optionValue, label]) => {
        const selected = value === optionValue
        return (
          <label
            key={optionValue}
            className={`flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2.5 text-sm transition-colors ${
              selected
                ? "border-purple-500 bg-purple-50/80 font-medium text-purple-900 dark:border-purple-400 dark:bg-purple-500/15 dark:text-purple-100"
                : "border-border hover:bg-muted/40"
            }`}
          >
            <input
              type="radio"
              name={name}
              className="cursor-pointer accent-purple-600"
              checked={selected}
              onChange={() => onChange(optionValue)}
            />
            <span>{label}</span>
          </label>
        )
      })}
    </div>
  )
}

export default function GroupSettings({
  group,
  categories,
  visibilityOptions,
  joinPolicyOptions,
  postingPolicyOptions,
  ruleExamples,
  canDelete,
}: Props) {
  const { data, setData, post, processing, errors, transform } = useForm<{
    name: string
    description: string
    category: string
    visibility: string
    join_policy: string
    posting_policies: string[]
    rules: string[]
    allow_photos: boolean
    allow_videos: boolean
    allow_documents: boolean
    allow_polls: boolean
    allow_events: boolean
    cover_image: File | null
    icon_image: File | null
  }>({
    name: group.name,
    description: group.description ?? "",
    category: group.category ?? "",
    visibility: group.visibility ?? "public",
    join_policy: group.join_policy ?? "anyone",
    posting_policies:
      group.posting_policies && group.posting_policies.length > 0
        ? group.posting_policies
        : [group.posting_policy ?? "members"],
    rules: group.rules ?? [],
    allow_photos: group.allow_photos,
    allow_videos: group.allow_videos,
    allow_documents: group.allow_documents,
    allow_polls: group.allow_polls,
    allow_events: group.allow_events,
    cover_image: null,
    icon_image: null,
  })
  const [coverPreview, setCoverPreview] = useState<string | null>(group.cover_image)
  const [iconPreview, setIconPreview] = useState<string | null>(group.icon_image)
  const [customRule, setCustomRule] = useState("")
  const [deleting, setDeleting] = useState(false)

  const postingPolicies = Array.isArray(data.posting_policies)
    ? data.posting_policies
    : group.posting_policies && group.posting_policies.length > 0
      ? group.posting_policies
      : [group.posting_policy ?? "members"]

  const togglePostingPolicy = (value: string) => {
    const selected = postingPolicies.includes(value)
    const next = selected
      ? postingPolicies.filter((p) => p !== value)
      : [...postingPolicies, value]
    setData("posting_policies", next.length > 0 ? next : [value])
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
    const policies =
      Array.isArray(data.posting_policies) && data.posting_policies.length > 0
        ? data.posting_policies
        : ["members"]
    setData("posting_policies", policies)
    transform((form) => ({
      ...form,
      posting_policies: policies,
      rules: Array.isArray(form.rules) ? form.rules : [],
    }))
    post(route("groups.settings.update", group.slug), { forceFormData: true })
  }

  const deleteGroup = () => {
    const confirmed = window.confirm(
      `Permanently delete “${group.name}”? This removes the group, members, and all posts. This cannot be undone.`,
    )
    if (!confirmed) {
      return
    }
    setDeleting(true)
    router.delete(route("groups.destroy", group.slug), {
      onFinish: () => setDeleting(false),
    })
  }

  const mediaOptions = [
    ["allow_photos", "Photos"],
    ["allow_videos", "Videos"],
    ["allow_documents", "Documents"],
    ["allow_polls", "Polls"],
    ["allow_events", "Events"],
  ] as const

  const customRules = data.rules.filter((rule) => !ruleExamples.includes(rule))

  return (
    <AppLayout
      breadcrumbs={[
        { title: "Groups", href: "/organization/groups" },
        { title: group.name, href: route("groups.show", group.slug) },
        { title: "Edit", href: route("groups.settings", group.slug) },
      ]}
    >
      <Head title={`Edit · ${group.name}`} />
      <div className="w-full space-y-5 p-4 sm:p-6">
        <div>
          <Link
            href={route("groups.show", group.slug)}
            className="mb-3 inline-flex cursor-pointer items-center text-sm text-muted-foreground hover:text-foreground"
          >
            <ArrowLeft className="mr-2 h-4 w-4" />
            Back to group
          </Link>
          <h1 className="text-2xl font-semibold tracking-tight">Edit group</h1>
          <p className="text-sm text-muted-foreground">Update settings or permanently delete this group.</p>
        </div>

        <Card className="overflow-hidden border-border shadow-sm">
          {group.parent?.name && (
            <CardHeader className="border-b border-border bg-muted/20 py-4">
              <CardDescription className="text-xs font-semibold uppercase tracking-wide">Hosted by</CardDescription>
              <CardTitle className="mt-1 text-base font-semibold">{group.parent.name}</CardTitle>
            </CardHeader>
          )}

          <CardContent className="p-4 sm:p-5">
            <form onSubmit={submit} className="space-y-4">
              <Section title="Basics" description="Name, type, category, and description.">
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <Label htmlFor="name">Group Name *</Label>
                    <Input
                      id="name"
                      className="mt-1.5"
                      value={data.name}
                      onChange={(e) => setData("name", e.target.value)}
                      required
                    />
                    {errors.name && <p className="mt-1 text-sm text-red-600">{errors.name}</p>}
                  </div>
                  <div>
                    <Label htmlFor="category">Category *</Label>
                    <select
                      id="category"
                      className="mt-1.5 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                      value={data.category}
                      onChange={(e) => setData("category", e.target.value)}
                      required
                    >
                      <option value="">Select a category</option>
                      {categories.map((c) => (
                        <option key={c} value={c}>
                          {c}
                        </option>
                      ))}
                    </select>
                    {errors.category && <p className="mt-1 text-sm text-red-600">{errors.category}</p>}
                  </div>
                  <div className="sm:col-span-2">
                    <Label className="mb-2 block">Group Type *</Label>
                    <ChoiceGrid
                      name="visibility"
                      options={visibilityOptions}
                      value={data.visibility}
                      onChange={setVisibility}
                    />
                  </div>
                  <div className="sm:col-span-2">
                    <Label htmlFor="description">Description *</Label>
                    <Textarea
                      id="description"
                      className="mt-1.5"
                      rows={3}
                      value={data.description}
                      onChange={(e) => setData("description", e.target.value)}
                      placeholder="Tell members what this group is about, who should join, and what conversations belong here."
                      required
                    />
                    {errors.description && (
                      <p className="mt-1 text-sm text-red-600">{errors.description}</p>
                    )}
                  </div>
                </div>
              </Section>

              <Section title="Images" description="Upload a new cover or icon to replace the current one.">
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <Label htmlFor="cover_image">Cover Image</Label>
                    <Input
                      id="cover_image"
                      type="file"
                      accept="image/*"
                      className="mt-1.5 cursor-pointer"
                      onChange={(e) => {
                        const file = e.target.files?.[0] ?? null
                        setData("cover_image", file)
                        if (file) setCoverPreview(URL.createObjectURL(file))
                      }}
                    />
                    {coverPreview && (
                      <img
                        src={coverPreview}
                        alt="Cover preview"
                        className="mt-2 h-28 w-full rounded-lg object-cover"
                      />
                    )}
                  </div>
                  <div>
                    <Label htmlFor="icon_image">Group Icon</Label>
                    <Input
                      id="icon_image"
                      type="file"
                      accept="image/*"
                      className="mt-1.5 cursor-pointer"
                      onChange={(e) => {
                        const file = e.target.files?.[0] ?? null
                        setData("icon_image", file)
                        if (file) setIconPreview(URL.createObjectURL(file))
                      }}
                    />
                    {iconPreview && (
                      <img
                        src={iconPreview}
                        alt="Icon preview"
                        className="mt-2 h-20 w-20 rounded-2xl object-cover"
                      />
                    )}
                  </div>
                </div>
              </Section>

              <Section
                title="Who can join?"
                description="Choose who is allowed to become a member of this group."
              >
                <GroupJoinPolicyCards
                  value={data.join_policy}
                  options={joinPolicyOptions}
                  parentLabel={
                    group.parent?.type === "UnityImpactAlliance" || group.parent?.type === "CareAlliance"
                      ? "Unity Impact Alliance"
                      : "Organization"
                  }
                  compact
                  showHeading={false}
                  onChange={(value) => setData("join_policy", value)}
                />
              </Section>

              <Section
                title="Who can create posts? *"
                description="Select one or more. A member can post if they match any selected option."
              >
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                  {Object.entries(postingPolicyOptions).map(([value, label]) => {
                    const selected = postingPolicies.includes(value)
                    return (
                      <button
                        key={value}
                        type="button"
                        onClick={() => togglePostingPolicy(value)}
                        className={`flex cursor-pointer items-center gap-2.5 rounded-lg border px-3 py-2.5 text-left text-sm transition-colors ${
                          selected
                            ? "border-purple-500 bg-purple-50/80 font-medium text-purple-900 dark:border-purple-400 dark:bg-purple-500/15 dark:text-purple-100"
                            : "border-border hover:bg-muted/40"
                        }`}
                      >
                        <span
                          className={`flex h-[14px] w-[14px] shrink-0 items-center justify-center rounded-full border-2 ${
                            selected
                              ? "border-purple-600 bg-purple-600 dark:border-purple-400 dark:bg-purple-400"
                              : "border-slate-300 dark:border-slate-500"
                          }`}
                          aria-hidden
                        >
                          {selected ? <span className="h-1.5 w-1.5 rounded-full bg-white" /> : null}
                        </span>
                        <span>{label}</span>
                      </button>
                    )
                  })}
                </div>
                {errors.posting_policies && (
                  <p className="mt-2 text-sm text-red-600">{errors.posting_policies}</p>
                )}
              </Section>

              <div className="grid gap-4 lg:grid-cols-2">
                <Section title="Group Rules (optional)">
                  <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    {ruleExamples.map((rule) => (
                      <label
                        key={rule}
                        className="flex cursor-pointer items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm hover:bg-muted/40"
                      >
                        <input
                          type="checkbox"
                          className="cursor-pointer accent-purple-600"
                          checked={data.rules.includes(rule)}
                          onChange={() => toggleRule(rule)}
                        />
                        {rule}
                      </label>
                    ))}
                    {customRules.map((rule) => (
                      <label
                        key={rule}
                        className="flex cursor-pointer items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm hover:bg-muted/40"
                      >
                        <input
                          type="checkbox"
                          className="cursor-pointer accent-purple-600"
                          checked
                          onChange={() => toggleRule(rule)}
                        />
                        {rule}
                      </label>
                    ))}
                  </div>
                  <div className="mt-3 flex gap-2">
                    <Input
                      value={customRule}
                      onChange={(e) => setCustomRule(e.target.value)}
                      placeholder="Add another rule"
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
                      className="shrink-0 cursor-pointer"
                      onClick={addCustomRule}
                    >
                      Add
                    </Button>
                  </div>
                </Section>

                <Section title="File sharing">
                  <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {mediaOptions.map(([key, label]) => (
                      <label
                        key={key}
                        className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm ${
                          data[key]
                            ? "border-purple-500/40 bg-purple-50/60 dark:border-purple-400/40 dark:bg-purple-500/10"
                            : "border-border hover:bg-muted/40"
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
                </Section>
              </div>

              <div className="sticky bottom-0 z-10 -mx-4 flex flex-wrap justify-end gap-3 border-t border-border bg-background/95 px-4 py-3 backdrop-blur sm:-mx-5 sm:px-5">
                <Button type="button" variant="outline" className="cursor-pointer" asChild>
                  <Link href={route("groups.show", group.slug)}>Cancel</Link>
                </Button>
                <Button
                  type="submit"
                  disabled={processing}
                  className="cursor-pointer bg-gradient-to-r from-purple-600 to-blue-600 font-semibold text-white"
                >
                  {processing ? "Saving…" : "Save changes"}
                </Button>
              </div>
            </form>

            {canDelete && (
              <div className="mt-4">
                <Section
                  title="Delete group"
                  description="Permanently removes this group, its members, and all posts. This cannot be undone."
                  danger
                >
                  <Button
                    type="button"
                    variant="destructive"
                    className="cursor-pointer"
                    disabled={deleting}
                    onClick={deleteGroup}
                  >
                    <Trash2 className="mr-2 h-4 w-4" />
                    {deleting ? "Deleting…" : "Delete group permanently"}
                  </Button>
                </Section>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </AppLayout>
  )
}
