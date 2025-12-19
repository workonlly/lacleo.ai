import React, { useState } from "react"
import { useAppDispatch, useAppSelector } from "@/app/hooks/reduxHooks"
import { addSelectedItem, removeSelectedItem, selectSelectedItems } from "@/features/filters/slice/filterSlice"
import { useFilterSearch } from "@/app/hooks/filterValuesSearchHook"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import Checkbox from "@/components/ui/checkbox"
import { Search, Loader2, Plus, X } from "lucide-react"
import { IFilter } from "@/interface/filters/filterGroup"

export const DepartmentFilter = () => {
  const dispatch = useAppDispatch()
  const sectionId = "departments"

  const [searchTerm, setSearchTerm] = useState("")
  const [filterMode, setFilterMode] = useState<"include" | "exclude">("include")
  const [textInput, setTextInput] = useState("")

  const selectedItems = useAppSelector(selectSelectedItems)[sectionId] || []

  const { searchState, handleSearch } = useFilterSearch()
  const searchResults = searchState.results[sectionId] || []
  const isLoading = searchState.isLoading[sectionId]

  const handleSearchChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value
    setSearchTerm(val)
    setTextInput(val)
    if (val.length > 1) {
      const filter: IFilter = {
        id: sectionId,
        name: "Departments",
        filter_type: "contact",
        is_searchable: true,
        allows_exclusion: true,
        supports_value_lookup: true,
        input_type: "multi_select"
      }
      handleSearch(filter, val)
    }
  }

  const handleManualAdd = (dept: string, mode: "include" | "exclude") => {
    const trimmed = dept.trim()
    if (!trimmed) return

    // Support comma-separated
    const parts = trimmed
      .split(",")
      .map((p) => p.trim())
      .filter((p) => p)

    parts.forEach((part) => {
      const itemId = `${sectionId}_${part.toLowerCase()}`
      const existing = selectedItems.find((i) => i.name.toLowerCase() === part.toLowerCase())

      if (!existing) {
        dispatch(
          addSelectedItem({
            sectionId,
            item: { id: itemId, name: part, type: mode }
          })
        )
      } else if (existing.type !== mode) {
        // Toggle type if same name but different mode
        dispatch(
          addSelectedItem({
            sectionId,
            item: { ...existing, type: mode }
          })
        )
      }
    })

    setTextInput("")
    setSearchTerm("")
  }

  const handleKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "Enter") {
      e.preventDefault()
      handleManualAdd(textInput, filterMode)
    }
  }

  const toggleItem = (item: { id: string; name: string }) => {
    const itemId = `${sectionId}_${item.id}`
    const existing = selectedItems.find((i) => i.id === itemId || i.name === item.name)

    if (existing) {
      dispatch(removeSelectedItem({ sectionId, itemId: existing.id }))
    } else {
      dispatch(
        addSelectedItem({
          sectionId,
          item: { id: itemId, name: item.name, type: filterMode }
        })
      )
    }
  }

  const includes = selectedItems.filter((i) => i.type === "include")
  const excludes = selectedItems.filter((i) => i.type === "exclude")

  return (
    <div className="flex flex-col gap-4 p-1">
      <div className="flex flex-col gap-2">
        <div className="mb-2 flex items-center gap-2 rounded-lg bg-slate-50 p-1">
          <button
            onClick={() => setFilterMode("include")}
            className={`flex-1 rounded-md px-3 py-1.5 text-xs font-medium transition-all ${
              filterMode === "include" ? "border border-indigo-100 bg-white text-indigo-600 shadow-sm" : "text-slate-500 hover:text-slate-700"
            }`}
          >
            Include
          </button>
          <button
            onClick={() => setFilterMode("exclude")}
            className={`flex-1 rounded-md px-3 py-1.5 text-xs font-medium transition-all ${
              filterMode === "exclude" ? "border border-rose-100 bg-white text-rose-600 shadow-sm" : "text-slate-500 hover:text-slate-700"
            }`}
          >
            Exclude
          </button>
        </div>

        <div className="group relative">
          <div className="pointer-events-none absolute inset-y-0 left-3 flex items-center">
            <Search className={`size-4 ${filterMode === "include" ? "text-indigo-400" : "text-rose-400"}`} />
          </div>
          <Input
            placeholder="Search or enter departments..."
            value={textInput}
            onChange={handleSearchChange}
            onKeyDown={handleKeyDown}
            className={`w-full rounded-xl border bg-white px-10 py-2 text-sm transition-all focus:outline-none focus:ring-2 ${
              filterMode === "include"
                ? "border-slate-200 focus:border-indigo-500 focus:ring-indigo-500/20"
                : "border-slate-200 focus:border-rose-500 focus:ring-rose-500/20"
            }`}
          />
          {!!isLoading && <Loader2 className="absolute right-10 top-2.5 size-4 animate-spin text-gray-400" />}
          <button
            onClick={() => handleManualAdd(textInput, filterMode)}
            className={`absolute right-2 top-1.5 rounded-md p-1 transition-colors ${
              filterMode === "include" ? "bg-indigo-50 text-indigo-600 hover:bg-indigo-100" : "bg-rose-50 text-rose-600 hover:bg-rose-100"
            }`}
          >
            <Plus className="size-4" />
          </button>
        </div>

        {searchResults.length > 0 && searchTerm.length > 1 && (
          <div className="z-10 mt-1 max-h-48 overflow-y-auto rounded-md border bg-white p-1 shadow-md dark:bg-gray-950">
            {searchResults.map((res) => {
              const isSelected = selectedItems.some((i) => i.name.toLowerCase() === res.name.toLowerCase())
              return (
                <div
                  key={res.id}
                  className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 hover:bg-gray-100 dark:hover:bg-gray-900"
                  onClick={() => toggleItem(res)}
                >
                  <Checkbox checked={isSelected} onChange={() => {}} />
                  <span className="text-sm">{res.name}</span>
                </div>
              )
            })}
          </div>
        )}
      </div>

      <div className="space-y-3">
        {includes.length > 0 && (
          <div className="space-y-1.5">
            <span className="text-[10px] font-bold uppercase text-blue-600 dark:text-blue-400">Included Departments</span>
            <div className="flex flex-wrap gap-1.5">
              {includes.map((item) => (
                <span
                  key={item.id}
                  className="inline-flex items-center gap-1 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-700/10 dark:bg-blue-900/40 dark:text-blue-200"
                >
                  {item.name}
                  <button
                    onClick={() => dispatch(removeSelectedItem({ sectionId, itemId: item.id }))}
                    className="group rounded-full p-0.5 hover:bg-blue-100 dark:hover:bg-blue-800"
                  >
                    <X className="size-3" />
                  </button>
                </span>
              ))}
            </div>
          </div>
        )}

        {excludes.length > 0 && (
          <div className="space-y-1.5">
            <span className="text-[10px] font-bold uppercase text-red-600 dark:text-red-400">Excluded Departments</span>
            <div className="flex flex-wrap gap-1.5">
              {excludes.map((item) => (
                <span
                  key={item.id}
                  className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2.5 py-1 text-xs font-medium text-red-700 ring-1 ring-inset ring-red-700/10 dark:bg-red-900/40 dark:text-red-200"
                >
                  {item.name}
                  <button
                    onClick={() => dispatch(removeSelectedItem({ sectionId, itemId: item.id }))}
                    className="group rounded-full p-0.5 hover:bg-red-100 dark:hover:bg-red-800"
                  >
                    <X className="size-3" />
                  </button>
                </span>
              ))}
            </div>
          </div>
        )}
      </div>

      <div className="rounded border border-dashed bg-gray-50 p-2 text-[10px] text-gray-500 dark:border-gray-800 dark:bg-gray-900">
        Tip: Use common names like "Engineering", "Sales", or "HR". Synonyms are matched automatically.
      </div>
    </div>
  )
}
