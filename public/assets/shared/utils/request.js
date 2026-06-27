const http = axios.create({
  baseURL: '/api',
  timeout: 120000,
  withCredentials: true,
})

http.interceptors.response.use(
  (response) => response.data,
  (error) => {
    const payload = error.response?.data || {}
    const message = payload.message || error.message || '请求失败'
    const requestError = new Error(message)
    requestError.code = payload.code || 'REQUEST_FAILED'
    requestError.details = payload.details || {}
    requestError.status = error.response?.status || 0
    if (error.response?.status === 401 && location.hash !== '#/login') {
      window.dispatchEvent(new CustomEvent('auth-invalidated'))
      location.hash = '#/login'
    }
    return Promise.reject(requestError)
  }
)

export function withRefresh(options = {}) {
  // Accept only { params, refresh }; axios config should be passed separately.
  const { refresh, params = {} } = options
  const queryParams = { ...params }

  for (const [key, value] of Object.entries(queryParams)) {
    if (key === 'refresh' || value === undefined || value === null || value === '') delete queryParams[key]
  }

  return { params: refresh ? { ...queryParams, refresh: 1 } : queryParams }
}

export function unwrapItems(response) {
  if (Array.isArray(response?.data)) return { ...response, data: response.data }
  if (Array.isArray(response?.data?.items)) return { ...response, data: response.data.items, meta: response.data }
  return response
}

export default http
