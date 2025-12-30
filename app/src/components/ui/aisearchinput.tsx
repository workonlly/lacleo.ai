import { Button } from "@/components/ui/button"
import { Sparkles } from "lucide-react"
import React, { useState } from "react"

interface AISearchInputProps {
  placeholder?: string
  buttonText?: string
  buttonIcon?: React.ReactNode
  onSearch?: (query: string) => void
  className?: string
  disabled?: boolean
  maxHeight?: number
}

const AISearchInput: React.FC<AISearchInputProps> = ({
  placeholder = "Enter prompt to generate filters",
  buttonText = "Run AI Search",
  buttonIcon = <Sparkles className="size-5" />,
  onSearch,
  className = "",
  disabled = false,
  maxHeight = 120
}) => {
  const [searchQuery, setSearchQuery] = useState("")

  const autoResize = (e: React.ChangeEvent<HTMLTextAreaElement>) => {
    const textarea = e.target
    textarea.style.height = "auto"
    textarea.style.height = `${Math.min(textarea.scrollHeight, maxHeight)}px`
  }

  const handleSearch = () => {
    if (onSearch && searchQuery.trim()) {
      onSearch(searchQuery.trim())
    }
  }

  const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === "Enter" && !e.shiftKey) {
      e.preventDefault()
      handleSearch()
    }
  }

  return (
    <div className={`mx-auto w-full space-y-4 ${className}`}>
      <div className="rounded-lg border bg-gray-50 p-[18px]">
        <textarea
          value={searchQuery}
          onChange={(e) => {
            setSearchQuery(e.target.value)
            autoResize(e)
          }}
          onKeyDown={handleKeyDown}
          className="max-h-48 w-full resize-none overflow-auto bg-gray-50 text-sm font-medium  text-gray-950 focus:border-transparent focus:outline-none"
          rows={1}
          placeholder={placeholder}
          disabled={disabled}
        />
        <div className="flex w-full items-center justify-end">
          <Button
            onClick={handleSearch}
            disabled={disabled || !searchQuery.trim()}
            className="items-center gap-2 rounded-lg bg-blue-500 p-[10px] text-sm font-medium text-white transition-colors hover:bg-blue-600 disabled:cursor-not-allowed disabled:opacity-50"
          >
            {buttonIcon}
            {buttonText}
          </Button>
        </div>
      </div>
    </div>
  )
}

export default AISearchInput
