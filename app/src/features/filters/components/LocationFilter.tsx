import React, { useMemo, useState } from "react"
import { useAppDispatch, useAppSelector } from "@/app/hooks/reduxHooks"
import { Input } from "@/components/ui/input"
import { Button } from "@/components/ui/button"
import Checkbox from "@/components/ui/checkbox"
import { Search, Loader2, Plus, Minus, Check } from "lucide-react"
import { useFilterSearch } from "@/app/hooks/filterValuesSearchHook"
import { IFilter } from "@/interface/filters/filterGroup"
import { IFilterValue } from "@/interface/filters/filterValueSearch"
import { SelectedFilter } from "@/interface/filters/slice"
import {
  addSelectedItem,
  removeSelectedItem,
  selectSelectedItems,
  setFilterPresence,
  selectCompanyFilters,
  selectContactFilters
} from "../slice/filterSlice"

type LocationFilterProps = {
  scope: "contact" | "company"
}

const PresenceRadios = ({
  scope,
  presence,
  onChange
}: {
  scope: "contact" | "company"
  presence: "any" | "known" | "unknown"
  onChange: (p: "any" | "known" | "unknown") => void
}) => {
  return (
    <div className="flex gap-4">
      {["any", "known", "unknown"].map((p) => (
        <label key={`${scope}_${p}`} className="flex cursor-pointer items-center space-x-2">
          <input
            type="radio"
            name={`${scope}_location_presence`}
            value={p}
            checked={presence === p}
            onChange={() => onChange(p as "any" | "known" | "unknown")}
            className="text-blue-600 focus:ring-blue-500"
          />
          <span className="text-sm capitalize">{p}</span>
        </label>
      ))}
    </div>
  )
}

