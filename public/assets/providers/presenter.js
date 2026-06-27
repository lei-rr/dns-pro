export function isProviderConfigured(provider) {
  return !!provider.configured
}

export function providerFieldValues(provider) {
  return provider.fields || {}
}

export function presentProvider(provider) {
  return {
    ...provider,
    configured: isProviderConfigured(provider),
    editable_fields: provider.editable_fields || [],
    fields: providerFieldValues(provider),
  }
}
