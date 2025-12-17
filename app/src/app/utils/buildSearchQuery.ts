import { SelectedFilter, ActiveFilter } from "@/interface/filters/slice"

type BucketedDSL = {
  contact: Record<string, unknown>
  company: Record<string, unknown>
}

// Define range types for numeric filters
interface RangeFilter {
  min?: number
  max?: number
}

interface ExperienceFilter {
  min?: number
  max?: number
  include?: string[]
  exclude?: string[]
}

const mapIdToDslKey: Record<string, string> = {
  // Contact filters
  industry: "industries",
  company_location: "locations",
  contact_location: "locations",
  technologies: "technologies",
  employee_count: "employee_count",
  company_headcount: "company_headcount",
  company_revenue: "revenue",
  job_title: "job_title",
  departments: "departments",
  years_of_experience: "years_of_experience",
  seniority: "seniority",
  company_domain_company: "domains",
  company_domain_contact: "domains",
  company_name_company: "company_names",
  company_name_contact: "company_names",
  // New company filters for contact search
  company_employee_count: "employee_count",
  company_revenue_range: "revenue",
  company_industries: "industries",
  company_technologies: "technologies",
  company_headquarters: "locations",
  company_founded_year: "founded_year",
  company_domain: "domains",
  company_has_email: "has",
  company_has_phone: "has",
  // Contact-specific filters
  contact_experience_years: "experience_years",
  contact_has_email: "has",
  contact_has_phone: "has",
  contact_country: "country",
  contact_state: "state",
  contact_city: "city",
  company_keywords: "company_keywords"
}

const companyBucketKeys = new Set([
  "revenue",
  "employee_count",
  "company_headcount",
  "industries",
  "technologies",
  "domains",
  "company_names",
  "company_keywords",
  "founded_year",
  "locations"
])

// Special handling for numeric range filters
const rangeFilterKeys = new Set([
  "employee_count",
  "company_employee_count",
  "revenue",
  "company_revenue_range",
  "years_of_experience",
  "contact_experience_years",
  "company_founded_year"
])

// Boolean filters
const booleanFilterKeys = new Set(["company_has_email", "company_has_phone", "contact_has_email", "contact_has_phone"])

function buildIncludeExclude(items: SelectedFilter[]): IncludeExclude | null {
  const include = items
    .filter((i) => i.type === "include")
    .map((i) => i.name)
    .filter((v) => v && v.trim())
  const exclude = items
    .filter((i) => i.type === "exclude")
    .map((i) => i.name)
    .filter((v) => v && v.trim())

  if (include.length === 0 && exclude.length === 0) return null
  return {
    ...(include.length ? { include } : {}),
    ...(exclude.length ? { exclude } : {})
  }
}

function buildRangeFilter(items: SelectedFilter[]): RangeFilter | null {
  const ranges: RangeFilter = {}

  items.forEach((item) => {
    if (item.type === "include") {
      // Handle different range formats
      if (typeof item.value === "object" && item.value !== null) {
        if ("min" in item.value) ranges.min = Number(item.value.min)
        if ("max" in item.value) ranges.max = Number(item.value.max)
      } else if (typeof item.value === "string") {
        // Parse string ranges like "500-1000", "1000+", ">500"
        const str = item.value

        if (str.includes("+")) {
          const min = parseFloat(str.replace("+", ""))
          if (!isNaN(min)) ranges.min = min
        } else if (str.includes("-")) {
          const [minStr, maxStr] = str.split("-")
          const min = parseFloat(minStr)
          const max = parseFloat(maxStr)
          if (!isNaN(min)) ranges.min = min
          if (!isNaN(max)) ranges.max = max
        } else if (str.startsWith(">")) {
          const min = parseFloat(str.slice(1))
          if (!isNaN(min)) ranges.min = min
        } else if (str.startsWith("<")) {
          const max = parseFloat(str.slice(1))
          if (!isNaN(max)) ranges.max = max
        } else {
          // Try to parse as number
          const num = parseFloat(str)
          if (!isNaN(num)) {
            ranges.min = num
            ranges.max = num
          }
        }
      } else if (typeof item.value === "number") {
        ranges.min = item.value
        ranges.max = item.value
      }
    }
  })

  if (Object.keys(ranges).length === 0) return null
  return ranges
}

