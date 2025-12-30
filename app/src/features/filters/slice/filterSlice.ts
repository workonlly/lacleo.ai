import { FilterState, SelectedFilter, RangeFilterValue, ActiveFilter } from "@/interface/filters/slice"
import type { FilterDSL } from "@/features/filters/adapter/querySerializer"
import { TRootState } from "@/interface/reduxRoot/state"
import { PayloadAction, createSlice } from "@reduxjs/toolkit"

const initialState: FilterState = {
  expandedSections: {},
  searchTerms: {},
  selectedItems: {},
  searchContext: "contacts",
  activeFilters: {
    contact: {},
    company: {}
  }
}

// Helper: map UI sectionId to DSL key
export const sectionToKey: Record<string, string> = {
  // Contact filters (use FilterRegistry IDs)
  job_title: "job_title",
  departments: "departments", // specific to backend expectations if needed, but registry uses department? Validator maps department -> departments.
  seniority: "seniority",
  contact_country: "countries",
  contact_state: "states",
  contact_city: "cities",
  contact_has_email: "work_email_exists",
  contact_has_phone: "mobile_number_exists",
  // Contact filters that join to company data
  company_technologies_contact: "technologies",
  annual_revenue_contact: "annual_revenue",
  founded_year_contact: "founded_year",
  employee_count_contact: "employee_count",

  // Company filters (use FilterRegistry IDs)
  company_employee_count: "employee_count",
  employee_count: "employee_count",
  company_revenue: "annual_revenue",
  company_revenue_range: "annual_revenue",
  annual_revenue: "annual_revenue",
  technologies: "technologies",
  founded_year: "founded_year",
  business_category: "business_category",
  company_technologies: "technologies",
  company_country: "countries",
  company_state: "states",
  company_city: "cities",
  company_founded_year: "founded_year",
  company_domain: "company_domain",
  company_domain_company: "company_domain",
  company_domain_contact: "company_domain",
  company_name_company: "company_name",
  company_name_contact: "company_name",
  company_has_phone: "company_phone_exists",
  company_keywords: "keywords",
  company_names: "company_name"
}

function resolveBucket(state: FilterState, sectionId: string): "company" | "contact" {
  const key = sectionToKey[sectionId] || sectionId
  // These keys MUST always go to the contact bucket regardless of page
  const contactKeys = ["job_title", "departments", "seniority"]
  if (state.searchContext === "companies") return "company"
  return contactKeys.includes(key) ? "contact" : "company"
}

function updateActiveFilters(
  state: FilterState,
  sectionId: string,
  item: SelectedFilter,
  actionType: "add" | "remove",
  bucketType?: "company" | "contact"
) {
  const key = sectionToKey[sectionId] || sectionId
  const resolvedBucketType = bucketType ?? resolveBucket(state, sectionId)
  const bucket = resolvedBucketType === "company" ? state.activeFilters.company : state.activeFilters.contact
  bucket[key] = bucket[key] || { include: [], exclude: [] }

  // Range
  const rv = item.value as RangeFilterValue | undefined
  if (rv && (rv.min !== undefined || rv.max !== undefined)) {
    bucket[key] = {
      ...bucket[key],
      range: { min: rv.min, max: rv.max }
    }
    return
  }

  // Presence toggles for existence filters
  if (sectionId.endsWith("_has_email") || sectionId.endsWith("_has_phone") || sectionId.endsWith("_exists")) {
    if (actionType === "add") {
      bucket[key].presence = item.type === "include" ? "known" : "unknown"
    } else if (actionType === "remove") {
      bucket[key].presence = "any"
    }
    return
  }

  // Include/Exclude lists
  if (actionType === "add") {
    if (item.type === "include") {
      bucket[key].include = Array.from(new Set([...(bucket[key].include || []), String(item.name)]))
    } else {
      bucket[key].exclude = Array.from(new Set([...(bucket[key].exclude || []), String(item.name)]))
    }
  } else {
    if (item.type === "include") {
      bucket[key].include = (bucket[key].include || []).filter((v) => v !== String(item.name))
    } else {
      bucket[key].exclude = (bucket[key].exclude || []).filter((v) => v !== String(item.name))
    }
  }
}

