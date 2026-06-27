import http, { unwrapItems, withRefresh } from '../../../shared/utils/request.js'

const path = (value) => encodeURIComponent(value)
const providerBase = (provider) => `/cloudflared/providers/${path(provider)}`
const tunnelBase = (provider, tunnelId) => `${providerBase(provider)}/tunnels/${path(tunnelId)}`

const endpoints = {
  zones: (provider) => `${providerBase(provider)}/zones`,
  tunnels: (provider) => `${providerBase(provider)}/tunnels`,
  tunnel: (provider, tunnelId) => tunnelBase(provider, tunnelId),
  token: (provider, tunnelId) => `${tunnelBase(provider, tunnelId)}/token`,
  tokenRotate: (provider, tunnelId) => `${tunnelBase(provider, tunnelId)}/token/rotate`,
  routes: (provider, tunnelId) => `${tunnelBase(provider, tunnelId)}/routes`,
}

export const cloudflaredApi = {
  zones: async (provider, options) => unwrapItems(await http.get(endpoints.zones(provider), withRefresh({ refresh: options?.refresh }))),
  tunnels: async (provider, options) => unwrapItems(await http.get(endpoints.tunnels(provider), withRefresh({ refresh: options?.refresh }))),
  tunnel: (provider, tunnelId, options) => http.get(endpoints.tunnel(provider, tunnelId), withRefresh({ refresh: options?.refresh })),
  createTunnel: (provider, name) => http.post(endpoints.tunnels(provider), { name }),
  deleteTunnel: (provider, tunnelId) => http.delete(endpoints.tunnel(provider, tunnelId)),
  tunnelToken: (provider, tunnelId) => http.get(endpoints.token(provider, tunnelId)),
  rotateToken: (provider, tunnelId) => http.post(endpoints.tokenRotate(provider, tunnelId)),
  routes: (provider, tunnelId) => http.get(endpoints.routes(provider, tunnelId)),
  addRoute: (provider, tunnelId, data) => http.post(endpoints.routes(provider, tunnelId), data),
  updateRoute: (provider, tunnelId, data, originalHostname, originalPath) => http.put(
    endpoints.routes(provider, tunnelId),
    data,
    { params: { original_hostname: originalHostname, original_path: originalPath || '' } },
  ),
  deleteRoute: (provider, tunnelId, hostname, path, zoneId) => http.delete(
    endpoints.routes(provider, tunnelId),
    { params: { hostname, path: path || '', zone_id: zoneId || '' } },
  ),
}
