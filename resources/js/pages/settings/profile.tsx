"use client"

import type React from "react"
import type { SharedData } from "@/types"
import { Transition } from "@headlessui/react"
import { router, useForm, usePage } from "@inertiajs/react"
import { type FormEventHandler, useState, useRef, useCallback, useMemo, useEffect } from "react"
import SettingsLayout from "@/layouts/settings/layout"
import { Button } from "@/components/frontend/ui/button"
import { Card, CardContent, CardHeader, CardTitle } from "@/components/frontend/ui/card"
import { Input } from "@/components/frontend/ui/input"
import { Label } from "@/components/frontend/ui/label"
import { Switch } from "@/components/frontend/ui/switch"
import { Alert, AlertDescription } from "@/components/frontend/ui/alert"
import { Separator } from "@/components/frontend/ui/separator"
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from "@/components/frontend/ui/dialog"
import { Avatar, AvatarFallback, AvatarImage } from "@/components/frontend/ui/avatar"
import {
  Mail,
  Phone,
  MapPin,
  User,
  Globe,
  Calendar,
  Save,
  AlertCircle,
  CheckCircle,
  Camera,
  Upload,
  Trash2,
  RotateCcw,
  Check,
  Image as ImageIcon,
  Gift,
  X,
  HeartHandshake,
} from "lucide-react"
import InputError from "@/components/input-error"
import Cropper from "react-easy-crop"
import type { Area, Point } from "react-easy-crop/types"
import { TextArea } from "@/components/ui/textarea"
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/frontend/ui/select"
import MembershipSettingsSection, {
  type MembershipSettingsPayload,
} from "@/components/membership/MembershipSettingsSection"
// import { TextArea } from "@/components/ui/textarea"

type ProfileForm = {
  name: string
  email: string
  dob: string
  contact_title: string
  website?: string
  wefunder_project_url?: string
  phone?: string
  description?: string
  mission?: string
  gift_card_terms_approved?: boolean
  primary_action_category_ids: number[]
  alliance_ein?: string
  alliance_name?: string
  alliance_city?: string
  alliance_state?: string
}

type CareAlliancePayload = {
  name: string
  description: string | null
  website: string | null
  city: string | null
  state: string | null
  ein: string | null
} | null

type ProfileSettingsVariant = "standard" | "organization" | "alliance"

