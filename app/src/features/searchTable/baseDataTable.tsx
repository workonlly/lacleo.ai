import { Button } from "@/components/ui/button"
import Checkbox from "@/components/ui/checkbox"
import { Input } from "@/components/ui/input"
import { Skeleton } from "@/components/ui/skeleton"
import { LoadingSpinner } from "@/components/ui/spinner"
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table"
import { DataTableColumn, DataTableProps } from "@/interface/searchTable/components"
import { ArrowDownIcon, ArrowUpDownIcon, ArrowUpIcon, ChevronDown, ChevronLeft, ChevronRight, Save, Search, SortDesc, Plus } from "lucide-react"
import { useState } from "react"
import SaveFilter from "@/components/ui/modals/savefilter"
import React from "react"
import { useSelector, useDispatch } from "react-redux"
import { selectIsAiPanelCollapsed, expandAiPanel } from "@/features/aisearch/slice/searchslice"
import Pagination from "./pagination"
import SavedFiltersPage from "./savedFiltersPage"
import { selectSearchTableView, setView } from "@/interface/searchTable/view"
import EditIcon from "../../static/media/icons/edit-icon.svg?react"
import SparkleIcon from "../../static/media/icons/sparkle-icon.svg?react"

interface ExtendedDataTableProps<T> extends DataTableProps<T> {
  showCheckbox?: boolean
  selectedItems?: string[]
  onItemSelect?: (itemId: string) => void
  onSelectAll?: (checked: boolean) => void
  onRowClick?: (row: T) => void
}

