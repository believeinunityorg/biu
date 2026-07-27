"use client";

import React, { useState } from "react";
import { Link, usePage } from "@inertiajs/react";
import { Button } from "@/components/ui/button";
import {
  LayoutDashboard,
  Coins,
  FileText,
  History,
  Handshake,
  Star,
  Home,
  Store,
  List,
  Menu,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { Sheet, SheetContent, SheetTrigger } from "@/components/ui/sheet";

declare global {
  function route(name: string, params?: Record<string, unknown>): string;
}

export type BarterTab = "home" | "browse" | "listings" | "trades";

interface BarterLayoutProps {
  children: React.ReactNode;
  currentTab?: BarterTab;
  title?: string;
  recentListings?: { id: number; title: string; status: string }[];
}

export function BarterLayout({
  children,
  currentTab = "home",
  title = "My Dashboard",
  recentListings = [],
}: BarterLayoutProps) {
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const page = usePage();
  const auth = (page.props as { auth?: { user?: any; organization?: { name: string } } }).auth;
  const balance =
    auth?.user?.purchased_believe_points ??
    Math.max(
      0,
      Number(auth?.user?.believe_points ?? 0) - Number(auth?.user?.gifted_believe_points ?? 0)
    );
  const orgName = auth?.organization?.name ?? "Nonprofit";

  const tabs = [
    { id: "home" as const, label: "Home", shortLabel: "Home", href: route("barter.index"), icon: Home },
    { id: "browse" as const, label: "Browse Offers", shortLabel: "Browse", href: route("barter.marketplace"), icon: Store },
    { id: "listings" as const, label: "My Listings", shortLabel: "Listings", href: route("barter.my-listings"), icon: List },
    { id: "trades" as const, label: "My Trades", shortLabel: "Trades", href: route("barter.active-trades"), icon: Handshake },
  ];

  const sidebarLinks = [
    { label: "My Dashboard", href: route("barter.index"), icon: LayoutDashboard },
    { label: "Active Trades", href: route("barter.active-trades"), icon: Handshake },
    { label: "Trade History", href: route("barter.trade-history"), icon: History },
    { label: "My Points Wallet", href: route("barter.points-wallet"), icon: Coins },
    { label: "Reputation Score", href: route("barter.reputation"), icon: Star },
  ];

  const sidebarContent = (
    <>
      <Button asChild className="w-full bg-primary hover:bg-primary/90 text-primary-foreground h-11">
        <Link href={route("barter.my-listings")} className="flex items-center justify-center gap-2" onClick={() => setSidebarOpen(false)}>
          <FileText className="h-4 w-4 shrink-0" />
          Create New Listing
        </Link>
      </Button>
      <nav className="rounded-lg border border-border bg-card p-2">
        {sidebarLinks.map((item) => {
          const Icon = item.icon;
          const isActive =
            (item.label === "My Dashboard" && currentTab === "home") ||
            (item.label === "Active Trades" && currentTab === "trades");
          return (
            <Link
              key={item.label}
              href={item.href}
              onClick={() => setSidebarOpen(false)}
              className={cn(
                "flex items-center gap-2 rounded-lg px-3 py-2.5 text-sm font-medium transition",
                isActive
                  ? "bg-primary/10 text-primary"
                  : "text-muted-foreground hover:bg-accent hover:text-accent-foreground"
              )}
            >
              <Icon className="h-4 w-4 shrink-0" />
              {item.label}
            </Link>
          );
        })}
      </nav>
      {recentListings.length > 0 && (
        <div className="rounded-lg border border-border bg-card p-4">
          <h3 className="mb-2 text-sm font-semibold text-foreground">Recent Listings</h3>
          <ul className="space-y-1.5 text-sm text-muted-foreground">
            {recentListings.map((l) => (
              <li key={l.id}>
                <Link
                  href={route("barter.my-listings")}
                  onClick={() => setSidebarOpen(false)}
                  className="hover:text-primary"
                >
                  {l.title}
                </Link>
              </li>
            ))}
          </ul>
        </div>
      )}
    </>
  );

  return (
    <div className="min-h-screen bg-background flex flex-col">
      {/* Header — compact on mobile */}
      <header className="sticky top-0 z-20 border-b border-border bg-card shadow-sm safe-area-inset-top">
        <div className="mx-auto flex max-w-7xl items-center justify-between gap-2 px-4 py-3 sm:gap-3 md:px-6">
          <div className="flex min-w-0 items-center gap-2 sm:gap-3">
            <Sheet open={sidebarOpen} onOpenChange={setSidebarOpen}>
              <SheetTrigger asChild className="md:hidden touch-manipulation">
                <Button variant="ghost" size="icon" className="h-10 w-10 shrink-0" aria-label="Open menu">
                  <Menu className="h-5 w-5" />
                </Button>
              </SheetTrigger>
              <SheetContent side="left" className="w-[min(320px,85vw)] overflow-y-auto p-4 md:hidden">
                <div className="flex flex-col gap-6 pt-2">{sidebarContent}</div>
              </SheetContent>
            </Sheet>
            <Link
              href={route("barter.index")}
              className="flex min-h-[44px] items-center gap-2 truncate font-semibold text-foreground"
            >
              <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <Handshake className="h-4 w-4" />
              </div>
              <span className="hidden truncate md:inline">Nonprofit Barter Network</span>
              <span className="truncate md:hidden">Barter</span>
            </Link>
          </div>
          <div className="flex shrink-0 items-center gap-2 sm:gap-3">
            <div className="text-right min-w-0">
              <p className="text-[10px] sm:text-xs text-muted-foreground leading-tight">Points</p>
              <p className="text-sm font-semibold tabular-nums text-foreground truncate sm:text-base">
                {Number(balance).toLocaleString()}
              </p>
            </div>
            <span className="rounded-lg border border-border bg-muted/50 px-2 py-1.5 text-xs font-medium text-foreground truncate max-w-[120px] sm:max-w-[180px]">
              {orgName}
            </span>
          </div>
        </div>

        {/* Desktop only: horizontal tabs (no scroll) */}
        <nav className="hidden border-t border-border bg-muted/30 md:block">
          <div className="mx-auto flex max-w-7xl flex-wrap gap-0 px-4 md:px-6">
            {tabs.map((tab) => {
              const Icon = tab.icon;
              const isActive = currentTab === tab.id;
              return (
                <Link
                  key={tab.id}
                  href={tab.href}
                  className={cn(
                    "flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-medium transition",
                    isActive
                      ? "border-primary bg-primary/10 text-primary"
                      : "border-transparent text-muted-foreground hover:bg-muted/50 hover:text-foreground"
                  )}
                >
                  <Icon className="h-4 w-4 shrink-0" />
                  {tab.label}
                </Link>
              );
            })}
          </div>
        </nav>
      </header>

      {/* Main + sidebar */}
      <div className="mx-auto flex w-full max-w-7xl flex-1 gap-4 px-4 py-4 pb-24 sm:py-5 md:pb-5 md:px-6 lg:gap-8 lg:pb-6">
        <aside className="hidden w-52 shrink-0 space-y-6 lg:block">
          {sidebarContent}
        </aside>

        <main className="min-w-0 flex-1">
          {title && (
            <h1 className="mb-4 text-lg font-semibold text-foreground md:mb-5 md:text-xl">{title}</h1>
          )}
          {children}
        </main>
      </div>

      {/* Mobile: fixed bottom nav (replaces scroll tabs) */}
      <nav
        className="fixed bottom-0 left-0 right-0 z-20 border-t border-border bg-card/95 backdrop-blur supports-[padding:env(safe-area-inset-bottom)]:pb-[env(safe-area-inset-bottom)] md:hidden"
        style={{ paddingBottom: "max(env(safe-area-inset-bottom), 8px)" }}
      >
        <div className="mx-auto grid max-w-lg grid-cols-4 gap-0">
          {tabs.map((tab) => {
            const Icon = tab.icon;
            const isActive = currentTab === tab.id;
            return (
              <Link
                key={tab.id}
                href={tab.href}
                className={cn(
                  "flex min-h-[56px] flex-col items-center justify-center gap-0.5 px-1 py-2 transition touch-manipulation active:bg-muted/50",
                  isActive ? "text-primary" : "text-muted-foreground"
                )}
              >
                <Icon className={cn("h-6 w-6 shrink-0", isActive && "stroke-[2.5px]")} />
                <span className="text-[10px] font-medium leading-tight max-w-full truncate px-0.5">
                  {tab.shortLabel}
                </span>
              </Link>
            );
          })}
        </div>
      </nav>
    </div>
  );
}
