"use client"

import React, { useState } from "react"
import { Head, useForm, Link } from "@inertiajs/react"
import AppLayout from "@/layouts/app-layout"
import { Button } from "@/components/ui/button"
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card"
import { Input } from "@/components/ui/input"
import { Label } from "@/components/ui/label"
import { Textarea } from "@/components/ui/textarea"
import { Switch } from "@/components/admin/ui/switch"
import {
    Save,
    ArrowLeft,
    Plus,
    Trash2,
} from "lucide-react"
import type { BreadcrumbItem } from "@/types"

interface EditProps {
    section: string
    content: any
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Contact Page', href: '/admin/contact-page' },
    { title: 'Edit Section', href: '#' },
]

export default function AdminContactPageEdit({ section, content }: EditProps) {
    const isArraySection = ['contact_methods', 'faq'].includes(section)
    const initialContent = isArraySection
        ? (Array.isArray(content) ? content : [])
        : (content || { content: {} })

    const { data, setData, put, processing, errors } = useForm({
        content: isArraySection ? {} : (initialContent.content || {}),
        items: isArraySection ? (Array.isArray(initialContent) ? initialContent.map((item: any) => ({
            id: item.id,
            content: item.content || {},
            sort_order: item.sort_order || 0,
            is_active: item.is_active !== false,
        })) : []) : [],
        is_active: !isArraySection ? (initialContent.is_active !== false) : true,
    })

    const addItem = () => {
        if (isArraySection) {
            const newItem = {
                id: null,
                content: section === 'contact_methods'
                    ? { title: '', description: '', contact: '', action: '', color: 'from-blue-500 to-blue-600', bgColor: 'bg-blue-50 dark:bg-blue-900/20', iconColor: 'text-blue-600 dark:text-blue-400' }
                    : { question: '', answer: '' },
                sort_order: data.items.length,
                is_active: true,
            }
            setData('items', [...data.items, newItem])
        }
    }

    const removeItem = (index: number) => {
        if (isArraySection) {
            setData('items', data.items.filter((_: any, i: number) => i !== index))
        }
    }

    const updateItem = (index: number, field: string, value: any) => {
        if (isArraySection) {
            const updated = [...data.items]
            updated[index] = {
                ...updated[index],
                content: {
                    ...updated[index].content,
                    [field]: value,
                },
            }
            setData('items', updated)
        }
    }

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault()
        put(`/admin/contact-page/${section}`)
    }

    const sectionTitles: Record<string, string> = {
        hero: 'Hero Section',
        contact_methods: 'Contact Methods',
        faq: 'FAQ Section',
        office_hours: 'Office Hours',
        office_location: 'Office Location',
        cta: 'Call to Action',
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Edit ${sectionTitles[section] || section}`} />

            <div className="space-y-6 p-4 sm:p-6">
                {/* Header */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 className="text-3xl font-bold text-gray-900 dark:text-white">
                            Edit {sectionTitles[section] || section}
                        </h1>
                        <p className="text-gray-600 dark:text-gray-400 mt-1.5">
                            Update the content for this section
                        </p>
                    </div>
                    <Link href="/admin/contact-page">
                        <Button variant="outline">
                            <ArrowLeft className="h-4 w-4 mr-2" />
                            Back
                        </Button>
                    </Link>
                </div>

                <form onSubmit={handleSubmit}>
                    <Card>
                        <CardHeader>
                            <CardTitle>{sectionTitles[section] || section}</CardTitle>
                            <CardDescription>
                                Configure the content for this section
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-6">
                            {/* Hero Section */}
                            {section === 'hero' && (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="badge_text">Badge Text</Label>
                                        <Input
                                            id="badge_text"
                                            value={data.content.badge_text || ''}
                                            onChange={(e) => setData('content', { ...data.content, badge_text: e.target.value })}
                                            placeholder="We're Here to Help"
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="title">Title *</Label>
                                        <Input
                                            id="title"
                                            value={data.content.title || ''}
                                            onChange={(e) => setData('content', { ...data.content, title: e.target.value })}
                                            placeholder="Get in Touch"
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="description">Description *</Label>
                                        <Textarea
                                            id="description"
                                            value={data.content.description || ''}
                                            onChange={(e) => setData('content', { ...data.content, description: e.target.value })}
                                            placeholder="Have questions? We're here to help..."
                                            rows={3}
                                            required
                                        />
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="is_active"
                                            checked={data.is_active}
                                            onCheckedChange={(checked) => setData('is_active', checked)}
                                        />
                                        <Label htmlFor="is_active">Active</Label>
                                    </div>
                                </>
                            )}

                            {/* Contact Methods */}
                            {section === 'contact_methods' && (
                                <>
                                    <div className="flex items-center justify-between">
                                        <Label>Contact Methods</Label>
                                        <Button type="button" onClick={addItem} size="sm" variant="outline">
                                            <Plus className="h-4 w-4 mr-2" />
                                            Add Method
                                        </Button>
                                    </div>
                                    {data.items.map((item: any, index: number) => (
                                        <Card key={index} className="p-4">
                                            <div className="flex items-start justify-between mb-4">
                                                <h4 className="font-semibold">Method {index + 1}</h4>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => removeItem(index)}
                                                >
                                                    <Trash2 className="h-4 w-4 text-red-500" />
                                                </Button>
                                            </div>
                                            <div className="space-y-4">
                                                <div className="grid grid-cols-2 gap-4">
                                                    <div className="space-y-2">
                                                        <Label>Title *</Label>
                                                        <Input
                                                            value={item.content.title || ''}
                                                            onChange={(e) => updateItem(index, 'title', e.target.value)}
                                                            placeholder="Email Support"
                                                            required
                                                        />
                                                    </div>
                                                    <div className="space-y-2">
                                                        <Label>Contact Info *</Label>
                                                        <Input
                                                            value={item.content.contact || ''}
                                                            onChange={(e) => updateItem(index, 'contact', e.target.value)}
                                                            placeholder="support@believeinunity.org"
                                                            required
                                                        />
                                                    </div>
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Description *</Label>
                                                    <Textarea
                                                        value={item.content.description || ''}
                                                        onChange={(e) => updateItem(index, 'description', e.target.value)}
                                                        placeholder="Send us an email..."
                                                        rows={2}
                                                        required
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Action Link</Label>
                                                    <Input
                                                        value={item.content.action || ''}
                                                        onChange={(e) => updateItem(index, 'action', e.target.value)}
                                                        placeholder="mailto:support@believeinunity.org"
                                                    />
                                                </div>
                                            </div>
                                        </Card>
                                    ))}
                                </>
                            )}

                            {/* FAQ */}
                            {section === 'faq' && (
                                <>
                                    <div className="flex items-center justify-between">
                                        <Label>FAQ Items</Label>
                                        <Button type="button" onClick={addItem} size="sm" variant="outline">
                                            <Plus className="h-4 w-4 mr-2" />
                                            Add FAQ
                                        </Button>
                                    </div>
                                    {data.items.map((item: any, index: number) => (
                                        <Card key={index} className="p-4">
                                            <div className="flex items-start justify-between mb-4">
                                                <h4 className="font-semibold">FAQ {index + 1}</h4>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => removeItem(index)}
                                                >
                                                    <Trash2 className="h-4 w-4 text-red-500" />
                                                </Button>
                                            </div>
                                            <div className="space-y-4">
                                                <div className="space-y-2">
                                                    <Label>Question *</Label>
                                                    <Input
                                                        value={item.content.question || ''}
                                                        onChange={(e) => updateItem(index, 'question', e.target.value)}
                                                        placeholder="How do I know my donation is secure?"
                                                        required
                                                    />
                                                </div>
                                                <div className="space-y-2">
                                                    <Label>Answer *</Label>
                                                    <Textarea
                                                        value={item.content.answer || ''}
                                                        onChange={(e) => updateItem(index, 'answer', e.target.value)}
                                                        placeholder="We use industry-standard encryption..."
                                                        rows={4}
                                                        required
                                                    />
                                                </div>
                                            </div>
                                        </Card>
                                    ))}
                                </>
                            )}

                            {/* Office Hours */}
                            {section === 'office_hours' && (
                                <>
                                    <div className="space-y-4">
                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="space-y-2">
                                                <Label>Day Range</Label>
                                                <Input
                                                    value={data.content.day_range || ''}
                                                    onChange={(e) => setData('content', { ...data.content, day_range: e.target.value })}
                                                    placeholder="Monday - Friday"
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Hours</Label>
                                                <Input
                                                    value={data.content.hours || ''}
                                                    onChange={(e) => setData('content', { ...data.content, hours: e.target.value })}
                                                    placeholder="9:00 AM - 6:00 PM EST"
                                                />
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="space-y-2">
                                                <Label>Saturday Day</Label>
                                                <Input
                                                    value={data.content.saturday_day || ''}
                                                    onChange={(e) => setData('content', { ...data.content, saturday_day: e.target.value })}
                                                    placeholder="Saturday"
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Saturday Hours</Label>
                                                <Input
                                                    value={data.content.saturday_hours || ''}
                                                    onChange={(e) => setData('content', { ...data.content, saturday_hours: e.target.value })}
                                                    placeholder="10:00 AM - 4:00 PM EST"
                                                />
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="space-y-2">
                                                <Label>Sunday Day</Label>
                                                <Input
                                                    value={data.content.sunday_day || ''}
                                                    onChange={(e) => setData('content', { ...data.content, sunday_day: e.target.value })}
                                                    placeholder="Sunday"
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Sunday Status</Label>
                                                <Input
                                                    value={data.content.sunday_status || ''}
                                                    onChange={(e) => setData('content', { ...data.content, sunday_status: e.target.value })}
                                                    placeholder="Closed"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="is_active"
                                            checked={data.is_active}
                                            onCheckedChange={(checked) => setData('is_active', checked)}
                                        />
                                        <Label htmlFor="is_active">Active</Label>
                                    </div>
                                </>
                            )}

                            {/* Office Location */}
                            {section === 'office_location' && (
                                <>
                                    <div className="space-y-4">
                                        <div className="space-y-2">
                                            <Label>Address Line 1</Label>
                                            <Input
                                                value={data.content.address_line1 || ''}
                                                onChange={(e) => setData('content', { ...data.content, address_line1: e.target.value })}
                                                placeholder="123 Charity Lane"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Address Line 2</Label>
                                            <Input
                                                value={data.content.address_line2 || ''}
                                                onChange={(e) => setData('content', { ...data.content, address_line2: e.target.value })}
                                                placeholder="Suite 456"
                                            />
                                        </div>
                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="space-y-2">
                                                <Label>City</Label>
                                                <Input
                                                    value={data.content.city || ''}
                                                    onChange={(e) => setData('content', { ...data.content, city: e.target.value })}
                                                    placeholder="New York"
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>State</Label>
                                                <Input
                                                    value={data.content.state || ''}
                                                    onChange={(e) => setData('content', { ...data.content, state: e.target.value })}
                                                    placeholder="NY"
                                                />
                                            </div>
                                        </div>
                                        <div className="grid grid-cols-2 gap-4">
                                            <div className="space-y-2">
                                                <Label>ZIP Code</Label>
                                                <Input
                                                    value={data.content.zip || ''}
                                                    onChange={(e) => setData('content', { ...data.content, zip: e.target.value })}
                                                    placeholder="10001"
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label>Country</Label>
                                                <Input
                                                    value={data.content.country || ''}
                                                    onChange={(e) => setData('content', { ...data.content, country: e.target.value })}
                                                    placeholder="United States"
                                                />
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="is_active"
                                            checked={data.is_active}
                                            onCheckedChange={(checked) => setData('is_active', checked)}
                                        />
                                        <Label htmlFor="is_active">Active</Label>
                                    </div>
                                </>
                            )}

                            {/* CTA Section */}
                            {section === 'cta' && (
                                <>
                                    <div className="space-y-2">
                                        <Label>Title *</Label>
                                        <Input
                                            value={data.content.title || ''}
                                            onChange={(e) => setData('content', { ...data.content, title: e.target.value })}
                                            placeholder="Ready to Make a Difference?"
                                            required
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label>Description *</Label>
                                        <Textarea
                                            value={data.content.description || ''}
                                            onChange={(e) => setData('content', { ...data.content, description: e.target.value })}
                                            placeholder="Join thousands of supporters..."
                                            rows={3}
                                            required
                                        />
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label>Button 1 Text</Label>
                                            <Input
                                                value={data.content.button1_text || ''}
                                                onChange={(e) => setData('content', { ...data.content, button1_text: e.target.value })}
                                                placeholder="Start Donating"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Button 1 Link</Label>
                                            <Input
                                                value={data.content.button1_link || ''}
                                                onChange={(e) => setData('content', { ...data.content, button1_link: e.target.value })}
                                                placeholder="/donate"
                                            />
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="space-y-2">
                                            <Label>Button 2 Text</Label>
                                            <Input
                                                value={data.content.button2_text || ''}
                                                onChange={(e) => setData('content', { ...data.content, button2_text: e.target.value })}
                                                placeholder="Browse Organizations"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Button 2 Link</Label>
                                            <Input
                                                value={data.content.button2_link || ''}
                                                onChange={(e) => setData('content', { ...data.content, button2_link: e.target.value })}
                                                placeholder="/organizations"
                                            />
                                        </div>
                                    </div>
                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="is_active"
                                            checked={data.is_active}
                                            onCheckedChange={(checked) => setData('is_active', checked)}
                                        />
                                        <Label htmlFor="is_active">Active</Label>
                                    </div>
                                </>
                            )}

                            {/* Submit Button */}
                            <div className="flex justify-end gap-4 pt-4 border-t">
                                <Link href="/admin/contact-page">
                                    <Button type="button" variant="outline">
                                        Cancel
                                    </Button>
                                </Link>
                                <Button type="submit" disabled={processing} className="bg-primary">
                                    {processing ? (
                                        <>
                                            <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                                            Saving...
                                        </>
                                    ) : (
                                        <>
                                            <Save className="h-4 w-4 mr-2" />
                                            Save Changes
                                        </>
                                    )}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </form>
            </div>
        </AppLayout>
    )
}

