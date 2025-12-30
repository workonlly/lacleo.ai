import React, { useState, useEffect } from "react"
import { Check } from "lucide-react"
import { SearchConfirmationProps, SearchCriterion } from "@/features/aisearch/types"
import { Avatar } from "./avatar"
import LacleoIcon from "./../../static/media/avatars/lacleo_avatar.svg?react"
import { Button } from "./button"

const SearchConfirmation: React.FC<SearchConfirmationProps> = ({
  title = "Confirm to see results or modify your prompt.",
  criteria,
  onApply,
  onCriterionChange,
  applyButtonText = "Apply",
  className = "",
  disabled = false
}) => {
  const [localCriteria, setLocalCriteria] = useState<SearchCriterion[]>(criteria)

  // Update local state when props change
  useEffect(() => {
    setLocalCriteria(criteria)
  }, [criteria])

  const handleCriterionToggle = (id: string, checked: boolean) => {
    setLocalCriteria((prev) => prev.map((c) => (c.id === id ? { ...c, checked } : c)))

    // Call parent callback if provided
    onCriterionChange?.(id, checked)
  }

  const handleApply = () => {
    if (disabled) return
    onApply(localCriteria)
  }

  return (
    <div>
      <div className={` max-w-lg rounded-2xl border border-gray-200 bg-white px-6 py-[18px] shadow-sm ${className}`}>
        {/* Title */}
        <span className="text-xs font-medium text-gray-950">{title}</span>

        {/* Criteria List */}
        <div className="my-4 mb-0 space-y-4">
          {localCriteria.map((criterion, idx) => (
            <div key={`${criterion.id}-${criterion.value}-${idx}`} className="flex items-center gap-4">
              {/* Checkbox */}
              <button
                onClick={() => handleCriterionToggle(criterion.id, !criterion.checked)}
                className={`flex size-5 items-center justify-center rounded transition-colors ${
                  criterion.checked ? "bg-[#335CFF] text-white" : "bg-gray-200 hover:bg-gray-300"
                }`}
                aria-label={`Toggle ${criterion.label}`}
              >
                {!!criterion.checked && <Check className="size-4" />}
              </button>

              {/* Label */}
              <span className="text-[11px] font-normal text-gray-900">{criterion.label}</span>

              {/* Value Badge */}
              <span className=" rounded-full bg-[#C0D5FF] px-2 py-1 text-xs font-medium text-[#122368]">{criterion.value}</span>
            </div>
          ))}
        </div>
      </div>

      {!!applyButtonText && (
        <div className="mt-4 flex flex-row items-center gap-[10px]">
          <Avatar className="">
            <LacleoIcon className=" transition-all duration-500 ease-in-out dark:invert" />
          </Avatar>
          <Button
            onClick={handleApply}
            disabled={disabled}
            className="w-full max-w-28 rounded-[10px] border border-blue-500 bg-transparent p-[10px] text-base text-blue-500 hover:bg-transparent"
          >
            {applyButtonText}
          </Button>
        </div>
      )}
    </div>
  )
}

export default SearchConfirmation
