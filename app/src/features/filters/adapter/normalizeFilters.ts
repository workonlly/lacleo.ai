// app/src/features/filters/adapter/normalizeFilters.ts

import { IFilter, IFilterGroup } from "@/interface/filters/filterGroup"

export interface NormalizedFilter extends IFilter {
  range?: {
    min: number
    max: number
    step: number
    unit?: string
  }
}

export interface NormalizedFilterGroup extends Omit<IFilterGroup, "filters"> {
  filters: NormalizedFilter[]
}

export function normalizeFilters(apiGroups: IFilterGroup[]): NormalizedFilterGroup[] {
  return apiGroups.map((group) => ({
    ...group,
    filters: group.filters.map((f) => {
      const anyF = f as unknown as {
        search?: { enabled?: boolean }
        input?: string
        filtering?: { supports_exclusion?: boolean }
      }
      const searchEnabled = f.is_searchable || anyF.search?.enabled || false

      return {
        ...f,
        is_searchable: searchEnabled,
        supports_value_lookup: f.supports_value_lookup || searchEnabled,
        input_type: (f.input_type || anyF.input) as IFilter["input_type"],
        allows_exclusion: f.allows_exclusion || anyF.filtering?.supports_exclusion || false,
        range: inferRange(f)
      }
    })
  }))
}

function inferRange(filter: IFilter): NormalizedFilter["range"] {
  // Prefer server-driven aggregation ranges when provided
  const anyFilter = filter as unknown as { aggregation?: { type?: string; ranges?: Array<{ from?: number; to?: number }> } }
  const agg = anyFilter.aggregation
  if (agg && agg.type === "range" && Array.isArray(agg.ranges) && agg.ranges.length) {
    const mins = agg.ranges.map((r) => (typeof r.from === "number" ? r.from : undefined)).filter((v): v is number => v != null)
    const maxs = agg.ranges.map((r) => (typeof r.to === "number" ? r.to : undefined)).filter((v): v is number => v != null)
    const min = mins.length ? Math.min(...mins) : 0
    const max = maxs.length ? Math.max(...maxs) : mins.length ? Math.max(...mins) : 0
    return { min, max, step: 1 }
  }

  // Fallbacks for legacy filters without server ranges
  const currentYear = new Date().getFullYear()
  if (filter.id.includes("founded_year")) return { min: 1900, max: currentYear, step: 1, unit: "year" }
  return undefined
}
