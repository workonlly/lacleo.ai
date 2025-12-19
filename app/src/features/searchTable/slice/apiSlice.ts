import { apiSlice } from "@/app/redux/apiSlice"
import { CompanyAttributes, ContactAttributes, SavedFilter, SearchApiResponse, SearchRequestParams } from "@/interface/searchTable/search"

const enhancedApi = apiSlice.enhanceEndpoints({ addTagTypes: ["BillingUsage", "User", "SavedFilter"] })

const contactsApi = enhancedApi.injectEndpoints({
  endpoints: (builder) => {
    return {
      searchContacts: builder.query<SearchApiResponse<ContactAttributes | CompanyAttributes>, SearchRequestParams>({
        query: ({ type, buildParams }) => ({ url: `/search/${type}${buildParams}` }),
        transformResponse: (response: SearchApiResponse<ContactAttributes | CompanyAttributes>, meta, arg) => {
          if (arg.type === "contact") {
            return response as SearchApiResponse<ContactAttributes>
          }
          return response as SearchApiResponse<CompanyAttributes>
        }
      }),
      billingUsage: builder.query<
        {
          balance: number
          plan_total: number
          used: number
          period_start: string
          period_end: string
          breakdown: Record<string, number>
          free_grants_total: number
          stripe_enabled: boolean
        },
        void
      >({
        query: () => ({ url: "/billing/usage" }),
        providesTags: ["BillingUsage"]
      }),

      revealContact: builder.mutation<
        {
          contact: { email: string | null; phone: string | null }
          revealed: boolean
          deducted_credits: number
          field: "email" | "phone" | "none"
          remaining_credits: number
        },
        { id: string; revealPhone?: boolean; revealEmail?: boolean; requestId?: string }
      >({
        query: ({ id, revealPhone, revealEmail, requestId }) => ({
          url: `/contacts/${id}/reveal`,
          method: "POST",
          body: { revealPhone, revealEmail },
          headers: requestId ? { request_id: requestId } : undefined
        }),
        invalidatesTags: ["BillingUsage", "User"]
      }),

      adminBillingContext: builder.query<
        {
          user: { id: string; email: string; name: string }
          workspace: { id: string; balance: number; reserved: number }
          transactions: Array<{ id: string; amount: number; type: string; meta: Record<string, unknown>; created_at: string }>
        },
        { email: string }
      >({
        query: ({ email }) => ({ url: `/admin/debug/billing-context?email=${encodeURIComponent(email)}` })
      }),

      grantCredits: builder.mutation<{ success: boolean; new_balance: number }, { user_id: string; credits: number; reason?: string }>({
        query: ({ user_id, credits, reason }) => ({
          url: "/billing/grant-credits",
          method: "POST",
          body: { user_id, credits, reason }
        })
      }),
      companyLogo: builder.query<{ domain: string | null; logo_url: string | null }, { domain: string }>({
        query: ({ domain }) => ({ url: `/logo?domain=${encodeURIComponent(domain)}` })
      }),

      translateQuery: builder.mutation<
        {
          entity: "contacts" | "companies"
          filters: { [key: string]: unknown }
          summary?: string
          semantic_query?: string | null
          custom?: { label: string; value: string; type: string }[]
        },
        { messages: { role: string; content: string }[]; context?: { lastResultCount?: number | null } }
      >({
        query: ({ messages, context }) => ({
          url: "/ai/translate-query",
          method: "POST",
          body: { messages, context }
        })
      }),

      revealCompany: builder.mutation<
        {
          company: { email: string | null; phone: string | null }
          revealed: boolean
          deducted_credits: number
          field: "email" | "phone" | "none"
          remaining_credits: number
        },
        { id: string; revealPhone?: boolean; revealEmail?: boolean; requestId?: string }
      >({
        query: ({ id, revealPhone, revealEmail, requestId }) => ({
          url: `/companies/${id}/reveal`,
          method: "POST",
          body: { revealPhone, revealEmail },
          headers: requestId ? { request_id: requestId } : undefined
        }),
        invalidatesTags: ["BillingUsage", "User"]
      }),
      exportEstimate: builder.mutation<
        {
          email_count: number
          phone_count: number
          credits_required: number
          total_rows: number
          can_export_free: boolean
          remaining_before: number
          remaining_after: number
        },
        { type?: "contacts" | "companies"; ids: string[]; fields: { email: boolean; phone: boolean }; limit?: number; sanitize?: boolean }
      >({
        query: ({ type = "contacts", ids, fields, limit, sanitize }) => ({
          url: "/billing/preview-export",
          method: "POST",
          body: { type, ids, fields, limit, sanitize }
        })
      }),
      exportCreate: builder.mutation<
        { url: string; credits_deducted: number; remaining_credits: number; request_id: string },
        {
          type: "contacts" | "companies"
          ids: string[]
          fields: { email: boolean; phone: boolean }
          limit?: number
          requestId?: string
          sanitize?: boolean
          download?: boolean
        }
      >({
        query: ({ type, ids, fields, limit, requestId, sanitize, download }) => {
          const rId = requestId ?? (typeof crypto !== "undefined" && crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}`)
          return {
            url: "/billing/export",
            method: "POST",
            body: { type, ids, fields, limit, sanitize, download, requestId: rId },
            headers: { "X-Request-Id": rId }
          }
        },
        invalidatesTags: ["BillingUsage", "User"]
      }),
      billingPurchase: builder.mutation<{ checkout_url: string }, { pack: 500 | 2000 | 10000 }>({
        query: ({ pack }) => ({ url: "/billing/purchase", method: "POST", body: { pack } })
      }),
      billingPortal: builder.mutation<{ url: string }, void>({
        query: () => ({ url: "/billing/portal", method: "POST" })
      }),
      billingSubscribe: builder.mutation<{ checkout_url: string }, { plan_id: string }>({
        query: ({ plan_id }) => ({ url: "/billing/subscribe", method: "POST", body: { plan_id } })
      }),
      // Saved Filters
      getSavedFilters: builder.query<{ data: SavedFilter[] }, { type?: "contact" | "company" }>({
        query: ({ type }) => ({
          url: "/saved-filters",
          params: type ? { type } : undefined
        }),
        providesTags: ["SavedFilter"]
      }),
      createSavedFilter: builder.mutation<
        { data: SavedFilter },
        {
          name: string
          description?: string
          filters: unknown
          entity_type: "contact" | "company"
          tags?: string[]
        }
      >({
        query: (body) => ({
          url: "/saved-filters",
          method: "POST",
          body
        }),
        invalidatesTags: ["SavedFilter"]
      }),
      deleteSavedFilter: builder.mutation<{ message: string }, string>({
        query: (id) => ({
          url: `/saved-filters/${id}`,
          method: "DELETE"
        }),
        invalidatesTags: ["SavedFilter"]
      }),
      updateSavedFilter: builder.mutation<
        { data: SavedFilter },
        { id: string; name?: string; description?: string; is_starred?: boolean; filters?: unknown; tags?: string[] }
      >({
        query: ({ id, ...body }) => ({
          url: `/saved-filters/${id}`,
          method: "PUT",
          body
        }),
        invalidatesTags: ["SavedFilter"]
      })
    }
  }
})
export const {
  useSearchContactsQuery,
  useBillingUsageQuery,
  useRevealContactMutation,
  useLazyAdminBillingContextQuery,
  useGrantCreditsMutation,
  useCompanyLogoQuery,
  useTranslateQueryMutation,
  useRevealCompanyMutation,
  useExportEstimateMutation,
  useExportCreateMutation,
  useBillingPurchaseMutation,
  useBillingPortalMutation,
  useBillingSubscribeMutation,
  useGetSavedFiltersQuery,
  useCreateSavedFilterMutation,
  useDeleteSavedFilterMutation,
  useUpdateSavedFilterMutation
} = contactsApi
