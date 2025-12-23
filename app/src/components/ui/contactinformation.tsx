import { useAppDispatch, useAppSelector } from "@/app/hooks/reduxHooks"
import { openCompanyDetails } from "@/features/searchTable/slice/companyDetailsSlice"
import { closeContactInfo, openContactInfoForCompany } from "@/features/searchTable/slice/contactInfoSlice"
import { CompanyAttributes, ContactAttributes } from "@/interface/searchTable/search"
import { Building, Eye, List, Mail, Phone, User, X } from "lucide-react"
import { useState } from "react"
import LinkIcon from "../../static/media/icons/link-m.svg?react"
import LinkedinIcon from "../../static/media/icons/linkedin_icon.svg?react"
import NetflixLogo from "../../static/media/logo/netflix-logo.svg?react"
import {
  useCompanyLogoQuery,
  useSearchContactsQuery,
  useRevealContactMutation,
  useRevealCompanyMutation,
  useBillingUsageQuery
} from "@/features/searchTable/slice/apiSlice"
import { buildSearchUrl } from "@/app/utils/searchUtils"
import { Avatar } from "./avatar"
import { Badge } from "./badge"
import { Button } from "./button"
import EmployeesDetails from "./employeeDetails"
import { useAlert } from "@/app/hooks/alertHooks"
import { setCreditUsageOpen } from "@/features/settings/slice/settingSlice"

type ContactInformationProps = {
  contact: ContactAttributes | null
  company: CompanyAttributes | null
  onClose: () => void
  hideContactFields?: boolean
  hideCompanyActions?: boolean
}