function buildBooleanFilter(items: SelectedFilter[]): boolean | null {
  const includeItems = items.filter((i) => i.type === "include")
  const excludeItems = items.filter((i) => i.type === "exclude")

  if (includeItems.length > 0 && excludeItems.length > 0) {
    // If both include and exclude, prioritize include
    return true
  }

  if (includeItems.length > 0) return true
  if (excludeItems.length > 0) return false

  return null
}

function buildExperienceFilter(items: SelectedFilter[]): ExperienceFilter | null {
  const filter: ExperienceFilter = {}
  const includeExclude = buildIncludeExclude(items)

  if (includeExclude) {
    if (includeExclude.include) filter.include = includeExclude.include
    if (includeExclude.exclude) filter.exclude = includeExclude.exclude
  }

  // Also check for range values
  items.forEach((item) => {
    if (item.type === "include" && typeof item.value === "object" && item.value !== null) {
      if ("min" in item.value) filter.min = Number(item.value.min)
      if ("max" in item.value) filter.max = Number(item.value.max)
    }
  })

  if (filter.include || filter.exclude || filter.min !== undefined || filter.max !== undefined) {
    return filter
  }

  return null
}

function parseMoneyString(str: string): number {
  str = str.toUpperCase().replace(/\s+/g, "").replace(/\$/g, "")

  let multiplier = 1
  if (str.endsWith("M")) {
    multiplier = 1000000
    str = str.slice(0, -1)
  } else if (str.endsWith("B")) {
    multiplier = 1000000000
    str = str.slice(0, -1)
  } else if (str.endsWith("K")) {
    multiplier = 1000
    str = str.slice(0, -1)
  }

  const number = parseFloat(str.replace(/,/g, ""))
  return isNaN(number) ? 0 : number * multiplier
}

function buildRevenueFilter(items: SelectedFilter[]): RangeFilter | IncludeExclude | null {
  // First try to parse as range
  const range = buildRangeFilter(items)
  if (range && (range.min !== undefined || range.max !== undefined)) {
    // Convert string values to numbers if needed
    if (typeof range.min === "string") {
      range.min = parseMoneyString(range.min)
    }
    if (typeof range.max === "string") {
      range.max = parseMoneyString(range.max)
    }
    return range
  }

  // Fall back to include/exclude for string ranges like "1M-10M"
  const includeExclude = buildIncludeExclude(items)
  if (includeExclude) {
    return includeExclude
  }

  return null
}

