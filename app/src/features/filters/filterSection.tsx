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
import SaveFilter from "@/components/ui/modals/savefilter"
import Checkbox from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select"
import { IndustryFilter } from "./components/IndustryFilter"
import { JobTitleFilter } from "./components/JobTitleFilter"
import { DepartmentFilter } from "./components/DepartmentFilter"
import { TechnologyFilter } from "./components/TechnologyFilter"
import { LocationFilter } from "./components/LocationFilter"
import { LocationFieldFilter } from "./components/LocationFieldFilter"
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
  sectionToKey,
  setSearchTerm,
  toggleSection
} from "./slice/filterSlice"

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
  // Default YOE range
  const [yoeRange, setYoeRange] = useState<[number, number]>([0, 5])
  const personalSectionId = "years_of_experience"

  const expandedSections = useAppSelector(selectExpandedSections)
  const searchTerms = useAppSelector(selectSearchTerms)
  const selectedItems = useAppSelector(selectSelectedItems)
  const appliedCount = Object.values(selectedItems).reduce((sum, items) => sum + (items?.length || 0), 0)

  const { currentData: filterGroups = [] } = useGetFiltersQuery()
  const { searchState, handleSearch, clearSearchResults } = useFilterSearch()

  // Sync Local YOE State with Redux (One-way: Redux -> Local on mount/change)
  useEffect(() => {
    const yoeFilter = selectedItems[personalSectionId]?.[0]
    if (yoeFilter) {
      // Parse "min-max" string back to numbers
      const parts = yoeFilter.id.split("-").map(Number)
      if (parts.length === 2 && !isNaN(parts[0]) && !isNaN(parts[1])) {
        // Only update if different to avoid loop
        setYoeRange((prev) => (prev[0] === parts[0] && prev[1] === parts[1] ? prev : [parts[0], parts[1]]))
      }
    } else {
      // If cleared in Redux, reset local state
      setYoeRange((prev) => (prev[0] === 0 && prev[1] === 5 ? prev : [0, 5]))
    }
  }, [selectedItems, personalSectionId])

  const handleSearchChange = async (filter: IFilter, term: string) => {
    dispatch(setSearchTerm({ sectionId: filter.id, term }))
    if (term) {
      await handleSearch(filter, term)
    } else {
      clearSearchResults(filter.id)
    }
  }
  const toggleCompanyOption = (sectionId: string, option: string) => {
    const isSelected = selectedItems[sectionId]?.some((item) => item.id === option)
    if (isSelected) {
      dispatch(removeSelectedItem({ sectionId, itemId: option }))
    } else {
      dispatch(addSelectedItem({ sectionId, item: { id: option, name: option, type: "include" } }))
    }
  }

  const currentYoeId = selectedItems[personalSectionId]?.[0]?.id || null

  // Debounce YOE updates (Local -> Redux)
  useEffect(() => {
    const timer = setTimeout(() => {
      const rangeString = `${yoeRange[0]}-${yoeRange[1]}`
      // If local state matches Redux, do nothing (prevents loop)
      if (currentYoeId === rangeString) return

      // If [0,5] (default) and Redux is empty, do nothing (don't force default filter)
      if (yoeRange[0] === 0 && yoeRange[1] === 5 && !currentYoeId) return

      // Update Redux
      if (currentYoeId) {
        dispatch(removeSelectedItem({ sectionId: personalSectionId, itemId: currentYoeId }))
      }
      // Only dispatch if it's NOT the default [0,5] OR if user explicitly changed it
      // Actually, if we want [0,5] to be a filter, we should send it.
      // But usually "Clear All" means no filter.
      // Let's assume [0,5] is default "no filter" or "all ranges"?
      // User likely wants to filter.
      dispatch(
        addSelectedItem({ sectionId: personalSectionId, item: { id: rangeString, name: `${yoeRange[0]}-${yoeRange[1]} Years`, type: "include" } })
      )
    }, 500)
    return () => clearTimeout(timer)
  }, [yoeRange, dispatch, personalSectionId, currentYoeId])

  // Helper to get options
  const getOptionsForSection = (section: "employeeCount" | "companyRevenue" | "annualRevenue" | "foundedYear" | "totalFunding") => {
    if (section === "employeeCount") return ["1-10", "11-50", "51-200", "201-500", "501-1000", "1001-5000", "5001-10000", "10000+"]
    if (section === "companyRevenue") return ["0-1M", "1M-10M", "10M-50M", "50M-100M", "100M-250M", "250M-500M", "500M-1B", "1B-10B", "10B+"]
    if (section === "annualRevenue") return ["0-1M", "1M-10M", "10M-50M", "50M-100M", "100M-250M", "250M-500M", "500M-1B", "1B-10B", "10B+"]
    if (section === "totalFunding") return ["0-1M", "1M-10M", "10M-50M", "50M-100M", "100M-500M", "500M-1B", "1B+"]
    if (section === "foundedYear") return ["Before 1950", "1950-1970", "1970-1990", "1990-2000", "2000-2010", "2010-2015", "2015-2020", "2020+"]
    return []
  }

  const renderCheckboxListSection = (args: {
    id: "employee_count" | "company_revenue" | "annual_revenue" | "founded_year" | "total_funding" | "annual_revenue_contact" | "founded_year_contact" | "total_funding_contact" | "employee_count_contact"
    title: string
    section: "employeeCount" | "companyRevenue" | "annualRevenue" | "foundedYear" | "totalFunding"
  }) => {
    const { id, title, section } = args
    const isOpen = !!expandedSections[id]
    const options = getOptionsForSection(section)
    const currentSelected = selectedItems[id]?.map((i) => i.id) || []

    return (
      <div
        className={`border-b border-gray-100  dark:border-gray-800/50 ${
          isOpen ? "bg-[#F7F7F7] dark:bg-gray-800/80" : "bg-white dark:bg-transparent"
        }`}
      >
        <SectionHeader title={title} expanded={isOpen} onClick={() => dispatch(toggleSection(id))} />
        {!!isOpen && (
          <div className="space-y-3 p-4">
            {options.map((option) => (
              <label key={option} className="flex cursor-pointer items-center gap-3">
                <Checkbox checked={currentSelected.includes(option)} onChange={() => toggleCompanyOption(id, option)} />
                <span className="text-sm font-normal text-gray-900">{option}</span>
              </label>
            ))}
          </div>
        )}
      </div>
    )
  }

  const renderCheckboxList = (args: {
    id: "employee_count" | "company_revenue" | "annual_revenue" | "founded_year" | "total_funding" | "annual_revenue_contact" | "founded_year_contact" | "total_funding_contact" | "employee_count_contact"
    section: "employeeCount" | "companyRevenue" | "annualRevenue" | "foundedYear" | "totalFunding"
  }) => {
    const { id, section } = args
    const options = getOptionsForSection(section)
    const currentSelected = selectedItems[id]?.map((i) => i.id) || []

    return (
      <div className="space-y-3">
        {options.map((option) => (
          <label key={option} className="flex cursor-pointer items-center gap-3">
            <Checkbox checked={currentSelected.includes(option)} onChange={() => toggleCompanyOption(id, option)} />
            <span className="text-sm font-normal text-gray-900 dark:text-gray-100">{option}</span>
          </label>
        ))}
      </div>
    )
  }

  // ...

  const currentFiltersGroups: IFilterGroup[] = useMemo(() => {
    // Map to track seen DSL keys per group to prevent duplicates
    const seenDslKeys = new Set<string>()

    const groups = filterGroups
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
          // Hide explicit standalone keywords (merged into Industry)
          if (["company_keywords"].includes(filter.id)) return false

          return filter.filter_type === "company"
        })

        // Deduplicate based on DSL key to prevent visual redundancy (e.g. two "Company Domain" fields)
        filters = filters.filter((f) => {
          const key = sectionToKey[f.id] || f.id
          const uniqueKey = `${group.group_id}_${key}`
          if (seenDslKeys.has(uniqueKey)) return false
          seenDslKeys.add(uniqueKey)
          return true
        })

        // Reorder Company Group: Put Company Name first, then Domain
        if (group.group_name.toLowerCase() === "company") {
          const priorityMap: Record<string, number> = {
            company_name_company: 1,
            company_domain_company: 2,
            industry: 3,
            company_industries: 3,
            technologies: 4,
            company_technologies: 4,
            employee_count: 5,
            company_headcount: 5,
            company_headcount_contact: 5,
            company_revenue: 6,
            company_revenue_range: 6,
            annual_revenue: 7,
            founded_year: 8,
            total_funding: 9,
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
  }, [filterGroups, isPeoplePage])

  useEffect(() => {
    dispatch(resetFilters())
  }, [dispatch, location.pathname])

  useEffect(() => {
    const filters = currentFiltersGroups.flatMap((group) => group.filters).filter((filter) => filter.input_type !== "text")
    Object.entries(expandedSections).forEach(([filterId, isExpanded]) => {
      const filter = filters.find((filter) => filter.id === filterId)
      if (isExpanded && filter) handleSearch(filter)
    })
  }, [expandedSections, currentFiltersGroups, handleSearch])

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
              onClick={() => dispatch(resetFilters())}
            >
              Clear All
            </Button>
            <Button className="border border-gray-200 bg-white p-2 text-sm text-gray-600 hover:bg-transparent" onClick={() => setIsSaveOpen(true)}>
              <Save /> Save filters
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
                .map((filter) => (
                  <div key={filter.id}>
                    <div
                      className={`border-b border-gray-100 dark:border-gray-800/50 ${
                        expandedSections[filter.id] ? "bg-[#F7F7F7] dark:bg-gray-800/80" : "bg-white dark:bg-transparent"
                      }`}
                    >
                      <SectionHeader title={filter.name} expanded={expandedSections[filter.id]} onClick={() => dispatch(toggleSection(filter.id))} />
                      {!!expandedSections[filter.id] && (
                        <div className="space-y-3 p-4">
                          {/* Custom Components Rendering */}
                          {["industry", "company_industries"].includes(filter.id) ? (
                            <IndustryFilter />
                          ) : filter.id === "job_title" ? (
                            <JobTitleFilter />
                          ) : filter.id === "departments" ? (
                            <DepartmentFilter />
                          ) : ["technologies", "company_technologies_contact"].includes(filter.id) ? (
                            <TechnologyFilter />
                          ) : filter.id === "company_country" ? (
                            <LocationFieldFilter filterId="company_country" fieldName="country" scope="company" placeholder="Search country..." />
                          ) : filter.id === "company_state" ? (
                            <LocationFieldFilter filterId="company_state" fieldName="state" scope="company" placeholder="Search state..." />
                          ) : filter.id === "company_city" ? (
                            <LocationFieldFilter filterId="company_city" fieldName="city" scope="company" placeholder="Search city..." />
                          ) : filter.id === "contact_country" ? (
                            <LocationFieldFilter filterId="contact_country" fieldName="country" scope="contact" placeholder="Search country..." />
                          ) : filter.id === "contact_state" ? (
                            <LocationFieldFilter filterId="contact_state" fieldName="state" scope="contact" placeholder="Search state..." />
                          ) : filter.id === "contact_city" ? (
                            <LocationFieldFilter filterId="contact_city" fieldName="city" scope="contact" placeholder="Search city..." />
                          ) : filter.id === "company_country_contact" ? (
                            <LocationFieldFilter filterId="company_country_contact" fieldName="country" scope="contact" placeholder="Search company country..." />
                          ) : filter.id === "company_state_contact" ? (
                            <LocationFieldFilter filterId="company_state_contact" fieldName="state" scope="contact" placeholder="Search company state..." />
                          ) : filter.id === "company_city_contact" ? (
                            <LocationFieldFilter filterId="company_city_contact" fieldName="city" scope="contact" placeholder="Search company city..." />
                          ) : filter.id === "annual_revenue" ? (
                            renderCheckboxList({ id: "annual_revenue", section: "annualRevenue" })
                          ) : filter.id === "founded_year" ? (
                            renderCheckboxList({ id: "founded_year", section: "foundedYear" })
                          ) : filter.id === "total_funding" ? (
                            renderCheckboxList({ id: "total_funding", section: "totalFunding" })
                          ) : filter.id === "annual_revenue_contact" ? (
                            renderCheckboxList({ id: "annual_revenue_contact", section: "annualRevenue" })
                          ) : filter.id === "founded_year_contact" ? (
                            renderCheckboxList({ id: "founded_year_contact", section: "foundedYear" })
                          ) : filter.id === "total_funding_contact" ? (
                            renderCheckboxList({ id: "total_funding_contact", section: "totalFunding" })
                          ) : filter.id === "employee_count_contact" ? (
                            renderCheckboxList({ id: "employee_count_contact", section: "employeeCount" })
                          ) : (
                            <>
                              {["company_domain_company", "company_domain_contact", "company_name_company"].includes(filter.id) && (
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
                                    dispatch(
                                      addSelectedItem({
                                        sectionId: filter.id,
                                        item: { id: searchTerms[filter.id] || "", name: searchTerms[filter.id] || "", type: "include" }
                                      })
                                    )
                                  }}
                                >
                                  <input
                                    type="text"
                                    className="w-full rounded-lg border border-gray-200 bg-white py-2 pl-2 pr-9 text-sm text-gray-900 transition-shadow placeholder:text-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-200 dark:border-gray-800 dark:bg-gray-900 dark:text-gray-100 dark:placeholder:text-gray-500 dark:focus:ring-gray-700"
                                    placeholder={`Search ${filter.name.toLowerCase()}`}
                                    value={searchTerms[filter.id] || ""}
                                    onChange={(e) => handleSearchChange(filter, e.target.value)}
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
                                  />
                                </div>
                              ) : null}
                              {/* Standard Tag List */}
                              {selectedItems[filter.id]?.length > 0 && (
                                <div className="flex flex-wrap gap-2">
                                  {selectedItems[filter.id].map((item) => (
                                    <FilterTag
                                      key={item.id}
                                      item={item}
                                      onRemove={() => dispatch(removeSelectedItem({ sectionId: filter.id, itemId: item.id }))}
                                    />
                                  ))}
                                </div>
                              )}

                              {filter.input_type !== "text" && (
                                <div className="mt-2">
                                  <FilterSearchValueResults
                                    results={searchState.results[filter.id]}
                                    metadata={searchState.metadata[filter.id]}
                                    isLoading={searchState.isLoading[filter.id]}
                                    error={searchState.error[filter.id]}
                                    selectedItems={selectedItems[filter.id] || []}
                                    canExclude={filter.allows_exclusion}
                                    onInclude={(item) =>
                                      dispatch(
                                        addSelectedItem({
                                          sectionId: filter.id,
                                          item: { ...item, type: "include" }
                                        })
                                      )
                                    }
                                    onExclude={(item) =>
                                      dispatch(
                                        addSelectedItem({
                                          sectionId: filter.id,
                                          item: { ...item, type: "exclude" }
                                        })
                                      )
                                    }
                                    onRemove={(item) => dispatch(removeSelectedItem({ sectionId: filter.id, itemId: item.id }))}
                                  />
                                </div>
                              )}
                            </>
                          )}
                        </div>
                      )}
                    </div>
                  </div>
                ))}

              {group.group_name.toLowerCase() === "personal" && (
                <div
                  className={`border-b border-gray-100 dark:border-gray-800/50 ${
                    expandedSections[personalSectionId] ? "bg-[#F7F7F7] dark:bg-gray-800/80" : "bg-white dark:bg-transparent"
                  }`}
                >
                  <SectionHeader
                    title="Years of Experience"
                    expanded={!!expandedSections[personalSectionId]}
                    onClick={() => dispatch(toggleSection(personalSectionId))}
                  />
                  {!!expandedSections[personalSectionId] && (
                    <div className="p-4">
                      <RangeSlider min={0} max={30} step={1} unit="YOE" hideHeader value={yoeRange} onChange={setYoeRange} />
                    </div>
                  )}
                </div>
              )}
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
