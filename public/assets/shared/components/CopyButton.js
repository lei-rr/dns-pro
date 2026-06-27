import { message } from '../plugins/antDesignVue.js'

export default {
  props: { value: [String, Number] },
  methods: {
    async copy() {
      const text = String(this.value || '')

      // 优先用 Clipboard API（仅 HTTPS / localhost 等安全上下文可用）
      if (navigator.clipboard && window.isSecureContext) {
        try {
          await navigator.clipboard.writeText(text)
          message.success('已复制')
          return
        } catch {
          // 落到下面的降级方案
        }
      }

      // 降级：HTTP 等非安全上下文用 execCommand
      if (this.fallbackCopy(text)) {
        message.success('已复制')
      } else {
        message.warning('复制失败，请手动选择复制')
      }
    },
    fallbackCopy(text) {
      try {
        const textarea = document.createElement('textarea')
        textarea.value = text
        textarea.setAttribute('readonly', '')
        textarea.style.position = 'fixed'
        textarea.style.top = '-9999px'
        textarea.style.opacity = '0'
        document.body.appendChild(textarea)
        textarea.select()
        textarea.setSelectionRange(0, text.length)
        const ok = document.execCommand('copy')
        document.body.removeChild(textarea)
        return ok
      } catch {
        return false
      }
    },
  },
  template: `
    <a-button type="link" size="small" title="复制" class="copy-button" @click="copy">
      <template #icon><span aria-hidden="true">⧉</span></template>
    </a-button>
  `,
}