export function buildSearchQuery(
  selectedItems: Record<string, SelectedFilter[]>,
  activeFilters?: { contact: Record<string, ActiveFilter>; company: Record<string, ActiveFilter> }
): BucketedDSL {
  const dsl: BucketedDSL = { contact: {}, company: {} }

  Object.entries(selectedItems).forEach(([sectionId, items]) => {
    if (!items || items.length === 0) return

    const paramKey = mapIdToDslKey[sectionId] || sectionId

    // Special handling for different filter types

    // 1. Range filters (employee count, revenue, years of experience)
    if (rangeFilterKeys.has(sectionId)) {
      let filterValue: FilterValue | null = null

      if (sectionId.includes("revenue")) {
        filterValue = buildRevenueFilter(items)
      } else if (sectionId.includes("experience")) {
        filterValue = buildExperienceFilter(items)
      } else {
        filterValue = buildRangeFilter(items)
      }

      if (!filterValue) return

      // Determine where to place the filter
      if (sectionId.startsWith("company_")) {
        const companyKey = sectionId.replace("company_", "")
        dsl.company[companyKey] = filterValue
      } else if (companyBucketKeys.has(paramKey)) {
        dsl.company[paramKey] = filterValue
      } else {
        dsl.contact[paramKey] = filterValue
      }
      return
    }

    // 2. Boolean filters (has_email, has_phone)
    if (booleanFilterKeys.has(sectionId)) {
      const boolValue = buildBooleanFilter(items)
      if (boolValue === null) return

      const targetKey = sectionId.includes("email") ? "email" : sectionId.includes("phone") ? "phone" : sectionId.split("_").pop() || sectionId

      if (sectionId.startsWith("company_")) {
        const companyBucket = dsl.company as Record<string, unknown>
        const current = (companyBucket["has"] as Record<string, boolean> | undefined) || {}
        companyBucket["has"] = { ...current, [targetKey]: boolValue }
      } else {
        const contactBucket = dsl.contact as Record<string, unknown>
        const current = (contactBucket["has"] as Record<string, boolean> | undefined) || {}
        contactBucket["has"] = { ...current, [targetKey]: boolValue }
      }
      return
    }

    // 3. Location filters (special handling)
    if (sectionId === "company_location" || sectionId === "company_headquarters") {
      const filterObj = buildIncludeExclude(items)
      if (!filterObj) return
      dsl.company["locations"] = filterObj
      return
    }

    if (sectionId === "contact_location" || sectionId === "contact_country" || sectionId === "contact_state" || sectionId === "contact_city") {
      const filterObj = buildIncludeExclude(items)
      if (!filterObj) return

      // For contact location fields, use locations object with field specification
      if (sectionId === "contact_country") {
        dsl.contact["country"] = filterObj
      } else if (sectionId === "contact_state") {
        dsl.contact["state"] = filterObj
      } else if (sectionId === "contact_city") {
        dsl.contact["city"] = filterObj
      } else {
        dsl.contact["locations"] = filterObj
      }
      return
    }

    // 4. Standard include/exclude filters
    const filterObj = buildIncludeExclude(items)
    if (!filterObj) return

    // Determine bucket based on section ID
    if (sectionId.startsWith("company_")) {
      // Company filters for contact search
      const companyKey = sectionId.replace("company_", "")
      // Pass operator when available for domains/company_names
      if (companyKey === "domain" || companyKey === "domains" || companyKey === "company_name" || companyKey === "company_names") {
        const opKey = companyKey === "domains" || companyKey === "domain" ? "domains" : "company_names"
        const bucket = activeFilters?.company?.[opKey]
        dsl.company[opKey] = bucket?.operator ? { ...filterObj, operator: bucket.operator } : filterObj
      } else {
        dsl.company[companyKey] = filterObj
      }
    } else if (companyBucketKeys.has(paramKey)) {
      // Include operator for domains/company_names if available
      if (paramKey === "domains" || paramKey === "company_names") {
        const bucket = activeFilters?.company?.[paramKey]
        dsl.company[paramKey] = bucket?.operator ? { ...filterObj, operator: bucket.operator } : filterObj
      } else {
        dsl.company[paramKey] = filterObj
      }
    } else {
      dsl.contact[paramKey] = filterObj
    }
  })

  // Keep empty objects; backend can ignore empty buckets

  return dsl
}

