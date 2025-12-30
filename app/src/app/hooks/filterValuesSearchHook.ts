// features/filters/hooks/useFilterSearch.ts
import { useLazySearchFilterValuesQuery, useLazyCompaniesSuggestQuery } from "@/features/filters/slice/apiSlice"
import { sectionToKey } from "@/features/filters/slice/filterSlice"
import { IFilter } from "@/interface/filters/filterGroup"
import { IFilterSearchState } from "@/interface/filters/filterValueSearch"
import { useCallback, useState } from "react"

export const useFilterSearch = () => {
  const [searchState, setSearchState] = useState<IFilterSearchState>({
    results: {},
    metadata: {},
    isLoading: {},
    error: {}
  })

  const [triggerSearch] = useLazySearchFilterValuesQuery()
  const [triggerCompaniesSuggest] = useLazyCompaniesSuggestQuery()

  const handleSearch = useCallback(
    async (filter: IFilter, query: string = "", page: string = "1") => {
      console.log("🔍 handleSearch called for:", filter.id, "Query:", query, "Searchable:", filter.is_searchable)
      if (filter.is_searchable && !query) return

      const filterId = filter.id
      setSearchState((prev) => ({
        ...prev,
        isLoading: { ...prev.isLoading, [filterId]: true },
        error: { ...prev.error, [filterId]: null }
      }))

      try {
        // Special suggestion source for company name/domain
        const isCompanyName = filter.id === "company_name"
        const isCompanyDomain = filter.id === "company_domain"

        let response:
          | { data: Array<{ id: string | null; name: string | null }>; metadata?: IFilterSearchState["metadata"][string] }
          | { data: Array<{ id: string | null; name: string | null }>; metadata: IFilterSearchState["metadata"][string] }

        if ((isCompanyName || isCompanyDomain) && query) {
          const suggest = await triggerCompaniesSuggest({ q: query }).unwrap()
          const mapped = (suggest.data || []).map((s) => ({ id: String(s.name ?? s.domain ?? ""), name: String(s.name ?? s.domain ?? "") }))
          response = {
            data: mapped,
            metadata: {
              total_count: mapped.length,
              returned_count: mapped.length,
              page: Number(page),
              per_page: 10,
              total_pages: 1
            }
          }
        } else {
          // Use server-provided filter ID directly for value lookup
          response = await triggerSearch({
            filter: filterId,
            page,
            count: "10",
            ...(query && { q: query })
          }).unwrap()
        }
        setSearchState((prev) => ({
          ...prev,
          results: {
            ...prev.results,
            [filterId]: response.data as Array<{ id: string; name: string }>
          },
          metadata: {
            ...prev.metadata,
            [filterId]: response.metadata
          },
          isLoading: { ...prev.isLoading, [filterId]: false }
        }))
      } catch (error) {
        setSearchState((prev) => ({
          ...prev,
          isLoading: { ...prev.isLoading, [filterId]: false },
          error: { ...prev.error, [filterId]: "Failed to load results" }
        }))
      }
    },
    [triggerSearch, triggerCompaniesSuggest]
  )

  const clearSearchResults = useCallback((filterId: string) => {
    setSearchState((prev) => ({
      ...prev,
      results: { ...prev.results, [filterId]: [] },
      metadata: { ...prev.metadata, [filterId]: undefined },
      error: { ...prev.error, [filterId]: null }
    }))
  }, [])

  return {
    searchState,
    handleSearch,
    clearSearchResults
  }
}
