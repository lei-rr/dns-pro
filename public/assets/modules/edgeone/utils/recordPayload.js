export function recordToFormState(record, zoneName) {
  return {
    prefix: record?.name ? prefixFromDomain(record.name, zoneName) : 'www',
    origin_type: record?.origin?.type || 'IP_DOMAIN',
    origin: record?.origin?.value || '',
    origin_protocol: record?.origin_protocol || 'HTTP',
    http_origin_port: record?.http_origin_port || 80,
    https_origin_port: record?.https_origin_port || 443,
    host_header: record?.origin?.host_header || '',
    host_header_mode: record?.origin?.host_header && record.origin.host_header !== record.name ? 'custom' : 'accelerate',
    ipv6_status: record?.ipv6_status || 'follow',
  }
}

export function formStateToRecordPayload(form, zoneName) {
  const domainName = fullDomainName(form.prefix, zoneName)
  return {
    ...form,
    domain_name: domainName,
    host_header: form.origin_type === 'IP_DOMAIN' && form.host_header_mode === 'custom' ? form.host_header : '',
  }
}

export function fullDomainName(prefixValue, zoneName) {
  const prefix = String(prefixValue || '').trim().toLowerCase()
  if (!zoneName) return prefix
  if (prefix === '@' || prefix === '') return zoneName
  return `${prefix}.${zoneName}`
}

export function prefixFromDomain(domain, zoneName) {
  const suffix = `.${zoneName}`
  if (domain === zoneName) return '@'
  if (zoneName && domain.endsWith(suffix)) return domain.slice(0, -suffix.length)
  return domain
}

export function validateEdgeOneRecordForm(form, zoneName) {
  const fullDomain = fullDomainName(form.prefix, zoneName)
  if (!validPrefix(form.prefix)) return '加速域名前缀格式不正确'
  if (!validDomain(fullDomain)) return '加速域名格式不正确'
  if (!String(form.origin || '').trim()) return '源站不能为空'

  const httpPort = Number(form.http_origin_port)
  const httpsPort = Number(form.https_origin_port)
  if (['FOLLOW', 'HTTP'].includes(form.origin_protocol) && (!Number.isInteger(httpPort) || httpPort < 1 || httpPort > 65535)) return 'HTTP 回源端口必须是 1 到 65535 之间的整数'
  if (['FOLLOW', 'HTTPS'].includes(form.origin_protocol) && (!Number.isInteger(httpsPort) || httpsPort < 1 || httpsPort > 65535)) return 'HTTPS 回源端口必须是 1 到 65535 之间的整数'
  if (form.origin_type === 'IP_DOMAIN' && form.host_header_mode === 'custom' && !validDomain(form.host_header)) return '回源 HOST 头格式不正确'

  return ''
}

function validDomain(value) {
  return /^(\*\.)?(?!-)([a-z0-9-]{1,63}\.)+[a-z]{2,63}$/i.test(String(value || '').trim())
}

function validPrefix(value) {
  const prefix = String(value || '').trim()
  if (prefix === '@' || prefix === '*') return true
  return /^(?!-)([a-z0-9-]{1,63}\.)*[a-z0-9-]{1,63}$/i.test(prefix)
}
