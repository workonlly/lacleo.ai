import React, { useState } from "react"
import { Eye, Star, Linkedin, Phone, Mail, User, Building } from "lucide-react"
import { ContactAttributes, CompanyAttributes } from "@/interface/searchTable/search"
import CompanyIcon from "../../static/media/avatars/phoenix_avatar.svg?react"
import LinkedinIcon from "../../static/media/icons/linkedin_icon.svg?react"

import { Button } from "./button"
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "./tooltip"
import { useAppDispatch, useAppSelector } from "@/app/hooks/reduxHooks"
import { openCompanyDetails } from "@/features/searchTable/slice/companyDetailsSlice"
import {
  useRevealContactMutation,
  useCompanyLogoQuery,
  useSearchContactsQuery,
  useRevealCompanyMutation,
  useBillingUsageQuery
} from "@/features/searchTable/slice/apiSlice"
import { buildSearchUrl } from "@/app/utils/searchUtils"
import { useAlert } from "@/app/hooks/alertHooks"
import { setCreditUsageOpen } from "@/features/settings/slice/settingSlice"

interface ContactCardProps {
  contact: ContactAttributes
  location?: string
  secondaryPhone?: string
}

const ContactCard: React.FC<ContactCardProps> = ({ contact, location = "", secondaryPhone }) => {
  const [revealedFields, setRevealedFields] = useState<Set<string>>(new Set())
  const dispatch = useAppDispatch()
  const user = useAppSelector((s) => s.setting.user)
  const [revealedPhone, setRevealedPhone] = useState<string | null>(null)
  const [revealedEmail, setRevealedEmail] = useState<string | null>(null)
  const [revealedCompanyPhone, setRevealedCompanyPhone] = useState<string | null>(null)
  const [revealedCompanyEmail, setRevealedCompanyEmail] = useState<string | null>(null)
  const [revealContact] = useRevealContactMutation()
  const [revealCompany] = useRevealCompanyMutation()
  const { showAlert } = useAlert()
  const { data: billingUsage } = useBillingUsageQuery()

  // Normalized company domain for logo and search
  const normalizedDomain = (contact.website || "")
    .replace(/^https?:\/\//, "")
    .replace(/^www\./, "")
    .trim()
  const { data: logoData } = useCompanyLogoQuery({ domain: normalizedDomain }, { skip: normalizedDomain === "" })
  const logoUrl = logoData?.logo_url || null

  const companySearchUrl = buildSearchUrl({ page: 1, count: 1 }, { searchTerm: normalizedDomain || contact.company || "" })
  const { data: companySearchData } = useSearchContactsQuery({ type: "company", buildParams: companySearchUrl }, { refetchOnMountOrArgChange: true })

  const resolvedCompany: CompanyAttributes | null =
    ((companySearchData as { data?: Array<{ attributes: CompanyAttributes }> } | undefined)?.data?.[0]?.attributes as
      | CompanyAttributes
      | undefined) || null

  // Categorize Emails
  const rawEmails = contact.emails || (contact.email ? [{ address: contact.email, type: "work" }] : [])
  const emailsList = Array.isArray(rawEmails)
    ? rawEmails.map((e) => (typeof e === "string" ? { address: e, type: "work" } : { address: e.email || e.address, type: e.type || "work" }))
    : []

  const workEmails = emailsList.filter((e) => e.type === "work" || e.type === "business")
  const personalEmails = emailsList.filter((e) => e.type === "personal")

  // Categorize Phones
  const rawPhones: Array<string | { number?: string; phone_number?: string; type?: string }> =
    contact.phone_numbers || (contact.phone_number ? [{ number: contact.phone_number, type: "mobile" }] : [])
  const phonesList = Array.isArray(rawPhones)
    ? rawPhones.map((p) =>
        typeof p === "string" ? { number: p, type: "mobile" } : { number: p.phone_number || p.number || "", type: p.type || "mobile" }
      )
    : []

  const mobilePhones = phonesList.filter((p) => p.type === "mobile")
  const directPhones = phonesList.filter((p) => p.type !== "mobile") // Assuming others are direct/office if not mobile

  // Company Phone
  const companyPhone = resolvedCompany?.company_phone || resolvedCompany?.phone_number || null

  const handleInsufficientCredits = () => {
    dispatch(setCreditUsageOpen(true))
  }

  // Email verification gating removed; rely on credit balance and backend auth

  const toggleRevealEmail = async () => {
    if (revealedFields.has("email")) return
    const id = contact._id

    // Check if user has enough credits (1 credit for email reveal)
    const balance = billingUsage?.balance as number | undefined
    if (balance !== undefined && balance < 1) {
      dispatch(setCreditUsageOpen(true))
      return
    }

    const requestId = crypto && "randomUUID" in crypto ? crypto.randomUUID() : `${Date.now()}`
    try {
      const res = await revealContact({ id, requestId, revealEmail: true }).unwrap()
      if (res.revealed) {
        setRevealedEmail(res.contact.email) // Update reliable single source if needed
        const newRevealed = new Set(revealedFields)
        newRevealed.add("email")
        setRevealedFields(newRevealed)
      } else {
        showAlert("Reveal unavailable", "No email available to reveal", "warning", 4000)
      }
    } catch (e: unknown) {
      const status = typeof e === "object" && e !== null && "status" in e ? (e as { status?: number }).status : undefined
      const dataError =
        typeof e === "object" && e !== null && "data" in (e as Record<string, unknown>)
          ? (e as { data?: { error?: string } }).data?.error || null
          : null
      if (status === 402 || dataError === "INSUFFICIENT_CREDITS") {
        dispatch(setCreditUsageOpen(true))
        return
      }
      showAlert("Reveal failed", "Unable to reveal email", "error", 5000)
    }
  }

  const toggleRevealPhone = async () => {
    if (revealedFields.has("phone")) return
    const id = contact._id

    const requestId = crypto && "randomUUID" in crypto ? crypto.randomUUID() : `${Date.now()}`
    try {
      const res = await revealContact({ id, requestId, revealPhone: true }).unwrap()
      if (res.revealed) {
        setRevealedPhone(res.contact.phone)
        const newRevealed = new Set(revealedFields)
        newRevealed.add("phone")
        setRevealedFields(newRevealed)
      } else {
        showAlert("Reveal unavailable", "No phone available to reveal", "warning", 4000)
      }
    } catch (e: unknown) {
      const status = typeof e === "object" && e !== null && "status" in e ? (e as { status?: number }).status : undefined
      const dataError =
        typeof e === "object" && e !== null && "data" in (e as Record<string, unknown>)
          ? (e as { data?: { error?: string } }).data?.error || null
          : null
      if (status === 402 || dataError === "INSUFFICIENT_CREDITS") {
        dispatch(setCreditUsageOpen(true))
        return
      }
      showAlert("Reveal failed", "Unable to reveal phone", "error", 5000)
    }
  }

  const toggleRevealCompanyPhone = async () => {
    if (revealedFields.has("company_phone")) return
    const id = resolvedCompany?._id
    if (!id) return showAlert("Unavailable", "Company info not found", "warning", 3000)

    const requestId = crypto && "randomUUID" in crypto ? crypto.randomUUID() : `${Date.now()}`
    try {
      const res = await revealCompany({ id, requestId, revealPhone: true }).unwrap()
      if (res.revealed) {
        setRevealedCompanyPhone(res.company.phone)
        const newRevealed = new Set(revealedFields)
        newRevealed.add("company_phone")
        setRevealedFields(newRevealed)
      }
    } catch (e: unknown) {
      // Company phone reveal is free - no credit handling needed
      showAlert("Reveal failed", "Unable to reveal company phone", "error", 5000)
    }
  }

  const toggleRevealCompanyEmail = async () => {
    if (revealedFields.has("company_email")) return
    const id = resolvedCompany?._id
    if (!id) return showAlert("Unavailable", "Company info not found", "warning", 3000)

    const requestId = crypto && "randomUUID" in crypto ? crypto.randomUUID() : `${Date.now()}`
    try {
      const res = await revealCompany({ id, requestId, revealEmail: true }).unwrap()
      if (res.revealed) {
        setRevealedCompanyEmail(res.company.email)
        const newRevealed = new Set(revealedFields)
        newRevealed.add("company_email")
        setRevealedFields(newRevealed)
      }
    } catch (e: unknown) {
      // Company email reveal is free - no credit handling needed
      showAlert("Reveal failed", "Unable to reveal company email", "error", 5000)
    }
  }

  const revealAllInfo = async () => {
    // Reveal all available sections
    if (!revealedFields.has("email") && emailsList.length > 0) await toggleRevealEmail()
    if (!revealedFields.has("phone") && phonesList.length > 0) await toggleRevealPhone()
    if (!revealedFields.has("company_phone") && companyPhone) await toggleRevealCompanyPhone()
  }

  const maskEmail = (email?: string) => {
    if (!email) return "N/A"
    const at = email.indexOf("@")
    if (at === -1) return "N/A"
    const domain = email.slice(at + 1)
    return `••••@${domain}`
  }

  const maskPhone = (phone?: string) => {
    if (!phone) return "N/A"
    const digits = phone.replace(/\D/g, "")
    const lastFour = digits.slice(-4)
    return `•••• ${lastFour}`
  }

  const displayName = contact.full_name || (contact.first_name ? `${contact.first_name} ${contact.last_name}` : "Unknown")
  const jobTitle = contact.title || ""
  const companyName = contact.company || ""

  return (
    <div className="w-full max-w-[440px] rounded-xl border border-gray-200 bg-white shadow-sm transition-all hover:shadow-md">
      {/* Header Section */}
      <div className="p-5">
        <div className="flex items-start gap-4 pb-4">
          <div className="flex size-12 items-center justify-center rounded-full border border-gray-200 bg-gray-50">
            {logoUrl ? <img src={logoUrl} alt={companyName} className="size-8 object-contain " /> : <User className="size-6 text-gray-400" />}
          </div>
          <div className="min-w-0 flex-1">
            <h3 className="truncate text-base font-semibold text-gray-900">{displayName}</h3>
            {!!jobTitle && <p className="truncate text-sm text-gray-700">{jobTitle}</p>}
            {!!companyName && <p className="truncate text-sm text-gray-600">{companyName}</p>}
            {!!location && <p className="mt-1 truncate text-sm text-gray-500">{location}</p>}
          </div>
        </div>

        {!!(resolvedCompany?.short_description || resolvedCompany?.description) && (
          <div className="mb-4 rounded-lg border border-gray-100 bg-gray-50 p-3">
            <p className="line-clamp-3 text-xs leading-relaxed text-gray-600">{resolvedCompany.short_description || resolvedCompany.description}</p>
          </div>
        )}
      </div>

      {/* Action Buttons */}
      <div className="flex gap-2 px-5 pb-4">
        <TooltipProvider>
          <Tooltip>
            <TooltipTrigger asChild>
              <Button onClick={revealAllInfo} className="flex-1 rounded-lg bg-blue-600 py-2.5 font-medium text-white hover:bg-blue-700">
                <Eye className="mr-2 size-4" />
                Reveal Info
              </Button>
            </TooltipTrigger>
            <TooltipContent side="bottom" className="text-xs ">
              Reveal all contact information (1 credit per work email, 4 credits per phone)
            </TooltipContent>
          </Tooltip>
        </TooltipProvider>
        <Button
          variant="outline"
          className="flex-1 rounded-lg border-gray-300 py-2.5 font-medium text-gray-700 hover:bg-gray-50"
          onClick={() => dispatch(openCompanyDetails(resolvedCompany || null))}
        >
          <User className="mr-2 size-4 text-gray-600" />
          View Profile
        </Button>
        {!!contact.linkedin_url && (
          <a
            href={contact.linkedin_url}
            target="_blank"
            rel="noopener noreferrer"
            className="flex size-11 items-center justify-center rounded-lg border border-gray-300 bg-white transition-colors hover:bg-gray-50"
            aria-label="View LinkedIn profile"
          >
            <LinkedinIcon className="size-5 text-blue-600" />
          </a>
        )}
      </div>

      {/* Contact Information */}
      <div className="border-t border-gray-100">
        {/* Phone Section */}
        {!!(phonesList.length > 0 || companyPhone) && (
          <div className="border-b border-gray-100 last:border-b-0">
            <div className="bg-gray-50 px-5 py-3">
              <h4 className="text-xs font-medium uppercase tracking-wide text-gray-500">Phone</h4>
            </div>
            <div className="space-y-3 p-5 pt-3">
              {mobilePhones.map((p, i) => (
                <div key={i} className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <Phone className="size-4 text-gray-500" />
                    <span className="text-sm text-gray-600">Mobile Phone</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-gray-900">{revealedFields.has("phone") ? p.number : maskPhone(p.number)}</span>
                    <button
                      onClick={toggleRevealPhone}
                      className="flex items-center gap-1 text-blue-500 transition-colors hover:text-blue-600"
                      aria-label="Reveal phone number"
                    >
                      <span className="text-[10px] text-gray-400">4 credits</span>
                      <Eye className="size-4" />
                    </button>
                  </div>
                </div>
              ))}

              {/* Company Phone */}
              {!!companyPhone && (
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <Phone className="size-4 text-gray-500" />
                    <span className="text-sm text-gray-600">Company Phone</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-gray-900">
                      {revealedFields.has("company_phone") ? revealedCompanyPhone || companyPhone : maskPhone(companyPhone)}
                    </span>
                    <button
                      onClick={toggleRevealCompanyPhone}
                      className="flex items-center gap-1 text-blue-500 transition-colors hover:text-blue-600"
                      aria-label="Reveal company phone"
                    >
                      
                      <Eye className="size-4" />
                    </button>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}

        {/* Email Section */}
        {(emailsList.length > 0 || (resolvedCompany?.emails || []).length > 0) && (
          <div className="border-b border-gray-100 last:border-b-0">
            <div className="bg-gray-50 px-5 py-3">
              <h4 className="text-xs font-medium uppercase tracking-wide text-gray-500">Email</h4>
            </div>
            <div className="space-y-3 p-5 pt-3">
              {workEmails.map((e, i) => (
                <div key={i} className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <Mail className="size-4 text-gray-500" />
                    <span className="text-sm text-gray-600">Work Email</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-gray-900">{revealedFields.has("email") ? e.address : maskEmail(e.address)}</span>
                    <button
                      onClick={toggleRevealEmail}
                      className="flex items-center gap-1 text-blue-500 transition-colors hover:text-blue-600"
                      aria-label="Reveal email"
                    >
                      <span className="text-[10px] text-gray-400">1 credits</span>
                      <Eye className="size-4" />
                    </button>
                  </div>
                </div>
              ))}

              {personalEmails.map((e, i) => (
                <div key={i} className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <Mail className="size-4 text-gray-500" />
                    <span className="text-sm text-gray-600">Personal Email</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-gray-900">{revealedFields.has("email") ? e.address : maskEmail(e.address)}</span>
                    <button
                      onClick={toggleRevealEmail}
                      className="flex items-center gap-1 text-blue-500 transition-colors hover:text-blue-600"
                      aria-label="Reveal email"
                    >
                     
                      <Eye className="size-4" />
                    </button>
                  </div>
                </div>
              ))}

              {Array.isArray(resolvedCompany?.emails) && (resolvedCompany!.emails as Array<string | { address?: string }>).length > 0 && (
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <Mail className="size-4 text-gray-500" />
                    <span className="text-sm text-gray-600">Company Email</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-sm font-medium text-gray-900">
                      {revealedFields.has("company_email")
                        ? revealedCompanyEmail ||
                          (typeof resolvedCompany!.emails[0] === "string"
                            ? (resolvedCompany!.emails[0] as string)
                            : (resolvedCompany!.emails[0] as { address?: string }).address || "")
                        : maskEmail(
                            typeof resolvedCompany!.emails[0] === "string"
                              ? (resolvedCompany!.emails[0] as string)
                              : (resolvedCompany!.emails[0] as { address?: string }).address || ""
                          )}
                    </span>
                    <button
                      onClick={toggleRevealCompanyEmail}
                      className="flex items-center gap-1 text-blue-500 transition-colors hover:text-blue-600"
                      aria-label="Reveal company email"
                    >
                      
                      <Eye className="size-4" />
                    </button>
                  </div>
                </div>
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

export default ContactCard
