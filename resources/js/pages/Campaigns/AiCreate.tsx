"use client"

import type React from "react"
import { Head, Link, router, useForm, usePage } from "@inertiajs/react"
import AppLayout from "@/layouts/app-layout"
import { useEffect, useState } from "react"
import { Calendar, Clock, FileText, Users, MessageSquare, Radio, Sparkles, AlertCircle, CheckCircle2, Mail } from "lucide-react"
import type { SharedData } from "@/types"
import { AiChatUsageCard } from "@/components/ai-chat/AiChatUsageCard"
import { AiTokensPurchaseModal } from "@/components/ai-chat/AiTokensPurchaseModal"
import {
  AiTokenPurchaseSuccessOverlay,
  isAiCreditPurchaseFlashSuccess,
} from "@/components/ai-chat/AiTokenPurchaseSuccessOverlay"
import { chatPrimaryButtonClass } from "@/components/chat/chat-brand"
import { cn } from "@/lib/utils"

const route = (name: string) => {
  const routes: Record<string, string> = {
    "campaigns.ai-store": "/campaigns/ai",
    "campaigns.index": "/campaigns",
  }
  return routes[name] || "/"
}

interface User {
  id: number
  name: string
  email: string
  contact_number?: string
  whatsapp_opt_in?: string
  push_token?: string
}

interface AiCampaignsCreateProps {
  defaultChannels: string[]
  users: User[]
}

