"use client"

import React, { useState, useRef, useEffect } from 'react'
import { Head, router, usePage } from '@inertiajs/react'
import AppLayout from '@/layouts/app-layout'
import { Bot, Send, User as UserIcon, Coins, AlertCircle, Sparkles, Loader2, RefreshCw, Plus, MessageSquare, Trash2, ChevronRight, Menu, X, Copy, Check, Edit2, Save } from 'lucide-react'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog'
import type { SharedData } from '@/types'
import { CampaignCreateForm } from '@/components/ai-chat/CampaignCreateForm'
import { AiTokensPurchaseModal } from '@/components/ai-chat/AiTokensPurchaseModal'
import {
  AiTokenPurchaseSuccessOverlay,
  isAiCreditPurchaseFlashSuccess,
} from '@/components/ai-chat/AiTokenPurchaseSuccessOverlay'
import { AiChatUsageCard } from '@/components/ai-chat/AiChatUsageCard'
import { chatGradientText, chatPrimaryButtonClass, chatInputFocusRing } from '@/components/chat/chat-brand'
import { cn } from '@/lib/utils'

/** Icon accent aligned with sidebar logo gradient (site-title.tsx) */
const brandIconClass = 'text-purple-600 dark:text-blue-400'

interface Message {
  id: number | string
  role: 'user' | 'assistant'
  content: string
  timestamp: Date | string
  isTyping?: boolean
  formData?: {
    show_form?: boolean
    form_type?: string
    form_data?: any
    users?: any[]
    content_items?: any[]
    default_channels?: string[]
  }
}

interface Conversation {
  id: number
  title: string
  last_message_at: string
  created_at: string
}

// Streaming text component that works with formatted content and increases speed over time
const StreamingText: React.FC<{ 
  text: string
  isUser: boolean
  onUpdate?: (displayedText: string) => void
  onComplete?: () => void
}> = ({ 
  text, 
  isUser,
  onUpdate,
  onComplete
}) => {
  const [displayedText, setDisplayedText] = useState('')
  const [currentIndex, setCurrentIndex] = useState(0)

  useEffect(() => {
    setDisplayedText('')
    setCurrentIndex(0)
  }, [text])

  useEffect(() => {
    if (currentIndex < text.length) {
      const currentChar = text[currentIndex]
      const prevChar = currentIndex > 0 ? text[currentIndex - 1] : ''
      
      // Base speed - starts slower, gets faster as content increases
      const progress = currentIndex / Math.max(text.length, 1) // 0 to 1
      const baseSpeed = 15 // Starting speed (ms per character)
      const minSpeed = 8   // Minimum speed (faster typing)
      
      // Speed increases gradually - starts at baseSpeed, reaches minSpeed near the end
      const currentSpeed = baseSpeed - (baseSpeed - minSpeed) * Math.pow(progress, 0.5)
      
      // Calculate variable delay based on character type
      let delay = currentSpeed
      
      // Punctuation - longer pause
      if (/[.,!?;:]/.test(currentChar)) {
        delay = currentSpeed * 2 // Shorter pause than before for faster feel
      } 
      // Spaces - slight pause
      else if (currentChar === ' ') {
        delay = currentSpeed * 1.2
      }
      // After punctuation - extra pause
      else if (/[.,!?;:]/.test(prevChar) && currentChar === ' ') {
        delay = currentSpeed * 2.5
      }
      // Line breaks - pause
      else if (currentChar === '\n') {
        delay = currentSpeed * 2
      }
      // Common letters - faster
      else if (/[aeiouAEIOU]/.test(currentChar)) {
        delay = currentSpeed * 0.7
      }
      // Consonants - normal
      else if (/[a-zA-Z0-9]/.test(currentChar)) {
        delay = currentSpeed * 0.9
      }
      // Special characters - slightly slower
      else {
        delay = currentSpeed * 1.1
      }
      
      // Add small random variation for natural feel (Â±15%)
      const variation = delay * 0.15
      const randomVariation = (Math.random() - 0.5) * 2 * variation
      delay = Math.max(3, delay + randomVariation) // Minimum 3ms

      const timeout = setTimeout(() => {
        setDisplayedText(prev => {
          const newText = prev + currentChar
          onUpdate?.(newText)
          return newText
        })
        setCurrentIndex(prev => prev + 1)
      }, delay)

      return () => clearTimeout(timeout)
    }
  }, [currentIndex, text, displayedText, onUpdate, onComplete])

  // Handle completion when text fully matches
  useEffect(() => {
    if (currentIndex >= text.length && text.length > 0 && displayedText === text && onComplete) {
      const timeout = setTimeout(() => {
        onComplete()
      }, 50)
      return () => clearTimeout(timeout)
    }
  }, [currentIndex, displayedText, text, onComplete])

  return null // This component doesn't render, it updates via callback
}

interface AiChatIndexProps {
  conversations?: {
    data: Conversation[]
    current_page: number
    has_more_pages: boolean
  }
  currentConversation?: Conversation | null
  messages?: Array<{
    id: number
    role: 'user' | 'assistant'
    content: string
    timestamp: string
  }>
}

