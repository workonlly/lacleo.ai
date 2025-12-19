import DownloadIcon from "../../../static/media/icons/download-icon.svg?react"
import { Button } from "../button"
import Checkbox from "../checkbox"
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "../dialog"
import { Input } from "../input"
import { useEffect, useMemo, useState } from "react"
import { useAppDispatch } from "@/app/hooks/reduxHooks"
import { setCreditUsageOpen } from "@/features/settings/slice/settingSlice"
import { useExportEstimateMutation, useExportCreateMutation } from "@/features/searchTable/slice/apiSlice"
import { useToast } from "../use-toast"

type ExportLeadsProps = {
  open: boolean
  onClose: () => void
  selectedCount: number
  totalAvailable: number
  selectedIds?: string[]
  type?: "contacts" | "companies"
}

const CountText = ({ value }: { value: string }) => {
  return <span className="text-xs font-medium text-gray-950">{value}</span>
}

const CountsSummary = ({ showEmail, showPhone, emails, phones }: { showEmail: boolean; showPhone: boolean; emails: string; phones: string }) => {
  return (
    <>
      {showEmail ? (
        <div className="flex flex-row items-center justify-between gap-2">
          <span className="text-xs font-medium text-gray-600">Emails to export</span>
          <CountText value={emails} />
        </div>
      ) : null}
      {showPhone ? (
        <div className="flex flex-row items-center justify-between gap-2">
          <span className="text-xs font-medium text-gray-600">Phones to export</span>
          <CountText value={phones} />
        </div>
      ) : null}
    </>
  )
}