const AiCampaignsCreate: React.FC<AiCampaignsCreateProps> = ({ defaultChannels, users }) => {
  const { auth, flash } = usePage<SharedData & { flash?: { success?: string } }>().props
  const aiTokensIncluded = (auth.user as { ai_tokens_included?: number }).ai_tokens_included ?? 0
  const aiTokensUsedRaw = (auth.user as { ai_tokens_used?: number }).ai_tokens_used ?? 0
  const aiTokensUsed =
    aiTokensIncluded > 0 ? Math.min(aiTokensUsedRaw, aiTokensIncluded) : aiTokensUsedRaw
  const hasAiTokensLeft = aiTokensIncluded === 0 || aiTokensUsed < aiTokensIncluded
  const percentTokensUsed =
    aiTokensIncluded > 0
      ? Math.min(100, Math.round((aiTokensUsed / aiTokensIncluded) * 100))
      : 0
  const aiTokensRemaining =
    aiTokensIncluded === 0 ? null : Math.max(0, aiTokensIncluded - aiTokensUsed)
  const { data, setData, errors, post, processing } = useForm({
    name: "",
    start_date: "",
    end_date: "",
    send_time_local: "07:00",
    channels: defaultChannels,
    user_ids: [] as number[],
    prompt: "",
    content_count: 10,
    content_type: "prayer" as "prayer" | "devotional" | "scripture",
  })

  const [userSearch, setUserSearch] = useState("")
  const [showPromptExamples, setShowPromptExamples] = useState(false)
  const [aiTokensPurchaseModalOpen, setAiTokensPurchaseModalOpen] = useState(false)
  const [purchaseCheckoutError, setPurchaseCheckoutError] = useState<string | null>(null)
  const [tokenPurchaseCelebration, setTokenPurchaseCelebration] = useState<{
    id: number
    active: boolean
    message: string | null
  }>({ id: 0, active: false, message: null })

  useEffect(() => {
    const success = flash?.success
    if (!isAiCreditPurchaseFlashSuccess(success)) {
      return
    }

    setTokenPurchaseCelebration((prev) => ({
      id: prev.id + 1,
      active: true,
      message: success,
    }))
    setAiTokensPurchaseModalOpen(false)

    const hideTimer = window.setTimeout(() => {
      setTokenPurchaseCelebration((prev) => ({ ...prev, active: false }))
    }, 3200)

    return () => window.clearTimeout(hideTimer)
  }, [flash?.success])

  const promptExamples = [
    "List 20 Daily Prayer with Bible Verses for morning motivation",
    "Create 15 devotional messages about faith and trust in God",
    "Generate 10 scripture-based reflections on hope and perseverance",
    "Write 12 daily prayers for peace and mental wellness",
    "Create 20 inspirational prayers with relevant Bible verses",
  ]

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    post(route("campaigns.ai-store"), {
      onSuccess: () => {
        // Reload auth data to update token usage in real-time
        router.reload({ only: ['auth'] })
      },
    })
  }

  const toggleChannel = (channel: string) => {
    setData(
      "channels",
      data.channels.includes(channel) ? data.channels.filter((c) => c !== channel) : [...data.channels, channel],
    )
  }

  const toggleUser = (userId: number) => {
    setData(
      "user_ids",
      data.user_ids.includes(userId) ? data.user_ids.filter((id) => id !== userId) : [...data.user_ids, userId],
    )
  }

  const selectAllUsers = () => {
    const filteredUserIds = filteredUsers.map((u) => u.id)
    setData("user_ids", filteredUserIds)
  }

  const clearAllUsers = () => {
    setData("user_ids", [])
  }

  const filteredUsers = users.filter(
    (user) =>
      user.name.toLowerCase().includes(userSearch.toLowerCase()) ||
      user.email.toLowerCase().includes(userSearch.toLowerCase()),
  )

  const calculateDays = (): number => {
    return data.content_count
  }

  const applyExample = (example: string) => {
    setData("prompt", example)
    setShowPromptExamples(false)
  }

  return (
    <AppLayout>
      <Head title="Create AI Campaign" />

      <div className="py-6">
        <div className="w-full px-4 sm:px-6 lg:px-8">
          {/* Header Section */}
          <div className="mb-8">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-4">
              <div className="flex items-start sm:items-center gap-3 sm:gap-4 flex-1 min-w-0">
                <div className="p-2 sm:p-3 rounded-xl bg-primary/10 flex-shrink-0">
                  <Sparkles className="h-5 w-5 sm:h-6 sm:w-6 text-primary" />
                </div>
                <div className="min-w-0 flex-1">
                  <h1 className="text-2xl sm:text-3xl font-bold leading-tight">Create AI-Powered Campaign</h1>
                  <p className="text-muted-foreground mt-1 text-sm sm:text-base">
                    Let AI generate unique daily content for your campaign
                  </p>
                </div>
              </div>
              {auth.user.role === "organization" && (
                <div className="w-full max-w-[17.5rem] shrink-0">
                  <AiChatUsageCard
                    userRole={auth.user.role}
                    aiTokensIncluded={aiTokensIncluded}
                    aiTokensUsed={aiTokensUsed}
                    percentTokensUsed={percentTokensUsed}
                    hasAiTokensLeft={hasAiTokensLeft}
                    onAddTokens={() => setAiTokensPurchaseModalOpen(true)}
                    addTokensDisabled={processing}
                  />
                </div>
              )}
            </div>

            {auth.user.role === "organization" &&
              aiTokensIncluded > 0 &&
              aiTokensUsed >= aiTokensIncluded * 0.9 &&
              aiTokensUsed < aiTokensIncluded && (
              <div className="mb-4 flex-shrink-0 rounded-lg border-2 border-amber-500 bg-amber-50 p-4 dark:bg-amber-950/20">
                <div className="flex items-center gap-3">
                  <AlertCircle className="h-5 w-5 flex-shrink-0 text-amber-600 dark:text-amber-400" />
                  <div className="flex-1">
                    <div className="mb-1 font-semibold text-amber-900 dark:text-amber-300">AI tokens running low</div>
                    <p className="text-sm text-amber-800 dark:text-amber-400">
                      You have used <strong>{aiTokensUsed.toLocaleString()} of {aiTokensIncluded.toLocaleString()}</strong> AI tokens. Add more tokens to keep generating campaigns.
                    </p>
                  </div>
                </div>
              </div>
            )}

            {auth.user.role === "organization" && !hasAiTokensLeft && aiTokensIncluded > 0 && (
              <div className="mb-4 flex items-start gap-3 rounded-lg border border-destructive/20 bg-destructive/10 p-4">
                <AlertCircle className="mt-0.5 h-5 w-5 flex-shrink-0 text-destructive" />
                <div className="flex-1">
                  <div className="text-sm font-medium text-destructive">AI token limit reached</div>
                  <div className="mt-0.5 text-sm text-destructive/90">
                    You have used all your AI tokens for this period. Each campaign uses tokens based on actual AI usage (prompt + generated content).
                  </div>
                </div>
                <button
                  type="button"
                  onClick={() => setAiTokensPurchaseModalOpen(true)}
                  className={cn(
                    chatPrimaryButtonClass,
                    "flex flex-shrink-0 items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium"
                  )}
                >
                  Add more tokens
                </button>
              </div>
            )}

            {purchaseCheckoutError && (
              <div className="mb-4 flex items-start gap-3 rounded-lg border border-destructive/20 bg-destructive/10 p-4">
                <AlertCircle className="mt-0.5 h-5 w-5 flex-shrink-0 text-destructive" />
                <div className="text-sm text-destructive/90">{purchaseCheckoutError}</div>
              </div>
            )}
          </div>

          <form onSubmit={handleSubmit} className="space-y-8">
            {/* Basic Information Card */}
            <div className="bg-card border border-border rounded-xl shadow-sm p-6">
              <div className="flex items-center gap-2 mb-6">
                <div className="p-2 rounded-lg bg-primary/10">
                  <FileText className="h-4 w-4 text-primary" />
                </div>
                <h2 className="text-xl font-semibold">Campaign Details</h2>
              </div>
              
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="space-y-2">
                  <label className="flex items-center gap-2 text-sm font-medium">
                    <FileText className="h-4 w-4 text-muted-foreground" />
                    Campaign Name *
                  </label>
                  <input
                    type="text"
                    value={data.name}
                    onChange={(e) => setData("name", e.target.value)}
                    className="w-full px-4 py-2.5 border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary bg-background transition-all"
                    placeholder="e.g., AI Daily Prayers - October 2025"
                  />
                  {errors.name && <div className="text-destructive text-sm mt-1 flex items-center gap-1"><AlertCircle className="h-3 w-3" /> {errors.name}</div>}
                </div>

                <div className="space-y-2">
                  <label className="flex items-center gap-2 text-sm font-medium">
                    <Calendar className="h-4 w-4 text-muted-foreground" />
                    Start Date *
                  </label>
                  <input
                    type="date"
                    value={data.start_date}
                    onChange={(e) => setData("start_date", e.target.value)}
                    min={new Date().toISOString().split("T")[0]}
                    className="w-full px-4 py-2.5 border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary bg-background transition-all"
                  />
                  {errors.start_date && (
                    <div className="text-destructive text-sm mt-1 flex items-center gap-1"><AlertCircle className="h-3 w-3" /> {errors.start_date}</div>
                  )}
                </div>

                <div className="space-y-2">
                  <label className="flex items-center gap-2 text-sm font-medium">
                    <Clock className="h-4 w-4 text-muted-foreground" />
                    Daily Send Time *
                  </label>
                  <input
                    type="time"
                    value={data.send_time_local}
                    onChange={(e) => setData("send_time_local", e.target.value)}
                    className="w-full px-4 py-2.5 border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary bg-background transition-all"
                  />
                  <p className="text-xs text-muted-foreground">Local time for your organization</p>
                  {errors.send_time_local && (
                    <div className="text-destructive text-sm mt-1 flex items-center gap-1"><AlertCircle className="h-3 w-3" /> {errors.send_time_local}</div>
                  )}
                </div>

                <div className="space-y-2">
                  <label className="flex items-center gap-2 text-sm font-medium">
                    <Calendar className="h-4 w-4 text-muted-foreground" />
                    Number of Days (Content Items) *
                  </label>
                  <input
                    type="number"
                    value={data.content_count}
                    onChange={(e) => setData("content_count", Math.max(1, Number.parseInt(e.target.value) || 1))}
                    min="1"
                    max="30"
                    className="w-full px-4 py-2.5 border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary bg-background transition-all"
                  />
                  <p className="text-xs text-muted-foreground">
                    Campaign will generate <strong>{data.content_count}</strong> unique content items
                  </p>
                  {errors.content_count && (
                    <div className="text-destructive text-sm mt-1 flex items-center gap-1"><AlertCircle className="h-3 w-3" /> {errors.content_count}</div>
                  )}
                </div>
              </div>
            </div>

            {/* Content Type Selection */}
            <div className="bg-card border border-border rounded-xl shadow-sm p-6">
              <div className="flex items-center gap-2 mb-6">
                <div className="p-2 rounded-lg bg-primary/10">
                  <Radio className="h-4 w-4 text-primary" />
                </div>
                <h2 className="text-xl font-semibold">Content Type</h2>
              </div>
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                {(["prayer", "devotional", "scripture"] as const).map((type) => (
                  <label
                    key={type}
                    className={`flex items-center gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all ${
                      data.content_type === type
                        ? "border-primary bg-primary/10 shadow-sm"
                        : "border-border bg-card hover:border-primary/50 hover:bg-accent/50"
                    }`}
                  >
                    <input
                      type="radio"
                      name="content_type"
                      value={type}
                      checked={data.content_type === type}
                      onChange={(e) => setData("content_type", e.target.value as "prayer" | "devotional" | "scripture")}
                      className="rounded-full border-border text-primary focus:ring-primary w-4 h-4"
                    />
                    <span className="text-sm font-medium capitalize flex-1">{type}</span>
                    {data.content_type === type && <CheckCircle2 className="h-4 w-4 text-primary" />}
                  </label>
                ))}
              </div>
              {errors.content_type && (
                <div className="text-destructive text-sm mt-3 flex items-center gap-1"><AlertCircle className="h-3 w-3" /> {errors.content_type}</div>
              )}
            </div>

            {/* AI Prompt */}
            <div className="bg-card border border-border rounded-xl shadow-sm p-6">
              <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-2">
                  <div className="p-2 rounded-lg bg-primary/10">
                    <MessageSquare className="h-4 w-4 text-primary" />
                  </div>
                  <h2 className="text-xl font-semibold">AI Prompt</h2>
                </div>
                <button
                  type="button"
                  onClick={() => setShowPromptExamples(!showPromptExamples)}
                  className="text-sm text-primary hover:text-primary/80 font-medium px-3 py-1.5 rounded-lg hover:bg-primary/10 transition-colors"
                >
                  {showPromptExamples ? "Hide" : "Show"} Examples
                </button>
              </div>

              {showPromptExamples && (
                <div className="mb-4 p-4 bg-primary/5 rounded-lg border border-primary/20 space-y-2">
                  {promptExamples.map((example, idx) => (
                    <button
                      key={idx}
                      type="button"
                      onClick={() => applyExample(example)}
                      className="w-full text-left text-sm p-3 hover:bg-primary/10 rounded-lg transition-colors text-muted-foreground hover:text-foreground border border-transparent hover:border-primary/20"
                    >
                      {example}
                    </button>
                  ))}
                </div>
              )}

              <div className="space-y-2">
                <label className="text-sm font-medium text-muted-foreground">
                  Describe what content you want AI to generate *
                </label>
                <textarea
                  value={data.prompt}
                  onChange={(e) => setData("prompt", e.target.value)}
                  rows={5}
                  className="w-full px-4 py-3 border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary bg-background transition-all resize-none"
                  placeholder="Be specific about the theme, tone, and any requirements. Example: 'Create 20 daily prayers with Bible verses for morning motivation'"
                />
                {errors.prompt && <div className="text-destructive text-sm mt-1 flex items-center gap-1"><AlertCircle className="h-3 w-3" /> {errors.prompt}</div>}
                <p className="text-xs text-muted-foreground">
                  Each generation uses <strong>AI tokens</strong> based on actual usage (prompt + response). Your balance is shown above.
                </p>
              </div>
            </div>

            {/* Delivery Channels */}
            <div className="bg-card border border-border rounded-xl shadow-sm p-6">
              <div className="flex items-center gap-2 mb-6">
                <div className="p-2 rounded-lg bg-primary/10">
                  <MessageSquare className="h-4 w-4 text-primary" />
                </div>
                <h2 className="text-xl font-semibold">Delivery Channels</h2>
              </div>
              <div className="flex flex-wrap gap-6">
                <label className="inline-flex items-center gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all border-border hover:border-primary/50 hover:bg-accent/50">
                  <input
                    type="checkbox"
                    checked={data.channels.includes("whatsapp")}
                    onChange={() => toggleChannel("whatsapp")}
                    className="rounded border-border text-primary focus:ring-primary w-4 h-4"
                  />
                  <span className="text-sm font-medium">WhatsApp</span>
                  {data.channels.includes("whatsapp") && <CheckCircle2 className="h-4 w-4 text-primary ml-auto" />}
                </label>
                <label className="inline-flex items-center gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all border-border hover:border-primary/50 hover:bg-accent/50">
                  <input
                    type="checkbox"
                    checked={data.channels.includes("web")}
                    onChange={() => toggleChannel("web")}
                    className="rounded border-border text-primary focus:ring-primary w-4 h-4"
                  />
                  <span className="text-sm font-medium">Web Notification</span>
                  {data.channels.includes("web") && <CheckCircle2 className="h-4 w-4 text-primary ml-auto" />}
                </label>
                <label className="inline-flex items-center gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all border-border hover:border-primary/50 hover:bg-accent/50">
                  <input
                    type="checkbox"
                    checked={data.channels.includes("email")}
                    onChange={() => toggleChannel("email")}
                    className="rounded border-border text-primary focus:ring-primary w-4 h-4"
                  />
                  <span className="text-sm font-medium inline-flex items-center gap-1.5">
                    <Mail className="h-3.5 w-3.5" />
                    Email
                  </span>
                  {data.channels.includes("email") && <CheckCircle2 className="h-4 w-4 text-primary ml-auto" />}
                </label>
              </div>
              {errors.channels && (
                <div className="text-destructive text-sm mt-3 flex items-center gap-1"><AlertCircle className="h-3 w-3" /> {errors.channels}</div>
              )}
            </div>

            {/* User Selection */}
            <div className="bg-card border border-border rounded-xl shadow-sm p-6">
              <div className="flex items-center justify-between mb-6">
                <div className="flex items-center gap-2">
                  <div className="p-2 rounded-lg bg-primary/10">
                    <Users className="h-4 w-4 text-primary" />
                  </div>
                  <h2 className="text-xl font-semibold">Select Recipients</h2>
                </div>
                <div className="flex items-center gap-3">
                  <span className="text-sm text-muted-foreground">
                    {data.user_ids.length} {data.user_ids.length === 1 ? 'user' : 'users'} selected
                  </span>
                  <div className="flex gap-2">
                    <button
                      type="button"
                      onClick={selectAllUsers}
                      className="text-sm text-primary hover:text-primary/80 font-medium px-3 py-1.5 rounded-lg hover:bg-primary/10 transition-colors"
                    >
                      Select All
                    </button>
                    <span className="text-muted-foreground">|</span>
                    <button
                      type="button"
                      onClick={clearAllUsers}
                      className="text-sm text-muted-foreground hover:text-foreground font-medium px-3 py-1.5 rounded-lg hover:bg-accent transition-colors"
                    >
                      Clear All
                    </button>
                  </div>
                </div>
              </div>

              <div className="space-y-4">
                <input
                  type="text"
                  placeholder="🔍 Search users by name or email..."
                  value={userSearch}
                  onChange={(e) => setUserSearch(e.target.value)}
                  className="w-full px-4 py-2.5 border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-primary focus:border-primary bg-background transition-all"
                />

                <div className="max-h-72 overflow-y-auto p-4 border border-border rounded-lg space-y-2 bg-muted/30">
                  {filteredUsers.length === 0 ? (
                    <div className="text-center py-8">
                      <Users className="h-12 w-12 text-muted-foreground/50 mx-auto mb-3" />
                      <p className="text-muted-foreground">No users found</p>
                    </div>
                  ) : (
                    filteredUsers.map((user) => (
                      <label
                        key={user.id}
                        className={`flex items-center gap-3 p-4 border-2 rounded-xl cursor-pointer transition-all ${
                          data.user_ids.includes(user.id)
                            ? "border-primary bg-primary/10 shadow-sm"
                            : "border-border bg-card hover:border-primary/50 hover:bg-accent/50"
                        }`}
                      >
                        <input
                          type="checkbox"
                          checked={data.user_ids.includes(user.id)}
                          onChange={() => toggleUser(user.id)}
                          className="rounded border-border text-primary focus:ring-primary w-4 h-4"
                        />
                        <div className="flex-1 min-w-0">
                          <div className="font-medium text-sm">{user.name}</div>
                          <div className="text-xs text-muted-foreground">{user.email}</div>
                          <div className="flex gap-2 mt-2">
                            {user.whatsapp_opt_in === "yes" && (
                              <span className="text-xs bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400 px-2 py-0.5 rounded-full font-medium">
                                WhatsApp Enabled
                              </span>
                            )}
                          </div>
                        </div>
                        {data.user_ids.includes(user.id) && <CheckCircle2 className="h-5 w-5 text-primary flex-shrink-0" />}
                      </label>
                    ))
                  )}
                </div>
              </div>
              {errors.user_ids && (
                <div className="text-destructive text-sm mt-3 flex items-center gap-1"><AlertCircle className="h-3 w-3" /> {errors.user_ids}</div>
              )}
            </div>

            {/* Campaign Summary */}
            {data.prompt && data.user_ids.length > 0 ? (
              <div className="bg-gradient-to-br from-primary/10 via-primary/5 to-background border-2 border-primary/20 rounded-xl shadow-sm p-6">
                <div className="flex items-center gap-2 mb-4">
                  <div className="p-2 rounded-lg bg-primary/20">
                    <CheckCircle2 className="h-5 w-5 text-primary" />
                  </div>
                  <h2 className="text-xl font-semibold">Campaign Summary</h2>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div className="space-y-3">
                    <div className="flex items-start gap-3">
                      <Sparkles className="h-5 w-5 text-primary mt-0.5 flex-shrink-0" />
                      <div>
                        <div className="text-sm font-medium">Content Generation</div>
                        <div className="text-sm text-muted-foreground">
                          <strong>{data.content_count}</strong> unique {data.content_type} items
                        </div>
                      </div>
                    </div>
                    <div className="flex items-start gap-3">
                      <Calendar className="h-5 w-5 text-primary mt-0.5 flex-shrink-0" />
                      <div>
                        <div className="text-sm font-medium">Campaign Duration</div>
                        <div className="text-sm text-muted-foreground">
                          <strong>{calculateDays()} days</strong> from start date
                        </div>
                      </div>
                    </div>
                    <div className="flex items-start gap-3">
                      <Users className="h-5 w-5 text-primary mt-0.5 flex-shrink-0" />
                      <div>
                        <div className="text-sm font-medium">Recipients</div>
                        <div className="text-sm text-muted-foreground">
                          <strong>{data.user_ids.length}</strong> {data.user_ids.length === 1 ? 'user' : 'users'} will receive notifications
                        </div>
                      </div>
                    </div>
                  </div>
                  <div className="space-y-3">
                    <div className="flex items-start gap-3">
                      <MessageSquare className="h-5 w-5 text-primary mt-0.5 flex-shrink-0" />
                      <div>
                        <div className="text-sm font-medium">Delivery Channels</div>
                        <div className="text-sm text-muted-foreground">
                          <strong>{data.channels.join(" & ")}</strong>
                        </div>
                      </div>
                    </div>
                    <div className="flex items-start gap-3">
                      <Clock className="h-5 w-5 text-primary mt-0.5 flex-shrink-0" />
                      <div>
                        <div className="text-sm font-medium">Send Time</div>
                        <div className="text-sm text-muted-foreground">
                          Daily at <strong>{data.send_time_local}</strong> local time
                        </div>
                      </div>
                    </div>
                    <div className="flex items-start gap-3">
                      <Sparkles className="h-5 w-5 text-primary mt-0.5 flex-shrink-0" />
                      <div>
                        <div className="text-sm font-medium">AI Token Usage</div>
                        <div className="text-sm text-muted-foreground">
                          {aiTokensIncluded > 0 ? (
                            <>
                              <strong>{aiTokensUsed.toLocaleString()} / {aiTokensIncluded.toLocaleString()}</strong> tokens used
                              {aiTokensRemaining !== null && (
                                <> · <strong>{aiTokensRemaining.toLocaleString()}</strong> remaining</>
                              )}
                            </>
                          ) : (
                            <>Uses your shared AI token balance (no cap on this plan)</>
                          )}
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            ) : null}

            {('error' in errors && errors.error) && (
              <div className="flex items-start gap-3 p-4 bg-destructive/10 rounded-lg border-2 border-destructive/20">
                <AlertCircle className="h-5 w-5 text-destructive flex-shrink-0 mt-0.5" />
                <div>
                  <div className="text-sm font-medium text-destructive">Error</div>
                  <div className="text-sm text-destructive/90 mt-0.5">{(errors as { error?: string }).error}</div>
                </div>
              </div>
            )}

            {/* Actions */}
            <div className="flex justify-end gap-4 pt-6 border-t border-border">
              <Link
                href={route("campaigns.index")}
                className="px-6 py-2.5 border border-border rounded-lg hover:bg-accent focus:outline-none focus:ring-2 focus:ring-primary transition-all font-medium"
              >
                Cancel
              </Link>
              <button
                type="submit"
                disabled={
                  processing ||
                  !data.prompt ||
                  data.user_ids.length === 0 ||
                  !data.start_date ||
                  data.content_count < 1 ||
                  !hasAiTokensLeft
                }
                className="px-8 py-2.5 bg-primary text-primary-foreground rounded-lg hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-primary disabled:opacity-50 disabled:cursor-not-allowed transition-all font-medium flex items-center gap-2 shadow-sm hover:shadow-md"
              >
                {processing ? (
                  <>
                    <div className="h-4 w-4 border-2 border-primary-foreground/30 border-t-primary-foreground rounded-full animate-spin" />
                    Generating Content...
                  </>
                ) : (
                  <>
                    <Sparkles className="h-4 w-4" />
                    Generate & Create Campaign
                  </>
                )}
              </button>
            </div>
          </form>
        </div>
      </div>

      <AiTokenPurchaseSuccessOverlay
        key={tokenPurchaseCelebration.id}
        active={tokenPurchaseCelebration.active}
        message={tokenPurchaseCelebration.message}
      />

      <AiTokensPurchaseModal
        open={aiTokensPurchaseModalOpen}
        onOpenChange={setAiTokensPurchaseModalOpen}
        returnRoute="campaigns.ai-create"
        onCheckoutError={(message) => setPurchaseCheckoutError(message)}
      />
    </AppLayout>
  )
}

export default AiCampaignsCreate
