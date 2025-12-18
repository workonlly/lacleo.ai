import { FetchBaseQueryError, FetchBaseQueryMeta } from "@reduxjs/toolkit/query"

const CONNECTION_REFUSED_RESPONSE = {
  TITLE: "Connection Error",
  MESSAGE: "Unable to establish a server connection at the moment. Please check back shortly."
}

export const ACCOUNT_HOST = import.meta.env.VITE_ACCOUNT_HOST
// In production, use configured host (fallback to default agent host). In dev/stage, use relative path to leverage Vite proxy.
export const AI_HOST = import.meta.env.PROD ? import.meta.env.VITE_AI_HOST : "/api/v1/ai"

export const USER = {
  GET_USER: "/user",
  LOGOUT_USER: "/logout"
}

export const FILTER = {
  GET_ALL_FILTERS: "/filters",
  GET_FILTER_VALUES: "/filter/values"
}

export const AI = {
  EXTRACT_FILTERS: "/generate-filters"
}

export const transformErrorResponse = (response: FetchBaseQueryError, _: FetchBaseQueryMeta | undefined, arg: undefined) => {
  const fetchError = !response.status || isNaN(+response.status)
  return {
    status: fetchError ? 503 : response.status,
    originalArgs: arg,
    data: fetchError
      ? {
          title: CONNECTION_REFUSED_RESPONSE.TITLE,
          message: CONNECTION_REFUSED_RESPONSE.MESSAGE
        }
      : {
          message:
            typeof response.data === "object" && response.data && "error" in response.data && typeof response.data.error === "string"
              ? response.data.error
              : "Unexpected error occurred. Please try again later."
        }
  }
}
