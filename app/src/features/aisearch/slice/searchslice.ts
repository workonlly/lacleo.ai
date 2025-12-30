import { SearchCriterion } from "@/features/aisearch/types"
import { TRootState } from "@/interface/reduxRoot/state"
import { createSlice, PayloadAction } from "@reduxjs/toolkit"

export interface SearchState {
  // UI State
  showResults: boolean // Controls whether to show outlet or AI search page
  currentView: "initial" | "chat"
  isAiPanelCollapsed: boolean
  // Search Data
  searchQuery: string
  appliedCriteria: SearchCriterion[]
  searchHistory: string[]

  // Loading States
  isSearching: boolean
  isProcessingCriteria: boolean
  lastResultCount: number | null
  semanticQuery: string | null
}

const initialState: SearchState = {
  showResults: true,
  currentView: "initial",
  isAiPanelCollapsed: false,
  searchQuery: "",
  appliedCriteria: [],
  searchHistory: [],
  isSearching: false,
  isProcessingCriteria: false,
  lastResultCount: null,
  semanticQuery: null
}

const searchSlice = createSlice({
  name: "search",
  initialState,
  reducers: {
    // UI Actions
    setShowResults: (state, action: PayloadAction<boolean>) => {
      state.showResults = action.payload
    },
    setIsAiPanelCollapsed: (state, action: PayloadAction<boolean>) => {
      state.isAiPanelCollapsed = action.payload
    },
    collapseAiPanel: (state) => {
      state.isAiPanelCollapsed = true
    },
    expandAiPanel: (state) => {
      state.isAiPanelCollapsed = false
    },
    setCurrentView: (state, action: PayloadAction<"initial" | "chat">) => {
      state.currentView = action.payload
    },
    resetToInitial: (state) => {
      state.showResults = false
      state.currentView = "initial"
      state.searchQuery = ""
      state.appliedCriteria = []
      state.isSearching = false
      state.isProcessingCriteria = false
    },

    // Search Actions
    setSearchQueryOnly: (state, action: PayloadAction<string>) => {
      state.searchQuery = action.payload
    },
    startSearch: (state, action: PayloadAction<string>) => {
      state.searchQuery = action.payload
      state.currentView = "chat"
      state.isProcessingCriteria = true
      // Ensure AI view is visible when starting a search
      state.showResults = false
      state.isAiPanelCollapsed = false
      // Add to history if not already present
      if (!state.searchHistory.includes(action.payload)) {
        state.searchHistory.push(action.payload)
      }
    },
    finishCriteriaProcessing: (state) => {
      state.isProcessingCriteria = false
    },
    applyCriteria: (state, action: PayloadAction<SearchCriterion[]>) => {
      state.appliedCriteria = action.payload
      state.showResults = true
      state.isSearching = true
    },
    finishSearch: (state) => {
      state.isSearching = false
    },
    updateCriteria: (state, action: PayloadAction<SearchCriterion[]>) => {
      state.appliedCriteria = action.payload
    },

    // History Actions
    clearSearchHistory: (state) => {
      state.searchHistory = []
    },
    setLastResultCount: (state, action: PayloadAction<number | null>) => {
      state.lastResultCount = action.payload
    },
    setSemanticQuery: (state, action: PayloadAction<string | null>) => {
      state.semanticQuery = action.payload
    }
  }
})

export const {
  setShowResults,
  setIsAiPanelCollapsed,
  setCurrentView,
  resetToInitial,
  startSearch,
  setSearchQueryOnly,
  finishCriteriaProcessing,
  applyCriteria,
  finishSearch,
  updateCriteria,
  clearSearchHistory,
  collapseAiPanel,
  expandAiPanel,
  setLastResultCount,
  setSemanticQuery
} = searchSlice.actions

export default searchSlice.reducer

// Selectors - Update to use your existing TRootState type
export const selectShowResults = (state: TRootState) => state.search.showResults
export const selectIsAiPanelCollapsed = (state: TRootState) => state.search.isAiPanelCollapsed
export const selectCurrentView = (state: TRootState) => state.search.currentView
export const selectSearchQuery = (state: TRootState) => state.search.searchQuery
export const selectAppliedCriteria = (state: TRootState) => state.search.appliedCriteria
export const selectSearchHistory = (state: TRootState) => state.search.searchHistory
export const selectIsSearching = (state: TRootState) => state.search.isSearching
export const selectIsProcessingCriteria = (state: TRootState) => state.search.isProcessingCriteria
export const selectLastResultCount = (state: TRootState) => state.search.lastResultCount
export const selectSemanticQuery = (state: TRootState) => state.search.semanticQuery
