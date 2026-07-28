"use client"

import type React from "react"
import { useEffect, useMemo, useRef, useState } from "react"
import { router, useForm } from "@inertiajs/react"
import SettingsLayout from "@/layouts/settings/layout"
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/frontend/ui/card"
import { Badge } from "@/components/frontend/ui/badge"
import { Button } from "@/components/frontend/ui/button"
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/frontend/ui/tabs"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Checkbox } from "@/components/frontend/ui/checkbox"
import { Textarea } from "@/components/frontend/ui/textarea"
import { motion, AnimatePresence } from "framer-motion"
import {
  Zap,
  Trash2,
  CheckCircle2,
  XCircle,
  Loader2,
  Terminal,
  HardDrive,
  Database,
  AlertTriangle,
  Sparkles,
  Save,
  Plus,
  X,
  Layout,
  Link2,
  Settings2,
  Sprout,
  ChevronLeft,
  ChevronRight,
  ChevronsLeft,
  ChevronsRight,
} from "lucide-react"
import { Alert, AlertDescription } from "@/components/frontend/ui/alert"
import { showSuccessToast, showErrorToast } from "@/lib/toast"

interface TerminalLine {
  id: string
  type: 'command' | 'output' | 'success' | 'error'
  content: string
  timestamp: Date
}

interface CacheStats {
  driver: string
  prefix: string
}

interface StorageStats {
  logs_size: number
  cache_size: number
  total_size: number
  logs_size_formatted: string
  cache_size_formatted: string
  total_size_formatted: string
}

interface StorageLinkStatus {
  connected: boolean
  needs_connect: boolean
  target_exists: boolean
  link_exists: boolean
  link_is_symlink: boolean
  link_is_directory_not_symlink: boolean
  link_path: string
  target_path: string
}

interface MigrationStatus {
  check_error: string | null
  pending_count: number | null
  pending_names: string[]
  repository_exists: boolean | null
  has_pending: boolean
}

interface MigrationConfigItem {
  name: string
  path: string
  status: 'ran' | 'pending'
  batch: number | null
}

interface MigrationsConfiguration {
  check_error: string | null
  repository_exists: boolean
  ran_count: number
  pending_count: number
  /** Server-side filter echo + pagination (see ApplicationSettingsController). */
  filter?: string
  page?: number
  per_page?: number
  total_filtered?: number
  last_page?: number
  items: MigrationConfigItem[]
}

interface SeederConfigItem {
  class: string
  short_name: string
  seeded: boolean
  last_run_at: string | null
}

interface SeedersConfiguration {
  check_error: string | null
  table_exists: boolean
  seeded_count: number
  not_seeded_count: number
  filter?: string
  page?: number
  per_page?: number
  total_filtered?: number
  last_page?: number
  items: SeederConfigItem[]
}

interface FooterSettings {
  description?: string
  social_links?: {
    facebook?: string
    twitter?: string
    instagram?: string
    linkedin?: string
  }
  quick_links?: Array<{
    title: string
    url: string
  }>
  contact_email?: string
  contact_phone?: string
  contact_address?: string
  copyright_text?: string
  legal_links?: Array<{
    title: string
    url: string
  }>
}

interface Props {
  cache_stats: CacheStats
  storage_stats: StorageStats
  storage_link: StorageLinkStatus
  migration_status: MigrationStatus
  migrations_configuration?: MigrationsConfiguration | null
  seeders_configuration?: SeedersConfiguration | null
  footer_settings?: FooterSettings | null
}

/** Same Inertia partial-reload pattern as the donate page (`router.get` + `only` + `preserveState`). */
const applicationPartialVisit = {
  preserveState: true,
  preserveScroll: true,
  replace: true,
} as const

