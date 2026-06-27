import http from '../../../shared/utils/request.js'
import { presentProvider } from '../../../providers/presenter.js'

const path = (value) => encodeURIComponent(value)
const endpoints = {
  providers: '/providers',
  providerDefinitions: '/providers/definitions',
  providerOrder: '/providers/sort-order',
  provider: (provider) => `/providers/${path(provider)}`,
}

function presentDefinitions(definitions) {
  const labels = {}
  for (const definition of definitions) Object.assign(labels, definition.labels || {})
  return { data: { types: definitions, labels } }
}

export const providerSettingsApi = {
  providers: async () => {
    const response = await http.get(endpoints.providers)
    return { ...response, data: response.data.map(presentProvider) }
  },
  providerDefinitions: async () => presentDefinitions((await http.get(endpoints.providerDefinitions)).data),
  createProvider: (data) => http.post(endpoints.providers, data),
  updateProvider: (provider, data) => http.put(endpoints.provider(provider), data),
  deleteProvider: (provider) => http.delete(endpoints.provider(provider)),
  updateProviderOrder: async (order) => {
    const response = await http.put(endpoints.providerOrder, { order })
    return { ...response, data: response.data.map(presentProvider) }
  },
}
