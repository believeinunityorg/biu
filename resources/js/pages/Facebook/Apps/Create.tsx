import React, { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/frontend/ui/switch';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Key,
    Globe,
    Info,
    ExternalLink,
    Shield,
    Copy,
    ArrowLeft
} from 'lucide-react';
import { toast } from 'sonner';

interface Props {
    defaultCallbackUrl?: string;
}

export default function FacebookAppsCreate({ defaultCallbackUrl = '' }: Props) {
    const [form, setForm] = useState({
        app_name: '',
        facebook_app_id: '',
        facebook_app_secret: '',
        callback_url: defaultCallbackUrl,
        is_default_app: false,
    });
    const [loading, setLoading] = useState(false);

    const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        const { name, value, type } = e.target;
        setForm(prev => ({
            ...prev,
            [name]: type === 'checkbox' ? (e.target as HTMLInputElement).checked : value
        }));
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();

        if (!form.app_name.trim()) {
            toast.error('Please enter an app name');
            return;
        }

        if (!form.facebook_app_id.trim()) {
            toast.error('Please enter Facebook App ID');
            return;
        }

        if (!form.facebook_app_secret.trim()) {
            toast.error('Please enter Facebook App Secret');
            return;
        }

        setLoading(true);

        try {
            await router.post('/facebook/apps', form, {
                onSuccess: () => {
                    toast.success('Facebook App created successfully');
                },
                onError: (errors) => {
                    if (errors) {
                        Object.values(errors).forEach(error => {
                            if (Array.isArray(error)) {
                                error.forEach(err => toast.error(err));
                            } else {
                                toast.error(error);
                            }
                        });
                    }
                },
            });
        } catch (error) {
            toast.error('Failed to create Facebook App');
        } finally {
            setLoading(false);
        }
    };

    const copyToClipboard = (text: string) => {
        navigator.clipboard.writeText(text);
        toast.success('Copied to clipboard');
    };

    return (
        <AppLayout>
            <Head title="Add Facebook App" />

            <div className="container mx-auto p-4 md:p-6">
                {/* Header */}
                <div className="flex items-center gap-4 mb-6">
                    <Link href="/facebook/apps">
                        <Button variant="ghost" size="sm" className="gap-2">
                            <ArrowLeft className="h-4 w-4" />
                            Back to Apps
                        </Button>
                    </Link>
                    <div>
                        <h1 className="text-3xl font-bold tracking-tight">Add Facebook App</h1>
                        <p className="text-muted-foreground">
                            Add your Facebook App credentials to connect pages
                        </p>
                    </div>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Form */}
                    <div className="lg:col-span-2">
                        <Card>
                            <CardHeader>
                                <CardTitle>App Credentials</CardTitle>
                                <CardDescription>
                                    Enter your Facebook App ID and App Secret
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form onSubmit={handleSubmit} className="space-y-4">
                                    <div className="space-y-2">
                                        <Label htmlFor="app_name">App Name *</Label>
                                        <Input
                                            id="app_name"
                                            name="app_name"
                                            value={form.app_name}
                                            onChange={handleInputChange}
                                            placeholder="My Facebook App"
                                            required
                                        />
                                        <p className="text-sm text-muted-foreground">
                                            A friendly name to identify this app
                                        </p>
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="facebook_app_id">Facebook App ID *</Label>
                                        <Input
                                            id="facebook_app_id"
                                            name="facebook_app_id"
                                            value={form.facebook_app_id}
                                            onChange={handleInputChange}
                                            placeholder="123456789012345"
                                            required
                                        />
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="facebook_app_secret">Facebook App Secret *</Label>
                                        <Input
                                            id="facebook_app_secret"
                                            name="facebook_app_secret"
                                            type="password"
                                            value={form.facebook_app_secret}
                                            onChange={handleInputChange}
                                            placeholder="abcdef1234567890abcdef1234567890"
                                            required
                                        />
                                    </div>

                                    <div className="space-y-2">
                                        <Label htmlFor="callback_url">Callback URL</Label>
                                        <div className="flex">
                                            <Input
                                                id="callback_url"
                                                name="callback_url"
                                                disabled={true}
                                                value={form.callback_url}
                                                onChange={handleInputChange}
                                                placeholder={defaultCallbackUrl}
                                            />
                                            {/* <Button
                                                type="button"
                                                variant="outline"
                                                onClick={() => copyToClipboard(form.callback_url)}
                                                className="gap-2"
                                            >
                                                <Copy className="h-4 w-4" />
                                            </Button> */}
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            Must match the OAuth redirect URI in Facebook Developer settings
                                        </p>
                                    </div>

                                    <div className="flex items-center justify-between p-4 border rounded-lg">
                                        <div className="space-y-0.5">
                                            <Label htmlFor="is_default_app" className="text-base">
                                                Set as Default App
                                            </Label>
                                            <p className="text-sm text-muted-foreground">
                                                Use this app for new page connections by default
                                            </p>
                                        </div>
                                        <Switch
                                            id="is_default_app"
                                            checked={form.is_default_app}
                                            onCheckedChange={(checked) =>
                                                setForm(prev => ({ ...prev, is_default_app: checked }))
                                            }
                                        />
                                    </div>

                                    <div className="flex gap-3 pt-4">
                                        <Link href="/facebook/apps">
                                            <Button type="button" variant="outline">
                                                Cancel
                                            </Button>
                                        </Link>
                                        <Button type="submit" disabled={loading}>
                                            {loading ? 'Creating...' : 'Create Facebook App'}
                                        </Button>
                                    </div>
                                </form>
                            </CardContent>
                        </Card>
                    </div>

                    {/* Instructions */}
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Info className="h-5 w-5" />
                                    How to Get Credentials
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <div className="space-y-3">
                                    <div className="flex items-start gap-3">
                                        <div className="flex-shrink-0 w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                                            <span className="font-bold text-primary">1</span>
                                        </div>
                                        <div>
                                            <h4 className="font-medium">Go to Facebook Developer</h4>
                                            <p className="text-sm text-muted-foreground">
                                                Visit{' '}
                                                <a
                                                    href="https://developers.facebook.com/apps"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="text-blue-600 hover:underline"
                                                >
                                                    developers.facebook.com/apps
                                                </a>
                                            </p>
                                        </div>
                                    </div>

                                    <div className="flex items-start gap-3">
                                        <div className="flex-shrink-0 w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                                            <span className="font-bold text-primary">2</span>
                                        </div>
                                        <div>
                                            <h4 className="font-medium">Create or Select App</h4>
                                            <p className="text-sm text-muted-foreground">
                                                Create a new app or use an existing one
                                            </p>
                                        </div>
                                    </div>

                                    <div className="flex items-start gap-3">
                                        <div className="flex-shrink-0 w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                                            <span className="font-bold text-primary">3</span>
                                        </div>
                                        <div>
                                            <h4 className="font-medium">Get Credentials</h4>
                                            <p className="text-sm text-muted-foreground">
                                                Go to Settings → Basic to find App ID and App Secret
                                            </p>
                                        </div>
                                    </div>

                                    <div className="flex items-start gap-3">
                                        <div className="flex-shrink-0 w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                                            <span className="font-bold text-primary">4</span>
                                        </div>
                                        <div>
                                            <h4 className="font-medium">Add Facebook Login</h4>
                                            <p className="text-sm text-muted-foreground">
                                                Add "Facebook Login" product to your app
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <a
                                    href="https://developers.facebook.com/apps"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <Button className="w-full gap-2">
                                        <ExternalLink className="h-4 w-4" />
                                        Open Facebook Developer
                                    </Button>
                                </a>
                            </CardContent>
                        </Card>

                        <Alert className="bg-yellow-50 dark:bg-yellow-900/20 border-yellow-200 dark:border-yellow-800">
                            <Shield className="h-4 w-4 text-yellow-600" />
                            <AlertTitle>Important Security Note</AlertTitle>
                            <AlertDescription className="text-sm">
                                Your App Secret is sensitive information. Keep it secure and never share it publicly.
                            </AlertDescription>
                        </Alert>

                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2">
                                    <Globe className="h-5 w-5" />
                                    Required Permissions
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div className="space-y-2">
                                    <div className="flex items-center gap-2">
                                        <div className="w-2 h-2 rounded-full bg-green-500"></div>
                                        <span className="text-sm">pages_show_list</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <div className="w-2 h-2 rounded-full bg-green-500"></div>
                                        <span className="text-sm">pages_manage_posts</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
