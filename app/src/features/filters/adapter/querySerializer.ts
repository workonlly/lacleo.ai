// app/src/features/filters/adapter/querySerializer.ts

import { SelectedFilter, RangeFilterValue, ActiveFilter } from "@/interface/filters/slice"
import { sectionToKey } from "@/features/filters/slice/filterSlice"

export type FilterBucketEntry = {
  include?: string[]
  exclude?: string[]
  range?: { min?: number; max?: number }
  presence?: "any" | "known" | "unknown"
  operator?: "and" | "or"
}

export interface FilterDSL {
  contact?: Record<string, FilterBucketEntry>
  company?: Record<string, FilterBucketEntry>
}

function resolveBucket(sectionId: string, searchContext: "contacts" | "companies"): "company" | "contact" {
  const contactOnlyIds = new Set([
    "job_title",
    "seniority",
    "departments",
    "years_of_experience",
    "work_email_exists",
    "mobile_number_exists",
    "direct_number_exists",
    "contact_country",
    "contact_state",
    "contact_city"
  ])

  if (sectionId.startsWith("contact_") || contactOnlyIds.has(sectionId)) return "contact"
  return "company"
}

function isRangeValue(v: unknown): v is RangeFilterValue {
  const r = v as RangeFilterValue | undefined
  return r?.min !== undefined || r?.max !== undefined
}

export function serializeToDSL(
  selectedItems: Record<string, SelectedFilter[]>,
  activeFilters: { contact: Record<string, ActiveFilter>; company: Record<string, ActiveFilter> },
  searchContext: "contacts" | "companies" = "contacts"
): FilterDSL {
  const dsl: FilterDSL = { contact: {}, company: {} }

  Object.entries(selectedItems).forEach(([sectionId, items]) => {
    if (!items || items.length === 0) return

    const bucketKey = sectionToKey[sectionId] || sectionId
    const bucketType = resolveBucket(sectionId, searchContext)
    const bucket = bucketType === "company" ? (dsl.company![bucketKey] ||= {}) : (dsl.contact![bucketKey] ||= {})

    const includeItems = items.filter((i) => i.type === "include")
    const excludeItems = items.filter((i) => i.type === "exclude")

    // Range
    const rangeItem = items.find((item) => isRangeValue(item.value))
    if (rangeItem?.value) {
      const rv = rangeItem.value as RangeFilterValue
      bucket.range = { min: rv.min, max: rv.max }
    }

    // Presence from activeFilters
    const af = bucketType === "company" ? activeFilters.company[bucketKey] : activeFilters.contact[bucketKey]
    if (af?.presence) {
      bucket.presence = af.presence
    }

    // Include/Exclude values (prefer explicit value, fallback to name, then id)
    const presenceActive = bucket.presence === "known" || bucket.presence === "unknown"
    if (!presenceActive) {
      const incVals = includeItems
        .filter((i) => !((i.value as RangeFilterValue | undefined)?.min || (i.value as RangeFilterValue | undefined)?.max))
        .map((i) => String((i.value as string | undefined) ?? i.name ?? i.id))
      const excVals = excludeItems.map((i) => String((i.value as string | undefined) ?? i.name ?? i.id))
      if (incVals.length) bucket.include = incVals
      if (excVals.length) bucket.exclude = excVals
    }

    // Operator from activeFilters if set
    if (af?.operator) bucket.operator = af.operator
  })

  // Cleanup empty buckets
  if (dsl.contact && Object.keys(dsl.contact).length === 0) delete dsl.contact
  if (dsl.company && Object.keys(dsl.company).length === 0) delete dsl.company

  return dsl
}
