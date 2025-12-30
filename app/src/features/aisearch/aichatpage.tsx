import React, { useCallback, useEffect, useRef, useState } from "react"
import { useDispatch, useSelector } from "react-redux"
import { useNavigate } from "react-router-dom"

// UI Components
import { MessageList } from "@/components/ui/aimessagelist"
import AISearchInput from "@/components/ui/aisearchinput"
import SearchConfirmation from "@/components/ui/searchconfirmation"
import LacleoIcon from "../../static/media/avatars/lacleo_avatar.svg?react"
import LoaderLines from "../../static/media/icons/loader-lines.svg?react"

// Logic & Types
import { AiChatPageProps, Message, SearchCriterion, SearchConfirmationProps } from "./types"
import { FILTER_KEYS, FILTER_LABELS } from "../filters/utils/constants"

// Redux
import {
  applyCriteria,
  finishCriteriaProcessing,
  finishSearch,
  startSearch,
  selectIsProcessingCriteria,
  selectSearchQuery,
  selectLastResultCount,
  setSemanticQuery,
  collapseAiPanel,
  setShowResults,
  setCurrentView,
  setIsAiPanelCollapsed
} from "./slice/searchslice"
import { useGetFiltersQuery } from "../filters/slice/apiSlice"
import { useTranslateQueryMutation } from "../searchTable/slice/apiSlice"
import { addSelectedItem, resetFilters, importFiltersFromDSL } from "../filters/slice/filterSlice"