// Helper function to parse natural language queries
export function parseNaturalLanguageQuery(query: string): BucketedDSL {
  const dsl: BucketedDSL = { contact: {}, company: {} }
  const lowerQuery = query.toLowerCase()

  // Job title extraction
  const jobTitlePatterns = [
    { pattern: /\b(hr|human resources)\b/i, title: "hr" },
    { pattern: /\b(software engineer|software developer|developer)\b/i, title: "software engineer" },
    { pattern: /\b(ai engineer|machine learning engineer|ml engineer)\b/i, title: "ai engineer" },
    { pattern: /\b(marketing director|marketing manager)\b/i, title: "marketing director" },
    { pattern: /\b(sales director|sales manager)\b/i, title: "sales director" },
    { pattern: /\b(cto|chief technology officer)\b/i, title: "cto" },
    { pattern: /\b(ceo|chief executive officer)\b/i, title: "ceo" },
    { pattern: /\b(cfo|chief financial officer)\b/i, title: "cfo" },
    { pattern: /\b(engineer|developer|programmer)\b/i, title: "engineer" }
  ]

  for (const { pattern, title } of jobTitlePatterns) {
    if (pattern.test(query)) {
      dsl.contact.job_title = { include: [title], fuzzy: true }
      break
    }
  }

  // Experience years extraction
  const expMatch = lowerQuery.match(/(\d+)\+?\s*(?:years?|yrs?)(?:\s+of?\s+experience)?/i)
  if (expMatch) {
    dsl.contact.experience_years = { min: parseInt(expMatch[1]) }
  }

  // Employee count extraction
  const empMatch = lowerQuery.match(/(\d+)(?:\s*[-+]?\s*)?(?:\+)?\s*(?:employees|people|staff)/i)
  if (empMatch) {
    dsl.company.employee_count = { min: parseInt(empMatch[1]) }
  }

  // Revenue extraction
  const revMatch = lowerQuery.match(/(?:revenue|sales).*?(\d+(?:\.\d+)?[mkb]?)\s*(?:-|to|and)\s*(\d+(?:\.\d+)?[mkb]?)/i)
  if (revMatch) {
    const min = parseMoneyString(revMatch[1])
    const max = parseMoneyString(revMatch[2])
    dsl.company.revenue = { min, max }
  }

  // Location extraction
  const locationPatterns = [
    { pattern: /\b(us|usa|united states|america)\b/i, location: "United States" },
    { pattern: /\b(europe|eu)\b/i, location: "Europe" },
    { pattern: /\b(california|ca)\b/i, location: "California" },
    { pattern: /\b(new york|ny)\b/i, location: "New York" },
    { pattern: /\b(texas|tx)\b/i, location: "Texas" },
    { pattern: /\b(florida|fl)\b/i, location: "Florida" },
    { pattern: /\b(london)\b/i, location: "London" }
  ]

  for (const { pattern, location } of locationPatterns) {
    if (pattern.test(query)) {
      if (lowerQuery.includes("company") || lowerQuery.includes("headquarters")) {
        dsl.company.locations = { include: [location] }
      } else {
        dsl.contact.locations = { include: [location] }
      }
      break
    }
  }

  // Technology extraction
  const techPatterns = [
    { pattern: /\b(aws|amazon web services)\b/i, tech: "AWS" },
    { pattern: /\b(hubspot)\b/i, tech: "HubSpot" },
    { pattern: /\b(salesforce)\b/i, tech: "Salesforce" },
    { pattern: /\b(react|angular|vue)\b/i, tech: "JavaScript Framework" },
    { pattern: /\b(python|java|javascript)\b/i, tech: "Programming Language" }
  ]

  const techs: string[] = []
  for (const { pattern, tech } of techPatterns) {
    if (pattern.test(query) && !techs.includes(tech)) {
      techs.push(tech)
    }
  }

  if (techs.length > 0) {
    if (lowerQuery.includes("company") || lowerQuery.includes("uses") || lowerQuery.includes("using")) {
      dsl.company.technologies = { include: techs }
    }
  }

  return dsl
}

// Type for the search request
export interface SearchRequest {
  searchTerm?: string
  filter_dsl?: BucketedDSL
  page?: number
  count?: number
  sort?: Array<{ field: string; direction: "asc" | "desc" }>
}
type IncludeExclude = { include?: string[]; exclude?: string[] }
type FilterValue = RangeFilter | ExperienceFilter | IncludeExclude
