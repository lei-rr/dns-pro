import AppLayout from './layouts/AppLayout.js'
import DashboardView from './views/DashboardView.js'
import LoginView from './views/LoginView.js'

export const systemRouteIds = new Set(['', 'login', 'providers'])

export const systemRoutes = {
  layout: AppLayout,
  dashboard: DashboardView,
  login: LoginView,
}

export const publicRoutes = [
  { path: '/login', component: LoginView, meta: { public: true } },
]

export const childRoutes = [
  { path: '', component: DashboardView },
]
