// components/facebook/FacebookPermissionModal.tsx
import React, { useState, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
  AlertCircle,
  CheckCircle2,
  FileText,
  Globe,
  MessageSquare,
  Shield,
  ChevronDown,
  ChevronUp,
  Info,
} from 'lucide-react';
import { cn } from '@/lib/utils';

interface FacebookPermissionModalProps {
  isOpen: boolean;
  onClose: () => void;
  onAccept: () => void;
}

const PERMISSIONS = [
    {
      id: 'pages_manage_posts',
      title: 'Manage Page Posts',
      description: 'Create and publish posts on your Facebook Page',
      detailedDescription: 'This permission allows us to create and publish posts on your behalf when you choose. We use this only when you explicitly click Publish in our app. You can view those BIU-created posts in our app; we do not read your full Facebook Page feed.',
      icon: <MessageSquare className="h-5 w-5" />,
      required: true,
      color: 'text-blue-500 dark:text-blue-400',
      bgColor: 'bg-blue-100 dark:bg-blue-900/30',
      borderColor: 'border-blue-200 dark:border-blue-800',
    },
    {
      id: 'pages_show_list',
      title: 'Show Page List',
      description: 'See the list of Facebook Pages you manage',
      detailedDescription: 'This permission allows us to see which Facebook Pages you are an admin of, so you can select which page to post to. We only access the list of pages and basic info (name, ID), not any private page data or messages.',
      icon: <Globe className="h-5 w-5" />,
      required: true,
      color: 'text-purple-500 dark:text-purple-400',
      bgColor: 'bg-purple-100 dark:bg-purple-900/30',
      borderColor: 'border-purple-200 dark:border-purple-800',
    },
    {
      id: 'public_profile',
      title: 'Public Profile',
      description: 'Access your basic public profile information',
      detailedDescription: 'We access your public Facebook profile info (name, profile picture) to personalize your experience and verify your identity. We do not access your private information, friend lists, or messages.',
      icon: <Shield className="h-5 w-5" />,
      required: true,
      color: 'text-orange-500 dark:text-orange-400',
      bgColor: 'bg-orange-100 dark:bg-orange-900/30',
      borderColor: 'border-orange-200 dark:border-orange-800',
    },
];

