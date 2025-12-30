import { useFilterSearch } from "@/app/hooks/filterValuesSearchHook"
import { useAppDispatch, useAppSelector } from "@/app/hooks/reduxHooks"
import { Button } from "@/components/ui/button"
import { Card } from "@/components/ui/card"
import { RangeSlider } from "@/components/ui/rangeslider"
import { IFilter, IFilterGroup } from "@/interface/filters/filterGroup"
import { SelectedFilter } from "@/interface/filters/slice"
import { CheckCircle, Minus, Plus, Save, Search, Upload, Filter } from "lucide-react"
import { useEffect, useMemo, useState } from "react"
import { useLocation } from "react-router-dom"
import FilterSearchValueResults from "./filterValues"
import { useGetFiltersQuery } from "./slice/apiSlice"
import { setShowResults } from "../aisearch/slice/searchslice"
import SaveFilter from "@/components/ui/modals/savefilter"
import Checkbox from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import BulkCompanyInputDialog from "./components/BulkCompanyInputDialog"
import {
  addSelectedItem,
  removeSelectedItem,
  resetFilters,
  selectExpandedSections,
  selectSearchTerms,
  selectSelectedItems,
  selectCompanyFilters,
  selectContactFilters,
  selectActiveFilters,
  sectionToKey,
  setSearchTerm,
  toggleSection,
  setRangeFilter,
  clearRangeFilter,
  setBucketOperator,
  setFilterPresence,
  setSearchContext
} from "./slice/filterSlice"
import { normalizeFilters, NormalizedFilter } from "@/features/filters/adapter/normalizeFilters"

const EMPTY_SELECTED: SelectedFilter[] = []

