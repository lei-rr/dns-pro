import { message } from '../plugins/antDesignVue.js'

export default {
  props: { value: [String, Number] },
  methods: {
    copy() {
      ;(navigator.clipboard?.writeText(String(this.value || '')) ?? Promise.reject())
        .then(() => message.success('已复制'))
        .catch(() => message.warning('复制失败'))
    },
  },
  template: `
    <a-button type="link" size="small" title="复制" class="copy-button" @click="copy">
      <template #icon><span aria-hidden="true">⧉</span></template>
    </a-button>
  `,
}
