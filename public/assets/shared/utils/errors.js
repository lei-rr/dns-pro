export function errorMessage(error, fallback = '请求失败') {
  if (typeof error === 'string') return error
  if (error && typeof error.message === 'string' && error.message.trim() !== '') return error.message
  return fallback
}
