export type FilterType = "company" | "contact"
export type SelectionType = "include" | "exclude"

export interface RangeFilterValue {
  min?: number
  max?: number
}

export interface ActiveFilter {
  include: string[]
  exclude: string[]
  ranges?: RangeFilterValue[]
  range?: RangeFilterValue
  operator?: "or" | "and" // Legacy operator for simple lists
  presence?: "any" | "known" | "unknown" // For Business Category
  mode?: "all" | "any" // For Keywords (and future use)
  fields?: string[] // For Keywords (e.g. ['name', 'description'])
}

export interface SelectedFilter {
  id: string
  name: string
  type: SelectionType
  value?: unknown
}

export interface FilterState {
  expandedSections: Record<string, boolean>
  searchTerms: Record<string, string>
  selectedItems: Record<string, SelectedFilter[]>

  searchContext: "contacts" | "companies"
  activeFilters: {
    contact: Record<string, ActiveFilter>
    company: Record<string, ActiveFilter>
  }
}
