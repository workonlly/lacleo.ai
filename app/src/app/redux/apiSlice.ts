import { TRootState } from "@/interface/reduxRoot/state"
import { createApi, fetchBaseQuery } from "@reduxjs/toolkit/query/react"
import Cookies from "js-cookie"
import { ACCOUNT_HOST, AI_HOST } from "../constants/apiConstants"

const apiHost = (import.meta.env.VITE_API_HOST as string) || "/api/v1"
const baseQuery = fetchBaseQuery({
  baseUrl: apiHost,
  credentials: "include",
  prepareHeaders: (headers, { getState }) => {
    const state = getState() as TRootState
    const token = state.setting.token
    if (token) headers.set("authorization", `Bearer ${token}`)
    const xsrfToken = Cookies.get("XSRF-TOKEN")
    if (xsrfToken) headers.set("X-XSRF-TOKEN", xsrfToken)
    return headers
  }
})

export const accountBaseQuery = fetchBaseQuery({
  baseUrl: ACCOUNT_HOST as string,
  credentials: "include",
  prepareHeaders: (headers) => {
    const xsrfToken = Cookies.get("XSRF-TOKEN")
    if (xsrfToken) {
      headers.set("X-XSRF-TOKEN", xsrfToken)
    }

    return headers
  }
})

export const aiBaseQuery = fetchBaseQuery({
  baseUrl: AI_HOST as string,
  credentials: "include",
  prepareHeaders: (headers, { getState }) => {
    const state = getState() as TRootState
    const token = state.setting.token || import.meta.env.VITE_AI_BEARER
    if (token) headers.set("authorization", `Bearer ${token}`)
    headers.set("Content-Type", "application/json")
    return headers
  }
})

export const apiSlice = createApi({
  baseQuery: baseQuery,
  endpoints: () => ({})
})