export const LocationFilter: React.FC<LocationFilterProps> = ({ scope }) => {
  const dispatch = useAppDispatch()
  const selectedItems = useAppSelector(selectSelectedItems)

  const { searchState, handleSearch } = useFilterSearch()

  const [countryQuery, setCountryQuery] = useState("")
  const [stateQuery, setStateQuery] = useState("")
  const [cityQuery, setCityQuery] = useState("")
  const [actionType, setActionType] = useState<"include" | "exclude">("include")

  const companyFilters = useAppSelector(selectCompanyFilters)
  const contactFilters = useAppSelector(selectContactFilters)
  const presence = useMemo(() => {
    const bucketFilters = scope === "company" ? companyFilters : contactFilters
    const p = bucketFilters["locations"]?.presence
    return (p || "any") as "any" | "known" | "unknown"
  }, [scope, companyFilters, contactFilters])

  const sectionIds =
    scope === "company"
      ? { root: "company_location" }
      : { root: "contact_location", country: "contact_country", state: "contact_state", city: "contact_city" }

  const countrySelected = selectedItems[sectionIds.country || ""] || []
  const stateSelected = selectedItems[sectionIds.state || ""] || []
  const citySelected = selectedItems[sectionIds.city || ""] || []
  const companySelected = selectedItems[sectionIds.root] || []

  const countryFilter: IFilter = {
    id: sectionIds.country || sectionIds.root,
    name: "Country",
    filter_type: scope,
    is_searchable: true,
    allows_exclusion: true,
    supports_value_lookup: true,
    input_type: "multi_select"
  }
  const stateFilter: IFilter = {
    id: sectionIds.state || sectionIds.root,
    name: "State / Region",
    filter_type: scope,
    is_searchable: true,
    allows_exclusion: true,
    supports_value_lookup: true,
    input_type: "multi_select"
  }
  const cityFilter: IFilter = {
    id: sectionIds.city || sectionIds.root,
    name: "City",
    filter_type: scope,
    is_searchable: true,
    allows_exclusion: true,
    supports_value_lookup: true,
    input_type: "multi_select"
  }

  const onPresenceChange = (p: "any" | "known" | "unknown") => {
    const bucket = scope === "company" ? "company" : "contact"
    dispatch(setFilterPresence({ bucket, key: "locations", presence: p }))
  }

  const toggleAction = (t: "include" | "exclude") => setActionType(t)

  const onSelect = (sectionId: string, item: { id: string; name: string }) => {
    const itemId = `${sectionId}_${item.name}`
    const exists = (selectedItems[sectionId] || []).find((i) => i.id === itemId)
    if (exists) {
      dispatch(removeSelectedItem({ sectionId, itemId: exists.id, isCompanyFilter: scope === "company" }))
    } else {
      const payload: SelectedFilter = { id: itemId, name: item.name, type: actionType }
      dispatch(addSelectedItem({ sectionId, item: payload, isCompanyFilter: scope === "company" }))
    }
  }

  const renderSearch = (
    label: string,
    filter: IFilter,
    query: string,
    setQuery: (v: string) => void,
    selected: SelectedFilter[],
    disabled = false
  ) => {
    const res = searchState.results[filter.id]
    const loading = searchState.isLoading[filter.id]
    return (
      <div className="space-y-2">
        <div className="flex items-center gap-2">
          <Button
            variant={actionType === "include" ? "secondary" : "ghost"}
            size="sm"
            className="h-6 text-xs"
            onClick={() => toggleAction("include")}
          >
            Include
          </Button>
          <Button
            variant={actionType === "exclude" ? "secondary" : "ghost"}
            size="sm"
            className="h-6 text-xs"
            onClick={() => toggleAction("exclude")}
          >
            Exclude
          </Button>
        </div>
        <div className="relative">
          <Search className="absolute left-2 top-2.5 size-4 text-gray-400" />
          <Input
            placeholder={`Search ${label.toLowerCase()}...`}
            value={query}
            onChange={(e) => {
              const val = e.target.value
              setQuery(val)
              if (val.length > 1 && !disabled) handleSearch(filter, val)
            }}
            className="h-9 pl-8 text-sm"
            disabled={disabled}
          />
          {!!loading && <Loader2 className="absolute right-2 top-2.5 size-4 animate-spin text-gray-400" />}
        </div>
        {!!query && !!res && res.length > 0 && (
          <div className="max-h-40 overflow-y-auto rounded-md border bg-white p-2 shadow-sm dark:bg-gray-900">
            {res.map((r: IFilterValue) => {
              const isSelected = selected.some((i) => i.name === r.name)
              return (
                <div
                  key={r.id}
                  className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 hover:bg-gray-100 dark:hover:bg-gray-800"
                  onClick={() => onSelect(filter.id, r)}
                >
                  <Checkbox checked={isSelected} onChange={() => {}} />
                  <span className="text-sm">{r.name}</span>
                </div>
              )
            })}
          </div>
        )}
        {selected.length > 0 && (
          <div className="flex flex-wrap gap-2">
            {selected.map((item) => (
              <div
                key={item.id}
                className={`flex items-center gap-1 rounded-full px-2 py-1 text-xs ${
                  item.type === "include"
                    ? "bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300"
                    : "bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300"
                }`}
              >
                <span>{item.name}</span>
                <button
                  onClick={() => dispatch(removeSelectedItem({ sectionId: filter.id, itemId: item.id, isCompanyFilter: scope === "company" }))}
                  className="ml-1 hover:text-black dark:hover:text-white"
                >
                  ×
                </button>
              </div>
            ))}
          </div>
        )}
      </div>
    )
  }

  const countryNeeded = scope === "contact" && countrySelected.length === 0

  return (
    <div className="flex flex-col gap-6 p-2">
      <h3 className="text-xs font-semibold text-gray-900 dark:text-gray-100">{scope === "company" ? "Company Location" : "Contact Location"}</h3>

      <PresenceRadios scope={scope} presence={presence} onChange={onPresenceChange} />

      {scope === "company" ? (
        renderSearch("Location", countryFilter, countryQuery, setCountryQuery, companySelected)
      ) : (
        <>
          {renderSearch("Country", countryFilter, countryQuery, setCountryQuery, countrySelected)}
          {renderSearch("State / Region", stateFilter, stateQuery, setStateQuery, stateSelected, countryNeeded)}
          {renderSearch("City", cityFilter, cityQuery, setCityQuery, citySelected)}
        </>
      )}
    </div>
  )
}

export default LocationFilter
