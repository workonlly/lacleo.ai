import React from "react"
import { ChevronRight, Sparkles } from "lucide-react"
import { useSelector, useDispatch } from "react-redux"
import InitialView from "./initialview"
import AiChatPage from "./aichatpage"
import {
  resetToInitial,
  selectCurrentView,
  selectSearchQuery,
  selectShowResults,
  startSearch,
  collapseAiPanel,
  expandAiPanel
} from "./slice/searchslice"
import { Button } from "@/components/ui/button"
import SparkleIcon from "../../static/media/icons/sparkle-icon.svg?react"

const AISearchPage: React.FC = () => {
  const dispatch = useDispatch()

  // Redux selectors
  const currentView = useSelector(selectCurrentView)
  const searchQuery = useSelector(selectSearchQuery)
  const showResults = useSelector(selectShowResults)

  const handleSearch = (query: string) => {
    console.log("Searching for:", query)
    dispatch(startSearch(query))
  }

  const handleBackToHome = () => {
    dispatch(resetToInitial())
  }

  return (
    <div
      className={`flex min-h-[calc(100vh-205px)] flex-col rounded-[10px] border bg-white dark:bg-gray-900 ${
        showResults ? "min-h-[calc(100vh-230px)]" : ""
      }`}
    >
      {/* Header - always visible */}
      <div className="mx-4 flex items-center justify-between border-b border-gray-200 pb-[18px] pt-4">
        <div className="flex items-center gap-3">
          <SparkleIcon className="size-5" />
          <span className="text-sm font-medium text-gray-700">
            {showResults ? "Generate Filters" : "Use AI to search for contacts or companies."}
          </span>
        </div>
        {showResults ? (
          <Button
            onClick={() => {
              if (showResults) {
                dispatch(collapseAiPanel())
              }
            }}
            className="bg-white p-1.5 text-xs font-medium text-gray-950 hover:bg-white"
          >
            Collapse <ChevronRight className="size-4" />
          </Button>
        ) : null}
      </div>

      {/* Conditional content */}
      {currentView === "initial" ? (
        <InitialView onSearch={handleSearch} />
      ) : (
        <AiChatPage initialQuery={showResults ? "" : searchQuery} onBackToHome={showResults ? undefined : handleBackToHome} />
      )}
    </div>
  )
}

export default AISearchPage
