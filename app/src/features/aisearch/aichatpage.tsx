import { MessageList } from "@/components/ui/aimessagelist"
import AISearchInput from "@/components/ui/aisearchinput"
import SearchConfirmation from "@/components/ui/searchconfirmation"
import React, { useCallback, useEffect, useRef, useState } from "react"
import { useDispatch, useSelector } from "react-redux"
// Import useNavigate
import { useNavigate } from "react-router-dom"
import { AiChatPageProps, Message, SearchCriterion, SearchConfirmationProps } from "./types"
import LacleoIcon from "../../static/media/avatars/lacleo_avatar.svg?react"
import LoaderLines from "../../static/media/icons/loader-lines.svg?react"
import {
  applyCriteria,
  finishCriteriaProcessing,
  finishSearch,
  startSearch,
  selectIsProcessingCriteria,
  selectSearchQuery,
  selectLastResultCount,
  setSemanticQuery
} from "./slice/searchslice"
import { useGetFiltersQuery } from "../filters/slice/apiSlice"
import { useTranslateQueryMutation } from "../searchTable/slice/apiSlice"
import { addSelectedItem, resetFilters, importFiltersFromDSL } from "../filters/slice/filterSlice"
import { IFilterGroup } from "@/interface/filters/filterGroup"
import { FILTER_KEYS, FILTER_LABELS } from "../filters/utils/constants"

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

