import { FilterState, SelectedFilter, RangeFilterValue, ActiveFilter } from "@/interface/filters/slice"
import { TRootState } from "@/interface/reduxRoot/state"
import { PayloadAction, createSlice } from "@reduxjs/toolkit"

const initialState: FilterState = {
  expandedSections: {},
  searchTerms: {},
  selectedItems: {},
  // Apollo-style cross-index filtering state
  searchContext: "contacts", // 'contacts' | 'companies'
  activeFilters: {
    contact: {},
    company: {}
  }
}

// Helper: map UI sectionId to DSL key
export const sectionToKey: Record<string, string> = {
  // Contact filters
  job_title: "job_title",
  departments: "departments",
  seniority: "seniority",
  years_of_experience: "experience_years",
  contact_location: "locations",
  contact_country: "country",
  contact_state: "state",
  contact_city: "city",
  contact_has_email: "has_email",
  contact_has_phone: "has_phone",
  // Contact filters that join to company data
  company_technologies_contact: "technologies",
  annual_revenue_contact: "annual_revenue",
  founded_year_contact: "founded_year",
  total_funding_contact: "total_funding",
  employee_count_contact: "employee_count",
  company_country_contact: "country",
  company_state_contact: "state",
  company_city_contact: "city",

  // Company filters (context-aware mapping in SearchService handles these)
  company_employee_count: "employee_count",
  employee_count: "employee_count",
  company_revenue_range: "revenue",
  company_revenue: "revenue",
  annual_revenue: "annual_revenue",
  founded_year: "founded_year",
  total_funding: "total_funding",
  company_industries: "industries",
  industry: "industries",
  company_technologies: "technologies",
  technologies: "technologies",
  company_headquarters: "locations",
  company_location: "locations",
  company_country: "country",
  company_state: "state",
  company_city: "city",
  company_founded_year: "founded_year",
  company_domain: "domains",
  company_domain_company: "domains",
  company_domain_contact: "domains",
  company_name_company: "company_names",
  company_name_contact: "company_names",
  company_has_email: "has_email",
  company_has_phone: "has_phone",
  company_keywords: "company_keywords"
}

function updateActiveFilters(state: FilterState, sectionId: string, item: SelectedFilter, actionType: "add" | "remove", isCompanyFilter: boolean) {
  const key = sectionToKey[sectionId] || sectionId
  const targetBucket = isCompanyFilter ? "company" : "contact"
  // ... existing logic ...
}

function getSectionIdFromKey(key: string, isCompanyFilter: boolean): string | null {
  const keyToSection: Record<string, string> = {
    // Contact filters
    job_title: "job_title",
    departments: "departments",
    seniority: "seniority",
    experience_years: "years_of_experience",
    locations: "contact_location",
    country: isCompanyFilter ? "company_country" : "contact_country",
    state: isCompanyFilter ? "company_state" : "contact_state",
    city: isCompanyFilter ? "company_city" : "contact_city",
    has_email: "contact_has_email",
    has_phone: "contact_has_phone",

    // Company filters
    employee_count: "employee_count",
    revenue: "company_revenue_range",
    annual_revenue: "annual_revenue",
    founded_year: "founded_year",
    total_funding: "total_funding",
    industries: "industry",
    technologies: "technologies",
    domains: "company_domain_company",
    company_names: "company_name_company",
    company_keywords: "company_keywords",
    industry: "industry"
  }
  return keyToSection[key] || null
}

