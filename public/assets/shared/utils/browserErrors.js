const resizeObserverMessages = [
  'ResizeObserver loop completed with undelivered notifications.',
  'ResizeObserver loop limit exceeded',
]

export function ignoreResizeObserverNoise() {
  window.addEventListener('error', (event) => {
    if (resizeObserverMessages.includes(event.message)) {
      event.stopImmediatePropagation()
      event.preventDefault()
    }
  })
}