const ExportLeads = ({ open, onClose, selectedCount, totalAvailable, selectedIds = [], type = "contacts" }: ExportLeadsProps) => {
  const [exportMode, setExportMode] = useState<"selected" | "custom">("selected")
  const [customCount, setCustomCount] = useState("")
  const [emailSelected, setEmailSelected] = useState<boolean>(true)
  const [phoneSelected, setPhoneSelected] = useState<boolean>(true)
  const [estimate, setEstimate] = useState<{
    email_count: number
    phone_count: number
    credits_required: number
    total_rows: number
    can_export_free: boolean
    remaining_before: number
    remaining_after: number
  } | null>(null)
  const [estimateExport, { isLoading: estimating }] = useExportEstimateMutation()
  const [createExport, { isLoading: exporting }] = useExportCreateMutation()
  const { toast } = useToast()
  const dispatch = useAppDispatch()

  const hasSelectedData = emailSelected || phoneSelected
  const selectedDataLabel = useMemo(() => {
    if (emailSelected && phoneSelected) return "Emails + Phone Numbers"
    if (emailSelected) return "Emails"
    if (phoneSelected) return "Phone Numbers"
    return ""
  }, [emailSelected, phoneSelected])

  const parsedCustomCount = useMemo(() => {
    const value = Number(customCount)
    if (Number.isNaN(value) || value < 0) return 0
    // clamp to total available
    return Math.min(value, totalAvailable)
  }, [customCount, totalAvailable])

  const exportCount = exportMode === "selected" ? selectedCount : parsedCustomCount

  const canShowCounts = !!estimate && !estimating
  const totalRows = canShowCounts ? (estimate as { total_rows: number }).total_rows : exportCount
  const emailsToExport = canShowCounts ? (estimate as { email_count: number }).email_count : 0
  const phonesToExport = canShowCounts ? (estimate as { phone_count: number }).phone_count : 0
  const creditsRequired = canShowCounts ? (estimate as { credits_required: number }).credits_required : undefined
  const availableCredits = canShowCounts ? (estimate as { remaining_before: number }).remaining_before : undefined
  const est = estimate as { credits_required: number; remaining_before: number }
  const insufficient = canShowCounts ? est.credits_required > est.remaining_before : false
  const emailsLabelMemo = useMemo(
    () => (canShowCounts ? String((estimate as { email_count: number }).email_count) : "..."),
    [canShowCounts, estimate]
  )
  const phonesLabelMemo = useMemo(
    () => (canShowCounts ? String((estimate as { phone_count: number }).phone_count) : "..."),
    [canShowCounts, estimate]
  )

  // Debounced Estimate Call
  useEffect(() => {
    if (!open) return

    const timer = setTimeout(() => {
      const ids = selectedIds
      // If we are in "selected" mode but no IDs, zero out
      if (exportMode === "selected" && (!ids || ids.length === 0)) {
        setEstimate({
          email_count: 0,
          phone_count: 0,
          credits_required: 0,
          total_rows: 0,
          can_export_free: true,
          remaining_before: 0,
          remaining_after: 0
        })
        return
      }

      // If custom mode, we might not need IDs, but let's send what we have + limit
      if (exportCount <= 0) return

      const payload = {
        type,
        ids: exportMode === "selected" ? ids : [], // Only send IDs if in selected mode
        fields: { email: emailSelected, phone: phoneSelected },
        limit: exportCount,
        sanitize: !hasSelectedData
      }

      // Prevent redundant calls if payload hasn't meaningfully changed?
      // RTK Query usually handles deduping if args are same, but array ref changes break it.
      // We rely on the debounce here.

      estimateExport(payload)
        .unwrap()
        .then((res) => setEstimate(res))
        .catch(() => setEstimate(null))
    }, 500) // 500ms debounce

    return () => clearTimeout(timer)
  }, [open, selectedIds, emailSelected, phoneSelected, exportCount, estimateExport, type, hasSelectedData, exportMode])

  const handleExport = async () => {
    try {
      const res = await createExport({
        type,
        ids: selectedIds,
        fields: { email: emailSelected, phone: phoneSelected },
        sanitize: !hasSelectedData,
        limit: exportCount,
        download: true,
        requestId: typeof crypto !== "undefined" && crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}`
      }).unwrap()
      if (res?.url) {
        window.location.href = res.url
        onClose()
      }
    } catch (err) {
      const status = (err as { status?: number })?.status
      const code = (err as { data?: { error?: string } })?.data?.error
      if (status === 402 || code === "INSUFFICIENT_CREDITS") {
        dispatch(setCreditUsageOpen(true))
        return
      }
      toast({
        title: "Export failed",
        description: (err as { data?: { message?: string } })?.data?.message || "Something went wrong. Please try again.",
        variant: "destructive"
      })
    }
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(isOpen) => {
        if (!isOpen) onClose()
      }}
    >
      <DialogTitle className="sr-only ">Dialog</DialogTitle>
      <DialogDescription className="sr-only">Internal dialog content</DialogDescription>
      <DialogContent className="max-w-[400px]  max-h-[600px] overflow-y-auto rounded-xl border p-0  ">
        <DialogHeader className="flex flex-row items-start justify-between border-b border-border p-5">
          <DialogTitle className="flex flex-row items-center gap-3.5">
            <span className="flex items-center justify-center rounded-full border p-[10px]">
              <DownloadIcon className="size-5 text-gray-600" />
            </span>
            <div className="flex flex-col items-start ">
              <span className="text-sm font-medium text-gray-950">Export Leads</span>
              <span className="text-xs font-normal text-gray-600">Download Leads in CSV Format</span>
            </div>
          </DialogTitle>
          <DialogDescription className="sr-only">Choose export options for your leads.</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-5 p-5 ">
          {/* Export selected leads */}
          <label className="flex cursor-pointer items-center gap-3">
            <div className="relative">
              <input
                type="radio"
                name="exportMode"
                value="selected"
                checked={exportMode === "selected"}
                onChange={(e) => setExportMode(e.target.value as "selected" | "custom")}
                className="sr-only"
              />
              <div
                className={`size-4 rounded-full border-2 transition-all ${
                  exportMode === "selected" ? "border-blue-600 bg-blue-600" : "border-gray-300 bg-white"
                }`}
              >
                {exportMode === "selected" && <div className="absolute inset-0 m-[3px] rounded-full bg-white" />}
              </div>
            </div>
            <span className="text-sm font-medium text-gray-950">
              Export Selected leads ({selectedCount} {selectedCount === 1 ? "lead" : "leads"})
            </span>
          </label>

          {/* Add custom value */}
          <div className="flex flex-col gap-2">
            <label className="flex cursor-pointer items-center gap-3">
              <div className="relative">
                <input
                  type="radio"
                  name="exportMode"
                  value="custom"
                  checked={exportMode === "custom"}
                  onChange={(e) => setExportMode(e.target.value as "selected" | "custom")}
                  className="sr-only"
                />
                <div
                  className={`size-4 rounded-full border-2 transition-all ${
                    exportMode === "custom" ? "border-blue-600 bg-blue-600" : "border-gray-300 bg-white"
                  }`}
                >
                  {exportMode === "custom" && <div className="absolute inset-0 m-[3px] rounded-full bg-white" />}
                </div>
              </div>
              <span className="text-sm font-medium text-gray-950">Add custom value</span>
            </label>

            <div className="flex flex-row items-center justify-between gap-2">
              <Input
                type="number"
                placeholder="Enter Number"
                value={customCount}
                onChange={(e) => setCustomCount(e.target.value)}
                disabled={exportMode !== "custom"}
                className={`w-full rounded-lg p-[10px] ${exportMode !== "custom" ? "bg-gray-50 opacity-60" : ""}`}
              />
              <span className=" text-xs font-medium text-gray-600">Out of {totalAvailable}</span>
            </div>
          </div>

          <div>
            <span className="text-sm font-medium text-gray-950">Data to export</span>

            <div className="rounded-lg border border-border">
              <div className="flex flex-row gap-[10px] p-3.5">
                <Checkbox checked={emailSelected} onChange={setEmailSelected} />
                <span className="text-sm font-medium text-gray-950">Email Addresses</span>
              </div>
              <div className="border-b"></div>
              <div className="flex flex-row gap-[10px] p-3.5">
                <Checkbox checked={phoneSelected} onChange={setPhoneSelected} />
                <span className="text-sm font-medium text-gray-950">Phone Numbers</span>
              </div>
            </div>
          </div>

          <div className="flex flex-col gap-3 rounded-2xl border p-4">
            <div className="py-1">
              <span className="text-sm font-medium text-gray-950">Credits Cost</span>
            </div>

            <div className="flex flex-col items-center justify-center rounded-xl border">
              <div className="py-1.5">
                <span className="text-xl font-medium text-gray-950">{creditsRequired !== undefined ? creditsRequired : "..."} Credits</span>
              </div>
              <div className="border-b"></div>
              <div className="flex w-full justify-center rounded-b-xl bg-[#F7F7F7] py-1.5">
                <span className={`text-sm font-medium ${insufficient ? "text-red-600" : "text-gray-600"}`}>
                  Available = <span className="text-gray-950">{availableCredits !== undefined ? availableCredits : "..."}</span>{" "}
                </span>
              </div>
            </div>

            <div className="space-y-3">
              <div className="flex flex-row items-center justify-between gap-2">
                <span className="text-xs font-medium text-gray-600">Total Rows</span>
                <span className="text-xs font-medium text-gray-950">{typeof totalRows === "number" ? totalRows : "..."}</span>
              </div>

              {hasSelectedData ? (
                <>
                  <div className="flex flex-row items-center justify-between gap-2">
                    <span className="text-xs font-medium text-gray-600">Data</span>
                    <span className="text-xs font-medium text-gray-950">{selectedDataLabel}</span>
                  </div>
                  <CountsSummary showEmail={emailSelected} showPhone={phoneSelected} emails={emailsLabelMemo} phones={phonesLabelMemo} />
                </>
              ) : null}
            </div>
          </div>
        </div>

        <DialogFooter className="border-t p-5">
          <DialogClose asChild>
            <Button variant="outline" className="w-full rounded-lg p-2 text-sm font-medium text-[#5C5C5C]">
              Cancel
            </Button>
          </DialogClose>
          <Button
            variant="outline"
            className="w-full rounded-lg bg-[#335CFF] p-2 text-sm font-medium text-white hover:bg-[#335CFF] hover:text-white"
            disabled={exporting || estimating || exportCount <= 0 || insufficient}
            onClick={handleExport}
          >
            {exporting ? "Exporting..." : creditsRequired === 0 ? "Export for Free" : "Export"}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export default ExportLeads
