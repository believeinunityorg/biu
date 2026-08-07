import React, { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Facebook,
    Plus,
    RefreshCw,
    Trash2,
    CheckCircle,
    XCircle,
    ExternalLink,
    Shield,
    Users,
    Globe,
    AlertTriangle
} from 'lucide-react';
import { toast } from 'sonner';
import axios from 'axios';
import FacebookPermissionModal from '@/components/facebook/FacebookPermissionModal';

interface FacebookAccount {
    id: number;
    facebook_page_id: string;
    facebook_page_name: string;
    page_category: string;
    followers_count: number;
    is_connected: boolean;
    last_synced_at?: string;
    picture_url?: string;
    is_token_expired: boolean;
}

interface Props {
    accounts?: FacebookAccount[];
    hasConnectedAccounts: boolean;
    organization?: {
        id: number;
        name: string;
    };
    requestedScopes?: string[];
    privacyPolicyUrl?: string;
}

export default function Connect({
    accounts = [],
    hasConnectedAccounts = false,
    organization = { id: 0, name: 'Your Organization' },
    requestedScopes = ['pages_show_list', 'pages_manage_posts'],
    privacyPolicyUrl = '/privacy-policy',
}: Props) {
    const [disconnecting, setDisconnecting] = useState<Record<number, boolean>>({});
    const [refreshing, setRefreshing] = useState<Record<number, boolean>>({});
    const [showPermissionModal, setShowPermissionModal] = useState(false);

    const connectToFacebook = () => {
        // Show permission modal first
        setShowPermissionModal(true);
    };

    const handleAcceptPermissions = () => {
        setShowPermissionModal(false);
        window.location.href = '/facebook/oauth/redirect';
    };

    // সরাসরি Facebook OAuth এ redirect
    // const connectToFacebook = () => {
    //     window.location.href = '/facebook/oauth/redirect';
    // };

    const handleDisconnect = async (accountId: number, pageName: string) => {
        if (!confirm(`Are you sure you want to disconnect "${pageName}"?`)) {
            return;
        }

        setDisconnecting(prev => ({ ...prev, [accountId]: true }));

        try {
            await axios.post(`/facebook/${accountId}/disconnect`);
            toast.success('Facebook page disconnected successfully');
            window.location.reload();
        } catch (error: any) {
            toast.error('Failed to disconnect Facebook page');
        } finally {
            setDisconnecting(prev => ({ ...prev, [accountId]: false }));
        }
    };

    const handleRefresh = async (accountId: number) => {
        setRefreshing(prev => ({ ...prev, [accountId]: true }));

        try {
            await axios.post(`/facebook/${accountId}/refresh`);
            toast.success('Facebook page refreshed successfully');
            window.location.reload();
        } catch (error: any) {
            toast.error('Failed to refresh Facebook page');
        } finally {
            setRefreshing(prev => ({ ...prev, [accountId]: false }));
        }
    };

    const handleSetDefault = async (accountId: number) => {
        try {
            await axios.post(`/facebook/${accountId}/set-default`);
            toast.success('Default Facebook page set successfully');
            window.location.reload();
        } catch (error: any) {
            toast.error('Failed to set default page');
        }
    };

    return (
        <AppLayout>
            <Head title="Connect Facebook" />

            <div className="container mx-auto p-4 md:p-6">
                {/* Header */}
                <div className="mb-8">
                    <h1 className="text-3xl font-bold tracking-tight">Facebook Integration</h1>
                    <p className="text-muted-foreground mt-2">
                        Connect your Facebook pages to post content directly from {organization.name}
                    </p>
                </div>

                {/* Connection Status & Instructions */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    {/* Instructions */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Facebook className="h-5 w-5 text-blue-600" />
                                How to Connect
                            </CardTitle>
                            <CardDescription>
                                Connect your Facebook Pages in four steps (required for Meta App Review)
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="space-y-3">
                                <div className="flex items-start gap-3">
                                    <div className="flex-shrink-0 w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                                        <span className="font-bold text-primary">1</span>
                                    </div>
                                    <div>
                                        <h4 className="font-medium">Review permissions in our app</h4>
                                        <p className="text-sm text-muted-foreground">
                                            We explain {requestedScopes.join(', ')} before sending you to Facebook Login
                                        </p>
                                    </div>
                                </div>

                                <div className="flex items-start gap-3">
                                    <div className="flex-shrink-0 w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                                        <span className="font-bold text-primary">2</span>
                                    </div>
                                    <div>
                                        <h4 className="font-medium">Authorize on Facebook</h4>
                                        <p className="text-sm text-muted-foreground">
                                            Log in with an account that is an admin of the Page you want to connect
                                        </p>
                                    </div>
                                </div>

                                <div className="flex items-start gap-3">
                                    <div className="flex-shrink-0 w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                                        <span className="font-bold text-primary">3</span>
                                    </div>
                                    <div>
                                        <h4 className="font-medium">Select Pages (pages_show_list)</h4>
                                        <p className="text-sm text-muted-foreground">
                                            Pick which Pages to connect — we only save the ones you select
                                        </p>
                                    </div>
                                </div>

                                <div className="flex items-start gap-3">
                                    <div className="flex-shrink-0 w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                                        <span className="font-bold text-primary">4</span>
                                    </div>
                                    <div>
                                        <h4 className="font-medium">Publish posts (pages_manage_posts)</h4>
                                        <p className="text-sm text-muted-foreground">
                                            Create, schedule, and publish posts from BIU when you choose
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <Alert className="bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800">
                                <Shield className="h-4 w-4 text-blue-600" />
                                <AlertTitle>Permissions we request</AlertTitle>
                                <AlertDescription className="text-sm space-y-2">
                                    <p>
                                        <strong>pages_show_list</strong> — list Pages you manage so you can choose which to connect.
                                    </p>
                                    <p>
                                        <strong>pages_manage_posts</strong> — publish or schedule posts only when you click Publish in our app. Posts created in BIU are listed in our app; we do not read your Page&apos;s full Facebook feed.
                                    </p>
                                    <p>
                                        <a href={privacyPolicyUrl} target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline font-medium">
                                            Privacy Policy
                                        </a>
                                    </p>
                                </AlertDescription>
                            </Alert>
                        </CardContent>
                    </Card>

                    {/* Connect Button */}
                    <Card>
                        <CardContent className="pt-6">
                            <div className="text-center">
                                <div className="mx-auto w-20 h-20 mb-4 rounded-full bg-blue-100 dark:bg-blue-900/20 flex items-center justify-center">
                                    <Facebook className="h-10 w-10 text-blue-600" />
                                </div>

                                <h3 className="text-xl font-bold mb-2">Connect Facebook</h3>
                                <p className="text-muted-foreground mb-6">
                                    Connect your Facebook pages to start posting
                                </p>

                                <Button
                                    onClick={connectToFacebook}
                                    size="lg"
                                    className="w-full gap-2 bg-blue-600 hover:bg-blue-700"
                                >
                                    <Facebook className="h-5 w-5" />
                                    Connect Facebook Pages
                                </Button>

                                <p className="text-xs text-muted-foreground mt-4">
                                    You can connect multiple pages and manage them individually
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                     {/* Permission Modal */}
      <FacebookPermissionModal
        isOpen={showPermissionModal}
        onClose={() => setShowPermissionModal(false)}
        onAccept={handleAcceptPermissions}
      />
                </div>

                {/* Connected Accounts */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <div>
                                <CardTitle>Connected Pages</CardTitle>
                                <CardDescription>
                                    Manage your connected Facebook pages
                                </CardDescription>
                            </div>
                            <Badge variant="outline" className="gap-2">
                                <Users className="h-3 w-3" />
                                {accounts.length} {accounts.length === 1 ? 'Page' : 'Pages'}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {accounts.length === 0 ? (
                            <div className="text-center py-12">
                                <div className="mx-auto w-24 h-24 mb-4 text-muted-foreground">
                                    <div className="w-full h-full rounded-full bg-muted flex items-center justify-center">
                                        <Facebook className="h-12 w-12" />
                                    </div>
                                </div>
                                <h3 className="text-lg font-semibold mb-2">No pages connected</h3>
                                <p className="text-muted-foreground mb-6 max-w-md mx-auto">
                                    Connect your Facebook pages to start posting content directly from your dashboard
                                </p>
                                <Button
                                    onClick={connectToFacebook}
                                    className="gap-2 bg-blue-600 hover:bg-blue-700"
                                >
                                    <Facebook className="h-4 w-4" />
                                    Connect Your First Page
                                </Button>
                            </div>
                        ) : (
                            <div className="space-y-4">
                                {accounts.map((account) => (
                                    <div
                                        key={account.id}
                                        className="p-4 border rounded-lg hover:bg-muted/50 transition-colors"
                                    >
                                        <div className="flex items-start justify-between">
                                            <div className="flex items-start gap-4">
                                                {account.picture_url ? (
                                                    <img
                                                        src={account.picture_url}
                                                        alt={account.facebook_page_name}
                                                        className="w-12 h-12 rounded-lg object-cover"
                                                    />
                                                ) : (
                                                    <div className="w-12 h-12 rounded-lg bg-blue-100 dark:bg-blue-900/20 flex items-center justify-center">
                                                        <Facebook className="h-6 w-6 text-blue-600" />
                                                    </div>
                                                )}

                                                <div>
                                                    <div className="flex items-center gap-2 mb-1">
                                                        <h4 className="font-bold text-lg">
                                                            {account.facebook_page_name}
                                                        </h4>
                                                        {account.is_token_expired && (
                                                            <Badge variant="destructive" className="gap-1">
                                                                <AlertTriangle className="h-3 w-3" />
                                                                Expired
                                                            </Badge>
                                                        )}
                                                        {account.is_connected ? (
                                                            <Badge variant="default" className="gap-1">
                                                                <CheckCircle className="h-3 w-3" />
                                                                Connected
                                                            </Badge>
                                                        ) : (
                                                            <Badge variant="outline" className="gap-1">
                                                                <XCircle className="h-3 w-3" />
                                                                Disconnected
                                                            </Badge>
                                                        )}
                                                    </div>

                                                    <div className="flex flex-wrap gap-2 mb-2">
                                                        {account.page_category && (
                                                            <Badge variant="secondary">
                                                                {account.page_category}
                                                            </Badge>
                                                        )}
                                                        <Badge variant="outline" className="gap-1">
                                                            <Users className="h-3 w-3" />
                                                            {account.followers_count.toLocaleString()} followers
                                                        </Badge>
                                                    </div>

                                                    <p className="text-sm text-muted-foreground">
                                                        Page ID: {account.facebook_page_id}
                                                        {account.last_synced_at && (
                                                            <> • Last synced: {new Date(account.last_synced_at).toLocaleDateString()}</>
                                                        )}
                                                    </p>
                                                </div>
                                            </div>

                                            <div className="flex flex-col gap-2 min-w-[200px]">
                                                <div className="flex gap-2">
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => handleRefresh(account.id)}
                                                        disabled={refreshing[account.id]}
                                                        className="gap-2 flex-1"
                                                    >
                                                        {refreshing[account.id] ? (
                                                            <RefreshCw className="h-4 w-4 animate-spin" />
                                                        ) : (
                                                            <RefreshCw className="h-4 w-4" />
                                                        )}
                                                        Refresh
                                                    </Button>

                                                    <Button
                                                        variant="destructive"
                                                        size="sm"
                                                        onClick={() => handleDisconnect(account.id, account.facebook_page_name)}
                                                        disabled={disconnecting[account.id]}
                                                        className="gap-2 flex-1"
                                                    >
                                                        {disconnecting[account.id] ? (
                                                            <RefreshCw className="h-4 w-4 animate-spin" />
                                                        ) : (
                                                            <Trash2 className="h-4 w-4" />
                                                        )}
                                                        Disconnect
                                                    </Button>
                                                </div>

                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() => handleSetDefault(account.id)}
                                                    className="gap-2"
                                                >
                                                    <CheckCircle className="h-4 w-4" />
                                                    Set as Default
                                                </Button>

                                                <Link href={`/facebook/posts/create?account_id=${account.id}`}>
                                                    <Button size="sm" className="w-full gap-2">
                                                        <Plus className="h-4 w-4" />
                                                        Create Post
                                                    </Button>
                                                </Link>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}

                        {accounts.length > 0 && (
                            <div className="mt-6 pt-6 border-t">
                                <div className="flex items-center justify-between">
                                    <div className="text-sm text-muted-foreground">
                                        {accounts.length} connected page{accounts.length !== 1 ? 's' : ''}
                                    </div>
                                    <Button
                                        onClick={connectToFacebook}
                                        variant="outline"
                                        className="gap-2"
                                    >
                                        <Plus className="h-4 w-4" />
                                        Connect Another Page
                                    </Button>
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Next Steps */}
                {accounts.length > 0 && (
                    <Card className="mt-6">
                        <CardHeader>
                            <CardTitle>Next Steps</CardTitle>
                            <CardDescription>
                                What you can do with connected Facebook pages
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div className="p-4 border rounded-lg">
                                    <div className="w-10 h-10 rounded-full bg-green-100 dark:bg-green-900/20 flex items-center justify-center mb-3">
                                        <Plus className="h-5 w-5 text-green-600" />
                                    </div>
                                    <h4 className="font-bold mb-2">Create Posts</h4>
                                    <p className="text-sm text-muted-foreground">
                                        Create and schedule posts to your connected pages
                                    </p>
                                    <Link href="/facebook/posts/create">
                                        <Button variant="link" className="p-0 h-auto mt-2">
                                            Start creating →
                                        </Button>
                                    </Link>
                                </div>

                                <div className="p-4 border rounded-lg">
                                    <div className="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900/20 flex items-center justify-center mb-3">
                                        <Globe className="h-5 w-5 text-blue-600" />
                                    </div>
                                    <h4 className="font-bold mb-2">View Posts</h4>
                                    <p className="text-sm text-muted-foreground">
                                        See all your scheduled and published posts
                                    </p>
                                    <Link href="/facebook/posts">
                                        <Button variant="link" className="p-0 h-auto mt-2">
                                            View all posts →
                                        </Button>
                                    </Link>
                                </div>

                                <div className="p-4 border rounded-lg">
                                    <div className="w-10 h-10 rounded-full bg-purple-100 dark:bg-purple-900/20 flex items-center justify-center mb-3">
                                        <ExternalLink className="h-5 w-5 text-purple-600" />
                                    </div>
                                    <h4 className="font-bold mb-2">Visit Pages</h4>
                                    <p className="text-sm text-muted-foreground">
                                        Visit your Facebook pages to see published content
                                    </p>
                                    <div className="flex flex-wrap gap-2 mt-2">
                                        {accounts.map(account => (
                                            <a
                                                key={account.id}
                                                href={`https://facebook.com/${account.facebook_page_id}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-sm text-blue-600 hover:underline"
                                            >
                                                {account.facebook_page_name}
                                            </a>
                                        ))}
                                    </div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
