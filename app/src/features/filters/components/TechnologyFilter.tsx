import React, { useState } from "react"
import { useAppDispatch, useAppSelector } from "@/app/hooks/reduxHooks"
import { addSelectedItem, removeSelectedItem, selectSelectedItems } from "@/features/filters/slice/filterSlice"
import { useFilterSearch } from "@/app/hooks/filterValuesSearchHook"
import { X, Search, Info, Plus, Loader2 } from "lucide-react"
import { IFilter } from "@/interface/filters/filterGroup"
import Checkbox from "@/components/ui/checkbox"

export const TechnologyFilter = () => {
  const dispatch = useAppDispatch()
  const sectionId = "technologies"

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
        name: "Technologies",
        filter_type: "company",
        is_searchable: true,
        allows_exclusion: true,
        supports_value_lookup: true,
        input_type: "multi_select"
      }
      handleSearch(filter, val)
    }
  }

  const handleManualAdd = (term: string, mode: "include" | "exclude") => {
    const trimmed = term.trim()
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

  const includeItems = selectedItems.filter((i) => i.type === "include")
  const excludeItems = selectedItems.filter((i) => i.type === "exclude")

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
          <input
            type="text"
            value={textInput}
            onChange={handleSearchChange}
            onKeyDown={handleKeyDown}
            placeholder={filterMode === "include" ? "Search or add technologies..." : "Search or add to exclude..."}
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

      {(includeItems.length > 0 || excludeItems.length > 0) && (
        <div className="flex flex-col gap-3">
          {includeItems.length > 0 && (
            <div>
              <span className="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-slate-400">Included</span>
              <div className="flex flex-wrap gap-1.5">
                {includeItems.map((item) => (
                  <span
                    key={item.id}
                    className="group inline-flex items-center gap-1 rounded-full border border-indigo-100 bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700 transition-all hover:bg-indigo-100"
                  >
                    {item.name}
                    <button
                      onClick={() => dispatch(removeSelectedItem({ sectionId, itemId: item.id }))}
                      className="rounded-full p-0.5 text-indigo-400 transition-colors hover:bg-indigo-200/50 hover:text-indigo-600"
                    >
                      <X className="size-3" />
                    </button>
                  </span>
                ))}
              </div>
            </div>
          )}

          {excludeItems.length > 0 && (
            <div>
              <span className="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-slate-400">Excluded</span>
              <div className="flex flex-wrap gap-1.5">
                {excludeItems.map((item) => (
                  <span
                    key={item.id}
                    className="group inline-flex items-center gap-1 rounded-full border border-rose-100 bg-rose-50 px-2.5 py-1 text-xs font-medium text-rose-700 transition-all hover:bg-rose-100"
                  >
                    {item.name}
                    <button
                      onClick={() => dispatch(removeSelectedItem({ sectionId, itemId: item.id }))}
                      className="rounded-full p-0.5 text-rose-400 transition-colors hover:bg-rose-200/50 hover:text-rose-600"
                    >
                      <X className="size-3" />
                    </button>
                  </span>
                ))}
              </div>
            </div>
          )}
        </div>
      )}

      <div className="mt-2 rounded-xl border border-indigo-100/50 bg-indigo-50/50 p-3">
        <div className="flex gap-2 text-indigo-700">
          <Info className="mt-0.5 size-4 shrink-0" />
          <div className="text-[11px] leading-relaxed">
            <p className="mb-1 font-semibold">Pro Tip: Multi-term Normalization</p>
            <p className="opacity-80">
              Searching for <code className="rounded bg-indigo-100 px-1 text-indigo-800">AWS</code> will automatically match{" "}
              <b>Amazon Web Services</b>. You can add multiple technologies separated by commas.
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