const AiChatPage: React.FC<AiChatPageProps> = ({ initialQuery }) => {
  const dispatch = useDispatch()
  const navigate = useNavigate()

  // API Hooks
  const { currentData: filterGroups = [] } = useGetFiltersQuery()
  const [translateQuery] = useTranslateQueryMutation()

  // --- State ---
  const [messages, setMessages] = useState<Message[]>([])
  const [isLoading, setIsLoading] = useState(false)
  const [loadingPhraseIndex, setLoadingPhraseIndex] = useState(0)
  const [inferredEntity, setInferredEntity] = useState<"contacts" | "companies" | null>(null)
  type DslBuckets = { contact: Record<string, unknown>; company: Record<string, unknown> } | null
  const [pendingDsl, setPendingDsl] = useState<DslBuckets>(null)

  // --- Selectors ---
  const lastResultCount = useSelector(selectLastResultCount)

  // --- Refs (For async/closure stability) ---
  const mountedRef = useRef(true)
  const isProcessingRef = useRef(false)
  const lastProcessedInitialQuery = useRef<string | null>(null)
  const messagesRef = useRef<Message[]>([])

  useEffect(() => {
    messagesRef.current = messages
  }, [messages])

  // --- Helpers ---
  const addMessage = useCallback((message: Omit<Message, "id" | "timestamp">) => {
    const newMessage: Message = {
      ...message,
      id: crypto.randomUUID(),
      timestamp: new Date()
    }
    setMessages((prev) => [...prev, newMessage])
    return newMessage
  }, [])

  const disablePreviousConfirmations = useCallback(() => {
    setMessages((prev) =>
      prev.map((m) => {
        if (React.isValidElement(m.component) && m.component.type === SearchConfirmation) {
          return {
            ...m,
            component: React.cloneElement(m.component as React.ReactElement<SearchConfirmationProps>, { disabled: true })
          }
        }
        return m
      })
    )
  }, [])

  // Apply filters and navigate
  const handleApplySearch = useCallback(
    async (criteria: SearchCriterion[]) => {
      disablePreviousConfirmations()
      setIsLoading(true)

      dispatch(resetFilters())
      if (pendingDsl) {
        dispatch(importFiltersFromDSL(pendingDsl))
      }

      const target = inferredEntity === "contacts" ? "contacts" : "companies"
      navigate(`/app/search/${target}`, { state: { fromAi: true } })

      dispatch(setShowResults(true))
      dispatch(finishSearch())
      setIsLoading(false)
    },
    [dispatch, navigate, pendingDsl, inferredEntity, disablePreviousConfirmations]
  )

  // --- Core Search Logic ---
  const processQuery = useCallback(
    async (query: string, isInitial = false) => {
      if (isProcessingRef.current || (isInitial && lastProcessedInitialQuery.current === query)) return

      isProcessingRef.current = true
      if (isInitial) lastProcessedInitialQuery.current = query

      setIsLoading(true)
      addMessage({ type: "user", content: query })

      // Prepare context for AI
      const history = messagesRef.current
        .filter((m) => m.type === "user" || m.type === "ai")
        .map((m) => ({ role: m.type === "user" ? "user" : "assistant", content: m.content }))

      try {
        const response = await translateQuery({
          query,
          messages: [...history, { role: "user", content: query }],
          context: { lastResultCount }
        }).unwrap()

        if (!mountedRef.current) return

        // 1. Process Result Structure
        const hasBuckets = !!(response.filters?.contact || response.filters?.company)
        const flatFilters = hasBuckets ? { ...(response.filters.contact as object), ...(response.filters.company as object) } : response.filters

        const criteria = mapBackendFiltersToCriteria(flatFilters, response.custom)
        const dslUnified: { contact: Record<string, unknown>; company: Record<string, unknown> } = hasBuckets
          ? (response.filters as { contact: Record<string, unknown>; company: Record<string, unknown> })
          : buildDslFromResponse(response.filters, response.entity)

        setInferredEntity(response.entity)
        setPendingDsl(dslUnified)

        // 2. Update Redux Search State
        if (response.semantic_query) dispatch(setSemanticQuery(response.semantic_query))
        dispatch(finishCriteriaProcessing())

        // 3. UI Feedback
        if (response.summary) addMessage({ type: "ai", content: response.summary })

        // Show DSL JSON for transparency
        addMessage({
          type: "system",
          content: "Filters (DSL):",
          component: (
            <pre className="max-h-48 overflow-auto rounded border bg-gray-50 p-3 text-sm text-gray-700">{JSON.stringify(dslUnified, null, 2)}</pre>
          )
        })

        // Show criteria (button hidden) and auto-apply
        addMessage({
          type: "system",
          content: "Applying these filters to your results.",
          component: (
            <SearchConfirmation criteria={criteria} onApply={() => handleApplySearch(criteria)} onCriterionChange={() => {}} applyButtonText="" />
          )
        })

        // Auto-apply immediately
        await handleApplySearch(criteria)
      } catch (error) {
        console.error("AI Search Error:", error)
        addMessage({ type: "ai", content: "I'm having trouble connecting to my brain right now. Please try again or use manual filters." })
      } finally {
        if (mountedRef.current) setIsLoading(false)
        isProcessingRef.current = false
      }
    },
    [addMessage, translateQuery, lastResultCount, dispatch, handleApplySearch]
  )
  // --- Lifecycle Effects ---
  useEffect(() => {
    if (initialQuery) processQuery(initialQuery, true)
    return () => {
      mountedRef.current = false
    }
  }, [initialQuery, processQuery])

  useEffect(() => {
    if (!isLoading) return
    const interval = setInterval(() => {
      setLoadingPhraseIndex((i) => (i + 1) % LOADING_PHRASES.length)
    }, 1500)
    return () => clearInterval(interval)
  }, [isLoading])

  return (
    <div className="flex h-full flex-1 flex-col">
      <div className="flex max-h-[calc(100vh-363px)] min-h-0 flex-1 flex-col overflow-auto">
        <MessageList messages={messages} />

        {!!isLoading && (
          <div className="ml-6 flex animate-pulse items-end gap-[10px]">
            <LacleoIcon />
            <div className="rounded-[12px] border border-[#EBEBEB] bg-gray-50 px-6 py-[18px]">
              <span className="flex items-center gap-2 text-base font-normal text-[#5C5C5C]">
                <LoaderLines className="size-8" />
                {LOADING_PHRASES[loadingPhraseIndex]}
              </span>
            </div>
          </div>
        )}
      </div>

      <div className="bg-white p-4">
        <AISearchInput
          onSearch={(q) => {
            disablePreviousConfirmations()
            processQuery(q)
          }}
          placeholder="Refine your search..."
          disabled={isLoading}
        />
      </div>
    </div>
  )
}

export default AiChatPage

const LOADING_PHRASES: string[] = [
  "Curating filters for your search…",
  "Finding the most relevant results…",
  "Scanning through data…",
  "Sifting through possibilities…",
  "Matching the best options for you…",
  "Refining your filters…",
  "Analyzing search parameters…",
  "Narrowing it down for you…",
  "Exploring all possibilities…"
]

type BackendCustomItem = { label?: string; value: string; type?: string }

