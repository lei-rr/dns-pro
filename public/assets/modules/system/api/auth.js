import http from '../../../shared/utils/request.js'

const endpoints = {
  session: '/session',
}

let mePromise = null

function rememberMe(response) {
  mePromise = Promise.resolve(response)
  return response
}

export function clearAuthCache() {
  mePromise = null
}

window.addEventListener('auth-invalidated', clearAuthCache)

export const authApi = {
  captchaUrl: () => `/captcha?t=${Date.now()}`,
  login: async (username, password, captcha) => {
    const response = await http.post(endpoints.session, { username, password, captcha })
    return rememberMe(response)
  },
  logout: async () => {
    clearAuthCache()
    return http.delete(endpoints.session)
  },
  me: () => {
    if (!mePromise) {
      mePromise = http.get(endpoints.session).catch((error) => {
        clearAuthCache()
        throw error
      })
    }
    return mePromise
  },
}
