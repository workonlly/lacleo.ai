import { Button } from "@/components/ui/button"
import FileCopy from "@/static/media/icons/file-copy.svg?react"
import StarFill from "@/static/media/icons/star-fill.svg?react"
import { CalendarRange, Check, Ellipsis, Play, Trash2, Loader2, Star } from "lucide-react"
import { useGetSavedFiltersQuery, useDeleteSavedFilterMutation, useCreateSavedFilterMutation, useUpdateSavedFilterMutation } from "./slice/apiSlice"
import { useDispatch } from "react-redux"
import { useNavigate } from "react-router-dom"
import { importFiltersFromDSL } from "@/features/filters/slice/filterSlice"
import { setView } from "@/interface/searchTable/view"
import { format } from "date-fns"
import { SavedFilter } from "@/interface/searchTable/search"
import { useToast } from "@/components/ui/use-toast"
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from "@/components/ui/dropdown-menu"

const SavedFiltersPage = () => {
  const { data, isLoading, isFetching } = useGetSavedFiltersQuery({})
  const [deleteFilter] = useDeleteSavedFilterMutation()
  const [createFilter] = useCreateSavedFilterMutation()
  const [updateFilter] = useUpdateSavedFilterMutation()
  const dispatch = useDispatch()
  const navigate = useNavigate()
  const { toast } = useToast()

  const handleRunSearch = (filter: SavedFilter) => {
    // Check if filters match expected structure
    const filters = filter.filters as { contact?: Record<string, unknown>; company?: Record<string, unknown> }

    // Import filters into Redux state
    dispatch(
      importFiltersFromDSL({
        contact: filters.contact || {},
        company: filters.company || {}
      })
    )

    // Switch to search view
    dispatch(setView("search"))

    // Navigate to the correct entity page based on entity_type
    const targetPath = filter.entity_type === "contact" ? "/app/search/contacts" : "/app/search/companies"
    navigate(targetPath)
  }

  const handleDuplicate = async (filter: SavedFilter) => {
    try {
      await createFilter({
        name: `Copy of ${filter.name}`,
        description: filter.description,
        filters: filter.filters,
        entity_type: filter.entity_type,
        tags: filter.tags
      }).unwrap()
      toast({ title: "Filter duplicated" })
    } catch (err) {
      toast({ title: "Failed to duplicate filter", variant: "destructive" })
    }
  }

  const handleDelete = async (id: string) => {
    try {
      await deleteFilter(id).unwrap()
      toast({ title: "Filter deleted" })
    } catch (err) {
      toast({ title: "Failed to delete filter", variant: "destructive" })
    }
  }

  const handleToggleStar = async (filter: SavedFilter) => {
    try {
      await updateFilter({
        id: filter.id,
        is_starred: !filter.is_starred
      }).unwrap()
    } catch (err) {
      toast({ title: "Failed to update filter", variant: "destructive" })
    }
  }

  if (isLoading) {
    return (
      <div className="flex justify-center p-10">
        <Loader2 className="animate-spin text-blue-600" />
      </div>
    )
  }

  const filters = data?.data || []

  if (filters.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center p-10 text-center">
        <h3 className="text-lg font-medium">No saved filters yet</h3>
        <p className="text-sm text-gray-500">Save your search filters to access them quickly later.</p>
      </div>
    )
  }

  return (
    <div className="flex flex-col gap-3 p-4">
      {filters.map((filter) => {
        const visibleTags = (filter.tags || []).slice(0, 3)
        const remainingTagCount = Math.max((filter.tags || []).length - visibleTags.length, 0)

        // Estimated results count is not stored in DB currently, maybe add later?
        // For now, omitting or showing placeholder if needed.

        return (
          <div
            key={filter.id}
            className="flex w-full flex-1 justify-between rounded-[12px] border bg-white p-4 transition-colors hover:border-blue-200"
          >
            <div className="flex flex-col gap-3.5">
              <div className="flex flex-col gap-[5px]">
                <div className="flex items-center gap-2">
                  <span
                    className="flex cursor-pointer items-center gap-1 text-sm font-medium text-gray-950 hover:underline"
                    onClick={() => handleRunSearch(filter)}
                  >
                    {filter.name}
                  </span>
                  <button onClick={() => handleToggleStar(filter)} className="focus:outline-none">
                    {filter.is_starred ? <StarFill className="size-[18px]" /> : <Star className="size-[18px] text-gray-400" />}
                  </button>
                </div>
                {!!filter.description && <span className="text-xs font-normal text-gray-600">{filter.description}</span>}
              </div>

              <div className="flex flex-wrap gap-2">
                {visibleTags.map((tag, idx) => (
                  <span key={`${filter.id}_tag_${idx}`} className="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-[#122368]">
                    {tag}
                  </span>
                ))}
                {remainingTagCount > 0 ? (
                  <span className="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-[#122368]">+{remainingTagCount}</span>
                ) : null}
              </div>

              <div className="flex items-center gap-3.5">
                <span className="flex items-center gap-1 text-xs font-normal text-gray-600">
                  <CalendarRange className="size-3.5" /> Created on {format(new Date(filter.created_at), "MMM d, yyyy")}
                </span>
                {/* 
                <span className="flex items-center gap-1 text-xs font-normal text-gray-600">
                  <Check className="size-3.5" /> {filter.resultsCount} Results
                </span>
                */}
              </div>
            </div>
            <div className="flex items-end gap-1.5">
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="outline" className="rounded-[10px] p-[10px]">
                    <Ellipsis className="size-5" />
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                  <DropdownMenuItem onClick={() => handleDelete(filter.id)} className="text-red-600 focus:text-red-600">
                    <Trash2 className="mr-2 size-4" /> Delete
                  </DropdownMenuItem>
                </DropdownMenuContent>
              </DropdownMenu>

              <Button
                variant="outline"
                className="flex items-center gap-2 rounded-[10px] p-[10px] text-sm font-medium text-gray-600"
                onClick={() => handleDuplicate(filter)}
              >
                <FileCopy className="size-5" />
                Duplicate
              </Button>

              <Button
                variant="outline"
                className="flex items-center gap-2 rounded-[10px] bg-blue-600 p-[10px] text-sm font-medium text-white hover:bg-blue-600 hover:text-white"
                onClick={() => handleRunSearch(filter)}
              >
                <Play className="size-5" />
                Run Search
              </Button>
            </div>
          </div>
        )
      })}
    </div>
  )
}

export default SavedFiltersPage
