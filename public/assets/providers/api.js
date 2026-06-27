import http from '../shared/utils/request.js'
import { presentProvider } from './presenter.js'

const endpoints = {
  configured: '/providers',
}

export const providersApi = {
  configured: async () => {
    const response = await http.get(endpoints.configured)
    return { ...response, data: response.data.map(presentProvider).filter((provider) => provider.configured) }
  },
}