export function DataTable<T>({
  columns,
  data,
  loading = false,
  fetching = false,
  sortableFields = [],
  onSort,
  sortSelected = [],
  searchPlaceholder = "Search...",
  onSearch,
  searchValue = "",
  pagination,
  onPageChange,
  showCheckbox = false,
  selectedItems = [],
  onItemSelect,
  onSelectAll,
  onRowClick,
  onOpenEditColumns,
  entityType = "contact"
}: ExtendedDataTableProps<T>) {
  const dispatch = useDispatch()
  const isAiPanelCollapsed = useSelector(selectIsAiPanelCollapsed)
  const currentView = useSelector(selectSearchTableView)
  const [isSaveModalOpen, setIsSaveModalOpen] = useState(false)
  const renderCell = (column: DataTableColumn<T>, row: T): React.ReactNode => {
    const value = row[column.field]
    if (column.render) {
      return column.render(value, row)
    }
    return value as string
  }

  // Helper to get current sort direction for a column
  const getSortDirection = (field: string) => {
    const sortItem = sortSelected.find((item) => item.startsWith(field))
    if (!sortItem) return null
    return sortItem.endsWith(":asc") ? "asc" : "desc"
  }

  // Handle column header click for sorting
  const handleSort = (field: string) => {
    const currentDirection = getSortDirection(field)
    let newSort: string[]

    if (!currentDirection) {
      newSort = [`${field}:asc`]
    } else if (currentDirection === "asc") {
      newSort = [`${field}:desc`]
    } else {
      newSort = []
    }

    onSort?.(newSort)
  }

  // Check if all items are selected
  const isAllSelected = data.length > 0 && selectedItems.length === data.length
  const isPartiallySelected = selectedItems.length > 0 && selectedItems.length < data.length
  const handleRowClick = (event: React.MouseEvent, row: T) => {
    const target = event.target as HTMLElement
    if (target.closest('button, a, input, label, [role="button"]')) {
      return
    }
    onRowClick?.(row)
  }

  // Handle select all checkbox
  const handleSelectAll = (checked: boolean) => {
    onSelectAll?.(checked)
  }

  // Handle individual item selection
  const handleItemSelect = (itemId: string) => {
    onItemSelect?.(itemId)
  }

  if (loading) {
    return (
      <div className="w-full space-y-3">
        <div className="flex items-center justify-between">
          <Skeleton className="h-9 w-[300px]" />
        </div>
        <div className="rounded-lg border border-border/40 bg-background/50 backdrop-blur-sm">
          <Table>
            <TableHeader>
              <TableRow className="border-b border-border hover:bg-transparent">
                {!!showCheckbox && (
                  <TableHead className="w-[50px] py-3">
                    <Skeleton className="size-5" />
                  </TableHead>
                )}
                {columns.map((column) => (
                  <TableHead key={column.field as string} className={`${column.width} py-3`}>
                    <Skeleton className="mr-20 h-5" />
                  </TableHead>
                ))}
              </TableRow>
            </TableHeader>
            <TableBody>
              {[...Array(20)].map((_, idx) => (
                <TableRow key={idx} className="border-b border-border/40 hover:bg-transparent">
                  {!!showCheckbox && (
                    <TableCell className="py-2.5">
                      <Skeleton className="size-5" />
                    </TableCell>
                  )}
                  {columns.map((column) => (
                    <TableCell key={column.field as string} className="py-2.5">
                      <Skeleton className="mr-20 h-5" />
                    </TableCell>
                  ))}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
        <Skeleton className="h-9 w-full" />
      </div>
    )
  }

  return (
    <div className="w-full space-y-5 ">
      <div className="flex items-center justify-between gap-3">
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
          {!!onSearch && (
            <div className="relative">
              <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder={searchPlaceholder}
                value={searchValue}
                onChange={(e) => onSearch(e.target.value)}
                className="h-9 w-[300px] bg-white pl-10 pr-3"
              />
              {!!fetching && <LoadingSpinner size="sm" className="absolute right-3 top-1/2 size-4 -translate-y-1/2" />}
            </div>
          )}

          {currentView === "savedFilters" ? (
            <Button variant="outline" className="h-9 gap-1 border-gray-200 bg-white px-3 text-xs font-medium text-gray-700 hover:bg-gray-50">
              <SortDesc className="size-3.5" />
              Sort by
              <ChevronDown className="size-3.5" />
            </Button>
          ) : (
            <>
              <Button
                variant="outline"
                className="h-9 gap-1 border-gray-200 bg-white px-3 text-xs font-medium text-gray-700 hover:bg-gray-50"
                onClick={() => setIsSaveModalOpen(true)}
              >
                <Plus className="size-3.5" /> Save Search
              </Button>
              <Button
                variant="outline"
                className="h-9 gap-1 border-gray-200 bg-white px-3 text-xs font-medium text-gray-700 hover:bg-gray-50"
                onClick={onOpenEditColumns}
              >
                <EditIcon /> Edit Columns
              </Button>
            </>
          )}

          {!!isAiPanelCollapsed && (
            <Button
              variant="outline"
              className="h-9 gap-1 border-[#335CFF] bg-transparent px-3 text-xs font-medium text-[#335CFF] hover:bg-transparent hover:text-[#335CFF]"
              onClick={() => dispatch(expandAiPanel())}
            >
              <SparkleIcon className="size-3.5" />
              Modify filters using AI
              <ChevronLeft className="size-3.5" />
            </Button>
          )}
        </div>
      </div>

      {currentView === "search" ? (
        <div
          className={`relative max-h-[calc(100vh-320px)] min-h-0 overflow-auto bg-background/50 backdrop-blur-sm transition-all duration-200 ${
            fetching ? "opacity-50" : ""
          }`}
        >
          {!!fetching && (
            <div className="absolute inset-0 z-10 flex items-center justify-center bg-background/30">
              <LoadingSpinner size="lg" />
            </div>
          )}
          <Table>
            <TableHeader>
              <TableRow className="border-none bg-[#F7F7F7] hover:bg-[#F7F7F7]">
                {!!showCheckbox && (
                  <TableHead className="w-[50px] rounded-l-lg py-3">
                    <Checkbox
                      checked={isAllSelected}
                      onChange={handleSelectAll}
                      disabled={fetching || data.length === 0}
                      className={isPartiallySelected && !isAllSelected ? "opacity-60" : ""}
                    />
                  </TableHead>
                )}
                {columns.map((column) => {
                  const sortDirection = getSortDirection(column.field as string)
                  const isSortable = sortableFields.some((opt) => opt === (column.field as string))

                  return (
                    <TableHead key={column.field as string} className={`${column.width} rounded-r-lg py-3 text-xs font-medium text-muted-foreground`}>
                      <div
                        className={`flex items-center gap-2 ${isSortable && !fetching ? "group cursor-pointer" : ""} ${
                          fetching ? "pointer-events-none" : ""
                        }`}
                        onClick={() => isSortable && !fetching && handleSort(column.field as string)}
                      >
                        {column.title}
                        {!!isSortable && (
                          <div className="transition-opacity">
                            {sortDirection === "asc" ? (
                              <ArrowUpIcon className="size-4" />
                            ) : sortDirection === "desc" ? (
                              <ArrowDownIcon className="size-4" />
                            ) : (
                              <ArrowUpDownIcon className="size-[14px] text-muted-foreground/80 group-hover:text-muted-foreground" />
                            )}
                          </div>
                        )}
                      </div>
                    </TableHead>
                  )
                })}
              </TableRow>
            </TableHeader>
            <TableBody>
              {data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={columns.length + (showCheckbox ? 1 : 0)} className="h-32 text-center">
                    <div className="flex flex-col items-center justify-center gap-2">
                      <Search className="size-8 text-muted-foreground/80" />
                      <p className="text-sm text-muted-foreground">No results found matching your criteria</p>
                      <p className="text-xs text-muted-foreground/80">Try broadening your search or removing some filters.</p>
                    </div>
                  </TableCell>
                </TableRow>
              ) : (
                data.map((row, rowIndex) => {
                  const itemId = row.id || rowIndex.toString()
                  const isSelected = selectedItems.includes(itemId)

                  return (
                    <TableRow
                      key={itemId}
                      className="cursor-pointer border-b border-border/40 transition-colors hover:bg-muted/40"
                      onClick={(e) => handleRowClick(e, row.attributes)}
                    >
                      {!!showCheckbox && (
                        <TableCell className="py-2.5" onClick={(e) => e.stopPropagation()}>
                          <Checkbox checked={isSelected} onChange={() => handleItemSelect(itemId)} disabled={fetching} />
                        </TableCell>
                      )}
                      {columns.map((column) => (
                        <TableCell key={column.field as string} className="py-2.5 text-sm">
                          {renderCell(column, row.attributes)}
                        </TableCell>
                      ))}
                    </TableRow>
                  )
                })
              )}
            </TableBody>
          </Table>
        </div>
      ) : (
        <div className="relative max-h-[calc(100vh-260px)] min-h-0 overflow-auto bg-background/50 backdrop-blur-sm">
          <SavedFiltersPage />
        </div>
      )}

      {currentView === "search" && !!pagination && (
        <Pagination
          currentPage={pagination.page}
          lastPage={pagination.lastPage}
          onPageChange={onPageChange}
          className={`absolute bottom-0 left-1/2 !mt-2 -translate-x-1/2 bg-transparent transition-opacity duration-200 ${
            fetching ? "opacity-50" : ""
          }`}
        />
      )}
      <SaveFilter open={isSaveModalOpen} onOpenChange={(val) => setIsSaveModalOpen(val)} entityType={entityType} />
    </div>
  )
}

export default DataTable
