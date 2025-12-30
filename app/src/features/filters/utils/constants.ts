export const FILTER_KEYS = {
  // Contact Filters
  JOB_TITLE: "job_title",
  DEPARTMENTS: "departments",
  SENIORITY: "seniority",
  CONTACT_LOCATION: "location",
  CONTACT_CITY: "city",
  CONTACT_STATE: "state",
  CONTACT_COUNTRY: "country",

  // Company Filters
  COMPANY_NAME: "company_names",
  EMPLOYEE_COUNT: "employee_count",
  REVENUE: "annual_revenue", // Normalized from 'revenue'
  BUSINESS_CATEGORY: "business_category",
  TECHNOLOGIES: "technologies",
  COMPANY_KEYWORDS: "company_keywords",
  COMPANY_LOCATION: "company_location", // if distinct from contact location in response structure

  // Custom / Dynamic
  CUSTOM: "custom" // New field for flexible chips
} as const

export const FILTER_LABELS: Record<string, string> = {
  [FILTER_KEYS.JOB_TITLE]: "Job Title",
  [FILTER_KEYS.DEPARTMENTS]: "Department",
  [FILTER_KEYS.SENIORITY]: "Seniority",
  [FILTER_KEYS.CONTACT_LOCATION]: "Location",
  [FILTER_KEYS.COMPANY_NAME]: "Company",
  [FILTER_KEYS.EMPLOYEE_COUNT]: "Company Size",
  [FILTER_KEYS.REVENUE]: "Revenue",
  [FILTER_KEYS.BUSINESS_CATEGORY]: "Business Category",
  [FILTER_KEYS.TECHNOLOGIES]: "Technologies",
  [FILTER_KEYS.COMPANY_KEYWORDS]: "Keywords"
}