export function getSectionIdFromKey(key: string, isCompanyFilter: boolean): string | null {
  const keyToSection: Record<string, string> = {
    // Contact filters (FilterRegistry IDs)
    job_title: "job_title",
    department: "departments",
    seniority: "seniority",
    contact_country: "contact_country",
    contact_state: "contact_state",
    contact_city: "contact_city",
    work_email_exists: "contact_has_email",
    mobile_number_exists: "contact_has_phone",
    direct_number_exists: "contact_has_phone",

    // Company filters (FilterRegistry IDs)
    employee_count: "employee_count",
    annual_revenue: "annual_revenue",
    founded_year: "founded_year",
    business_category: "business_category",
    technologies: "technologies",
    company_domain: isCompanyFilter ? "company_domain_company" : "company_domain_contact",
    company_name: isCompanyFilter ? "company_name_company" : "company_name_contact",
    company_country: "company_country",
    company_state: "company_state",
    company_city: "company_city",
    keywords: "company_keywords"
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
      }>
    ) => {
      const { sectionId, item } = action.payload

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

      updateActiveFilters(state, sectionId, item, "add")
    },

    removeSelectedItem: (
      state,
      action: PayloadAction<{
        sectionId: string
        itemId: string
      }>
    ) => {
      const { sectionId, itemId } = action.payload

      if (state.selectedItems[sectionId]) {
        const removedItem = state.selectedItems[sectionId].find((item) => item.id === itemId)
        state.selectedItems[sectionId] = state.selectedItems[sectionId].filter((item) => item.id !== itemId)

        if (removedItem) {
          updateActiveFilters(state, sectionId, removedItem, "remove")
        }
      }
    },

    setSearchContext: (state, action: PayloadAction<"contacts" | "companies">) => {
      state.searchContext = action.payload
    },

    clearCompanyFilters: (state) => {
      const companySectionIds = [
        "employee_count",
        "annual_revenue",
        "founded_year",
        "business_category",
        "technologies",
        "company_domain",
        "company_name",
        "company_phone_exists",
        "company_linkedin_exists",
        "company_facebook_exists",
        "company_twitter_exists",
        "company_country",
        "company_state",
        "company_city",
        "company_keywords"
      ]

      companySectionIds.forEach((sectionId) => {
        delete state.selectedItems[sectionId]
      })

      // Clear active company filters
      state.activeFilters.company = {}
    },

    clearContactFilters: (state) => {
      const contactSectionIds = [
        "job_title",
        "departments",
        "seniority",
        "years_of_experience",
        "contact_country",
        "contact_state",
        "contact_city",
        "work_email_exists",
        "mobile_number_exists",
        "direct_number_exists"
      ]

      contactSectionIds.forEach((sectionId) => {
        delete state.selectedItems[sectionId]
      })

      // Clear active contact filters
      state.activeFilters.contact = {}
    },

    setRangeFilter: (state, action: PayloadAction<{ sectionId: string; range: RangeFilterValue }>) => {
      const { sectionId, range } = action.payload
      const name = `${range.min ?? "Any"}-${range.max ?? "Any"}`
      const itemId = `range_${sectionId}_${Date.now()}`

      state.selectedItems[sectionId] = (state.selectedItems[sectionId] || []).filter((item) => !item.id.startsWith(`range_${sectionId}_`))
      const rangeItem: SelectedFilter = { id: itemId, name, type: "include", value: range }
      state.selectedItems[sectionId].push(rangeItem)
      updateActiveFilters(state, sectionId, rangeItem, "add")
    },

    clearRangeFilter: (state, action: PayloadAction<{ sectionId: string }>) => {
      const { sectionId } = action.payload
      state.selectedItems[sectionId] = (state.selectedItems[sectionId] || []).filter((item) => !item.id.startsWith(`range_${sectionId}_`))
      const key = sectionToKey[sectionId] || sectionId
      const bucketType = resolveBucket(state, sectionId)
      const bucket = bucketType === "company" ? state.activeFilters.company : state.activeFilters.contact
      if (bucket[key]) {
        // remove range property when clearing range selection
        const af = bucket[key] as ActiveFilter
        if (af.range) {
          delete af.range
        }
        if (!bucket[key].include?.length && !bucket[key].exclude?.length && !bucket[key].presence && !bucket[key].operator) {
          delete bucket[key]
        }
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

        const bucket = value as { include?: unknown; exclude?: unknown; range?: { min?: number; max?: number }; operator?: "or" | "and" }
        const inc = Array.isArray(bucket.include) ? (bucket.include as string[]) : []
        const exc = Array.isArray(bucket.exclude) ? (bucket.exclude as string[]) : []

        if (inc.length > 0) {
          inc.forEach((itemName: string) => {
            const item: SelectedFilter = { id: `${sectionId}_${itemName}`, name: itemName, type: "include" }
            if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
            state.selectedItems[sectionId].push(item)
            updateActiveFilters(state, sectionId, item, "add", "contact")
          })
        }

        if (exc.length > 0) {
          exc.forEach((itemName: string) => {
            const item: SelectedFilter = { id: `${sectionId}_${itemName}_exclude`, name: itemName, type: "exclude" }
            if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
            state.selectedItems[sectionId].push(item)
            updateActiveFilters(state, sectionId, item, "add", "contact")
          })
        }

        if (bucket.range) {
          const r = bucket.range
          const rangeItem: SelectedFilter = {
            id: `${sectionId}_range_${r.min ?? ""}_${r.max ?? ""}`,
            name: `${r.min ?? "Any"}-${r.max ?? "Any"}`,
            type: "include",
            value: { min: r.min, max: r.max }
          }
          if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
          state.selectedItems[sectionId].push(rangeItem)
          updateActiveFilters(state, sectionId, rangeItem, "add", "contact")
        }
        if (bucket.operator) {
          const keyName = sectionToKey[sectionId] || sectionId
          if (!state.activeFilters.contact[keyName]) {
            state.activeFilters.contact[keyName] = { include: [], exclude: [], operator: bucket.operator }
          } else {
            state.activeFilters.contact[keyName].operator = bucket.operator
          }
        }
      })

      // Import company filters
      Object.entries(company).forEach(([key, value]) => {
        const sectionId = getSectionIdFromKey(key, true)
        if (!sectionId) return

        const bucket = value as { include?: unknown; exclude?: unknown; range?: { min?: number; max?: number }; operator?: "or" | "and" }
        const inc = Array.isArray(bucket.include) ? (bucket.include as string[]) : []
        const exc = Array.isArray(bucket.exclude) ? (bucket.exclude as string[]) : []

        if (inc.length > 0) {
          inc.forEach((itemName: string) => {
            const item: SelectedFilter = { id: `${sectionId}_${itemName}`, name: itemName, type: "include" }
            if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
            state.selectedItems[sectionId].push(item)
            updateActiveFilters(state, sectionId, item, "add", "company")
          })
        }

        if (exc.length > 0) {
          exc.forEach((itemName: string) => {
            const item: SelectedFilter = { id: `${sectionId}_${itemName}_exclude`, name: itemName, type: "exclude" }
            if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
            state.selectedItems[sectionId].push(item)
            updateActiveFilters(state, sectionId, item, "add", "company")
          })
        }

        if (bucket.range) {
          const r = bucket.range
          const rangeItem: SelectedFilter = {
            id: `${sectionId}_range_${r.min ?? ""}_${r.max ?? ""}`,
            name: `${r.min ?? "Any"}-${r.max ?? "Any"}`,
            type: "include",
            value: { min: r.min, max: r.max }
          }
          if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
          state.selectedItems[sectionId].push(rangeItem)
          updateActiveFilters(state, sectionId, rangeItem, "add", "company")
        }
        if (bucket.operator) {
          const keyName = sectionToKey[sectionId] || sectionId
          if (!state.activeFilters.company[keyName]) {
            state.activeFilters.company[keyName] = { include: [], exclude: [], operator: bucket.operator }
          } else {
            state.activeFilters.company[keyName].operator = bucket.operator
          }
        }
      })
    },
    importFiltersFromCanonical: (state, action: PayloadAction<FilterDSL>) => {
      state.expandedSections = {}
      state.searchTerms = {}
      state.selectedItems = {}
      state.activeFilters = { contact: {}, company: {} }

      const { contact = {}, company = {} } = action.payload

      Object.entries(contact).forEach(([key, bucket]) => {
        const sectionId = getSectionIdFromKey(key, false)
        if (!sectionId) return
        const inc = Array.isArray(bucket.include) ? bucket.include : []
        const exc = Array.isArray(bucket.exclude) ? bucket.exclude : []
        inc.forEach((name) => {
          const item: SelectedFilter = { id: `${sectionId}_${name}`, name, type: "include" }
          if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
          state.selectedItems[sectionId].push(item)
          updateActiveFilters(state, sectionId, item, "add", "contact")
        })
        exc.forEach((name) => {
          const item: SelectedFilter = { id: `${sectionId}_${name}_exclude`, name, type: "exclude" }
          if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
          state.selectedItems[sectionId].push(item)
          updateActiveFilters(state, sectionId, item, "add", "contact")
        })
        if (bucket.range) {
          const rangeItem: SelectedFilter = {
            id: `${sectionId}_range_${bucket.range.min ?? ""}_${bucket.range.max ?? ""}`,
            name: `${bucket.range.min ?? "Any"}-${bucket.range.max ?? "Any"}`,
            type: "include",
            value: { min: bucket.range.min, max: bucket.range.max }
          }
          if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
          state.selectedItems[sectionId].push(rangeItem)
          updateActiveFilters(state, sectionId, rangeItem, "add", "contact")
        }
        if (bucket.operator) {
          const keyName = sectionToKey[sectionId] || sectionId
          state.activeFilters.contact[keyName] = {
            ...(state.activeFilters.contact[keyName] || { include: [], exclude: [] }),
            operator: bucket.operator
          }
        }
        if (bucket.presence) {
          const keyName = sectionToKey[sectionId] || sectionId
          state.activeFilters.contact[keyName] = {
            ...(state.activeFilters.contact[keyName] || { include: [], exclude: [] }),
            presence: bucket.presence
          }
        }
      })

      Object.entries(company).forEach(([key, bucket]) => {
        const sectionId = getSectionIdFromKey(key, true)
        if (!sectionId) return
        const inc = Array.isArray(bucket.include) ? bucket.include : []
        const exc = Array.isArray(bucket.exclude) ? bucket.exclude : []
        inc.forEach((name) => {
          const item: SelectedFilter = { id: `${sectionId}_${name}`, name, type: "include" }
          if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
          state.selectedItems[sectionId].push(item)
          updateActiveFilters(state, sectionId, item, "add", "company")
        })
        exc.forEach((name) => {
          const item: SelectedFilter = { id: `${sectionId}_${name}_exclude`, name, type: "exclude" }
          if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
          state.selectedItems[sectionId].push(item)
          updateActiveFilters(state, sectionId, item, "add", "company")
        })
        if (bucket.range) {
          const rangeItem: SelectedFilter = {
            id: `${sectionId}_range_${bucket.range.min ?? ""}_${bucket.range.max ?? ""}`,
            name: `${bucket.range.min ?? "Any"}-${bucket.range.max ?? "Any"}`,
            type: "include",
            value: { min: bucket.range.min, max: bucket.range.max }
          }
          if (!state.selectedItems[sectionId]) state.selectedItems[sectionId] = []
          state.selectedItems[sectionId].push(rangeItem)
          updateActiveFilters(state, sectionId, rangeItem, "add", "company")
        }
        if (bucket.operator) {
          const keyName = sectionToKey[sectionId] || sectionId
          state.activeFilters.company[keyName] = {
            ...(state.activeFilters.company[keyName] || { include: [], exclude: [] }),
            operator: bucket.operator
          }
        }
        if (bucket.presence) {
          const keyName = sectionToKey[sectionId] || sectionId
          state.activeFilters.company[keyName] = {
            ...(state.activeFilters.company[keyName] || { include: [], exclude: [] }),
            presence: bucket.presence
          }
        }
      })
    },
    setBucketOperator: (state, action: PayloadAction<{ bucket: "contact" | "company"; key: string; operator: "or" | "and" }>) => {
      const { bucket, key, operator } = action.payload
      if (!state.activeFilters[bucket][key]) {
        state.activeFilters[bucket][key] = { include: [], exclude: [], operator }
      } else {
        state.activeFilters[bucket][key].operator = operator
      }
    },
    setFilterPresence: (state, action: PayloadAction<{ bucket: "contact" | "company"; key: string; presence: "any" | "known" | "unknown" }>) => {
      const { bucket, key, presence } = action.payload
      if (!state.activeFilters[bucket][key]) {
        state.activeFilters[bucket][key] = { include: [], exclude: [], presence }
      } else {
        state.activeFilters[bucket][key].presence = presence
      }
    },

    setFilterMode: (state, action: PayloadAction<{ bucket: "contact" | "company"; key: string; mode: "all" | "any" }>) => {
      const { bucket, key, mode } = action.payload
      if (!state.activeFilters[bucket][key]) {
        state.activeFilters[bucket][key] = { include: [], exclude: [], mode }
      } else {
        state.activeFilters[bucket][key].mode = mode
      }
    },

    setFilterFields: (state, action: PayloadAction<{ bucket: "contact" | "company"; key: string; fields: string[] }>) => {
      const { bucket, key, fields } = action.payload
      if (!state.activeFilters[bucket][key]) {
        state.activeFilters[bucket][key] = { include: [], exclude: [], fields }
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
  importFiltersFromCanonical,
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