export default function ApplicationSettings({
  cache_stats,
  storage_stats,
  storage_link,
  migration_status,
  migrations_configuration = null,
  seeders_configuration = null,
  footer_settings,
}: Props) {
  const [isOptimizing, setIsOptimizing] = useState(false)
  const [isClearing, setIsClearing] = useState(false)
  const [isConnectingStorage, setIsConnectingStorage] = useState(false)
  const [isMigrating, setIsMigrating] = useState(false)
  const [isSeeding, setIsSeeding] = useState(false)
  const [terminalLines, setTerminalLines] = useState<TerminalLine[]>([])
  const [showTerminal, setShowTerminal] = useState(false)
  const [clearType, setClearType] = useState<'all' | 'cache' | 'config' | 'route' | 'view'>('all')
  const [settingsTab, setSettingsTab] = useState('performance')
  const [configInnerTab, setConfigInnerTab] = useState<'migrations' | 'seeders'>('migrations')
  const [migrationsLoading, setMigrationsLoading] = useState(false)
  const [seedersLoading, setSeedersLoading] = useState(false)

  /** Local filter text while typing; synced from server `filter` after each Inertia visit. */
  const [migrationFilterInput, setMigrationFilterInput] = useState('')
  const [seederFilterInput, setSeederFilterInput] = useState('')
  const migrationFilterDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const seederFilterDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  /** Pending migration base names selected for “Run selected” (can span pages). */
  const [selectedMigrationNames, setSelectedMigrationNames] = useState<string[]>([])
  const [selectedSeederClasses, setSelectedSeederClasses] = useState<string[]>([])

  const getMigrationsQueryParams = (patch: Partial<{ migration_q: string; migration_page: number; migration_per_page: number }>) => ({
    load_configuration: 'migrations' as const,
    migration_q: patch.migration_q ?? migrations_configuration?.filter ?? '',
    migration_page: patch.migration_page ?? migrations_configuration?.page ?? 1,
    migration_per_page: patch.migration_per_page ?? migrations_configuration?.per_page ?? 25,
  })

  const getSeedersQueryParams = (patch: Partial<{ seeder_q: string; seeder_page: number; seeder_per_page: number }>) => ({
    load_configuration: 'seeders' as const,
    seeder_q: patch.seeder_q ?? seeders_configuration?.filter ?? '',
    seeder_page: patch.seeder_page ?? seeders_configuration?.page ?? 1,
    seeder_per_page: patch.seeder_per_page ?? seeders_configuration?.per_page ?? 25,
  })

  const visitMigrationsConfiguration = (params: ReturnType<typeof getMigrationsQueryParams>) => {
    setMigrationsLoading(true)
    router.get(route('application.index'), params, {
      ...applicationPartialVisit,
      only: ['migrations_configuration'],
      onFinish: () => setMigrationsLoading(false),
      onCancel: () => setMigrationsLoading(false),
    })
  }

  const visitSeedersConfiguration = (params: ReturnType<typeof getSeedersQueryParams>) => {
    setSeedersLoading(true)
    router.get(route('application.index'), params, {
      ...applicationPartialVisit,
      only: ['seeders_configuration'],
      onFinish: () => setSeedersLoading(false),
      onCancel: () => setSeedersLoading(false),
    })
  }

  useEffect(() => {
    if (migrations_configuration?.filter !== undefined) {
      setMigrationFilterInput(migrations_configuration.filter)
    }
  }, [migrations_configuration?.filter])

  useEffect(() => {
    if (seeders_configuration?.filter !== undefined) {
      setSeederFilterInput(seeders_configuration.filter)
    }
  }, [seeders_configuration?.filter])

  useEffect(() => {
    if (settingsTab !== 'configuration') return
    if (configInnerTab !== 'migrations') return
    if (migrations_configuration != null) return
    visitMigrationsConfiguration(
      getMigrationsQueryParams({ migration_q: '', migration_page: 1, migration_per_page: 25 }),
    )
  }, [settingsTab, configInnerTab, migrations_configuration])

  useEffect(() => {
    if (settingsTab !== 'configuration') return
    if (configInnerTab !== 'seeders') return
    if (seeders_configuration != null) return
    visitSeedersConfiguration(
      getSeedersQueryParams({ seeder_q: '', seeder_page: 1, seeder_per_page: 25 }),
    )
  }, [settingsTab, configInnerTab, seeders_configuration])

  const migrationRange = useMemo(() => {
    const mc = migrations_configuration
    if (!mc || mc.total_filtered === undefined || mc.page === undefined || mc.per_page === undefined) {
      return { from: 0, to: 0, total: 0, lastPage: 1, page: 1 }
    }
    const total = mc.total_filtered
    const from = total === 0 ? 0 : (mc.page - 1) * mc.per_page + 1
    const to = total === 0 ? 0 : Math.min(mc.page * mc.per_page, total)
    return {
      from,
      to,
      total,
      lastPage: mc.last_page ?? 1,
      page: mc.page,
    }
  }, [migrations_configuration])

  const seederRange = useMemo(() => {
    const sc = seeders_configuration
    if (!sc || sc.total_filtered === undefined || sc.page === undefined || sc.per_page === undefined) {
      return { from: 0, to: 0, total: 0, lastPage: 1, page: 1 }
    }
    const total = sc.total_filtered
    const from = total === 0 ? 0 : (sc.page - 1) * sc.per_page + 1
    const to = total === 0 ? 0 : Math.min(sc.page * sc.per_page, total)
    return {
      from,
      to,
      total,
      lastPage: sc.last_page ?? 1,
      page: sc.page,
    }
  }, [seeders_configuration])

  const pendingMigrationsOnPage = useMemo(
    () => (migrations_configuration?.items ?? []).filter((r) => r.status === 'pending'),
    [migrations_configuration?.items],
  )

  const seedersOnPage = useMemo(() => seeders_configuration?.items ?? [], [seeders_configuration?.items])

  const migrationHeaderCheckboxState = useMemo((): boolean | 'indeterminate' => {
    if (pendingMigrationsOnPage.length === 0) return false
    const onPage = pendingMigrationsOnPage.map((r) => r.name)
    const n = onPage.filter((name) => selectedMigrationNames.includes(name)).length
    if (n === 0) return false
    if (n === onPage.length) return true
    return 'indeterminate'
  }, [pendingMigrationsOnPage, selectedMigrationNames])

  const allSeedersOnPageSelected =
    seedersOnPage.length > 0 && seedersOnPage.every((r) => selectedSeederClasses.includes(r.class))

  const seederHeaderCheckboxState = useMemo((): boolean | 'indeterminate' => {
    if (seedersOnPage.length === 0) return false
    const n = seedersOnPage.filter((r) => selectedSeederClasses.includes(r.class)).length
    if (n === 0) return false
    if (n === seedersOnPage.length) return true
    return 'indeterminate'
  }, [seedersOnPage, selectedSeederClasses])

  const toggleMigrationSelected = (name: string) => {
    setSelectedMigrationNames((prev) =>
      prev.includes(name) ? prev.filter((n) => n !== name) : [...prev, name],
    )
  }

  const toggleSelectAllPendingMigrationPage = () => {
    if (pendingMigrationsOnPage.length === 0) return
    const onPage = pendingMigrationsOnPage.map((r) => r.name)
    const allOnPage = onPage.every((name) => selectedMigrationNames.includes(name))
    if (allOnPage) {
      const drop = new Set(onPage)
      setSelectedMigrationNames((prev) => prev.filter((n) => !drop.has(n)))
    } else {
      setSelectedMigrationNames((prev) => [...new Set([...prev, ...onPage])])
    }
  }

  const toggleSeederSelected = (className: string) => {
    setSelectedSeederClasses((prev) =>
      prev.includes(className) ? prev.filter((c) => c !== className) : [...prev, className],
    )
  }

  const toggleSelectAllSeedersPage = () => {
    if (seedersOnPage.length === 0) return
    if (allSeedersOnPageSelected) {
      const drop = new Set(seedersOnPage.map((r) => r.class))
      setSelectedSeederClasses((prev) => prev.filter((c) => !drop.has(c)))
    } else {
      setSelectedSeederClasses((prev) => [...new Set([...prev, ...seedersOnPage.map((r) => r.class)])])
    }
  }

  const formatSeedRunAt = (value: string | null) => {
    if (!value) return '—'
    const normalized = value.includes('T') ? value : value.replace(' ', 'T')
    const d = new Date(normalized)
    return Number.isNaN(d.getTime())
      ? value
      : d.toLocaleString(undefined, { dateStyle: 'short', timeStyle: 'short' })
  }

  // Footer settings form
  const { data: footerData, setData: setFooterData, post: postFooter, processing: isSavingFooter, errors: footerErrors } = useForm<FooterSettings>({
    description: footer_settings?.description || '',
    social_links: footer_settings?.social_links || {
      facebook: '',
      twitter: '',
      instagram: '',
      linkedin: ''
    },
    quick_links: footer_settings?.quick_links || [],
    contact_email: footer_settings?.contact_email || '',
    contact_phone: footer_settings?.contact_phone || '',
    contact_address: footer_settings?.contact_address || '',
    copyright_text: footer_settings?.copyright_text || '',
    legal_links: footer_settings?.legal_links || []
  })

  const addTerminalLine = (type: TerminalLine['type'], content: string) => {
    const line: TerminalLine = {
      id: Date.now().toString() + Math.random().toString(36).substr(2, 9),
      type,
      content,
      timestamp: new Date(),
    }
    setTerminalLines((prev) => [...prev, line])
    setShowTerminal(true)
  }

  const handleOptimize = async () => {
    setIsOptimizing(true)
    setTerminalLines([])
    addTerminalLine('command', '$ php artisan optimize')
    addTerminalLine('output', 'Starting optimization process...')

    try {
      const response = await fetch('/settings/application/optimize', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
      })

      const data = await response.json()

      if (data.success) {
        data.results?.forEach((result: { action: string; message: string; status: string }) => {
          if (result.status === 'success') {
            addTerminalLine('success', `✓ ${result.message}`)
          } else {
            addTerminalLine('error', `✗ ${result.message}`)
          }
        })
        addTerminalLine('success', 'Optimization completed successfully!')
      } else {
        addTerminalLine('error', `Error: ${data.message}`)
      }
    } catch (error) {
      addTerminalLine('error', `Failed to optimize: ${error instanceof Error ? error.message : 'Unknown error'}`)
    } finally {
      setIsOptimizing(false)
    }
  }

  const postApplicationAction = async (url: string, jsonBody?: Record<string, unknown>) => {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
      'X-Requested-With': 'XMLHttpRequest',
    }
    if (jsonBody !== undefined) {
      headers['Content-Type'] = 'application/json'
    }
    return fetch(url, {
      method: 'POST',
      headers,
      credentials: 'include',
      body: jsonBody !== undefined ? JSON.stringify(jsonBody) : undefined,
    })
  }

  const handleStorageLink = async () => {
    setIsConnectingStorage(true)
    try {
      const response = await postApplicationAction(route('application.storage-link'))
      const data = await response.json()
      if (data.success) {
        showSuccessToast(data.message || 'Storage linked successfully.')
        router.reload({
          only: ['storage_stats', 'storage_link', 'migration_status', 'cache_stats'],
        })
      } else {
        showErrorToast(data.message || 'Could not create storage link.')
      }
    } catch (error) {
      showErrorToast(error instanceof Error ? error.message : 'Could not create storage link.')
    } finally {
      setIsConnectingStorage(false)
    }
  }

  /**
   * Run all pending migrations (no payload), or only the given pending migration base names (selective).
   */
  const handleRunMigrations = async (payload?: { migrations: string[] }) => {
    const selective = payload?.migrations !== undefined
    if (selective && payload.migrations.length === 0) {
      showErrorToast('Select at least one pending migration.')
      return
    }

    setIsMigrating(true)
    setTerminalLines([])
    addTerminalLine(
      'command',
      selective
        ? `$ php artisan migrate --force (${payload!.migrations.length} selected)`
        : '$ php artisan migrate --force',
    )
    addTerminalLine('output', 'Running database migrations...')
    setShowTerminal(true)
    try {
      const response = await postApplicationAction(
        route('application.migrate'),
        selective ? { migrations: payload!.migrations } : undefined,
      )
      const data = await response.json()
      if (response.ok && data.success) {
        const msg = data.message || 'Migrations completed.'
        addTerminalLine('success', msg)
        showSuccessToast(selective ? 'Selected migrations completed.' : 'Migrations completed successfully.')
        setSelectedMigrationNames([])
        setMigrationsLoading(true)
        router.get(route('application.index'), getMigrationsQueryParams({}), {
          ...applicationPartialVisit,
          only: ['migration_status', 'migrations_configuration', 'storage_stats', 'storage_link', 'cache_stats'],
          onFinish: () => setMigrationsLoading(false),
          onCancel: () => setMigrationsLoading(false),
        })
      } else {
        const err = data.message || 'Migration failed.'
        addTerminalLine('error', err)
        showErrorToast(err)
      }
    } catch (error) {
      const errMsg = error instanceof Error ? error.message : 'Migration failed.'
      addTerminalLine('error', errMsg)
      showErrorToast(errMsg)
    } finally {
      setIsMigrating(false)
    }
  }

  /**
   * Run `php artisan db:seed` (DatabaseSeeder), all not-yet-recorded seeders, or `--class` for each selected seeder.
   */
  const handleRunSeeders = async (payload?: { classes?: string[]; notRecordedOnly?: boolean }) => {
    const notRecordedOnly = payload?.notRecordedOnly === true
    const selective = !notRecordedOnly && payload?.classes !== undefined
    if (selective && (payload!.classes?.length ?? 0) === 0) {
      showErrorToast('Select at least one seeder.')
      return
    }

    setIsSeeding(true)
    setTerminalLines([])
    const cmd = notRecordedOnly
      ? '$ php artisan db:seed --force (each not recorded)'
      : selective
        ? `$ php artisan db:seed --force (${payload!.classes!.length} selected)`
        : '$ php artisan db:seed --force'
    addTerminalLine('command', cmd)
    addTerminalLine('output', 'Running seeders...')
    setShowTerminal(true)
    try {
      const jsonBody = notRecordedOnly
        ? { not_recorded_only: true }
        : selective
          ? { classes: payload!.classes! }
          : undefined
      const response = await postApplicationAction(route('application.seed'), jsonBody)
      const data = await response.json()
      if (response.ok && data.success) {
        const msg = data.message || 'Seeding completed.'
        addTerminalLine('success', msg)
        showSuccessToast(
          notRecordedOnly
            ? 'Not recorded seeders completed.'
            : selective
              ? 'Selected seeders completed.'
              : 'DatabaseSeeder completed.',
        )
        setSelectedSeederClasses([])
        setSeedersLoading(true)
        router.get(route('application.index'), getSeedersQueryParams({}), {
          ...applicationPartialVisit,
          only: ['seeders_configuration', 'migration_status', 'storage_stats', 'storage_link', 'cache_stats'],
          onFinish: () => setSeedersLoading(false),
          onCancel: () => setSeedersLoading(false),
        })
      } else {
        const err = data.message || 'Seeding failed.'
        addTerminalLine('error', err)
        showErrorToast(err)
      }
    } catch (error) {
      const errMsg = error instanceof Error ? error.message : 'Seeding failed.'
      addTerminalLine('error', errMsg)
      showErrorToast(errMsg)
    } finally {
      setIsSeeding(false)
    }
  }

  const handleClear = async () => {
    setIsClearing(true)
    setTerminalLines([])
    addTerminalLine('command', `$ php artisan cache:clear --type=${clearType}`)
    addTerminalLine('output', `Clearing ${clearType === 'all' ? 'all caches' : clearType + ' cache'}...`)

    try {
      const response = await fetch('/settings/application/clear', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
          'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'include',
        body: JSON.stringify({ type: clearType }),
      })

      const data = await response.json()

      if (data.success) {
        data.results?.forEach((result: { action: string; message: string; status: string }) => {
          if (result.status === 'success') {
            addTerminalLine('success', `✓ ${result.message}`)
          } else {
            addTerminalLine('error', `✗ ${result.message}`)
          }
        })
        addTerminalLine('success', 'Cache cleared successfully!')

        // Refresh page to update stats
        setTimeout(() => {
          router.reload({
            only: ['cache_stats', 'storage_stats', 'migration_status'],
          })
        }, 1000)
      } else {
        addTerminalLine('error', `Error: ${data.message}`)
      }
    } catch (error) {
      addTerminalLine('error', `Failed to clear cache: ${error instanceof Error ? error.message : 'Unknown error'}`)
    } finally {
      setIsClearing(false)
    }
  }

  const formatTimestamp = (date: Date) => {
    return date.toLocaleTimeString('en-US', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' })
  }

  const handleFooterSave = (e: React.FormEvent) => {
    e.preventDefault()

    postFooter(route('application.footer.update'), {
      preserveScroll: true,
      onSuccess: () => {
        router.reload({ only: ['footer_settings'] })
      },
      onError: (errors) => {
        console.error('Footer save errors:', errors)
      }
    })
  }

  const addQuickLink = () => {
    setFooterData('quick_links', [...(footerData.quick_links || []), { title: '', url: '' }])
  }

  const removeQuickLink = (index: number) => {
    setFooterData('quick_links', footerData.quick_links?.filter((_, i) => i !== index) || [])
  }

  const updateQuickLink = (index: number, field: 'title' | 'url', value: string) => {
    const updatedLinks = footerData.quick_links?.map((link, i) =>
      i === index ? { ...link, [field]: value } : link
    ) || []
    setFooterData('quick_links', updatedLinks)
  }

  const addLegalLink = () => {
    setFooterData('legal_links', [...(footerData.legal_links || []), { title: '', url: '' }])
  }

  const removeLegalLink = (index: number) => {
    setFooterData('legal_links', footerData.legal_links?.filter((_, i) => i !== index) || [])
  }

  const updateLegalLink = (index: number, field: 'title' | 'url', value: string) => {
    const updatedLinks = footerData.legal_links?.map((link, i) =>
      i === index ? { ...link, [field]: value } : link
    ) || []
    setFooterData('legal_links', updatedLinks)
  }

  return (
    <SettingsLayout activeTab="application">
      <div className="space-y-6">
        {/* Header */}
        <div>
          <h2 className="text-2xl font-bold text-gray-900 dark:text-white">Application Settings</h2>
          <p className="text-gray-600 dark:text-gray-400 mt-1">
            Manage performance, database configuration, caches, and footer settings
          </p>
        </div>

        <Tabs value={settingsTab} onValueChange={setSettingsTab} className="w-full">
          <TabsList className="grid w-full max-w-2xl grid-cols-3">
            <TabsTrigger value="performance">
              <Zap className="h-4 w-4 mr-2" />
              Performance
            </TabsTrigger>
            <TabsTrigger value="configuration">
              <Settings2 className="h-4 w-4 mr-2" />
              Configuration
            </TabsTrigger>
            <TabsTrigger value="footer">
              <Layout className="h-4 w-4 mr-2" />
              Footer
            </TabsTrigger>
          </TabsList>

          <TabsContent value="performance" className="space-y-6 mt-6">

        {/* Public storage link */}
        {storage_link.needs_connect && (
          <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.25 }}
          >
            <Alert className="border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30">
              <AlertTriangle className="h-4 w-4 text-amber-600 dark:text-amber-400" />
              <AlertDescription className="text-amber-900 dark:text-amber-100 sm:flex sm:items-start sm:justify-between sm:gap-4">
                <div className="space-y-2 flex-1">
                  <p className="font-medium">Public storage is not linked</p>
                  <p className="text-sm opacity-90">
                    Uploaded files in <code className="text-xs bg-amber-100/80 dark:bg-amber-900/40 px-1 rounded">storage/app/public</code> are
                    not reachable at <code className="text-xs bg-amber-100/80 dark:bg-amber-900/40 px-1 rounded">/storage</code> until the symlink exists.
                  </p>
                  {storage_link.link_is_directory_not_symlink && (
                    <p className="text-sm text-amber-800 dark:text-amber-200">
                      <strong>Note:</strong> <code className="text-xs px-1 rounded bg-amber-100/80 dark:bg-amber-900/40">{storage_link.link_path}</code> is a normal folder, not a symlink.
                      Connect will delete that folder, then create the correct link to{' '}
                      <code className="text-xs px-1 rounded bg-amber-100/80 dark:bg-amber-900/40">storage/app/public</code>
                      (anything only in that folder copy will be removed).
                    </p>
                  )}
                </div>
                <Button
                  type="button"
                  onClick={handleStorageLink}
                  disabled={isConnectingStorage}
                  className="mt-3 sm:mt-0 shrink-0 bg-amber-700 hover:bg-amber-800 text-white dark:bg-amber-600 dark:hover:bg-amber-500"
                >
                  {isConnectingStorage ? (
                    <>
                      <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                      Connecting…
                    </>
                  ) : (
                    <>
                      <Link2 className="h-4 w-4 mr-2" />
                      Connect storage
                    </>
                  )}
                </Button>
              </AlertDescription>
            </Alert>
          </motion.div>
        )}

        {/* Pending migrations */}
        {migration_status.check_error ? (
          <motion.div
            initial={{ opacity: 0, y: 12 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.25 }}
          >
            <Alert className="border-red-200 dark:border-red-900 bg-red-50 dark:bg-red-950/30">
              <XCircle className="h-4 w-4 text-red-600 dark:text-red-400" />
              <AlertDescription className="text-red-900 dark:text-red-100">
                <p className="font-medium">Could not check migrations</p>
                <p className="text-sm mt-1 opacity-90">{migration_status.check_error}</p>
              </AlertDescription>
            </Alert>
          </motion.div>
        ) : (
          migration_status.has_pending &&
          migration_status.pending_count !== null &&
          migration_status.pending_count > 0 && (
            <motion.div
              initial={{ opacity: 0, y: 12 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.25 }}
            >
              <Alert className="border-orange-200 dark:border-orange-800 bg-orange-50 dark:bg-orange-950/20">
                <Database className="h-4 w-4 text-orange-600 dark:text-orange-400" />
                <AlertDescription className="text-orange-950 dark:text-orange-100 sm:flex sm:items-start sm:justify-between sm:gap-4">
                  <div className="space-y-2 flex-1">
                    <p className="font-medium">
                      {migration_status.pending_count} pending migration{migration_status.pending_count === 1 ? '' : 's'}
                    </p>
                    {migration_status.pending_names.length > 0 && (
                      <ul className="text-sm font-mono list-disc list-inside max-h-32 overflow-y-auto opacity-95 space-y-0.5">
                        {migration_status.pending_names.map((name) => (
                          <li key={name} className="break-all">
                            {name}
                          </li>
                        ))}
                      </ul>
                    )}
                    {migration_status.pending_count > migration_status.pending_names.length && (
                      <p className="text-sm opacity-80">
                        …and {migration_status.pending_count - migration_status.pending_names.length} more
                      </p>
                    )}
                  </div>
                  <Button
                    type="button"
                    onClick={handleRunMigrations}
                    disabled={isMigrating}
                    className="mt-3 sm:mt-0 shrink-0 bg-orange-700 hover:bg-orange-800 text-white dark:bg-orange-600 dark:hover:bg-orange-500"
                  >
                    {isMigrating ? (
                      <>
                        <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                        Running…
                      </>
                    ) : (
                      <>
                        <Database className="h-4 w-4 mr-2" />
                        Run migrations
                      </>
                    )}
                  </Button>
                </AlertDescription>
              </Alert>
            </motion.div>
          )
        )}

        {/* Stats Cards */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.1 }}
          >
            <Card className="border-gray-200 dark:border-gray-800 bg-gradient-to-br from-blue-50 to-blue-100/50 dark:from-blue-900/20 dark:to-blue-800/10">
              <CardContent className="p-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Cache Driver</p>
                    <p className="text-2xl font-bold text-gray-900 dark:text-white mt-1">
                      {cache_stats.driver || 'unknown'}
                    </p>
                  </div>
                  <div className="p-3 bg-blue-500/10 rounded-lg">
                    <Database className="h-6 w-6 text-blue-600 dark:text-blue-400" />
                  </div>
                </div>
              </CardContent>
            </Card>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.2 }}
          >
            <Card className="border-gray-200 dark:border-gray-800 bg-gradient-to-br from-purple-50 to-purple-100/50 dark:from-purple-900/20 dark:to-purple-800/10">
              <CardContent className="p-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Cache Size</p>
                    <p className="text-2xl font-bold text-gray-900 dark:text-white mt-1">
                      {storage_stats.cache_size_formatted || '0 B'}
                    </p>
                  </div>
                  <div className="p-3 bg-purple-500/10 rounded-lg">
                    <HardDrive className="h-6 w-6 text-purple-600 dark:text-purple-400" />
                  </div>
                </div>
              </CardContent>
            </Card>
          </motion.div>

          <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.3 }}
          >
            <Card className="border-gray-200 dark:border-gray-800 bg-gradient-to-br from-green-50 to-green-100/50 dark:from-green-900/20 dark:to-green-800/10">
              <CardContent className="p-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium text-gray-600 dark:text-gray-400">Logs Size</p>
                    <p className="text-2xl font-bold text-gray-900 dark:text-white mt-1">
                      {storage_stats.logs_size_formatted || '0 B'}
                    </p>
                  </div>
                  <div className="p-3 bg-green-500/10 rounded-lg">
                    <Terminal className="h-6 w-6 text-green-600 dark:text-green-400" />
                  </div>
                </div>
              </CardContent>
            </Card>
          </motion.div>
        </div>

        {/* Action Cards */}
        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
          {/* Optimize Card */}
          <motion.div
            initial={{ opacity: 0, scale: 0.95 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{ delay: 0.4 }}
          >
            <Card className="border-gray-200 dark:border-gray-800 hover:shadow-lg transition-shadow">
              <CardHeader>
                <div className="flex items-center gap-3">
                  <div className="p-2 bg-gradient-to-br from-purple-600 to-blue-600 rounded-lg">
                    <Zap className="h-5 w-5 text-white" />
                  </div>
                  <div>
                    <CardTitle className="text-lg">Optimize Application</CardTitle>
                    <CardDescription>
                      Clear caches and rebuild optimized files for better performance
                    </CardDescription>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-2">
                  <p className="text-sm text-gray-600 dark:text-gray-400">
                    This will:
                  </p>
                  <ul className="text-sm text-gray-600 dark:text-gray-400 space-y-1 list-disc list-inside">
                    <li>Clear all caches</li>
                    <li>Rebuild configuration cache</li>
                    <li>Rebuild route cache</li>
                    <li>Rebuild view cache</li>
                    <li>Optimize autoloader</li>
                  </ul>
                </div>
                <Button
                  onClick={handleOptimize}
                  disabled={isOptimizing}
                  className="w-full bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white"
                >
                  {isOptimizing ? (
                    <>
                      <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                      Optimizing...
                    </>
                  ) : (
                    <>
                      <Zap className="h-4 w-4 mr-2" />
                      Optimize Application
                    </>
                  )}
                </Button>
              </CardContent>
            </Card>
          </motion.div>

          {/* Clear Cache Card */}
          <motion.div
            initial={{ opacity: 0, scale: 0.95 }}
            animate={{ opacity: 1, scale: 1 }}
            transition={{ delay: 0.5 }}
          >
            <Card className="border-gray-200 dark:border-gray-800 hover:shadow-lg transition-shadow">
              <CardHeader>
                <div className="flex items-center gap-3">
                  <div className="p-2 bg-gradient-to-br from-red-600 to-red-500 rounded-lg">
                    <Trash2 className="h-5 w-5 text-white" />
                  </div>
                  <div>
                    <CardTitle className="text-lg">Clear Cache</CardTitle>
                    <CardDescription>
                      Clear specific or all application caches
                    </CardDescription>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="space-y-2">
                  <label className="text-sm font-medium text-gray-700 dark:text-gray-300">
                    Cache Type
                  </label>
                  <select
                    value={clearType}
                    onChange={(e) => setClearType(e.target.value as typeof clearType)}
                    className="w-full px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-md bg-white dark:bg-gray-800 text-gray-900 dark:text-white"
                  >
                    <option value="all">All Caches</option>
                    <option value="cache">Application Cache</option>
                    <option value="config">Config Cache</option>
                    <option value="route">Route Cache</option>
                    <option value="view">View Cache</option>
                  </select>
                </div>
                <Button
                  onClick={handleClear}
                  disabled={isClearing}
                  className="w-full bg-gradient-to-r from-red-600 to-red-500 hover:from-red-700 hover:to-red-600 text-white"
                >
                  {isClearing ? (
                    <>
                      <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                      Clearing...
                    </>
                  ) : (
                    <>
                      <Trash2 className="h-4 w-4 mr-2" />
                      Clear Cache
                    </>
                  )}
                </Button>
              </CardContent>
            </Card>
          </motion.div>
        </div>

        {/* Terminal Output */}
        <AnimatePresence>
          {showTerminal && (
            <motion.div
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              exit={{ opacity: 0, y: -20 }}
              transition={{ duration: 0.3 }}
            >
              <Card className="border-gray-200 dark:border-gray-800 bg-gray-900 dark:bg-black">
                <CardHeader className="border-b border-gray-700">
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <Terminal className="h-5 w-5 text-green-400" />
                      <CardTitle className="text-green-400 font-mono text-sm">Terminal Output</CardTitle>
                    </div>
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => {
                        setShowTerminal(false)
                        setTerminalLines([])
                      }}
                      className="text-gray-400 hover:text-white"
                    >
                      Clear
                    </Button>
                  </div>
                </CardHeader>
                <CardContent className="p-0">
                  <div className="p-4 font-mono text-sm max-h-96 overflow-y-auto">
                    <div className="space-y-1">
                      {terminalLines.map((line) => (
                        <motion.div
                          key={line.id}
                          initial={{ opacity: 0, x: -10 }}
                          animate={{ opacity: 1, x: 0 }}
                          className="flex items-start gap-2"
                        >
                          <span className="text-gray-500 text-xs flex-shrink-0">
                            [{formatTimestamp(line.timestamp)}]
                          </span>
                          {line.type === 'command' && (
                            <span className="text-blue-400 flex-1">
                              <span className="text-green-400">$</span> {line.content}
                            </span>
                          )}
                          {line.type === 'output' && (
                            <span className="text-gray-300 flex-1">{line.content}</span>
                          )}
                          {line.type === 'success' && (
                            <span className="text-green-400 flex-1 flex items-center gap-1">
                              <CheckCircle2 className="h-3 w-3" />
                              {line.content}
                            </span>
                          )}
                          {line.type === 'error' && (
                            <span className="text-red-400 flex-1 flex items-center gap-1">
                              <XCircle className="h-3 w-3" />
                              {line.content}
                            </span>
                          )}
                        </motion.div>
                      ))}
                    </div>
                    {terminalLines.length === 0 && (
                      <div className="text-gray-500 text-center py-8">
                        <Terminal className="h-8 w-8 mx-auto mb-2 opacity-50" />
                        <p>Terminal output will appear here...</p>
                      </div>
                    )}
                  </div>
                </CardContent>
              </Card>
            </motion.div>
          )}
        </AnimatePresence>

        {/* Info Alert */}
        <motion.div
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ delay: 0.6 }}
        >
          <Alert className="border-blue-200 dark:border-blue-800 bg-blue-50 dark:bg-blue-900/20">
            <Sparkles className="h-4 w-4 text-blue-600 dark:text-blue-400" />
            <AlertDescription className="text-blue-800 dark:text-blue-200">
              <strong>Tip:</strong> Optimize your application regularly to maintain peak performance.
              Clear caches when you make configuration changes or after deployments.
            </AlertDescription>
          </Alert>
        </motion.div>
          </TabsContent>

          <TabsContent value="configuration" className="space-y-4 mt-6">
            <Tabs
              value={configInnerTab}
              onValueChange={(v) => setConfigInnerTab(v as 'migrations' | 'seeders')}
              className="w-full"
            >
              <TabsList className="grid w-full max-w-md grid-cols-2 h-auto gap-1 p-1 bg-gray-100 dark:bg-gray-900/60">
                <TabsTrigger value="migrations" className="gap-2 data-[state=active]:bg-white dark:data-[state=active]:bg-gray-800">
                  <Database className="h-4 w-4 shrink-0" />
                  Migrations
                </TabsTrigger>
                <TabsTrigger value="seeders" className="gap-2 data-[state=active]:bg-white dark:data-[state=active]:bg-gray-800">
                  <Sprout className="h-4 w-4 shrink-0" />
                  Seeders
                </TabsTrigger>
              </TabsList>

              <TabsContent value="migrations" className="mt-4 space-y-0">
                <Card className="border-gray-200 dark:border-gray-800">
                  <CardHeader>
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                      <div className="flex items-start gap-3">
                        <div className="p-2 rounded-lg bg-slate-100 dark:bg-slate-800">
                          <Database className="h-5 w-5 text-slate-700 dark:text-slate-200" />
                        </div>
                        <div>
                          <CardTitle>Migrations</CardTitle>
                          <CardDescription>
                            Every migration file in <code className="text-xs">database/migrations</code> and whether it has been applied.
                          </CardDescription>
                        </div>
                      </div>
                      <div className="flex flex-wrap gap-2 items-center text-sm min-h-[1.75rem]">
                        {migrationsLoading && migrations_configuration === null ? (
                          <Loader2 className="h-5 w-5 animate-spin text-gray-400" aria-hidden />
                        ) : migrations_configuration ? (
                          <>
                            <Badge variant="secondary" className="bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100 border-0">
                              Ran: {migrations_configuration.ran_count}
                            </Badge>
                            <Badge variant="secondary" className="bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100 border-0">
                              Pending: {migrations_configuration.pending_count}
                            </Badge>
                          </>
                        ) : null}
                      </div>
                    </div>
                  </CardHeader>
                  <CardContent className="space-y-4">
                    {migrationsLoading && migrations_configuration === null ? (
                      <div className="flex flex-col items-center justify-center py-16 gap-2 text-gray-500 dark:text-gray-400">
                        <Loader2 className="h-8 w-8 animate-spin" aria-hidden />
                        <span className="text-sm">Loading migrations…</span>
                      </div>
                    ) : migrations_configuration?.check_error ? (
                      <Alert variant="destructive" className="border-red-200 dark:border-red-900">
                        <XCircle className="h-4 w-4" />
                        <AlertDescription>{migrations_configuration.check_error}</AlertDescription>
                      </Alert>
                    ) : migrations_configuration ? (
                      <>
                        {!migrations_configuration.repository_exists && (
                          <Alert className="border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30">
                            <AlertTriangle className="h-4 w-4 text-amber-600" />
                            <AlertDescription className="text-amber-950 dark:text-amber-100">
                              The migrations table is not present yet. All migrations are listed as <strong>Pending</strong> until you run{' '}
                              <code className="text-xs">php artisan migrate</code> (or use <strong>Run migrations</strong> on the Performance tab).
                            </AlertDescription>
                          </Alert>
                        )}
                        <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                          <div className="space-y-2 flex-1 min-w-[12rem]">
                            <Label htmlFor="migration-filter">Filter</Label>
                            <Input
                              id="migration-filter"
                              value={migrationFilterInput}
                              onChange={(e) => {
                                const v = e.target.value
                                setMigrationFilterInput(v)
                                if (migrationFilterDebounceRef.current) {
                                  clearTimeout(migrationFilterDebounceRef.current)
                                }
                                migrationFilterDebounceRef.current = setTimeout(() => {
                                  migrationFilterDebounceRef.current = null
                                  visitMigrationsConfiguration(
                                    getMigrationsQueryParams({ migration_q: v, migration_page: 1 }),
                                  )
                                }, 300)
                              }}
                              placeholder="Search by migration name, path, or batch…"
                              className="max-w-md"
                            />
                          </div>
                          <div className="space-y-2 w-full sm:w-auto">
                            <Label htmlFor="migration-page-size">Per page</Label>
                            <select
                              id="migration-page-size"
                              title="Rows per page for migrations"
                              value={String(migrations_configuration.per_page ?? 25)}
                              onChange={(e) => {
                                const per = Number(e.target.value)
                                visitMigrationsConfiguration(
                                  getMigrationsQueryParams({ migration_per_page: per, migration_page: 1 }),
                                )
                              }}
                              className="w-full sm:w-36 px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-md bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm"
                            >
                              <option value={10}>10</option>
                              <option value={25}>25</option>
                              <option value={50}>50</option>
                              <option value={100}>100</option>
                            </select>
                          </div>
                        </div>
                        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                          <div className="flex flex-wrap gap-2">
                            <Button
                              type="button"
                              size="sm"
                              className="bg-slate-800 hover:bg-slate-900 text-white dark:bg-slate-700 dark:hover:bg-slate-600"
                              disabled={isMigrating}
                              onClick={() => handleRunMigrations()}
                            >
                              {isMigrating ? (
                                <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                              ) : (
                                <Database className="h-4 w-4 mr-2" />
                              )}
                              Run all pending
                            </Button>
                            <Button
                              type="button"
                              size="sm"
                              variant="secondary"
                              disabled={
                                isMigrating ||
                                selectedMigrationNames.length === 0 ||
                                !migrations_configuration.repository_exists
                              }
                              onClick={() => handleRunMigrations({ migrations: selectedMigrationNames })}
                            >
                              Run selected ({selectedMigrationNames.length})
                            </Button>
                            <Button
                              type="button"
                              size="sm"
                              variant="outline"
                              disabled={
                                isMigrating ||
                                pendingMigrationsOnPage.length === 0 ||
                                !migrations_configuration.repository_exists
                              }
                              onClick={toggleSelectAllPendingMigrationPage}
                            >
                              {pendingMigrationsOnPage.length > 0 &&
                              pendingMigrationsOnPage.every((r) => selectedMigrationNames.includes(r.name))
                                ? 'Deselect page'
                                : 'Select pending on page'}
                            </Button>
                            <Button
                              type="button"
                              size="sm"
                              variant="ghost"
                              disabled={selectedMigrationNames.length === 0}
                              onClick={() => setSelectedMigrationNames([])}
                            >
                              Clear selection
                            </Button>
                          </div>
                          {!migrations_configuration.repository_exists && (
                            <p className="text-xs text-amber-700 dark:text-amber-300 max-w-xl">
                              Run <strong>Run all pending</strong> once to create the migrations table; then you can select individual files.
                            </p>
                          )}
                        </div>
                        <div className="rounded-md border border-gray-200 dark:border-gray-800 overflow-hidden">
                          <div className="max-h-[min(480px,55vh)] overflow-auto">
                            <table className="w-full text-sm">
                              <thead className="sticky top-0 bg-gray-50 dark:bg-gray-900/80 z-10 border-b border-gray-200 dark:border-gray-800">
                                <tr>
                                  <th className="w-10 px-2 py-2 text-center">
                                    <Checkbox
                                      checked={migrationHeaderCheckboxState}
                                      onCheckedChange={() => toggleSelectAllPendingMigrationPage()}
                                      disabled={
                                        isMigrating ||
                                        pendingMigrationsOnPage.length === 0 ||
                                        !migrations_configuration.repository_exists
                                      }
                                      title="Select all pending migrations on this page"
                                      aria-label="Select all pending migrations on this page"
                                    />
                                  </th>
                                  <th className="text-left font-medium px-3 py-2">Migration</th>
                                  <th className="text-left font-medium px-3 py-2 w-28">Status</th>
                                  <th className="text-left font-medium px-3 py-2 w-24">Batch</th>
                                </tr>
                              </thead>
                              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                {(migrations_configuration.items ?? []).map((row) => (
                                  <tr key={row.name} className="hover:bg-gray-50/80 dark:hover:bg-gray-900/40">
                                    <td className="px-2 py-2 align-middle text-center w-10">
                                      {row.status === 'pending' && migrations_configuration.repository_exists ? (
                                        <Checkbox
                                          checked={selectedMigrationNames.includes(row.name)}
                                          onCheckedChange={() => toggleMigrationSelected(row.name)}
                                          disabled={isMigrating}
                                          title={`Select ${row.name}`}
                                          aria-label={`Select migration ${row.name}`}
                                        />
                                      ) : (
                                        <span className="inline-block w-4 h-4" aria-hidden />
                                      )}
                                    </td>
                                    <td className="px-3 py-2 align-top">
                                      <div className="font-mono text-xs break-all text-gray-900 dark:text-gray-100">{row.name}</div>
                                      <div className="text-[11px] text-gray-500 dark:text-gray-400 mt-0.5">{row.path}</div>
                                    </td>
                                    <td className="px-3 py-2 align-top">
                                      {row.status === 'ran' ? (
                                        <Badge className="bg-emerald-600 hover:bg-emerald-600 text-white border-0">Ran</Badge>
                                      ) : (
                                        <Badge variant="secondary" className="bg-amber-100 text-amber-950 dark:bg-amber-900/50 dark:text-amber-100 border-amber-200 dark:border-amber-800">
                                          Pending
                                        </Badge>
                                      )}
                                    </td>
                                    <td className="px-3 py-2 align-top font-mono text-xs text-gray-600 dark:text-gray-300">
                                      {row.batch ?? '—'}
                                    </td>
                                  </tr>
                                ))}
                              </tbody>
                            </table>
                          </div>
                        </div>
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-t border-gray-100 dark:border-gray-800 pt-4">
                          <p className="text-xs text-gray-500 dark:text-gray-400">
                            Showing {migrationRange.from}–{migrationRange.to} of {migrationRange.total} migrations
                            {(migrations_configuration.filter ?? '').trim() ? ' (filtered)' : ''}
                            {migrationRange.lastPage > 1 ? ` · Page ${migrationRange.page} of ${migrationRange.lastPage}` : ''}.
                          </p>
                          {migrationRange.lastPage > 1 && (
                            <div className="flex flex-wrap items-center gap-1 justify-end">
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8 px-2"
                                disabled={migrationRange.page <= 1}
                                onClick={() =>
                                  visitMigrationsConfiguration(getMigrationsQueryParams({ migration_page: 1 }))
                                }
                                aria-label="First page"
                              >
                                <ChevronsLeft className="h-4 w-4" />
                              </Button>
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8 px-2"
                                disabled={migrationRange.page <= 1}
                                onClick={() =>
                                  visitMigrationsConfiguration(
                                    getMigrationsQueryParams({
                                      migration_page: Math.max(1, migrationRange.page - 1),
                                    }),
                                  )
                                }
                                aria-label="Previous page"
                              >
                                <ChevronLeft className="h-4 w-4" />
                              </Button>
                              <span className="text-sm tabular-nums px-2 text-gray-700 dark:text-gray-300">
                                {migrationRange.page} / {migrationRange.lastPage}
                              </span>
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8 px-2"
                                disabled={migrationRange.page >= migrationRange.lastPage}
                                onClick={() =>
                                  visitMigrationsConfiguration(
                                    getMigrationsQueryParams({
                                      migration_page: Math.min(
                                        migrationRange.lastPage,
                                        migrationRange.page + 1,
                                      ),
                                    }),
                                  )
                                }
                                aria-label="Next page"
                              >
                                <ChevronRight className="h-4 w-4" />
                              </Button>
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8 px-2"
                                disabled={migrationRange.page >= migrationRange.lastPage}
                                onClick={() =>
                                  visitMigrationsConfiguration(
                                    getMigrationsQueryParams({ migration_page: migrationRange.lastPage }),
                                  )
                                }
                                aria-label="Last page"
                              >
                                <ChevronsRight className="h-4 w-4" />
                              </Button>
                            </div>
                          )}
                        </div>
                      </>
                    ) : null}
                  </CardContent>
                </Card>
              </TabsContent>

              <TabsContent value="seeders" className="mt-4 space-y-0">
                <Card className="border-gray-200 dark:border-gray-800">
                  <CardHeader>
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                      <div className="flex items-start gap-3">
                        <div className="p-2 rounded-lg bg-slate-100 dark:bg-slate-800">
                          <Sprout className="h-5 w-5 text-slate-700 dark:text-slate-200" />
                        </div>
                        <div>
                          <CardTitle>Seeders</CardTitle>
                          <CardDescription>
                            Seeder classes in <code className="text-xs">database/seeders</code>. Completion is tracked in the{' '}
                            <code className="text-xs">seed_runs</code> table when using <code className="text-xs">DatabaseSeeder</code> or the{' '}
                            <code className="text-xs">TracksSeedCompletion</code> trait.
                          </CardDescription>
                        </div>
                      </div>
                      <div className="flex flex-row flex-nowrap gap-2 items-center text-sm min-h-[1.75rem] shrink-0">
                        {seedersLoading && seeders_configuration === null ? (
                          <Loader2 className="h-5 w-5 animate-spin text-gray-400" aria-hidden />
                        ) : seeders_configuration ? (
                          <>
                            <Badge variant="secondary" className="bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100 border-0 whitespace-nowrap">
                              Recorded: {seeders_configuration.seeded_count}
                            </Badge>
                            <Badge variant="secondary" className="bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100 border-0 whitespace-nowrap">
                              Not recorded: {seeders_configuration.not_seeded_count}
                            </Badge>
                          </>
                        ) : null}
                      </div>
                    </div>
                  </CardHeader>
                  <CardContent className="space-y-4">
                    {seedersLoading && seeders_configuration === null ? (
                      <div className="flex flex-col items-center justify-center py-16 gap-2 text-gray-500 dark:text-gray-400">
                        <Loader2 className="h-8 w-8 animate-spin" aria-hidden />
                        <span className="text-sm">Loading seeders…</span>
                      </div>
                    ) : seeders_configuration?.check_error ? (
                      <Alert variant="destructive" className="border-red-200 dark:border-red-900">
                        <XCircle className="h-4 w-4" />
                        <AlertDescription>{seeders_configuration.check_error}</AlertDescription>
                      </Alert>
                    ) : seeders_configuration ? (
                      <>
                        {!seeders_configuration.table_exists && (
                          <Alert className="border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-950/30">
                            <AlertTriangle className="h-4 w-4 text-amber-600" />
                            <AlertDescription className="text-amber-950 dark:text-amber-100">
                              The <code className="text-xs">seed_runs</code> table does not exist yet. Run pending migrations so seeder completion can be tracked.
                            </AlertDescription>
                          </Alert>
                        )}
                        <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
                          <div className="space-y-2 flex-1 min-w-[12rem]">
                            <Label htmlFor="seeder-filter">Filter</Label>
                            <Input
                              id="seeder-filter"
                              value={seederFilterInput}
                              onChange={(e) => {
                                const v = e.target.value
                                setSeederFilterInput(v)
                                if (seederFilterDebounceRef.current) {
                                  clearTimeout(seederFilterDebounceRef.current)
                                }
                                seederFilterDebounceRef.current = setTimeout(() => {
                                  seederFilterDebounceRef.current = null
                                  visitSeedersConfiguration(
                                    getSeedersQueryParams({ seeder_q: v, seeder_page: 1 }),
                                  )
                                }, 300)
                              }}
                              placeholder="Search by class or file name…"
                              className="max-w-md"
                            />
                          </div>
                          <div className="space-y-2 w-full sm:w-auto">
                            <Label htmlFor="seeder-page-size">Per page</Label>
                            <select
                              id="seeder-page-size"
                              title="Rows per page for seeders"
                              value={String(seeders_configuration.per_page ?? 25)}
                              onChange={(e) => {
                                const per = Number(e.target.value)
                                visitSeedersConfiguration(
                                  getSeedersQueryParams({ seeder_per_page: per, seeder_page: 1 }),
                                )
                              }}
                              className="w-full sm:w-36 px-3 py-2 border border-gray-300 dark:border-gray-700 rounded-md bg-white dark:bg-gray-800 text-gray-900 dark:text-white text-sm"
                            >
                              <option value={10}>10</option>
                              <option value={25}>25</option>
                              <option value={50}>50</option>
                              <option value={100}>100</option>
                            </select>
                          </div>
                        </div>
                        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                          <div className="flex flex-wrap gap-2">
                            <Button
                              type="button"
                              size="sm"
                              className="bg-emerald-800 hover:bg-emerald-900 text-white dark:bg-emerald-700 dark:hover:bg-emerald-600"
                              disabled={isSeeding}
                              onClick={() => handleRunSeeders()}
                            >
                              {isSeeding ? (
                                <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                              ) : (
                                <Sprout className="h-4 w-4 mr-2" />
                              )}
                              Run DatabaseSeeder
                            </Button>
                            <Button
                              type="button"
                              size="sm"
                              variant="secondary"
                              className="bg-amber-100 text-amber-950 hover:bg-amber-200 dark:bg-amber-900/45 dark:text-amber-100 dark:hover:bg-amber-900/65 border border-amber-300/80 dark:border-amber-800"
                              disabled={isSeeding || seeders_configuration.not_seeded_count === 0}
                              onClick={() => handleRunSeeders({ notRecordedOnly: true })}
                              title="Run db:seed --class for every seeder not yet in seed_runs"
                            >
                              Run not recorded ({seeders_configuration.not_seeded_count})
                            </Button>
                            <Button
                              type="button"
                              size="sm"
                              variant="secondary"
                              disabled={isSeeding || selectedSeederClasses.length === 0}
                              onClick={() => handleRunSeeders({ classes: selectedSeederClasses })}
                            >
                              Run selected ({selectedSeederClasses.length})
                            </Button>
                            <Button
                              type="button"
                              size="sm"
                              variant="outline"
                              disabled={isSeeding || seedersOnPage.length === 0}
                              onClick={toggleSelectAllSeedersPage}
                            >
                              {allSeedersOnPageSelected ? 'Deselect page' : 'Select all on page'}
                            </Button>
                            <Button
                              type="button"
                              size="sm"
                              variant="ghost"
                              disabled={selectedSeederClasses.length === 0}
                              onClick={() => setSelectedSeederClasses([])}
                            >
                              Clear selection
                            </Button>
                          </div>
                          <p className="text-xs text-gray-500 dark:text-gray-400 max-w-xl">
                            <strong>Run DatabaseSeeder</strong> runs <code className="text-[10px]">DatabaseSeeder</code>.{' '}
                            <strong>Run not recorded</strong> runs <code className="text-[10px]">db:seed --class</code> for each seeder with no{' '}
                            <code className="text-[10px]">seed_runs</code> row.{' '}
                            <strong>Run selected</strong> runs only the checked classes.
                          </p>
                        </div>
                        <div className="rounded-md border border-gray-200 dark:border-gray-800 overflow-hidden">
                          <div className="max-h-[min(480px,55vh)] overflow-auto">
                            <table className="w-full text-sm">
                              <thead className="sticky top-0 bg-gray-50 dark:bg-gray-900/80 z-10 border-b border-gray-200 dark:border-gray-800">
                                <tr>
                                  <th className="w-10 px-2 py-2 text-center">
                                    <Checkbox
                                      checked={seederHeaderCheckboxState}
                                      onCheckedChange={() => toggleSelectAllSeedersPage()}
                                      disabled={isSeeding || seedersOnPage.length === 0}
                                      title="Select all seeders on this page"
                                      aria-label="Select all seeders on this page"
                                    />
                                  </th>
                                  <th className="text-left font-medium px-3 py-2">Seeder</th>
                                  <th className="text-left font-medium px-3 py-2 w-36">Status</th>
                                  <th className="text-left font-medium px-3 py-2 min-w-[9rem]">Last run</th>
                                </tr>
                              </thead>
                              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                                {(seeders_configuration.items ?? []).map((row) => (
                                  <tr key={row.class} className="hover:bg-gray-50/80 dark:hover:bg-gray-900/40">
                                    <td className="px-2 py-2 align-middle text-center w-10">
                                      <Checkbox
                                        checked={selectedSeederClasses.includes(row.class)}
                                        onCheckedChange={() => toggleSeederSelected(row.class)}
                                        disabled={isSeeding}
                                        title={`Select ${row.short_name}`}
                                        aria-label={`Select seeder ${row.short_name}`}
                                      />
                                    </td>
                                    <td className="px-3 py-2 align-top">
                                      <div className="font-medium text-gray-900 dark:text-gray-100">{row.short_name}</div>
                                      <div className="text-[11px] font-mono text-gray-500 dark:text-gray-400 break-all mt-0.5">{row.class}</div>
                                    </td>
                                    <td className="px-3 py-2 align-top">
                                      {row.seeded ? (
                                        <Badge className="bg-emerald-600 hover:bg-emerald-600 text-white border-0">Recorded</Badge>
                                      ) : (
                                        <Badge variant="secondary" className="bg-amber-100 text-amber-950 dark:bg-amber-900/50 dark:text-amber-100 border-amber-200 dark:border-amber-800">
                                          Not recorded
                                        </Badge>
                                      )}
                                    </td>
                                    <td className="px-3 py-2 align-top text-xs text-gray-600 dark:text-gray-300 whitespace-nowrap">
                                      {formatSeedRunAt(row.last_run_at)}
                                    </td>
                                  </tr>
                                ))}
                              </tbody>
                            </table>
                          </div>
                        </div>
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-t border-gray-100 dark:border-gray-800 pt-4">
                          <p className="text-xs text-gray-500 dark:text-gray-400">
                            Showing {seederRange.from}–{seederRange.to} of {seederRange.total} seeders
                            {(seeders_configuration.filter ?? '').trim() ? ' (filtered)' : ''}
                            {seederRange.lastPage > 1 ? ` · Page ${seederRange.page} of ${seederRange.lastPage}` : ''}.
                          </p>
                          {seederRange.lastPage > 1 && (
                            <div className="flex flex-wrap items-center gap-1 justify-end">
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8 px-2"
                                disabled={seederRange.page <= 1}
                                onClick={() =>
                                  visitSeedersConfiguration(getSeedersQueryParams({ seeder_page: 1 }))
                                }
                                aria-label="First page"
                              >
                                <ChevronsLeft className="h-4 w-4" />
                              </Button>
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8 px-2"
                                disabled={seederRange.page <= 1}
                                onClick={() =>
                                  visitSeedersConfiguration(
                                    getSeedersQueryParams({
                                      seeder_page: Math.max(1, seederRange.page - 1),
                                    }),
                                  )
                                }
                                aria-label="Previous page"
                              >
                                <ChevronLeft className="h-4 w-4" />
                              </Button>
                              <span className="text-sm tabular-nums px-2 text-gray-700 dark:text-gray-300">
                                {seederRange.page} / {seederRange.lastPage}
                              </span>
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8 px-2"
                                disabled={seederRange.page >= seederRange.lastPage}
                                onClick={() =>
                                  visitSeedersConfiguration(
                                    getSeedersQueryParams({
                                      seeder_page: Math.min(
                                        seederRange.lastPage,
                                        seederRange.page + 1,
                                      ),
                                    }),
                                  )
                                }
                                aria-label="Next page"
                              >
                                <ChevronRight className="h-4 w-4" />
                              </Button>
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="h-8 px-2"
                                disabled={seederRange.page >= seederRange.lastPage}
                                onClick={() =>
                                  visitSeedersConfiguration(
                                    getSeedersQueryParams({ seeder_page: seederRange.lastPage }),
                                  )
                                }
                                aria-label="Last page"
                              >
                                <ChevronsRight className="h-4 w-4" />
                              </Button>
                            </div>
                          )}
                        </div>
                      </>
                    ) : null}
                  </CardContent>
                </Card>
              </TabsContent>
            </Tabs>
          </TabsContent>

          <TabsContent value="footer" className="space-y-6 mt-6">
            <Card className="border-gray-200 dark:border-gray-800">
              <CardHeader>
                <CardTitle>Footer Settings</CardTitle>
                <CardDescription>
                  Customize the landing page footer content. All fields are optional.
                </CardDescription>
              </CardHeader>
              <CardContent className="space-y-6">
                {/* Description */}
                <div className="space-y-2">
                  <Label htmlFor="description">Description</Label>
                    <Textarea
                      id="description"
                      value={footerData.description || ''}
                      onChange={(e) => setFooterData('description', e.target.value)}
                      placeholder="Enter footer description..."
                      rows={4}
                      className="w-full"
                    />
                </div>

                {/* Social Links */}
                <div className="space-y-4">
                  <Label>Social Media Links</Label>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="facebook">Facebook URL</Label>
                      <Input
                        id="facebook"
                        type="url"
                        value={footerData.social_links?.facebook || ''}
                        onChange={(e) => setFooterData('social_links', { ...footerData.social_links, facebook: e.target.value })}
                        placeholder="https://facebook.com/..."
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="twitter">Twitter URL</Label>
                      <Input
                        id="twitter"
                        type="url"
                        value={footerData.social_links?.twitter || ''}
                        onChange={(e) => setFooterData(prev => ({
                          ...prev,
                          social_links: { ...prev.social_links, twitter: e.target.value }
                        }))}
                        placeholder="https://twitter.com/..."
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="instagram">Instagram URL</Label>
                      <Input
                        id="instagram"
                        type="url"
                        value={footerData.social_links?.instagram || ''}
                        onChange={(e) => setFooterData('social_links', { ...footerData.social_links, instagram: e.target.value })}
                        placeholder="https://instagram.com/..."
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="linkedin">LinkedIn URL</Label>
                      <Input
                        id="linkedin"
                        type="url"
                        value={footerData.social_links?.linkedin || ''}
                        onChange={(e) => setFooterData('social_links', { ...footerData.social_links, linkedin: e.target.value })}
                        placeholder="https://linkedin.com/..."
                      />
                    </div>
                  </div>
                </div>

                {/* Quick Links */}
                <div className="space-y-4">
                  <div className="flex items-center justify-between">
                    <Label>Quick Links</Label>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={addQuickLink}
                    >
                      <Plus className="h-4 w-4 mr-2" />
                      Add Link
                    </Button>
                  </div>
                  <div className="space-y-3">
                    {footerData.quick_links?.map((link, index) => (
                      <div key={index} className="flex gap-2">
                        <Input
                          placeholder="Link Title"
                          value={link.title}
                          onChange={(e) => updateQuickLink(index, 'title', e.target.value)}
                          className="flex-1"
                        />
                        <Input
                          placeholder="URL"
                          value={link.url}
                          onChange={(e) => updateQuickLink(index, 'url', e.target.value)}
                          className="flex-1"
                        />
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          onClick={() => removeQuickLink(index)}
                        >
                          <X className="h-4 w-4" />
                        </Button>
                      </div>
                    ))}
                    {(!footerData.quick_links || footerData.quick_links.length === 0) && (
                      <p className="text-sm text-gray-500 dark:text-gray-400">No quick links added yet.</p>
                    )}
                  </div>
                </div>

                {/* Contact Info */}
                <div className="space-y-4">
                  <Label>Contact Information</Label>
                  <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div className="space-y-2">
                      <Label htmlFor="contact_email">Email</Label>
                      <Input
                        id="contact_email"
                        type="email"
                        value={footerData.contact_email || ''}
                        onChange={(e) => setFooterData(prev => ({ ...prev, contact_email: e.target.value }))}
                        placeholder="support@believeinunity.org"
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="contact_phone">Phone</Label>
                      <Input
                        id="contact_phone"
                        type="tel"
                        value={footerData.contact_phone || ''}
                        onChange={(e) => setFooterData('contact_phone', e.target.value)}
                        placeholder="+1 (555) 123-4567"
                      />
                    </div>
                  </div>
                  <div className="space-y-2">
                    <Label htmlFor="contact_address">Address</Label>
                    <Textarea
                      id="contact_address"
                      value={footerData.contact_address || ''}
                      onChange={(e) => setFooterData(prev => ({ ...prev, contact_address: e.target.value }))}
                      placeholder="123 Street Name&#10;City, State ZIP"
                      rows={3}
                    />
                    <p className="text-xs text-gray-500 dark:text-gray-400">Use line breaks for multi-line addresses</p>
                  </div>
                </div>

                {/* Copyright */}
                <div className="space-y-2">
                  <Label htmlFor="copyright_text">Copyright Text</Label>
                  <Input
                    id="copyright_text"
                    value={footerData.copyright_text || ''}
                    onChange={(e) => setFooterData('copyright_text', e.target.value)}
                    placeholder={`${new Date().getFullYear()} ${import.meta.env.VITE_APP_NAME}. All rights reserved.`}
                  />
                </div>

                {/* Legal Links */}
                <div className="space-y-4">
                  <div className="flex items-center justify-between">
                    <Label>Legal Links</Label>
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      onClick={addLegalLink}
                    >
                      <Plus className="h-4 w-4 mr-2" />
                      Add Link
                    </Button>
                  </div>
                  <div className="space-y-3">
                    {footerData.legal_links?.map((link, index) => (
                      <div key={index} className="flex gap-2">
                        <Input
                          placeholder="Link Title"
                          value={link.title}
                          onChange={(e) => updateLegalLink(index, 'title', e.target.value)}
                          className="flex-1"
                        />
                        <Input
                          placeholder="URL"
                          value={link.url}
                          onChange={(e) => updateLegalLink(index, 'url', e.target.value)}
                          className="flex-1"
                        />
                        <Button
                          type="button"
                          variant="ghost"
                          size="sm"
                          onClick={() => removeLegalLink(index)}
                        >
                          <X className="h-4 w-4" />
                        </Button>
                      </div>
                    ))}
                    {(!footerData.legal_links || footerData.legal_links.length === 0) && (
                      <p className="text-sm text-gray-500 dark:text-gray-400">No legal links added yet.</p>
                    )}
                  </div>
                </div>

                {/* Save Button */}
                <div className="flex items-center justify-end pt-4 border-t border-gray-200 dark:border-gray-800">
                  <Button
                    onClick={handleFooterSave}
                    disabled={isSavingFooter}
                    type="submit"
                    className="bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white"
                  >
                    {isSavingFooter ? (
                      <>
                        <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                        Saving...
                      </>
                    ) : (
                      <>
                        <Save className="h-4 w-4 mr-2" />
                        Save Footer Settings
                      </>
                    )}
                  </Button>
                </div>
              </CardContent>
            </Card>
          </TabsContent>
        </Tabs>
      </div>
    </SettingsLayout>
  )
}

