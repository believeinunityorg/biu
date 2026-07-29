import { motion } from 'framer-motion'
import { CheckCircle2, AlertCircle, Clock, ExternalLink, Shield, RefreshCw, FileCheck } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { ImageUploadDropzone } from '@/components/ImageUploadDropzone'
import { DocumentUploadDropzone } from '@/components/DocumentUploadDropzone'
import { KybFormData, KybStep } from './types'
import { getCsrfToken } from './utils'
import { showSuccessToast, showErrorToast } from '@/lib/toast'

interface KYBFormProps {
    kybStep: KybStep
    kybFormData: KybFormData
    controlPersonErrors: Record<string, string>
    businessDocumentErrors: Record<string, string>
    documentStatuses: Record<string, string>
    documentRejectionReasons: Record<string, string>
    businessDocumentsSubmitted: boolean
    requestedFields: string[]
    refillMessage: string | null
    controlPersonKycIframeUrl: string | null
    controlPersonKycLink: string | null
    kybSubmissionStatus: string | null
    isLoading: boolean
    signedAgreementId: string | null
    tosStatus: 'pending' | 'accepted' | 'rejected'
    onKybFormDataChange: (data: KybFormData) => void
    onControlPersonErrorsChange: (errors: Record<string, string>) => void
    onBusinessDocumentErrorsChange: (errors: Record<string, string>) => void
    onKybStepChange: (step: KybStep) => void
    onBusinessDocumentsSubmittedChange: (submitted: boolean) => void
    onRequestedFieldsChange: (fields: string[]) => void
    onRefillMessageChange: (message: string | null) => void
    onControlPersonKycIframeUrlChange: (url: string | null) => void
    onControlPersonKycLinkChange: (link: string | null) => void
    onIsLoadingChange: (loading: boolean) => void
    onSubmitControlPerson: () => Promise<void>
    onSubmitBusinessDocuments: () => Promise<void>
    onControlPersonKyc: (silent?: boolean) => Promise<void>
    shouldShowField: (fieldPath: string) => boolean
}

