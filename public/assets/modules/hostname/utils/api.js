import http, { unwrapItems, withRefresh } from '../../../shared/utils/request.js'

const path = (value) => encodeURIComponent(value)
const providerBase = (provider) => `/hostname/providers/${path(provider)}`
const zoneBase = (provider, zone) => `${providerBase(provider)}/zones/${path(zone)}`

const endpoints = {
  hostnames: (provider, zone) => `${zoneBase(provider, zone)}/hostnames`,
  hostname: (provider, zone, hostname) => `${zoneBase(provider, zone)}/hostnames/${path(hostname)}`,
  refreshHostname: (provider, zone, hostname) => `${zoneBase(provider, zone)}/hostnames/${path(hostname)}/refresh`,
  createHostname: (provider, zone) => `${zoneBase(provider, zone)}/hostnames`,
  fallbackOrigin: (provider, zone) => `${zoneBase(provider, zone)}/fallback-origin`,
  preferredDomains: () => `/hostname/preferred-domains`,
  preferredDomain: (domain) => `/hostname/preferred-domains/${path(domain)}`,
  preferredDomainsSort: () => `/hostname/preferred-domains/sort`,
}

export const hostnameApi = {
  hostnames: async (provider, zone, options) => unwrapItems(await http.get(endpoints.hostnames(provider, zone), withRefresh({ params: options, refresh: options?.refresh }))),
  hostname: async (provider, zone, hostname, options = {}) => unwrapItems(await http.get(endpoints.hostname(provider, zone, hostname), withRefresh({ params: options, refresh: options?.refresh }))),
  refreshHostname: (provider, zone, hostname) => http.post(endpoints.refreshHostname(provider, zone, hostname)),
  createHostname: (provider, zone, data, options = {}) => http.post(endpoints.createHostname(provider, zone), data, options.autoSync ? { params: { auto_sync: 1 } } : {}),
  deleteHostname: (provider, zone, hostname, options = {}) => http.delete(endpoints.hostname(provider, zone, hostname), options.skipCleanup ? { params: { auto_cleanup: 0 } } : {}),
  // zone 级 fallback origin(SSL for SaaS 默认源服务器)
  fallbackOrigin: (provider, zone, options = {}) => http.get(endpoints.fallbackOrigin(provider, zone), withRefresh({ refresh: options?.refresh })),
  setFallbackOrigin: (provider, zone, origin) => http.put(endpoints.fallbackOrigin(provider, zone), { origin }),
  deleteFallbackOrigin: (provider, zone) => http.delete(endpoints.fallbackOrigin(provider, zone)),
}

export const preferredDomainApi = {
  list: async () => unwrapItems(await http.get(endpoints.preferredDomains())),
  create: (domain) => http.post(endpoints.preferredDomains(), { domain }),
  rename: (oldDomain, newDomain) => http.put(endpoints.preferredDomain(oldDomain), { domain: newDomain }),
  delete: (domain) => http.delete(endpoints.preferredDomain(domain)),
  sort: async (domains) => unwrapItems(await http.put(endpoints.preferredDomainsSort(), { domains })),
}