function mapBackendFiltersToCriteria(filters: Record<string, unknown>, customFilters: BackendCustomItem[] = []): SearchCriterion[] {
  const out: SearchCriterion[] = []

  const push = (id: string, label: string, value: string) => {
    if (value && value.trim()) out.push({ id, label, value: value.trim(), checked: true })
  }

  const extractValues = (val: unknown): string[] => {
    if (!val) return []
    if (typeof val === "string") return [val]
    if (Array.isArray(val)) return val.map(String)
    if (typeof val === "object") {
      const obj = val as { include?: unknown }
      if (Array.isArray(obj.include)) return obj.include.map(String)
      if (typeof obj.include === "string") return [obj.include]
      return []
    }
    return [String(val)]
  }

  const formatRange = (val: unknown): string => {
    const obj = (val || {}) as { gte?: number; lte?: number; min?: number; max?: number }
    const g = obj.gte ?? obj.min
    const l = obj.lte ?? obj.max
    const toKMB = (n: number) =>
      n >= 1_000_000_000 ? `${n / 1_000_000_000}B` : n >= 1_000_000 ? `${n / 1_000_000}M` : n >= 1_000 ? `${n / 1_000}K` : String(n)
    if (g !== undefined && l !== undefined) return `${toKMB(g)}-${toKMB(l)}`
    if (g !== undefined) return `${toKMB(g)}+`
    if (l !== undefined) return `<${toKMB(l)}`
    return ""
  }

  const handlers: Record<string, (v: unknown) => void> = {
    [FILTER_KEYS.JOB_TITLE]: (v) => extractValues(v).forEach((x) => push(FILTER_KEYS.JOB_TITLE, FILTER_LABELS[FILTER_KEYS.JOB_TITLE], x)),
    [FILTER_KEYS.DEPARTMENTS]: (v) => extractValues(v).forEach((x) => push(FILTER_KEYS.DEPARTMENTS, FILTER_LABELS[FILTER_KEYS.DEPARTMENTS], x)),
    [FILTER_KEYS.SENIORITY]: (v) => extractValues(v).forEach((x) => push(FILTER_KEYS.SENIORITY, FILTER_LABELS[FILTER_KEYS.SENIORITY], x)),
    [FILTER_KEYS.COMPANY_NAME]: (v) => extractValues(v).forEach((x) => push(FILTER_KEYS.COMPANY_NAME, FILTER_LABELS[FILTER_KEYS.COMPANY_NAME], x)),
    [FILTER_KEYS.EMPLOYEE_COUNT]: (v) => push(FILTER_KEYS.EMPLOYEE_COUNT, FILTER_LABELS[FILTER_KEYS.EMPLOYEE_COUNT], formatRange(v)),
    [FILTER_KEYS.REVENUE]: (v) => push(FILTER_KEYS.REVENUE, FILTER_LABELS[FILTER_KEYS.REVENUE], formatRange(v)),
    [FILTER_KEYS.TECHNOLOGIES]: (v) => extractValues(v).forEach((x) => push(FILTER_KEYS.TECHNOLOGIES, FILTER_LABELS[FILTER_KEYS.TECHNOLOGIES], x)),
    [FILTER_KEYS.COMPANY_KEYWORDS]: (v) =>
      extractValues(v).forEach((x) => push(FILTER_KEYS.COMPANY_KEYWORDS, FILTER_LABELS[FILTER_KEYS.COMPANY_KEYWORDS], x)),
    [FILTER_KEYS.BUSINESS_CATEGORY]: (v) =>
      extractValues(v).forEach((x) => push(FILTER_KEYS.BUSINESS_CATEGORY, FILTER_LABELS[FILTER_KEYS.BUSINESS_CATEGORY], x)),
    location: (v) => {
      const loc = v as { include?: { countries?: unknown; states?: unknown; cities?: unknown }; country?: unknown; state?: unknown; city?: unknown }
      const toValues = (val: unknown): string[] => (Array.isArray(val) ? val.map(String) : val ? [String(val)] : [])
      const inc = (loc?.include || {}) as { countries?: unknown; states?: unknown; cities?: unknown }
      toValues(inc.countries || loc.country).forEach((x) => push(FILTER_KEYS.CONTACT_LOCATION, "Location (Country)", x))
      toValues(inc.states || loc.state).forEach((x) => push(FILTER_KEYS.CONTACT_LOCATION, "Location (State)", x))
      toValues(inc.cities || loc.city).forEach((x) => push(FILTER_KEYS.CONTACT_CITY, "Location (City)", x))
    },
    company_domain: (v) => extractValues(v).forEach((x) => push("company_domain", "Company Domain", x.toLowerCase()))
  }

  for (const [key, val] of Object.entries(filters)) {
    if (!val) continue
    let normKey = key
    if (key === "company") normKey = FILTER_KEYS.COMPANY_NAME
    if (key === "company.revenue") normKey = FILTER_KEYS.REVENUE
    if (key === "annual_revenue") normKey = FILTER_KEYS.REVENUE
    if (key === "company.employee_count") normKey = FILTER_KEYS.EMPLOYEE_COUNT
    if (key === "company_names") normKey = FILTER_KEYS.COMPANY_NAME
    if (key === "company_name") normKey = FILTER_KEYS.COMPANY_NAME
    if (key === "business_category") normKey = FILTER_KEYS.BUSINESS_CATEGORY
    if (key === "technology") normKey = FILTER_KEYS.TECHNOLOGIES
    if (key === "keywords") normKey = FILTER_KEYS.COMPANY_KEYWORDS
    if (key === "countries" || key === "states" || key === "cities") normKey = "location"
    if (handlers[normKey]) {
      handlers[normKey](val)
    } else if (handlers[key]) {
      handlers[key](val)
    }
  }

  customFilters.forEach((cf) => push(FILTER_KEYS.CUSTOM, cf.label || "Custom", cf.value))
  if (out.length === 0) return [{ id: "general", label: "Search Type", value: "General Search", checked: true }]
  return out
}

