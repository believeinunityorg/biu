import SiteTitle from "@/components/site-title"
import { Link, usePage } from "@inertiajs/react"
import {  Facebook, Twitter, Instagram, Linkedin, Mail, Phone, MapPin } from "lucide-react"

interface FooterSettings {
  description?: string
  social_links?: {
    facebook?: string
    twitter?: string
    instagram?: string
    linkedin?: string
  }
  quick_links?: Array<{
    title: string
    url: string
  }>
  contact_email?: string
  contact_phone?: string
  contact_address?: string
  copyright_text?: string
  legal_links?: Array<{
    title: string
    url: string
  }>
}

export default function Footer() {
  const { merchantDomain, footerSettings } = usePage().props as {
    merchantDomain?: string
    footerSettings?: FooterSettings
  }

  // Default values
  const defaultSettings: FooterSettings = {
    description: "Connecting hearts with causes worldwide. We help you discover and support verified non-profit organizations making a real difference in communities across the globe.",
    social_links: {
      facebook: "#",
      twitter: "#",
      instagram: "#",
      linkedin: "#"
    },
    quick_links: [
      { title: "About Us", url: route("about") },
      { title: "Organizations", url: route("organizations") },
      { title: "Donate", url: route("donate") },
      { title: "Contact", url: route("contact") },
      { title: "Register Organization", url: route("register.organization") }
    ],
    contact_email: "support@believeinunity.org",
    contact_phone: "+1 (555) 123-4567",
    copyright_text: `${new Date().getFullYear()} ${import.meta.env.VITE_APP_NAME}. All rights reserved.`,
    legal_links: [
      { title: "Privacy Policy", url: "/privacy-policy" },
      { title: "Terms of Service", url: "/terms-of-service" },
      { title: "Data Deletion", url: "/data-deletion" },
    ]
  }

  const settings = { ...defaultSettings, ...footerSettings }

  return (
    <footer className="bg-gray-900 pb-[calc(5.25rem+env(safe-area-inset-bottom))] text-white 2xl:pb-0 dark:bg-black">
      <div className="container mx-auto px-4 py-16">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
          {/* Logo and Description */}
          <div className="lg:col-span-2">
            {/* Logo */}
            <SiteTitle className="mb-6"/>
            {settings.description && (
              <p className="text-gray-400 mb-6 max-w-md leading-relaxed">
                {settings.description}
              </p>
            )}
            {settings.social_links && (
              <div className="flex space-x-4">
                {settings.social_links.facebook && (
                  <Link
                    href={settings.social_links.facebook}
                    className="text-gray-400 hover:text-white transition-colors p-2 rounded-lg hover:bg-gray-800"
                  >
                    <Facebook className="h-5 w-5" />
                  </Link>
                )}
                {settings.social_links.twitter && (
                  <Link
                    href={settings.social_links.twitter}
                    className="text-gray-400 hover:text-white transition-colors p-2 rounded-lg hover:bg-gray-800"
                  >
                    <Twitter className="h-5 w-5" />
                  </Link>
                )}
                {settings.social_links.instagram && (
                  <Link
                    href={settings.social_links.instagram}
                    className="text-gray-400 hover:text-white transition-colors p-2 rounded-lg hover:bg-gray-800"
                  >
                    <Instagram className="h-5 w-5" />
                  </Link>
                )}
                {settings.social_links.linkedin && (
                  <Link
                    href={settings.social_links.linkedin}
                    className="text-gray-400 hover:text-white transition-colors p-2 rounded-lg hover:bg-gray-800"
                  >
                    <Linkedin className="h-5 w-5" />
                  </Link>
                )}
              </div>
            )}
          </div>

          {/* Quick Links */}
          {settings.quick_links && settings.quick_links.length > 0 && (
            <div>
              <h3 className="text-lg font-semibold mb-6">Quick Links</h3>
              <ul className="space-y-3">
                {settings.quick_links.map((link, index) => (
                  <li key={index}>
                    {link.url.startsWith('http') || link.url.startsWith('//') ? (
                      <a
                        href={link.url}
                        className="text-gray-400 hover:text-white transition-colors"
                      >
                        {link.title}
                      </a>
                    ) : (
                      <Link
                        href={link.url}
                        className="text-gray-400 hover:text-white transition-colors"
                      >
                        {link.title}
                      </Link>
                    )}
                  </li>
                ))}
                {merchantDomain && (
                  <li>
                    <a
                      href={`//${merchantDomain || import.meta.env.VITE_MERCHANT_DOMAIN || 'merchant.believeinunity.org'}`}
                      className="text-gray-400 hover:text-white transition-colors"
                    >
                      Become a Merchant
                    </a>
                  </li>
                )}
              </ul>
            </div>
          )}

          {/* Contact Info */}
          {(settings.contact_email || settings.contact_phone || settings.contact_address) && (
            <div>
              <h3 className="text-lg font-semibold mb-6">Contact Info</h3>
              <ul className="space-y-4">
                {settings.contact_email && (
                  <li className="flex items-center space-x-3">
                    <Mail className="h-5 w-5 text-gray-400" />
                    <span className="text-gray-400">{settings.contact_email}</span>
                  </li>
                )}
                {settings.contact_phone && (
                  <li className="flex items-center space-x-3">
                    <Phone className="h-5 w-5 text-gray-400" />
                    <span className="text-gray-400">{settings.contact_phone}</span>
                  </li>
                )}
                {settings.contact_address && (
                  <li className="flex items-start space-x-3">
                    <MapPin className="h-5 w-5 text-gray-400 mt-0.5" />
                    <span className="text-gray-400 whitespace-pre-line">
                      {settings.contact_address}
                    </span>
                  </li>
                )}
              </ul>
            </div>
          )}
        </div>

        <div className="border-t border-gray-800 mt-12 pt-8">
          <div className="flex flex-col md:flex-row justify-between items-center">
            <p className="text-gray-400 text-sm">
              &copy; {settings.copyright_text || `${new Date().getFullYear()} ${import.meta.env.VITE_APP_NAME}. All rights reserved.`}
            </p>
            {settings.legal_links && settings.legal_links.length > 0 && (
              <div className="flex space-x-6 mt-4 md:mt-0">
                {settings.legal_links.map((link, index) => (
                  <Link
                    key={index}
                    href={link.url}
                    className="text-gray-400 hover:text-white text-sm transition-colors"
                  >
                    {link.title}
                  </Link>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>
    </footer>
  )
}