export default function FacebookPermissionModal({
  isOpen,
  onClose,
  onAccept,
}: FacebookPermissionModalProps) {
  const [acceptedPermissions, setAcceptedPermissions] = useState<string[]>(
    () => PERMISSIONS.map((p) => p.id)
  );
  const [allChecked, setAllChecked] = useState(true);
  const [expandedPermission, setExpandedPermission] = useState<string | null>(null);
  const [isMobile, setIsMobile] = useState(false);

  useEffect(() => {
    if (isOpen) {
      setAcceptedPermissions(PERMISSIONS.map((p) => p.id));
      setAllChecked(true);
    }
  }, [isOpen]);

  useEffect(() => {
    const checkMobile = () => setIsMobile(window.innerWidth < 768);
    checkMobile();
    window.addEventListener('resize', checkMobile);
    return () => window.removeEventListener('resize', checkMobile);
  }, []);

  const permissions = PERMISSIONS;

  const handlePermissionToggle = (permissionId: string) => {
    setAcceptedPermissions((prev) =>
      prev.includes(permissionId)
        ? prev.filter((id) => id !== permissionId)
        : [...prev, permissionId]
    );
  };

  const handleSelectAll = () => {
    if (allChecked) {
      setAcceptedPermissions([]);
    } else {
      setAcceptedPermissions(permissions.map((p) => p.id));
    }
    setAllChecked(!allChecked);
  };

  const toggleExpanded = (permissionId: string) => {
    setExpandedPermission(expandedPermission === permissionId ? null : permissionId);
  };

  const allRequiredAccepted = permissions
    .filter((p) => p.required)
    .every((p) => acceptedPermissions.includes(p.id));

  return (
    <Dialog open={isOpen} onOpenChange={onClose}>
      <DialogContent className={cn(
        "max-w-2xl max-h-[90vh] overflow-hidden",
        "sm:max-w-2xl md:max-w-3xl",
        "w-[95vw] mx-auto",
        "p-0 flex flex-col"
      )}>
        {/* Header - Fixed */}
        <DialogHeader className="p-6 pb-4 flex-shrink-0 border-b bg-background">
          <div className="flex items-start gap-3">
            <div className={cn(
              "flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center",
              "bg-blue-100 dark:bg-blue-900/30"
            )}>
              <Shield className="h-6 w-6 text-blue-600 dark:text-blue-400" />
            </div>
            <div className="flex-1 min-w-0">
              <DialogTitle className="text-xl sm:text-2xl font-bold truncate">
                Facebook Page Permissions
              </DialogTitle>
              <DialogDescription className="text-sm sm:text-base mt-1">
                To connect your Facebook Page, we need the following permissions.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        {/* Scrollable Content Area */}
        <div className="flex-1 overflow-y-auto px-6 pb-6">
          {/* Info Alert */}
          <div className={cn(
            "rounded-lg p-4 mb-6",
            "bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800"
          )}>
            <div className="flex items-start gap-3">
              <AlertCircle className="h-5 w-5 text-blue-600 dark:text-blue-400 mt-0.5 flex-shrink-0" />
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-blue-800 dark:text-blue-300">
                  Why we need these permissions?
                </p>
                <p className="text-sm text-blue-700 dark:text-blue-400 mt-1">
                  These permissions allow our app to list Pages you manage and to
                  publish posts to your Facebook Page when you choose.
                </p>
              </div>
            </div>
          </div>

          {/* Select All - Mobile Optimized */}
          <div className="flex items-center justify-between p-4 mb-4 rounded-lg border bg-card">
            <div className="flex items-center space-x-3">
              <Checkbox
                id="select-all"
                checked={allChecked}
                onCheckedChange={handleSelectAll}
                className="h-5 w-5"
              />
              <Label
                htmlFor="select-all"
                className="text-base font-semibold cursor-pointer select-none"
              >
                Select All Permissions
              </Label>
            </div>
            <span className="text-sm text-muted-foreground">
              {acceptedPermissions.length} of {permissions.length}
            </span>
          </div>

          {/* Permissions List */}
          <div className="space-y-3 sm:space-y-4">
            {permissions.map((permission) => {
              const isExpanded = expandedPermission === permission.id;
              const isAccepted = acceptedPermissions.includes(permission.id);

              return (
                <div
                  key={permission.id}
                  className={cn(
                    "rounded-lg border transition-all duration-200",
                    "hover:shadow-sm",
                    isAccepted
                      ? `${permission.borderColor} ${permission.bgColor}`
                      : "border-gray-200 dark:border-gray-800 bg-card",
                    isExpanded && "shadow-md"
                  )}
                >
                  {/* Permission Header - Clickable on mobile */}
                  <div
                    className={cn(
                      "flex items-center justify-between p-4 cursor-pointer",
                      !isMobile && "cursor-default"
                    )}
                    onClick={() => isMobile && toggleExpanded(permission.id)}
                  >
                    <div className="flex items-start gap-3 flex-1 min-w-0">
                      <div className={cn(
                        "flex-shrink-0 w-10 h-10 rounded-lg flex items-center justify-center",
                        permission.bgColor
                      )}>
                        <div className={permission.color}>
                          {permission.icon}
                        </div>
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2">
                          <h4 className="font-semibold text-base truncate">
                            {permission.title}
                          </h4>
                          {permission.required && (
                            <span className="text-xs bg-red-100 dark:bg-red-900 text-red-700 dark:text-red-300 px-2 py-0.5 rounded whitespace-nowrap">
                              Required
                            </span>
                          )}
                        </div>
                        <p className="text-sm text-muted-foreground mt-1 line-clamp-2">
                          {permission.description}
                        </p>
                      </div>
                    </div>

                    <div className="flex items-center gap-3 ml-3 flex-shrink-0">
                      {isMobile ? (
                        <button
                          onClick={(e) => {
                            e.stopPropagation();
                            toggleExpanded(permission.id);
                          }}
                          className="p-1 hover:bg-gray-100 dark:hover:bg-gray-800 rounded"
                        >
                          {isExpanded ?
                            <ChevronUp className="h-4 w-4" /> :
                            <ChevronDown className="h-4 w-4" />
                          }
                        </button>
                      ) : (
                        <button
                          onClick={() => toggleExpanded(permission.id)}
                          className="p-1 hover:bg-gray-100 dark:hover:bg-gray-800 rounded flex items-center gap-1 text-sm text-muted-foreground"
                        >
                          <Info className="h-4 w-4" />
                          <span className="hidden sm:inline">Details</span>
                        </button>
                      )}

                      <Checkbox
                        id={permission.id}
                        checked={isAccepted}
                        onCheckedChange={() => handlePermissionToggle(permission.id)}
                        className="h-5 w-5"
                      />
                    </div>
                  </div>

                  {/* Detailed Description - Expandable */}
                  {(isExpanded || !isMobile) && (
                    <div className={cn(
                      "px-4 pb-4",
                      isMobile ? "border-t pt-4" : "pt-2"
                    )}>
                      <div className="flex items-start gap-2 text-sm text-muted-foreground">
                        <Info className="h-4 w-4 mt-0.5 flex-shrink-0" />
                        <p>{permission.detailedDescription}</p>
                      </div>
                    </div>
                  )}
                </div>
              );
            })}
          </div>

          {/* Privacy Notice */}
          <div className={cn(
            "rounded-lg p-4 mt-6",
            "bg-gray-50 dark:bg-gray-900/30 border border-gray-200 dark:border-gray-800"
          )}>
            <div className="flex items-start gap-3">
              <FileText className="h-5 w-5 text-gray-600 dark:text-gray-400 mt-0.5 flex-shrink-0" />
              <div className="flex-1 min-w-0">
                <p className="text-sm font-medium text-gray-800 dark:text-gray-300">
                  Privacy & Data Usage
                </p>
                <p className="text-sm text-gray-700 dark:text-gray-400 mt-1">
                  We only use these permissions to provide the Facebook posting features.
                  We do not sell your data or use it for advertising purposes.
                  <a
                    href="/privacy-policy"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="ml-1 text-blue-600 dark:text-blue-400 hover:underline font-medium"
                  >
                    View our Privacy Policy
                  </a>
                </p>
              </div>
            </div>
          </div>

          {/* Required Permissions Warning */}
          {!allRequiredAccepted && (
            <div className={cn(
              "rounded-lg p-4 mt-4",
              "bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800"
            )}>
              <div className="flex items-center gap-2 text-sm text-amber-800 dark:text-amber-300">
                <AlertCircle className="h-4 w-4 flex-shrink-0" />
                <span>All required permissions must be accepted to continue.</span>
              </div>
            </div>
          )}
        </div>

        {/* Footer - Fixed */}
        <DialogFooter className={cn(
          "p-6 pt-4 border-t bg-background flex-shrink-0",
          "flex flex-col-reverse sm:flex-row gap-3"
        )}>
          <Button
            variant="outline"
            onClick={onClose}
            className="w-full sm:w-auto"
            size={isMobile ? "default" : "lg"}
          >
            Cancel
          </Button>
          <Button
            onClick={onAccept}
            disabled={!allRequiredAccepted}
            className={cn(
              "w-full sm:w-auto",
              "bg-blue-600 hover:bg-blue-700 dark:bg-blue-700 dark:hover:bg-blue-600",
              "disabled:opacity-50 disabled:cursor-not-allowed"
            )}
            size={isMobile ? "default" : "lg"}
          >
            <CheckCircle2 className="h-4 w-4 mr-2" />
            Accept & Continue to Facebook
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