export function KYBForm({
    kybStep,
    kybFormData,
    controlPersonErrors,
    businessDocumentErrors,
    documentStatuses,
    documentRejectionReasons,
    businessDocumentsSubmitted,
    requestedFields,
    refillMessage,
    controlPersonKycIframeUrl,
    controlPersonKycLink,
    kybSubmissionStatus,
    isLoading,
    signedAgreementId,
    tosStatus,
    onKybFormDataChange,
    onControlPersonErrorsChange,
    onBusinessDocumentErrorsChange,
    onKybStepChange,
    onBusinessDocumentsSubmittedChange,
    onRequestedFieldsChange,
    onRefillMessageChange,
    onControlPersonKycIframeUrlChange,
    onControlPersonKycLinkChange,
    onIsLoadingChange,
    onSubmitControlPerson,
    onSubmitBusinessDocuments,
    onControlPersonKyc,
    shouldShowField
}: KYBFormProps) {
    const handleFieldChange = (field: keyof KybFormData, value: any) => {
        onKybFormDataChange({
            ...kybFormData,
            [field]: value
        })
    }

    const handleControlPersonFieldChange = (field: keyof KybFormData['control_person'], value: any) => {
        onKybFormDataChange({
            ...kybFormData,
            control_person: {
                ...kybFormData.control_person,
                [field]: value
            }
        })
    }

    const handlePhysicalAddressFieldChange = (field: keyof KybFormData['physical_address'], value: string) => {
        onKybFormDataChange({
            ...kybFormData,
            physical_address: {
                ...kybFormData.physical_address,
                [field]: value
            }
        })
    }

    return (
        <div className="space-y-2 sm:space-y-3 max-h-[250px] sm:max-h-[350px] overflow-y-auto overflow-x-hidden wallet-scroll-nested w-full -mx-0">
            {/* Step Indicator with Labels */}
            <div className="mb-3 space-y-2">
                <div className="flex items-center gap-1">
                    {/* Step 1 */}
                    <div className="flex items-center gap-1 flex-shrink-0">
                        <div className={`w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold transition-all duration-200 ${
                            kybStep === 'control_person' 
                                ? 'bg-gradient-to-r from-blue-600 to-purple-600 text-white shadow-md' 
                                : kybStep === 'business_documents' || kybStep === 'kyc_verification'
                                ? 'bg-green-500 text-white'
                                : 'bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400'
                        }`}>
                            {kybStep === 'business_documents' || kybStep === 'kyc_verification' ? (
                                <CheckCircle2 className="h-3 w-3" />
                            ) : (
                                '1'
                            )}
                        </div>
                        <span className={`text-[10px] font-medium whitespace-nowrap hidden sm:inline ${
                            kybStep === 'control_person' 
                                ? 'text-blue-600 dark:text-blue-400' 
                                : kybStep === 'business_documents' || kybStep === 'kyc_verification'
                                ? 'text-green-600 dark:text-green-400'
                                : 'text-gray-500 dark:text-gray-400'
                        }`}>
                            Control Person
                        </span>
                    </div>
                    
                    {/* Connector 1 */}
                    <div className={`flex-1 h-0.5 rounded-full transition-all duration-200 ${
                        kybStep === 'business_documents' || kybStep === 'kyc_verification' 
                            ? 'bg-green-500' 
                            : 'bg-gray-200 dark:bg-gray-700'
                    }`}></div>
                    
                    {/* Step 2 */}
                    <div className="flex items-center gap-1 flex-shrink-0">
                        <div className={`w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold transition-all duration-200 ${
                            kybStep === 'business_documents' 
                                ? 'bg-gradient-to-r from-blue-600 to-purple-600 text-white shadow-md' 
                                : (kybStep === 'kyc_verification' || businessDocumentsSubmitted)
                                ? 'bg-green-500 text-white'
                                : 'bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400'
                        }`}>
                            {(kybStep === 'kyc_verification' || businessDocumentsSubmitted) ? (
                                <CheckCircle2 className="h-3 w-3" />
                            ) : (
                                '2'
                            )}
                        </div>
                        <span className={`text-[10px] font-medium whitespace-nowrap hidden sm:inline ${
                            kybStep === 'business_documents' 
                                ? 'text-blue-600 dark:text-blue-400' 
                                : (kybStep === 'kyc_verification' || businessDocumentsSubmitted)
                                ? 'text-green-600 dark:text-green-400'
                                : 'text-gray-500 dark:text-gray-400'
                        }`}>
                            Documents
                        </span>
                    </div>
                    
                    {/* Connector 2 */}
                    <div className={`flex-1 h-0.5 rounded-full transition-all duration-200 ${
                        kybStep === 'kyc_verification' 
                            ? 'bg-green-500' 
                            : (kybSubmissionStatus === 'approved' || businessDocumentsSubmitted) 
                            ? 'bg-green-500' 
                            : 'bg-gray-200 dark:bg-gray-700'
                    }`}></div>
                    
                    {/* Step 3 */}
                    <div className="flex items-center gap-1 flex-shrink-0">
                        <div className={`w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold transition-all duration-200 ${
                            kybStep === 'kyc_verification' 
                                ? 'bg-gradient-to-r from-blue-600 to-purple-600 text-white shadow-md' 
                                : (kybSubmissionStatus === 'approved')
                                ? 'bg-green-500 text-white'
                                : 'bg-gray-200 dark:bg-gray-700 text-gray-500 dark:text-gray-400'
                        }`}>
                            {kybSubmissionStatus === 'approved' && kybStep !== 'kyc_verification' ? (
                                <CheckCircle2 className="h-3 w-3" />
                            ) : (
                                '3'
                            )}
                        </div>
                        <span className={`text-[10px] font-medium whitespace-nowrap hidden sm:inline ${
                            kybStep === 'kyc_verification' 
                                ? 'text-blue-600 dark:text-blue-400' 
                                : (kybSubmissionStatus === 'approved')
                                ? 'text-green-600 dark:text-green-400'
                                : 'text-gray-500 dark:text-gray-400'
                        }`}>
                            KYC Verify
                        </span>
                    </div>
                </div>
                
                {/* Step Labels (Mobile) */}
                <div className="sm:hidden text-center">
                    <p className={`text-[10px] font-medium ${
                        kybStep === 'control_person' 
                            ? 'text-blue-600 dark:text-blue-400' 
                            : kybStep === 'business_documents'
                            ? 'text-blue-600 dark:text-blue-400'
                            : 'text-blue-600 dark:text-blue-400'
                    }`}>
                        {kybStep === 'control_person' && 'Step 1: Control Person'}
                        {kybStep === 'business_documents' && 'Step 2: Business Documents'}
                        {kybStep === 'kyc_verification' && 'Step 3: KYC Verification'}
                    </p>
                </div>
            </div>

            {/* Step 1: Control Person */}
            {kybStep === 'control_person' && (
                <>
                    {/* Show refill message banner if admin requested re-fill */}
                    {(refillMessage || (requestedFields && requestedFields.length > 0)) && (
                        <div className="mb-3 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                            <div className="flex items-start gap-2">
                                <AlertCircle className="h-4 w-4 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" />
                                <div className="flex-1">
                                    <p className="text-xs font-semibold text-amber-900 dark:text-amber-100 mb-1">
                                        Admin Request: Please Re-fill the Following Fields
                                    </p>
                                    {refillMessage && (
                                        <p className="text-xs text-amber-800 dark:text-amber-200 mb-2">
                                            {refillMessage}
                                        </p>
                                    )}
                                    {requestedFields && requestedFields.length > 0 && (
                                        <div className="mt-2">
                                            <p className="text-xs font-medium text-amber-900 dark:text-amber-100 mb-1">
                                                Requested Fields ({requestedFields.length}):
                                            </p>
                                            <div className="flex flex-wrap gap-1">
                                                {requestedFields.map((field, idx) => (
                                                    <span key={idx} className="text-xs px-2 py-0.5 bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200 rounded">
                                                        {field}
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}
                    
                    {/* Display business info (read-only) from organization */}
                    {(shouldShowField('business_name') || shouldShowField('email') || shouldShowField('ein') || shouldShowField('street_line_1')) && (
                        <div className="mb-3 p-2 bg-muted/50 rounded-lg border border-border">
                            <p className="text-xs font-semibold mb-2 text-left">Business Information (from your organization profile)</p>
                            <div className="space-y-1 text-xs text-muted-foreground text-left">
                                {shouldShowField('business_name') && <p><span className="font-medium">Business Name:</span> {kybFormData.business_name || 'N/A'}</p>}
                                {shouldShowField('email') && <p><span className="font-medium">Email:</span> {kybFormData.email || 'N/A'}</p>}
                                {shouldShowField('ein') && <p><span className="font-medium">EIN:</span> {kybFormData.ein || 'N/A'}</p>}
                                {shouldShowField('street_line_1') && <p><span className="font-medium">Address:</span> {[kybFormData.street_line_1, kybFormData.city, kybFormData.subdivision, kybFormData.postal_code].filter(Boolean).join(', ') || 'N/A'}</p>}
                            </div>
                        </div>
                    )}
                    
                    <div className="border-t border-border pt-3 mt-3">
                        <p className="text-xs font-semibold mb-2 text-left">Step 1: Control Person (Beneficial Owner) Information *</p>
                        <p className="text-xs text-muted-foreground mb-3 text-left">
                            {requestedFields.length > 0 
                                ? 'Please re-fill the requested fields below.' 
                                : 'Required by Believe Cash for business verification'}
                        </p>
                
                        {/* Control Person Fields */}
                        <div className="space-y-2 sm:space-y-3">
                            {(shouldShowField('control_person.first_name') || shouldShowField('control_person.last_name')) && (
                                <div className="grid grid-cols-2 gap-2">
                                    {shouldShowField('control_person.first_name') && (
                                        <div>
                                            <label className="text-xs font-medium mb-1 block text-left">First Name *</label>
                                            <input
                                                type="text"
                                                value={kybFormData.control_person.first_name}
                                                onChange={(e) => {
                                                    handleControlPersonFieldChange('first_name', e.target.value)
                                                    if (controlPersonErrors.first_name) {
                                                        onControlPersonErrorsChange({
                                                            ...controlPersonErrors,
                                                            first_name: undefined as any
                                                        })
                                                    }
                                                }}
                                                className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground ${
                                                    controlPersonErrors.first_name ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                                }`}
                                                placeholder="John"
                                            />
                                            {controlPersonErrors.first_name && (
                                                <p className="text-xs text-red-500 mt-1">{controlPersonErrors.first_name}</p>
                                            )}
                                        </div>
                                    )}
                                    {shouldShowField('control_person.last_name') && (
                                        <div>
                                            <label className="text-xs font-medium mb-1 block text-left">Last Name *</label>
                                            <input
                                                type="text"
                                                value={kybFormData.control_person.last_name}
                                                onChange={(e) => {
                                                    handleControlPersonFieldChange('last_name', e.target.value)
                                                    if (controlPersonErrors.last_name) {
                                                        onControlPersonErrorsChange({
                                                            ...controlPersonErrors,
                                                            last_name: undefined as any
                                                        })
                                                    }
                                                }}
                                                className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground ${
                                                    controlPersonErrors.last_name ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                                }`}
                                                placeholder="Doe"
                                            />
                                            {controlPersonErrors.last_name && (
                                                <p className="text-xs text-red-500 mt-1">{controlPersonErrors.last_name}</p>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}
                            
                            {/* Email Field */}
                            {shouldShowField('control_person.email') && (
                                <div>
                                    <label className="text-xs font-medium mb-1 block text-left">Email *</label>
                                    <input
                                        type="email"
                                        value={kybFormData.control_person.email}
                                        onChange={(e) => {
                                            handleControlPersonFieldChange('email', e.target.value)
                                            if (controlPersonErrors.email) {
                                                const newErrors = { ...controlPersonErrors }
                                                delete newErrors.email
                                                onControlPersonErrorsChange(newErrors)
                                            }
                                        }}
                                        className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground ${
                                            controlPersonErrors.email ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                        }`}
                                        placeholder="john@example.com"
                                    />
                                    {controlPersonErrors.email && (
                                        <p className="text-xs text-red-500 mt-1">{controlPersonErrors.email}</p>
                                    )}
                                </div>
                            )}
                            
                            {/* Birth Date and SSN */}
                            {(shouldShowField('control_person.birth_date') || shouldShowField('control_person.ssn')) && (
                                <div className="grid grid-cols-2 gap-2">
                                    {shouldShowField('control_person.birth_date') && (
                                        <div>
                                            <label className="text-xs font-medium mb-1 block text-left">Date of Birth *</label>
                                            <input
                                                type="date"
                                                value={kybFormData.control_person.birth_date}
                                                onChange={(e) => {
                                                    handleControlPersonFieldChange('birth_date', e.target.value)
                                                    if (controlPersonErrors.birth_date) {
                                                        const newErrors = { ...controlPersonErrors }
                                                        delete newErrors.birth_date
                                                        onControlPersonErrorsChange(newErrors)
                                                    }
                                                }}
                                                className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground ${
                                                    controlPersonErrors.birth_date ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                                }`}
                                            />
                                            {controlPersonErrors.birth_date && (
                                                <p className="text-xs text-red-500 mt-1">{controlPersonErrors.birth_date}</p>
                                            )}
                                        </div>
                                    )}
                                    {shouldShowField('control_person.ssn') && (
                                        <div>
                                            <label className="text-xs font-medium mb-1 block text-left">SSN *</label>
                                            <input
                                                type="text"
                                                value={kybFormData.control_person.ssn}
                                                onChange={(e) => {
                                                    handleControlPersonFieldChange('ssn', e.target.value)
                                                    if (controlPersonErrors.ssn) {
                                                        const newErrors = { ...controlPersonErrors }
                                                        delete newErrors.ssn
                                                        onControlPersonErrorsChange(newErrors)
                                                    }
                                                }}
                                                className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground ${
                                                    controlPersonErrors.ssn ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                                }`}
                                                placeholder="123-45-6789"
                                            />
                                            {controlPersonErrors.ssn && (
                                                <p className="text-xs text-red-500 mt-1">{controlPersonErrors.ssn}</p>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}
                            
                            {/* Title and Ownership Percentage */}
                            {(shouldShowField('control_person.title') || shouldShowField('control_person.ownership_percentage')) && (
                                <div className="grid grid-cols-2 gap-2">
                                    {shouldShowField('control_person.title') && (
                                        <div>
                                            <label className="text-xs font-medium mb-1 block text-left">Title *</label>
                                            <input
                                                type="text"
                                                value={kybFormData.control_person.title}
                                                onChange={(e) => {
                                                    handleControlPersonFieldChange('title', e.target.value)
                                                    if (controlPersonErrors.title) {
                                                        const newErrors = { ...controlPersonErrors }
                                                        delete newErrors.title
                                                        onControlPersonErrorsChange(newErrors)
                                                    }
                                                }}
                                                className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground ${
                                                    controlPersonErrors.title ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                                }`}
                                                placeholder="CEO, President, etc."
                                            />
                                            {controlPersonErrors.title && (
                                                <p className="text-xs text-red-500 mt-1">{controlPersonErrors.title}</p>
                                            )}
                                        </div>
                                    )}
                                    {shouldShowField('control_person.ownership_percentage') && (
                                        <div>
                                            <label className="text-xs font-medium mb-1 block text-left">Ownership % *</label>
                                            <input
                                                type="number"
                                                min="0"
                                                max="100"
                                                value={kybFormData.control_person.ownership_percentage}
                                                onChange={(e) => {
                                                    handleControlPersonFieldChange('ownership_percentage', e.target.value)
                                                    if (controlPersonErrors.ownership_percentage) {
                                                        const newErrors = { ...controlPersonErrors }
                                                        delete newErrors.ownership_percentage
                                                        onControlPersonErrorsChange(newErrors)
                                                    }
                                                }}
                                                className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground ${
                                                    controlPersonErrors.ownership_percentage ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                                }`}
                                                placeholder="50"
                                            />
                                            {controlPersonErrors.ownership_percentage && (
                                                <p className="text-xs text-red-500 mt-1">{controlPersonErrors.ownership_percentage}</p>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}
                            
                            {/* Control Person Address */}
                            {(shouldShowField('control_person.street_line_1') || shouldShowField('control_person.city') || shouldShowField('control_person.state') || shouldShowField('control_person.postal_code')) && (
                                <div>
                                    <label className="text-xs font-medium mb-1 block text-left">Control Person Address *</label>
                                    {shouldShowField('control_person.street_line_1') && (
                                        <>
                                            <input
                                                type="text"
                                                value={kybFormData.control_person.street_line_1}
                                                onChange={(e) => {
                                                    handleControlPersonFieldChange('street_line_1', e.target.value)
                                                    if (controlPersonErrors.street_line_1) {
                                                        const newErrors = { ...controlPersonErrors }
                                                        delete newErrors.street_line_1
                                                        onControlPersonErrorsChange(newErrors)
                                                    }
                                                }}
                                                className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground mb-2 ${
                                                    controlPersonErrors.street_line_1 ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                                }`}
                                                placeholder="123 Main St"
                                            />
                                            {controlPersonErrors.street_line_1 && (
                                                <p className="text-xs text-red-500 mt-1 mb-2">{controlPersonErrors.street_line_1}</p>
                                            )}
                                        </>
                                    )}
                                    {(shouldShowField('control_person.city') || shouldShowField('control_person.state') || shouldShowField('control_person.postal_code')) && (
                                        <div className="grid grid-cols-3 gap-2">
                                            {shouldShowField('control_person.city') && (
                                                <div>
                                                    <input
                                                        type="text"
                                                        value={kybFormData.control_person.city}
                                                        onChange={(e) => {
                                                            handleControlPersonFieldChange('city', e.target.value)
                                                            if (controlPersonErrors.city) {
                                                                const newErrors = { ...controlPersonErrors }
                                                                delete newErrors.city
                                                                onControlPersonErrorsChange(newErrors)
                                                            }
                                                        }}
                                                        className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground ${
                                                            controlPersonErrors.city ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                                        }`}
                                                        placeholder="City"
                                                    />
                                                    {controlPersonErrors.city && (
                                                        <p className="text-xs text-red-500 mt-1">{controlPersonErrors.city}</p>
                                                    )}
                                                </div>
                                            )}
                                            {shouldShowField('control_person.state') && (
                                                <div>
                                                    <input
                                                        type="text"
                                                        value={kybFormData.control_person.state}
                                                        onChange={(e) => {
                                                            handleControlPersonFieldChange('state', e.target.value)
                                                            if (controlPersonErrors.state) {
                                                                const newErrors = { ...controlPersonErrors }
                                                                delete newErrors.state
                                                                onControlPersonErrorsChange(newErrors)
                                                            }
                                                        }}
                                                        className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground ${
                                                            controlPersonErrors.state ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                                        }`}
                                                        placeholder="State"
                                                    />
                                                    {controlPersonErrors.state && (
                                                        <p className="text-xs text-red-500 mt-1">{controlPersonErrors.state}</p>
                                                    )}
                                                </div>
                                            )}
                                            {shouldShowField('control_person.postal_code') && (
                                                <div>
                                                    <input
                                                        type="text"
                                                        value={kybFormData.control_person.postal_code}
                                                        onChange={(e) => {
                                                            handleControlPersonFieldChange('postal_code', e.target.value)
                                                            if (controlPersonErrors.postal_code) {
                                                                const newErrors = { ...controlPersonErrors }
                                                                delete newErrors.postal_code
                                                                onControlPersonErrorsChange(newErrors)
                                                            }
                                                        }}
                                                        className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground ${
                                                            controlPersonErrors.postal_code ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                                        }`}
                                                        placeholder="ZIP"
                                                    />
                                                    {controlPersonErrors.postal_code && (
                                                        <p className="text-xs text-red-500 mt-1">{controlPersonErrors.postal_code}</p>
                                                    )}
                                                </div>
                                            )}
                                            {shouldShowField('control_person.country') && (
                                                <div className="mt-2">
                                                    <label className="text-xs font-medium mb-1 block text-left">Country *</label>
                                                    <input
                                                        type="text"
                                                        value={kybFormData.control_person.country}
                                                        onChange={(e) => {
                                                            handleControlPersonFieldChange('country', e.target.value)
                                                            if (controlPersonErrors.country) {
                                                                const newErrors = { ...controlPersonErrors }
                                                                delete newErrors.country
                                                                onControlPersonErrorsChange(newErrors)
                                                            }
                                                        }}
                                                        className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground ${
                                                            controlPersonErrors.country ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                                        }`}
                                                        placeholder="USA"
                                                    />
                                                    {controlPersonErrors.country && (
                                                        <p className="text-xs text-red-500 mt-1">{controlPersonErrors.country}</p>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}
                            
                            {/* ID Type */}
                            {shouldShowField('control_person.id_type') && (
                                <div>
                                    <label className="text-xs font-medium mb-1 block text-left">ID Type *</label>
                                    <select
                                        value={kybFormData.control_person.id_type}
                                        onChange={(e) => {
                                            const newIdType = e.target.value
                                            onKybFormDataChange({
                                                ...kybFormData,
                                                control_person: {
                                                    ...kybFormData.control_person,
                                                    id_type: newIdType,
                                                    id_back_image: newIdType !== 'drivers_license' ? '' : kybFormData.control_person.id_back_image
                                                }
                                            })
                                            if (newIdType !== 'drivers_license' && controlPersonErrors.id_back_image) {
                                                const newErrors = { ...controlPersonErrors }
                                                delete newErrors.id_back_image
                                                onControlPersonErrorsChange(newErrors)
                                            }
                                            if (controlPersonErrors.id_type) {
                                                const newErrors = { ...controlPersonErrors }
                                                delete newErrors.id_type
                                                onControlPersonErrorsChange(newErrors)
                                            }
                                        }}
                                        className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground ${
                                            controlPersonErrors.id_type ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                        }`}
                                    >
                                        <option value="">Select ID Type...</option>
                                        <option value="drivers_license">Driver's License</option>
                                        <option value="passport">Passport</option>
                                    </select>
                                    {controlPersonErrors.id_type && (
                                        <p className="text-xs text-red-500 mt-1">{controlPersonErrors.id_type}</p>
                                    )}
                                </div>
                            )}
                            
                            {/* ID Number */}
                            {shouldShowField('control_person.id_number') && (
                                <div>
                                    <label className="text-xs font-medium mb-1 block text-left">ID Number *</label>
                                    <input
                                        type="text"
                                        value={kybFormData.control_person.id_number}
                                        onChange={(e) => {
                                            handleControlPersonFieldChange('id_number', e.target.value)
                                            if (controlPersonErrors.id_number) {
                                                const newErrors = { ...controlPersonErrors }
                                                delete newErrors.id_number
                                                onControlPersonErrorsChange(newErrors)
                                            }
                                        }}
                                        className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground ${
                                            controlPersonErrors.id_number ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                        }`}
                                        placeholder="ID Number"
                                    />
                                    {controlPersonErrors.id_number && (
                                        <p className="text-xs text-red-500 mt-1">{controlPersonErrors.id_number}</p>
                                    )}
                                </div>
                            )}
                            
                            {/* ID Images */}
                            {(shouldShowField('control_person.id_front_image') || shouldShowField('control_person.id_back_image')) && (
                                <div className={kybFormData.control_person.id_type === 'drivers_license' ? "grid grid-cols-1 sm:grid-cols-2 gap-3" : "space-y-3"}>
                                    {shouldShowField('control_person.id_front_image') && (documentStatuses.id_front === 'rejected' || !documentStatuses.id_front || documentStatuses.id_front === 'pending') && (
                                        <div>
                                            {documentStatuses.id_front === 'rejected' && (
                                                <div className="mb-2 p-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded text-xs text-red-800 dark:text-red-200">
                                                    <strong>Document Rejected:</strong> {documentRejectionReasons.id_front || 'Please re-upload the ' + (kybFormData.control_person.id_type === 'drivers_license' ? 'ID Front Image' : 'Passport Image') + '.'}
                                                </div>
                                            )}
                                            {documentStatuses.id_front === 'approved' ? (
                                                <div className="p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                                                    <div className="flex items-center gap-2">
                                                        <CheckCircle2 className="h-4 w-4 text-green-600 dark:text-green-400" />
                                                        <p className="text-xs text-green-900 dark:text-green-100">
                                                            {kybFormData.control_person.id_type === 'drivers_license' ? 'ID Front Image' : 'Passport Image'} has been approved.
                                                        </p>
                                                    </div>
                                                </div>
                                            ) : (
                                                <>
                                                    <ImageUploadDropzone
                                                        label={kybFormData.control_person.id_type === 'drivers_license' ? "ID Front Image *" : "Passport Image *"}
                                                        value={kybFormData.control_person.id_front_image}
                                                        onChange={(base64) => {
                                                            handleControlPersonFieldChange('id_front_image', base64)
                                                            if (controlPersonErrors.id_front_image) {
                                                                const newErrors = { ...controlPersonErrors }
                                                                delete newErrors.id_front_image
                                                                onControlPersonErrorsChange(newErrors)
                                                            }
                                                        }}
                                                        required={true}
                                                        maxSizeMB={5}
                                                    />
                                                    {controlPersonErrors.id_front_image && (
                                                        <p className="text-xs text-red-500 mt-1">{controlPersonErrors.id_front_image}</p>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    )}
                                    {shouldShowField('control_person.id_back_image') && kybFormData.control_person.id_type === 'drivers_license' && (documentStatuses.id_back === 'rejected' || !documentStatuses.id_back || documentStatuses.id_back === 'pending') && (
                                        <div>
                                            {documentStatuses.id_back === 'rejected' && (
                                                <div className="mb-2 p-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded text-xs text-red-800 dark:text-red-200">
                                                    <strong>Document Rejected:</strong> {documentRejectionReasons.id_back || 'Please re-upload the ID Back Image.'}
                                                </div>
                                            )}
                                            {documentStatuses.id_back === 'approved' ? (
                                                <div className="p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                                                    <div className="flex items-center gap-2">
                                                        <CheckCircle2 className="h-4 w-4 text-green-600 dark:text-green-400" />
                                                        <p className="text-xs text-green-900 dark:text-green-100">
                                                            ID Back Image has been approved.
                                                        </p>
                                                    </div>
                                                </div>
                                            ) : (
                                                <>
                                                    <ImageUploadDropzone
                                                        label="ID Back Image *"
                                                        value={kybFormData.control_person.id_back_image}
                                                        onChange={(base64) => {
                                                            handleControlPersonFieldChange('id_back_image', base64)
                                                            if (controlPersonErrors.id_back_image) {
                                                                const newErrors = { ...controlPersonErrors }
                                                                delete newErrors.id_back_image
                                                                onControlPersonErrorsChange(newErrors)
                                                            }
                                                        }}
                                                        required={true}
                                                        maxSizeMB={5}
                                                    />
                                                    {controlPersonErrors.id_back_image && (
                                                        <p className="text-xs text-red-500 mt-1">{controlPersonErrors.id_back_image}</p>
                                                    )}
                                                </>
                                            )}
                                        </div>
                                    )}
                                </div>
                            )}
                            
                            <div className="mt-4">
                                <Button
                                    onClick={onSubmitControlPerson}
                                    disabled={isLoading}
                                    className="w-full bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white"
                                    size="sm"
                                >
                                    {isLoading ? 'Submitting...' : 'Submit Control Person Information'}
                                </Button>
                            </div>
                        </div>
                    </div>
                </>
            )}

            {/* Step 2: Business Documents */}
            {kybStep === 'business_documents' && (
                <>
                    {/* Show refill message banner if admin requested re-fill */}
                    {(refillMessage || (requestedFields && requestedFields.length > 0)) && (
                        <div className="mb-3 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                            <div className="flex items-start gap-2">
                                <AlertCircle className="h-4 w-4 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" />
                                <div className="flex-1">
                                    <p className="text-xs font-semibold text-amber-900 dark:text-amber-100 mb-1">
                                        Admin Request: Please Re-fill the Following Fields
                                    </p>
                                    {refillMessage && (
                                        <p className="text-xs text-amber-800 dark:text-amber-200 mb-2">
                                            {refillMessage}
                                        </p>
                                    )}
                                    {requestedFields && requestedFields.length > 0 && (
                                        <div className="mt-2">
                                            <p className="text-xs font-medium text-amber-900 dark:text-amber-100 mb-1">
                                                Requested Fields ({requestedFields.length}):
                                            </p>
                                            <div className="flex flex-wrap gap-1">
                                                {requestedFields.map((field, idx) => (
                                                    <span key={idx} className="text-xs px-2 py-0.5 bg-amber-100 dark:bg-amber-900/40 text-amber-800 dark:text-amber-200 rounded">
                                                        {field}
                                                    </span>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}
                    
                    {/* Check if documents are submitted and pending approval - show waiting screen */}
                    {(() => {
                        const hasRejectedDocuments = documentStatuses.business_formation === 'rejected' || 
                                                    documentStatuses.business_ownership === 'rejected' || 
                                                    documentStatuses.proof_of_address === 'rejected'
                        const shouldShowWaiting = businessDocumentsSubmitted && !hasRejectedDocuments && requestedFields.length === 0
                        return shouldShowWaiting
                    })() ? (
                        /* Waiting for Approval Screen */
                        <div className="flex flex-col items-center justify-center py-8 px-4 space-y-4">
                            <motion.div
                                initial={{ scale: 0.8, opacity: 0 }}
                                animate={{ scale: 1, opacity: 1 }}
                                transition={{ duration: 0.3 }}
                                className="w-16 h-16 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center"
                            >
                                <Clock className="h-8 w-8 text-blue-600 dark:text-blue-400" />
                            </motion.div>
                            <div className="text-center space-y-2">
                                <h3 className="text-lg font-bold text-foreground">Documents Submitted</h3>
                                <p className="text-sm text-muted-foreground max-w-sm">
                                    Your business documents have been successfully submitted and are awaiting admin approval.
                                </p>
                                <p className="text-xs text-muted-foreground mt-3">
                                    Once your documents are approved, you will be able to proceed to Step 3: KYC Verification.
                                </p>
                            </div>
                            <div className="w-full max-w-sm p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                                <div className="space-y-2">
                                    <div className="flex items-center gap-2 text-xs">
                                        <FileCheck className="h-4 w-4 text-blue-600 dark:text-blue-400" />
                                        <span className="text-blue-900 dark:text-blue-100 font-medium">Status: Pending Review</span>
                                    </div>
                                    <p className="text-xs text-blue-800 dark:text-blue-200 pl-6">
                                        Our team will review your documents and notify you once the review is complete.
                                    </p>
                                </div>
                            </div>
                        </div>
                    ) : (
                        /* Show only rejected documents or initial form if nothing submitted yet */
                        <>
                            {/* Only show header if there are rejected documents or if form hasn't been submitted */}
                            {(documentStatuses.business_formation === 'rejected' || 
                              documentStatuses.business_ownership === 'rejected' || 
                              documentStatuses.proof_of_address === 'rejected' ||
                              !businessDocumentsSubmitted) && (
                                <div className="mb-3">
                                    <p className="text-xs font-semibold mb-2 text-left">Step 2: Business Documents *</p>
                                    {(documentStatuses.business_formation === 'rejected' || 
                                      documentStatuses.business_ownership === 'rejected' || 
                                      documentStatuses.proof_of_address === 'rejected') ? (
                                        <p className="text-xs text-muted-foreground mb-3 text-left">Please re-upload the rejected documents below.</p>
                                    ) : (
                                        <p className="text-xs text-muted-foreground mb-3 text-left">Upload required business formation and ownership documents</p>
                                    )}
                                </div>
                            )}
                            
                            <div className="space-y-4">
                                {/* Business Formation Document */}
                                {shouldShowField('business_formation_document') && (() => {
                                    const hasRejectedDocuments = documentStatuses.business_formation === 'rejected' || 
                                                                documentStatuses.business_ownership === 'rejected' || 
                                                                documentStatuses.proof_of_address === 'rejected'
                                    const formationStatus = documentStatuses.business_formation
                                    if (hasRejectedDocuments) {
                                        return formationStatus === 'rejected'
                                    }
                                    return !formationStatus || formationStatus === 'pending'
                                })() && (
                                    <>
                                        {documentStatuses.business_formation === 'rejected' && (
                                            <div className="mb-2 p-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded text-xs text-red-800 dark:text-red-200">
                                                <strong>Document Rejected:</strong> {documentRejectionReasons.business_formation || 'Please re-upload the Business Formation Document.'}
                                            </div>
                                        )}
                                        <DocumentUploadDropzone
                                            label="Business Formation Document"
                                            description="e.g., Articles of Incorporation, Certificate of Formation"
                                            value={kybFormData.business_formation_document}
                                            onChange={async (base64) => {
                                                handleFieldChange('business_formation_document', base64)
                                                if (businessDocumentErrors.business_formation_document) {
                                                    const newErrors = { ...businessDocumentErrors }
                                                    delete newErrors.business_formation_document
                                                    onBusinessDocumentErrorsChange(newErrors)
                                                }
                                            }}
                                            required={true}
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            maxSizeMB={10}
                                        />
                                        {businessDocumentErrors.business_formation_document && (
                                            <p className="text-xs text-red-500 -mt-2">{businessDocumentErrors.business_formation_document}</p>
                                        )}
                                    </>
                                )}
                                {documentStatuses.business_formation === 'approved' && (
                                    <div className="p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                                        <div className="flex items-center gap-2">
                                            <CheckCircle2 className="h-4 w-4 text-green-600 dark:text-green-400" />
                                            <p className="text-xs text-green-900 dark:text-green-100">
                                                Business Formation Document has been approved.
                                            </p>
                                        </div>
                                    </div>
                                )}
                                
                                {/* Business Ownership Document */}
                                {shouldShowField('business_ownership_document') && (() => {
                                    const hasRejectedDocuments = documentStatuses.business_formation === 'rejected' || 
                                                                documentStatuses.business_ownership === 'rejected' || 
                                                                documentStatuses.proof_of_address === 'rejected'
                                    const ownershipStatus = documentStatuses.business_ownership
                                    if (hasRejectedDocuments) {
                                        return ownershipStatus === 'rejected'
                                    }
                                    return !ownershipStatus || ownershipStatus === 'pending'
                                })() && (
                                    <>
                                        {documentStatuses.business_ownership === 'rejected' && (
                                            <div className="mb-2 p-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded text-xs text-red-800 dark:text-red-200">
                                                <strong>Document Rejected:</strong> {documentRejectionReasons.business_ownership || 'Please re-upload the Business Ownership Document.'}
                                            </div>
                                        )}
                                        <DocumentUploadDropzone
                                            label="Business Ownership Document"
                                            description="e.g., Cap table, Shareholder ledger, Operating Agreement"
                                            value={kybFormData.business_ownership_document}
                                            onChange={async (base64) => {
                                                handleFieldChange('business_ownership_document', base64)
                                                if (businessDocumentErrors.business_ownership_document) {
                                                    const newErrors = { ...businessDocumentErrors }
                                                    delete newErrors.business_ownership_document
                                                    onBusinessDocumentErrorsChange(newErrors)
                                                }
                                            }}
                                            required={true}
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            maxSizeMB={10}
                                        />
                                        {businessDocumentErrors.business_ownership_document && (
                                            <p className="text-xs text-red-500 -mt-2">{businessDocumentErrors.business_ownership_document}</p>
                                        )}
                                    </>
                                )}
                                {documentStatuses.business_ownership === 'approved' && (
                                    <div className="p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                                        <div className="flex items-center gap-2">
                                            <CheckCircle2 className="h-4 w-4 text-green-600 dark:text-green-400" />
                                            <p className="text-xs text-green-900 dark:text-green-100">
                                                Business Ownership Document has been approved.
                                            </p>
                                        </div>
                                    </div>
                                )}
                                
                                {/* Proof of Address Document */}
                                {shouldShowField('proof_of_address_document') && (documentStatuses.proof_of_address === 'rejected' || (!documentStatuses.proof_of_address && kybFormData.proof_of_address_document)) && (
                                    <>
                                        {documentStatuses.proof_of_address === 'rejected' && (
                                            <div className="mb-2 p-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded text-xs text-red-800 dark:text-red-200">
                                                <strong>Document Rejected:</strong> {documentRejectionReasons.proof_of_address || 'Please re-upload the Proof of Address Document.'}
                                            </div>
                                        )}
                                        <DocumentUploadDropzone
                                            label="Proof of Address Document (Optional)"
                                            description="Required if different from registered address"
                                            value={kybFormData.proof_of_address_document}
                                            onChange={async (base64) => {
                                                handleFieldChange('proof_of_address_document', base64)
                                            }}
                                            required={false}
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            maxSizeMB={10}
                                        />
                                    </>
                                )}
                                {documentStatuses.proof_of_address === 'approved' && (
                                    <div className="p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                                        <div className="flex items-center gap-2">
                                            <CheckCircle2 className="h-4 w-4 text-green-600 dark:text-green-400" />
                                            <p className="text-xs text-green-900 dark:text-green-100">
                                                Proof of Address Document has been approved.
                                            </p>
                                        </div>
                                    </div>
                                )}

                                {/* Proof of Nature of Business Document */}
                                {shouldShowField('proof_of_nature_of_business') && (documentStatuses.proof_of_nature_of_business === 'rejected' || !documentStatuses.proof_of_nature_of_business) && (
                                    <>
                                        {documentStatuses.proof_of_nature_of_business === 'rejected' && (
                                            <div className="mb-2 p-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded text-xs text-red-800 dark:text-red-200">
                                                <strong>Document Rejected:</strong> {documentRejectionReasons.proof_of_nature_of_business || 'Please re-upload the Proof of Nature of Business Document.'}
                                            </div>
                                        )}
                                        <DocumentUploadDropzone
                                            label="Proof of Nature of Business Document (Optional)"
                                            description="Alternative to website verification for Believe Cash (useful in sandbox mode)"
                                            value={kybFormData.proof_of_nature_of_business}
                                            onChange={async (base64) => {
                                                handleFieldChange('proof_of_nature_of_business', base64)
                                            }}
                                            required={false}
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            maxSizeMB={10}
                                        />
                                    </>
                                )}
                                {shouldShowField('proof_of_nature_of_business') && documentStatuses.proof_of_nature_of_business === 'approved' && (
                                    <div className="p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                                        <div className="flex items-center gap-2">
                                            <CheckCircle2 className="h-4 w-4 text-green-600 dark:text-green-400" />
                                            <p className="text-xs text-green-900 dark:text-green-100">
                                                Proof of Nature of Business Document has been approved.
                                            </p>
                                        </div>
                                    </div>
                                )}

                                {/* 501c3 Determination Letter */}
                                {shouldShowField('determination_letter_501c3') && (documentStatuses.determination_letter_501c3 === 'rejected' || !documentStatuses.determination_letter_501c3) && (
                                    <>
                                        {documentStatuses.determination_letter_501c3 === 'rejected' && (
                                            <div className="mb-2 p-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded text-xs text-red-800 dark:text-red-200">
                                                <strong>Document Rejected:</strong> {documentRejectionReasons.determination_letter_501c3 || 'Please re-upload the 501c3 Determination Letter.'}
                                            </div>
                                        )}
                                        <DocumentUploadDropzone
                                            label="501c3 Determination Letter"
                                            description="For internal use only (not sent to Believe Cash)"
                                            value={kybFormData.determination_letter_501c3}
                                            onChange={async (base64) => {
                                                handleFieldChange('determination_letter_501c3', base64)
                                            }}
                                            required={false}
                                            accept=".pdf,.jpg,.jpeg,.png"
                                            maxSizeMB={10}
                                        />
                                    </>
                                )}
                                {shouldShowField('determination_letter_501c3') && documentStatuses.determination_letter_501c3 === 'approved' && (
                                    <div className="p-3 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                                        <div className="flex items-center gap-2">
                                            <CheckCircle2 className="h-4 w-4 text-green-600 dark:text-green-400" />
                                            <p className="text-xs text-green-900 dark:text-green-100">
                                                501c3 Determination Letter has been approved.
                                            </p>
                                        </div>
                                    </div>
                                )}

                                {/* Business Information Section */}
                                {((documentStatuses.business_formation !== 'rejected' && 
                                 documentStatuses.business_ownership !== 'rejected' && 
                                 documentStatuses.proof_of_address !== 'rejected') || 
                                 (shouldShowField('entity_type') || shouldShowField('business_description') || shouldShowField('business_industry') || shouldShowField('primary_website'))) && (
                                <>
                                {/* Standard KYB Requirements */}
                                <div className="border-t border-border pt-4 mt-4">
                                    <p className="text-xs font-semibold mb-3 text-left">Business Information</p>
                                    
                                    <div className="space-y-3">
                                        {/* Entity Type */}
                                        {shouldShowField('entity_type') && (
                                            <div>
                                                <label className="text-xs font-medium mb-1 block text-left">Entity Type *</label>
                                                <select
                                                    value={kybFormData.entity_type}
                                                    onChange={(e) => {
                                                        handleFieldChange('entity_type', e.target.value)
                                                        if (businessDocumentErrors.entity_type) {
                                                            const newErrors = { ...businessDocumentErrors }
                                                            delete newErrors.entity_type
                                                            onBusinessDocumentErrorsChange(newErrors)
                                                        }
                                                    }}
                                                    className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground ${
                                                        businessDocumentErrors.entity_type ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                                    }`}
                                                >
                                                    <option value="">Select entity type...</option>
                                                    <option value="corporation">corporation</option>
                                                    <option value="llc">llc</option>
                                                    <option value="partnership">partnership</option>
                                                    <option value="sole_prop">sole_prop</option>
                                                    <option value="cooperative">cooperative</option>
                                                    <option value="trust">trust</option>
                                                    <option value="other">other</option>
                                                </select>
                                                {businessDocumentErrors.entity_type && (
                                                    <p className="text-xs text-red-500 mt-1">{businessDocumentErrors.entity_type}</p>
                                                )}
                                                <p className="text-[10px] text-muted-foreground mt-1 text-left">
                                                    Must be one of: cooperative, corporation, llc, other, partnership, sole_prop, trust.
                                                </p>
                                            </div>
                                        )}

                                        {/* DAO Status */}
                                        {shouldShowField('dao_status') && (
                                            <div className="flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    id="dao_status"
                                                    checked={kybFormData.dao_status}
                                                    onChange={(e) => handleFieldChange('dao_status', e.target.checked)}
                                                    className="w-4 h-4 text-purple-600 border-border rounded focus:ring-purple-500"
                                                />
                                                <label htmlFor="dao_status" className="text-xs font-medium text-left cursor-pointer">
                                                    This is a DAO (Decentralized Autonomous Organization)
                                                </label>
                                            </div>
                                        )}

                                        {/* Business Description */}
                                        {shouldShowField('business_description') && (
                                            <div>
                                                <label className="text-xs font-medium mb-1 block text-left">Business Description *</label>
                                                <textarea
                                                    value={kybFormData.business_description}
                                                    onChange={(e) => {
                                                        handleFieldChange('business_description', e.target.value)
                                                        if (businessDocumentErrors.business_description) {
                                                            const newErrors = { ...businessDocumentErrors }
                                                            delete newErrors.business_description
                                                            onBusinessDocumentErrorsChange(newErrors)
                                                        }
                                                    }}
                                                    className={`w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground ${
                                                        businessDocumentErrors.business_description ? 'border-red-500 dark:border-red-500' : 'border-border dark:border-gray-700'
                                                    }`}
                                                    rows={3}
                                                    placeholder="Brief description of your business operations"
                                                />
                                                {businessDocumentErrors.business_description && (
                                                    <p className="text-xs text-red-500 mt-1">{businessDocumentErrors.business_description}</p>
                                                )}
                                            </div>
                                        )}

                                        {/* Primary Website */}
                                        {shouldShowField('primary_website') && (
                                            <div>
                                                <label className="text-xs font-medium mb-1 block text-left">Primary Website</label>
                                                <input
                                                    type="url"
                                                    value={kybFormData.primary_website}
                                                    onChange={(e) => handleFieldChange('primary_website', e.target.value)}
                                                    className="w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border border-border dark:border-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground"
                                                    placeholder="https://example.com"
                                                />
                                            </div>
                                        )}
                                        
                                        {/* Business Industry */}
                                        {shouldShowField('business_industry') && (
                                            <div>
                                                <label className="text-xs font-medium mb-1 block text-left">Business Industry</label>
                                                <input
                                                    type="text"
                                                    value={kybFormData.business_industry}
                                                    onChange={(e) => handleFieldChange('business_industry', e.target.value)}
                                                    className="w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border border-border dark:border-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground"
                                                    placeholder="e.g., Technology, Healthcare, Finance"
                                                />
                                            </div>
                                        )}

                                        {/* Principal Operating Address */}
                                        {(shouldShowField('physical_address.street_line_1') || shouldShowField('physical_address.city') || shouldShowField('physical_address.subdivision') || shouldShowField('physical_address.postal_code') || shouldShowField('physical_address.country')) && (
                                            <div className="border-t border-border pt-3 mt-3">
                                                <p className="text-xs font-semibold mb-2 text-left">Principal Operating Address</p>
                                                <p className="text-xs text-muted-foreground mb-3 text-left">Required if different from registered address</p>
                                                
                                                <div className="space-y-2">
                                                    {shouldShowField('physical_address.street_line_1') && (
                                                        <div>
                                                            <label className="text-xs font-medium mb-1 block text-left">Street Address</label>
                                                            <input
                                                                type="text"
                                                                value={kybFormData.physical_address.street_line_1}
                                                                onChange={(e) => handlePhysicalAddressFieldChange('street_line_1', e.target.value)}
                                                                className="w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border border-border dark:border-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground"
                                                                placeholder="123 Main St"
                                                            />
                                                        </div>
                                                    )}
                                                    {(shouldShowField('physical_address.city') || shouldShowField('physical_address.subdivision')) && (
                                                        <div className="grid grid-cols-2 gap-2">
                                                            {shouldShowField('physical_address.city') && (
                                                                <div>
                                                                    <label className="text-xs font-medium mb-1 block text-left">City</label>
                                                                    <input
                                                                        type="text"
                                                                        value={kybFormData.physical_address.city}
                                                                        onChange={(e) => handlePhysicalAddressFieldChange('city', e.target.value)}
                                                                        className="w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border border-border dark:border-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground"
                                                                        placeholder="New York"
                                                                    />
                                                                </div>
                                                            )}
                                                            {shouldShowField('physical_address.subdivision') && (
                                                                <div>
                                                                    <label className="text-xs font-medium mb-1 block text-left">State</label>
                                                                    <input
                                                                        type="text"
                                                                        value={kybFormData.physical_address.subdivision}
                                                                        onChange={(e) => handlePhysicalAddressFieldChange('subdivision', e.target.value)}
                                                                        className="w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border border-border dark:border-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground"
                                                                        placeholder="NY"
                                                                    />
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                    {(shouldShowField('physical_address.postal_code') || shouldShowField('physical_address.country')) && (
                                                        <div className="grid grid-cols-2 gap-2">
                                                            {shouldShowField('physical_address.postal_code') && (
                                                                <div>
                                                                    <label className="text-xs font-medium mb-1 block text-left">ZIP Code</label>
                                                                    <input
                                                                        type="text"
                                                                        value={kybFormData.physical_address.postal_code}
                                                                        onChange={(e) => handlePhysicalAddressFieldChange('postal_code', e.target.value)}
                                                                        className="w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border border-border dark:border-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground"
                                                                        placeholder="10001"
                                                                    />
                                                                </div>
                                                            )}
                                                            {shouldShowField('physical_address.country') && (
                                                                <div>
                                                                    <label className="text-xs font-medium mb-1 block text-left">Country</label>
                                                                    <input
                                                                        type="text"
                                                                        value={kybFormData.physical_address.country}
                                                                        onChange={(e) => handlePhysicalAddressFieldChange('country', e.target.value)}
                                                                        className="w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border border-border dark:border-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground"
                                                                        placeholder="USA"
                                                                    />
                                                                </div>
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        )}
                                    </div>
                                </div>

                                {/* Enhanced KYB Requirements */}
                                {(shouldShowField('source_of_funds') || shouldShowField('annual_revenue') || shouldShowField('transaction_volume') || shouldShowField('account_purpose') || shouldShowField('high_risk_activities') || shouldShowField('high_risk_geographies')) && (
                                    <div className="border-t border-border pt-4 mt-4">
                                        <p className="text-xs font-semibold mb-3 text-left">Enhanced KYB Information</p>
                                        
                                        <div className="space-y-3">
                                            {/* Source of Funds */}
                                            {shouldShowField('source_of_funds') && (
                                                <div>
                                                    <label className="text-xs font-medium mb-1 block text-left">Source of Funds</label>
                                                    <select
                                                        value={kybFormData.source_of_funds}
                                                        onChange={(e) => handleFieldChange('source_of_funds', e.target.value)}
                                                        className="w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border border-border dark:border-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground"
                                                    >
                                                        <option value="">Select source of funds...</option>
                                                        <option value="business_operations">Business Operations</option>
                                                        <option value="investment_income">Investment Income</option>
                                                        <option value="loan_proceeds">Loan Proceeds</option>
                                                        <option value="sale_of_assets">Sale of Assets</option>
                                                        <option value="personal_savings">Personal Savings</option>
                                                        <option value="other">Other</option>
                                                    </select>
                                                </div>
                                            )}

                                            {/* Annual Revenue */}
                                            {shouldShowField('annual_revenue') && (
                                                <div>
                                                    <label className="text-xs font-medium mb-1 block text-left">Estimated Annual Revenue (USD)</label>
                                                    <select
                                                        value={kybFormData.annual_revenue}
                                                        onChange={(e) => handleFieldChange('annual_revenue', e.target.value)}
                                                        className="w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border border-border dark:border-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground"
                                                    >
                                                        <option value="">Select range...</option>
                                                        <option value="0-50000">$0 - $50,000</option>
                                                        <option value="50000-100000">$50,000 - $100,000</option>
                                                        <option value="100000-500000">$100,000 - $500,000</option>
                                                        <option value="500000-1000000">$500,000 - $1,000,000</option>
                                                        <option value="1000000-5000000">$1,000,000 - $5,000,000</option>
                                                        <option value="5000000+">$5,000,000+</option>
                                                    </select>
                                                </div>
                                            )}

                                            {/* Transaction Volume */}
                                            {shouldShowField('transaction_volume') && (
                                                <div>
                                                    <label className="text-xs font-medium mb-1 block text-left">Expected Monthly Transaction Volume (USD)</label>
                                                    <input
                                                        type="text"
                                                        value={kybFormData.transaction_volume}
                                                        onChange={(e) => handleFieldChange('transaction_volume', e.target.value)}
                                                        className="w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border border-border dark:border-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground placeholder:text-muted-foreground"
                                                        placeholder="e.g., 10000"
                                                    />
                                                </div>
                                            )}

                                            {/* Account Purpose */}
                                            {shouldShowField('account_purpose') && (
                                                <div>
                                                    <label className="text-xs font-medium mb-1 block text-left">Primary Account Purpose</label>
                                                    <select
                                                        value={kybFormData.account_purpose}
                                                        onChange={(e) => handleFieldChange('account_purpose', e.target.value)}
                                                        className="w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border border-border dark:border-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground"
                                                    >
                                                        <option value="">Select purpose...</option>
                                                        <option value="operating_account">Operating Account</option>
                                                        <option value="payroll">Payroll</option>
                                                        <option value="investment">Investment</option>
                                                        <option value="savings">Savings</option>
                                                        <option value="payment_processing">Payment Processing</option>
                                                        <option value="other">Other</option>
                                                    </select>
                                                </div>
                                            )}

                                            {/* High Risk Activities */}
                                            {shouldShowField('high_risk_activities') && (
                                                <div>
                                                    <label className="text-xs font-medium mb-1 block text-left">High Risk Activities (if applicable)</label>
                                                    <textarea
                                                        value={kybFormData.high_risk_activities}
                                                        onChange={(e) => handleFieldChange('high_risk_activities', e.target.value)}
                                                        className="w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border border-border dark:border-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground"
                                                        rows={2}
                                                        placeholder="Describe any high-risk activities your business engages in (if any)"
                                                    />
                                                </div>
                                            )}

                                            {/* High Risk Geographies */}
                                            {shouldShowField('high_risk_geographies') && (
                                                <div>
                                                    <label className="text-xs font-medium mb-1 block text-left">High Risk Geographies (if applicable)</label>
                                                    <textarea
                                                        value={kybFormData.high_risk_geographies}
                                                        onChange={(e) => handleFieldChange('high_risk_geographies', e.target.value)}
                                                        className="w-full px-3 py-2 text-sm bg-background dark:bg-gray-800 border border-border dark:border-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/50 focus:border-purple-500 text-foreground"
                                                        rows={2}
                                                        placeholder="List any high-risk geographies your business operates in (if any)"
                                                    />
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                )}
                                </>
                                )}
                            </div>
                            
                            <div className="mt-4">
                                <Button
                                    onClick={onSubmitBusinessDocuments}
                                    disabled={isLoading}
                                    className="w-full bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white"
                                    size="sm"
                                >
                                    {isLoading ? 'Submitting...' : 'Submit Business Documents'}
                                </Button>
                            </div>
                        </>
                    )}
                </>
            )}

            {/* Step 3: KYC Verification */}
            {kybStep === 'kyc_verification' && (
                <>
                    <div className="mb-3">
                        <p className="text-xs font-semibold mb-2 text-left">Step 3: Control Person KYC Verification *</p>
                        <p className="text-xs text-muted-foreground mb-3 text-left">
                            Your business documents have been approved. The Control Person must complete KYC verification to finalize business verification
                        </p>
                    </div>
                    
                    <div className="p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg mb-4">
                        <p className="text-xs text-blue-900 dark:text-blue-100 mb-2">
                            <strong>Control Person:</strong> {kybFormData.control_person.first_name} {kybFormData.control_person.last_name}
                        </p>
                        <p className="text-xs text-blue-900 dark:text-blue-100 mb-3">
                            <strong>Email:</strong> {kybFormData.control_person.email}
                        </p>
                        <p className="text-xs text-blue-900 dark:text-blue-100 mb-2">
                            Complete the KYC verification below. You will need to verify your ID and take a selfie.
                        </p>
                        <div className="mt-3 p-2 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded text-xs text-amber-900 dark:text-amber-100">
                            <p className="font-semibold mb-1">⚠️ Important Note:</p>
                            <p className="mb-1">Even though your information was already submitted, Believe Cash verification requires you to complete the verification flow again.</p>
                            <p>This is required for <strong>live selfie verification</strong> and additional identity checks that cannot be done via API. Please complete all steps in the verification form.</p>
                        </div>
                    </div>

                    {/* KYC Verification Iframe */}
                    {controlPersonKycIframeUrl ? (
                        <div className="space-y-3">
                            <div className="relative w-full border border-border rounded-lg overflow-hidden" style={{ minHeight: '600px' }}>
                                <iframe
                                    src={controlPersonKycIframeUrl}
                                    allow="camera; microphone;"
                                    className="w-full border-0"
                                    style={{ minHeight: '600px', height: '600px' }}
                                    sandbox="allow-same-origin allow-scripts allow-forms allow-popups allow-modals allow-top-navigation-by-user-activation"
                                    title="Control Person KYC Verification"
                                />
                            </div>
                            {controlPersonKycLink && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => window.open(controlPersonKycLink!, '_blank')}
                                    className="w-full text-xs"
                                >
                                    <ExternalLink className="h-3 w-3 mr-2" />
                                    Open in New Tab
                                </Button>
                            )}
                        </div>
                    ) : controlPersonKycLink ? (
                        <div className="space-y-3">
                            <div className="p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                                <p className="text-xs text-amber-900 dark:text-amber-100 mb-2 font-semibold">
                                    ⚠️ Iframe verification is not available
                                </p>
                                <p className="text-xs text-amber-900 dark:text-amber-100 mb-3">
                                    Please click the button below to open the verification link in a new tab.
                                </p>
                                <Button
                                    size="sm"
                                    onClick={() => window.open(controlPersonKycLink!, '_blank')}
                                    className="w-full bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 text-white text-xs"
                                >
                                    <ExternalLink className="h-3 w-3 mr-2" />
                                    Open KYC Verification Link
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="p-4 bg-muted rounded-lg border border-border">
                            <div className="mb-3 p-2 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded text-xs text-amber-900 dark:text-amber-100">
                                <p className="font-semibold mb-1">⏳ Waiting for Ultimate Beneficial Owner (UBO) verification</p>
                                <p className="mb-1">Your information has been submitted for Believe Cash. A verification link is being generated for you to complete live selfie verification.</p>
                            </div>
                            <Button
                                size="sm"
                                onClick={() => onControlPersonKyc()}
                                disabled={isLoading}
                                className="w-full bg-gradient-to-r from-blue-600 to-purple-600 hover:from-blue-700 hover:to-purple-700 text-white text-xs"
                            >
                                {isLoading ? (
                                    <>
                                        <RefreshCw className="h-3 w-3 mr-2 animate-spin" />
                                        Loading...
                                    </>
                                ) : (
                                    <>
                                        <Shield className="h-3 w-3 mr-2" />
                                        Get KYC Verification Link
                                    </>
                                )}
                            </Button>
                        </div>
                    )}
                </>
            )}
        </div>
    )
}

