import { SidebarGroup, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { Input } from '@/components/ui/input';
import { type NavItem, type NavGroup } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, Search } from 'lucide-react';
import React, { useState, useEffect } from 'react';
import { motion, AnimatePresence } from 'framer-motion';

interface NavMainProps {
    items?: (NavItem | NavGroup)[];
}

type NavEntry = NavItem | NavGroup;

type PageProps = {
    auth: {
        permissions?: string[];
        roles?: string[];
        user?: {
            care_alliance?: { slug?: string; name?: string } | null;
            organization?: {
                is_family_reunion?: boolean;
                community_organization_type_id?: number | null;
                community_organization_type_slug?: string | null;
            } | null;
        };
    };
    url: string;
};

const isGroup = (item: NavEntry): item is NavGroup => 'items' in item;
const isNavItem = (item: NavEntry): item is NavItem => 'href' in item;

/** Unity Impact Alliance users use the same org dashboard/sidebar; treat like organization for nav visibility. */
const ORG_SIDEBAR_ROLE_ALIASES = new Set(['organization', 'care_alliance']);

function userHasOrgSidebarRole(userRoles: string[]): boolean {
    return userRoles.some((ur) => ORG_SIDEBAR_ROLE_ALIASES.has(ur.toLowerCase()));
}

function roleRequirementMatches(
    requiredRole: string,
    userRoles: string[],
    organizationLiteralOnly: boolean,
): boolean {
    const req = requiredRole.toLowerCase();
    if (req === 'organization') {
        if (organizationLiteralOnly) {
            return userRoles.some((ur) => ur.toLowerCase() === 'organization');
        }
        return userHasOrgSidebarRole(userRoles);
    }
    return userRoles.some((ur) => ur.toLowerCase() === req);
}

/** Volunteers submenu: roster vs timesheet vs supporter-interests (avoid prefix false positives). */
function volunteerDashboardHrefActive(href: string, path: string): boolean {
    if (href === '/volunteers/timesheet') {
        return path.startsWith('/volunteers/timesheet');
    }
    if (href === '/volunteers/supporter-interests') {
        return path.startsWith('/volunteers/supporter-interests');
    }
    if (href === '/volunteers/volunteer-interests') {
        return path.startsWith('/volunteers/volunteer-interests');
    }
    if (href === '/volunteers') {
        if (!path.startsWith('/volunteers')) {
            return false;
        }
        if (
            path.startsWith('/volunteers/timesheet') ||
            path.startsWith('/volunteers/supporter-interests') ||
            path.startsWith('/volunteers/volunteer-interests')
        ) {
            return false;
        }
        return path === '/volunteers' || /^\/volunteers\/\d+$/.test(path);
    }
    return false;
}

/** True if this href matches the current URL (exact, /create, /:id/edit, or prefix with /) */
function itemMatchesUrl(href: string, url: string): boolean {
    if (url === href) return true;
    // Categories landing: only index + /categories/* — not sibling apps (items, subcategories, requests)
    if (href === '/admin/kiosk') {
        return url.startsWith('/admin/kiosk/categories');
    }
    if (url.startsWith(href + '/create')) return true;
    const escaped = href.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    if (url.match(new RegExp(`^${escaped}/\\d+/edit$`))) return true;
    if (url.startsWith(href + '/')) return true;
    return false;
}

/** Among sibling nav items, return the href of the one that should be active (longest matching href). */
function getActiveChildHref(entries: NavEntry[], currentUrl: string): string | null {
    const navItems = entries.filter((e): e is NavItem => isNavItem(e) && !!e.href);
    const matching = navItems.filter((n) => itemMatchesUrl(n.href, currentUrl));
    if (matching.length === 0) return null;
    return matching.reduce((best, n) => (n.href.length > (best?.length ?? 0) ? n.href : best), null as string | null);
}

