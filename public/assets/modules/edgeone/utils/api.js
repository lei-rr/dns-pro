import http, { unwrapItems, withRefresh } from '../../../shared/utils/request.js'

const path = (value) => encodeURIComponent(value)
const providerBase = (provider) => `/edgeone/providers/${path(provider)}`
const zoneBase = (provider, zone) => `${providerBase(provider)}/zones/${path(zone)}`
const accelerationDomainBase = (provider, zone, domain) => `${zoneBase(provider, zone)}/records/${path(domain)}`
const endpoints = {
  zones: (provider) => `${providerBase(provider)}/zones`,
  accelerationDomains: (provider, zone) => `${zoneBase(provider, zone)}/records`,
  accelerationDomain: (provider, zone, domain) => accelerationDomainBase(provider, zone, domain),
  accelerationDomainStatus: (provider, zone, domain) => `${accelerationDomainBase(provider, zone, domain)}/status`,
  accelerationDomainCertificate: (provider, zone, domain) => `${accelerationDomainBase(provider, zone, domain)}/certificate`,
  accelerationDomainCnameSyncs: (provider, zone, domain) => `${accelerationDomainBase(provider, zone, domain)}/cname-sync`,
  accelerationDomainCnameStatus: (provider, zone, domain) => `${accelerationDomainBase(provider, zone, domain)}/cname-status`,
}

export const edgeOneApi = {
  zones: async (provider, options) => unwrapItems(await http.get(endpoints.zones(provider), withRefresh({ params: options, refresh: options?.refresh }))),
  accelerationDomains: async (provider, zone, options) => unwrapItems(await http.get(endpoints.accelerationDomains(provider, zone), withRefresh({ params: options, refresh: options?.refresh }))),
  createAccelerationDomain: (provider, zone, data, options = {}) => http.post(endpoints.accelerationDomains(provider, zone), data, options.autoSync ? { params: { auto_sync: 1 } } : {}),
  updateAccelerationDomain: (provider, zone, domain, data) => http.put(endpoints.accelerationDomain(provider, zone, domain), data),
  updateAccelerationDomainStatus: (provider, zone, domain, status) => http.put(endpoints.accelerationDomainStatus(provider, zone, domain), { status }),
  updateCertificate: (provider, zone, domain, data) => http.put(endpoints.accelerationDomainCertificate(provider, zone, domain), data),
  syncAccelerationDomainCname: (provider, zone, domain) => http.post(endpoints.accelerationDomainCnameSyncs(provider, zone, domain)),
  accelerationDomainCnameStatus: (provider, zone, domain) => http.get(endpoints.accelerationDomainCnameStatus(provider, zone, domain)),
  deleteAccelerationDomain: (provider, zone, domain, options = {}) => http.delete(endpoints.accelerationDomain(provider, zone, domain), options.skipCleanup ? { params: { auto_cleanup: 0 } } : {}),
}
