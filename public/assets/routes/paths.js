export function providerPath(providerId) {
  return '/' + encodeURIComponent(providerId)
}

export function providerChildPath(providerId, childId) {
  return providerPath(providerId) + '/' + encodeURIComponent(childId)
}
