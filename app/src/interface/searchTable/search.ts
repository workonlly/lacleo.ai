export interface ContactAttributes {
  _id: string
  website?: string
  company?: string
  first_name?: string
  last_name?: string
  full_name?: string
  title?: string
  linkedin_url?: string
  email?: string
  work_email?: string
  personal_email?: string
  phone_number?: string
  mobile_phone?: string
  industry?: string | null
  business_category?: string | string[] | null
  company_headcount?: number | null
  employee_count?: number | null
  number_of_employees?: number | null
  employee_count_range?: string
  size?: string
  founded_year?: number | string | null
  revenue?: string | null
  annual_revenue?: number | string | null
  keywords?: string[]
  // Additional optional fields observed in UI usage
  departments?: string[] | string
  department?: string
  seniority?: string
  seniority_level?: string
  city?: string
  state?: string
  country?: string
  location?: { city?: string; state?: string; country?: string }
  // Some payloads carry richer email/phone objects
  emails?: Array<string | { email?: string; address?: string; type?: string; category?: string; email_status?: string; status?: string }>
  phones?: Array<string | { number?: string; phone_number?: string; type?: string; is_valid?: boolean; valid?: boolean }>
  gender?: string
  facebook_url?: string
  twitter_url?: string
  phone_numbers?: string[]
  // keep legacy shape for backward compatibility
  // emails?: string[]
  contact?: unknown
  actions?: unknown
}

type CompanyLocation = {
  country: string
}

export interface CompanyAttributes {
  _id: string
  website: string
  company: string
  company_linkedin_url: string | null
  linkedin_url?: string | null
  facebook_url?: string | null
  twitter_url?: string | null
  industry?: string | null
  business_category?: string | string[] | null
  keywords: string[]
  technologies?: string[]
  company_technologies?: string[]
  location: CompanyLocation
  description?: string
  short_description?: string | null
  long_description?: string
  seo_description?: string
  founded_year?: number | string | null
  revenue?: string | null
  annual_revenue?: number | string | null
  annual_revenue_usd?: number | string | null
  company_headcount?: number | null
  number_of_employees?: number | null
  total_employees?: number | string | null
  // Extended optional fields present in API payloads
  domain?: string | null
  company_domain?: string | null
  emails?: Array<string | { address?: string }>
  phone_numbers?: string[]
  phone_number?: string | null
  company_phone?: string | null
  phone?: string | null
  logo_url?: string | null
  street?: string | null
  city?: string | null
  state?: string | null
  postal_code?: string | null
  country?: string | null
  funding?: {
    last_raised_at?: string | null
  }
  social_media?: {
    facebook_url?: string | null
    twitter_url?: string | null
    linkedin_url?: string | null
  }
  actions?: unknown
}

interface ResponseMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

export interface BaseResponseItem<T> {
  id: string
  attributes: T
  highlights: null | Record<string, unknown>
}

export interface SearchApiResponse<T> {
  data: BaseResponseItem<T>[]
  meta: ResponseMeta
}

export interface SearchRequestParams {
  type: "contact" | "company"
  buildParams: string
}

export interface SavedFilter {
  id: string
  user_id: string
  name: string
  description?: string
  filters: Record<string, unknown>
  entity_type: "contact" | "company"
  is_starred: boolean
  tags?: string[]
  created_at: string
  updated_at: string
}