export function NavMain({ items = [] }: NavMainProps) {
    const page = usePage<PageProps>();
    const [expandedGroups, setExpandedGroups] = useState<Set<string>>(new Set());
    const [searchTerm, setSearchTerm] = useState('');
    /** Path only (no query or hash) so /admin/kiosk/items matches even with ?page=2 */
    const pathname = page.url.split('?')[0].split('#')[0];

    const userPermissions = page.props.auth.permissions ?? [];
    const userRoles = page.props.auth.roles ?? [];
    const careAllianceHubPayload = !!(
        page.props.auth?.user?.care_alliance?.slug && String(page.props.auth.user.care_alliance.slug).length > 0
    );

    const hasDirectAccess = (entry: NavEntry): boolean => {
        if ('excludeCareAllianceHub' in entry && entry.excludeCareAllianceHub === true) {
            if (userRoles.some((ur) => ur.toLowerCase() === 'care_alliance') || careAllianceHubPayload) {
                return false;
            }
        }

        if ('familyReunionOnly' in entry && entry.familyReunionOnly === true) {
            if (!page.props.auth?.user?.organization?.is_family_reunion) {
                return false;
            }
        }

        if ('excludeFamilyReunion' in entry && entry.excludeFamilyReunion === true) {
            if (page.props.auth?.user?.organization?.is_family_reunion) {
                return false;
            }
        }

        // Check permissions: if entry has permission requirement, user must have it
        let permissionAllowed = true;
        if (entry.permission) {
            if (Array.isArray(entry.permission)) {
                // If permission is an array, user must have at least one
                permissionAllowed = entry.permission.some((p) => userPermissions.includes(p));
            } else {
                // If permission is a string, user must have it
                permissionAllowed = userPermissions.includes(entry.permission);
            }
        }

        // Check roles: if entry has role requirement, user must have it
        let roleAllowed = true;
        if (entry.role) {
            if (entry.organizationOnlyNav) {
                const isCareAllianceHub =
                    userRoles.some((ur) => ur.toLowerCase() === 'care_alliance') || careAllianceHubPayload;
                if (isCareAllianceHub) {
                    roleAllowed = false;
                } else if (Array.isArray(entry.role)) {
                    roleAllowed = entry.role.some((r) => roleRequirementMatches(r, userRoles, true, careAllianceHubPayload));
                } else {
                    roleAllowed = roleRequirementMatches(entry.role, userRoles, true, careAllianceHubPayload);
                }
            } else if (Array.isArray(entry.role)) {
                roleAllowed = entry.role.some((r) => roleRequirementMatches(r, userRoles, false, careAllianceHubPayload));
            } else {
                roleAllowed = roleRequirementMatches(entry.role, userRoles, false, careAllianceHubPayload);
            }
        }

        // User must satisfy both permission AND role requirements (if any)
        return permissionAllowed && roleAllowed;
    };

    const buildVisibleEntries = (entries: NavEntry[]): NavEntry[] =>
        entries.reduce<NavEntry[]>((acc, entry) => {
            if (isGroup(entry)) {
                // Check if user has access to the group itself (role/permission check)
                if (!hasDirectAccess(entry)) {
                    return acc;
                }

                // Filter children based on their permissions/roles
                const children = buildVisibleEntries(entry.items);

                // If group has only role requirement (no permission), show it even if no children
                // This allows showing the group structure for organization users
                // They'll see the group but only accessible child items
                const hasOnlyRoleRequirement = entry.role && !entry.permission;

                // Show group if:
                // 1. It has children, OR
                // 2. It has only role requirement (no permission requirement)
                if (children.length === 0 && !hasOnlyRoleRequirement) {
                    // If no children and group has permission requirement, hide the group
                    return acc;
                }

                acc.push({ ...entry, items: children });
                return acc;
            }

            if (hasDirectAccess(entry)) {
                acc.push(entry);
            }

            return acc;
        }, []);

    // Auto-expand groups that contain the current active page
    // Only consider groups and items that the user has access to
    useEffect(() => {
        const activeGroups = new Set<string>();
        const accessibleItems = buildVisibleEntries(items);

        const checkItem = (entry: NavEntry, currentPath: string): boolean => {
            if (isGroup(entry)) {
                let hasActive = false;
                entry.items.forEach(subItem => {
                    if (checkItem(subItem, currentPath)) {
                        hasActive = true;
                        activeGroups.add(entry.title);
                    }
                });
                return hasActive;
            } else {
                const basePath = entry.href;
                if (!basePath) return false;
                return itemMatchesUrl(basePath, currentPath);
            }
        };

        accessibleItems.forEach((item) => {
            checkItem(item, pathname);
        });

        setExpandedGroups(activeGroups);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [pathname, items, userPermissions, userRoles]);

    const toggleGroup = (groupTitle: string) => {
        setExpandedGroups(prev => {
            const newSet = new Set(prev);
            if (newSet.has(groupTitle)) {
                newSet.delete(groupTitle);
            } else {
                newSet.add(groupTitle);
            }
            return newSet;
        });
    };

    const visibleItems = buildVisibleEntries(items);
    const normalizedSearch = searchTerm.trim().toLowerCase();

    const filterEntriesBySearch = (entries: NavEntry[], query: string): NavEntry[] => {
        if (!query) return entries;

        return entries.reduce<NavEntry[]>((acc, entry) => {
            if (isGroup(entry)) {
                const filteredChildren = filterEntriesBySearch(entry.items, query);
                const groupMatches = entry.title.toLowerCase().includes(query);
                if (groupMatches || filteredChildren.length > 0) {
                    acc.push({
                        ...entry,
                        items: filteredChildren,
                    });
                }
                return acc;
            }

            const titleMatches = entry.title.toLowerCase().includes(query);
            const hrefMatches = entry.href.toLowerCase().includes(query);
            if (titleMatches || hrefMatches) {
                acc.push(entry);
            }
            return acc;
        }, []);
    };

    const collectGroupTitles = (entries: NavEntry[]): string[] =>
        entries.flatMap((entry) => (isGroup(entry) ? [entry.title, ...collectGroupTitles(entry.items)] : []));

    const filteredVisibleItems = filterEntriesBySearch(visibleItems, normalizedSearch);

    useEffect(() => {
        if (!normalizedSearch) return;
        setExpandedGroups(new Set(collectGroupTitles(filteredVisibleItems)));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [normalizedSearch]);

    return (
        <div className="space-y-2">
            <div className="relative px-1">
                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-4 h-4 w-4 -translate-y-1/2" />
                <Input
                    value={searchTerm}
                    onChange={(e) => setSearchTerm(e.target.value)}
                    placeholder="Search menu..."
                    className="h-9 pl-9 text-sm"
                    aria-label="Search sidebar menu"
                />
            </div>

            {filteredVisibleItems.length === 0 ? (
                <div className="text-muted-foreground rounded-md px-3 py-4 text-sm">
                    No menu found for "{searchTerm.trim()}".
                </div>
            ) : null}

            {filteredVisibleItems.map((item) => {
                if (isGroup(item)) {
                    const isExpanded = expandedGroups.has(item.title);
                    const hasActiveChild = (() => {
                        const activeHref = getActiveChildHref(item.items, pathname);
                        if (activeHref) return true;
                        return item.items.some(subItem => {
                            if (isGroup(subItem)) {
                                return subItem.items.some(subSubItem => {
                                    if (isGroup(subSubItem)) {
                                        return subSubItem.items.some(ssItem => {
                                            if (!isNavItem(ssItem)) return false;
                                            const basePath = ssItem.href;
                                            if (!basePath) return false;
                                            return itemMatchesUrl(basePath, pathname);
                                        });
                                    }
                                    if (!isNavItem(subSubItem)) return false;
                                    return itemMatchesUrl(subSubItem.href, pathname);
                                });
                            }
                            if (!isNavItem(subItem)) return false;
                            if (subItem.href === '/volunteers/timesheet') return pathname.startsWith('/volunteers/timesheet');
                            if (subItem.href === '/volunteers/supporter-interests') {
                                return pathname.startsWith('/volunteers/supporter-interests');
                            }
                            if (subItem.href === '/volunteers/volunteer-interests') {
                                return pathname.startsWith('/volunteers/volunteer-interests');
                            }
                            if (subItem.href === '/volunteers') {
                                return volunteerDashboardHrefActive('/volunteers', pathname);
                            }
                            return itemMatchesUrl(subItem.href, pathname);
                        });
                    })();

                    return (
                        <SidebarGroup key={item.title} className="px-0 py-0">
                            <SidebarMenu>
                                <SidebarMenuItem>
                                    <SidebarMenuButton
                                        onClick={(e) => {
                                            e.preventDefault();
                                            e.stopPropagation();
                                            toggleGroup(item.title);
                                        }}
                                        isActive={hasActiveChild}
                                        tooltip={{ children: item.title }}
                                        title={item.title}
                                        className="flex items-center gap-3 w-full rounded-lg px-3 py-2.5 text-sm font-medium transition-all duration-200 hover:bg-sidebar-accent/80 hover:text-sidebar-accent-foreground data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground data-[active=true]:shadow-sm"
                                    >
                                        {item.icon && <item.icon className="h-4 w-4 shrink-0" />}
                                        <span className="min-w-0 flex-1 whitespace-normal break-words text-left leading-snug" title={item.title}>{item.title}</span>
                                        <motion.div
                                            animate={{ rotate: isExpanded ? 90 : 0 }}
                                            transition={{ duration: 0.3, ease: [0.4, 0, 0.2, 1] }}
                                    >
                                            <ChevronRight className="h-4 w-4 shrink-0 opacity-60" />
                                        </motion.div>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            </SidebarMenu>

                            <AnimatePresence initial={false}>
                            {isExpanded && (
                                    <motion.div
                                        initial={{ height: 0, opacity: 0 }}
                                        animate={{ height: 'auto', opacity: 1 }}
                                        exit={{ height: 0, opacity: 0 }}
                                        transition={{
                                            duration: 0.3,
                                            ease: [0.4, 0, 0.2, 1]
                                        }}
                                        className="ml-6 space-y-0.5 overflow-hidden border-l border-sidebar-border/50 pl-3"
                                    >
                                        {item.items.map((subItem, index) => {
                                            // Check if subItem is a nested group
                                            if (isGroup(subItem)) {
                                                const isSubExpanded = expandedGroups.has(subItem.title);
                                                const hasActiveSubChild = subItem.items.some(subSubItem => {
                                                    if (isGroup(subSubItem)) {
                                                        return subSubItem.items.some(ssItem => {
                                                            if (!isNavItem(ssItem)) return false;
                                                            const basePath = ssItem.href;
                                                            if (!basePath) return false;
                                                            return ssItem.href === page.url ||
                                                                page.url.startsWith(`${basePath}/create`) ||
                                                                !!page.url.match(new RegExp(`^${basePath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/\\d+/edit$`)) ||
                                                                page.url.startsWith(basePath);
                                                        });
                                                    }
                                                    if (!isNavItem(subSubItem)) return false;
                                                    const basePath = subSubItem.href;
                                                    if (!basePath) return false;
                                                    if (basePath.startsWith('/volunteers')) {
                                                        return volunteerDashboardHrefActive(basePath, pathname);
                                                    }
                                                    return subSubItem.href === pathname ||
                                                        pathname.startsWith(`${basePath}/create`) ||
                                                        !!pathname.match(new RegExp(`^${basePath.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/\\d+/edit$`)) ||
                                                        pathname.startsWith(basePath);
                                                });

                                                return (
                                                    <motion.div
                                                        key={subItem.title}
                                                        initial={{ opacity: 0, x: -10 }}
                                                        animate={{ opacity: 1, x: 0 }}
                                                        transition={{
                                                            duration: 0.2,
                                                            delay: index * 0.05,
                                                            ease: [0.4, 0, 0.2, 1]
                                                        }}
                                                    >
                                                        <SidebarMenu>
                                                            <SidebarMenuItem>
                                                                <SidebarMenuButton
                                                                    onClick={(e) => {
                                                                        e.preventDefault();
                                                                        e.stopPropagation();
                                                                        toggleGroup(subItem.title);
                                                                    }}
                                                                    isActive={hasActiveSubChild}
                                                                    tooltip={{ children: subItem.title }}
                                                                    title={subItem.title}
                                                                    className="flex items-center gap-2.5 w-full rounded-md px-2.5 py-2 text-sm transition-all duration-200 hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground data-[active=true]:font-medium"
                                                                >
                                                                    {subItem.icon && <subItem.icon className="h-4 w-4 shrink-0" />}
                                                                    <span className="min-w-0 flex-1 whitespace-normal break-words text-left leading-snug" title={subItem.title}>{subItem.title}</span>
                                                                    <motion.div
                                                                        animate={{ rotate: isSubExpanded ? 90 : 0 }}
                                                                        transition={{ duration: 0.3, ease: [0.4, 0, 0.2, 1] }}
                                                                    >
                                                                        <ChevronRight className="h-3 w-3 shrink-0 opacity-60" />
                                                                    </motion.div>
                                                                </SidebarMenuButton>
                                                            </SidebarMenuItem>
                                                        </SidebarMenu>

                                                        <AnimatePresence initial={false}>
                                                            {isSubExpanded && (
                                                                <motion.div
                                                                    initial={{ height: 0, opacity: 0 }}
                                                                    animate={{ height: 'auto', opacity: 1 }}
                                                                    exit={{ height: 0, opacity: 0 }}
                                                                    transition={{
                                                                        duration: 0.3,
                                                                        ease: [0.4, 0, 0.2, 1]
                                                                    }}
                                                                    className="ml-4 space-y-0.5 overflow-hidden border-l border-sidebar-border/30 pl-3 mt-1"
                                                                >
                                                                    {subItem.items.map((subSubItem, subIndex) => {
                                                                        if (isGroup(subSubItem)) {
                                                                            // Handle 3rd level nesting if needed
                                                                            return (
                                                                                <motion.div
                                                                                    key={subSubItem.title}
                                                                                    initial={{ opacity: 0, x: -10 }}
                                                                                    animate={{ opacity: 1, x: 0 }}
                                                                                    transition={{
                                                                                        duration: 0.2,
                                                                                        delay: subIndex * 0.05,
                                                                                        ease: [0.4, 0, 0.2, 1]
                                                                                    }}
                                                                                >
                                                                                    <SidebarMenu>
                                                                                        <SidebarMenuItem>
                                                                                            <SidebarMenuButton
                                                                                                onClick={(e) => {
                                                                                                    e.preventDefault();
                                                                                                    e.stopPropagation();
                                                                                                    toggleGroup(subSubItem.title);
                                                                                                }}
                                                                                                isActive={subSubItem.items.some(ssItem => isNavItem(ssItem) && ssItem.href && page.url.startsWith(ssItem.href))}
                                                                                                tooltip={{ children: subSubItem.title }}
                                                                                                title={subSubItem.title}
                                                                                                className="flex items-center gap-2 w-full rounded-md px-2 py-1.5 text-xs transition-all duration-200 hover:bg-sidebar-accent/50"
                                                                                            >
                                                                                                {subSubItem.icon && <subSubItem.icon className="h-3 w-3 shrink-0" />}
                                                                                                <span className="min-w-0 flex-1 whitespace-normal break-words text-left leading-snug" title={subSubItem.title}>{subSubItem.title}</span>
                                                                                                <motion.div
                                                                                                    animate={{ rotate: expandedGroups.has(subSubItem.title) ? 90 : 0 }}
                                                                                                    transition={{ duration: 0.3 }}
                                                                                                >
                                                                                                    <ChevronRight className="h-3 w-3 shrink-0 opacity-60" />
                                                                                                </motion.div>
                                                                                            </SidebarMenuButton>
                                                                                        </SidebarMenuItem>
                                                                                    </SidebarMenu>
                                                                                    <AnimatePresence>
                                                                                        {expandedGroups.has(subSubItem.title) && (
                                                                                            <motion.div
                                                                                                initial={{ height: 0, opacity: 0 }}
                                                                                                animate={{ height: 'auto', opacity: 1 }}
                                                                                                exit={{ height: 0, opacity: 0 }}
                                                                                                className="ml-3 space-y-0.5 border-l border-sidebar-border/20 pl-2 mt-1"
                                                                                            >
                                                                                                {subSubItem.items.map((ssItem) => {
                                                                                                    if (!isNavItem(ssItem)) return null;
                                                                                                    return (
                                                                                                        <SidebarMenu key={ssItem.title}>
                                                                                                            <SidebarMenuItem>
                                                                                                                <SidebarMenuButton
                                                                                                                    asChild
                                                                                                                    isActive={ssItem.href === page.url || page.url.startsWith(ssItem.href)}
                                                                                                                    title={ssItem.title}
                                                                                                                    className="flex items-center gap-2 w-full rounded px-2 py-1.5 text-xs"
                                                                                                                >
                                                                                                                    <Link href={ssItem.href} prefetch className="flex items-center gap-2 w-full" title={ssItem.title}>
                                                                                                                        {ssItem.icon && <ssItem.icon className="h-3 w-3 shrink-0" />}
                                                                                                                        <span className="min-w-0 flex-1 whitespace-normal break-words text-left leading-snug" title={ssItem.title}>{ssItem.title}</span>
                                                                                                                    </Link>
                                                                                                                </SidebarMenuButton>
                                                                                                            </SidebarMenuItem>
                                                                                                        </SidebarMenu>
                                                                                                    );
                                                                                                })}
                                                                                            </motion.div>
                                                                                        )}
                                                                                    </AnimatePresence>
                                                                                </motion.div>
                                                                            );
                                                                        }
                                                                        // Regular item at 3rd level
                                                                        if (!isNavItem(subSubItem)) return null;
                                                                        return (
                                                                            <motion.div
                                                                                key={subSubItem.title}
                                                                                initial={{ opacity: 0, x: -10 }}
                                                                                animate={{ opacity: 1, x: 0 }}
                                                                                transition={{
                                                                                    duration: 0.2,
                                                                                    delay: subIndex * 0.05,
                                                                                    ease: [0.4, 0, 0.2, 1]
                                                                                }}
                                                                            >
                                                                                <SidebarMenu>
                                                                                    <SidebarMenuItem>
                                                                                        <SidebarMenuButton
                                                                                            asChild
                                                                                            isActive={
                                                                                                subSubItem.href.startsWith('/volunteers')
                                                                                                    ? volunteerDashboardHrefActive(subSubItem.href, pathname)
                                                                                                    : subSubItem.href === pathname ||
                                                                                                      pathname.startsWith(subSubItem.href + '/create') ||
                                                                                                      !!pathname.match(new RegExp(`^${subSubItem.href.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}/\\d+/edit$`)) ||
                                                                                                      pathname.startsWith(subSubItem.href)
                                                                                            }
                                                                                            tooltip={{ children: subSubItem.title }}
                                                                                            title={subSubItem.title}
                                                                                            className="flex items-center gap-2 w-full rounded-md px-2 py-1.5 text-xs transition-all duration-200 hover:bg-sidebar-accent/50 hover:text-sidebar-accent-foreground data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground"
                                                                                        >
                                                                                            <Link href={subSubItem.href} prefetch className="flex items-center gap-2 w-full" title={subSubItem.title}>
                                                                                                {subSubItem.icon && <subSubItem.icon className="h-3 w-3 shrink-0" />}
                                                                                                <span className="min-w-0 flex-1 whitespace-normal break-words text-left leading-snug" title={subSubItem.title}>{subSubItem.title}</span>
                                                                                            </Link>
                                                                                        </SidebarMenuButton>
                                                                                    </SidebarMenuItem>
                                                                                </SidebarMenu>
                                                                            </motion.div>
                                                                        );
                                                                    })}
                                                                </motion.div>
                                                            )}
                                                        </AnimatePresence>
                                                    </motion.div>
                                                );
                                            }
                                            // Regular item at 2nd level — use pathname so only the matching item is active (e.g. /admin/kiosk/items, not /admin/kiosk)
                                            if (!isNavItem(subItem)) return null;
                                            const activeChildHref = getActiveChildHref(item.items, pathname);
                                            const isActiveSecondLevel =
                                                subItem.href === activeChildHref ||
                                                (subItem.href === '/volunteers/timesheet' && pathname.startsWith('/volunteers/timesheet')) ||
                                                (subItem.href === '/volunteers/supporter-interests' &&
                                                    pathname.startsWith('/volunteers/supporter-interests')) ||
                                                (subItem.href === '/volunteers/volunteer-interests' &&
                                                    pathname.startsWith('/volunteers/volunteer-interests')) ||
                                                (subItem.href === '/volunteers' && volunteerDashboardHrefActive('/volunteers', pathname));
                                            return (
                                                <motion.div
                                                    key={subItem.title}
                                                    initial={{ opacity: 0, x: -10 }}
                                                    animate={{ opacity: 1, x: 0 }}
                                                    transition={{
                                                        duration: 0.2,
                                                        delay: index * 0.05,
                                                        ease: [0.4, 0, 0.2, 1]
                                                    }}
                                                    data-active={isActiveSecondLevel}
                                                    className={isActiveSecondLevel ? 'rounded-md bg-sidebar-accent font-medium text-sidebar-accent-foreground [&_a]:text-sidebar-accent-foreground' : undefined}
                                                >
                                                    <SidebarMenu>
                                                <SidebarMenuItem>
                                                    <SidebarMenuButton
                                                        asChild
                                                        isActive={false}
                                                        tooltip={{ children: subItem.title }}
                                                        title={subItem.title}
                                                                className="flex items-center gap-2.5 w-full rounded-md px-2.5 py-2 text-sm transition-all duration-200 hover:bg-sidebar-accent/60 hover:text-sidebar-accent-foreground data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground data-[active=true]:font-medium data-[active=true]:shadow-sm"
                                                    >
                                                                <Link href={subItem.href} prefetch className="flex items-center gap-2.5 w-full" title={subItem.title}>
                                                                    {subItem.icon && <subItem.icon className="h-4 w-4 shrink-0" />}
                                                                    <span className="min-w-0 flex-1 whitespace-normal break-words text-left leading-snug" title={subItem.title}>{subItem.title}</span>
                                                        </Link>
                                                    </SidebarMenuButton>
                                                </SidebarMenuItem>
                                            </SidebarMenu>
                                                </motion.div>
                                            );
                                        })}
                                    </motion.div>
                            )}
                            </AnimatePresence>
                        </SidebarGroup>
                    );
                }

                return (
                    <SidebarGroup key={item.title} className="px-0 py-0">
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    asChild
                                    isActive={item.href === page.url}
                                    tooltip={{ children: item.title }}
                                    title={item.title}
                                    className="flex items-center gap-3 w-full rounded-lg px-3 py-2.5 text-sm font-medium transition-all duration-200 hover:bg-sidebar-accent/80 hover:text-sidebar-accent-foreground data-[active=true]:bg-sidebar-accent data-[active=true]:text-sidebar-accent-foreground data-[active=true]:shadow-sm"
                                >
                                    <Link href={item.href} prefetch className="flex items-center gap-3 w-full" title={item.title}>
                                        {item.icon && <item.icon className="h-4 w-4 shrink-0" />}
                                        <span className="min-w-0 flex-1 whitespace-normal break-words text-left leading-snug" title={item.title}>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroup>
                );
            })}
        </div>
    );
}