const AiChatIndex: React.FC<AiChatIndexProps> = ({ 
  conversations: initialConversations,
  currentConversation: initialConversation,
  messages: initialMessages = []
}) => {
  const { auth, flash } = usePage<SharedData & { flash?: { success?: string } }>().props
  const currentCredits = auth.user.credits ?? 0
  const aiTokensIncluded = (auth.user as { ai_tokens_included?: number }).ai_tokens_included ?? 0
  const aiTokensUsedRaw = (auth.user as { ai_tokens_used?: number }).ai_tokens_used ?? 0
  const aiTokensUsed =
    aiTokensIncluded > 0 ? Math.min(aiTokensUsedRaw, aiTokensIncluded) : aiTokensUsedRaw
  // AI chat is token-based: allow send when no limit (ai_tokens_included === 0) or when used < included
  const hasAiTokensLeft = aiTokensIncluded === 0 || aiTokensUsed < aiTokensIncluded

  const percentTokensUsed =
    aiTokensIncluded > 0
      ? Math.min(100, Math.round((aiTokensUsed / aiTokensIncluded) * 100))
      : 0
  
  // Sidebar state
  const [conversations, setConversations] = useState<Conversation[]>(initialConversations?.data || [])
  const [conversationsPage, setConversationsPage] = useState(1)
  const [hasMoreConversations, setHasMoreConversations] = useState(initialConversations?.has_more_pages || false)
  const [loadingConversations, setLoadingConversations] = useState(false)
  const sidebarScrollRef = useRef<HTMLDivElement>(null)

  // Chat state
  const [currentConversationId, setCurrentConversationId] = useState<number | null>(initialConversation?.id || null)
  const [messages, setMessages] = useState<Message[]>(
    initialMessages.map(msg => ({
      ...msg,
      timestamp: new Date(msg.timestamp),
    })) || []
  )
  const [input, setInput] = useState('')
  const [isLoading, setIsLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [streamingMessageId, setStreamingMessageId] = useState<string | null>(null)
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const [copiedCode, setCopiedCode] = useState<string | null>(null)
  const [deleteModalOpen, setDeleteModalOpen] = useState(false)
  const [conversationToDelete, setConversationToDelete] = useState<number | null>(null)
  const [editingTitleId, setEditingTitleId] = useState<number | null>(null)
  const [editingTitle, setEditingTitle] = useState('')
  const [aiTokensPurchaseModalOpen, setAiTokensPurchaseModalOpen] = useState(false)
  const [tokenPurchaseCelebration, setTokenPurchaseCelebration] = useState<{
    id: number
    active: boolean
    message: string | null
  }>({ id: 0, active: false, message: null })
  const [streamingTexts, setStreamingTexts] = useState<Record<string | number, string>>({})
  const scrollIntervalRef = useRef<ReturnType<typeof setInterval> | null>(null)
  const messagesEndRef = useRef<HTMLDivElement>(null)
  const messagesContainerRef = useRef<HTMLDivElement>(null)
  const inputRef = useRef<HTMLTextAreaElement>(null)
  const titleInputRef = useRef<HTMLInputElement>(null)
  const isUserScrollingRef = useRef(false)
  const shouldAutoScrollRef = useRef(true)
  const isInitialLoadRef = useRef(true)

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

  // Check if user is near bottom (within 150px) or at bottom
  const isNearBottom = () => {
    if (!messagesContainerRef.current) return true
    const container = messagesContainerRef.current
    const { scrollTop, scrollHeight, clientHeight } = container
    const threshold = 150
    return Math.abs(scrollHeight - scrollTop - clientHeight) < threshold
  }

  const scrollToBottom = (force = false, smooth = true) => {
    if (!messagesContainerRef.current) return
    
    const container = messagesContainerRef.current
    
    // Only auto-scroll if user is near bottom or force is true
    if (force || (shouldAutoScrollRef.current && isNearBottom())) {
      // Use scrollTo for better control and smoother scrolling
      const scrollHeight = container.scrollHeight
      container.scrollTo({
        top: scrollHeight,
        behavior: smooth ? 'smooth' : 'auto'
      })
    }
  }

  const adjustTextareaHeight = () => {
    if (inputRef.current) {
      inputRef.current.style.height = 'auto'
      inputRef.current.style.height = `${Math.min(inputRef.current.scrollHeight, 200)}px`
    }
  }

  // Handle scroll events to detect user scrolling and enable/disable auto-scroll
  useEffect(() => {
    const container = messagesContainerRef.current
    if (!container) return

    let scrollTimeout: ReturnType<typeof setTimeout> | null = null
    
    const handleScroll = () => {
      // Clear any existing timeout
      if (scrollTimeout) clearTimeout(scrollTimeout)
      
      const nearBottom = isNearBottom()
      
      // If user scrolls manually, temporarily disable auto-scroll
      if (!nearBottom) {
        isUserScrollingRef.current = true
        shouldAutoScrollRef.current = false
      } else {
        // If user scrolls back to bottom, re-enable auto-scroll after a brief delay
        scrollTimeout = setTimeout(() => {
          isUserScrollingRef.current = false
          shouldAutoScrollRef.current = true
        }, 500) // 500ms delay to ensure user stopped scrolling
      }
    }

    container.addEventListener('scroll', handleScroll, { passive: true })
    
    return () => {
      container.removeEventListener('scroll', handleScroll)
      if (scrollTimeout) clearTimeout(scrollTimeout)
    }
  }, [])

  // Initial scroll to bottom when messages first load or conversation changes
  useEffect(() => {
    if (messages.length > 0 && messagesContainerRef.current) {
      // Force scroll to bottom on initial load - wait for DOM to render
      const timer = setTimeout(() => {
        if (messagesContainerRef.current) {
          const container = messagesContainerRef.current
          const scrollHeight = container.scrollHeight
          // Always scroll to bottom on initial load, smoothly
          container.scrollTo({
            top: scrollHeight,
            behavior: isInitialLoadRef.current ? 'auto' : 'smooth' // Instant on first load, smooth on conversation change
          })
          // Reset auto-scroll state
          shouldAutoScrollRef.current = true
          isUserScrollingRef.current = false
          isInitialLoadRef.current = false
        }
      }, 100) // Give time for DOM to render
      
      return () => clearTimeout(timer)
    }
  }, [currentConversationId]) // Trigger when conversation changes

  // Auto-scroll when new messages arrive, but only if user is near bottom
  useEffect(() => {
    if (messages.length > 0 && shouldAutoScrollRef.current) {
      // Small delay to ensure DOM is updated
      setTimeout(() => scrollToBottom(false, true), 100)
    }
  }, [messages.length])

  useEffect(() => {
    adjustTextareaHeight()
  }, [input])

  // Load more conversations on scroll
  const handleSidebarScroll = () => {
    if (!sidebarScrollRef.current || loadingConversations || !hasMoreConversations) return

    const { scrollTop, scrollHeight, clientHeight } = sidebarScrollRef.current
    if (scrollHeight - scrollTop <= clientHeight + 100) {
      loadMoreConversations()
    }
  }

  const loadMoreConversations = async () => {
    if (loadingConversations || !hasMoreConversations) return

    setLoadingConversations(true)
    try {
      const response = await fetch(`${route('ai-chat.conversations') as string}?page=${conversationsPage + 1}`, {
        credentials: 'same-origin',
      })
      const data = await response.json()

      setConversations(prev => [...prev, ...data.conversations])
      setConversationsPage(prev => prev + 1)
      setHasMoreConversations(data.has_more)
    } catch (err) {
      console.error('Failed to load conversations:', err)
    } finally {
      setLoadingConversations(false)
    }
  }

  const loadConversation = async (conversationId: number) => {
    if (currentConversationId === conversationId) {
      setSidebarOpen(false) // Close sidebar on mobile when same conversation clicked
      return // Already loaded
    }
    
    try {
      setIsLoading(true)
      const response = await fetch(route('ai-chat.conversation', conversationId) as string, {
        credentials: 'same-origin',
      })
      
      if (!response.ok) {
        throw new Error('Failed to load conversation')
      }
      
      const data = await response.json()

      setCurrentConversationId(conversationId)
      setMessages(
        data.messages.map((msg: any) => ({
          id: msg.id,
          role: msg.role,
          content: msg.content,
          timestamp: new Date(msg.timestamp),
        }))
      )
      setError(null)
      setSidebarOpen(false) // Close sidebar on mobile after loading

      // Scroll to bottom after messages are loaded - wait for DOM to update
      setTimeout(() => {
        if (messagesContainerRef.current) {
          const container = messagesContainerRef.current
          container.scrollTo({
            top: container.scrollHeight,
            behavior: 'smooth'
          })
          shouldAutoScrollRef.current = true
          isUserScrollingRef.current = false
        }
      }, 150)

      // Update URL
      router.get(route('ai-chat.index') as string, { conversation_id: conversationId }, { preserveState: true })
    } catch (err) {
      console.error('Failed to load conversation:', err)
      setError('Failed to load conversation')
    } finally {
      setIsLoading(false)
    }
  }

  const createNewConversation = () => {
    setCurrentConversationId(null)
    setMessages([])
    setError(null)
    router.get(route('ai-chat.index') as string, {}, { preserveState: true })
  }

  const handleDeleteClick = (id: number, e: React.MouseEvent) => {
    e.stopPropagation()
    setConversationToDelete(id)
    setDeleteModalOpen(true)
  }

  const confirmDelete = async () => {
    if (!conversationToDelete) return

    try {
      await fetch(route('ai-chat.delete-conversation', conversationToDelete) as string, {
        method: 'DELETE',
        headers: {
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          'Accept': 'application/json',
        },
        credentials: 'same-origin',
      })

      setConversations(prev => prev.filter(conv => conv.id !== conversationToDelete))
      if (currentConversationId === conversationToDelete) {
        createNewConversation()
      }
      setDeleteModalOpen(false)
      setConversationToDelete(null)
    } catch (err) {
      console.error('Failed to delete conversation:', err)
      setDeleteModalOpen(false)
      setConversationToDelete(null)
    }
  }

  const handleTitleClick = (conversation: Conversation, e: React.MouseEvent) => {
    e.stopPropagation()
    setEditingTitleId(conversation.id)
    setEditingTitle(conversation.title)
    setTimeout(() => {
      titleInputRef.current?.focus()
      titleInputRef.current?.select()
    }, 0)
  }

  const saveTitle = async (id: number) => {
    if (!editingTitle.trim()) {
      setEditingTitleId(null)
      return
    }

    try {
      const response = await fetch(route('ai-chat.update-conversation', id) as string, {
        method: 'PUT',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ title: editingTitle.trim() }),
      })

      if (response.ok) {
        const data = await response.json()
        setConversations(prev =>
          prev.map(conv =>
            conv.id === id ? { ...conv, title: data.conversation.title } : conv
          )
        )
      }
    } catch (err) {
      console.error('Failed to update title:', err)
    }

    setEditingTitleId(null)
    setEditingTitle('')
  }

  const cancelEditTitle = () => {
    setEditingTitleId(null)
    setEditingTitle('')
  }

  const handleSend = async () => {
    if (!input.trim() || isLoading) return

    const userMessage: Message = {
      id: `temp-${Date.now()}`,
      role: 'user',
      content: input.trim(),
      timestamp: new Date(),
    }

    setMessages(prev => [...prev, userMessage])
    setInput('')
    setError(null)
    setIsLoading(true)
    
    // Reset textarea height after sending
    setTimeout(() => {
      if (inputRef.current) {
        inputRef.current.style.height = 'auto'
        inputRef.current.style.height = '52px'
      }
    }, 0)

    try {
      const response = await fetch(route('ai-chat.send') as string, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          'Accept': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          message: userMessage.content,
          conversation_id: currentConversationId,
        }),
      })

      const data = await response.json()

      // Debug: Log the response to see what we're getting
      if (data.show_form) {
        console.log('Form data received:', {
          show_form: data.show_form,
          form_type: data.form_type,
          form_data: data.form_data,
          users_count: data.users?.length,
          content_items_count: data.content_items?.length,
        })
      }

      if (!response.ok) {
        if (data.error === 'insufficient_credits') {
          setError('insufficient_credits')
        } else if (data.error === 'insufficient_tokens') {
          setError('insufficient_tokens')
        } else {
          setError(data.message || 'Failed to get response')
        }
        setIsLoading(false)
        // Remove the user message on error
        setMessages(prev => prev.filter(msg => msg.id !== userMessage.id))
        return
      }

      // Update conversation ID if new conversation was created
      if (data.conversation_id && !currentConversationId) {
        setCurrentConversationId(data.conversation_id)
        
        // Fetch the new conversation and add it to the list
        try {
          const convResponse = await fetch(route('ai-chat.conversation', data.conversation_id) as string, {
            credentials: 'same-origin',
          })
          if (convResponse.ok) {
            const convData = await convResponse.json()
            const newConversation = convData.conversation
            
            // Add new conversation to the top of the list
            setConversations(prev => {
              // Check if it already exists
              if (prev.some(conv => conv.id === newConversation.id)) {
                return prev
              }
              // Add to the beginning of the list
              return [newConversation, ...prev]
            })
          }
        } catch (err) {
          console.error('Failed to fetch new conversation:', err)
          // Fallback: reload conversations
          router.reload({ only: ['conversations'] })
        }
      }

      const assistantMessageId = `temp-${Date.now() + 1}`
      
      // Prepare form data if form should be shown
      const formDataObj = data.show_form ? {
        show_form: Boolean(data.show_form),
        form_type: data.form_type || 'campaign_create',
        form_data: data.form_data || {},
        users: Array.isArray(data.users) ? data.users : [],
        content_items: Array.isArray(data.content_items) ? data.content_items : [],
        default_channels: Array.isArray(data.default_channels) ? data.default_channels : ['web', 'whatsapp', 'email'],
      } : undefined
      
      console.log('Creating assistant message:', {
        has_show_form: Boolean(data.show_form),
        form_type: data.form_type,
        has_form_data: Boolean(formDataObj),
        users_count: formDataObj?.users?.length || 0,
        content_items_count: formDataObj?.content_items?.length || 0,
      })
      
      const assistantMessage: Message = {
        id: assistantMessageId,
        role: 'assistant',
        content: data.response || data.message || '',
        timestamp: new Date(),
        isTyping: !data.show_form, // Don't show typing if form is shown
        formData: formDataObj,
      }

      setMessages(prev => [...prev, assistantMessage])
      if (!data.show_form) {
        setStreamingMessageId(assistantMessageId)
        setStreamingTexts(prev => ({ ...prev, [assistantMessageId]: '' }))
      }
      setIsLoading(false)
      
      // Scroll to bottom to show the form (force scroll)
      setTimeout(() => scrollToBottom(true, true), 100)
      
      // Reload auth to update token usage (ai_tokens_used / ai_tokens_included)
      router.reload({ only: ['auth'] })

      // Auto-scroll during typing, but less aggressive and only if near bottom
      if (shouldAutoScrollRef.current) {
        scrollIntervalRef.current = setInterval(() => {
          if (shouldAutoScrollRef.current && isNearBottom()) {
            scrollToBottom(false, true)
          }
        }, 300) // Less frequent updates for smoother experience
      }
    } catch (err) {
      setError('Network error. Please try again.')
      setIsLoading(false)
      setMessages(prev => prev.filter(msg => msg.id !== userMessage.id))
    }
  }

  const handleKeyPress = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      handleSend()
    }
  }

  const formatDate = (dateString: string) => {
    const date = new Date(dateString)
    const now = new Date()
    const diffTime = now.getTime() - date.getTime()
    const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24))

    if (diffDays === 0) return 'Today'
    if (diffDays === 1) return 'Yesterday'
    if (diffDays < 7) return `${diffDays} days ago`
    return date.toLocaleDateString()
  }

  const copyToClipboard = async (text: string, id: string) => {
    try {
      await navigator.clipboard.writeText(text)
      setCopiedCode(id)
      setTimeout(() => setCopiedCode(null), 2000)
    } catch (err) {
      console.error('Failed to copy:', err)
    }
  }

  const renderMessageContent = (content: string, isUser: boolean) => {
    const lines = content.split('\n')
    const elements: React.ReactNode[] = []
    let currentParagraph: string[] = []
    let inCodeBlock = false
    let codeBlockContent: string[] = []
    let codeBlockLang = ''
    let codeBlockId = ''
    let listItems: string[] = []
    let isOrderedList = false
    let listCounter = 1

    const flushParagraph = () => {
      if (currentParagraph.length > 0) {
        const paragraphText = currentParagraph.join('\n')
        elements.push(
          <p key={`p-${elements.length}`} className="mb-2 last:mb-0 leading-relaxed">
            {renderInlineContent(paragraphText, isUser)}
          </p>
        )
        currentParagraph = []
      }
    }

    const flushCodeBlock = () => {
      if (codeBlockContent.length > 0) {
        const code = codeBlockContent.join('\n')
        const id = `code-${Date.now()}-${Math.random()}`
        elements.push(
          <div key={id} className="relative my-3 group">
            {codeBlockLang && (
              <div className="flex items-center justify-between px-3 py-1.5 bg-muted/50 border-b border-border rounded-t-lg">
                <span className="text-xs font-mono text-muted-foreground">{codeBlockLang}</span>
                <button
                  onClick={() => copyToClipboard(code, id)}
                  className="p-1 rounded hover:bg-accent transition-colors opacity-0 group-hover:opacity-100"
                  title="Copy code"
                >
                  {copiedCode === id ? (
                    <Check className="h-3 w-3 text-green-500" />
                  ) : (
                    <Copy className="h-3 w-3 text-muted-foreground" />
                  )}
                </button>
              </div>
            )}
            <pre className={`overflow-x-auto ${codeBlockLang ? 'rounded-b-lg rounded-t-none' : 'rounded-lg'} bg-muted/80 border border-border p-4`}>
              <code className="text-sm font-mono leading-relaxed whitespace-pre">
                {code}
              </code>
            </pre>
            {!codeBlockLang && (
              <button
                onClick={() => copyToClipboard(code, id)}
                className="absolute top-2 right-2 p-1.5 rounded bg-card/90 border border-border hover:bg-accent transition-colors opacity-0 group-hover:opacity-100 shadow-sm"
                title="Copy code"
              >
                {copiedCode === id ? (
                  <Check className="h-3.5 w-3.5 text-green-500" />
                ) : (
                  <Copy className="h-3.5 w-3.5 text-muted-foreground" />
                )}
              </button>
            )}
          </div>
        )
        codeBlockContent = []
        codeBlockLang = ''
      }
    }

    const flushList = () => {
      if (listItems.length > 0) {
        const ListTag = isOrderedList ? 'ol' : 'ul'
        const className = isOrderedList
          ? 'list-decimal list-inside space-y-1 my-2 ml-4'
          : 'list-disc list-inside space-y-1 my-2 ml-4'
        elements.push(
          <ListTag key={`list-${elements.length}`} className={className}>
            {listItems.map((item, idx) => (
              <li key={idx} className="leading-relaxed">
                {renderInlineContent(item.trim(), isUser)}
              </li>
            ))}
          </ListTag>
        )
        listItems = []
        listCounter = 1
        isOrderedList = false
      }
    }

    const renderInlineContent = (text: string, isUser: boolean): React.ReactNode => {
      const parts: React.ReactNode[] = []
      let currentIndex = 0

      // Match inline code, bold, italic, links
      const patterns = [
        { regex: /`([^`]+)`/g, render: (match: string, code: string) => (
          <code key={currentIndex++} className={`px-1.5 py-0.5 rounded text-xs font-mono ${
            isUser ? 'bg-white/20 text-white' : 'bg-muted border border-border'
          }`}>
            {code}
          </code>
        )},
        { regex: /\*\*([^*]+)\*\*/g, render: (match: string, bold: string) => (
          <strong key={currentIndex++} className="font-semibold">{bold}</strong>
        )},
        { regex: /\*([^*]+)\*/g, render: (match: string, italic: string) => (
          <em key={currentIndex++} className="italic">{italic}</em>
        )},
        { regex: /\[([^\]]+)\]\(([^)]+)\)/g, render: (match: string, text: string, url: string) => (
          <a
            key={currentIndex++}
            href={url}
            target="_blank"
            rel="noopener noreferrer"
            className="text-purple-600 underline hover:text-blue-600 dark:text-blue-400 dark:hover:text-blue-300"
          >
            {text}
          </a>
        )},
      ]

      let lastIndex = 0
      const matches: Array<{ index: number; length: number; render: () => React.ReactNode }> = []

      patterns.forEach(({ regex, render }) => {
        regex.lastIndex = 0
        let match
        while ((match = regex.exec(text)) !== null) {
          // Capture the match values immediately to avoid closure issues
          const fullMatch = match[0]
          const capturedGroups = match.slice(1)
          const matchIndex = match.index
          const matchLength = fullMatch.length
          
          matches.push({
            index: matchIndex,
            length: matchLength,
            render: () => render(fullMatch, ...capturedGroups),
          })
        }
      })

      matches.sort((a, b) => a.index - b.index)

      matches.forEach((match) => {
        if (lastIndex < match.index) {
          parts.push(text.slice(lastIndex, match.index))
        }
        parts.push(match.render())
        lastIndex = match.index + match.length
      })

      if (lastIndex < text.length) {
        parts.push(text.slice(lastIndex))
      }

      return parts.length > 0 ? <>{parts}</> : text
    }

    for (let i = 0; i < lines.length; i++) {
      const line = lines[i]
      const trimmed = line.trim()

      // Check for code block
      if (trimmed.startsWith('```')) {
        if (inCodeBlock) {
          flushCodeBlock()
          inCodeBlock = false
        } else {
          flushParagraph()
          flushList()
          inCodeBlock = true
          const langMatch = trimmed.match(/^```(\w+)?/)
          codeBlockLang = langMatch?.[1] || ''
          codeBlockId = `code-${i}`
          continue
        }
        continue
      }

      if (inCodeBlock) {
        codeBlockContent.push(line)
        continue
      }

      // Check for headers
      const headerMatch = trimmed.match(/^(#{1,6})\s+(.+)$/)
      if (headerMatch) {
        flushParagraph()
        flushList()
        const level = headerMatch[1].length
        const text = headerMatch[2]
        const HeadingTag = `h${level}` as keyof JSX.IntrinsicElements
        elements.push(
          <HeadingTag
            key={`h-${elements.length}`}
            className={`font-bold mb-2 mt-3 first:mt-0 ${
              level === 1 ? 'text-xl' :
              level === 2 ? 'text-lg' :
              level === 3 ? 'text-base' :
              'text-sm'
            }`}
          >
            {renderInlineContent(text, isUser)}
          </HeadingTag>
        )
        continue
      }

      // Check for lists
      const orderedMatch = trimmed.match(/^\d+\.\s+(.+)$/)
      const unorderedMatch = trimmed.match(/^[-*]\s+(.+)$/)

      if (orderedMatch) {
        if (!isOrderedList && listItems.length > 0) {
          flushList()
        }
        isOrderedList = true
        listItems.push(orderedMatch[1])
        continue
      } else if (unorderedMatch) {
        if (isOrderedList && listItems.length > 0) {
          flushList()
        }
        isOrderedList = false
        listItems.push(unorderedMatch[1])
        continue
      } else if (listItems.length > 0) {
        flushList()
      }

      // Regular paragraph
      if (trimmed === '') {
        flushParagraph()
      } else {
        currentParagraph.push(line)
      }
    }

    flushParagraph()
    flushCodeBlock()
    flushList()

    return elements.length > 0 ? <>{elements}</> : <p className="leading-relaxed">{renderInlineContent(content, isUser)}</p>
  }

  return (
    <AppLayout>
      <Head title="AI Believe Assistant" />

      <div className="relative h-[calc(100vh-4rem)] w-full">
        {/* Main Chat Area */}
        <div className="flex flex-col h-full px-2 sm:px-4 md:px-6 lg:px-8 py-2 sm:py-4">
            {/* Header — title left, same usage card top-right as sidebar */}
            <div className="mb-4 flex flex-shrink-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div className="flex min-w-0 flex-1 items-center gap-2 sm:gap-3">
                <div className="flex-shrink-0 rounded-xl bg-gradient-to-r from-purple-600/10 to-blue-600/10 p-2">
                  <Bot className={cn('h-5 w-5 sm:h-6 sm:w-6', brandIconClass)} />
                </div>
                <div className="min-w-0">
                  <h1 className={cn('truncate text-xl font-bold sm:text-2xl', chatGradientText)}>
                    AI Believe Assistant
                  </h1>
                  <p className="text-xs text-muted-foreground sm:text-sm">Ask me anything</p>
                </div>
              </div>
              <div className="ml-auto w-full max-w-[17.5rem] shrink-0 sm:ml-0">
                <AiChatUsageCard
                  userRole={auth.user.role}
                  aiTokensIncluded={aiTokensIncluded}
                  aiTokensUsed={aiTokensUsed}
                  percentTokensUsed={percentTokensUsed}
                  hasAiTokensLeft={hasAiTokensLeft}
                  onAddTokens={() => setAiTokensPurchaseModalOpen(true)}
                  addTokensDisabled={isLoading}
                />
              </div>
            </div>

            {/* Low AI tokens warning - when plan has a limit and most are used */}
            {auth.user.role === 'organization' && aiTokensIncluded > 0 && aiTokensUsed >= aiTokensIncluded * 0.9 && aiTokensUsed < aiTokensIncluded && (
              <div className="mb-4 p-4 bg-amber-50 dark:bg-amber-950/20 border-2 border-amber-500 rounded-lg flex-shrink-0">
                <div className="flex items-center gap-3">
                  <AlertCircle className="h-5 w-5 text-amber-600 dark:text-amber-400 flex-shrink-0" />
                  <div className="flex-1">
                    <div className="font-semibold text-amber-900 dark:text-amber-300 mb-1">AI tokens running low</div>
                    <p className="text-sm text-amber-800 dark:text-amber-400">
                      You have used <strong>{aiTokensUsed.toLocaleString()} of {aiTokensIncluded.toLocaleString()}</strong> AI tokens. Upgrade your plan for more.
                    </p>
                  </div>
                </div>
              </div>
            )}

            {/* Insufficient Credits Alert (legacy / other features) */}
            {error === 'insufficient_credits' && (
              <div className="mb-4 p-4 bg-destructive/10 border-2 border-destructive/20 rounded-lg flex-shrink-0">
                <div className="flex items-start gap-3">
                  <AlertCircle className="h-5 w-5 text-destructive flex-shrink-0 mt-0.5" />
                  <div className="flex-1">
                    <div className="font-semibold text-destructive mb-1">Insufficient Credits</div>
                    <p className="text-sm text-destructive/90 mb-3">
                      You don't have enough credits for this action. Top up to continue.
                    </p>
                    <button
                      onClick={() => setAiTokensPurchaseModalOpen(true)}
                      type="button"
                      className={cn(chatPrimaryButtonClass, 'px-4 py-2 rounded-lg transition-colors font-medium text-sm flex items-center gap-2 cursor-pointer')}
                    >
                      <Coins className="h-4 w-4" />
                      TopUp
                    </button>
                  </div>
                </div>
              </div>
            )}

            {/* Insufficient AI tokens alert */}
            {error === 'insufficient_tokens' && (
              <div className="mb-4 p-4 bg-destructive/10 border-2 border-destructive/20 rounded-lg flex-shrink-0">
                <div className="flex items-start gap-3">
                  <AlertCircle className="h-5 w-5 text-destructive flex-shrink-0 mt-0.5" />
                  <div className="flex-1">
                    <div className="font-semibold text-destructive mb-1">AI token limit reached</div>
                    <p className="text-sm text-destructive/90 mb-3">
                      You have used all your AI tokens for this period. Each message uses tokens based on the actual AI usage. Upgrade your plan or wait for your next allocation to continue.
                    </p>
                    <button
                      type="button"
                      onClick={() => setAiTokensPurchaseModalOpen(true)}
                      className={cn(chatPrimaryButtonClass, 'px-4 py-2 rounded-lg transition-colors font-medium text-sm flex items-center gap-2 cursor-pointer')}
                    >
                      <Coins className="h-4 w-4" />
                      TopUp
                    </button>
                  </div>
                </div>
              </div>
            )}

            {/* Chat Messages Container - This is the red mark area */}
            <div className="bg-card border border-border rounded-xl shadow-sm flex-1 flex flex-col min-h-0 relative" style={{ height: 'calc(100vh - 12rem)' }}>
              {/* Mobile Sidebar Toggle */}
              <button
                onClick={() => setSidebarOpen(!sidebarOpen)}
                className={`md:hidden absolute top-2 left-2 z-30 p-2 rounded-lg bg-card border border-border hover:bg-accent transition-opacity ${
                  sidebarOpen ? 'opacity-0 pointer-events-none' : 'opacity-100'
                }`}
              >
                <Menu className="h-5 w-5" />
              </button>

              {/* Backdrop overlay for mobile */}
              {sidebarOpen && (
                <div
                  onClick={() => setSidebarOpen(false)}
                  className="md:hidden fixed inset-0 bg-black/50 z-10"
                />
              )}

              {/* Sidebar - Positioned inside main content area (red mark position) */}
              <div className={`absolute left-0 top-0 bottom-0 w-72 sm:w-80 md:w-64 bg-card border-r border-border flex flex-col z-20 pointer-events-auto transition-transform duration-300 shadow-lg md:shadow-none rounded-l-xl ${
                sidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'
              }`}>
                <div className="p-3 border-b border-border flex-shrink-0 flex items-center justify-between gap-2">
                  <div className="flex items-center gap-2">
                    <Sparkles className="h-4 w-4 text-foreground" />
                    <span className="font-semibold text-foreground">Chat</span>
                  </div>
                  <button
                    onClick={() => setSidebarOpen(false)}
                    className="md:hidden p-1 rounded hover:bg-accent"
                  >
                    <X className="h-4 w-4" />
                  </button>
                </div>

                {/* New Chat Button */}
                <div className="p-2 border-b border-border flex-shrink-0">
                  <button
                    onClick={() => {
                      createNewConversation()
                      setSidebarOpen(false) // Close sidebar on mobile
                    }}
                    className={cn(chatPrimaryButtonClass, 'w-full flex items-center gap-2 px-3 py-2 rounded-lg transition-colors font-medium text-sm')}
                  >
                    <Plus className="h-4 w-4" />
                    <span>New Chat</span>
                  </button>
                </div>

                <div
                  ref={sidebarScrollRef}
                  onScroll={handleSidebarScroll}
                  className="flex-1 overflow-y-auto min-h-0 ai-chat-scrollbar"
                >
                  <div className="p-2 space-y-1">
                    {conversations.map((conversation) => (
                      <div
                        key={conversation.id}
                        onClick={(e) => {
                          e.preventDefault()
                          e.stopPropagation()
                          loadConversation(conversation.id)
                        }}
                        className={`group flex items-center gap-2 p-3 rounded-lg cursor-pointer transition-colors ${
                          currentConversationId === conversation.id
                            ? 'bg-gradient-to-r from-purple-600/10 to-blue-600/10 border border-purple-500/20'
                            : 'hover:bg-accent'
                        }`}
                        role="button"
                        tabIndex={0}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault()
                            loadConversation(conversation.id)
                          }
                        }}
                      >
                        <MessageSquare className="h-4 w-4 text-muted-foreground flex-shrink-0" />
                        <div className="flex-1 min-w-0">
                          {editingTitleId === conversation.id ? (
                            <div className="flex items-center gap-1" onClick={(e) => e.stopPropagation()}>
                              <input
                                ref={titleInputRef}
                                type="text"
                                value={editingTitle}
                                onChange={(e) => {
                                  e.stopPropagation()
                                  setEditingTitle(e.target.value)
                                }}
                                onBlur={() => saveTitle(conversation.id)}
                                onKeyDown={(e) => {
                                  e.stopPropagation()
                                  if (e.key === 'Enter') {
                                    e.preventDefault()
                                    saveTitle(conversation.id)
                                  } else if (e.key === 'Escape') {
                                    e.preventDefault()
                                    cancelEditTitle()
                                  }
                                }}
                                onKeyUp={(e) => e.stopPropagation()}
                                onKeyPress={(e) => e.stopPropagation()}
                                onClick={(e) => e.stopPropagation()}
                                onFocus={(e) => e.stopPropagation()}
                                onInput={(e) => e.stopPropagation()}
                                className={cn(
                                  'text-sm font-medium bg-background border border-purple-500/25 rounded px-2 py-1 flex-1 min-w-0 focus:outline-none',
                                  chatInputFocusRing
                                )}
                                autoFocus
                              />
                              <button
                                onClick={(e) => {
                                  e.stopPropagation()
                                  saveTitle(conversation.id)
                                }}
                                className="p-0.5 hover:bg-accent rounded transition-colors"
                                title="Save"
                              >
                                <Save className={cn('h-3 w-3', brandIconClass)} />
                              </button>
                              <button
                                onClick={(e) => {
                                  e.stopPropagation()
                                  cancelEditTitle()
                                }}
                                className="p-0.5 hover:bg-accent rounded transition-colors"
                                title="Cancel"
                              >
                                <X className="h-3 w-3 text-muted-foreground" />
                              </button>
                            </div>
                          ) : (
                            <div
                              className="text-sm font-medium truncate cursor-text hover:text-purple-600 dark:hover:text-blue-400 transition-colors"
                              onClick={(e) => handleTitleClick(conversation, e)}
                              title="Click to edit title"
                            >
                              {conversation.title}
                            </div>
                          )}
                          <div className="text-xs text-muted-foreground">{formatDate(conversation.last_message_at)}</div>
                        </div>
                        <button
                          onClick={(e) => handleDeleteClick(conversation.id, e)}
                          className="opacity-0 group-hover:opacity-100 p-1 hover:bg-destructive/10 rounded transition-opacity"
                          title="Delete conversation"
                        >
                          <Trash2 className="h-3.5 w-3.5 text-destructive" />
                        </button>
                      </div>
                    ))}
                    {loadingConversations && (
                      <div className="flex justify-center p-4">
                        <Loader2 className={cn('h-4 w-4 animate-spin', brandIconClass)} />
                      </div>
                    )}
                    {conversations.length === 0 && !loadingConversations && (
                      <div className="p-2">
                        {/* Empty state - clean space like in image */}
                      </div>
                    )}
                  </div>
                </div>
                
                {/* Bottom — same AI usage card as header */}
                <div className="flex-shrink-0 border-t border-border p-3">
                  <AiChatUsageCard
                    userRole={auth.user.role}
                    aiTokensIncluded={aiTokensIncluded}
                    aiTokensUsed={aiTokensUsed}
                    percentTokensUsed={percentTokensUsed}
                    hasAiTokensLeft={hasAiTokensLeft}
                    onAddTokens={() => setAiTokensPurchaseModalOpen(true)}
                    addTokensDisabled={isLoading}
                  />
                </div>
              </div>

              {/* Chat Content Area - with left margin for sidebar */}
              <div className="flex-1 flex flex-col ml-0 md:ml-64 h-full min-h-0">
                {/* Conversation Header */}
                {currentConversationId && (
                  <div className="flex-shrink-0 px-4 sm:px-6 py-3 border-b border-border bg-muted/30">
                    <div className="flex items-center gap-2">
                      <MessageSquare className={cn('h-4 w-4', brandIconClass)} />
                      <h2 className="text-base sm:text-lg font-semibold truncate">
                        {conversations.find(conv => conv.id === currentConversationId)?.title || 'Chat'}
                      </h2>
                    </div>
                  </div>
                )}
                
                {/* Messages */}
                <div 
                  ref={messagesContainerRef}
                  className={`flex-1 p-3 sm:p-4 md:p-6 space-y-4 sm:space-y-6 min-h-0 overscroll-contain ai-chat-scrollbar ${messages.length > 0 ? 'overflow-y-auto' : 'overflow-hidden'}`}
                  style={{ 
                    scrollBehavior: 'smooth',
                    WebkitOverflowScrolling: 'touch', // Smooth scrolling on iOS
                    overscrollBehavior: 'contain', // Prevent scroll chaining
                  }}
                >
                {messages.length === 0 ? (
                  <div className="flex flex-col items-center justify-center h-full text-center px-4">
                    {/* Logo */}
                    <div className="mb-6 flex items-center justify-center">
                      <div className="relative">
                        <img 
                          src="/believeiu-logo.png" 
                          alt="Believeiu.cash Logo" 
                          className="h-16 w-16 sm:h-20 sm:w-20 object-contain"
                          onError={(e) => {
                            // Fallback if logo doesn't exist
                            e.currentTarget.style.display = 'none';
                          }}
                        />
                      </div>
                    </div>
                    
                    {/* Branding */}
                    <div className="mb-6">
                      <div className="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-gradient-to-r from-purple-600/10 to-blue-600/10 border border-purple-500/20 mb-4">
                        <Bot className={cn('h-5 w-5', brandIconClass)} />
                        <span className={cn('text-lg font-semibold', chatGradientText)}>AI Believe Assistant</span>
                      </div>
                      <h3 className="text-2xl sm:text-3xl font-bold mb-3 bg-gradient-to-r from-purple-600 via-violet-500 to-blue-600 bg-clip-text text-transparent">
                        Start a Conversation
                      </h3>
                      <p className="text-muted-foreground max-w-md mx-auto text-base sm:text-lg">
                        Hello! I'm your AI Believe Assistant. I'm here to help you with your organization's needs.
                      </p>
                    </div>

                    {/* Capabilities */}
                    <div className="mt-6 max-w-md mx-auto">
                      <p className="text-sm font-medium text-foreground mb-3">I can help you with:</p>
                      <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm text-muted-foreground">
                        <div className="flex items-center gap-2">
                          <Sparkles className={cn('h-4 w-4', brandIconClass)} />
                          <span>Creating campaigns</span>
                        </div>
                        <div className="flex items-center gap-2">
                          <Sparkles className={cn('h-4 w-4', brandIconClass)} />
                          <span>Generating content</span>
                        </div>
                        <div className="flex items-center gap-2">
                          <Sparkles className={cn('h-4 w-4', brandIconClass)} />
                          <span>Answering questions</span>
                        </div>
                        <div className="flex items-center gap-2">
                          <Sparkles className={cn('h-4 w-4', brandIconClass)} />
                          <span>Providing insights</span>
                        </div>
                      </div>
                    </div>

                    {/* Token usage info */}
                    <div className="mt-8 p-3 rounded-lg bg-muted/50 border border-border">
                      <p className="text-sm text-muted-foreground">
                        ðŸ’¡ Each reply uses <strong className="text-foreground">AI tokens</strong> based on actual usage (prompt + response). Your balance is shown above.
                      </p>
                    </div>
                  </div>
                ) : (
                  <>
                    {messages.map((message) => {
                      const isStreaming = streamingMessageId === message.id && message.isTyping
                      const timestamp = message.timestamp instanceof Date 
                        ? message.timestamp 
                        : new Date(message.timestamp)
                      
                      return (
                        <div
                          key={message.id}
                          className={`flex gap-3 items-start ${
                            message.role === 'user' ? 'justify-end' : 'justify-start'
                          }`}
                        >
                          {message.role === 'assistant' && (
                            <div className="flex-shrink-0 w-8 h-8 rounded-full bg-gradient-to-br from-purple-600/15 to-blue-600/15 flex items-center justify-center mt-1">
                              <Bot className={cn('h-4 w-4', brandIconClass)} />
                            </div>
                          )}
                          <div
                            className={`max-w-[75%] rounded-2xl px-4 py-3 flex flex-col ${
                              message.role === 'user'
                                ? 'bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm'
                                : 'bg-muted border border-border'
                            }`}
                          >
                            <div className="break-words">
                              {isStreaming ? (
                                <>
                                  <StreamingText
                                    text={message.content}
                                    isUser={message.role === 'user'}
                                    onUpdate={(text) => {
                                      setStreamingTexts(prev => ({ ...prev, [message.id]: text }))
                                      // Only scroll if user is near bottom (don't interrupt user scrolling)
                                      if (shouldAutoScrollRef.current) {
                                        scrollToBottom(false, true)
                                      }
                                    }}
                                    onComplete={() => {
                                      setStreamingMessageId(null)
                                      setMessages(prev =>
                                        prev.map(msg =>
                                          msg.id === message.id ? { ...msg, isTyping: false } : msg
                                        )
                                      )
                                      if (scrollIntervalRef.current) {
                                        clearInterval(scrollIntervalRef.current)
                                        scrollIntervalRef.current = null
                                      }
                                      // Scroll to bottom when typing completes, but only if user is near bottom
                                      setTimeout(() => {
                                        if (shouldAutoScrollRef.current && isNearBottom()) {
                                          scrollToBottom(false, true)
                                        }
                                      }, 100)
                                      
                                      // Update conversation in the list
                                      const convIdToUpdate = currentConversationId
                                      if (convIdToUpdate) {
                                        fetch(route('ai-chat.conversation', convIdToUpdate) as string, {
                                          credentials: 'same-origin',
                                        })
                                          .then(res => res.ok && res.json())
                                          .then(convData => {
                                            if (convData?.conversation) {
                                              const updatedConversation = convData.conversation
                                              setConversations(prev => {
                                                const existingIndex = prev.findIndex(conv => conv.id === updatedConversation.id)
                                                if (existingIndex >= 0) {
                                                  const updated = [...prev]
                                                  updated[existingIndex] = updatedConversation
                                                  const [moved] = updated.splice(existingIndex, 1)
                                                  return [moved, ...updated]
                                                } else {
                                                  return [updatedConversation, ...prev]
                                                }
                                              })
                                            }
                                          })
                                          .catch(err => console.error('Failed to update conversation:', err))
                                      }
                                    }}
                                  />
                                  <div className="leading-relaxed">
                                    {renderMessageContent(streamingTexts[message.id] || '', message.role === 'user')}
                                  </div>
                                  {streamingTexts[message.id] && streamingTexts[message.id].length < message.content.length && (
                                    <span className={`inline-block w-0.5 h-4 align-middle ml-1 animate-pulse ${
                                      message.role === 'user' ? 'bg-white' : 'bg-purple-600 dark:bg-blue-400'
                                    }`} />
                                  )}
                                </>
                              ) : (
                                <>
                                  {message.content && (
                                    <div className="leading-relaxed">
                                      {renderMessageContent(message.content, message.role === 'user')}
                                    </div>
                                  )}
                                  {message.formData?.show_form && message.formData.form_type === 'campaign_create' && (
                                    <div className="mt-4">
                                      <CampaignCreateForm
                                        formData={message.formData.form_data || {}}
                                        users={Array.isArray(message.formData.users) ? message.formData.users : []}
                                        contentItems={Array.isArray(message.formData.content_items) ? message.formData.content_items : []}
                                        defaultChannels={Array.isArray(message.formData.default_channels) ? message.formData.default_channels : ['web', 'whatsapp', 'email']}
                                        onSubmit={async (formData) => {
                                          // Create campaign via chat endpoint
                                          try {
                                            const createMessage = `Create campaign with name: ${formData.name}, start date: ${formData.start_date}, end date: ${formData.end_date}, send time: ${formData.send_time_local}, channels: ${formData.channels.join(', ')}, user ids: ${formData.user_ids.join(', ')}, content items: ${formData.content_items.join(', ')}`
                                            
                                            const response = await fetch(route('ai-chat.send') as string, {
                                              method: 'POST',
                                              headers: {
                                                'Content-Type': 'application/json',
                                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                                                'Accept': 'application/json',
                                              },
                                              credentials: 'same-origin',
                                              body: JSON.stringify({
                                                message: createMessage,
                                                conversation_id: currentConversationId,
                                              }),
                                            })
                                            
                                            const data = await response.json()
                                            
                                            if (response.ok) {
                                              // Add success message
                                              const successMessage: Message = {
                                                id: `temp-${Date.now()}`,
                                                role: 'assistant',
                                                content: data.response || data.message || 'Campaign created successfully!',
                                                timestamp: new Date(),
                                              }
                                              setMessages(prev => [...prev, successMessage])
                                              router.reload({ only: ['auth'] })
                                            } else {
                                              alert(data.message || 'Failed to create campaign')
                                            }
                                          } catch (err) {
                                            console.error('Failed to create campaign:', err)
                                            alert('Failed to create campaign. Please try again.')
                                          }
                                        }}
                                      />
                                    </div>
                                  )}
                                </>
                              )}
                            </div>
                            <div
                              className={`text-xs mt-1.5 opacity-60 ${
                                message.role === 'user'
                                  ? 'text-white/75'
                                  : 'text-muted-foreground'
                              }`}
                            >
                              {timestamp.toLocaleTimeString()}
                            </div>
                          </div>
                          {message.role === 'user' && (
                            <div className="flex-shrink-0 w-8 h-8 rounded-full bg-gradient-to-br from-purple-600/15 to-blue-600/15 flex items-center justify-center mt-1">
                              <UserIcon className={cn('h-4 w-4', brandIconClass)} />
                            </div>
                          )}
                        </div>
                      )
                    })}
                    {isLoading && (
                      <div className="flex gap-3 items-start justify-start">
                        <div className="flex-shrink-0 w-8 h-8 rounded-full bg-gradient-to-br from-purple-600/15 to-blue-600/15 flex items-center justify-center mt-1">
                          <Bot className={cn('h-4 w-4', brandIconClass)} />
                        </div>
                        <div className="bg-muted border border-border rounded-2xl px-4 py-3">
                          <div className="flex items-center gap-1.5">
                            <span className="text-muted-foreground">Thinking</span>
                            <span className="flex gap-1">
                              <span className="inline-block w-1 h-1 rounded-full bg-muted-foreground animate-[bounce_1.4s_ease-in-out_infinite] opacity-60" style={{ animationDelay: '0s' }}></span>
                              <span className="inline-block w-1 h-1 rounded-full bg-muted-foreground animate-[bounce_1.4s_ease-in-out_infinite] opacity-60" style={{ animationDelay: '0.2s' }}></span>
                              <span className="inline-block w-1 h-1 rounded-full bg-muted-foreground animate-[bounce_1.4s_ease-in-out_infinite] opacity-60" style={{ animationDelay: '0.4s' }}></span>
                            </span>
                          </div>
                        </div>
                      </div>
                    )}
                  </>
                )}
                  <div ref={messagesEndRef} />
                </div>

                {/* Input Area */}
                <div className="p-2 sm:p-3 md:p-4 border-t border-border flex-shrink-0">
                {error && error !== 'insufficient_credits' && error !== 'insufficient_tokens' && (
                  <div className="mb-3 p-3 bg-destructive/10 border border-destructive/20 rounded-lg text-sm text-destructive">
                    {error}
                  </div>
                )}
                <div className="flex gap-3">
                  <div className="flex-1 relative">
                    <textarea
                      ref={inputRef}
                      value={input}
                      onChange={(e) => {
                        setInput(e.target.value)
                      }}
                      onKeyDown={handleKeyPress}
                      placeholder="Type your message..."
                      rows={1}
                      className={cn(
                        'w-full px-4 py-3 pr-12 border border-border rounded-lg bg-background focus:outline-none resize-none transition-all overflow-hidden',
                        chatInputFocusRing
                      )}
                      style={{ minHeight: '52px', maxHeight: '200px', height: '52px' }}
                      disabled={isLoading || !hasAiTokensLeft}
                    />
                    <div className="absolute right-2 bottom-2 text-xs text-muted-foreground">
                      {input.length}/2000
                    </div>
                  </div>
                  <button
                    onClick={handleSend}
                    disabled={!input.trim() || isLoading || currentCredits < 1}
                    className={cn(
                      chatPrimaryButtonClass,
                      'px-3 sm:px-4 py-3 rounded-lg disabled:opacity-50 disabled:cursor-not-allowed transition-all flex items-center justify-center shadow-sm hover:shadow-md'
                    )}
                    title="Send message"
                  >
                    {isLoading ? (
                      <Loader2 className="h-4 w-4 sm:h-5 sm:w-5 animate-spin" />
                    ) : (
                      <Send className="h-4 w-4 sm:h-5 sm:w-5" />
                    )}
                  </button>
                </div>
              </div>
            </div>
          </div>
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
        returnRoute="ai-chat.index"
        onCheckoutError={(message) => setError(message)}
      />

      {/* Delete Confirmation Modal */}
      <Dialog open={deleteModalOpen} onOpenChange={setDeleteModalOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete Conversation</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete this conversation? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter className="gap-2">
            <button
              onClick={() => {
                setDeleteModalOpen(false)
                setConversationToDelete(null)
              }}
              className="px-4 py-2 rounded-lg border border-border hover:bg-accent transition-colors font-medium"
            >
              Cancel
            </button>
            <button
              onClick={confirmDelete}
              className="px-4 py-2 rounded-lg bg-destructive text-destructive-foreground hover:bg-destructive/90 transition-colors font-medium"
            >
              Delete
            </button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </AppLayout>
  )
}

export default AiChatIndex