function buildDslFromResponse(
  filters: Record<string, unknown>,
  entity: "contacts" | "companies"
): { contact: Record<string, unknown>; company: Record<string, unknown> } {
  const dsl: { contact: Record<string, unknown>; company: Record<string, unknown> } = { contact: {}, company: {} }
  const ensureInclude = (bucket: Record<string, unknown>, key: string, value: string) => {
    const existing = (bucket[key] as { include?: string[]; exclude?: string[]; presence?: string } | undefined) || {}
    const include = Array.isArray(existing.include) ? existing.include : []
    bucket[key] = { ...existing, include: Array.from(new Set([...include, value])) }
  }
  const ensureExclude = (bucket: Record<string, unknown>, key: string, value: string) => {
    const existing = (bucket[key] as { include?: string[]; exclude?: string[]; presence?: string } | undefined) || {}
    const exclude = Array.isArray(existing.exclude) ? existing.exclude : []
    bucket[key] = { ...existing, exclude: Array.from(new Set([...exclude, value])) }
  }
  const setRange = (bucket: Record<string, unknown>, key: string, val: unknown) => {
    const range: { min?: number; max?: number } = {}
    if (val && typeof val === "object") {
      const obj = val as { gte?: number; lte?: number; min?: number; max?: number }
      if (typeof obj.gte === "number") range.min = obj.gte
      if (typeof obj.lte === "number") range.max = obj.lte
      if (typeof obj.min === "number") range.min = obj.min
      if (typeof obj.max === "number") range.max = obj.max
    }
    bucket[key] = range
  }
  for (const [key, val] of Object.entries(filters)) {
    if (!val) continue
    if (key === "title" || key === "job_title") {
      ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.contact, "job_title", String(v)))
    } else if (key === "departments") {
      ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.contact, "departments", String(v)))
    } else if (key === "seniority") {
      ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.contact, "seniority", String(v)))
    } else if (key === "years_experience" || key === "years_of_experience") {
      setRange(dsl.contact, "experience_years", val)
    } else if (key === "location.country") {
      ;(Array.isArray(val) ? val : [val]).forEach((v) =>
        ensureInclude(entity === "companies" ? dsl.company : dsl.contact, entity === "companies" ? "countries" : "countries", String(v))
      )
    } else if (key === "state") {
      ;(Array.isArray(val) ? val : [val]).forEach((v) =>
        ensureInclude(entity === "companies" ? dsl.company : dsl.contact, entity === "companies" ? "states" : "states", String(v))
      )
    } else if (key === "city") {
      ;(Array.isArray(val) ? val : [val]).forEach((v) =>
        ensureInclude(entity === "companies" ? dsl.company : dsl.contact, entity === "companies" ? "cities" : "cities", String(v))
      )
    } else if (key === "business_category") {
      ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.company, "business_category", String(v)))
    } else if (key === "skills" || key === "technologies" || key === "company.technologies" || key === "technology") {
      ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.company, "technologies", String(v)))
    } else if (key === "company_size" || key === "company.employee_count" || key === "employee_count") {
      setRange(dsl.company, "employee_count", val)
    } else if (key === "revenue" || key === "company.revenue" || key === "annual_revenue") {
      setRange(dsl.company, "annual_revenue", val)
    } else if (key === "company_keywords" || key === "keywords") {
      ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.company, "keywords", String(v)))
    } else if (key === "company_names" || key === "company_name" || key === "company") {
      ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.company, "company_name", String(v)))
    } else if (key === "company_alias" || key === "company_also_known_as") {
      ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.company, "company_alias", String(v)))
    } else if (key === "company_domain") {
      ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.company, "company_domain", String(v).toLowerCase()))
    } else if (key === "countries" || key === "states" || key === "cities") {
      const arr = Array.isArray(val) ? val.map(String) : [String(val)]
      arr.forEach((v) => ensureInclude(entity === "companies" ? dsl.company : dsl.contact, key, v))
    }
  }
  return dsl
}
