const defaultBrand = { color: 'geekblue', avatarColor: '#2f54eb' }

const providerBrands = {
  dnspod: { color: 'blue', avatarColor: '#1677ff' },
  edgeone: { color: 'blue', avatarColor: '#1677ff' },
  cloudflare: { color: 'orange', avatarColor: '#fa8c16' },
  cloudflared: { color: 'orange', avatarColor: '#fa8c16' },
  hostname: { color: 'orange', avatarColor: '#fa8c16' },
}

export function providerBrand(type) {
  return providerBrands[type] || defaultBrand
}

export function providerAvatarColor(type) {
  return providerBrand(type).avatarColor
}

export function providerTagColor(type) {
  return providerBrand(type).color
}

export { defaultBrand }