const filterSlice = createSlice({
  name: "filters",
  initialState,
  reducers: {
    toggleSection: (state, action: PayloadAction<string>) => {
      const sectionId = action.payload
      state.expandedSections[sectionId] = !state.expandedSections[sectionId]
    },

    setSearchTerm: (state, action: PayloadAction<{ sectionId: string; term: string }>) => {
      const { sectionId, term } = action.payload
      state.searchTerms[sectionId] = term
    },

    addSelectedItem: (
      state,
      action: PayloadAction<{
        sectionId: string
        item: SelectedFilter
        isCompanyFilter?: boolean
      }>
    ) => {
      const { sectionId, item, isCompanyFilter = false } = action.payload

      if (!state.selectedItems[sectionId]) {
        state.selectedItems[sectionId] = []
      }

      const existingItemIndex = state.selectedItems[sectionId].findIndex((existingItem) => existingItem.id === item.id)

      if (existingItemIndex !== -1) {
        // If item exists but with different type, update its type
        if (state.selectedItems[sectionId][existingItemIndex].type !== item.type) {
          state.selectedItems[sectionId][existingItemIndex].type = item.type
        }
      } else {
        // If item doesn't exist, add it
        state.selectedItems[sectionId].push(item)
      }

      updateActiveFilters(state, sectionId, item, "add", isCompanyFilter)
    },

    removeSelectedItem: (
      state,
      action: PayloadAction<{
        sectionId: string
        itemId: string
        isCompanyFilter?: boolean
      }>
    ) => {
      const { sectionId, itemId, isCompanyFilter = false } = action.payload

      if (state.selectedItems[sectionId]) {
        const removedItem = state.selectedItems[sectionId].find((item) => item.id === itemId)
        state.selectedItems[sectionId] = state.selectedItems[sectionId].filter((item) => item.id !== itemId)

        if (removedItem) {
          updateActiveFilters(state, sectionId, removedItem, "remove", isCompanyFilter)
        }
      }
    },

    setSearchContext: (state, action: PayloadAction<"contacts" | "companies">) => {
      state.searchContext = action.payload
    },

    clearCompanyFilters: (state) => {
      // Remove all company-related filters from selectedItems
      const companySectionIds = [
        "company_employee_count",
        "company_revenue_range",
        "annual_revenue",
        "founded_year",
        "total_funding",
        "company_industries",
        "company_technologies",
        "company_headquarters",
        "company_founded_year",
        "company_domain",
        "company_has_email",
        "company_has_phone",
        "company_location",
        "company_country",
        "company_state",
        "company_city"
      ]

      companySectionIds.forEach((sectionId) => {
        delete state.selectedItems[sectionId]
      })

      // Clear active company filters
      state.activeFilters.company = {}
    },

    clearContactFilters: (state) => {
      // Remove all contact-related filters from selectedItems
      const contactSectionIds = [
        "job_title",
        "departments",
        "seniority",
        "years_of_experience",
        "contact_location",
        "contact_country",
        "contact_state",
        "contact_city",
        "contact_has_email",
        "contact_has_phone",
        // Contact filters that join to company
        "company_technologies_contact",
        "annual_revenue_contact",
        "founded_year_contact",
        "total_funding_contact",
        "employee_count_contact",
        "company_country_contact",
        "company_state_contact",
        "company_city_contact"
      ]

      contactSectionIds.forEach((sectionId) => {
        delete state.selectedItems[sectionId]
      })

      // Clear active contact filters
      state.activeFilters.contact = {}
    },

    setRangeFilter: (
      state,
      action: PayloadAction<{
        sectionId: string
        range: RangeFilterValue
        isCompanyFilter?: boolean
      }>
    ) => {
      const { sectionId, range, isCompanyFilter = false } = action.payload

      // Create a SelectedFilter for the range
      const rangeItem: SelectedFilter = {
        id: `${sectionId}_range_${range.min || "0"}_${range.max || "inf"}`,
        name: range.min && range.max ? `${range.min}-${range.max}` : range.min ? `${range.min}+` : range.max ? `up to ${range.max}` : "",
        type: "include",
        value: range
      }

      // Remove any existing range filters for this section
      if (state.selectedItems[sectionId]) {
        state.selectedItems[sectionId] = state.selectedItems[sectionId].filter(
          (item) => !(item.value && typeof item.value === "object" && ("min" in item.value || "max" in item.value))
        )
      } else {
        state.selectedItems[sectionId] = []
      }

      // Add the new range filter
      state.selectedItems[sectionId].push(rangeItem)

      updateActiveFilters(state, sectionId, rangeItem, "add", isCompanyFilter)
    },

    clearRangeFilter: (
      state,
      action: PayloadAction<{
        sectionId: string
        isCompanyFilter?: boolean
      }>
    ) => {
      const { sectionId, isCompanyFilter = false } = action.payload

      if (state.selectedItems[sectionId]) {
        // Find and remove range filters
        const rangeItems = state.selectedItems[sectionId].filter(
          (item) => item.value && typeof item.value === "object" && ("min" in item.value || "max" in item.value)
        )

        // Remove from selectedItems
        state.selectedItems[sectionId] = state.selectedItems[sectionId].filter(
          (item) => !(item.value && typeof item.value === "object" && ("min" in item.value || "max" in item.value))
        )

        rangeItems.forEach((item) => {
          updateActiveFilters(state, sectionId, item, "remove", isCompanyFilter)
        })
      }
    },

    resetFilters: (state) => {
      state.expandedSections = {}
      state.searchTerms = {}
      state.selectedItems = {}
      state.activeFilters = { contact: {}, company: {} }
      // Keep searchContext as is
    },

    importFiltersFromDSL: (
      state,
      action: PayloadAction<{
        contact: Record<string, unknown>
        company: Record<string, unknown>
      }>
    ) => {
      const { contact, company } = action.payload
      // Clear existing filters (inline)
      state.expandedSections = {}
      state.searchTerms = {}
      state.selectedItems = {}
      state.activeFilters = { contact: {}, company: {} }

      // Import contact filters
      Object.entries(contact).forEach(([key, value]) => {
        const sectionId = getSectionIdFromKey(key, false)
        if (!sectionId) return

        const bucket = value as { include?: unknown; exclude?: unknown; min?: number; max?: number; operator?: "or" | "and" }
        const inc = Array.isArray(bucket.include) ? (bucket.include as string[]) : []
        const exc = Array.isArray(bucket.exclude) ? (bucket.exclude as string[]) : []

        if (inc.length > 0) {
          inc.forEach((itemName: string) => {
            const item: SelectedFilter = { id: `${sectionId}_${itemName}`, name: itemName, type: "include" }
            if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
            state.selectedItems[sectionId].push(item)
            updateActiveFilters(state, sectionId, item, "add", false)
          })
        }

        if (exc.length > 0) {
          exc.forEach((itemName: string) => {
            const item: SelectedFilter = { id: `${sectionId}_${itemName}_exclude`, name: itemName, type: "exclude" }
            if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
            state.selectedItems[sectionId].push(item)
            updateActiveFilters(state, sectionId, item, "add", false)
          })
        }

        // Handle range values
        if (bucket.min !== undefined || bucket.max !== undefined) {
          const range: RangeFilterValue = {
            min: bucket.min,
            max: bucket.max
          }
          const rangeItem: SelectedFilter = {
            id: `${sectionId}_range_${range.min || "0"}_${range.max || "inf"}`,
            name: range.min && range.max ? `${range.min}-${range.max}` : range.min ? `${range.min}+` : range.max ? `up to ${range.max}` : "",
            type: "include",
            value: range
          }
          if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
          state.selectedItems[sectionId].push(rangeItem)
          updateActiveFilters(state, sectionId, rangeItem, "add", false)
        }
        if (bucket.operator) {
          const keyName = sectionToKey[sectionId] || sectionId
          if (!state.activeFilters.contact[keyName]) {
            state.activeFilters.contact[keyName] = { include: [], exclude: [], ranges: [], operator: bucket.operator }
          } else {
            state.activeFilters.contact[keyName].operator = bucket.operator
          }
        }
      })

      // Import company filters
      Object.entries(company).forEach(([key, value]) => {
        const sectionId = getSectionIdFromKey(key, true)
        if (!sectionId) return

        const bucket = value as { include?: unknown; exclude?: unknown; min?: number; max?: number; operator?: "or" | "and" }
        const inc = Array.isArray(bucket.include) ? (bucket.include as string[]) : []
        const exc = Array.isArray(bucket.exclude) ? (bucket.exclude as string[]) : []

        if (inc.length > 0) {
          inc.forEach((itemName: string) => {
            const item: SelectedFilter = { id: `${sectionId}_${itemName}`, name: itemName, type: "include" }
            if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
            state.selectedItems[sectionId].push(item)
            updateActiveFilters(state, sectionId, item, "add", true)
          })
        }

        if (exc.length > 0) {
          exc.forEach((itemName: string) => {
            const item: SelectedFilter = { id: `${sectionId}_${itemName}_exclude`, name: itemName, type: "exclude" }
            if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
            state.selectedItems[sectionId].push(item)
            updateActiveFilters(state, sectionId, item, "add", true)
          })
        }

        // Handle range values
        if (bucket.min !== undefined || bucket.max !== undefined) {
          const range: RangeFilterValue = {
            min: bucket.min,
            max: bucket.max
          }
          const rangeItem: SelectedFilter = {
            id: `${sectionId}_range_${range.min || "0"}_${range.max || "inf"}`,
            name: range.min && range.max ? `${range.min}-${range.max}` : range.min ? `${range.min}+` : range.max ? `up to ${range.max}` : "",
            type: "include",
            value: range
          }
          if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
          state.selectedItems[sectionId].push(rangeItem)
          updateActiveFilters(state, sectionId, rangeItem, "add", true)
        }
        if (bucket.operator) {
          const keyName = sectionToKey[sectionId] || sectionId
          if (!state.activeFilters.company[keyName]) {
            state.activeFilters.company[keyName] = { include: [], exclude: [], ranges: [], operator: bucket.operator }
          } else {
            state.activeFilters.company[keyName].operator = bucket.operator
          }
        }
      })
    },
    setBucketOperator: (state, action: PayloadAction<{ bucket: "contact" | "company"; key: string; operator: "or" | "and" }>) => {
      const { bucket, key, operator } = action.payload
      if (!state.activeFilters[bucket][key]) {
        state.activeFilters[bucket][key] = { include: [], exclude: [], ranges: [], operator }
      } else {
        state.activeFilters[bucket][key].operator = operator
      }
    },
    setFilterPresence: (state, action: PayloadAction<{ bucket: "contact" | "company"; key: string; presence: "any" | "known" | "unknown" }>) => {
      const { bucket, key, presence } = action.payload
      if (!state.activeFilters[bucket][key]) {
        state.activeFilters[bucket][key] = { include: [], exclude: [], ranges: [], presence }
      } else {
        state.activeFilters[bucket][key].presence = presence
      }
    },

    setFilterMode: (state, action: PayloadAction<{ bucket: "contact" | "company"; key: string; mode: "all" | "any" }>) => {
      const { bucket, key, mode } = action.payload
      if (!state.activeFilters[bucket][key]) {
        state.activeFilters[bucket][key] = { include: [], exclude: [], ranges: [], mode }
      } else {
        state.activeFilters[bucket][key].mode = mode
      }
    },

    setFilterFields: (state, action: PayloadAction<{ bucket: "contact" | "company"; key: string; fields: string[] }>) => {
      const { bucket, key, fields } = action.payload
      if (!state.activeFilters[bucket][key]) {
        state.activeFilters[bucket][key] = { include: [], exclude: [], ranges: [], fields }
      } else {
        state.activeFilters[bucket][key].fields = fields
      }
    }
  }
})

export const {
  toggleSection,
  setSearchTerm,
  addSelectedItem,
  removeSelectedItem,
  setSearchContext,
  clearCompanyFilters,
  clearContactFilters,
  setRangeFilter,
  clearRangeFilter,
  resetFilters,
  importFiltersFromDSL,
  setBucketOperator,
  setFilterPresence,
  setFilterMode,
  setFilterFields
} = filterSlice.actions

export const selectExpandedSections = (state: TRootState) => state.filters.expandedSections
export const selectSearchTerms = (state: TRootState) => state.filters.searchTerms
export const selectSelectedItems = (state: TRootState) => state.filters.selectedItems
export const selectSearchContext = (state: TRootState) => state.filters.searchContext
export const selectActiveFilters = (state: TRootState) => state.filters.activeFilters
export const selectContactFilters = (state: TRootState) => state.filters.activeFilters.contact
export const selectCompanyFilters = (state: TRootState) => state.filters.activeFilters.company

export default filterSlice.reducer
