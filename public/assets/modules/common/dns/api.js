import http, { unwrapItems, withRefresh } from '../../../shared/utils/request.js'
import { getCachedProvider } from '../../../providers/store.js'

const path = (value) => encodeURIComponent(value)
const providerType = (provider) => getCachedProvider(provider)?.type || 'dnspod'
const providerBase = (provider) => `/${providerType(provider)}/providers/${path(provider)}`
const zoneBase = (provider, zone) => `${providerBase(provider)}/zones/${path(zone)}`
const endpoints = {
  zones: (provider) => `${providerBase(provider)}/zones`,
  zone: (provider, zone) => zoneBase(provider, zone),
  records: (provider, zone) => `${zoneBase(provider, zone)}/records`,
  record: (provider, zone, record) => `${zoneBase(provider, zone)}/records/${path(record)}`,
}

function normalizedPaging(options = {}, defaultPerPage = 20) {
  const page = Math.max(1, Number(options.page) || 1)
  const perPage = Math.max(1, Number(options.per_page) || defaultPerPage)
  return { page, per_page: perPage }
}

function zoneQuery(provider, options = {}) {
  const type = providerType(provider)
  const paging = normalizedPaging(options, 20)
  const keyword = String(options.keyword || '').trim()

  if (type === 'cloudflare') {
    return {
      page: paging.page,
      per_page: paging.per_page,
      name: keyword || undefined,
    }
  }

  return {
    offset: (paging.page - 1) * paging.per_page,
    limit: paging.per_page,
    keyword,
  }
}

function recordQuery(provider, options = {}) {
  const type = providerType(provider)
  const paging = normalizedPaging(options, 20)
  const keyword = String(options.keyword ?? options.search ?? '').trim()

  if (type === 'cloudflare') {
    return {
      page: paging.page,
      per_page: paging.per_page,
      type: options.type,
      search: keyword || undefined,
    }
  }

  return {
    offset: (paging.page - 1) * paging.per_page,
    limit: paging.per_page,
    subdomain: options.subdomain,
    record_type: options.record_type,
    keyword,
  }
}

function recordPayload(provider, zone, data, options = {}) {
  if (providerType(provider) === 'cloudflare') {
    const zoneName = options.zoneName || zone
    const name = data.name === '@'
      ? zoneName
      : String(data.name || '').toLowerCase().endsWith('.' + String(zoneName).toLowerCase())
        ? data.name
        : `${data.name}.${zoneName}`

    return {
      type: data.type,
      name,
      content: data.value ?? data.content,
      ttl: data.ttl || 1,
      priority: data.priority ?? data.mx,
      comment: data.remark,
      proxied: data.proxied,
    }
  }

  return {
    record_type: data.type ?? data.record_type,
    record_line: data.line ?? data.record_line ?? '默认',
    value: data.value,
    subdomain: data.name ?? data.subdomain ?? '@',
    ttl: data.ttl,
    mx: data.priority ?? data.mx,
    weight: data.weight,
    record_line_id: data.record_line_id,
    status: data.status ? String(data.status).toUpperCase() : undefined,
    remark: data.remark,
  }
}

function presentDomain(provider, domain) {
  const cached = getCachedProvider(provider)
  const type = cached?.type || providerType(provider)

  return {
    ...domain,
    provider,
    provider_type: type,
    provider_name: cached?.name || ({ cloudflare: 'Cloudflare', dnspod: 'DNSPod', hostname: 'Hostname', edgeone: 'EdgeOne' })[type] || type,
    name_servers: domain.name_servers || domain.effective_dns || [],
    access_status: domain.access_status || domain.status || domain.dns_status,
  }
}

function presentRecord(provider, record) {
  if (providerType(provider) === 'cloudflare') {
    const fqdn = String(record.name || '')
    const zoneName = String(record.zone_name || '')
    const host = zoneName && fqdn.toLowerCase() === zoneName.toLowerCase()
      ? '@'
      : zoneName && fqdn.toLowerCase().endsWith('.' + zoneName.toLowerCase())
        ? fqdn.slice(0, -(zoneName.length + 1))
        : fqdn

    return {
      ...record,
      provider,
      provider_type: 'cloudflare',
      fqdn,
      name: host,
      value: record.content,
      line: '默认',
      remark: record.comment || '',
      priority: record.priority,
    }
  }
  return {
    ...record,
    provider,
    provider_type: 'dnspod',
    priority: record.mx,
    record_line_id: record.record_line_id ?? record.line_id,
    remark: record.remark || '',
  }
}

export const dnsApi = {
  zones: async (provider, options) => {
    const response = unwrapItems(await http.get(endpoints.zones(provider), withRefresh({ params: zoneQuery(provider, options), refresh: options?.refresh })))
    return { ...response, data: response.data.map((domain) => presentDomain(provider, domain)) }
  },
  createZone: (provider, data) => http.post(endpoints.zones(provider), providerType(provider) === 'cloudflare' ? { name: data.domain } : data),
  deleteZone: (provider, zone) => http.delete(endpoints.zone(provider, zone)),
  records: async (provider, domain, options) => {
    const response = unwrapItems(await http.get(endpoints.records(provider, domain), withRefresh({ params: recordQuery(provider, options), refresh: options?.refresh })))
    return { ...response, data: response.data.map((record) => presentRecord(provider, record)) }
  },
  createRecord: (provider, domain, data, options) => http.post(endpoints.records(provider, domain), recordPayload(provider, domain, data, options)),
  updateRecord: (provider, domain, recordId, data, options) => http.put(endpoints.record(provider, domain, recordId), recordPayload(provider, domain, data, options)),
  deleteRecord: (provider, domain, recordId) => http.delete(endpoints.record(provider, domain, recordId)),
}
