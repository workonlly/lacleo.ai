// features/filters/hooks/useFilterSearch.ts
import { useLazySearchFilterValuesQuery } from "@/features/filters/slice/apiSlice"
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
        // Map frontend ID to backend expected key
        const apiFilterId = sectionToKey[filterId] || filterId

        const response = await triggerSearch({
          filter: apiFilterId,
          page,
          count: "10",
          ...(query && { q: query })
        }).unwrap()

        setSearchState((prev) => ({
          ...prev,
          results: {
            ...prev.results,
            [filterId]: response.data
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
    [triggerSearch]
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
