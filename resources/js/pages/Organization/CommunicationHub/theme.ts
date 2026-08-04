/**
 * Communication Hub theme — purple→blue brand (matches sidebar logo),
 * with full light/dark support via semantic tokens + dark: variants.
 */
export const ch = {
  /** Primary CTAs */
  btn: 'text-white bg-gradient-to-r from-purple-600 to-blue-600 shadow-sm hover:from-purple-500 hover:to-blue-500',
  btnSm:
    'text-white bg-gradient-to-r from-purple-600 to-blue-600 text-sm shadow-sm hover:from-purple-500 hover:to-blue-500',
  btnOutline:
    'border border-purple-500/40 text-purple-600 hover:bg-purple-500/10 dark:text-purple-400 dark:border-purple-400/35 dark:hover:bg-purple-500/15',

  /** Tabs & filter chips */
  tabActive: 'bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm',
  tabInactive:
    'text-muted-foreground hover:bg-muted hover:text-foreground dark:hover:bg-muted/80',

  /** Segmented control (Announcements / Discussions) */
  segment:
    'inline-flex w-full rounded-xl border border-border bg-muted/40 p-1 shadow-sm sm:w-auto dark:bg-muted/20',
  segmentBtn:
    'flex-1 rounded-lg px-3.5 py-2 text-sm font-medium transition sm:flex-none sm:px-4',
  segmentActive: 'bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-sm',
  segmentIdle: 'text-muted-foreground hover:text-foreground hover:bg-background/60',

  /** Text accents */
  text: 'text-purple-600 dark:text-purple-400',
  textMuted: 'text-muted-foreground',
  textStrong: 'text-foreground',
  titleGradient: 'bg-gradient-to-r from-purple-600 to-blue-600 bg-clip-text text-transparent',
  link: 'text-purple-600 hover:text-blue-600 font-medium dark:text-purple-400 dark:hover:text-blue-400',

  /** Surfaces */
  card: 'rounded-2xl border border-border bg-card text-card-foreground shadow-sm',
  cardHover: 'transition-shadow hover:shadow-md dark:hover:shadow-none dark:hover:border-purple-500/25',
  surface: 'rounded-xl border border-purple-500/25 bg-purple-500/10 dark:bg-purple-500/15',
  surfaceSoft: 'rounded-xl border border-purple-500/15 bg-purple-500/5 dark:bg-purple-500/10',
  empty:
    'rounded-2xl border border-dashed border-purple-500/30 bg-purple-500/5 px-4 py-12 text-center dark:border-purple-400/25 dark:bg-purple-500/10',
  input:
    'rounded-xl border border-border bg-background text-foreground placeholder:text-muted-foreground dark:bg-muted/30',
  chip: 'rounded-full border border-border bg-muted/50 text-muted-foreground',

  /** Badges */
  badge:
    'rounded-full border-0 bg-purple-500/15 px-2.5 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-500/20 dark:text-purple-300',
  badgePinned:
    'rounded-full border-0 bg-amber-500/15 px-2.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-500/20 dark:text-amber-300',
  badgeLocked:
    'rounded-full border-0 bg-muted px-2.5 py-0.5 text-xs font-medium text-foreground/80',

  /** Icons */
  iconWrap:
    'flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-purple-500/15 text-purple-600 dark:bg-purple-500/20 dark:text-purple-400',
  iconWrapLg:
    'flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-purple-500/15 text-purple-600 dark:bg-purple-500/20 dark:text-purple-400',

  /** Focus & borders */
  border: 'border border-border',
  focus:
    'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-purple-500/40 focus-visible:border-purple-500/50',

  /** Page chrome */
  page: 'mx-auto max-w-7xl space-y-5 px-3 py-4 sm:space-y-6 sm:px-6 sm:py-6',
  pageNarrow: 'mx-auto max-w-3xl space-y-5 px-3 py-4 sm:space-y-6 sm:px-6 sm:py-6',
  pageMedium: 'mx-auto max-w-5xl space-y-5 px-3 py-4 sm:space-y-6 sm:px-6 sm:py-6',
  heading: 'text-2xl font-bold tracking-tight text-foreground sm:text-3xl',
  subheading: 'mt-1 text-sm text-muted-foreground',
  sectionTitle: 'text-base font-semibold text-foreground sm:text-lg',
  divider: 'border-b border-border',
  unread: 'bg-purple-500/5 dark:bg-purple-500/10',
} as const
