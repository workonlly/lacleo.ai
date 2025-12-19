import React, { useState } from "react"
import { useAppDispatch, useAppSelector } from "@/app/hooks/reduxHooks"
import {
  addSelectedItem,
  removeSelectedItem,
  selectSelectedItems,
  selectCompanyFilters,
  setFilterPresence,
  setFilterMode,
  setFilterFields
} from "@/features/filters/slice/filterSlice"
import { useFilterSearch } from "@/app/hooks/filterValuesSearchHook"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import Checkbox from "@/components/ui/checkbox"
import { Search, Loader2, Plus, X, Globe, Hash } from "lucide-react"
import { IFilter } from "@/interface/filters/filterGroup"

export const IndustryFilter = () => {
  const dispatch = useAppDispatch()

  // --- Industry Logic ---
  const industrySectionId = "industry"
  const industryFilterKey = "industries"

  const [industrySearchTerm, setIndustrySearchTerm] = useState("")
  const [industryMode, setIndustryMode] = useState<"include" | "exclude">("include")

  const selectedIndustries = useAppSelector(selectSelectedItems)[industrySectionId] || []
  const activeCompanyFilters = useAppSelector(selectCompanyFilters)
  const presence = activeCompanyFilters[industryFilterKey]?.presence || "any"

  const { searchState, handleSearch } = useFilterSearch()
  const industryResults = searchState.results["industry"] || []
  const isIndustryLoading = searchState.isLoading["industry"]

  const handleIndustrySearch = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value
    setIndustrySearchTerm(val)
    if (val.length > 1) {
      const filter: IFilter = {
        id: "industry",
        name: "Industry",
        filter_type: "company",
        is_searchable: true,
        allows_exclusion: true,
        supports_value_lookup: true,
        input_type: "multi_select"
      }
      handleSearch(filter, val)
    }
  }

  const toggleIndustry = (item: { id: string; name: string }) => {
    const itemId = `${industrySectionId}_${item.name}`
    const existing = selectedIndustries.find((i) => i.id === itemId)

    if (existing) {
      dispatch(removeSelectedItem({ sectionId: industrySectionId, itemId: existing.id, isCompanyFilter: true }))
    } else {
      dispatch(
        addSelectedItem({
          sectionId: industrySectionId,
          item: { id: itemId, name: item.name, type: industryMode },
          isCompanyFilter: true
        })
      )
    }
  }

  const handlePresenceChange = (val: "any" | "known" | "unknown") => {
    dispatch(setFilterPresence({ bucket: "company", key: industryFilterKey, presence: val }))
  }

  // --- Keyword Logic ---
  const keywordSectionId = "company_keywords"
  const keywordFilterKey = "company_keywords"

  const [keywordInput, setKeywordInput] = useState("")
  const [keywordActionType, setKeywordActionType] = useState<"include" | "exclude">("include")

  const selectedKeywords = useAppSelector(selectSelectedItems)[keywordSectionId] || []
  const activeKeywordFilter = activeCompanyFilters[keywordFilterKey]
  const keywordMode = activeKeywordFilter?.mode || "all"
  const keywordFields = activeKeywordFilter?.fields || ["name", "keywords", "description"]

  const handleAddKeyword = () => {
    const trimmed = keywordInput.trim()
    if (!trimmed) return

    const parts = trimmed
      .split(",")
      .map((p) => p.trim())
      .filter((p) => p)

    parts.forEach((val) => {
      const id = `${keywordSectionId}_${val.toLowerCase()}_${keywordActionType}`
      const existing = selectedKeywords.find((k) => k.name.toLowerCase() === val.toLowerCase())

      if (!existing) {
        dispatch(
          addSelectedItem({
            sectionId: keywordSectionId,
            item: { id, name: val, type: keywordActionType },
            isCompanyFilter: true
          })
        )
      } else if (existing.type !== keywordActionType) {
        dispatch(
          addSelectedItem({
            sectionId: keywordSectionId,
            item: { ...existing, type: keywordActionType },
            isCompanyFilter: true
          })
        )
      }
    })
    setKeywordInput("")
  }

  const handleKeywordKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "Enter") {
      e.preventDefault()
      handleAddKeyword()
    }
  }

  const toggleKeywordField = (field: string) => {
    const newFields = keywordFields.includes(field) ? keywordFields.filter((f) => f !== field) : [...keywordFields, field]
    if (newFields.length === 0) return
    dispatch(setFilterFields({ bucket: "company", key: keywordFilterKey, fields: newFields }))
  }

  const setKeywordSearchMode = (m: "all" | "any") => {
    dispatch(setFilterMode({ bucket: "company", key: keywordFilterKey, mode: m }))
  }

  return (
    <div className="flex flex-col gap-6 p-1">
      {/* === INDUSTRY SECTION === */}
      <div className="flex flex-col gap-3">
        <div className="flex items-center gap-2 px-1">
          <Globe className="size-3.5 text-slate-400" />
          <h3 className="text-xs font-bold uppercase tracking-wider text-slate-500">Industry</h3>
        </div>

        {/* Presence */}
        <div className="flex gap-4 rounded-lg border border-slate-100 bg-slate-50/50 p-2 px-1">
          {["any", "known", "unknown"].map((p) => (
            <label key={p} className="flex cursor-pointer items-center space-x-2">
              <input
                type="radio"
                name="industry_presence"
                value={p}
                checked={presence === p}
                onChange={() => handlePresenceChange(p as "any" | "known" | "unknown")}
                className="size-3.5 border-slate-300 text-indigo-600 focus:ring-indigo-500"
              />
              <span className="text-xs font-medium capitalize text-slate-600">{p}</span>
            </label>
          ))}
        </div>

        {presence !== "unknown" && (
          <div className="flex flex-col gap-3">
            {/* Modes (Include/Exclude) */}
            <div className="flex items-center gap-2 rounded-lg bg-slate-50 p-1">
              <button
                onClick={() => setIndustryMode("include")}
                className={`flex-1 rounded-md px-3 py-1.5 text-xs font-medium transition-all ${
                  industryMode === "include" ? "border border-indigo-100 bg-white text-indigo-600 shadow-sm" : "text-slate-500 hover:text-slate-700"
                }`}
              >
                Include
              </button>
              <button
                onClick={() => setIndustryMode("exclude")}
                className={`flex-1 rounded-md px-3 py-1.5 text-xs font-medium transition-all ${
                  industryMode === "exclude" ? "border border-rose-100 bg-white text-rose-600 shadow-sm" : "text-slate-500 hover:text-slate-700"
                }`}
              >
                Exclude
              </button>
            </div>

            {/* Search Input */}
            <div className="group relative">
              <div className="pointer-events-none absolute inset-y-0 left-3 flex items-center">
                <Search className={`size-4 ${industryMode === "include" ? "text-indigo-400" : "text-rose-400"}`} />
              </div>
              <Input
                placeholder="Search industries..."
                value={industrySearchTerm}
                onChange={handleIndustrySearch}
                className={`w-full rounded-xl border bg-white px-10 py-2 text-sm transition-all focus:outline-none focus:ring-2 ${
                  industryMode === "include"
                    ? "border-slate-200 focus:border-indigo-500 focus:ring-indigo-500/20"
                    : "border-slate-200 focus:border-rose-500 focus:ring-rose-500/20"
                }`}
              />
              {!!isIndustryLoading && <Loader2 className="absolute right-3 top-2.5 size-4 animate-spin text-gray-400" />}
            </div>

            {/* Suggestions */}
            {!!industrySearchTerm && industryResults.length > 0 && (
              <div className="z-10 max-h-48 overflow-y-auto rounded-md border bg-white p-1 shadow-md dark:bg-gray-950">
                {industryResults.map((res) => {
                  const isSelected = selectedIndustries.some((i) => i.name === res.name)
                  return (
                    <div
                      key={res.id}
                      className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 hover:bg-gray-100 dark:hover:bg-gray-900"
                      onClick={() => toggleIndustry(res)}
                    >
                      <Checkbox checked={isSelected} onChange={() => {}} />
                      <span className="text-sm">{res.name}</span>
                    </div>
                  )
                })}
              </div>
            )}

            {/* Industry Tags */}
            <div className="flex flex-wrap gap-1.5">
              {selectedIndustries.map((item) => (
                <span
                  key={item.id}
                  className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-medium transition-all ${
                    item.type === "include"
                      ? "border-indigo-100 bg-indigo-50 text-indigo-700 hover:bg-indigo-100"
                      : "border-rose-100 bg-rose-50 text-rose-700 hover:bg-rose-100"
                  }`}
                >
                  {item.name}
                  <button
                    onClick={() => dispatch(removeSelectedItem({ sectionId: industrySectionId, itemId: item.id, isCompanyFilter: true }))}
                    className={`rounded-full p-0.5 transition-colors ${
                      item.type === "include"
                        ? "text-indigo-400 hover:bg-indigo-200/50 hover:text-indigo-600"
                        : "text-rose-400 hover:bg-rose-200/50 hover:text-rose-600"
                    }`}
                  >
                    <X className="size-3" />
                  </button>
                </span>
              ))}
            </div>
          </div>
        )}
      </div>

      <div className="h-px bg-slate-100 dark:bg-slate-800" />

      {/* === KEYWORDS SECTION === */}
      <div className="flex flex-col gap-3">
        <div className="flex items-center gap-2 px-1">
          <Hash className="size-3.5 text-slate-400" />
          <h3 className="text-xs font-bold uppercase tracking-wider text-slate-500">Company Keywords</h3>
        </div>

        {/* Modes (Include/Exclude) */}
        <div className="flex items-center gap-2 rounded-lg bg-slate-50 p-1">
          <button
            onClick={() => setKeywordActionType("include")}
            className={`flex-1 rounded-md px-3 py-1.5 text-xs font-medium transition-all ${
              keywordActionType === "include" ? "border border-indigo-100 bg-white text-indigo-600 shadow-sm" : "text-slate-500 hover:text-slate-700"
            }`}
          >
            Include
          </button>
          <button
            onClick={() => setKeywordActionType("exclude")}
            className={`flex-1 rounded-md px-3 py-1.5 text-xs font-medium transition-all ${
              keywordActionType === "exclude" ? "border border-rose-100 bg-white text-rose-600 shadow-sm" : "text-slate-500 hover:text-slate-700"
            }`}
          >
            Exclude
          </button>
        </div>

        {/* Input */}
        <div className="group relative">
          <div className="pointer-events-none absolute inset-y-0 left-3 flex items-center">
            <Plus className={`size-4 ${keywordActionType === "include" ? "text-indigo-400" : "text-rose-400"}`} />
          </div>
          <Input
            placeholder="SaaS, AI, B2B..."
            value={keywordInput}
            onChange={(e) => setKeywordInput(e.target.value)}
            onKeyDown={handleKeywordKeyDown}
            className={`w-full rounded-xl border bg-white px-10 py-2 text-sm transition-all focus:outline-none focus:ring-2 ${
              keywordActionType === "include"
                ? "border-slate-200 focus:border-indigo-500 focus:ring-indigo-500/20"
                : "border-slate-200 focus:border-rose-500 focus:ring-rose-500/20"
            }`}
          />
          <button
            onClick={handleAddKeyword}
            className={`absolute right-2 top-1.5 rounded-md p-1 transition-colors ${
              keywordActionType === "include" ? "bg-indigo-50 text-indigo-600 hover:bg-indigo-100" : "bg-rose-50 text-rose-600 hover:bg-rose-100"
            }`}
          >
            <Plus className="size-4" />
          </button>
        </div>

        {/* Keyword Controls Row */}
        <div className="flex items-center justify-between gap-4 px-1">
          <div className="flex flex-col gap-1.5">
            <span className="text-[10px] font-bold uppercase tracking-tight text-slate-400">Keyword Match</span>
            <div className="flex items-center gap-1 rounded-md border border-slate-100 bg-slate-50 p-0.5">
              <button
                onClick={() => setKeywordSearchMode("all")}
                disabled={keywordActionType === "exclude"}
                className={`rounded px-2 py-0.5 text-[10px] font-bold transition-all ${
                  keywordMode === "all" ? "bg-white text-indigo-600 shadow-sm" : "text-slate-400 hover:text-slate-600 disabled:opacity-30"
                }`}
              >
                ALL
              </button>
              <button
                onClick={() => setKeywordSearchMode("any")}
                disabled={keywordActionType === "exclude"}
                className={`rounded px-2 py-0.5 text-[10px] font-bold transition-all ${
                  keywordMode === "any" ? "bg-white text-indigo-600 shadow-sm" : "text-slate-400 hover:text-slate-600 disabled:opacity-30"
                }`}
              >
                ANY
              </button>
            </div>
          </div>

          <div className="flex flex-1 flex-col gap-1.5">
            <span className="text-[10px] font-bold uppercase tracking-tight text-slate-400">Search In</span>
            <div className="flex items-center gap-3 rounded-md border border-slate-100 bg-slate-50 p-1.5">
              {["name", "keywords", "description"].map((f) => (
                <label key={f} className="flex cursor-pointer items-center gap-1.5">
                  <Checkbox checked={keywordFields.includes(f)} onChange={() => toggleKeywordField(f)} className="size-3" />
                  <span className="text-[10px] font-medium capitalize text-slate-600">{f.slice(0, 4)}</span>
                </label>
              ))}
            </div>
          </div>
        </div>

        {/* Keyword Tags */}
        <div className="flex flex-wrap gap-1.5">
          {selectedKeywords.map((item) => (
            <span
              key={item.id}
              className={`inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-medium transition-all ${
                item.type === "include"
                  ? "border-indigo-100 bg-indigo-50 text-indigo-700 hover:bg-indigo-100"
                  : "border-rose-100 bg-rose-50 text-rose-700 hover:bg-rose-100"
              }`}
            >
              {item.name}
              <button
                onClick={() => dispatch(removeSelectedItem({ sectionId: keywordSectionId, itemId: item.id, isCompanyFilter: true }))}
                className={`rounded-full p-0.5 transition-colors ${
                  item.type === "include"
                    ? "text-indigo-400 hover:bg-indigo-200/50 hover:text-indigo-600"
                    : "text-rose-400 hover:bg-rose-200/50 hover:text-rose-600"
                }`}
              >
                <X className="size-3" />
              </button>
            </span>
          ))}
        </div>
      </div>
    </div>
  )
}