const AiChatPage: React.FC<AiChatPageProps> = ({ initialQuery, onBackToHome }) => {
  const dispatch = useDispatch()
  const navigate = useNavigate()
  const { currentData: filterGroups = [] as IFilterGroup[] } = useGetFiltersQuery()
  const [translateQuery] = useTranslateQueryMutation()

  // Local state for messages and UI
  const [messages, setMessages] = useState<Message[]>([])
  const [isLoading, setIsLoading] = useState(false)
  const [currentCriteria, setCurrentCriteria] = useState<SearchCriterion[]>([])
  const [loadingPhraseIndex, setLoadingPhraseIndex] = useState(0)
  const [inferredEntity, setInferredEntity] = useState<"contacts" | "companies" | null>(null)
  const [hasProcessedInitialQuery, setHasProcessedInitialQuery] = useState(false)
  const [pendingDsl, setPendingDsl] = useState<{ contact: Record<string, unknown>; company: Record<string, unknown> } | null>(null)

  // Redux selectors
  const isProcessingCriteria = useSelector(selectIsProcessingCriteria)
  const reduxSearchQuery = useSelector(selectSearchQuery)
  const lastResultCount = useSelector(selectLastResultCount)

  // Track the last processed initial query to prevent duplicates
  const lastProcessedInitialQuery = useRef<string | null>(null)
  const hasProcessedInitialRef = useRef(false)
  const isProcessingRef = useRef(false)
  const mountedRef = useRef(false)
  const initialQueryRef = useRef(initialQuery)
  const reduxSearchQueryRef = useRef(reduxSearchQuery)
  const messagesRef = useRef<Message[]>([])
  const lastResultCountRef = useRef<number | null>(typeof lastResultCount === "number" ? lastResultCount : null)
  const processQueryRef = useRef<(q: string, isInitial?: boolean) => void>(() => {})

  const mapBackendFiltersToCriteria = useCallback((filters: Record<string, unknown>, customFilters: BackendCustomItem[] = []): SearchCriterion[] => {
    const out: SearchCriterion[] = []

    const push = (id: string, label: string, value: string, isCustom = false) => {
      if (value && value.trim()) {
        out.push({ id, label, value: value.trim(), checked: true })
      }
    }

    // Helper to extract values from potential { include: [] } structure or raw value
    const extractValues = (val: unknown): string[] => {
      if (!val) return []
      if (typeof val === "string") return [val]
      if (Array.isArray(val)) return val.map(String)
      if (typeof val === "object") {
        const obj = val as { include?: unknown }
        if (Array.isArray(obj.include)) return obj.include.map(String)
        if (typeof obj.include === "string") return [obj.include]
        // Fallback for simple key-value pairs if not using include
        return []
      }
      return [String(val)]
    }

    // Helper to format ranges
    const formatRange = (val: unknown): string => {
      const obj = (val || {}) as { gte?: number; lte?: number; min?: number; max?: number }
      const gte = obj.gte ?? obj.min
      const lte = obj.lte ?? obj.max

      const toKMB = (n: number) => {
        if (n >= 1_000_000_000) return `${n / 1_000_000_000}B`
        if (n >= 1_000_000) return `${n / 1_000_000}M`
        if (n >= 1_000) return `${n / 1_000}K`
        return String(n)
      }
      if (gte !== undefined && lte !== undefined) return `${toKMB(gte)}-${toKMB(lte)}`
      if (gte !== undefined) return `${toKMB(gte)}+`
      if (lte !== undefined) return `<${toKMB(lte)}`
      return ""
    }

    // Generic handler options
    const HANDLERS: Record<string, (val: unknown) => void> = {
      [FILTER_KEYS.JOB_TITLE]: (v) => extractValues(v).forEach((x) => push(FILTER_KEYS.JOB_TITLE, FILTER_LABELS[FILTER_KEYS.JOB_TITLE], x)),
      [FILTER_KEYS.DEPARTMENTS]: (v) => extractValues(v).forEach((x) => push(FILTER_KEYS.DEPARTMENTS, FILTER_LABELS[FILTER_KEYS.DEPARTMENTS], x)),
      [FILTER_KEYS.SENIORITY]: (v) => extractValues(v).forEach((x) => push(FILTER_KEYS.SENIORITY, FILTER_LABELS[FILTER_KEYS.SENIORITY], x)),
      [FILTER_KEYS.COMPANY_NAME]: (v) => extractValues(v).forEach((x) => push(FILTER_KEYS.COMPANY_NAME, FILTER_LABELS[FILTER_KEYS.COMPANY_NAME], x)),
      [FILTER_KEYS.EMPLOYEE_COUNT]: (v) => push(FILTER_KEYS.EMPLOYEE_COUNT, FILTER_LABELS[FILTER_KEYS.EMPLOYEE_COUNT], formatRange(v)),
      [FILTER_KEYS.REVENUE]: (v) => push(FILTER_KEYS.REVENUE, FILTER_LABELS[FILTER_KEYS.REVENUE], formatRange(v)),
      [FILTER_KEYS.EXPERIENCE]: (v) => push(FILTER_KEYS.EXPERIENCE, FILTER_LABELS[FILTER_KEYS.EXPERIENCE], formatRange(v)),
      [FILTER_KEYS.CONTACT_LOCATION]: (v) => {
        const loc = v as {
          type?: string
          include?: { countries?: unknown; states?: unknown; cities?: unknown }
          exclude?: { countries?: unknown; states?: unknown; cities?: unknown }
          country?: unknown
          state?: unknown
          city?: unknown
        }

        const toValues = (val: unknown): string[] => {
          if (!val) return []
          if (Array.isArray(val)) return val.map(String)
          return [String(val)]
        }

        if (loc && typeof loc === "object") {
          const include = loc.include || {}

          // New structured shape
          toValues((include as { countries?: unknown }).countries || loc.country).forEach((x) =>
            push(FILTER_KEYS.CONTACT_LOCATION, "Location (Country)", x)
          )
          toValues((include as { states?: unknown }).states || loc.state).forEach((x) => push(FILTER_KEYS.CONTACT_LOCATION, "Location (State)", x))
          toValues((include as { cities?: unknown }).cities || loc.city).forEach((x) => push(FILTER_KEYS.CONTACT_CITY, "Location (City)", x))
        } else {
          // Fallback for flat include arrays/strings
          extractValues(v).forEach((x) => push(FILTER_KEYS.CONTACT_LOCATION, "Location", x))
        }
      },
      [FILTER_KEYS.TECHNOLOGIES]: (v) => extractValues(v).forEach((x) => push(FILTER_KEYS.TECHNOLOGIES, FILTER_LABELS[FILTER_KEYS.TECHNOLOGIES], x)),
      [FILTER_KEYS.COMPANY_KEYWORDS]: (v) =>
        extractValues(v).forEach((x) => push(FILTER_KEYS.COMPANY_KEYWORDS, FILTER_LABELS[FILTER_KEYS.COMPANY_KEYWORDS], x)),
      [FILTER_KEYS.INDUSTRY]: (v) => extractValues(v).forEach((x) => push(FILTER_KEYS.INDUSTRY, FILTER_LABELS[FILTER_KEYS.INDUSTRY], x))
    }

    // Process standard filters
    for (const [key, val] of Object.entries(filters)) {
      if (!val) continue

      // Normalize legacy keys to constants if needed
      let normKey = key
      if (key === "company") normKey = FILTER_KEYS.COMPANY_NAME
      if (key === "company.revenue") normKey = FILTER_KEYS.REVENUE
      if (key === "company.employee_count") normKey = FILTER_KEYS.EMPLOYEE_COUNT
      // Map company_names explicitly to COMPANY_NAME constant
      if (key === "company_names") normKey = FILTER_KEYS.COMPANY_NAME

      if (HANDLERS[normKey]) {
        HANDLERS[normKey](val)
      } else if (HANDLERS[key]) {
        HANDLERS[key](val)
      }
    }

    // Process Custom / Dynamic Filters
    customFilters.forEach((cf) => {
      push(FILTER_KEYS.CUSTOM, cf.label || "Custom", cf.value, true)
    })

    if (out.length === 0) {
      return [{ id: "general", label: "Search Type", value: "General Search", checked: true }]
    }
    return out
  }, [])

  const addMessage = useCallback((message: Omit<Message, "id" | "timestamp">) => {
    const newMessage: Message = {
      ...message,
      id: crypto.randomUUID(),
      timestamp: new Date()
    }

    setMessages((prev) => [...prev, newMessage])
    return newMessage
  }, [])

  useEffect(() => {
    messagesRef.current = messages
  }, [messages])

  useEffect(() => {
    lastResultCountRef.current = typeof lastResultCount === "number" ? lastResultCount : null
  }, [lastResultCount])

  const disablePreviousConfirmations = useCallback(() => {
    setMessages((prev) =>
      prev.map((message) => {
        const componentNode = message.component
        if (componentNode && React.isValidElement(componentNode) && componentNode.type === SearchConfirmation) {
          const element = componentNode as React.ReactElement<SearchConfirmationProps>
          return {
            ...message,
            component: React.cloneElement<SearchConfirmationProps>(element, { disabled: true })
          }
        }
        return message
      })
    )
  }, [])

  // Memoized handlers
  const handleCriterionChange = useCallback((id: string, checked: boolean) => {
    setCurrentCriteria((prev) => prev.map((c) => (c.id === id ? { ...c, checked } : c)))
  }, [])

  const handleApplySearch = useCallback(
    async (criteria: SearchCriterion[]) => {
      if (!mountedRef.current) return

      setIsLoading(true)
      dispatch(applyCriteria(criteria))

      try {
        dispatch(resetFilters())

        if (pendingDsl && (Object.keys(pendingDsl.contact).length || Object.keys(pendingDsl.company).length)) {
          dispatch(importFiltersFromDSL(pendingDsl))
        } else {
          const allFilters = (filterGroups || []).flatMap((g) => g.filters)

          criteria
            .filter((c) => c.checked)
            .forEach((c) => {
              let filterId = c.id.toLowerCase()

              if (filterId === "revenue" || filterId === "annual_revenue") filterId = "company_revenue"
              if (filterId === "headcount" || filterId === "company_headcount") filterId = "employee_count"
              if (filterId === "experience") filterId = "years_of_experience"

              const matched =
                allFilters.find((f) => f.name.toLowerCase() === c.label.toLowerCase()) ||
                allFilters.find((f) => f.id.toLowerCase() === filterId) ||
                allFilters.find((f) => f.id.toLowerCase() === c.id.toLowerCase())

              if (matched) {
                dispatch(
                  addSelectedItem({
                    sectionId: matched.id,
                    item: { id: c.value, name: c.value, type: "include" }
                  })
                )
              } else if (c.id === "company_keywords" || c.id === FILTER_KEYS.CUSTOM) {
                dispatch(
                  addSelectedItem({
                    sectionId: "company_keywords",
                    item: { id: c.value, name: c.value, type: "include" },
                    isCompanyFilter: true
                  })
                )
              }
            })
        }

        if (!mountedRef.current) return

        if (inferredEntity === "contacts") {
          navigate("/app/search/contacts", { state: { fromAi: true } })
        } else if (inferredEntity === "companies") {
          navigate("/app/search/companies", { state: { fromAi: true } })
        } else {
          const hasContactFilters = criteria.some((c) => ["job_title", "departments", "seniority"].includes(c.id))
          if (hasContactFilters) navigate("/app/search/contacts", { state: { fromAi: true } })
          else navigate("/app/search/companies", { state: { fromAi: true } })
        }

        dispatch(finishSearch())
      } catch (error) {
        console.error("Error applying search:", error)
        if (mountedRef.current) {
          addMessage({
            type: "ai",
            content: "Sorry, there was an error executing your search. Please try again."
          })
        }
      } finally {
        if (mountedRef.current) {
          setIsLoading(false)
        }
      }
    },
    [addMessage, dispatch, filterGroups, navigate, inferredEntity, pendingDsl]
  )

  useEffect(() => {
    if (!isLoading) {
      setLoadingPhraseIndex(0)
      return
    }
    let nextIndex = 0
    setLoadingPhraseIndex(0)
    const intervalId = setInterval(() => {
      nextIndex = (nextIndex + 1) % LOADING_PHRASES.length
      setLoadingPhraseIndex(nextIndex)
    }, 1500)
    return () => clearInterval(intervalId)
  }, [isLoading])

  const processQuery = useCallback(
    async (query: string, isInitial = false) => {
      if (isProcessingRef.current) return

      if (isInitial && lastProcessedInitialQuery.current === query) return

      isProcessingRef.current = true
      if (isInitial) {
        lastProcessedInitialQuery.current = query
      }

      setIsLoading(true)

      // Construct message history for the AI
      // We need to map our local Message[] (which has IDs, timestamps, components)
      // to the API's simple format { role, content }
      const historyContext = (messagesRef.current || [])
        .filter((m) => m.type === "user" || m.type === "ai")
        .map((m) => ({
          role: m.type === "user" ? "user" : "assistant",
          content: m.content
        }))

      // Append the new user query to the history
      const newHistory = [...historyContext, { role: "user", content: query }]

      addMessage({
        type: "user",
        content: query
      })

      try {
        const response = await translateQuery({
          messages: newHistory,
          context: { lastResultCount: lastResultCountRef.current }
        }).unwrap()

        if (!mountedRef.current) return

        const criteria = mapBackendFiltersToCriteria(response.filters, response.custom)
        setInferredEntity(response.entity)
        setCurrentCriteria(criteria)

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
        const setLocationPresence = (bucket: Record<string, unknown>, presence: "known" | "unknown") => {
          const existing = (bucket["locations"] as { include?: string[]; exclude?: string[]; presence?: string } | undefined) || {}
          bucket["locations"] = { ...existing, presence }
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

        for (const [key, val] of Object.entries(response.filters as Record<string, unknown>)) {
          if (!val) continue
          if (key === "title" || key === "job_title") {
            ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.contact, "job_title", String(v)))
          } else if (key === "departments") {
            ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.contact, "departments", String(v)))
          } else if (key === "seniority") {
            ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.contact, "seniority", String(v)))
          } else if (key === "years_experience" || key === "years_of_experience") {
            setRange(dsl.contact, "experience_years", val)
          } else if (key === "location") {
            const raw = val as {
              type?: "contact" | "company" | null
              include?: { countries?: unknown; states?: unknown; cities?: unknown }
              exclude?: { countries?: unknown; states?: unknown; cities?: unknown }
              known?: boolean
              unknown?: boolean
            }

            const entity = response.entity
            const inferredType: "contact" | "company" =
              raw?.type === "company" || raw?.type === "contact" ? raw.type : entity === "companies" ? "company" : "contact"

            const targetBucket = inferredType === "company" ? dsl.company : dsl.contact

            const toArray = (v: unknown): string[] => {
              if (!v) return []
              if (Array.isArray(v)) return v.map(String)
              return [String(v)]
            }

            const include = raw?.include || {}
            const exclude = raw?.exclude || {}

            toArray((include as { countries?: unknown }).countries).forEach((v) => ensureInclude(targetBucket, "country", v))
            toArray((include as { states?: unknown }).states).forEach((v) => ensureInclude(targetBucket, "state", v))
            toArray((include as { cities?: unknown }).cities).forEach((v) => ensureInclude(targetBucket, "city", v))

            toArray((exclude as { countries?: unknown }).countries).forEach((v) => ensureExclude(targetBucket, "country", v))
            toArray((exclude as { states?: unknown }).states).forEach((v) => ensureExclude(targetBucket, "state", v))
            toArray((exclude as { cities?: unknown }).cities).forEach((v) => ensureExclude(targetBucket, "city", v))

            const known = raw?.known === true
            const unknown = raw?.unknown === true
            if (known && !unknown) {
              setLocationPresence(targetBucket, "known")
            } else if (unknown && !known) {
              setLocationPresence(targetBucket, "unknown")
            }
          } else if (key === "location.country") {
            ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.company, "locations", String(v)))
          } else if (key === "location.city" || key === "city") {
            ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.contact, "city", String(v)))
          } else if (key === "industry" || key === "company.industry" || key === "industries") {
            ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.company, "industries", String(v)))
          } else if (key === "skills" || key === "technologies" || key === "company.technologies") {
            ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.company, "technologies", String(v)))
          } else if (key === "company_size" || key === "company.employee_count" || key === "employee_count") {
            setRange(dsl.company, "employee_count", val)
          } else if (key === "revenue" || key === "company.revenue" || key === "annual_revenue") {
            setRange(dsl.company, "annual_revenue", val)
          } else if (key === "company_keywords") {
            ;(Array.isArray(val) ? val : [val]).forEach((v) => ensureInclude(dsl.company, "company_keywords", String(v)))
          } else if (key === "company_names" || key === "company") {
            // ...
          }
        }

        // Persist DSL for Apply step and mark criteria processing finished
        setPendingDsl(dsl)
        dispatch(finishCriteriaProcessing())

        // Create a stable component instance to prevent re-renders
        const confirmationComponent = (
          <SearchConfirmation criteria={criteria} onApply={handleApplySearch} onCriterionChange={handleCriterionChange} disabled={false} />
        )

        // Add AI summary explanation if available
        if (response.summary) {
          addMessage({
            type: "ai",
            content: response.summary
          })
        }

        // Set Semantic Query if available (for vector search)
        if (response.semantic_query) {
          dispatch(setSemanticQuery(response.semantic_query))
        } else {
          dispatch(setSemanticQuery(null))
        }

        // Add confirmation message with component
        addMessage({
          type: "system",
          content: "I've analyzed your request. Please confirm these filters:",
          component: confirmationComponent
        })
      } catch (error) {
        console.error("Error processing query:", error)
        if (mountedRef.current) {
          addMessage({
            type: "ai",
            content: "I encountered an error connecting to the AI service. Switching to general keyword search..."
          })

          // Fallback: heuristic to guess entity type if AI failed
          const isContactQuery =
            /(person|people|member|contact|email|phone|who|manager|director|pro|hr|recruiter|developer|engineer|rep|agent|consultant)/i.test(query)
          setInferredEntity(isContactQuery ? "contacts" : "companies")

          // Fallback: Use the raw user query as a company_keyword
          const criteria: SearchCriterion[] = [{ id: "general", label: "Search Type", value: "General Search", checked: true }]
          setCurrentCriteria(criteria)

          // Ensure the global search query is set for the table to pick up
          dispatch(startSearch(query))

          // Create a stable component instance to prevent re-renders
          const confirmationComponent = (
            <SearchConfirmation criteria={criteria} onApply={handleApplySearch} onCriterionChange={handleCriterionChange} disabled={false} />
          )

          addMessage({
            type: "system",
            content: "Please confirm to continue with keyword search:",
            component: confirmationComponent
          })
        }
      } finally {
        if (mountedRef.current) {
          setIsLoading(false)
        }
        isProcessingRef.current = false
      }
    },
    [addMessage, translateQuery, mapBackendFiltersToCriteria, handleApplySearch, handleCriterionChange, dispatch]
  )

  // Keep ref in sync with latest processQuery
  useEffect(() => {
    processQueryRef.current = processQuery
  }, [processQuery])

  // Handle initial query processing — run exactly once per distinct initialQuery
  useEffect(() => {
    mountedRef.current = true
    const redirecting = typeof window !== "undefined" ? sessionStorage.getItem("authRedirectInProgress") === "1" : false
    if (initialQuery && !hasProcessedInitialRef.current && !redirecting) {
      hasProcessedInitialRef.current = true
      processQueryRef.current(initialQuery, true)
      setHasProcessedInitialQuery(true)
    }
    return () => {
      mountedRef.current = false
    }
  }, [initialQuery, hasProcessedInitialQuery])

  // Reset when initialQuery changes to a new value
  useEffect(() => {
    const queryToCheck = initialQuery || reduxSearchQuery
    if (queryToCheck && lastProcessedInitialQuery.current && lastProcessedInitialQuery.current !== queryToCheck) {
      lastProcessedInitialQuery.current = null
      isProcessingRef.current = false
    }
  }, [initialQuery, reduxSearchQuery])

  const handleNewSearch = useCallback(
    (query: string) => {
      // Disable previous confirmation Apply buttons
      disablePreviousConfirmations()
      // Dispatch Redux action for new search
      dispatch(startSearch(query))
      // Prevent duplicate processing if same as initial processed query
      if (lastProcessedInitialQuery.current && lastProcessedInitialQuery.current === query) {
        return
      }
      // Reset processing flag for new searches
      isProcessingRef.current = false
      processQuery(query, false)
    },
    [processQuery, dispatch, disablePreviousConfirmations]
  )

  return (
    <div className="flex h-full flex-1 flex-col">
      {/* Messages Container */}
      <div className="flex max-h-[calc(100vh-363px)] min-h-0 flex-1 flex-col overflow-auto">
        <MessageList messages={messages} />

        {/* Loading indicator */}
        {!!isLoading && (
          <div className="ml-6 flex items-end gap-[10px]">
            <LacleoIcon />
            <div className="rounded-[12px] border border-[#EBEBEB] px-6 py-[18px]">
              <span className="flex items-center gap-2 text-base font-normal text-[#5C5C5C]">
                <LoaderLines className="size-8" />
                {LOADING_PHRASES[loadingPhraseIndex]}
              </span>
            </div>
          </div>
        )}
      </div>

      {/* Search Input at bottom */}
      <div className="bg-white p-4">
        <AISearchInput onSearch={handleNewSearch} placeholder="Ask a follow-up question or start a new search..." disabled={isLoading} />
      </div>
    </div>
  )
}

export default AiChatPage
