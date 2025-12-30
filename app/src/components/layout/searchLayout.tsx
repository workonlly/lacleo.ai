import ContactDetails from "@/components/ui/contactDetails"
import ContactInformation from "@/components/ui/contactinformation"
import { Dialog, DialogOverlay, DialogPortal } from "@/components/ui/dialog"
import { Input } from "@/components/ui/input"
import AISearchPage from "@/features/aisearch/AISearchPage"
import { selectIsAiPanelCollapsed, selectSearchQuery, selectShowResults, startSearch, setShowResults } from "@/features/aisearch/slice/searchslice"
import Filters from "@/features/filters/filterSection"
import { selectIsCompanyDetailsOpen } from "@/features/searchTable/slice/companyDetailsSlice"
import {
  closeContactInfo,
  selectContactInfoCompany,
  selectContactInfoContact,
  selectContactInfoFlags,
  selectIsContactInfoOpen
} from "@/features/searchTable/slice/contactInfoSlice"
import { selectSearchTableView, setView } from "@/interface/searchTable/view"
import * as DialogPrimitive from "@radix-ui/react-dialog"
import { Building2, Save, Search, UsersRound, PanelLeft } from "lucide-react"
import { useDispatch, useSelector } from "react-redux"
import { Outlet, useLocation } from "react-router-dom"
import { useState, useEffect } from "react"
import NavAnchor from "../utils/navAnchor"