export default function ProfileEdit({
  mustVerifyEmail,
  status,
  primaryActionCategories = [],
  organizationPrimaryActionCategoryIds = [],
  careAlliance = null,
  profileSettingsVariant = "standard",
  membership = null,
}: {
  mustVerifyEmail: boolean
  status?: string
  primaryActionCategories?: { id: number; name: string }[]
  organizationPrimaryActionCategoryIds?: number[]
  careAlliance?: CareAlliancePayload
  profileSettingsVariant?: ProfileSettingsVariant
  membership?: MembershipSettingsPayload | null
}) {
  const page = usePage<SharedData & { success?: string }>()
  const { auth } = page.props
  const isCareAllianceHub = profileSettingsVariant === "alliance"
  const showOrgProfileCard =
    profileSettingsVariant === "alliance" || profileSettingsVariant === "organization"

  useEffect(() => {
    const hash = window.location.hash.replace(/^#/, "")
    if (!hash) return
    window.setTimeout(() => {
      document.getElementById(hash)?.scrollIntoView({ behavior: "smooth", block: "start" })
    }, 100)
  }, [])

  // Profile photo upload states
  const [isPhotoDialogOpen, setIsPhotoDialogOpen] = useState(false)
  const [selectedFile, setSelectedFile] = useState<File | null>(null)
  const [previewUrl, setPreviewUrl] = useState<string>("")
  const [crop, setCrop] = useState<Point>({ x: 0, y: 0 })
  const [zoom, setZoom] = useState(1)
  const [croppedAreaPixels, setCroppedAreaPixels] = useState<Area | null>(null)
  const [isUploading, setIsUploading] = useState(false)
  const [photoError, setPhotoError] = useState<string>("")
  const [photoSuccess, setPhotoSuccess] = useState<string>("")
  const isOrganizationProfile = profileSettingsVariant === "organization"
  const orgLogoUrl =
    (auth.user as { organization?: { registered_user_image?: string | null } })?.organization
      ?.registered_user_image ?? null
  const [currentPhotoUrl, setCurrentPhotoUrl] = useState(
    isOrganizationProfile && orgLogoUrl ? orgLogoUrl : auth.user.image,
  )
  const fileInputRef = useRef<HTMLInputElement>(null)

  // Cover photo upload states
  const [isCoverDialogOpen, setIsCoverDialogOpen] = useState(false)
  const [selectedCoverFile, setSelectedCoverFile] = useState<File | null>(null)
  const [coverPreviewUrl, setCoverPreviewUrl] = useState<string>("")
  const [coverCrop, setCoverCrop] = useState<Point>({ x: 0, y: 0 })
  const [coverZoom, setCoverZoom] = useState(1)
  const [croppedCoverAreaPixels, setCroppedCoverAreaPixels] = useState<Area | null>(null)
  const [isCoverUploading, setIsCoverUploading] = useState(false)
  const [coverError, setCoverError] = useState<string>("")
  const [coverSuccess, setCoverSuccess] = useState<string>("")
  const [currentCoverUrl, setCurrentCoverUrl] = useState(auth.user.cover_img)
  const coverFileInputRef = useRef<HTMLInputElement>(null)

  const { data, setData, patch, errors, processing, recentlySuccessful } = useForm<ProfileForm>({
    name: auth.user.name || "",
    email: auth.user.email || "",
    phone: auth.user.phone || "",
    dob: auth.user.dob || "",
    contact_title: auth.user?.organization?.contact_title || "",
    website: isCareAllianceHub
      ? careAlliance?.website || ""
      : auth.user?.organization?.website || "",
    wefunder_project_url: auth.user?.organization?.wefunder_project_url || "",
    description: isCareAllianceHub
      ? careAlliance?.description || ""
      : auth.user?.organization?.description || "",
    mission: auth.user?.organization?.mission || "",
    gift_card_terms_approved: auth.user?.organization?.gift_card_terms_approved || false,
    primary_action_category_ids: organizationPrimaryActionCategoryIds ?? [],
    alliance_ein: careAlliance?.ein ?? "",
    alliance_name: careAlliance?.name ?? "",
    alliance_city: careAlliance?.city ?? "",
    alliance_state: careAlliance?.state ?? "",
  })

  const primaryCategoryIdsKey = (organizationPrimaryActionCategoryIds ?? []).join(",")

  useEffect(() => {
    if (profileSettingsVariant !== "alliance") return
    setData("alliance_name", careAlliance?.name ?? "")
    setData("alliance_city", careAlliance?.city ?? "")
    setData("alliance_state", careAlliance?.state ?? "")
    setData("alliance_ein", careAlliance?.ein ?? "")
    setData("website", careAlliance?.website ?? "")
    setData("description", careAlliance?.description ?? "")
    setData("primary_action_category_ids", organizationPrimaryActionCategoryIds ?? [])
  }, [
    profileSettingsVariant,
    careAlliance?.name,
    careAlliance?.description,
    careAlliance?.website,
    careAlliance?.city,
    careAlliance?.state,
    careAlliance?.ein,
    primaryCategoryIdsKey,
  ])

  const selectedPrimaryCategories = useMemo(
    () =>
      primaryActionCategories.filter((c) => data.primary_action_category_ids.includes(c.id)),
    [primaryActionCategories, data.primary_action_category_ids],
  )

  const remainingPrimaryCategories = useMemo(
    () =>
      primaryActionCategories.filter((c) => !data.primary_action_category_ids.includes(c.id)),
    [primaryActionCategories, data.primary_action_category_ids],
  )

  const addPrimaryCategoryTag = (id: number) => {
    if (data.primary_action_category_ids.includes(id)) return
    setData("primary_action_category_ids", [...data.primary_action_category_ids, id])
  }

  const removePrimaryCategoryTag = (id: number) => {
    setData(
      "primary_action_category_ids",
      data.primary_action_category_ids.filter((x) => x !== id),
    )
  }

  const submit: FormEventHandler = (e) => {
    e.preventDefault()
    patch(route("profile.update"), {
      preserveScroll: true,
      onSuccess: () => {
        //
      },
    })
  }

  // Photo upload functions
  const onCropComplete = useCallback((croppedArea: Area, croppedAreaPixels: Area) => {
    setCroppedAreaPixels(croppedAreaPixels)
  }, [])

  const onCoverCropComplete = useCallback((croppedArea: Area, croppedAreaPixels: Area) => {
    setCroppedCoverAreaPixels(croppedAreaPixels)
  }, [])

  const handleFileSelect = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    if (!file) return

    // Validate file type
    if (!file.type.startsWith("image/")) {
      setPhotoError("Please select a valid image file.")
      return
    }

    // Validate file size (5MB max)
    if (file.size > 5 * 1024 * 1024) {
      setPhotoError("Image size must be less than 5MB.")
      return
    }

    setSelectedFile(file)
    setPreviewUrl(URL.createObjectURL(file))
    setPhotoError("")
    setCrop({ x: 0, y: 0 })
    setZoom(1)
  }

  const handleCoverFileSelect = (event: React.ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    if (!file) return

    // Validate file type
    if (!file.type.startsWith("image/")) {
      setCoverError("Please select a valid image file.")
      return
    }

    // Validate file size (5MB max)
    if (file.size > 5 * 1024 * 1024) {
      setCoverError("Image size must be less than 5MB.")
      return
    }

    setSelectedCoverFile(file)
    setCoverPreviewUrl(URL.createObjectURL(file))
    setCoverError("")
    setCoverCrop({ x: 0, y: 0 })
    setCoverZoom(1)
  }

    // This helper turns crop data into a cropped image preview URL
const getCroppedImage = async (
    file: File,
    crop: { x: number; y: number; width: number; height: number }
  ): Promise<string> => {
    return new Promise((resolve, reject) => {
      const img = new Image()
      img.src = URL.createObjectURL(file)

      img.onload = () => {
        const canvas = document.createElement("canvas")
        canvas.width = crop.width
        canvas.height = crop.height
        const ctx = canvas.getContext("2d")
        if (!ctx) return reject("No canvas context")

        ctx.drawImage(img, crop.x, crop.y, crop.width, crop.height, 0, 0, crop.width, crop.height)
        canvas.toBlob((blob) => {
          if (!blob) return reject("Canvas blob is null")
          resolve(URL.createObjectURL(blob))
        }, "image/jpeg")
      }

      img.onerror = () => reject("Failed to load image")
    })
  }

  const handlePhotoUpload = async () => {
    if (!selectedFile || !croppedAreaPixels) return

    setIsUploading(true)
    setPhotoError("")

    try {
      const formData = new FormData()
      formData.append("photo", selectedFile)
      formData.append("x", croppedAreaPixels.x.toString())
      formData.append("y", croppedAreaPixels.y.toString())
      formData.append("width", croppedAreaPixels.width.toString())
      formData.append("height", croppedAreaPixels.height.toString())

      router.post(route('profile.photo.store'), formData, {
          onSuccess: async () => {
              setPhotoSuccess("Profile photo updated successfully!")
              const previewUrl = await getCroppedImage(selectedFile, croppedAreaPixels)
                setCurrentPhotoUrl(previewUrl)
          setTimeout(() => {
            setIsPhotoDialogOpen(false)
            resetPhotoState()
            router.reload()
          }, 1500)
        },
        onError: (errors) => {
          setPhotoError(errors.photo || "Failed to upload photo")
        },
        preserveScroll: true,
      })
    } catch (error) {
      setPhotoError("Network error. Please try again.")
    } finally {
      setIsUploading(false)
    }
  }

  const handleCoverUpload = async () => {
    if (!selectedCoverFile || !croppedCoverAreaPixels) return

    setIsCoverUploading(true)
    setCoverError("")

    try {
      const formData = new FormData()
      formData.append("cover", selectedCoverFile)
      formData.append("x", croppedCoverAreaPixels.x.toString())
      formData.append("y", croppedCoverAreaPixels.y.toString())
      formData.append("width", croppedCoverAreaPixels.width.toString())
      formData.append("height", croppedCoverAreaPixels.height.toString())

      router.post(route('profile.cover'), formData, {
        onSuccess: async () => {
              setCoverSuccess("Cover photo updated successfully!")
              const previewUrl = await getCroppedImage(selectedCoverFile, croppedCoverAreaPixels)
              setCurrentCoverUrl(previewUrl)
          setTimeout(() => {
            setIsCoverDialogOpen(false)
            resetCoverState()
            router.reload()
          }, 1500)
        },
        onError: (errors) => {
          setCoverError(errors.cover || "Failed to upload cover photo")
        },
        preserveScroll: true,
      })
    } catch (error) {
      setCoverError("Network error. Please try again.")
    } finally {
      setIsCoverUploading(false)
    }
  }

  const handlePhotoDelete = async () => {
    setIsUploading(true)
    setPhotoError("")

    try {
      router.delete(route('profile.photo.destroy'), {
          onSuccess: (resp) => {
              console.log(resp);
              setPhotoSuccess("Profile photo removed successfully!")
              setCurrentPhotoUrl("");
          setTimeout(() => {
            setIsPhotoDialogOpen(false)
              resetPhotoState()
            router.reload()
          }, 1500)
        },
        onError: () => {
          setPhotoError("Failed to delete photo")
        },
        preserveScroll: true,
      })
    } catch (error) {
      setPhotoError("Network error. Please try again.")
    } finally {
      setIsUploading(false)
    }
  }

  const handleCoverDelete = async () => {
    setIsCoverUploading(true)
    setCoverError("")

    try {
      router.delete(route('profile.cover'), {
        onSuccess: () => {
          setCoverSuccess("Cover photo removed successfully!")
          setTimeout(() => {
            setIsCoverDialogOpen(false)
            resetCoverState()
            router.reload()
          }, 1500)
        },
        onError: () => {
          setCoverError("Failed to delete cover photo")
        },
        preserveScroll: true,
      })
    } catch (error) {
      setCoverError("Network error. Please try again.")
    } finally {
      setIsCoverUploading(false)
    }
  }

  const resetPhotoState = () => {
    setSelectedFile(null)
    setPreviewUrl("")
    setPhotoError("")
    setPhotoSuccess("")
    setCrop({ x: 0, y: 0 })
    setZoom(1)
    setCroppedAreaPixels(null)
    if (fileInputRef.current) {
      fileInputRef.current.value = ""
    }
  }

  const resetCoverState = () => {
    setSelectedCoverFile(null)
    setCoverPreviewUrl("")
    setCoverError("")
    setCoverSuccess("")
    setCoverCrop({ x: 0, y: 0 })
    setCoverZoom(1)
    setCroppedCoverAreaPixels(null)
    if (coverFileInputRef.current) {
      coverFileInputRef.current.value = ""
    }
  }

  const triggerFileInput = () => {
    fileInputRef.current?.click()
  }

  const triggerCoverFileInput = () => {
    coverFileInputRef.current?.click()
  }

  return (
    <SettingsLayout activeTab="profile" settingsBranding={isCareAllianceHub ? "alliance" : "default"}>
      <div className="space-y-6">
        {/* Success Message */}
        <Transition
          show={recentlySuccessful}
          enter="transition ease-in-out"
          enterFrom="opacity-0"
          leave="transition ease-in-out"
          leaveTo="opacity-0"
        >
          <Alert className="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950">
            <CheckCircle className="h-4 w-4 text-green-600" />
            <AlertDescription className="text-green-700 dark:text-green-400">
              {isCareAllianceHub ? "Alliance Settings saved successfully!" : "Profile updated successfully!"}
            </AlertDescription>
          </Alert>
        </Transition>

        {typeof page.props.success === "string" && page.props.success.trim() !== "" && (
          <Alert className="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950">
            <CheckCircle className="h-4 w-4 text-green-600" />
            <AlertDescription className="text-green-700 dark:text-green-400">{page.props.success}</AlertDescription>
          </Alert>
        )}

        {/* Email Verification Alert */}
        {mustVerifyEmail && auth.user.email_verified_at === null && (
          <Alert className="border-yellow-200 bg-yellow-50 dark:border-yellow-800 dark:bg-yellow-950">
            <AlertCircle className="h-4 w-4 text-yellow-600" />
            <AlertDescription className="text-yellow-700 dark:text-yellow-400">
              Your email address is unverified. Please check your inbox for a verification link.
            </AlertDescription>
          </Alert>
        )}

              <form onSubmit={submit} className="space-y-6">

        {showOrgProfileCard && !isCareAllianceHub && (

          <Card className="bg-white dark:bg-transparent border-gray-200 dark:border-gray-800 shadow-sm">
            <CardHeader className="pb-4">
              <CardTitle className="text-gray-900 dark:text-white flex items-center gap-2">
                <ImageIcon className="h-5 w-5 text-blue-500" />
                Cover Photo
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="flex flex-col sm:flex-row items-center gap-6">
                {/* Current Cover Preview */}
                <div className="relative w-full h-40 rounded-lg overflow-hidden bg-gray-100 dark:bg-tra">
                  {currentCoverUrl ? (
                    <img
                      src={currentCoverUrl}
                      alt="Cover"
                      className="w-full h-full object-cover"
                    />
                  ) : (
                    <div className="w-full h-full flex items-center justify-center text-gray-400">
                      No cover photo uploaded
                    </div>
                  )}
                </div>

                {/* Upload Controls */}
                <div className="flex-1 text-center sm:text-left">
                  <h3 className="text-lg font-medium text-gray-900 dark:text-white mb-2">Update your cover photo</h3>
                  <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    Upload a new cover photo. Recommended size: 1500x500px.
                  </p>

                  <div className="flex gap-3">
                    <Dialog open={isCoverDialogOpen} onOpenChange={setIsCoverDialogOpen}>
                      <DialogTrigger asChild>
                        <Button variant="outline" className="flex items-center gap-2 bg-gray-500 dark:bg-gray-900">
                          <Upload className="h-4 w-4" />
                          Upload New Cover
                        </Button>
                      </DialogTrigger>

                      <DialogContent className="sm:max-w-2xl">
                        <DialogHeader>
                          <DialogTitle>Update Cover Photo</DialogTitle>
                        </DialogHeader>

                        <div className="space-y-4">
                          {/* Error/Success Messages */}
                          {coverError && (
                            <Alert className="border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950">
                              <AlertDescription className="text-red-700 dark:text-red-400">
                                {coverError}
                              </AlertDescription>
                            </Alert>
                          )}

                          {coverSuccess && (
                            <Alert className="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950">
                              <Check className="h-4 w-4 text-green-600" />
                              <AlertDescription className="text-green-700 dark:text-green-400">
                                {coverSuccess}
                              </AlertDescription>
                            </Alert>
                          )}

                          {/* File Input */}
                          <input
                            ref={coverFileInputRef}
                            type="file"
                            accept="image/*"
                            onChange={handleCoverFileSelect}
                            className="hidden"
                          />

                          {/* Image Cropper */}
                          {selectedCoverFile && coverPreviewUrl ? (
                            <div className="space-y-4">
                              <div className="relative w-full h-64 bg-gray-100 dark:bg-tra rounded-lg overflow-hidden">
                                <Cropper
                                  image={coverPreviewUrl}
                                  crop={coverCrop}
                                  zoom={coverZoom}
                                  aspect={3}
                                  onCropChange={setCoverCrop}
                                  onCropComplete={onCoverCropComplete}
                                  onZoomChange={setCoverZoom}
                                  cropShape="rect"
                                  showGrid={false}
                                />
                              </div>

                              {/* Zoom Control */}
                              <div className="space-y-2">
                                <label className="text-sm font-medium">Zoom</label>
                                <input
                                  type="range"
                                  value={coverZoom}
                                  min={1}
                                  max={3}
                                  step={0.1}
                                  onChange={(e) => setCoverZoom(Number(e.target.value))}
                                  className="w-full"
                                />
                              </div>

                              {/* Action Buttons */}
                              <div className="flex gap-2">
                                <Button onClick={handleCoverUpload} disabled={isCoverUploading} className="flex-1">
                                  {isCoverUploading ? (
                                    <>
                                      <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                                      Uploading...
                                    </>
                                  ) : (
                                    <>
                                      <Upload className="h-4 w-4 mr-2" />
                                      Upload Cover
                                    </>
                                  )}
                                </Button>
                                <Button onClick={resetCoverState} variant="outline" disabled={isCoverUploading}>
                                  <RotateCcw className="h-4 w-4" />
                                </Button>
                              </div>
                            </div>
                          ) : (
                            /* Upload Options */
                            <div className="space-y-4">
                              <Button
                                onClick={triggerCoverFileInput}
                                variant="outline"
                                className="w-full h-32 border-2 border-dashed border-gray-300 dark:border-gray-600 hover:border-gray-400 dark:hover:border-gray-500 bg-transparent"
                                disabled={isCoverUploading}
                              >
                                <div className="text-center">
                                  <Upload className="h-8 w-8 mx-auto mb-2 text-gray-400" />
                                  <p className="text-sm text-gray-600 dark:text-gray-400">
                                    Click to upload a new cover photo
                                  </p>
                                  <p className="text-xs text-gray-500 dark:text-gray-500 mt-1">JPG, PNG up to 5MB</p>
                                </div>
                              </Button>

                              {/* Delete Option */}
                              {auth.user.cover_image && (
                                <Button
                                  onClick={handleCoverDelete}
                                  variant="destructive"
                                  className="w-full"
                                  disabled={isCoverUploading}
                                >
                                  {isCoverUploading ? (
                                    <>
                                      <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                                      Deleting...
                                    </>
                                  ) : (
                                    <>
                                      <Trash2 className="h-4 w-4 mr-2" />
                                      Remove Current Cover
                                    </>
                                  )}
                                </Button>
                              )}
                            </div>
                          )}
                        </div>
                      </DialogContent>
                    </Dialog>

                    {auth.user.cover_image && (
                      <Button onClick={handleCoverDelete} variant="destructive" size="sm" disabled={isCoverUploading}>
                        <Trash2 className="h-4 w-4 mr-2" />
                        Remove
                      </Button>
                    )}
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>
        )}
          {/* Profile / organization logo */}
          <Card
            id="readiness-profile-information"
            className="scroll-mt-24 bg-white dark:bg-transparent border-gray-200 dark:border-gray-800 shadow-sm"
          >
            <CardHeader className="pb-4">
              <CardTitle className="text-gray-900 dark:text-white flex items-center gap-2">
                <Camera className="h-5 w-5 text-purple-500" />
                {isOrganizationProfile ? "Organization logo" : "Profile Photo"}
              </CardTitle>
            </CardHeader>
            <CardContent>
              {isOrganizationProfile && !currentPhotoUrl ? (
                <Alert className="mb-4 border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/40">
                  <AlertCircle className="h-4 w-4 text-amber-600" />
                  <AlertDescription className="text-amber-800 dark:text-amber-200">
                    Upload your organization logo so it appears on Unity Live overlays and your public profile.
                    You can also customize CTAs in{" "}
                    <a href="/livestreams/overlay-studio" className="font-medium underline">
                      Overlay Studio
                    </a>
                    .
                  </AlertDescription>
                </Alert>
              ) : null}
              <div className="flex flex-col sm:flex-row items-center gap-6">
                {/* Current Avatar */}
                <div className="relative">
                  <Avatar className="w-24 h-24 border-4 border-gray-200 dark:border-gray-600 shadow-lg">
                    <AvatarImage
                      src={currentPhotoUrl || "/placeholder.svg"}
                      alt={isOrganizationProfile ? "Organization logo" : "Profile"}
                    />
                    <AvatarFallback className="bg-gradient-to-r from-blue-500 to-purple-500 text-white text-2xl font-semibold">
                      {auth.user.name?.charAt(0) || "U"}
                    </AvatarFallback>
                  </Avatar>
                </div>

                {/* Upload Controls */}
                <div className="flex-1 text-center sm:text-left">
                  <h3 className="text-lg font-medium text-gray-900 dark:text-white mb-2">
                    {isOrganizationProfile ? "Update your organization logo" : "Update your profile photo"}
                  </h3>
                  <p className="text-sm text-gray-600 dark:text-gray-400 mb-4">
                    {isOrganizationProfile
                      ? "This logo is shown on Unity Live stream overlays, your nonprofit profile, and donation pages. Recommended: square PNG or JPG, at least 400×400px."
                      : "Upload a new photo or remove your current one. Recommended size: 400x400px."}
                  </p>

                  <div className="flex flex-col sm:flex-row gap-3">
                    <Dialog open={isPhotoDialogOpen} onOpenChange={setIsPhotoDialogOpen}>
                      <DialogTrigger asChild>
                        <Button variant="outline" className="flex items-center gap-2 bg-transparent">
                          <Upload className="h-4 w-4" />
                          {isOrganizationProfile ? "Upload logo" : "Upload New Photo"}
                        </Button>
                      </DialogTrigger>

                      <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                          <DialogTitle>
                            {isOrganizationProfile ? "Update organization logo" : "Update Profile Photo"}
                          </DialogTitle>
                        </DialogHeader>

                        <div className="space-y-4">
                          {/* Error/Success Messages */}
                          {photoError && (
                            <Alert className="border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950">
                              <AlertDescription className="text-red-700 dark:text-red-400">
                                {photoError}
                              </AlertDescription>
                            </Alert>
                          )}

                          {photoSuccess && (
                            <Alert className="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950">
                              <Check className="h-4 w-4 text-green-600" />
                              <AlertDescription className="text-green-700 dark:text-green-400">
                                {photoSuccess}
                              </AlertDescription>
                            </Alert>
                          )}

                          {/* File Input */}
                          <Input
                            ref={fileInputRef}
                            type="file"
                            accept="image/*"
                            onChange={handleFileSelect}
                            className="hidden"
                          />

                          {/* Image Cropper */}
                          {selectedFile && previewUrl ? (
                            <div className="space-y-4">
                              <div className="relative w-full h-64 bg-gray-100 dark:bg-tra rounded-lg overflow-hidden">
                                <Cropper
                                  image={previewUrl}
                                  crop={crop}
                                  zoom={zoom}
                                  aspect={1}
                                  onCropChange={setCrop}
                                  onCropComplete={onCropComplete}
                                  onZoomChange={setZoom}
                                  cropShape="round"
                                  showGrid={false}
                                />
                              </div>

                              {/* Zoom Control */}
                              <div className="space-y-2">
                                <label className="text-sm font-medium">Zoom</label>
                                <input
                                  type="range"
                                  value={zoom}
                                  min={1}
                                  max={3}
                                  step={0.1}
                                  onChange={(e) => setZoom(Number(e.target.value))}
                                  className="w-full"
                                />
                              </div>

                              {/* Action Buttons */}
                              <div className="flex gap-2">
                                <Button onClick={handlePhotoUpload} disabled={isUploading} className="flex-1">
                                  {isUploading ? (
                                    <>
                                      <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                                      Uploading...
                                    </>
                                  ) : (
                                    <>
                                      <Upload className="h-4 w-4 mr-2" />
                                      Upload Photo
                                    </>
                                  )}
                                </Button>
                                <Button onClick={resetPhotoState} variant="outline" disabled={isUploading}>
                                  <RotateCcw className="h-4 w-4" />
                                </Button>
                              </div>
                            </div>
                          ) : (
                            /* Upload Options */
                            <div className="space-y-4">
                              <Button
                                onClick={triggerFileInput}
                                variant="outline"
                                className="w-full h-32 border-2 border-dashed border-gray-300 dark:border-gray-600 hover:border-gray-400 dark:hover:border-gray-500 bg-transparent"
                                disabled={isUploading}
                              >
                                <div className="text-center">
                                  <Upload className="h-8 w-8 mx-auto mb-2 text-gray-400" />
                                  <p className="text-sm text-gray-600 dark:text-gray-400">
                                    Click to upload a new photo
                                  </p>
                                  <p className="text-xs text-gray-500 dark:text-gray-500 mt-1">JPG, PNG up to 5MB</p>
                                </div>
                              </Button>

                              {/* Delete Option */}
                              {auth.user.image && (
                                <Button
                                  onClick={handlePhotoDelete}
                                  variant="destructive"
                                  className="w-full"
                                  disabled={isUploading}
                                >
                                  {isUploading ? (
                                    <>
                                      <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-white mr-2"></div>
                                      Deleting...
                                    </>
                                  ) : (
                                    <>
                                      <Trash2 className="h-4 w-4 mr-2" />
                                      Remove Current Photo
                                    </>
                                  )}
                                </Button>
                              )}
                            </div>
                          )}
                        </div>
                      </DialogContent>
                    </Dialog>

                    {/* {auth.user.image && (
                      <Button onClick={handlePhotoDelete} variant="destructive" size="sm" disabled={isUploading}>
                        <Trash2 className="h-4 w-4 mr-2" />
                        Remove
                      </Button>
                    )} */}
                  </div>
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Personal Information */}
          <Card className="bg-white dark:bg-transparent border-gray-200 dark:border-gray-800 shadow-sm">
            <CardHeader className="pb-4">
              <CardTitle className="text-gray-900 dark:text-white flex items-center gap-2">
                <User className="h-5 w-5 text-blue-500" />
                Personal Information
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
              {/* Name */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="sm:col-span-2">
                  <Label htmlFor="name" className="text-gray-900 dark:text-white font-medium">
                    Full Name *
                  </Label>
                  <Input
                    id="name"
                    type="text"
                    value={data.name}
                    onChange={(e) => setData("name", e.target.value)}
                    className="mt-1 bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="Enter your full name"
                    required
                  />
                  <InputError message={errors.name} className="mt-1" />
                </div>
                          </div>

                          {showOrgProfileCard && !isCareAllianceHub && (
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="sm:col-span-2">
                  <Label htmlFor="name" className="text-gray-900 dark:text-white font-medium">
                    Contact Title *
                  </Label>
                  <Input
                    id="contact_title"
                    type="text"
                    value={data.contact_title}
                    onChange={(e) => setData("contact_title", e.target.value)}
                    className="mt-1 bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="Excutive Director, Ceo, Manager, etc.."
                    required={profileSettingsVariant === "organization"}
                  />
                  <InputError message={errors.contact_title} className="mt-1" />
                </div>
              </div>
                        )}

              {/* Email */}
              <div>
                <Label htmlFor="email" className="text-gray-900 dark:text-white font-medium">
                  Email Address *
                </Label>
                <div className="relative mt-1">
                  <Mail className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-gray-400" />
                  <Input
                    id="email"
                    type="email"
                    value={data.email}
                    onChange={(e) => setData("email", e.target.value)}
                    className="pl-10 bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="your.email@example.com"
                    required
                  />
                </div>
                <InputError message={errors.email} className="mt-1" />
                          </div>


              {/* Phone */}
              <div>
                <Label htmlFor="phone" className="text-gray-900 dark:text-white font-medium">
                  Phone Number
                </Label>
                <div className="relative mt-1">
                  <Phone className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-gray-400" />
                  <Input
                    id="phone"
                    type="tel"
                    value={data.phone}
                    onChange={(e) => setData("phone", e.target.value)}
                    className="pl-10 bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="+1 (555) 123-4567"
                  />
                </div>
                <InputError message={errors.phone} className="mt-1" />
                          </div>


               {/* Date of Birth */}
                <div>
                <Label htmlFor="dob" className="text-gray-900 dark:text-white font-medium">
                    Date of Birth
                </Label>
                <div className="relative mt-1">
                    <Calendar className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-gray-400" />
                    <Input
                    id="dob"
                    type="date"
                    value={data.dob}
                    onChange={(e) => setData("dob", e.target.value)}
                    className="pl-10 bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500"
                    max={new Date().toISOString().split('T')[0]} // Prevent future dates
                    />
                </div>
                <InputError message={errors.dob} className="mt-1" />
                </div>
            </CardContent>
                  </Card>

                  {showOrgProfileCard && (
          <Card className="bg-white dark:bg-transparent border-gray-200 dark:border-gray-800 shadow-sm">
            <CardHeader className="pb-4">
              <CardTitle className="text-gray-900 dark:text-white flex items-center gap-2">
                {isCareAllianceHub ? (
                  <HeartHandshake className="h-5 w-5 text-rose-500" />
                ) : (
                  <User className="h-5 w-5 text-blue-500" />
                )}
                {isCareAllianceHub ? "Unity Impact Alliance profile" : "Additional Information"}
              </CardTitle>
            </CardHeader>
            <CardContent className="space-y-6">
              {isCareAllianceHub && (
                <>
                  <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div className="sm:col-span-2">
                      <Label htmlFor="alliance_name" className="text-gray-900 dark:text-white font-medium">
                        Alliance name *
                      </Label>
                      <Input
                        id="alliance_name"
                        type="text"
                        value={data.alliance_name ?? ""}
                        onChange={(e) => setData("alliance_name", e.target.value)}
                        className="mt-1 bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500"
                        placeholder="Your Unity Impact Alliance name"
                        required
                      />
                      <InputError message={errors.alliance_name} className="mt-1" />
                    </div>
                    <div>
                      <Label htmlFor="alliance_city" className="text-gray-900 dark:text-white font-medium">
                        City
                      </Label>
                      <div className="relative mt-1">
                        <MapPin className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-gray-400" />
                        <Input
                          id="alliance_city"
                          type="text"
                          value={data.alliance_city ?? ""}
                          onChange={(e) => setData("alliance_city", e.target.value)}
                          className="pl-10 bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500"
                          placeholder="City"
                        />
                      </div>
                      <InputError message={errors.alliance_city} className="mt-1" />
                    </div>
                    <div>
                      <Label htmlFor="alliance_state" className="text-gray-900 dark:text-white font-medium">
                        State / region
                      </Label>
                      <Input
                        id="alliance_state"
                        type="text"
                        value={data.alliance_state ?? ""}
                        onChange={(e) => setData("alliance_state", e.target.value)}
                        className="mt-1 bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500"
                        placeholder="ST"
                      />
                      <InputError message={errors.alliance_state} className="mt-1" />
                    </div>
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="alliance_ein" className="text-gray-900 dark:text-white font-medium">
                      EIN (optional)
                    </Label>
                    <Input
                      id="alliance_ein"
                      value={data.alliance_ein ?? ""}
                      onChange={(e) => setData("alliance_ein", e.target.value)}
                      className="mt-1 bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500"
                      placeholder="12-3456789"
                      autoComplete="off"
                    />
                    <p className="text-xs text-gray-500 dark:text-gray-400">
                      A valid 9-digit EIN unlocks wallet and payout features for your alliance. It is stored on your Unity Impact Alliance and synced to your linked organization record.
                    </p>
                    <InputError message={errors.alliance_ein} className="mt-1" />
                  </div>
                </>
              )}

              <div className="space-y-2 min-w-0">
                <Label className="text-sm font-medium text-gray-700 dark:text-gray-300">
                  {isCareAllianceHub
                    ? "Primary action categories *"
                    : "Causes & Interest *"}
                </Label>
                {primaryActionCategories.length === 0 ? (
                  <Alert className="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
                    <AlertCircle className="h-4 w-4 text-amber-600" />
                    <AlertDescription className="text-amber-800 dark:text-amber-200 text-sm">
                      No categories are available yet. An administrator must add them under Admin → Org Primary Action
                      Categories, or run the database seeder.
                    </AlertDescription>
                  </Alert>
                ) : (
                  <div
                    role="group"
                    aria-label="Primary action categories"
                    className="flex min-h-[2.375rem] w-full flex-wrap items-center gap-1 rounded-md border border-[#DDD] bg-white px-2 py-1 text-sm shadow-[inset_0_1px_0_0_rgba(255,255,255,0.9)] transition-[border-color,box-shadow] focus-within:border-ring focus-within:ring-2 focus-within:ring-ring/25 dark:border-gray-600 dark:bg-[hsl(210_12%_10%)] dark:shadow-none"
                  >
                    {selectedPrimaryCategories.map((c) => (
                      <span
                        key={c.id}
                        className="tagify-tag inline-flex max-w-full items-center gap-0.5 rounded-md border border-white/25 bg-gradient-to-r from-purple-600 to-blue-600 px-1.5 py-0.5 text-[13px] leading-tight text-white shadow-sm"
                      >
                        <span className="truncate">{c.name}</span>
                        <button
                          type="button"
                          onClick={() => removePrimaryCategoryTag(c.id)}
                          className="tagify-tag__removeBtn ml-0.5 inline-flex size-[14px] shrink-0 cursor-pointer items-center justify-center rounded-sm text-white/85 transition-colors hover:bg-white/20 hover:text-white focus:outline-none focus:ring-2 focus:ring-white/50"
                          aria-label={`Remove ${c.name}`}
                        >
                          <X className="h-2.5 w-2.5" strokeWidth={2.5} />
                        </button>
                      </span>
                    ))}
                    {remainingPrimaryCategories.length > 0 ? (
                      <>
                        <label className="sr-only" htmlFor="primary-action-category-add">
                          Add category
                        </label>
                        <Select
                          key={data.primary_action_category_ids.join(",")}
                          onValueChange={(v) => {
                            if (v) addPrimaryCategoryTag(Number(v))
                          }}
                        >
                          <SelectTrigger
                            id="primary-action-category-add"
                            className="tagify__input h-7 min-w-[7rem] flex-1 justify-start border-0 bg-transparent px-1 py-0.5 text-sm text-muted-foreground shadow-none ring-0 ring-offset-0 hover:bg-transparent focus:ring-0 focus:ring-offset-0 data-[placeholder]:text-muted-foreground [&_svg]:hidden"
                          >
                            <SelectValue placeholder="Add category…" />
                          </SelectTrigger>
                          <SelectContent className="max-h-60 border border-border bg-popover text-popover-foreground shadow-md">
                            {remainingPrimaryCategories.map((c) => (
                              <SelectItem
                                key={c.id}
                                value={String(c.id)}
                                className="cursor-pointer focus:bg-accent focus:text-accent-foreground"
                              >
                                {c.name}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </>
                    ) : selectedPrimaryCategories.length > 0 ? (
                      <span className="px-1 text-xs text-muted-foreground">All categories selected</span>
                    ) : null}
                  </div>
                )}
                <InputError message={errors.primary_action_category_ids} className="mt-1" />
              </div>

              {/* Website */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="sm:col-span-2">
                  <Label htmlFor="website" className="text-gray-900 dark:text-white font-medium">
                    {isCareAllianceHub ? "Alliance website (optional)" : "Website (Optional)"}
                  </Label>
                  <div className="relative mt-1">
                    <Globe className="absolute left-3 top-3 h-4 w-4 text-gray-400" />
                    <Input
                      id="website"
                      type="text"
                      value={data.website}
                      onChange={(e) => setData("website", e.target.value)}
                      className="pl-10 bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500"
                      placeholder={isCareAllianceHub ? "https://your-alliance.org" : "example.com"}
                    />
                  </div>
                  <InputError message={errors.website} className="mt-1" />
                </div>
              </div>

              {!isCareAllianceHub && (
              <>
              {/* Wefunder project URL — link shown to supporters on your page */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="sm:col-span-2">
                  <Label htmlFor="wefunder_project_url" className="text-gray-900 dark:text-white font-medium">
                    Wefunder project URL (Optional)
                  </Label>
                  <Input
                    id="wefunder_project_url"
                    type="url"
                    value={data.wefunder_project_url}
                    onChange={(e) => setData("wefunder_project_url", e.target.value)}
                    className="mt-1 bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="https://wefunder.com/your-project"
                  />
                  <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Add your Wefunder campaign link so supporters can go straight to your investment page.
                  </p>
                  <InputError message={errors.wefunder_project_url} className="mt-1" />
                </div>
              </div>
              </>
              )}

              {/* Description */}
      <div className="grid grid-cols-1 gap-4">
        <div>
          <Label htmlFor="description" className="text-gray-900 dark:text-white font-medium">
            {isCareAllianceHub ? "Alliance description *" : "Description *"}
          </Label>
          <TextArea
            id="description"
            value={data.description}
            onChange={(e) => setData("description", e.target.value)}
            className="mt-1 w-full bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500 rounded-md shadow-sm"
            rows={4}
            placeholder={
              isCareAllianceHub
                ? "Describe your Unity Impact Alliance, its purpose, and how you work with member organizations…"
                : "Briefly describe your organization..."
            }
            required={showOrgProfileCard}
          />
          <InputError message={errors.description} className="mt-1" />
        </div>
      </div>

      {!isCareAllianceHub && (
      <div className="grid grid-cols-1 gap-4">
        <div>
          <Label htmlFor="mission" className="text-gray-900 dark:text-white font-medium">
            Mission Statement *
          </Label>
          <TextArea
            id="mission"
            value={data.mission}
            onChange={(e) => setData("mission", e.target.value)}
            className="mt-1 w-full bg-white dark:bg-gray-900 border-gray-300 dark:border-gray-600 focus:ring-blue-500 focus:border-blue-500 rounded-md shadow-sm"
            rows={4}
            placeholder="What is your organization's mission?"
            required={profileSettingsVariant === "organization"}
          />
          <InputError message={errors.mission} className="mt-1" />
        </div>
      </div>
      )}

            </CardContent>
                      </Card>

                    )}

          {showOrgProfileCard && membership && (
            <MembershipSettingsSection
              membership={membership}
              membershipRoute={
                isCareAllianceHub
                  ? route("care-alliance.membership.index")
                  : route("organization.membership.index")
              }
            />
          )}


          {/* Save Button */}
          <div className="flex justify-end">
            <Button type="submit" disabled={processing} className="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2">
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
        </form>
      </div>
    </SettingsLayout>
  )
}
