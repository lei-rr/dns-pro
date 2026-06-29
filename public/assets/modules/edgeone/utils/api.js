import http, { unwrapItems, withRefresh } from '../../../shared/utils/request.js'

const path = (value) => encodeURIComponent(value)
const providerBase = (provider) => `/edgeone/providers/${path(provider)}`
const zoneBase = (provider, zone) => `${providerBase(provider)}/zones/${path(zone)}`
const accelerationDomainBase = (provider, zone, domain) => `${zoneBase(provider, zone)}/records/${path(domain)}`
const endpoints = {
  zones: (provider) => `${providerBase(provider)}/zones`,
  zone: (provider, zoneId) => `${providerBase(provider)}/zones/${path(zoneId)}`,
  accelerationDomains: (provider, zone) => `${zoneBase(provider, zone)}/records`,
  accelerationDomain: (provider, zone, domain) => accelerationDomainBase(provider, zone, domain),
  accelerationDomainStatus: (provider, zone, domain) => `${accelerationDomainBase(provider, zone, domain)}/status`,
  accelerationDomainCertificate: (provider, zone, domain) => `${accelerationDomainBase(provider, zone, domain)}/certificate`,
  accelerationDomainCnameSyncs: (provider, zone, domain) => `${accelerationDomainBase(provider, zone, domain)}/cname-sync`,
}

function normalizedPaging(options = {}, defaultPerPage = 20) {
  const page = Math.max(1, Number(options.page) || 1)
  const perPage = Math.max(1, Number(options.per_page) || defaultPerPage)
  return { page, per_page: perPage }
}

function edgeOneQuery(options = {}, defaultPerPage = 20) {
  const paging = normalizedPaging(options, defaultPerPage)
  return {
    offset: (paging.page - 1) * paging.per_page,
    limit: paging.per_page,
  }
}

export const edgeOneApi = {
  zones: async (provider, options) => unwrapItems(await http.get(endpoints.zones(provider), withRefresh({ params: edgeOneQuery(options, 20), refresh: options?.refresh }))),
  zone: (provider, zoneId) => http.get(endpoints.zone(provider, zoneId)),
  accelerationDomains: async (provider, zone, options) => unwrapItems(await http.get(endpoints.accelerationDomains(provider, zone), withRefresh({ params: edgeOneQuery(options, 20), refresh: options?.refresh }))),
  createAccelerationDomain: (provider, zone, data, options = {}) => http.post(endpoints.accelerationDomains(provider, zone), data, options.autoSync ? { params: { auto_sync: 1 } } : {}),
  updateAccelerationDomain: (provider, zone, domain, data) => http.put(endpoints.accelerationDomain(provider, zone, domain), data),
  updateAccelerationDomainStatus: (provider, zone, domain, status) => http.put(endpoints.accelerationDomainStatus(provider, zone, domain), { status }),
  updateCertificate: (provider, zone, domain, data) => http.put(endpoints.accelerationDomainCertificate(provider, zone, domain), data),
  syncAccelerationDomainCname: (provider, zone, domain) => http.post(endpoints.accelerationDomainCnameSyncs(provider, zone, domain)),
  deleteAccelerationDomain: (provider, zone, domain, options = {}) => http.delete(endpoints.accelerationDomain(provider, zone, domain), options.skipCleanup ? { params: { auto_cleanup: 0 } } : {}),
}