const FilterTag = ({ item, onRemove }: { item: SelectedFilter; onRemove: () => void }) => (
  <span
    className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-normal transition-colors ${
      item.type === "include"
        ? "bg-[#335CFF] text-white ring-1 ring-emerald-200/50 dark:bg-[#335CFF] dark:text-white dark:ring-[#335CFF]"
        : "bg-red-200 text-red-950 ring-1 ring-red-200/50 dark:bg-red-950/50 dark:text-red-400 dark:ring-red-800/50"
    }`}
  >
    {item.name}
    <button onClick={onRemove} className="rounded-full p-0.5 transition-colors hover:bg-black/10 dark:hover:bg-white/10" aria-label="Remove filter">
      <svg className="size-3.5" viewBox="0 0 20 20" fill="currentColor">
        <path
          fillRule="evenodd"
          d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
          clipRule="evenodd"
        />
      </svg>
    </button>
  </span>
)

const SectionHeader = ({ title, expanded, onClick }: { title: string; expanded: boolean; onClick: () => void }) => (
  <button
    onClick={onClick}
    className="group flex w-full items-center justify-between p-3.5 transition-colors hover:bg-gray-50/50 dark:hover:bg-gray-900/50"
  >
    <span className="text-sm font-medium text-gray-950 dark:text-gray-200">{title}</span>

    <span className="text-gray-400 dark:text-gray-500">{expanded ? <Minus className="size-4" /> : <Plus className="size-4" />}</span>
  </button>
)

export const Filters = () => {
  const location = useLocation()
  const isPeoplePage = location.pathname === "/app/search/contacts"
  const dispatch = useAppDispatch()

  const [isSaveOpen, setIsSaveOpen] = useState(false)
  const [isBulkOpen, setIsBulkOpen] = useState(false)

  const expandedSections = useAppSelector(selectExpandedSections)
  const searchTerms = useAppSelector(selectSearchTerms)
  const selectedItems = useAppSelector(selectSelectedItems)
  const activeFilters = useAppSelector(selectActiveFilters)
  const companyFilters = useAppSelector(selectCompanyFilters)
  const contactFilters = useAppSelector(selectContactFilters)

  const selectedItemsMemo = useMemo(() => {
    const out: Record<string, SelectedFilter[]> = {}
    Object.entries(selectedItems).forEach(([k, v]) => {
      out[k] = Array.isArray(v) ? v.slice() : []
    })
    return out
  }, [selectedItems])

  const appliedCount = Object.values(selectedItems).reduce((sum, items) => sum + (items?.length || 0), 0)

  const { currentData: filterGroups = [] } = useGetFiltersQuery()

  // Keep filter search context aligned with current page (contacts vs companies)
  useEffect(() => {
    dispatch(setSearchContext(isPeoplePage ? "contacts" : "companies"))
  }, [dispatch, isPeoplePage])

  // Normalize filters using the adapter
  const normalizedFilterGroups = useMemo(() => {
    return normalizeFilters(filterGroups)
  }, [filterGroups])

  const { searchState, handleSearch, clearSearchResults } = useFilterSearch()

  const handleSearchChange = async (filter: IFilter, term: string) => {
    dispatch(setSearchTerm({ sectionId: filter.id, term }))
    if (term) {
      await handleSearch(filter, term)
    } else {
      clearSearchResults(filter.id)
    }
  }

  const handleRangeChange = (sectionId: string, range: [number, number]) => {
    const [min, max] = range
    dispatch(setRangeFilter({ sectionId, range: { min, max } }))
  }
  const handleRangeInputApply = (sectionId: string, minStr: string, maxStr: string) => {
    const min = minStr !== "" ? Number(minStr) : undefined
    const max = maxStr !== "" ? Number(maxStr) : undefined
    if (min === undefined && max === undefined) {
      dispatch(clearRangeFilter({ sectionId }))
    } else {
      dispatch(setRangeFilter({ sectionId, range: { min, max } }))
    }
  }
  const handleRangeInputClear = (sectionId: string) => {
    dispatch(clearRangeFilter({ sectionId }))
  }

  const currentFiltersGroups: IFilterGroup[] = useMemo(() => {
    // Map to track seen DSL keys per group to prevent duplicates
    const seenDslKeys = new Set<string>()

    const groups = normalizedFilterGroups
      .map((group) => {
        let filters = group.filters.filter((filter) => {
          const dslKey = sectionToKey[filter.id] || filter.id

          if (isPeoplePage) {
            // Hide explicit duplicates for contact search
            if (filter.id === "company_name_contact") return false
            if (filter.id === "company_domain_contact") return false

            if (filter.filter_type === "contact") return true
            // Show all company filters on contacts page
            if (filter.filter_type === "company") return true
          }

          // For Company page, only show company filters

          return filter.filter_type === "company"
        })

        // Deduplicate based on DSL key to prevent visual redundancy
        filters = filters.filter((f) => {
          const key = sectionToKey[f.id] || f.id
          const uniqueKey = `${group.group_id}_${key}`
          if (seenDslKeys.has(uniqueKey)) return false
          seenDslKeys.add(uniqueKey)
          return true
        })

        // Reorder Company Group
        if (group.group_name.toLowerCase() === "company") {
          const priorityMap: Record<string, number> = {
            company_name_company: 1,
            company_domain_company: 2,
            business_category: 3,
            technologies: 4,
            company_technologies: 4,
            employee_count: 5,
            company_headcount: 5,
            company_headcount_contact: 5,
            company_revenue: 6,
            company_revenue_range: 6,
            annual_revenue: 7,
            founded_year: 8,
            company_location: 10,
            company_headquarters: 10,
            company_country: 11,
            company_state: 12,
            company_city: 13,
            company_founded_year: 14
          }

          const getP = (id: string) => priorityMap[id] || 100

          filters.sort((a, b) => {
            const pA = getP(a.id)
            const pB = getP(b.id)
            return pA - pB
          })

          // Rename filters for better UX
          filters = filters.map((f) => {
            if (f.id.includes("employee_count") || f.id.includes("company_headcount")) {
              return { ...f, name: "Company Size / Employee" }
            }
            if (f.id === "company_revenue" || f.id === "company_revenue_range") {
              return { ...f, name: "Company Revenue" }
            }
            if (f.id === "company_name_company") {
              return { ...f, name: "Company Name" }
            }
            if (f.id === "company_domain_company") {
              return { ...f, name: "Company Domain" }
            }
            return f
          })
        }

        return { ...group, filters }
      })
      .filter((group) => group.filters.length > 0)

    return groups
  }, [normalizedFilterGroups, isPeoplePage])

  useEffect(() => {
    const isSearchPage = location.pathname.startsWith("/app/search/")
    if (!isSearchPage) {
      dispatch(resetFilters())
    }
  }, [dispatch, location.pathname])

  useEffect(() => {
    const filters = currentFiltersGroups.flatMap((group) => group.filters).filter((filter) => filter.input_type !== "text")
    Object.entries(expandedSections).forEach(([filterId, isExpanded]) => {
      const filter = filters.find((filter) => filter.id === filterId)
      if (isExpanded && filter) handleSearch(filter)
    })
  }, [expandedSections, currentFiltersGroups, handleSearch])

  // Handle range filter changes

  return (
    <div className="flex flex-col">
      <Card className="mt-4 max-h-[calc(100vh-200px)] min-h-0 min-w-full overflow-auto  rounded-[10px] border bg-white p-4 dark:border-gray-800 dark:bg-gray-950">
        <div className="mb-4 flex items-center justify-between border-b pb-4">
          <span className="flex items-center gap-1 text-sm font-medium text-gray-950">
            <Filter /> Filter
          </span>
          {appliedCount > 0 && (
            <div className="flex size-4 items-center justify-center rounded-full border border-blue-500 bg-blue-500 p-0.5">
              <span className="text-xs font-normal text-white">{appliedCount}</span>
            </div>
          )}
          <div className="flex flex-row gap-2">
            <Button
              className="border border-gray-200 bg-white p-2 text-sm text-gray-600 hover:bg-transparent"
              onClick={() => {
                dispatch(resetFilters())
              }}
            >
              Clear All
            </Button>
            <Button className="border border-gray-200 bg-white p-2 text-sm text-gray-600 hover:bg-transparent" onClick={() => setIsSaveOpen(true)}>
              <Save /> Save filters
            </Button>
            <Button
              className="border border-blue-600 bg-blue-600 p-2 text-sm text-white hover:bg-blue-700"
              onClick={() => {
                dispatch(setShowResults(true))
              }}
            >
              Apply
            </Button>
          </div>
        </div>
        {currentFiltersGroups.map((group) => (
          <div key={group.group_name} className="flex flex-col gap-4">
            <div className="mt-4 px-2 py-1">
              <h2 className="text-xs font-semibold text-gray-600 dark:text-gray-400">{group.group_name}</h2>
            </div>
            <Card className="overflow-hidden rounded-lg border bg-transparent shadow-none dark:bg-transparent">
              {group.filters
                .filter((filter) => (isPeoplePage ? ["contact", "company"].includes(filter.filter_type) : filter.filter_type === "company"))
                .map((filter) => {
                  const normalizedFilter = filter as NormalizedFilter
                  const key = sectionToKey[filter.id] || filter.id
                  const selectedForSection = selectedItemsMemo[filter.id] || []
                  const rangeItem = selectedForSection.find((it) => {
                    const v = it.value as { min?: number; max?: number } | undefined
                    return !!v && (v.min !== undefined || v.max !== undefined)
                  })
                  const currentRange = (rangeItem?.value as { min?: number; max?: number }) || undefined

                  return (
                    <div key={filter.id}>
                      <div
                        className={`border-b border-gray-100 dark:border-gray-800/50 ${
                          expandedSections[filter.id] ? "bg-[#F7F7F7] dark:bg-gray-800/80" : "bg-white dark:bg-transparent"
                        }`}
                      >
                        <SectionHeader
                          title={filter.name}
                          expanded={expandedSections[filter.id]}
                          onClick={() => dispatch(toggleSection(filter.id))}
                        />
                        {!!expandedSections[filter.id] && (
                          <div className="space-y-3 p-4">
                            {["company_domain", "company_name"].includes(filter.id) && (
                              <div className="flex w-full justify-end">
                                <Button variant="outline" className="px-2 text-xs" onClick={() => setIsBulkOpen(true)}>
                                  <Upload className="mr-1 size-4" /> Bulk add
                                </Button>
                              </div>
                            )}
                            {filter.input_type === "text" ? (
                              <form
                                className="relative"
                                onSubmit={(e) => {
                                  e.preventDefault()
                                  e.stopPropagation() // Prevents the event from bubbling to parent components

                                  const value = searchTerms[filter.id] || ""
                                  if (value.trim()) {
                                    dispatch(
                                      addSelectedItem({
                                        sectionId: filter.id,
                                        item: { id: value, name: value, type: "include" }
                                      })
                                    )
                                    dispatch(setShowResults(true))
                                  }
                                }}
                              >
                                <input
                                  type="text"
                                  className="w-full rounded-lg border border-gray-200 bg-white py-2 pl-2 pr-9 text-sm text-gray-900 transition-shadow placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:ring-gray-700"
                                  placeholder={`Search ${filter.name.toLowerCase()}`}
                                  value={searchTerms[filter.id] || ""}
                                  onChange={(e) => handleSearchChange(filter, e.target.value)}
                                  onKeyDown={(e) => {
                                    if (e.key === "Enter") {
                                      e.preventDefault()
                                      e.stopPropagation()
                                      const val = searchTerms[filter.id]
                                      if (val) {
                                        dispatch(
                                          addSelectedItem({
                                            sectionId: filter.id,
                                            item: { id: val, name: val, type: "include" }
                                          })
                                        )
                                        dispatch(setShowResults(true))
                                      }
                                    }
                                  }}
                                />
                                <button type="submit">
                                  <CheckCircle className="absolute right-3 top-2.5 size-4 cursor-pointer text-green-400 dark:text-green-500" />
                                </button>
                              </form>
                            ) : filter.is_searchable ? (
                              <div className="relative">
                                <Search className="absolute left-3 top-2.5 size-4 text-gray-400 dark:text-gray-500" />
                                <input
                                  type="text"
                                  className="w-full rounded-lg border border-gray-200 bg-white py-2 pl-9 text-sm text-gray-900 transition-shadow placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:ring-gray-700"
                                  placeholder={`Search ${filter.name.toLowerCase()}`}
                                  value={searchTerms[filter.id] || ""}
                                  onChange={(e) => handleSearchChange(filter, e.target.value)}
                                  onKeyDown={(e) => {
                                    if (e.key === "Enter") {
                                      e.preventDefault()
                                      const val = searchTerms[filter.id]
                                      if (val && val.trim()) {
                                        dispatch(
                                          addSelectedItem({
                                            sectionId: filter.id,
                                            item: { id: val, name: val, type: "include" }
                                          })
                                        )

                                        // Optional: clear search term after adding
                                        dispatch(setSearchTerm({ sectionId: filter.id, term: "" }))
                                      }
                                    }
                                  }}
                                />
                              </div>
                            ) : null}

                            {(() => {
                              const bucket: "company" | "contact" = normalizedFilter.filter_type === "company" ? "company" : "contact"
                              const key = sectionToKey[filter.id] || filter.id
                              const af = bucket === "company" ? companyFilters : contactFilters

                              return (
                                <>
                                  {filter.id === "company_keywords" && (
                                    <div className="flex items-center gap-2">
                                      <Select
                                        value={(af[key]?.operator as "and" | "or") || "or"}
                                        onValueChange={(val) => {
                                          dispatch(setBucketOperator({ bucket, key, operator: val as "and" | "or" }))
                                        }}
                                      >
                                        <SelectTrigger className="w-40">
                                          <SelectValue placeholder="Operator" />
                                        </SelectTrigger>
                                        <SelectContent>
                                          <SelectItem value="or">Match any</SelectItem>
                                          <SelectItem value="and">Match all</SelectItem>
                                        </SelectContent>
                                      </Select>
                                    </div>
                                  )}

                                  {filter.id === "business_category" && (
                                    <div className="flex items-center gap-2">
                                      <Select
                                        value={(af[key]?.presence as "any" | "known" | "unknown") || "any"}
                                        onValueChange={(val) => {
                                          dispatch(setFilterPresence({ bucket, key, presence: val as "any" | "known" | "unknown" }))
                                        }}
                                      >
                                        <SelectTrigger className="w-44">
                                          <SelectValue placeholder="Presence" />
                                        </SelectTrigger>
                                        <SelectContent>
                                          <SelectItem value="any">Any</SelectItem>
                                          <SelectItem value="known">Known only</SelectItem>
                                          <SelectItem value="unknown">Unknown only</SelectItem>
                                        </SelectContent>
                                      </Select>
                                    </div>
                                  )}
                                </>
                              )
                            })()}

                            {/* Range Input for numeric filters */}
                            {filter.input_type === "range" && !!normalizedFilter.range && (
                              <div className="space-y-4">
                                <RangeSlider
                                  min={normalizedFilter.range.min}
                                  max={normalizedFilter.range.max}
                                  step={normalizedFilter.range.step}
                                  unit={normalizedFilter.range.unit}
                                  value={[currentRange?.min ?? normalizedFilter.range.min, currentRange?.max ?? normalizedFilter.range.max]}
                                  onChange={(range) => handleRangeChange(filter.id, range)}
                                  hideHeader={false}
                                />
                                <div className="flex items-center gap-2">
                                  <Input
                                    type="number"
                                    placeholder="Min"
                                    value={currentRange?.min?.toString() || ""}
                                    onChange={(e) => {
                                      const min = e.target.value
                                      const max = currentRange?.max?.toString() || ""
                                      handleRangeInputApply(filter.id, min, max)
                                    }}
                                  />
                                  <Input
                                    type="number"
                                    placeholder="Max"
                                    value={currentRange?.max?.toString() || ""}
                                    onChange={(e) => {
                                      const min = currentRange?.min?.toString() || ""
                                      const max = e.target.value
                                      handleRangeInputApply(filter.id, min, max)
                                      // REMOVE auto-dispatch from here
                                    }}
                                  />
                                  <Button variant="outline" onClick={() => handleRangeInputClear(filter.id)}>
                                    Clear
                                  </Button>
                                </div>
                              </div>
                            )}

                            {filter.input_type === "toggle" && (
                              <div className="flex items-center gap-4">
                                <label className="flex items-center gap-2 text-sm">
                                  <Checkbox
                                    checked={(selectedItems[filter.id] || []).some((i) => i.type === "include")}
                                    onChange={(checked: boolean) => {
                                      // Remove any existing entries
                                      const existing = selectedItems[filter.id] ?? EMPTY_SELECTED
                                      for (const it of existing) {
                                        dispatch(removeSelectedItem({ sectionId: filter.id, itemId: it.id }))
                                      }
                                      if (checked) {
                                        dispatch(
                                          addSelectedItem({ sectionId: filter.id, item: { id: `${filter.id}_has`, name: "Has", type: "include" } })
                                        )
                                      }
                                    }}
                                  />
                                  Has
                                </label>
                                <label className="flex items-center gap-2 text-sm">
                                  <Checkbox
                                    checked={(selectedItems[filter.id] || []).some((i) => i.type === "exclude")}
                                    onChange={(checked: boolean) => {
                                      const existing = selectedItems[filter.id] ?? EMPTY_SELECTED
                                      for (const it of existing) {
                                        dispatch(removeSelectedItem({ sectionId: filter.id, itemId: it.id }))
                                      }
                                      if (checked) {
                                        dispatch(
                                          addSelectedItem({
                                            sectionId: filter.id,
                                            item: { id: `${filter.id}_not`, name: "Does not have", type: "exclude" }
                                          })
                                        )
                                      }
                                    }}
                                  />
                                  Does not have
                                </label>
                              </div>
                            )}

                            {(() => {
                              const s = selectedItemsMemo[filter.id] || EMPTY_SELECTED
                              return (
                                <>
                                  {s.length > 0 && (
                                    <div className="flex flex-wrap gap-2">
                                      {s.map((item) => (
                                        <FilterTag
                                          key={item.id}
                                          item={item}
                                          onRemove={() => {
                                            dispatch(removeSelectedItem({ sectionId: filter.id, itemId: item.id }))
                                          }}
                                        />
                                      ))}
                                    </div>
                                  )}

                                  {!!filter.supports_value_lookup &&
                                    (() => {
                                      const r = searchState.results[filter.id] as (typeof searchState.results)[string]
                                      const m = searchState.metadata[filter.id]
                                      const l = searchState.isLoading[filter.id]
                                      const err = searchState.error[filter.id]
                                      return (
                                        <div className="mt-2">
                                          <FilterSearchValueResults
                                            results={r}
                                            metadata={m}
                                            isLoading={l}
                                            error={err}
                                            selectedItems={s}
                                            canExclude={filter.allows_exclusion}
                                            onInclude={(item) => {
                                              dispatch(
                                                addSelectedItem({
                                                  sectionId: filter.id,
                                                  item: { ...item, type: "include" }
                                                })
                                              )
                                            }}
                                            onExclude={(item) => {
                                              dispatch(
                                                addSelectedItem({
                                                  sectionId: filter.id,
                                                  item: { ...item, type: "exclude" }
                                                })
                                              )
                                              dispatch(setShowResults(true))
                                            }}
                                            onRemove={(item) => {
                                              dispatch(removeSelectedItem({ sectionId: filter.id, itemId: item.id }))
                                              dispatch(setShowResults(true))
                                            }}
                                          />
                                        </div>
                                      )
                                    })()}
                                </>
                              )
                            })()}
                          </div>
                        )}
                      </div>
                    </div>
                  )
                })}
            </Card>
          </div>
        ))}
      </Card>
      <SaveFilter open={isSaveOpen} onOpenChange={setIsSaveOpen} entityType={isPeoplePage ? "contact" : "company"} />
      <BulkCompanyInputDialog open={isBulkOpen} onOpenChange={setIsBulkOpen} />
    </div>
  )
}

export default Filters
