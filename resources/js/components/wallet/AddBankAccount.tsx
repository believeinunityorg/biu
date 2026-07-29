import { motion, AnimatePresence } from 'framer-motion'
import { useState } from 'react'
import {
    Building2,
    Loader2,
    ArrowLeft,
    ArrowRight,
    AlertCircle,
    Landmark,
    User,
    MapPin,
    Shield,
    Check,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select'

export type BankAccountFormData = {
    routing_number: string
    account_number: string
    account_type: 'checking' | 'savings'
    account_holder_name: string
    bank_name: string
    first_name: string
    last_name: string
    street_line_1: string
    street_line_2?: string
    city: string
    state: string
    postal_code: string
    country: string
}

interface AddBankAccountProps {
    isLoading: boolean
    onLinkAccount: (accountData: BankAccountFormData) => void
    onCancel: () => void
}

const STEPS = [
    { id: 'bank', label: 'Bank', icon: Landmark },
    { id: 'holder', label: 'Holder', icon: User },
    { id: 'address', label: 'Address', icon: MapPin },
] as const

type StepId = (typeof STEPS)[number]['id']

const inputClass = (hasError: boolean) =>
    `w-full px-4 py-2.5 bg-background border ${
        hasError ? 'border-red-500' : 'border-border'
    } rounded-lg focus:outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 transition-all duration-200`

export function AddBankAccount({ isLoading, onLinkAccount, onCancel }: AddBankAccountProps) {
    const [step, setStep] = useState<StepId>('bank')
    const [formData, setFormData] = useState({
        routing_number: '',
        account_number: '',
        account_type: '' as 'checking' | 'savings' | '',
        account_holder_name: '',
        bank_name: '',
        first_name: '',
        last_name: '',
        street_line_1: '',
        street_line_2: '',
        city: '',
        state: '',
        postal_code: '',
        country: 'USA',
    })
    const [errors, setErrors] = useState<Record<string, string>>({})

    const stepIndex = STEPS.findIndex((s) => s.id === step)

    const clearFieldError = (field: string) => {
        if (errors[field]) {
            setErrors((prev) => {
                const next = { ...prev }
                delete next[field]
                return next
            })
        }
    }

    const handleInputChange = (field: string, value: string) => {
        setFormData((prev) => ({ ...prev, [field]: value }))
        clearFieldError(field)
    }

    const validateStep = (currentStep: StepId): boolean => {
        const newErrors: Record<string, string> = {}

        if (currentStep === 'bank') {
            if (!formData.routing_number.trim()) {
                newErrors.routing_number = 'Routing number is required'
            } else if (!/^\d{9}$/.test(formData.routing_number.replace(/\D/g, ''))) {
                newErrors.routing_number = 'Routing number must be 9 digits'
            }

            if (!formData.account_number.trim()) {
                newErrors.account_number = 'Account number is required'
            } else if (formData.account_number.replace(/\D/g, '').length < 4) {
                newErrors.account_number = 'Account number must be at least 4 digits'
            }

            if (!formData.account_type) {
                newErrors.account_type = 'Account type is required'
            }

            if (!formData.bank_name.trim()) {
                newErrors.bank_name = 'Bank name is required'
            }
        }

        if (currentStep === 'holder') {
            if (!formData.account_holder_name.trim()) {
                newErrors.account_holder_name = 'Account holder name is required'
            }
            if (!formData.first_name.trim()) {
                newErrors.first_name = 'First name is required'
            }
            if (!formData.last_name.trim()) {
                newErrors.last_name = 'Last name is required'
            }
        }

        if (currentStep === 'address') {
            if (!formData.street_line_1.trim()) {
                newErrors.street_line_1 = 'Street address is required'
            } else if (formData.street_line_1.trim().length < 3) {
                newErrors.street_line_1 = 'Must be at least 3 characters'
            } else if (formData.street_line_1.trim().length > 35) {
                newErrors.street_line_1 = 'Must be 35 characters or less'
            }

            if (formData.street_line_2.trim().length > 35) {
                newErrors.street_line_2 = 'Must be 35 characters or less'
            }

            if (!formData.city.trim()) {
                newErrors.city = 'City is required'
            }
            if (!formData.state.trim()) {
                newErrors.state = 'State is required'
            }
            if (!formData.postal_code.trim()) {
                newErrors.postal_code = 'Postal code is required'
            }
            if (!formData.country.trim()) {
                newErrors.country = 'Country is required'
            }
        }

        setErrors(newErrors)
        return Object.keys(newErrors).length === 0
    }

    const goNext = () => {
        if (!validateStep(step)) {
            return
        }

        if (step === 'bank') {
            setStep('holder')
        } else if (step === 'holder') {
            setStep('address')
        }
    }

    const goBack = () => {
        if (step === 'holder') {
            setStep('bank')
        } else if (step === 'address') {
            setStep('holder')
        } else {
            onCancel()
        }
    }

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault()
        if (!validateStep('address')) {
            return
        }

        onLinkAccount({
            routing_number: formData.routing_number.replace(/\D/g, ''),
            account_number: formData.account_number.replace(/\D/g, ''),
            account_type: formData.account_type as 'checking' | 'savings',
            account_holder_name: formData.account_holder_name.trim(),
            bank_name: formData.bank_name.trim(),
            first_name: formData.first_name.trim(),
            last_name: formData.last_name.trim(),
            street_line_1: formData.street_line_1.trim(),
            street_line_2: formData.street_line_2.trim() || undefined,
            city: formData.city.trim(),
            state: formData.state.trim(),
            postal_code: formData.postal_code.trim(),
            country: formData.country.trim(),
        })
    }

    const FieldError = ({ message }: { message?: string }) =>
        message ? (
            <p className="text-xs text-red-500 flex items-center gap-1">
                <AlertCircle className="h-3 w-3 shrink-0" />
                {message}
            </p>
        ) : null

    return (
        <motion.div
            initial={{ opacity: 0, x: 20 }}
            animate={{ opacity: 1, x: 0 }}
            exit={{ opacity: 0, x: -20 }}
            transition={{ duration: 0.3 }}
            className="p-4 space-y-5"
        >
            <div className="flex items-center gap-3">
                <Button
                    variant="ghost"
                    size="sm"
                    type="button"
                    onClick={goBack}
                    disabled={isLoading}
                    className="h-8 w-8 p-0 shrink-0"
                >
                    <ArrowLeft className="h-4 w-4" />
                </Button>
                <div className="flex-1 min-w-0">
                    <h3 className="text-lg font-semibold">Link Bank Account</h3>
                    <p className="text-xs text-muted-foreground truncate">
                        Step {stepIndex + 1} of {STEPS.length} · {STEPS[stepIndex].label}
                    </p>
                </div>
            </div>

            <div className="flex items-center gap-2">
                {STEPS.map((s, index) => {
                    const Icon = s.icon
                    const isActive = s.id === step
                    const isComplete = index < stepIndex

                    return (
                        <div key={s.id} className="flex items-center gap-2 flex-1 min-w-0">
                            <div
                                className={`flex items-center justify-center h-8 w-8 rounded-full border shrink-0 transition-colors ${
                                    isActive
                                        ? 'border-purple-500 bg-purple-500/10 text-purple-600'
                                        : isComplete
                                          ? 'border-purple-500 bg-gradient-to-r from-purple-600 to-blue-600 text-white'
                                          : 'border-border bg-muted text-muted-foreground'
                                }`}
                            >
                                {isComplete ? <Check className="h-4 w-4" /> : <Icon className="h-4 w-4" />}
                            </div>
                            {index < STEPS.length - 1 && (
                                <div
                                    className={`h-0.5 flex-1 rounded-full ${
                                        index < stepIndex ? 'bg-gradient-to-r from-purple-600 to-blue-600' : 'bg-border'
                                    }`}
                                />
                            )}
                        </div>
                    )
                })}
            </div>

            <form onSubmit={handleSubmit} className="space-y-4">
                <AnimatePresence mode="wait">
                    {step === 'bank' && (
                        <motion.div
                            key="bank"
                            initial={{ opacity: 0, x: 12 }}
                            animate={{ opacity: 1, x: 0 }}
                            exit={{ opacity: 0, x: -12 }}
                            className="space-y-4 rounded-xl border border-border bg-muted/30 p-4"
                        >
                            <div className="flex items-center gap-2 text-sm font-medium">
                                <Landmark className="h-4 w-4 text-purple-600" />
                                Bank account details
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="bank_name">Bank name <span className="text-red-500">*</span></Label>
                                <input
                                    id="bank_name"
                                    type="text"
                                    placeholder="Chase, Bank of America, etc."
                                    value={formData.bank_name}
                                    onChange={(e) => handleInputChange('bank_name', e.target.value)}
                                    disabled={isLoading}
                                    className={inputClass(Boolean(errors.bank_name))}
                                />
                                <FieldError message={errors.bank_name} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="routing_number">Routing number <span className="text-red-500">*</span></Label>
                                <input
                                    id="routing_number"
                                    type="text"
                                    inputMode="numeric"
                                    placeholder="123456789"
                                    value={formData.routing_number}
                                    onChange={(e) =>
                                        handleInputChange('routing_number', e.target.value.replace(/\D/g, '').slice(0, 9))
                                    }
                                    disabled={isLoading}
                                    maxLength={9}
                                    className={inputClass(Boolean(errors.routing_number))}
                                />
                                <FieldError message={errors.routing_number} />
                                <p className="text-xs text-muted-foreground">9-digit number from your check or bank app</p>
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="account_number">Account number <span className="text-red-500">*</span></Label>
                                <input
                                    id="account_number"
                                    type="text"
                                    inputMode="numeric"
                                    placeholder="Account number"
                                    value={formData.account_number}
                                    onChange={(e) =>
                                        handleInputChange('account_number', e.target.value.replace(/\D/g, ''))
                                    }
                                    disabled={isLoading}
                                    className={inputClass(Boolean(errors.account_number))}
                                />
                                <FieldError message={errors.account_number} />
                            </div>

                            <div className="space-y-2">
                                <Label>Account type <span className="text-red-500">*</span></Label>
                                <Select
                                    value={formData.account_type || undefined}
                                    onValueChange={(value) => handleInputChange('account_type', value)}
                                    disabled={isLoading}
                                >
                                    <SelectTrigger className={inputClass(Boolean(errors.account_type))}>
                                        <SelectValue placeholder="Checking or savings" />
                                    </SelectTrigger>
                                    <SelectContent className="z-[100]">
                                        <SelectItem value="checking">Checking</SelectItem>
                                        <SelectItem value="savings">Savings</SelectItem>
                                    </SelectContent>
                                </Select>
                                <FieldError message={errors.account_type} />
                            </div>
                        </motion.div>
                    )}

                    {step === 'holder' && (
                        <motion.div
                            key="holder"
                            initial={{ opacity: 0, x: 12 }}
                            animate={{ opacity: 1, x: 0 }}
                            exit={{ opacity: 0, x: -12 }}
                            className="space-y-4 rounded-xl border border-border bg-muted/30 p-4"
                        >
                            <div className="flex items-center gap-2 text-sm font-medium">
                                <User className="h-4 w-4 text-purple-600" />
                                Account holder
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="account_holder_name">
                                    Name on account <span className="text-red-500">*</span>
                                </Label>
                                <input
                                    id="account_holder_name"
                                    type="text"
                                    placeholder="As shown on your bank account"
                                    value={formData.account_holder_name}
                                    onChange={(e) => handleInputChange('account_holder_name', e.target.value)}
                                    disabled={isLoading}
                                    className={inputClass(Boolean(errors.account_holder_name))}
                                />
                                <FieldError message={errors.account_holder_name} />
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-2">
                                    <Label htmlFor="first_name">First name <span className="text-red-500">*</span></Label>
                                    <input
                                        id="first_name"
                                        type="text"
                                        value={formData.first_name}
                                        onChange={(e) => handleInputChange('first_name', e.target.value)}
                                        disabled={isLoading}
                                        className={inputClass(Boolean(errors.first_name))}
                                    />
                                    <FieldError message={errors.first_name} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="last_name">Last name <span className="text-red-500">*</span></Label>
                                    <input
                                        id="last_name"
                                        type="text"
                                        value={formData.last_name}
                                        onChange={(e) => handleInputChange('last_name', e.target.value)}
                                        disabled={isLoading}
                                        className={inputClass(Boolean(errors.last_name))}
                                    />
                                    <FieldError message={errors.last_name} />
                                </div>
                            </div>
                        </motion.div>
                    )}

                    {step === 'address' && (
                        <motion.div
                            key="address"
                            initial={{ opacity: 0, x: 12 }}
                            animate={{ opacity: 1, x: 0 }}
                            exit={{ opacity: 0, x: -12 }}
                            className="space-y-4 rounded-xl border border-border bg-muted/30 p-4"
                        >
                            <div className="flex items-center gap-2 text-sm font-medium">
                                <MapPin className="h-4 w-4 text-purple-600" />
                                Billing address
                            </div>

                            <div className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <Label htmlFor="street_line_1">
                                        Street address <span className="text-red-500">*</span>
                                    </Label>
                                    <span
                                        className={`text-xs ${
                                            formData.street_line_1.length > 35
                                                ? 'text-red-500'
                                                : 'text-muted-foreground'
                                        }`}
                                    >
                                        {formData.street_line_1.length}/35
                                    </span>
                                </div>
                                <input
                                    id="street_line_1"
                                    type="text"
                                    placeholder="123 Main St"
                                    maxLength={35}
                                    value={formData.street_line_1}
                                    onChange={(e) => handleInputChange('street_line_1', e.target.value)}
                                    disabled={isLoading}
                                    className={inputClass(Boolean(errors.street_line_1))}
                                />
                                <FieldError message={errors.street_line_1} />
                            </div>

                            <div className="space-y-2">
                                <div className="flex items-center justify-between">
                                    <Label htmlFor="street_line_2">Apt, suite, etc. (optional)</Label>
                                    <span className="text-xs text-muted-foreground">
                                        {formData.street_line_2.length}/35
                                    </span>
                                </div>
                                <input
                                    id="street_line_2"
                                    type="text"
                                    placeholder="Apt 4B"
                                    maxLength={35}
                                    value={formData.street_line_2}
                                    onChange={(e) => handleInputChange('street_line_2', e.target.value)}
                                    disabled={isLoading}
                                    className={inputClass(Boolean(errors.street_line_2))}
                                />
                                <FieldError message={errors.street_line_2} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="city">City <span className="text-red-500">*</span></Label>
                                <input
                                    id="city"
                                    type="text"
                                    value={formData.city}
                                    onChange={(e) => handleInputChange('city', e.target.value)}
                                    disabled={isLoading}
                                    className={inputClass(Boolean(errors.city))}
                                />
                                <FieldError message={errors.city} />
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div className="space-y-2">
                                    <Label htmlFor="state">State <span className="text-red-500">*</span></Label>
                                    <input
                                        id="state"
                                        type="text"
                                        placeholder="CA"
                                        maxLength={2}
                                        value={formData.state}
                                        onChange={(e) =>
                                            handleInputChange('state', e.target.value.toUpperCase().slice(0, 2))
                                        }
                                        disabled={isLoading}
                                        className={inputClass(Boolean(errors.state))}
                                    />
                                    <FieldError message={errors.state} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="postal_code">ZIP <span className="text-red-500">*</span></Label>
                                    <input
                                        id="postal_code"
                                        type="text"
                                        placeholder="94107"
                                        value={formData.postal_code}
                                        onChange={(e) => handleInputChange('postal_code', e.target.value)}
                                        disabled={isLoading}
                                        className={inputClass(Boolean(errors.postal_code))}
                                    />
                                    <FieldError message={errors.postal_code} />
                                </div>
                            </div>

                            <div className="flex items-start gap-2 rounded-lg border border-purple-500/20 bg-purple-500/5 p-3">
                                <Shield className="h-4 w-4 text-purple-600 shrink-0 mt-0.5" />
                                <p className="text-xs text-muted-foreground">
                                    Your bank details are encrypted and processed securely through Believe Cash.
                                    Street line 1 must be 3–35 characters for US bank transfers.
                                </p>
                            </div>
                        </motion.div>
                    )}
                </AnimatePresence>

                <div className="flex gap-2 pt-1">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={step === 'bank' ? onCancel : goBack}
                        disabled={isLoading}
                        className="flex-1"
                    >
                        {step === 'bank' ? 'Cancel' : 'Back'}
                    </Button>

                    {step !== 'address' ? (
                        <Button
                            type="button"
                            onClick={goNext}
                            disabled={isLoading}
                            className="flex-1 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white"
                        >
                            Continue
                            <ArrowRight className="h-4 w-4 ml-2" />
                        </Button>
                    ) : (
                        <Button
                            type="submit"
                            disabled={isLoading}
                            className="flex-1 bg-gradient-to-r from-purple-600 to-blue-600 hover:from-purple-700 hover:to-blue-700 text-white"
                        >
                            {isLoading ? (
                                <>
                                    <Loader2 className="h-4 w-4 mr-2 animate-spin" />
                                    Linking...
                                </>
                            ) : (
                                <>
                                    <Building2 className="h-4 w-4 mr-2" />
                                    Link Account
                                </>
                            )}
                        </Button>
                    )}
                </div>
            </form>
        </motion.div>
    )
}