const SearchLayout = () => {
  const dispatch = useDispatch()
  const showResults = useSelector(selectShowResults)
  const isAiPanelCollapsed = useSelector(selectIsAiPanelCollapsed)
  const isCompanyDetailsOpen = useSelector(selectIsCompanyDetailsOpen)
  const currentView = useSelector(selectSearchTableView)
  const searchQuery = useSelector(selectSearchQuery)
  const isContactInfoOpen = useSelector(selectIsContactInfoOpen)
  const contactInfoContact = useSelector(selectContactInfoContact)
  const contactInfoCompany = useSelector(selectContactInfoCompany)
  const { hideCompanyActions, hideContactFields } = useSelector(selectContactInfoFlags)

  // Local state for sidebar visibility
  const [isFiltersOpen, setIsFiltersOpen] = useState(false)
  const location = useLocation()

  // Auto-show filters if navigating from AI Search logic (so user sees the transition)
  useEffect(() => {
    const s = (location.state as Record<string, unknown> | null) || null
    if (s && "fromAi" in s && Boolean((s as { fromAi?: boolean }).fromAi)) {
      setIsFiltersOpen(true)
    }
  }, [location.state])

  // Do NOT auto-show results on route enter; results appear only after Apply
  // Keep landing AI page until user applies filters

  const renderTopControls = () => (
    <div className="my-3 flex items-center justify-between gap-3">
      {/* Left side - Toggle buttons */}
      <div className="flex items-center gap-1 rounded-[6px] bg-gray-50 p-1">
        <button
          type="button"
          onClick={() => dispatch(setView("search"))}
          className={`flex items-center gap-1 rounded-[6px] px-3 py-1.5 text-xs font-medium ${
            currentView === "search" ? "bg-white text-gray-950" : "text-gray-600 hover:bg-white hover:text-gray-950"
          }`}
        >
          <Search className="size-3.5" />
          Search
        </button>
        <button
          type="button"
          onClick={() => dispatch(setView("savedFilters"))}
          className={`flex items-center gap-1 rounded-[6px] px-3 py-1.5 text-xs font-medium ${
            currentView === "savedFilters" ? "bg-white text-gray-950" : "text-gray-600 hover:bg-white hover:text-gray-950"
          }`}
        >
          <Save className="size-3.5" />
          Saved Filters
        </button>
      </div>

      {/* Center/Right side - Search and Sort */}
      <div className="flex flex-1 items-center justify-end gap-2">
        <div className="relative">
          <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            placeholder="Search..."
            // Gate AI: only run on Enter via startSearch
            defaultValue=""
            onKeyDown={(e) => {
              const target = e.target as HTMLInputElement
              if (e.key === "Enter" && target.value.trim()) {
                e.preventDefault()
                dispatch(startSearch(target.value.trim()))
                target.blur()
              }
            }}
            className="h-9 w-[300px] bg-white pl-10 pr-3"
          />
        </div>
      </div>
    </div>
  )

  return (
    <div className="flex min-h-screen flex-col px-6">
      <div className="border-b py-5">
        <h2 className="flex flex-col text-lg font-medium">
          Discovery{" "}
          <span className="text-sm font-normal text-[#5C5C5C] dark:text-gray-400">Use AI prompts or Filters to search through our database.</span>
        </h2>
      </div>

      {isCompanyDetailsOpen ? (
        <div className="flex flex-row">
          <div className="flex-1">
            <div className="h-full p-6 pb-0 pr-0">
              <ContactDetails />
            </div>
          </div>
        </div>
      ) : (
        <>
          <div className="relative z-20 flex items-center justify-between border-b bg-white px-2 dark:border-gray-800 dark:bg-background dark:from-gray-950 dark:to-gray-900/50">
            <div className="flex items-center gap-4">
              <button
                onClick={() => setIsFiltersOpen((prev) => !prev)}
                className={`group flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-colors hover:bg-gray-100 dark:hover:bg-gray-800 ${
                  isFiltersOpen ? "text-gray-900 dark:text-gray-100" : "text-gray-500 dark:text-gray-400"
                }`}
              >
                <PanelLeft className="size-4" />
                <span className="hidden sm:inline">{isFiltersOpen ? "Hide Filters" : "Show Filters"}</span>
              </button>
              <div className="h-4 w-px bg-gray-200 dark:bg-gray-800" />
              <nav className="flex space-x-3">
                <NavAnchor
                  to="contacts"
                  activePaths={["/app/search/contacts"]}
                  activeClassName="group py-3.5 gap-2 space-x-2 border-b-2 px-1 text-sm inline-flex items-center border-primary text-primary font-semibold"
                  className="group inline-flex items-center gap-2 space-x-2 border-b-2 border-transparent px-1 py-3.5 text-sm font-normal text-muted-foreground hover:text-foreground"
                >
                  <UsersRound className="size-4 group-[.font-semibold]:stroke-[2.5]" /> Contact
                </NavAnchor>
                <NavAnchor
                  to="companies"
                  activePaths={["/app/search/companies"]}
                  activeClassName="group py-3.5 gap-2 space-x-2 border-b-2 px-1 text-sm inline-flex items-center border-primary text-primary font-semibold"
                  className="group inline-flex items-center gap-2 space-x-2 border-b-2 border-transparent px-1 py-3.5 text-sm font-normal text-muted-foreground hover:text-foreground"
                >
                  <Building2 className="size-4 group-[.font-semibold]:stroke-[2.5]" /> Company
                </NavAnchor>
              </nav>
            </div>
          </div>

          <div className="flex flex-row">
            <div
              className={`shrink-0 overflow-y-auto transition-all duration-300 ease-in-out ${
                isFiltersOpen ? "w-80 opacity-100" : "w-0 overflow-hidden opacity-0"
              }`}
            >
              <Filters />
            </div>
            {showResults ? (
              <>
                <div className="flex-1">
                  <div className="p-6 pb-0 pr-0">
                    <Outlet />
                  </div>
                </div>

                {!isAiPanelCollapsed && (
                  <aside className="ml-6 mt-[18px] w-96">
                    <AISearchPage />
                  </aside>
                )}
              </>
            ) : (
              <div className="ml-6 flex-1 ">
                {renderTopControls()}
                <AISearchPage />
              </div>
            )}
          </div>
        </>
      )}

      <Dialog open={isContactInfoOpen} onOpenChange={(open) => !open && dispatch(closeContactInfo())}>
        <DialogPortal>
          <DialogOverlay />

          <DialogPrimitive.Content className="fixed right-0 top-0 z-50 h-full w-[424px] border-l border-stone-200 bg-white shadow-xl focus:outline-none">
            <DialogPrimitive.Title className="sr-only">Contact Details</DialogPrimitive.Title>
            <DialogPrimitive.Description className="sr-only">
              Detailed information about the selected contact and company.
            </DialogPrimitive.Description>
            <ContactInformation
              contact={contactInfoContact}
              company={contactInfoCompany}
              onClose={() => dispatch(closeContactInfo())}
              hideContactFields={hideContactFields}
              hideCompanyActions={hideCompanyActions}
            />
          </DialogPrimitive.Content>
        </DialogPortal>
      </Dialog>
    </div>
  )
}

export default SearchLayout
