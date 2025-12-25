import React, { useState } from "react"
import { useAppDispatch, useAppSelector } from "@/app/hooks/reduxHooks"
import { Input } from "@/components/ui/input"
import { Search, Loader2, Plus } from "lucide-react"
import { useFilterSearch } from "@/app/hooks/filterValuesSearchHook"
import { IFilter } from "@/interface/filters/filterGroup"
import { IFilterValue } from "@/interface/filters/filterValueSearch"
import { SelectedFilter } from "@/interface/filters/slice"
import {
  addSelectedItem,
  removeSelectedItem,
  selectSelectedItems
} from "../slice/filterSlice"

type LocationFieldFilterProps = {
  filterId: string
  fieldName: "country" | "state" | "city"
  scope: "contact" | "company"
  placeholder?: string
}

export const LocationFieldFilter: React.FC<LocationFieldFilterProps> = ({ 
  filterId, 
  fieldName, 
  scope,
  placeholder 
}) => {
  const dispatch = useAppDispatch()
  const selectedItems = useAppSelector(selectSelectedItems)
  const { searchState, handleSearch } = useFilterSearch()

  const [query, setQuery] = useState("")
  const [actionType, setActionType] = useState<"include" | "exclude">("include")

  const selected = selectedItems[filterId] || []

  const filter: IFilter = {
    id: filterId,
    name: fieldName.charAt(0).toUpperCase() + fieldName.slice(1),
    filter_type: scope,
    is_searchable: true,
    allows_exclusion: true,
    supports_value_lookup: true,
    input_type: "multi_select"
  }

  const onSelect = (item: { id: string; name: string }) => {
    const itemId = `${filterId}_${item.name}`
    const exists = selected.find((i) => i.id === itemId)
    
    if (exists) {
      dispatch(removeSelectedItem({ sectionId: filterId, itemId: exists.id, isCompanyFilter: scope === "company" }))
    } else {
      const payload: SelectedFilter = { id: itemId, name: item.name, type: actionType }
      dispatch(addSelectedItem({ sectionId: filterId, item: payload, isCompanyFilter: scope === "company" }))
    }
  }

  const res = searchState.results[filter.id]
  const loading = searchState.isLoading[filter.id]

  return (
    <div className="space-y-3">
      {/* Action Type Toggle */}
      <div className="flex items-center gap-2">
        <button
          onClick={() => setActionType("include")}
          className={`rounded px-3 py-1 text-xs font-medium transition-colors ${
            actionType === "include"
              ? "bg-blue-500 text-white"
              : "bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700"
          }`}
        >
          Include
        </button>
        <button
          onClick={() => setActionType("exclude")}
          className={`rounded px-3 py-1 text-xs font-medium transition-colors ${
            actionType === "exclude"
              ? "bg-red-500 text-white"
              : "bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-400 dark:hover:bg-gray-700"
          }`}
        >
          Exclude
        </button>
      </div>

      {/* Search Input */}
      <div className="flex items-center gap-2">
        <div className="relative flex-1">
          <Search className="absolute left-2 top-2.5 size-4 text-gray-400" />
          <Input
            placeholder={placeholder || `Search ${fieldName}...`}
            value={query}
            onChange={(e) => {
              const val = e.target.value
              setQuery(val)
              if (val.length > 1) handleSearch(filter, val)
            }}
            onKeyDown={(e) => {
              if (e.key === "Enter" && query.trim()) {
                e.preventDefault()
                onSelect({ id: query.trim(), name: query.trim() })
                setQuery("")
              }
            }}
            className="h-9 pl-8 text-sm"
          />
          {!!loading && <Loader2 className="absolute right-2 top-2.5 size-4 animate-spin text-gray-400" />}
        </div>
        <button
          onClick={() => {
            if (query.trim()) {
              onSelect({ id: query.trim(), name: query.trim() })
              setQuery("")
            }
          }}
          className={`flex h-9 w-9 items-center justify-center rounded-lg border transition-all ${
            actionType === "include"
              ? "border-blue-200 bg-blue-50 text-blue-600 hover:bg-blue-100"
              : "border-red-200 bg-red-50 text-red-600 hover:bg-red-100"
          }`}
        >
          <Plus className="size-4" />
        </button>
      </div>

      {/* Search Results */}
      {!!query && !!res && res.length > 0 && (
        <div className="max-h-40 overflow-y-auto rounded-md border bg-white p-2 shadow-sm dark:bg-gray-900">
          {res.map((r: IFilterValue) => {
            const isSelected = selected.some((i) => i.name === r.name)
            return (
              <button
                key={r.id}
                onClick={() => onSelect({ id: r.id, name: r.name })}
                className={`flex w-full items-center justify-between rounded px-2 py-1.5 text-sm transition-colors hover:bg-gray-100 dark:hover:bg-gray-800 ${
                  isSelected ? "bg-blue-50 dark:bg-blue-900/20" : ""
                }`}
              >
                <span>{r.name}</span>
              </button>
            )
          })}
        </div>
      )}

      {/* Selected Items */}
      {selected.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {selected.map((item) => (
            <span
              key={item.id}
              className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-normal transition-colors ${
                item.type === "include"
                  ? "bg-[#335CFF] text-white"
                  : "bg-red-200 text-red-950 dark:bg-red-950/50 dark:text-red-400"
              }`}
            >
              {item.name}
              <button
                onClick={() => dispatch(removeSelectedItem({ sectionId: filterId, itemId: item.id, isCompanyFilter: scope === "company" }))}
                className="rounded-full p-0.5 transition-colors hover:bg-black/10 dark:hover:bg-white/10"
              >
                <svg className="size-3.5" viewBox="0 0 20 20" fill="currentColor">
                  <path
                    fillRule="evenodd"
                    d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
                    clipRule="evenodd"
                  />
                </svg>
              </button>
            </span>
          ))}
        </div>
      )}
    </div>
  )
}