const ContactInformation = ({ contact, onClose, hideContactFields = false, hideCompanyActions = false, company }: ContactInformationProps) => {
  const dispatch = useAppDispatch()
  const user = useAppSelector((s) => s.setting.user)
  const [revealedFields, setRevealedFields] = useState<Set<string>>(new Set())
  const [revealContact] = useRevealContactMutation()
  const [revealCompany] = useRevealCompanyMutation()
  const { showAlert } = useAlert()
  const [revealedPhone, setRevealedPhone] = useState<string | null>(null)
  const [revealedEmail, setRevealedEmail] = useState<string | null>(null)
  const [revealedCompanyPhone, setRevealedCompanyPhone] = useState<string | null>(null)
  const [revealedCompanyEmail, setRevealedCompanyEmail] = useState<string | null>(null)
  const { data: billingUsage } = useBillingUsageQuery()

  const normalizedDomain = (company?.website || contact?.website || "")
    .replace(/^https?:\/\//, "")
    .replace(/^www\./, "")
    .trim()
  const { data: logoData } = useCompanyLogoQuery({ domain: normalizedDomain }, { skip: normalizedDomain === "" })
  const logoUrl = logoData?.logo_url || null

  const companySearchUrl = buildSearchUrl({ page: 1, count: 1 }, { searchTerm: normalizedDomain || contact?.company || "" })
  const { data: companySearchData } = useSearchContactsQuery({ type: "company", buildParams: companySearchUrl }, { refetchOnMountOrArgChange: true })

  const resolvedCompany: CompanyAttributes | null =
    ((companySearchData as { data?: Array<{ attributes: CompanyAttributes }> } | undefined)?.data?.[0]?.attributes as
      | CompanyAttributes
      | undefined) ||
    company ||
    null

  const social = resolvedCompany?.social_media
  const companyLinkedIn = resolvedCompany?.company_linkedin_url || resolvedCompany?.linkedin_url || (social?.linkedin_url ?? null)
  const companyTwitter = resolvedCompany?.twitter_url || (social?.twitter_url ?? null)
  const companyFacebook = resolvedCompany?.facebook_url || (social?.facebook_url ?? null)

  const headcountRaw = (resolvedCompany?.company_headcount ??
    resolvedCompany?.number_of_employees ??
    contact?.company_headcount ??
    contact?.number_of_employees ??
    contact?.employee_count ??
    null) as number | string | null
  const headcountDisplay = headcountRaw !== null && headcountRaw !== undefined ? String(headcountRaw) : "N/A"

  const industryDisplay = String((resolvedCompany?.industry ?? contact?.industry ?? "") || "") || "N/A"

  const formatRevenue = (val: unknown): string => {
    if (val === null || val === undefined) return "N/A"
    const toCompact = (n: number): string => {
      if (n >= 1_000_000_000) return `$${(n / 1_000_000_000).toFixed(1)}B`
      if (n >= 1_000_000) return `$${(n / 1_000_000).toFixed(1)}M`
      if (n >= 1_000) return `$${(n / 1_000).toFixed(1)}K`
      return `$${n.toLocaleString()}`
    }
    if (typeof val === "number") return val > 0 ? toCompact(val) : "N/A"
    if (typeof val === "string") {
      const s = val.trim()
      if (!s) return "N/A"
      const match = s.match(/^\$?\s*([0-9]+(?:\.[0-9]+)?)\s*([kKmMbB])?/)
      if (match) {
        const n = parseFloat(match[1])
        const suf = (match[2] || "").toUpperCase()
        if (suf === "B") return `$${n.toFixed(1)}B`
        if (suf === "M") return `$${n.toFixed(1)}M`
        if (suf === "K") return `$${n.toFixed(1)}K`
        if (!isNaN(n)) return toCompact(n)
      }
      const digits = parseInt(s.replace(/[^0-9]/g, ""), 10)
      if (!isNaN(digits) && digits > 0) return toCompact(digits)
      return s
    }
    return "N/A"
  }

  const revenueRaw = resolvedCompany?.annual_revenue_usd ?? resolvedCompany?.revenue ?? contact?.annual_revenue ?? contact?.revenue ?? null
  const revenueDisplay = formatRevenue(revenueRaw)

  const getString = (obj: unknown, key: string): string | null => {
    if (!obj || typeof obj !== "object") return null
    const v = (obj as Record<string, unknown>)[key]
    return typeof v === "string" ? v : null
  }
  const descriptionText =
    getString(resolvedCompany, "short_description") ??
    resolvedCompany?.description ??
    getString(resolvedCompany, "business_description") ??
    getString(contact, "short_description") ??
    getString(contact, "business_description") ??
    null
  const foundedYearDisplay = String((resolvedCompany?.founded_year ?? contact?.founded_year ?? "") || "") || "N/A"

  const toArray = (v: unknown): unknown[] => {
    if (!v) return []
    if (Array.isArray(v)) return v
    if (typeof v === "string") {
      return v
        .split(/[,;]/)
        .map((x) => x.trim())
        .filter((x) => x)
    }
    return []
  }
  const departmentsArr: string[] = toArray(contact?.departments ?? contact?.department).filter((x): x is string => typeof x === "string")
  const seniorityDisplay = String(contact?.seniority ?? contact?.seniority_level ?? "") || "N/A"

  const contactCity = contact?.city ?? contact?.location?.city ?? ""
  const contactState = contact?.state ?? contact?.location?.state ?? ""
  const contactCountry = contact?.country ?? contact?.location?.country ?? ""

  // --- Logic Update for Emails/Phones ---

  // Emails
  const rawEmails: Array<string | { email?: string; address?: string; type?: string }> =
    contact?.emails || (contact?.email ? [{ address: contact.email, type: "work" }] : [])
  const emailsList = Array.isArray(rawEmails)
    ? rawEmails.map((e) => (typeof e === "string" ? { address: e, type: "work" } : { address: e.email || e.address, type: e.type || "work" }))
    : []
  const workEmails = emailsList.filter((e) => ["work", "business"].includes((e.type || "").toLowerCase()))
  const personalEmails = emailsList.filter((e) => (e.type || "").toLowerCase() === "personal")
  // Fallback: if no typed emails, treat all remaining as work
  const otherEmails = emailsList.filter((e) => !["work", "business", "personal"].includes((e.type || "").toLowerCase()))

  // Phones
  const rawPhones: Array<string | { number?: string; phone_number?: string; type?: string }> =
    (contact?.phone_numbers as Array<string>) || (contact?.phone_number ? [{ number: contact.phone_number, type: "mobile" }] : [])
  const phonesList = Array.isArray(rawPhones)
    ? rawPhones.map((p) => (typeof p === "string" ? { number: p, type: "mobile" } : { number: p.phone_number || p.number, type: p.type || "mobile" }))
    : []
  const mobilePhones = phonesList.filter((p) => ["mobile", "cell"].includes((p.type || "").toLowerCase()))
  const otherPhones = phonesList.filter((p) => !["mobile", "cell"].includes((p.type || "").toLowerCase()))
  // If no mobile explicitly, but phones exist, treat them as mobile for display if desired, or keep as other
  if (mobilePhones.length === 0 && phonesList.length > 0) mobilePhones.push(...phonesList)

  // Company Phone
  const companyPhone = revealedCompanyPhone ?? resolvedCompany?.company_phone ?? resolvedCompany?.phone_number ?? null

  const sanitizeUrl = (url?: string | null) => {
    if (!url) return null
    const s = String(url)
      .trim()
      .replace(/^[`\s]+|[`\s]+$/g, "")
    return s || null
  }
  const companyLinkedInUrl = sanitizeUrl(resolvedCompany?.company_linkedin_url || resolvedCompany?.linkedin_url || null)

  const handleInsufficientCredits = () => {
    dispatch(setCreditUsageOpen(true))
  }

  // Email verification gating removed; rely on credit balance and backend auth

  const toggleRevealEmail = async () => {
    if (revealedFields.has("email")) return
    const id = contact?._id
    if (!id) return

    // Check if user has enough credits (1 credit for email reveal)
    const balance = billingUsage?.balance as number | undefined
    if (balance !== undefined && balance < 1) {
      handleInsufficientCredits()
      return
    }

    const requestId = crypto && "randomUUID" in crypto ? crypto.randomUUID() : `${Date.now()}`
    try {
      const res = await revealContact({ id, requestId, revealEmail: true }).unwrap()
      if (res.revealed) {
        setRevealedEmail(res.contact.email)
        const newRevealed = new Set(revealedFields)
        newRevealed.add("email")
        setRevealedFields(newRevealed)
      } else {
        showAlert("Reveal unavailable", "No email available to reveal", "warning", 4000)
      }
    } catch (e: unknown) {
      const status = (e as { status?: number })?.status
      const dataError =
        typeof e === "object" && e !== null && "data" in (e as Record<string, unknown>)
          ? (e as { data?: { error?: string } }).data?.error || null
          : null
      if (status === 402 || dataError === "INSUFFICIENT_CREDITS") {
        handleInsufficientCredits()
        return
      }
      showAlert("Reveal failed", "Unable to reveal email", "error", 5000)
    }
  }

  const toggleRevealPhone = async () => {
    if (revealedFields.has("phone")) return
    const id = contact?._id
    if (!id) return

    // Check if user has enough credits (4 credits for phone reveal)
    const balance = billingUsage?.balance as number | undefined
    if (balance !== undefined && balance < 4) {
      handleInsufficientCredits()
      return
    }

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
      const status = (e as { status?: number })?.status
      const dataError =
        typeof e === "object" && e !== null && "data" in (e as Record<string, unknown>)
          ? (e as { data?: { error?: string } }).data?.error || null
          : null
      if (status === 402 || dataError === "INSUFFICIENT_CREDITS") {
        handleInsufficientCredits()
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
    const id = contact?._id
    if (!id) return
    // Simple approach: try to reveal email and phone on contact, then company phone
    // Note: user said "person reveal all email in 1 credit change", "phone... 4 credits"
    // So separate calls are fine or unwrap one by one.
    if (emailsList.length > 0 && !revealedFields.has("email")) await toggleRevealEmail()
    if (phonesList.length > 0 && !revealedFields.has("phone")) await toggleRevealPhone()
    if (companyPhone && !revealedFields.has("company_phone")) await toggleRevealCompanyPhone()
  }

  const maskEmail = (email: string) => {
    if (!email) return "N/A"
    const at = email.indexOf("@")
    if (at === -1) return "N/A"
    const domain = email.slice(at + 1)
    return `• • • • @${domain}`
  }

  const maskPhone = (phone: string) => {
    if (!phone) return "N/A"
    const digits = phone.replace(/\D/g, "")
    const lastFour = digits.slice(-4)
    return `• • • • ${lastFour}`
  }

  // Display placeholders for primary if not revealed
  const primaryEmail = (revealedEmail ?? contact?.email ?? emailsList?.[0]?.address ?? null) as string | null
  const primaryPhone = (revealedPhone ?? contact?.phone_number ?? phonesList?.[0]?.number ?? null) as string | null

  return (
    <div className="flex h-full w-[424px] flex-col border-l border-gray-200 bg-white shadow-sm">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-gray-200 p-5">
        <span className="text-base font-semibold text-gray-900">Information</span>
        <Button variant="ghost" size="icon" className="size-8 rounded-md hover:bg-gray-100" onClick={onClose} aria-label="Close panel">
          <X className="size-4" />
        </Button>
      </div>

      {/* Scrollable Content */}
      <div className="flex-1 overflow-y-auto">
        {/* Contact Profile Section */}
        {!hideContactFields && (
          <div className="space-y-5 p-5">
            <div className="flex items-center gap-4">
              {/* Added 'flex items-center justify-center' to center the icon */}
              <Avatar className="flex size-12 items-center justify-center border border-gray-200 bg-gray-50">
                <User className="size-6 text-gray-600" />
              </Avatar>

              <div className="min-w-0 flex-1">
                <h3 className="truncate text-base font-semibold text-gray-900">{contact?.full_name}</h3>
                <p className="truncate text-sm text-gray-600">{contact?.title}</p>
              </div>
            </div>

            <div className="flex gap-3">
              <Button onClick={revealAllInfo} className="flex-1 rounded-lg bg-blue-600 py-2 font-medium text-white hover:bg-blue-700">
                Reveal all info
              </Button>
              <Button
                variant="outline"
                className="flex-1 rounded-lg border-gray-300 py-2 font-medium text-gray-700 hover:bg-gray-50"
                onClick={() => {
                  dispatch(
                    openCompanyDetails({
                      company: resolvedCompany || {
                        _id: "",
                        website: contact?.website || "",
                        company: contact?.company || "",
                        company_linkedin_url: null,
                        industry: "",
                        keywords: [],
                        location: { country: "" },
                        founded_year: contact?.founded_year || null,
                        revenue: contact?.revenue || null,
                        company_headcount: typeof headcountRaw === "number" ? (headcountRaw as number) : null
                      },
                      contact: contact || undefined
                    })
                  )
                  dispatch(closeContactInfo())
                }}
              >
                <User className="mr-2 size-4" />
                View full profile
              </Button>

              {!!contact?.linkedin_url && (
                <a
                  href={contact?.linkedin_url}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="flex items-center justify-center rounded-lg border border-gray-300 bg-white p-2.5 transition-colors hover:bg-gray-50"
                >
                  <LinkedinIcon className="size-5 text-blue-600" />
                </a>
              )}
            </div>
          </div>
        )}

        {!!hideContactFields && (
          <div className="space-y-5 p-5">
            <div className="flex items-center gap-4">
              <Avatar className="size-12 items-center justify-center border border-gray-200 bg-gray-50">
                {logoUrl ? (
                  <img src={logoUrl} alt={company?.company || "Company logo"} className="size-8 object-contain" />
                ) : (
                  <Building className="size-6 text-gray-400" />
                )}
              </Avatar>
              <div className="min-w-0 flex-1">
                <h3 className="truncate text-base font-semibold text-gray-900">{resolvedCompany?.company || company?.company}</h3>
                <p className="truncate text-sm text-gray-600">{resolvedCompany?.location?.country || company?.location.country}</p>
              </div>
            </div>
            <div className="flex gap-2">
              <Button variant="outline" className="flex-1 rounded-lg border-blue-600 py-2 font-medium text-blue-600 hover:bg-blue-50">
                Add company as filter
              </Button>
              {!!normalizedDomain && (
                <a href={`https://${normalizedDomain}`} target="_blank" rel="noopener noreferrer">
                  <Button variant="outline" size="icon" className="size-10 rounded-lg border-gray-300 hover:bg-gray-50">
                    <LinkIcon className="size-5 text-gray-700" />
                  </Button>
                </a>
              )}
              {!!companyLinkedIn && (
                <a href={companyLinkedIn} target="_blank" rel="noopener noreferrer">
                  <Button variant="outline" size="icon" className="size-10 rounded-lg border-gray-300 hover:bg-gray-50">
                    <LinkedinIcon className="size-5 text-blue-600" />
                  </Button>
                </a>
              )}
            </div>
          </div>
        )}

        {/* Contact Info Sections */}
        {!hideContactFields && (
          <>
            {/* Phone Section */}
            <div className="border-t border-gray-100">
              <div className="bg-gray-50 px-5 py-3">
                <h4 className="text-xs font-medium uppercase tracking-wide text-gray-500">Phone</h4>
              </div>
              <div className="space-y-4 p-5 pt-3">
                {mobilePhones.map((phone, idx) => (
                  <div key={idx} className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <Phone className="size-4 text-gray-500" />
                      <span className="text-sm text-gray-600">Mobile Phone</span>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-medium text-gray-900">
                        {revealedFields.has("phone") ? phone.number : maskPhone(phone.number || "")}
                      </span>
                      <button
                        onClick={toggleRevealPhone}
                        className="text-blue-500 transition-colors hover:text-blue-600"
                        aria-label="Reveal phone number"
                      >
                        <Eye className="mr-1 size-4" />
                        <span className="text-xs">4 credits</span>
                      </button>
                    </div>
                  </div>
                ))}

                {!!companyPhone && (
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <Phone className="size-4 text-gray-500" />
                      <span className="text-sm text-gray-600">Company Phone</span>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-medium text-gray-900">
                        <span className="text-sm font-medium text-gray-900">
                          {revealedFields.has("company_phone") ? revealedCompanyPhone || companyPhone : maskPhone(companyPhone)}
                        </span>
                      </span>
                      <button onClick={toggleRevealCompanyPhone} className="text-blue-500 hover:text-blue-600" aria-label="Reveal company phone">
                        <Eye className="mr-1 size-4" />
                        
                      </button>
                    </div>
                  </div>
                )}
              </div>
            </div>

            {/* Email Section */}
            <div className="border-t border-gray-100">
              <div className="bg-gray-50 px-5 py-3">
                <h4 className="text-xs font-medium uppercase tracking-wide text-gray-500">Email</h4>
              </div>
              <div className="space-y-4 p-5 pt-3">
                {workEmails.map((email, idx) => (
                  <div key={idx} className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <Mail className="size-4 text-gray-500" />
                      <span className="text-sm text-gray-600">Work Email</span>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-medium text-gray-900">
                        {revealedFields.has("email") ? email.address : maskEmail(email.address || "")}
                      </span>
                      <button onClick={toggleRevealEmail} className="text-blue-500 transition-colors hover:text-blue-600" aria-label="Reveal email">
                        <span className="text-[10px] text-gray-400">1 credits</span>
                        <Eye className="mr-1 size-4" />
                        
                      </button>
                    </div>
                  </div>
                ))}

                {personalEmails.map((email, idx) => (
                  <div key={idx} className="flex items-center justify-between">
                    <div className="flex items-center gap-3">
                      <Mail className="size-4 text-gray-500" />
                      <span className="text-sm text-gray-600">Personal Email</span>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-medium text-gray-900">
                        {revealedFields.has("email") ? email.address : maskEmail(email.address || "")}
                      </span>
                      <button onClick={toggleRevealEmail} className="text-blue-500 transition-colors hover:text-blue-600" aria-label="Reveal email">
                        <Eye className="mr-1 size-4" />
                        <span className="text-xs">1 credit</span>
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </>
        )}

        {/* Company Information */}
        <div className="border-t border-gray-100">
          <div className="bg-gray-50 px-5 py-3">
            <h4 className="text-xs font-medium uppercase tracking-wide text-gray-500">Company Information</h4>
          </div>
          <div className="p-5">
            {!hideContactFields && (
              <>
                <div className="mb-4 flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <div className="flex size-8 items-center justify-center rounded-md bg-gray-50">
                      {logoUrl ? (
                        <img src={logoUrl} alt={contact?.company || "Company logo"} className="size-5 object-contain" />
                      ) : (
                        <Building className="size-4 text-gray-500" />
                      )}
                    </div>
                    <div>
                      <h5 className="text-sm font-medium text-gray-900">{contact?.company}</h5>
                      {!!normalizedDomain && <p className="text-xs text-gray-500">{normalizedDomain}</p>}
                    </div>
                  </div>
                  {!!normalizedDomain && (
                    <a
                      href={`https://${normalizedDomain}`}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="text-gray-500 transition-colors hover:text-gray-700"
                    >
                      <LinkIcon className="size-5" />
                    </a>
                  )}
                </div>

                <div className="flex gap-3">
                  <Button
                    variant="outline"
                    className="flex-1 rounded-lg border-blue-600 py-2 font-medium text-blue-600 hover:bg-blue-50"
                    onClick={() => {
                      onClose?.()
                      const companyFromContact: CompanyAttributes = {
                        _id: "",
                        website: contact?.website || "",
                        company: contact?.company || "",
                        company_linkedin_url: null,
                        industry: "",
                        keywords: [],
                        location: { country: "" },
                        founded_year: contact?.founded_year || null,
                        revenue: contact?.revenue || null,
                        company_headcount: typeof headcountRaw === "number" ? (headcountRaw as number) : null
                      }
                      dispatch(openContactInfoForCompany((resolvedCompany as CompanyAttributes) || companyFromContact))
                    }}
                  >
                    View Company
                  </Button>
                  <Button variant="outline" className="flex-1 rounded-lg border-gray-300 py-2 font-medium text-gray-700 hover:bg-gray-50">
                    {logoUrl ? (
                      <img src={logoUrl} alt={contact?.company || "Company logo"} className="mr-2 size-4 rounded-sm" />
                    ) : (
                      <Building className="size-6 text-gray-400" />
                    )}
                    Add to list
                  </Button>
                </div>
              </>
            )}
          </div>
        </div>

        {/* Company Details Grid */}
        <div className="border-t border-gray-100">
          <div className="grid grid-cols-2 gap-3 p-5">
            {!!companyPhone && (
              <div>
                <p className="mb-1 text-xs text-gray-500">Phone</p>
                <div className="flex items-center gap-2">
                  <p className="text-sm font-medium text-gray-900">
                    {revealedFields.has("company_phone") ? revealedCompanyPhone || companyPhone : maskPhone(companyPhone)}
                  </p>
                  <button onClick={toggleRevealCompanyPhone} className="text-blue-500 hover:text-blue-600" aria-label="Reveal company phone">
                    <Eye className="mr-1 size-4" />
                  
                  </button>
                </div>
              </div>
            )}
            {(() => {
              const direct = (resolvedCompany as { email?: string | null } | null)?.email
              const list = resolvedCompany?.emails || []
              let email: string | null = null
              if (direct) email = direct
              else {
                for (const e of list) {
                  if (typeof e === "string" && e) {
                    email = e
                    break
                  }
                  if (typeof e === "object" && e?.address) {
                    email = e.address
                    break
                  }
                }
              }
              return email ? (
                <div>
                  <p className="mb-1 text-xs text-gray-500">Email</p>
                  <div className="flex items-center gap-2">
                    <p className="truncate text-sm font-medium text-gray-900">
                      {revealedFields.has("company_email") ? revealedCompanyEmail || email : maskEmail(email)}
                    </p>
                    <button onClick={toggleRevealCompanyEmail} className="text-blue-500 hover:text-blue-600" aria-label="Reveal company email">
                      <Eye className="mr-1 size-4" />
                    
                    </button>
                  </div>
                </div>
              ) : null
            })()}
            {revenueDisplay !== "N/A" && (
              <div>
                <p className="mb-1 text-xs text-gray-500">Revenue</p>
                <p className="text-sm font-medium text-gray-900">{revenueDisplay}</p>
              </div>
            )}
            {!!(resolvedCompany?.founded_year ?? null) && (
              <div>
                <p className="mb-1 text-xs text-gray-500">Founded</p>
                <p className="text-sm font-medium text-gray-900">{String(resolvedCompany?.founded_year)}</p>
              </div>
            )}
            {headcountDisplay !== "N/A" && (
              <div>
                <p className="mb-1 text-xs text-gray-500">Headcount</p>
                <p className="text-sm font-medium text-gray-900">{headcountDisplay}</p>
              </div>
            )}
            {industryDisplay !== "N/A" && (
              <div>
                <p className="mb-1 text-xs text-gray-500">Industry</p>
                <p className="truncate text-sm font-medium text-gray-900">{industryDisplay}</p>
              </div>
            )}
          </div>
        </div>

        {/* Company Description */}
        {!!descriptionText && (
          <div className="border-t border-gray-100 p-5">
            <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
              <p className="text-sm leading-relaxed text-gray-700">{descriptionText}</p>
            </div>
          </div>
        )}

        {/* Employee Details */}
        <div className="border-t border-gray-100">
          <div className="bg-gray-50 px-5 py-3">
            <h4 className="text-xs font-medium uppercase tracking-wide text-gray-500">Employee Details</h4>
          </div>
          <div className="p-5">
            <EmployeesDetails headcount={headcountRaw as number | null} />
          </div>
        </div>

        {/* Specialties */}
        <div className="border-t border-gray-100">
          <div className="bg-gray-50 px-5 py-3">
            <h4 className="text-xs font-medium uppercase tracking-wide text-gray-500">Specialties</h4>
          </div>
          <div className="p-5">
            <div className="flex flex-wrap gap-2">
              {(() => {
                const spec = [...toArray(resolvedCompany?.keywords), ...toArray(contact?.keywords)].filter((x): x is string => typeof x === "string")
                return spec.length > 0 ? (
                  spec.map((kw) => (
                    <Badge
                      key={kw}
                      variant="secondary"
                      className="rounded-full border border-gray-200 bg-white px-3 py-1 text-xs font-medium text-gray-700 hover:bg-gray-50"
                    >
                      {kw}
                    </Badge>
                  ))
                ) : (
                  <span className="text-sm text-gray-500">No specialties listed</span>
                )
              })()}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}

export default ContactInformation
