import SparkleLine from "@/static/media/icons/sparkle-line.svg?react"
import { useState, useEffect } from "react"
import type { ReactNode } from "react"
import { useAppDispatch } from "@/app/hooks/reduxHooks"
import { closeCompanyDetails, selectCompanyDetails, selectCompanyDetailsContact } from "@/features/searchTable/slice/companyDetailsSlice"
import { useSelector } from "react-redux"
import { useAppSelector } from "@/app/hooks/reduxHooks"
import { setCreditUsageOpen } from "@/features/settings/slice/settingSlice"
import { useCompanyLogoQuery, useRevealContactMutation, useRevealCompanyMutation, useBillingUsageQuery } from "@/features/searchTable/slice/apiSlice"
import { useAlert } from "@/app/hooks/alertHooks"
import { CompanyAttributes, ContactAttributes } from "@/interface/searchTable/search"
import { Avatar } from "@radix-ui/react-avatar"
import {
  Eye,
  Globe,
  Mail,
  Phone,
  Twitter,
  User,
  X,
  Building,
  MapPin,
  DollarSign,
  Calendar,
  Briefcase,
  Users,
  Hash,
  Target,
  Link,
  Tag
} from "lucide-react"
import LinkedinIcon from "../../static/media/icons/linkedin_icon.svg?react"
import FacebookIcon from "../../static/media/icons/fb-icon.svg?react"
import { Badge } from "./badge"
import { Button } from "./button"
import { Card, CardContent, CardHeader, CardTitle } from "./card"

const ContactDetails = () => {
  const { showAlert } = useAlert()
  const [revealedFields, setRevealedFields] = useState<Set<string>>(new Set())
  const [revealContact] = useRevealContactMutation()
  const [revealCompany] = useRevealCompanyMutation()
  const dispatch = useAppDispatch()
  const { data: billingUsage } = useBillingUsageQuery()
  const user = useAppSelector((s) => s.setting.user)

  const company = useSelector(selectCompanyDetails)
  const contact = useSelector(selectCompanyDetailsContact)

  const [enhancedContact, setEnhancedContact] = useState<ContactAttributes | null>(contact)
  const [enhancedCompany, setEnhancedCompany] = useState<CompanyAttributes | null>(company)

  useEffect(() => {
    setEnhancedContact(contact)
    setEnhancedCompany(company)
    setRevealedFields(new Set())
  }, [contact?._id, contact, company?._id, company])

  // Helper functions
  const currentCompany = enhancedCompany || company
  const getNormalizedDomain = () => {
    const raw = currentCompany?.domain || currentCompany?.company_domain || currentCompany?.website || ""
    if (!raw) return null
    return (
      String(raw)
        .replace(/^https?:\/\//, "")
        .replace(/^www\./, "")
        .replace(/\/$/, "")
        .trim() || null
    )
  }

  const maskEmail = (email: unknown): string => {
    if (typeof email !== "string" || !email) return "••••"
    const parts = email.split("@")
    const domain = parts[1]
    return domain ? `••••@${domain}` : email
  }

  const maskPhone = (phone: string): string => {
    if (!phone) return "••••"
    const lastFour = phone.slice(-4)
    return `•••• ${lastFour}`
  }

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

  const formatDate = (val: unknown): string => {
    if (!val) return ""
    const s = String(val).trim()
    if (!s) return ""
    const d = new Date(s)
    if (isNaN(d.getTime())) return s
    return new Intl.DateTimeFormat(undefined, { year: "numeric", month: "short", day: "2-digit" }).format(d)
  }

  const toArray = (val: unknown): string[] => {
    if (!val) return []
    if (Array.isArray(val)) return val.filter((v): v is string => typeof v === "string")
    if (typeof val === "string")
      return val
        .split(/[,;\n]/)
        .map((s) => s.trim())
        .filter(Boolean)
    return []
  }

  // Data processing
  const normalizedDomain = getNormalizedDomain()
  const { data: logoData } = useCompanyLogoQuery({ domain: normalizedDomain || "" }, { skip: !normalizedDomain })
  const logoUrl = logoData?.logo_url || currentCompany?.logo_url || null
  const resolvedContact = enhancedContact || contact

  // Contact data
  const contactDomain = resolvedContact?.website || normalizedDomain
  const contactSeniority = resolvedContact?.seniority || resolvedContact?.seniority_level
  const contactDepartments = toArray(resolvedContact?.departments ?? resolvedContact?.department)

  // Contact location (from your data structure)
  const contactCity = resolvedContact?.city || (resolvedContact?.location as { city?: string })?.city
  const contactState = resolvedContact?.state || (resolvedContact?.location as { state?: string })?.state
  const contactCountry = resolvedContact?.country || (resolvedContact?.location as { country?: string })?.country

  // Contact emails & phones
  const contactEmailsAll: Array<{ address: string; type?: string }> = (() => {
    const emails = resolvedContact?.emails || []
    const workEmail = resolvedContact?.work_email ? [{ address: resolvedContact.work_email, type: "work" }] : []
    const personalEmail = resolvedContact?.personal_email ? [{ address: resolvedContact.personal_email, type: "personal" }] : []
    const email = resolvedContact?.email ? [{ address: resolvedContact.email }] : []
    const merged = [...emails, ...workEmail, ...personalEmail, ...email]
      .map((e) => (typeof e === "string" ? { address: e } : (e as { address?: string; type?: string })))
      .filter((e) => !!e.address)
    // Deduplicate by address
    const seen = new Map<string, { address: string; type?: string }>()
    merged.forEach((e) => {
      const key = e.address!.toLowerCase()
      if (!seen.has(key)) {
        seen.set(key, { address: e.address!, type: e.type })
      }
    })
    return Array.from(seen.values())
  })()

  const workEmails = contactEmailsAll.filter((e) => !e.type || /work|business/i.test(e.type))
  const personalEmails = contactEmailsAll.filter((e) => !!e.type && /personal/i.test(e.type))

  const contactPhonesAll: Array<{ number: string; type?: string }> = (() => {
    const phones = (resolvedContact as { phones?: Array<string | { number?: string; phone_number?: string; type?: string }> })?.phones || []
    const phoneNumbers = resolvedContact?.phone_numbers || []
    const mobile = resolvedContact?.mobile_phone ? [{ number: resolvedContact.mobile_phone, type: "mobile" }] : []
    const phone = resolvedContact?.phone_number ? [{ number: resolvedContact.phone_number }] : []
    const merged = [...phones, ...phoneNumbers, ...mobile, ...phone]
      .map((p) => (typeof p === "string" ? { number: p } : (p as { number?: string; phone_number?: string; type?: string })))
      .map((p) => ({ number: p.phone_number || p.number || "", type: p.type }))
      .filter((p) => !!p.number)
    // Deduplicate by number (remove non-digits for comparison)
    const seen = new Map<string, { number: string; type?: string }>()
    merged.forEach((p) => {
      const key = p.number.replace(/\D/g, '')
      if (key && !seen.has(key)) {
        seen.set(key, { number: p.number, type: p.type })
      }
    })
    return Array.from(seen.values())
  })()

  const mobilePhones = contactPhonesAll.filter((p) => !p.type || /mobile/i.test(p.type))
  const directPhones = contactPhonesAll.filter((p) => !!p.type && /direct/i.test(p.type))

  // Company data
  const headcount =
    currentCompany?.number_of_employees || currentCompany?.company_headcount || (currentCompany as { employee_count?: number })?.employee_count
  const headcountDisplay = headcount ? headcount.toLocaleString() : "N/A"
  const industryDisplay = currentCompany?.industry || (currentCompany as { business_category?: string })?.business_category || "N/A"
  const revenueDisplay = formatRevenue(currentCompany?.annual_revenue_usd || currentCompany?.revenue)
  const foundedYearDisplay = currentCompany?.founded_year ? String(currentCompany.founded_year) : "N/A"

  const companyPhones: string[] = [
    ...toArray(currentCompany?.phone_number || currentCompany?.phone),
    ...(currentCompany?.phone_numbers || []).filter((p): p is string => typeof p === "string")
  ].filter(Boolean)

  const companyEmails: string[] = toArray(currentCompany?.emails)

  const technologies = toArray(currentCompany?.technologies)
  const keywords = toArray(currentCompany?.keywords)
  const specialties = [...keywords, ...technologies]

  const socialMedia = currentCompany?.social_media || {}
  const linkedinUrl = currentCompany?.linkedin_url || socialMedia.linkedin_url
  const twitterUrl = currentCompany?.twitter_url || socialMedia.twitter_url
  const facebookUrl = currentCompany?.facebook_url || socialMedia.facebook_url

  // Company location (from your data structure)
  const companyStreet = currentCompany?.street || (currentCompany?.location as { street?: string })?.street
  const companyCity = currentCompany?.city || (currentCompany?.location as { city?: string })?.city
  const companyState = currentCompany?.state || (currentCompany?.location as { state?: string })?.state
  const companyCountry = currentCompany?.country || currentCompany?.location?.country
  const companyPostal = currentCompany?.postal_code || (currentCompany?.location as { postal_code?: string })?.postal_code
  const companyAddress = [companyStreet, companyCity, companyState, companyPostal, companyCountry].filter(Boolean).join(", ")

  // Funding data
  const totalFundingUsd = formatRevenue(
    (currentCompany as { total_funding_usd?: unknown })?.total_funding_usd ??
      (currentCompany as { total_funding?: unknown })?.total_funding ??
      (currentCompany as { funding?: { total_funding?: unknown } })?.funding?.total_funding ??
      null
  )
  const latestFunding =
    (currentCompany as { latest_funding?: string | null })?.latest_funding ??
    (currentCompany as { funding?: { latest_funding?: string | null } })?.funding?.latest_funding ??
    null
  const latestFundingAmount = formatRevenue(
    (currentCompany as { latest_funding_amount?: unknown })?.latest_funding_amount ??
      (currentCompany as { latest_funding_usd?: unknown })?.latest_funding_usd ??
      (currentCompany as { funding?: { latest_funding_amount?: unknown } })?.funding?.latest_funding_amount ??
      null
  )
  const lastRaisedAtRaw =
    (currentCompany as { last_raised_at?: string | null })?.last_raised_at ??
    (currentCompany as { funding?: { last_raised_at?: string | null } })?.funding?.last_raised_at ??
    null
  const lastRaisedAt = lastRaisedAtRaw ? formatDate(lastRaisedAtRaw) : ""
  const sicCode = (currentCompany as { sic_code?: string | null })?.sic_code || ""

  // AI Summary fallback
  const getAISummary = () => {
    if (currentCompany?.short_description) return currentCompany.short_description
    if (currentCompany?.description) return currentCompany.description
    if ((currentCompany as { business_description?: string })?.business_description)
      return (currentCompany as { business_description?: string }).business_description!

    const parts = []
    if (currentCompany?.company) parts.push(currentCompany.company)
    if (industryDisplay !== "N/A") parts.push(`operates in ${industryDisplay}`)
    if (companyCountry) parts.push(`based in ${companyCountry}`)
    if (headcountDisplay !== "N/A") parts.push(`with ${headcountDisplay} employees`)
    if (specialties.length > 0) parts.push(`specializing in ${specialties.slice(0, 3).join(", ")}`)
    if (normalizedDomain) parts.push(`website: ${normalizedDomain}`)

    return parts.join(", ") || "Company information not available."
  }

  // Reveal logic
  const handleContactReveal = async (fieldType: "email" | "phone", index?: number) => {
    if (!resolvedContact?._id) return
    
    // Check credits: 1 credit for email, 4 credits for phone
    const requiredCredits = fieldType === "email" ? 1 : 4
    const balance = billingUsage?.balance as number | undefined
    if (balance !== undefined && balance < requiredCredits) {
      dispatch(setCreditUsageOpen(true))
      return
    }
    
    const fieldKey = fieldType === "email" ? `contact_email_${index ?? "main"}` : `contact_phone_${index ?? "main"}`
    const requestId = crypto.randomUUID?.() || `${Date.now()}`
    try {
      const res = await revealContact({
        id: resolvedContact._id,
        requestId,
        revealEmail: fieldType === "email",
        revealPhone: fieldType === "phone"
      }).unwrap()

      if (res.revealed && resolvedContact) {
        const updatedContact = { ...resolvedContact }
        if (fieldType === "phone" && res.contact.phone) {
          updatedContact.phone_number = res.contact.phone
        }
        if (fieldType === "email" && res.contact.email) {
          updatedContact.email = res.contact.email
        }
        setEnhancedContact(updatedContact)

        setRevealedFields((prev) => new Set([...prev, fieldKey]))
      } else {
        showAlert("Information unavailable", `No ${fieldType} available to reveal`, "warning", 4000)
      }
    } catch (error: unknown) {
      // Only handle insufficient credits for phone reveals
      if (fieldType === "phone") {
        const status = (error as { status?: number })?.status
        const dataError =
          typeof error === "object" && error !== null && "data" in (error as Record<string, unknown>)
            ? (error as { data?: { error?: string } }).data?.error || null
            : null
        if (status === 402 || dataError === "INSUFFICIENT_CREDITS") {
          dispatch(setCreditUsageOpen(true))
          return
        }
      }
      showAlert("Reveal failed", "Please try again later", "error", 5000)
    }
  }

  const handleCompanyReveal = async (fieldType: "email" | "phone", index?: number) => {
    if (!company?._id) return
    
    // Company reveals are free - no credit check needed
    const fieldKey = fieldType === "email" ? `company_email_${index ?? "main"}` : `company_phone_${index ?? "main"}`

    try {
      const res = await revealCompany({
        id: company._id,
        revealEmail: fieldType === "email",
        revealPhone: fieldType === "phone"
      }).unwrap()

      if (res.revealed) {
        // Update enhanced company state
        const updatedCompany = { ...currentCompany, ...res.company }
        if (fieldType === "phone" && res.company.phone) {
          // ensure phone is added to phones list if not present
          const existingPhones = toArray(updatedCompany.phone_numbers)
          if (!existingPhones.includes(res.company.phone)) {
            updatedCompany.phone_numbers = [res.company.phone, ...existingPhones] // prepend to make it show up
          }
        }
        if (fieldType === "email" && res.company.email) {
          const existingEmails = toArray(updatedCompany.emails)
          if (!existingEmails.includes(res.company.email)) {
            updatedCompany.emails = [res.company.email, ...existingEmails]
          }
        }
        setEnhancedCompany(updatedCompany as CompanyAttributes)

        const fieldKey = fieldType === "email" ? `company_email_${index ?? "main"}` : `company_phone_${index ?? "main"}`
        setRevealedFields((prev) => new Set([...prev, fieldKey]))
      }
    } catch (error: unknown) {
      // Company reveals are free - no credit handling needed
      showAlert("Reveal failed", "Please try again later", "error", 5000)
    }
  }

  const revealAllInfo = async () => {
    // Reveal contact info
    if (resolvedContact?._id) {
      try {
        const requestId = crypto.randomUUID?.() || `${Date.now()}`
        const res = await revealContact({
          id: resolvedContact._id,
          requestId,
          revealEmail: true,
          revealPhone: true
        }).unwrap()

        if (res.revealed) {
          const updatedContact = { ...resolvedContact }
          if (res.contact.phone) {
            updatedContact.phone_number = res.contact.phone
          }
          if (res.contact.email) updatedContact.email = res.contact.email
          setEnhancedContact(updatedContact)
        }
      } catch (_error: unknown) {
        void 0
      }
    }

    // Reveal company info
    if (company?._id) {
      try {
        await revealCompany({
          id: company._id,
          revealEmail: true,
          revealPhone: true
        }).unwrap()
      } catch (_error: unknown) {
        void 0
      }
    }

    // Add all fields to revealed set
    const allFields = [
      ...contactEmailsAll.map((_, i) => `contact_email_${i}`),
      ...contactPhonesAll.map((_, i) => `contact_phone_${i}`),
      ...companyEmails.map((_, i) => `company_email_${i}`),
      ...companyPhones.map((_, i) => `company_phone_${i}`)
    ]
    setRevealedFields((prev) => new Set([...prev, ...allFields]))
  }

  if (!company) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <div className="text-center">
          <Building className="mx-auto size-12 text-gray-400" />
          <p className="mt-4 text-gray-600">No company selected</p>
        </div>
      </div>
    )
  }

  return (
    <div className="min-h-screen bg-gradient-to-b from-gray-50 to-white">
      {/* Header */}
      <div className="sticky top-0 z-50 border-b border-gray-200 bg-white/95 px-6 py-4 shadow-sm backdrop-blur-sm">
        <div className="mx-auto flex max-w-7xl items-center justify-between">
          <div className="flex items-center gap-4">
            <Avatar className="flex size-12 items-center justify-center rounded-xl border-2 border-gray-100 bg-white p-2 shadow-sm">
              {logoUrl ? (
                <img src={logoUrl} alt={company.company} className="size-8 object-contain" />
              ) : (
                <Building className="size-8 text-gray-400" />
              )}
            </Avatar>
            <div>
              <h1 className="text-xl font-bold text-gray-900">{company.company}</h1>
              <p className="text-sm text-gray-600">{companyCountry || "Global"}</p>
            </div>
          </div>

          <div className="flex items-center gap-3">
            <Button onClick={revealAllInfo} className="bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700">
              <Eye className="mr-2 size-4" />
              Reveal All
            </Button>
            <Button variant="ghost" size="icon" className="size-10 rounded-lg hover:bg-gray-100" onClick={() => dispatch(closeCompanyDetails())}>
              <X className="size-4" />
            </Button>
          </div>
        </div>
      </div>

      {/* Main Content */}
      <div className="mx-auto max-w-7xl px-6 py-8">
        <div className="grid grid-cols-1 gap-8 lg:grid-cols-3">
          {/* Left Column - 2/3 width */}
          <div className="space-y-8 lg:col-span-2">
            {/* Contact Information Card */}
            {!!resolvedContact && (
              <Card className="overflow-hidden border border-gray-200 shadow-sm transition-shadow duration-200 hover:shadow-md">
                <CardHeader className="border-b border-gray-100 bg-gradient-to-r from-gray-50 to-white px-6 py-5">
                  <CardTitle className="flex items-center gap-3">
                    <div className="flex size-10 items-center justify-center rounded-lg bg-blue-50">
                      <User className="size-5 text-blue-600" />
                    </div>
                    <div>
                      <h2 className="text-lg font-bold text-gray-900">Contact Profile</h2>
                      <p className="text-sm text-gray-600">Professional details & contact information</p>
                    </div>
                  </CardTitle>
                </CardHeader>
                <CardContent className="px-6 py-5">
                  <div className="space-y-6">
                    {/* Basic Info */}
                    <div className="space-y-4">
                      <div className="flex items-start justify-between">
                        <div>
                          <h3 className="text-xl font-bold text-gray-900">{resolvedContact.full_name}</h3>
                          <p className="text-gray-600">{resolvedContact.title}</p>
                        </div>
                        {!!resolvedContact?.linkedin_url && (
                          <a href={resolvedContact.linkedin_url} target="_blank" rel="noopener noreferrer">
                            <Button variant="outline" size="icon" className="size-10 rounded-lg">
                              <LinkedinIcon className="size-5" />
                            </Button>
                          </a>
                        )}
                      </div>

                      <div className="grid grid-cols-2 gap-4">
                        {!!contactDomain && <InfoBox label="Domain" value={contactDomain} icon={<Globe className="size-4" />} />}
                        {!!contactSeniority && <InfoBox label="Seniority" value={contactSeniority} icon={<Target className="size-4" />} />}
                        {!!(contactCity || contactState || contactCountry) && (
                          <div className="col-span-2">
                            <InfoBox
                              label="Location"
                              value={[contactCity, contactState, contactCountry].filter(Boolean).join(", ")}
                              icon={<MapPin className="size-4" />}
                            />
                          </div>
                        )}
                      </div>

                      {contactDepartments.length > 0 && (
                        <div>
                          <p className="mb-2 text-xs font-medium text-gray-500">Departments</p>
                          <div className="flex flex-wrap gap-2">
                            {contactDepartments.map((d) => (
                              <Badge
                                key={d}
                                variant="secondary"
                                className="rounded-lg border border-blue-100 bg-blue-50 px-3 py-1.5 text-sm font-medium text-blue-700"
                              >
                                {d}
                              </Badge>
                            ))}
                          </div>
                        </div>
                      )}
                    </div>

                    {/* Contact Methods */}
                    <div className="space-y-6">
                      {/* Emails Section */}
                      {(workEmails.length > 0 || personalEmails.length > 0) && (
                        <div className="space-y-4">
                          <div className="flex items-center justify-between">
                            <h4 className="flex items-center gap-2 font-semibold text-gray-900">
                              <Mail className="size-4 text-gray-600" />
                              Email Addresses
                            </h4>
                          </div>

                          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            {/* Work Emails */}
                            {workEmails.map((email, idx) => (
                              <div key={idx} className="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                <div className="flex items-center justify-between">
                                  <div>
                                    <p className="mb-1 text-xs font-medium text-gray-500">Work Email</p>
                                    <p className="font-medium text-gray-900">
                                      {revealedFields.has(`contact_email_${contactEmailsAll.findIndex((e) => e.address === email.address)}`)
                                        ? email.address
                                        : maskEmail(email.address)}
                                    </p>
                                  </div>
                                  <Button
                                    variant="ghost"
                                    size="sm"
                                    className="size-8 rounded-lg hover:bg-white"
                                    onClick={() =>
                                      handleContactReveal(
                                        "email",
                                        ((): number => {
                                          const i = contactEmailsAll.findIndex((e) => e.address === email.address)
                                          return i >= 0 ? i : idx
                                        })()
                                      )
                                    }
                                  >
                                    <span className="text-[10px] text-gray-400">1 credits</span>
                                    <Eye className="size-3.5" />
                                  </Button>
                                </div>
                              </div>
                            ))}

                            {/* Personal Emails */}
                            {personalEmails.map((email, idx) => (
                              <div key={`p_${idx}`} className="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                <div className="flex items-center justify-between">
                                  <div>
                                    <p className="mb-1 text-xs font-medium text-gray-500">Personal Email</p>
                                    <p className="font-medium text-gray-900">
                                      {revealedFields.has(`contact_email_${contactEmailsAll.findIndex((e) => e.address === email.address)}`)
                                        ? email.address
                                        : maskEmail(email.address)}
                                    </p>
                                  </div>
                                  <Button
                                    variant="ghost"
                                    size="sm"
                                    className="size-8 rounded-lg hover:bg-white"
                                    onClick={() =>
                                      handleContactReveal(
                                        "email",
                                        ((): number => {
                                          const i = contactEmailsAll.findIndex((e) => e.address === email.address)
                                          return i >= 0 ? i : idx
                                        })()
                                      )
                                    }
                                  >
                                    <Eye className="size-3.5" />
                                  </Button>
                                </div>
                              </div>
                            ))}
                          </div>
                        </div>
                      )}

                      {/* Phones Section */}
                      {(mobilePhones.length > 0 || directPhones.length > 0) && (
                        <div className="space-y-4">
                          <div className="flex items-center justify-between">
                            <h4 className="flex items-center gap-2 font-semibold text-gray-900">
                              <Phone className="size-4 text-gray-600" />
                              Phone Numbers
                            </h4>
                          </div>

                          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            {/* Mobile Phones */}
                            {mobilePhones.map((phone, idx) => (
                              <div key={idx} className="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                <div className="flex items-center justify-between">
                                  <div>
                                    <p className="mb-1 text-xs font-medium text-gray-500">Mobile Number</p>
                                    <p className="font-medium text-gray-900">
                                      {revealedFields.has(`contact_phone_${contactPhonesAll.findIndex((p) => p.number === phone.number)}`)
                                        ? phone.number
                                        : maskPhone(phone.number)}
                                    </p>
                                  </div>
                                  <Button
                                    variant="ghost"
                                    size="sm"
                                    className="size-8 rounded-lg hover:bg-white"
                                    onClick={() =>
                                      handleContactReveal(
                                        "phone",
                                        ((): number => {
                                          const i = contactPhonesAll.findIndex((p) => p.number === phone.number)
                                          return i >= 0 ? i : idx
                                        })()
                                      )
                                    }
                                  >
                                    <div className="flex items-center gap-1">
                                      <span className="text-[10px] text-gray-500">4 Credits</span>
                                      <Eye className="size-3.5" />
                                    </div>
                                  </Button>
                                </div>
                              </div>
                            ))}

                            {/* Direct Phones */}
                            {directPhones.map((phone, idx) => (
                              <div key={`d_${idx}`} className="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                <div className="flex items-center justify-between">
                                  <div>
                                    <p className="mb-1 text-xs font-medium text-gray-500">Direct Number</p>
                                    <p className="font-medium text-gray-900">
                                      {revealedFields.has(`contact_phone_${contactPhonesAll.findIndex((p) => p.number === phone.number)}`)
                                        ? phone.number
                                        : maskPhone(phone.number)}
                                    </p>
                                  </div>
                                  <Button
                                    variant="ghost"
                                    size="sm"
                                    className="size-8 rounded-lg hover:bg-white"
                                    onClick={() =>
                                      handleContactReveal(
                                        "phone",
                                        ((): number => {
                                          const i = contactPhonesAll.findIndex((p) => p.number === phone.number)
                                          return i >= 0 ? i : idx
                                        })()
                                      )
                                    }
                                  >
                                    <div className="flex items-center gap-1">
                                      <span className="text-[10px] text-gray-500">4 Credits</span>
                                      <Eye className="size-3.5" />
                                    </div>
                                  </Button>
                                </div>
                              </div>
                            ))}
                          </div>
                        </div>
                      )}
                    </div>
                  </div>
                </CardContent>
              </Card>
            )}

            {/* AI Insights Card */}
            <Card className="overflow-hidden border border-gray-200 shadow-sm transition-shadow duration-200 hover:shadow-md">
              <CardHeader className="border-b border-gray-100 bg-gradient-to-r from-purple-50 to-white px-6 py-5">
                <CardTitle className="flex items-center gap-3">
                  <div className="flex size-10 items-center justify-center rounded-lg bg-purple-50">
                    <SparkleLine className="size-5 text-purple-600" />
                  </div>
                  <div>
                    <h2 className="text-lg font-bold text-gray-900">AI Insights</h2>
                    <p className="text-sm text-gray-600">Intelligent analysis & key information</p>
                  </div>
                </CardTitle>
              </CardHeader>
              <CardContent className="px-6 py-5">
                <div className="space-y-6">
                  <div className="space-y-3 rounded-xl border border-gray-100 bg-gradient-to-r from-gray-50 to-white p-5">
                    <p className="leading-relaxed text-gray-700">{getAISummary()}</p>
                  </div>

                  <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {companyPhones.length > 0 && (
                      <div className="space-y-2">
                        <h4 className="flex items-center gap-2 text-sm font-semibold text-gray-900">
                          <Phone className="size-4" />
                          Company Phones
                        </h4>
                        {companyPhones.map((phone, idx) => (
                          <div key={idx} className="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3">
                            <span className="font-medium text-gray-900">{revealedFields.has(`company_phone_${idx}`) ? phone : maskPhone(phone)}</span>
                            <Button
                              variant="ghost"
                              size="sm"
                              className="size-8 rounded-lg hover:bg-gray-100"
                              onClick={() => handleCompanyReveal("phone", idx)}
                            >
                              <div className="flex items-center gap-1">
                             
                                <Eye className="size-3.5" />
                              </div>
                            </Button>
                          </div>
                        ))}
                      </div>
                    )}

                    {companyEmails.length > 0 && (
                      <div className="space-y-2">
                        <h4 className="flex items-center gap-2 text-sm font-semibold text-gray-900">
                          <Mail className="size-4" />
                          Company Emails
                        </h4>
                        {companyEmails.map((email, idx) => (
                          <div key={idx} className="flex items-center justify-between rounded-lg border border-gray-200 px-4 py-3">
                            <span className="font-medium text-gray-900">{revealedFields.has(`company_email_${idx}`) ? email : maskEmail(email)}</span>
                            <Button
                              variant="ghost"
                              size="sm"
                              className="size-8 rounded-lg hover:bg-gray-100"
                              onClick={() => handleCompanyReveal("email", idx)}
                            >
                              <Eye className="size-3.5" />
                            </Button>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>

                  <div className="flex flex-wrap gap-3">
                    {headcountDisplay !== "N/A" && <MetricBadge icon={<Users className="size-4" />} label="Employees" value={headcountDisplay} />}
                    {industryDisplay !== "N/A" && <MetricBadge icon={<Briefcase className="size-4" />} label="Industry" value={industryDisplay} />}
                    {revenueDisplay !== "N/A" && <MetricBadge icon={<DollarSign className="size-4" />} label="Revenue" value={revenueDisplay} />}
                    {foundedYearDisplay !== "N/A" && (
                      <MetricBadge icon={<Calendar className="size-4" />} label="Founded" value={foundedYearDisplay} />
                    )}
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Specialties & Technologies */}
            {specialties.length > 0 && (
              <Card className="overflow-hidden border border-gray-200 shadow-sm transition-shadow duration-200 hover:shadow-md">
                <CardHeader className="border-b border-gray-100 bg-gradient-to-r from-amber-50 to-white px-6 py-5">
                  <CardTitle className="flex items-center gap-3">
                    <div className="flex size-10 items-center justify-center rounded-lg bg-amber-50">
                      <Tag className="size-5 text-amber-600" />
                    </div>
                    <div>
                      <h2 className="text-lg font-bold text-gray-900">Specialties & Technologies</h2>
                      <p className="text-sm text-gray-600">Core competencies & tech stack</p>
                    </div>
                  </CardTitle>
                </CardHeader>
                <CardContent className="px-6 py-5">
                  <div className="flex flex-wrap gap-3">
                    {specialties.map((item, idx) => (
                      <Badge
                        key={idx}
                        variant="secondary"
                        className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm font-medium text-amber-800 transition-colors hover:bg-amber-100"
                      >
                        {item}
                      </Badge>
                    ))}
                  </div>
                </CardContent>
              </Card>
            )}
          </div>

          {/* Right Column - 1/3 width */}
          <div className="space-y-8">
            {/* Quick Actions Card */}
            <Card className="overflow-hidden border border-gray-200 shadow-sm">
              <CardContent className="p-5">
                <div className="space-y-4">
                  <div className="flex flex-wrap gap-3">
                    {!!normalizedDomain && (
                      <a href={`https://${normalizedDomain}`} target="_blank" rel="noopener noreferrer" className="min-w-[140px] flex-1">
                        <Button variant="outline" className="w-full justify-start border-gray-300 hover:bg-gray-50">
                          <Globe className="mr-2 size-4" />
                          Visit Website
                        </Button>
                      </a>
                    )}
                    {!!linkedinUrl && (
                      <a href={linkedinUrl} target="_blank" rel="noopener noreferrer" className="min-w-[140px] flex-1">
                        <Button variant="outline" className="w-full justify-start border-gray-300 hover:bg-gray-50">
                          <LinkedinIcon className="mr-2 size-4" />
                          LinkedIn
                        </Button>
                      </a>
                    )}
                  </div>

                  <div className="flex flex-wrap gap-2">
                    {!!twitterUrl && (
                      <a href={twitterUrl} target="_blank" rel="noopener noreferrer">
                        <Button variant="ghost" size="icon" className="size-10 rounded-lg">
                          <Twitter className="size-4" />
                        </Button>
                      </a>
                    )}
                    {!!facebookUrl && (
                      <a href={facebookUrl} target="_blank" rel="noopener noreferrer">
                        <Button variant="ghost" size="icon" className="size-10 rounded-lg">
                          <FacebookIcon className="size-4" />
                        </Button>
                      </a>
                    )}
                    {!!normalizedDomain && (
                      <a href={`https://${normalizedDomain}`} target="_blank" rel="noopener noreferrer">
                        <Button variant="ghost" size="icon" className="size-10 rounded-lg">
                          <Globe className="size-4" />
                        </Button>
                      </a>
                    )}
                    {!!linkedinUrl && (
                      <a href={linkedinUrl} target="_blank" rel="noopener noreferrer">
                        <Button variant="ghost" size="icon" className="size-10 rounded-lg">
                          <LinkedinIcon className="size-4" />
                        </Button>
                      </a>
                    )}
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* Company Overview Card */}
            <Card className="overflow-hidden border border-gray-200 shadow-sm transition-shadow duration-200 hover:shadow-md">
              <CardHeader className="border-b border-gray-100 bg-gradient-to-r from-blue-50 to-white px-5 py-4">
                <CardTitle className="flex items-center gap-2">
                  <Building className="size-5 text-blue-600" />
                  <span className="font-bold text-gray-900">Company Overview</span>
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4 px-5 py-4">
                <div className="rounded-lg border border-gray-200 bg-white p-4">
                  <div className="flex items-center gap-3">
                    {logoUrl ? (
                      <img src={logoUrl} alt={company.company} className="size-12 rounded-lg border border-gray-200 object-contain p-1" />
                    ) : (
                      <div className="flex size-12 items-center justify-center rounded-lg border border-gray-200 bg-gray-50">
                        <Building className="size-6 text-gray-400" />
                      </div>
                    )}
                    <div>
                      <h3 className="font-bold text-gray-900">{company.company}</h3>
                      {!!normalizedDomain && (
                        <p className="flex items-center gap-1 text-sm text-gray-600">
                          <Globe className="size-3" /> {normalizedDomain}
                        </p>
                      )}
                    </div>
                  </div>
                </div>

                <div className="space-y-3">
                  {!!company.company && <InfoRow label="Company Name" value={company.company} />}
                  {industryDisplay !== "N/A" && <InfoRow label="Industry" value={industryDisplay} icon={<Briefcase className="size-4" />} />}
                  {headcountDisplay !== "N/A" && <InfoRow label="Employees" value={headcountDisplay} icon={<Users className="size-4" />} />}
                  {revenueDisplay !== "N/A" && <InfoRow label="Annual Revenue" value={revenueDisplay} icon={<DollarSign className="size-4" />} />}
                  {foundedYearDisplay !== "N/A" && <InfoRow label="Founded Year" value={foundedYearDisplay} icon={<Calendar className="size-4" />} />}
                  {!!sicCode && <InfoRow label="SIC Code" value={sicCode} icon={<Hash className="size-4" />} />}
                  {!!companyCountry && <InfoRow label="Headquarters" value={companyCountry} icon={<MapPin className="size-4" />} />}
                </div>
              </CardContent>
            </Card>

            {/* Funding Card */}
            {!!(totalFundingUsd !== "N/A" || latestFunding || latestFundingAmount !== "N/A" || lastRaisedAt) && (
              <Card className="overflow-hidden border border-gray-200 shadow-sm transition-shadow duration-200 hover:shadow-md">
                <CardHeader className="border-b border-gray-100 bg-gradient-to-r from-emerald-50 to-white px-5 py-4">
                  <CardTitle className="flex items-center gap-2">
                    <DollarSign className="size-5 text-emerald-600" />
                    <span className="font-bold text-gray-900">Funding</span>
                  </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3 px-5 py-4">
                  {!!latestFunding && <InfoRow label="Latest Round" value={latestFunding} />}
                  {latestFundingAmount !== "N/A" && <InfoRow label="Amount" value={latestFundingAmount} />}
                  {totalFundingUsd !== "N/A" && <InfoRow label="Total Funding" value={totalFundingUsd} />}
                  {!!lastRaisedAt && <InfoRow label="Last Raised" value={lastRaisedAt} />}
                </CardContent>
              </Card>
            )}

            {/* Location Card */}
            {!!(companyStreet || companyCity || companyState || companyCountry || companyPostal || companyAddress) && (
              <Card className="overflow-hidden border border-gray-200 shadow-sm transition-shadow duration-200 hover:shadow-md">
                <CardHeader className="border-b border-gray-100 bg-gradient-to-r from-rose-50 to-white px-5 py-4">
                  <CardTitle className="flex items-center gap-2">
                    <MapPin className="size-5 text-rose-600" />
                    <span className="font-bold text-gray-900">Company Location</span>
                  </CardTitle>
                </CardHeader>
                <CardContent className="space-y-3 px-5 py-4">
                  {!!companyAddress && <InfoRow label="Full Address" value={companyAddress} />}
                  {!!companyStreet && <InfoRow label="Street" value={companyStreet} />}
                  {!!companyCity && <InfoRow label="City" value={companyCity} />}
                  {!!companyState && <InfoRow label="State" value={companyState} />}
                  {!!companyCountry && <InfoRow label="Country" value={companyCountry} />}
                  {!!companyPostal && <InfoRow label="Postal Code" value={companyPostal} />}
                </CardContent>
              </Card>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}

// Helper Components
const InfoRow = ({ label, value, icon }: { label: string; value: string | null | undefined; icon?: ReactNode }) => {
  if (!value || value === "N/A" || value.trim() === "") return null
  return (
    <div className="flex items-center justify-between py-2">
      <div className="flex items-center gap-2">
        {!!icon && <span className="text-gray-500">{icon}</span>}
        <span className="text-sm text-gray-600">{label}</span>
      </div>
      <span className="max-w-[60%] truncate text-right text-sm font-semibold text-gray-900">{value}</span>
    </div>
  )
}

const InfoBox = ({ label, value, icon }: { label: string; value: string; icon?: ReactNode }) => (
  <div className="rounded-lg border border-gray-200 bg-gray-50 p-3">
    <div className="flex items-center gap-2">
      {!!icon && <span className="text-gray-500">{icon}</span>}
      <span className="text-xs font-medium text-gray-500">{label}</span>
    </div>
    <p className="mt-1 truncate text-sm font-semibold text-gray-900">{value}</p>
  </div>
)

const MetricBadge = ({ icon, label, value }: { icon: ReactNode; label: string; value: string }) => (
  <div className="flex items-center gap-2 rounded-lg border border-gray-200 bg-white px-4 py-3">
    <div className="text-gray-500">{icon}</div>
    <div>
      <p className="text-xs text-gray-500">{label}</p>
      <p className="text-sm font-bold text-gray-900">{value}</p>
    </div>
  </div>
)

export default ContactDetails
